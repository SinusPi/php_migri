<?php
namespace SinusPi\Migri;

class Migri {
	/** @var \mysqli|null */
	private $mysqli_conn = null;
	/** @var \PDO|null */
	private $pdo_conn = null;

	/** Marker used in table comment to store version */
	const COMMENT_VERSION_MARKER = 'VER=';

	/**
	 * Constructor
	 * @param \mysqli|\PDO $connection MySQLi or PDO connection object
	 */
	public function __construct($connection) {
		if ($connection instanceof \mysqli) {
			$this->mysqli_conn = $connection;
		} elseif ($connection instanceof \PDO) {
			$this->pdo_conn = $connection;
		} else {
			throw new \InvalidArgumentException("Unsupported connection type");
		}
	}

	/**
	 * Manage table schema: create if needed and migrate to target version
	 * @param string $table_name Table name
	 * @param array $migrations Associative array of migration keys and SQL statements:
	 *                          - "N" => "CREATE TABLE ..." : defines state at version N (shortcut for N to target)
	 *                          - "N>N+1" => "ALTER TABLE ..." : transition from version N to N+1
	 * @return array Associative array with migration results and status: ['status'=>'migrated|already_current', 'current_version'=>int, 'target_version'=>int, 'created'=>bool, 'migrated'=>int]
	 * @throws \Exception If migration path is incomplete or other errors occur
	 */
	public function manageTable($table_name, $migrations) {
		if (is_string($migrations)) {
			// Shortcut: if a single CREATE TABLE is provided, treat it as the final state with a reset point at the same version
			$migrations = [
				"1" => $migrations,
			];
		} elseif (!$migrations || !is_array($migrations)) {
			throw new \InvalidArgumentException("Migrations must be a string or an array of migration definitions");
		}

		if (!preg_match('/^[A-Za-z0-9_]+$/', $table_name)) {
			throw new \InvalidArgumentException("Invalid table name '$table_name'. Only alphanumeric and underscore characters are allowed.");
		}
		$migrations = str_replace("<TABLE>", $table_name, $migrations);

		// Parse migration definitions
		list($states, $transitions, $max_reset, $max_version) = $this->parseStatesAndTransitions($migrations);

		// Get current version
		$current_version = $this->getCurrentVersion($table_name);

		if ($current_version === -1) { // table present, but no version - assume v1 just unstamped yet, stamp it with v1 and go from there
			$current_version = 1;
			$this->updateVersion($table_name, $current_version);
		}

		if ($current_version > $max_version)
			throw new \Exception("Current version $current_version of table '$table_name' exceeds maximum defined migration version $max_version");
		if ($current_version < 0)
			throw new \Exception("Invalid current version $current_version for table '$table_name'");
		if ($current_version == $max_version)
			return [
				'status' => 'already_current',
				'current_version' => $current_version,
				'target_version' => $max_version,
			];

		$queries = [];
		$created = false;
		$migrated = 0;

		// If table doesn't exist, create it at the highest reset point
		if ($current_version === 0) {
			$queries[$max_reset] = $states[$max_reset];
			$current_version = $max_reset;
			$created = true;
		}

		for ($i = $current_version + 1; $i <= $max_version; $i++) {
			$transition_key = ($i - 1) . ">" . $i;
			if (!isset($transitions[$transition_key])) {
				throw new \Exception("No migration path defined for version $i. Missing transition '$transition_key'");
			}
			$queries[$i] = $transitions[$transition_key];
			$migrated++;
		}

		// Execute migrations, updating version after every successful step
		if ($this->mysqli_conn) {
			// migrate using mysqli
			foreach ($queries as $ver=>$sql) {
				if (!$this->mysqli_conn->query($sql))
					throw new \Exception("Failed to execute migration SQL: " . $this->mysqli_conn->error);
				$this->updateVersion($table_name, $ver);
			}
		} elseif ($this->pdo_conn) {
			// migrate using PDO
			foreach ($queries as $ver=>$sql) {
				if ($this->pdo_conn->exec($sql) === false) {
					$errorInfo = $this->pdo_conn->errorInfo();
					throw new \Exception("Failed to execute migration SQL: " . $errorInfo[2]);
				}
				$this->updateVersion($table_name, $ver);
			}
		}

		return [
			'status' => 'migrated',
			'current_version' => $current_version,
			'target_version' => $max_version,
			'created' => $created,
			'migrated' => $migrated,
		];
	}

