Migri is a simple schema migration library.
It manages MySQL table creation and versioned migrations with version tracking in table comments.

Migrations are defined as an associative array, where keys define "checkpoints" (CREATE TABLE) or "transitions" (ALTER TABLE).
Multiple table definitions can be daisy-chained.
 
Usage:
```php
$db = new \mysqli(...); // or new \PDO(...)
new \SinusPi\Migri\Migri($db)
	->manageTable("users", [
		"1"   => "CREATE TABLE users (...)",
		"1>2" => "ALTER TABLE users ADD COLUMN ...",
		"2>3" => "ALTER TABLE users ADD COLUMN ...",
		"3"   => "CREATE TABLE users (...)",  // Reset point for fresh installs
		"3>4" => "ALTER TABLE users ADD COLUMN ...",
	])
	->manageTable("widgets", [
		"1" => "CREATE TABLE <TABLE> (...)"
	])
	;
```

*All* intermediate steps *must* be defined. Missing a step in the sequence throws an error.

Optionally, use `<TABLE>` placeholder to avoid repetition of table name.
 
Compatible with PHP 5.6+, \mysqli and \PDO.
