<?php
namespace Djabeu\Migri;

/**
 * One-shot schema management library.
 * Manages table creation and versioned migrations with version tracking in table comments.
 * 
 * Usage:
 *   new Migri($db)->manageTable("users", [
 *       "1" => "CREATE TABLE users (...)",
 *       "1>2" => "ALTER TABLE users ADD COLUMN ...",
 *       "2>3" => "ALTER TABLE users ADD COLUMN ...",
 *       "3" => "CREATE TABLE users (...)",  // Reset point for fresh installs
 *       "3>4" => "ALTER TABLE users ADD COLUMN ...",
 *   ]);
 * 
 * All intermediate steps must be defined. Missing a step in the sequence throws an error.
 */
class Migri {
	/** @var mysqli */
	private $conn;

	private $states = []; // version => CREATE TABLE SQL
	private $transitions = []; // "N>M" => ALTER TABLE SQL
	private $max_reset = 0;
	private $max_version = 0;

	/** Marker used in table comment to store version */
	const COMMENT_VERSION_MARKER = 'VER=';

	/**
	 * Constructor
	 * @param mysqli $connection MySQLi connection object
	 */
	public function __construct($connection) {
		if (!$connection instanceof \mysqli)
			throw new \InvalidArgumentException("Connection must be a MySQLi instance");
		$this->conn = $connection;
	}

	/**
	 * Manage table schema: create if needed and migrate to target version
	 * @param string $table_name Table name
	 * @param array $migrations Associative array of migration keys and SQL statements:
	 *                          - "N" => "CREATE TABLE ..." : defines state at version N (shortcut for N to target)
	 *                          - "N>N+1" => "ALTER TABLE ..." : transition from version N to N+1
	 * @return array Associative array with migration results and status: ['status'=>'migrated|already_current', 'current_version'=>int, 'target_version'=>int, 'created'=>bool, 'migrated'=>int]
	 * @throws Exception If migration path is incomplete or other errors occur
	 */
	public function manageTable($table_name, $migrations) {
		if (is_string($migrations)) {
			// Shortcut: if a single CREATE TABLE is provided, treat it as the final state with a reset point at the same version
			$migrations = [
				"1" => $migrations,
			];
		} elseif (!is_array($migrations)) {
			throw new \InvalidArgumentException("Migrations must be a string or an array of migration definitions");
		}

		// Parse migration definitions
		list($this->states, $this->transitions, $this->max_reset, $this->max_version) = $this->parseStatesAndTransitions($migrations);

		// Get current version
		$current_version = $this->getCurrentVersion($table_name);

		if ($current_version === -1) {
			$current_version = 1;
			$this->updateVersion($table_name, $current_version);
		}

		if ($current_version > $this->max_version)
			throw new \Exception("Current version $current_version of table '$table_name' exceeds maximum defined migration version $this->max_version");
		if ($current_version < 0)
			throw new \Exception("Invalid current version $current_version for table '$table_name'");
		if ($current_version == $this->max_version)
			return [
				'status' => 'already_current',
				'current_version' => $current_version,
				'target_version' => $this->max_version,
				'applied' => 0,
			];

		$queries = [];
		$created = false;
		$migrated = 0;

		// If table doesn't exist, create it at the highest reset point
		if ($current_version === 0) {
			$queries[] = $this->states[$this->max_reset];
			$current_version = $this->max_reset;
			$created = true;
		}

		for ($i = $current_version + 1; $i <= $this->max_version; $i++) {
			$transition_key = ($i - 1) . ">" . $i;
			if (!isset($this->transitions[$transition_key])) {
				throw new \Exception("No migration path defined for version $i. Missing transition '$transition_key'");
			}
			$queries[] = $this->transitions[$transition_key];
			$migrated++;
		}

		// Execute migrations
		try {
			$this->conn->begin_transaction();
			foreach ($queries as $sql) {
				if (!$this->conn->query($sql)) {
					throw new \Exception("Failed to execute migration SQL: " . $this->conn->error);
				}
			}
			$this->updateVersion($table_name, $this->max_version);
			$this->conn->commit();

			return [
				'status' => 'migrated',
				'current_version' => $current_version,
				'target_version' => $this->max_version,
				'created' => $created,
				'migrated' => $migrated,
			];

		} catch (\Exception $e) {
			$this->conn->rollback();
			throw $e;
		}
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

	private function getComment($table_name) {
		$table_escaped = $this->conn->real_escape_string($table_name);

		$query = "SELECT TABLE_COMMENT FROM INFORMATION_SCHEMA.TABLES 
				  WHERE TABLE_SCHEMA = DATABASE() 
				  AND TABLE_NAME = '{$table_escaped}'";
		$result = $this->conn->query($query);

		if (!$result || $result->num_rows === 0)
			return null; // Table doesn't exist

		$row = $result->fetch_assoc();
		return isset($row['TABLE_COMMENT']) ? $row['TABLE_COMMENT'] : '';
	}

	/**
	 * Get the current version of a table from its comment
	 * @param string $table_name Table name
	 * @return int Current version (0 if table doesn't exist, -1 if it exists without a valid version marker)
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
	 * @throws Exception On failure
	 */
	private function updateVersion($table_name, $version) {
		$table_escaped = $this->conn->real_escape_string($table_name);
		$version = (int)$version;

		// Get current comment
		$current_comment = $this->getComment($table_name);

		// Update or add version marker
		if (preg_match('/' . self::COMMENT_VERSION_MARKER . '\d+/', $current_comment)) {
			$new_comment = preg_replace('/' . self::COMMENT_VERSION_MARKER . '\d+/', self::COMMENT_VERSION_MARKER . $version, $current_comment);
		} else {
			$separator = $current_comment ? '; ' : '';
			$new_comment = $current_comment . $separator . self::COMMENT_VERSION_MARKER . $version;
		}

		// Truncate to MySQL comment length limit (2048 bytes)
		$new_comment = substr($new_comment, 0, 2048);
		$new_comment_escaped = $this->conn->real_escape_string($new_comment);

		$alter_query = "ALTER TABLE `{$table_escaped}` COMMENT = '{$new_comment_escaped}'";
		if (!$this->conn->query($alter_query))
			throw new \Exception("Failed to update version for table '$table_name': " . $this->conn->error);
	}
}