	private function parseStatesAndTransitions($migrations) {
		$states = [];
		$transitions = [];
		$max_version = 0;
		$max_reset = 0;

		foreach ($migrations as $key => $sql) {
			if (is_numeric($key)) {
				// State definition
				$version = (int)$key;
				if ($version < 1)
					throw new \InvalidArgumentException("State version must be a positive integer, got: $key");
				if (isset($states[$version]))
					throw new \InvalidArgumentException("Duplicate state definition for version $version");
				if (!preg_match('/^\s*CREATE\s+TABLE/i', $sql))
					throw new \InvalidArgumentException("State '$key' SQL must be a CREATE TABLE statement, got: " . substr($sql, 0, 50) . "...");
				$states[$version] = $sql;
				if ($version > $max_version) $max_version = $version;
				if ($version > $max_reset) $max_reset = $version;
			} elseif (strpos($key, '>') !== false) {
				// Transition definition
				$parts = explode('>', $key);
				if (count($parts) !== 2)
					throw new \InvalidArgumentException("Invalid transition key '$key', must be in format N>M");
				$from_version = (int)$parts[0];
				$to_version = (int)$parts[1];
				if ($from_version < 1 || $to_version < 1)
					throw new \InvalidArgumentException("Transition versions must be positive integers, got: $key");
				if ($to_version !== $from_version + 1)
					throw new \InvalidArgumentException("Transition '$key' skips version numbers. Must transition from N to N+1 only, got $from_version to $to_version");
				if (isset($transitions[$key]))
					throw new \InvalidArgumentException("Duplicate transition definition for '$key'");
				if (!preg_match('/^\s*ALTER\s+TABLE/i', $sql))
					throw new \InvalidArgumentException("Transition '$key' SQL must be an ALTER TABLE statement, got: " . substr($sql, 0, 50) . "...");
				$transitions[$key] = $sql;
				if ($to_version > $max_version) $max_version = $to_version;
			}
		}

		// check consistency
		for ($v = 1; $v < $max_version; $v++) {
			if (!isset($transitions[$v.">".($v+1)]))
				throw new \InvalidArgumentException("No migration path defined for version $v. Missing transition '$v>".($v+1)."'");
		}
		
		return [$states, $transitions, $max_reset, $max_version];
	}

	/**
	 * Get the comment for a table
	 * @param string $table_name Table name
	 * @return string|null Table comment, or null if table doesn't exist
	 */
	private function getComment($table_name) {
		if ($this->mysqli_conn) {
			// Check if table exists and get comment using MySQLi
			$table_escaped = $this->mysqli_conn->real_escape_string($table_name);
			$query = "SELECT TABLE_COMMENT FROM INFORMATION_SCHEMA.TABLES 
					  WHERE TABLE_SCHEMA = DATABASE() 
					  AND TABLE_NAME = '{$table_escaped}'";
			$result = $this->mysqli_conn->query($query);
			if (!$result || $result->num_rows === 0)
				return null; // Table doesn't exist
			$row = $result->fetch_assoc();

		} elseif ($this->pdo_conn) {
			// Check if table exists and get comment using PDO prepared statement
			$stmt = $this->pdo_conn->prepare("SELECT TABLE_COMMENT FROM INFORMATION_SCHEMA.TABLES 
					  WHERE TABLE_SCHEMA = DATABASE() 
					  AND TABLE_NAME = ?");
			$stmt->execute([$table_name]);
			$row = $stmt->fetch(\PDO::FETCH_ASSOC);
			if ($row === false)
				return null; // Table doesn't exist
		}

		return isset($row['TABLE_COMMENT']) ? $row['TABLE_COMMENT'] : '';
	}

	/**
	 * Set the comment for a table
	 * @param string $table_name Table name
	 * @param string $comment Comment to set
	 * @throws \Exception On failure
	 */
	private function setComment($table_name, $comment) {
		if ($this->mysqli_conn) {
			$new_comment_escaped = $this->mysqli_conn->real_escape_string($comment);
			$alter_query = "ALTER TABLE `{$table_name}` COMMENT = '{$new_comment_escaped}'";
			if (!$this->mysqli_conn->query($alter_query)) {
				$error = $this->mysqli_conn->error;
				throw new \Exception("Failed to update version for table '$table_name': " . $error);
			}
		} elseif ($this->pdo_conn) {
			// use prepared statement to avoid issues with quoting
			$alter_query = "ALTER TABLE {$table_name} COMMENT = ?";
			$stmt = $this->pdo_conn->prepare($alter_query);
			$stmt->bindParam(1, $comment);
			if (!$stmt->execute()) {
				$errorInfo = $stmt->errorInfo();
				$error = $errorInfo[2];
				throw new \Exception("Failed to update version for table '$table_name': " . $error);
			}
		}
	}
	/**
	 * Get the current version of a table from its comment
	 * @param string $table_name Table name
	 * @return int Current version (0 if table doesn't exist, -1 if it exists without a valid version marker)
	 * @throws \Exception On failure
	 */
	private function getCurrentVersion($table_name) {
		$comment = $this->getComment($table_name);

		if ($comment === null)
			return 0; // Table doesn't exist, treat as version 0

		// Extract version from comment
		if (preg_match('/' . self::COMMENT_VERSION_MARKER . '(\d+)/', $comment, $matches))
			return (int)$matches[1];

		return -1; // Invalid version (marker not found)
	}

	/**
	 * Update table version in its comment
	 * @param string $table_name Table name
	 * @param int $version New version
	 * @throws \Exception On failure
	 */
	private function updateVersion($table_name, $version) {
		$version = (int)$version;

		// Get current comment
		$current_comment = $this->getComment($table_name) ?: '';

		// Update or add version marker
		if (preg_match('/' . self::COMMENT_VERSION_MARKER . '\d+/', $current_comment)) {
			$new_comment = preg_replace('/' . self::COMMENT_VERSION_MARKER . '\d+/', self::COMMENT_VERSION_MARKER . $version, $current_comment);
		} else {
			$separator = $current_comment ? '; ' : '';
			$new_comment = $current_comment . $separator . self::COMMENT_VERSION_MARKER . $version;
		}

		// Truncate to MySQL comment length limit (2048 bytes)
		$new_comment = substr($new_comment, 0, 2048);

		$this->setComment($table_name, $new_comment);
	}

}
