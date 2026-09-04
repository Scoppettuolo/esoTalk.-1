<?php
/**
 * esoTalk database abstraction.
 * MySQL installations continue to use mysqli; SQLite installations use PDO.
 */
if (!defined("IN_ESO")) exit;

#[\AllowDynamicProperties]
class Database {

public $eso;
protected $link;
protected $host;
protected $user;
protected $password;
protected $db;
protected $encoding;
protected $driver = "mysqli";
protected $sqlitePath;
protected $lastError = "";
protected $lastAffectedRows = 0;

public function __construct($host, $user, $password, $db, $encoding = "utf8mb4", $driver = "mysqli", $sqlitePath = null)
{
	$this->host = $host;
	$this->user = $user;
	$this->password = $password;
	$this->db = $db;
	$this->encoding = $encoding;
	$this->driver = strtolower((string)$driver) === "sqlite" ? "sqlite" : "mysqli";
	$this->sqlitePath = $sqlitePath ?: $db;

	if ($this->isSQLite()) {
		if (!extension_loaded("pdo_sqlite")) {
			$this->lastError = "PDO SQLite is not enabled.";
			return;
		}
			$path = trim((string)($this->sqlitePath ?: ":memory:"));
			if ($path === "") $path = ":memory:";
			if ($path !== ":memory:" && preg_match('/[\\x00-\\x1F\\x7F]/', $path)) {
				// Older config writers did not escape Windows backslashes. Recover the
				// database filename inside the forum config directory instead of trying
				// to create a directory containing control characters.
				$compactPath = preg_replace('/[^A-Za-z0-9._-]/', '', $path);
				if (preg_match('/(?:esotalk|configsotalk)\\.sqlite$/i', $compactPath)) {
					// A previous writer could turn the final "\\e" into ESC and
					// leave the historical path looking like configsotalk.sqlite.
					$filename = "esotalk.sqlite";
				} else {
					$filename = $path;
					$slash = strrpos($filename, "/");
					$backslash = strrpos($filename, "\\");
					$separator = max($slash === false ? -1 : $slash, $backslash === false ? -1 : $backslash);
					if ($separator >= 0) $filename = substr($filename, $separator + 1);
					$filename = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$filename);
				}
				$path = (defined("PATH_CONFIG") ? PATH_CONFIG : (realpath(__DIR__ . "/../config") ?: dirname(__DIR__) . "/config")) . DIRECTORY_SEPARATOR . ($filename ?: "esotalk.sqlite");
			}
			if ($path !== ":memory:") {
				// Config files from older installs may contain a relative SQLite path.
				// Resolve it against the forum root instead of Apache's current working directory.
$isAbsolute = (DIRECTORY_SEPARATOR === "/" && substr($path, 0, 1) === "/")
						|| preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
						|| substr($path, 0, 2) === "\\\\";
					if (!$isAbsolute) {
					$root = defined("PATH_ROOT") ? PATH_ROOT : (realpath(__DIR__ . "/..") ?: getcwd());
					$path = rtrim($root, "/\\\\") . DIRECTORY_SEPARATOR . ltrim($path, "/\\\\");
				}
				$this->sqlitePath = $path;
				$directory = dirname($path);
				if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
					$this->lastError = "The SQLite database directory could not be created.";
					return;
				}
			}
			try {
			$this->link = new PDO("sqlite:".$path, null, null, array(
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH,
				PDO::ATTR_EMULATE_PREPARES => false
			));
				$this->link->exec("PRAGMA foreign_keys = ON");
				$this->registerSQLiteFunctions();
				$this->ensureSQLiteCompatibility();
		} catch (PDOException $e) {
			$this->lastError = $e->getMessage();
		}
	} else {
		if (!function_exists("mysqli_connect")) {
			$this->lastError = "The mysqli extension is not enabled.";
			return;
		}
		mysqli_report(MYSQLI_REPORT_OFF);
		$this->link = @mysqli_connect($host, $user, $password, $db);
		if ($this->link) @mysqli_set_charset($this->link, $encoding);
		else $this->lastError = function_exists("mysqli_connect_error") ? (mysqli_connect_error() ?: "Could not connect to MySQL.") : "Could not connect to MySQL.";
	}
}

public function isSQLite()
{
	return $this->driver === "sqlite";
}

public function getDriver()
{
	return $this->driver;
}

protected function registerSQLiteFunctions()
{
	if (!method_exists($this->link, "sqliteCreateFunction")) return;
	$this->link->sqliteCreateFunction("IF", function($condition, $yes, $no) { return $condition ? $yes : $no; }, 3);
	$this->link->sqliteCreateFunction("LEFT", function($value, $length) { return substr((string)$value, 0, (int)$length); }, 2);
	$this->link->sqliteCreateFunction("UNIX_TIMESTAMP", function() { return time(); }, 0);
	$this->link->sqliteCreateFunction("VERSION", function() { return defined("SQLITE3_VERSION") ? "SQLite ".SQLITE3_VERSION : "SQLite"; }, 0);
	$this->link->sqliteCreateFunction("RAND", function() { return mt_rand() / mt_getrandmax(); }, 0);
	$this->link->sqliteCreateFunction("FLOOR", function($value) { return floor((float)$value); }, 1);
	$this->link->sqliteCreateFunction("FIELD", function($value, ...$items) {
		$position = array_search($value, $items, true);
		return $position === false ? 0 : $position + 1;
	}, -1);
	$this->link->sqliteCreateFunction("CONCAT", function(...$items) { return implode("", array_map(function($v) { return (string)$v; }, $items)); }, -1);
	$this->link->sqliteCreateFunction("CONCAT_WS", function($separator, ...$items) {
		return implode((string)$separator, array_values(array_filter($items, function($v) { return $v !== null; })));
	}, -1);
}


	protected function ensureSQLiteCompatibility()
	{
		global $config;
		$prefix = isset($config["tablePrefix"]) ? (string)$config["tablePrefix"] : "";
		if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) return;
		$table = $prefix . "members";
		$quotedName = str_replace("'", "''", $table);
		$quotedIdentifier = str_replace("`", "``", $table);
		try {
			$exists = $this->link->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $quotedName . "'")->fetchColumn();
			if (!$exists) return;
			$columns = $this->link->query("PRAGMA table_info('" . $quotedName . "')")->fetchAll(PDO::FETCH_COLUMN, 1);
			if (!in_array("emoticons", $columns, true)) $this->link->exec("ALTER TABLE `" . $quotedIdentifier . "` ADD COLUMN emoticons INTEGER NOT NULL DEFAULT 0");
		} catch (Throwable $e) {
			$this->lastError = $e->getMessage();
		}
	}

	protected function replaceSQLiteFunction($query, $name, $callback)

{
	$offset = 0; $nameLength = strlen($name);
	while (($position = stripos($query, $name, $offset)) !== false) {
		$before = $position > 0 ? $query[$position - 1] : '';
		if ($before !== '' && preg_match('/[A-Za-z0-9_]/', $before)) { $offset = $position + $nameLength; continue; }
		$open = $position + $nameLength;
		while ($open < strlen($query) && ctype_space($query[$open])) $open++;
		if ($open >= strlen($query) || $query[$open] !== '(') { $offset = $position + $nameLength; continue; }
		$depth = 0; $quote = null; $close = null; $length = strlen($query);
		for ($i = $open; $i < $length; $i++) {
			$c = $query[$i];
			if ($quote !== null) { if ($c === $quote && ($i === 0 || $query[$i - 1] !== '\\\\')) $quote = null; continue; }
			if ($c === "'" || $c === '"') { $quote = $c; continue; }
			if ($c === '(') $depth++;
			elseif ($c === ')' && --$depth === 0) { $close = $i; break; }
		}
		if ($close === null) break;
		$inside = substr($query, $open + 1, $close - $open - 1);
		$replacement = call_user_func($callback, $this->splitSQLiteList($inside));
		$query = substr($query, 0, $position).$replacement.substr($query, $close + 1);
		$offset = $position + strlen($replacement);
	}
	return $query;
}

public function rewriteSQLiteQuery($query)
{
	$query = preg_replace('/^\\s*\\((SELECT\\b.*)\\)\\s+UNION\\s+\\((SELECT\\b.*)\\)\\s*$/is', '$1 UNION $2', $query);
	$query = preg_replace_callback('/SHOW\\s+COLUMNS\\s+FROM\\s+`?([A-Za-z0-9_]+)`?\\s+LIKE\\s+([\'\"].*?[\'\"])/i', function($m) {
		return "SELECT name FROM pragma_table_info('".$m[1]."') WHERE name LIKE ".$m[2];
	}, $query);
	$query = preg_replace('/SHOW\\s+COLUMNS\\s+FROM\\s+`?([A-Za-z0-9_]+)`?/i', "SELECT name FROM pragma_table_info('$1')", $query);
	$query = preg_replace('/\\(\\s*!\\s*([A-Za-z_][A-Za-z0-9_.]*)\\s*\\)/i', '(NOT $1)', $query);
	$query = preg_replace('/\\s+USE INDEX\\s*\\([^)]*\\)/i', '', $query);
	if (preg_match('/\\bUPDATE\\s+(`?[A-Za-z0-9_]+`?)\\s+(?:AS\\s+)?([A-Za-z_][A-Za-z0-9_]*)\\s+SET\\b/i', $query, $updateAlias)) {
		$alias = preg_quote($updateAlias[2], '/');
		$query = preg_replace('/\\b'.$alias.'\\./i', '', $query);
		$query = preg_replace('/\\bUPDATE\\s+(`?[A-Za-z0-9_]+`?)\\s+(?:AS\\s+)?([A-Za-z_][A-Za-z0-9_]*)\\s+SET\\b/i', 'UPDATE $1 SET', $query, 1);
	}
	$query = preg_replace_callback('/^\\s*DELETE\\s+([A-Za-z_][A-Za-z0-9_]*)\\s+FROM\\s+(`?[A-Za-z0-9_]+`?)\\s+\\1\\s+LEFT\\s+JOIN.*?\\s+WHERE\\s+(.+)$/is', function($m) {
		$where = preg_replace('/\\b'.preg_quote($m[1], '/').'\\./i', '', $m[3]);
		return 'DELETE FROM '.$m[2].' WHERE '.$where;
	}, $query);
	$query = preg_replace_callback('/MATCH\\s*\\(([^)]*)\\)\\s+AGAINST\\s*\\(\\s*(.*?)\\s*(?:IN\\s+BOOLEAN\\s+MODE)?\\s*\\)/is', function($m) {
		$columns = preg_split('/\\s*,\\s*/', trim($m[1]));
		$parts = array();
		foreach ($columns as $column) if ($column !== '') $parts[] = "COALESCE($column,'')";
		if (!$parts) return '0';
		return '(INSTR('.implode(" || ' ' || ", $parts).','.$m[2].') > 0)';
	}, $query);
	$query = preg_replace('/\\bON DUPLICATE KEY UPDATE\\b/i', 'ON CONFLICT DO UPDATE SET', $query);
	$query = preg_replace('/\\bVALUES\\s*\\(\\s*([A-Za-z_][A-Za-z0-9_]*)\\s*\\)/i', 'excluded.$1', $query);
	$query = preg_replace('/\\bLIMIT\\s+(\\?|[0-9]+)\\s*,\\s*(\\?|[0-9]+)/i', 'LIMIT $2 OFFSET $1', $query);
	$query = preg_replace_callback('/GROUP_CONCAT\\s*\\(\\s*(.*?)\\s+ORDER BY\\s+.*?\\s+SEPARATOR\\s+([\'\"])(.*?)\\2\\s*\\)/is', function($m) {
			return "GROUP_CONCAT(".$m[1].",'".str_replace("'", "''", $m[3])."')";
		}, $query);

	// SQLite does not need MySQL's IF() function; CASE also avoids parser differences
	// in older SQLite libraries bundled with PHP/XAMPP.
	while (preg_match('/\\bIF\\s*\\(/i', $query, $match, PREG_OFFSET_CAPTURE)) {
		$start = $match[0][1];
		$open = strpos($query, '(', $start);
		$depth = 0; $quote = null; $close = null; $length = strlen($query);
		for ($i = $open; $i < $length; $i++) {
			$c = $query[$i];
			if ($quote !== null) {
				if ($c === $quote && ($i === 0 || $query[$i - 1] !== '\\\\')) $quote = null;
				continue;
			}
			if ($c === "'" || $c === '"') { $quote = $c; continue; }
			if ($c === '(') $depth++;
			elseif ($c === ')' && --$depth === 0) { $close = $i; break; }
		}
		if ($close === null) break;
		$inside = substr($query, $open + 1, $close - $open - 1);
		$args = array(); $part = ''; $nested = 0; $quote = null;
		for ($i = 0, $n = strlen($inside); $i < $n; $i++) {
			$c = $inside[$i];
			if ($quote !== null) {
				$part .= $c;
				if ($c === $quote && ($i === 0 || $inside[$i - 1] !== '\\\\')) $quote = null;
				continue;
			}
			if ($c === "'" || $c === '"') { $quote = $c; $part .= $c; continue; }
			if ($c === '(') $nested++;
			elseif ($c === ')') $nested--;
			if ($c === ',' && $nested === 0) { $args[] = trim($part); $part = ''; }
			else $part .= $c;
		}
		$args[] = trim($part);
		if (count($args) !== 3) break;
			$query = substr($query, 0, $start).'(CASE WHEN '.$args[0].' THEN '.$args[1].' ELSE '.$args[2].' END)'.substr($query, $close + 1);
		}
		$query = preg_replace('/\\bUNIX_TIMESTAMP\\s*\\(\\s*\\)/i', "CAST(strftime('%s','now') AS INTEGER)", $query);
		$query = preg_replace('/\\bVERSION\\s*\\(\\s*\\)/i', 'sqlite_version()', $query);
		$query = $this->replaceSQLiteFunction($query, 'LEFT', function($args) {
			return count($args) === 2 ? 'SUBSTR('.$args[0].',1,'.$args[1].')' : 'NULL';
		});
		$query = $this->replaceSQLiteFunction($query, 'CONCAT_WS', function($args) {
			if (count($args) < 2) return "''";
			$separator = array_shift($args); $parts = array();
			foreach ($args as $arg) $parts[] = "COALESCE(".$arg.",'')";
			return '('.implode(' || '.$separator.' || ', $parts).')';
		});
		$query = $this->replaceSQLiteFunction($query, 'CONCAT', function($args) {
			if (!$args) return "''";
			return '('.implode(' || ', array_map(function($arg) { return "COALESCE(".$arg.",'')"; }, $args)).')';
		});
		$query = $this->replaceSQLiteFunction($query, 'FIELD', function($args) {
			if (count($args) < 2) return '0';
			$value = array_shift($args); $case = 'CASE';
			foreach ($args as $i => $arg) $case .= ' WHEN '.$value.'='.$arg.' THEN '.($i + 1);
			return $case.' ELSE 0 END';
		});
		$query = $this->replaceSQLiteFunction($query, 'FLOOR', function($args) {
			return count($args) === 1 ? 'CAST(('.$args[0].') AS INTEGER)' : '0';
		});
		$query = preg_replace('/\\bRAND\\s*\\(\\s*\\)/i', '(ABS(random()) / 9223372036854775807.0)', $query);
		return $query;
	}

public function connected()
{
	return $this->isSQLite() ? ($this->link instanceof PDO) : (bool)$this->link;
}

public function connectionHandle()
{
	return $this->link;
}

public function setLastError($error)
{
	$this->lastError = (string)$error;
}

public function query($query, $fatal = true)
{
	global $language, $config;
	if (!$query || !$this->connected()) return false;
	if ($this->eso && method_exists($this->eso, "callHook")) $this->eso->callHook("beforeDatabaseQuery", array(&$query));
	$originalQuery = $query;
		if ($this->isSQLite()) $query = $this->rewriteSQLiteQuery($query);
		try {
		if ($this->isSQLite()) {
				$statement = preg_match('/\\bON\\s+CONFLICT(?:\\s*\\([^)]*\\))?\\s+DO\\s+UPDATE\\s+SET\\b/i', $query)
					? $this->executeSQLiteUpsert($this->link, $query)
					: $this->link->query($query);
				$result = ($statement instanceof PDOStatement && $statement->columnCount() > 0) ? new SQLiteResult($statement) : true;
			$this->lastAffectedRows = ($statement instanceof PDOStatement) ? $statement->rowCount() : 0;
		} else {
			$result = mysqli_query($this->link, $query);
			$this->lastAffectedRows = $result ? mysqli_affected_rows($this->link) : 0;
		}
	} catch (Throwable $e) {
		$this->lastError = $e->getMessage();
		$result = false;
	}
	if (!$result && $fatal) {
		$error = $this->error();
		if ($this->eso && method_exists($this->eso, "fatalError"))
			$this->eso->fatalError(!empty($config["verboseFatalErrors"]) ? $error . "<p style='font:100% monospace; overflow:auto'>" . $this->highlightQueryErrors($originalQuery, $error) . "</p>" : "", "mysql");
	}
	if ($this->eso && method_exists($this->eso, "callHook")) $this->eso->callHook("afterDatabaseQuery", array($originalQuery, &$result));
	return $result;
}

/** Split a SQL list while respecting quoted strings and nested parentheses. */
public function splitSQLiteList($text)
{
	$parts = array(); $start = 0; $depth = 0; $quote = null; $length = strlen($text);
	for ($i = 0; $i < $length; $i++) {
		$c = $text[$i];
		if ($quote !== null) {
			if ($c === $quote) {
				if ($i + 1 < $length && $text[$i + 1] === $quote) { $i++; continue; }
				$quote = null;
			}
			continue;
		}
		if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; continue; }
		if ($c === '(') $depth++;
		elseif ($c === ')') $depth--;
		elseif ($c === ',' && $depth === 0) { $parts[] = trim(substr($text, $start, $i - $start)); $start = $i + 1; }
	}
	$parts[] = trim(substr($text, $start));
	return array_values(array_filter($parts, function($part) { return $part !== ''; }));
}

/** Execute an upsert without relying on SQLite's newer ON CONFLICT ... DO UPDATE syntax. */
public function executeSQLiteUpsert($connection, $query)
{
	if (!preg_match('/^\\s*INSERT\\s+INTO\\s+(`?[A-Za-z0-9_]+`?)\\s*\\((.*?)\\)\\s+VALUES\\s*(.*?)\\s+ON\\s+CONFLICT(?:\\s*\\([^)]*\\))?\\s+DO\\s+UPDATE\\s+SET\\s+(.+)\\s*$/is', $query, $m))
		return $connection->query($query);
	$table = $m[1];
	$fields = $this->splitSQLiteList($m[2]);
	$rowsText = trim($m[3]);
	$assignments = $this->splitSQLiteList($m[4]);
	$tableName = trim($table, '` ');
	$primary = array();
	$schema = $connection->query("PRAGMA table_info('".str_replace("'", "''", $tableName)."')")->fetchAll(PDO::FETCH_ASSOC);
	foreach ($schema as $row) if (!empty($row['pk'])) $primary[(int)$row['pk']] = $row['name'];
	ksort($primary); $primary = array_values($primary);
	if (!$primary) return $connection->query($query);
	$lastStatement = null;
	foreach ($this->splitSQLiteList($rowsText) as $rowText) {
		$rowText = trim($rowText);
		if (substr($rowText, 0, 1) !== '(' || substr($rowText, -1) !== ')') continue;
		$values = $this->splitSQLiteList(substr($rowText, 1, -1));
		$lastStatement = $connection->query("INSERT OR IGNORE INTO $table (".implode(', ', $fields).") VALUES (".implode(', ', $values).")");
		$changed = (int)$connection->query("SELECT changes()")->fetchColumn();
		if ($changed > 0) continue;
		$valueByField = array();
		foreach ($fields as $i => $field) $valueByField[trim($field, '` ')] = $values[$i] ?? 'NULL';
		$set = array();
		foreach ($assignments as $assignment) {
			$eq = strpos($assignment, '=');
			if ($eq === false) continue;
			$field = trim(substr($assignment, 0, $eq));
			$expression = trim(substr($assignment, $eq + 1));
			$expression = preg_replace_callback('/\\bexcluded\\.(`?[A-Za-z0-9_]+`?)\\b/i', function($match) use ($valueByField) {
				return $valueByField[trim($match[1], '` ')] ?? 'NULL';
			}, $expression);
			$set[] = "$field=$expression";
		}
		if (!$set) continue;
		$where = array();
		foreach ($primary as $key) $where[] = "`$key`=".($valueByField[$key] ?? 'NULL');
		$lastStatement = $connection->query("UPDATE $table SET ".implode(', ', $set)." WHERE ".implode(' AND ', $where));
	}
	return $lastStatement ?: $connection->query("SELECT 1 WHERE 0");
}

public function highlightQueryErrors($query, $error)
{
	preg_match("/'(.+?)'/", $error, $matches);
	if (!empty($matches[1])) $query = str_replace($matches[1], "<span style='color:#f00'>{$matches[1]}</span>", $query);
	return $query;
}

public function affectedRows()
{
	return $this->isSQLite() ? $this->lastAffectedRows : ($this->link ? mysqli_affected_rows($this->link) : 0);
}

public function fetchAssoc($input)
{
	if ($input instanceof SQLiteResult) return $input->fetch_assoc();
	if ($input instanceof mysqli_result) return mysqli_fetch_assoc($input);
	$result = $this->query($input);
	return $result ? $this->fetchAssoc($result) : false;
}

public function fetchRow($input)
{
	if ($input instanceof SQLiteResult) return $input->fetch_row();
	if ($input instanceof mysqli_result) return mysqli_fetch_row($input);
	$result = $this->query($input);
	return $result ? $this->fetchRow($result) : false;
}

public function fetchObject($input)
{
	if ($input instanceof SQLiteResult) return $input->fetch_object();
	if ($input instanceof mysqli_result) return mysqli_fetch_object($input);
	$result = $this->query($input);
	return $result ? $this->fetchObject($result) : false;
}

protected function fetchResult($input, $row, $field = 0)
{
	if ($input instanceof SQLiteResult) $input->data_seek($row);
	elseif ($input instanceof mysqli_result) mysqli_data_seek($input, $row);
	$datarow = $input instanceof SQLiteResult ? $input->fetch_array() : mysqli_fetch_array($input);
	return $datarow === null || $datarow === false ? false : ($datarow[$field] ?? false);
}

public function result($input, $row = 0)
{
	if ($input instanceof SQLiteResult || $input instanceof mysqli_result) return $this->fetchResult($input, $row);
	$result = $this->query($input);
	return $result ? $this->result($result, $row) : false;
}

public function lastInsertId()
{
	if ($this->isSQLite()) return $this->link ? $this->link->lastInsertId() : false;
	return $this->link ? mysqli_insert_id($this->link) : false;
}

public function numRows($input)
{
	if (!$input) return false;
	if ($input instanceof SQLiteResult) return $input->num_rows();
	if ($input instanceof mysqli_result) return mysqli_num_rows($input);
	$result = $this->query($input);
	return $result ? $this->numRows($result) : false;
}

public function connectError()
{
	return $this->connected() ? "" : $this->lastError;
}

public function error()
{
	if (!$this->connected()) return $this->lastError ?: "No database connection";
	if ($this->isSQLite()) return $this->lastError ?: "SQLite query failed";
	return mysqli_error($this->link);
}

public function escape($string)
{
	if ($this->isSQLite()) return str_replace("'", "''", (string)$string);
	return $this->link ? mysqli_real_escape_string($this->link, (string)$string) : addslashes((string)$string);
}

public function constructSelectQuery($components)
{
	$select = isset($components["select"]) ? (is_array($components["select"]) ? implode(", ", $components["select"]) : $components["select"]) : false;
	$from = isset($components["from"]) ? (is_array($components["from"]) ? implode("\n\t", $components["from"]) : $components["from"]) : false;
	$groupBy = isset($components["groupBy"]) ? (is_array($components["groupBy"]) ? implode(", ", $components["groupBy"]) : $components["groupBy"]) : false;
	$where = isset($components["where"]) ? (is_array($components["where"]) ? "(" . implode(")\n\tAND (", $components["where"]) . ")" : $components["where"]) : false;
	$having = isset($components["having"]) ? (is_array($components["having"]) ? "(" . implode(") AND (", $components["having"]) . ")" : $components["having"]) : false;
	$orderBy = isset($components["orderBy"]) ? (is_array($components["orderBy"]) ? implode(", ", $components["orderBy"]) : $components["orderBy"]) : false;
	$limit = $components["limit"] ?? false;
	return ($select ? "SELECT $select\n" : "") . ($from ? "FROM $from\n" : "") . ($where ? "WHERE $where\n" : "") . ($having ? "HAVING $having\n" : "") . ($groupBy ? "GROUP BY $groupBy\n" : "") . ($orderBy ? "ORDER BY $orderBy\n" : "") . ($limit ? "LIMIT $limit" : "");
}

public function constructInsertQuery($table, $data)
{
	global $config;
	return "INSERT INTO {$config["tablePrefix"]}$table (" . implode(", ", array_keys($data)) . ") VALUES (" . implode(", ", $data) . ")";
}

public function constructUpdateQuery($table, $data, $conditions)
{
	global $config;
	$update = "";
	foreach ($data as $k => $v) $update .= "$k=$v, ";
	$update = rtrim($update, ", ");
	$where = "";
	foreach ($conditions as $k => $v) $where .= "$k=$v AND ";
	$where = rtrim($where, " AND ");
	return "UPDATE {$config["tablePrefix"]}$table SET $update WHERE $where";
}

public function prepare($query, $fatal = true)
{
	global $config;
	if (!$query || !$this->connected()) return false;
	if ($this->isSQLite()) return new PreparedStatement($query, $this, $query, true, $fatal);
	$stmt = mysqli_prepare($this->link, $query);
	if (!$stmt) {
		if ($fatal && $this->eso && method_exists($this->eso, "fatalError")) $this->eso->fatalError(!empty($config["verboseFatalErrors"]) ? $this->error() : "", "mysql");
		return false;
	}
	return new PreparedStatement($stmt, $this, $query, false, $fatal);
}

public function queryPrepared($query, $types, ...$params)
{
	$stmt = $this->prepare($query);
	if (!$stmt) return false;
	$stmt->bindParams($types, ...$params);
	return $stmt->execute() ? $stmt : false;
}

public function fetchPrepared($query, $types, ...$params)
{
	$stmt = $this->queryPrepared($query, $types, ...$params);
	return $stmt ? $stmt->getResult() : false;
}

public function fetchOne($query, $types, ...$params)
{
	$result = $this->fetchPrepared($query, $types, ...$params);
	return $result ? $this->result($result, 0) : false;
}

public function fetchRowPrepared($query, $types, ...$params)
{
	$result = $this->fetchPrepared($query, $types, ...$params);
	return $result ? $this->fetchRow($result) : false;
}

public function fetchAssocPrepared($query, $types, ...$params)
{
	$result = $this->fetchPrepared($query, $types, ...$params);
	return $result ? $this->fetchAssoc($result) : false;
}

public function exists($query, $types, ...$params)
{
	$result = $this->fetchPrepared($query, $types, ...$params);
	return $result ? (bool)$this->result($result, 0) : false;
}

public function fetchPreparedIn($query, $inTypes, $inValues, $otherTypes = "", ...$otherParams)
{
	if (empty($inValues)) return false;
	$inPlaceholders = str_repeat("?,", count($inValues) - 1) . "?";
	$query = preg_replace('/\\bIN\\s*\\(\\?\\)/', "IN ($inPlaceholders)", $query, 1);
	$types = "";
	foreach ($inValues as $val) $types .= is_int($val) ? "i" : (is_float($val) ? "d" : "s");
	return $this->fetchPrepared($query, $types.$otherTypes, ...array_merge($inValues, $otherParams));
}

public function queryPreparedIn($query, $inTypes, $inValues, $otherTypes = "", ...$otherParams)
{
	if (empty($inValues)) return false;
	$inPlaceholders = str_repeat("?,", count($inValues) - 1) . "?";
	$query = preg_replace('/\\bIN\\s*\\(\\?\\)/', "IN ($inPlaceholders)", $query, 1);
	$types = "";
	foreach ($inValues as $val) $types .= is_int($val) ? "i" : (is_float($val) ? "d" : "s");
	return $this->queryPrepared($query, $types.$otherTypes, ...array_merge($inValues, $otherParams));
}

}

class SQLiteResult {
	protected $rows = array();
	protected $position = 0;
	public function __construct($statement) { $this->rows = $statement->fetchAll(PDO::FETCH_BOTH); }
	public function fetch_assoc() {
		$row = $this->rows[$this->position++] ?? null;
		if ($row === null) return false;
		return array_filter($row, function($v, $k) { return !is_int($k); }, ARRAY_FILTER_USE_BOTH);
	}
	public function fetch_row() {
		$row = $this->rows[$this->position++] ?? null;
		if ($row === null) return false;
		return array_values(array_filter($row, function($v, $k) { return is_int($k); }, ARRAY_FILTER_USE_BOTH));
	}
	public function fetch_array() { return $this->rows[$this->position++] ?? null; }
		public function fetch_object() { $row = $this->rows[$this->position++] ?? null; return $row === null ? false : (object)$this->fetch_assoc_from_row($row); }
	protected function fetch_assoc_from_row($row) { return array_filter($row, function($v, $k) { return !is_int($k); }, ARRAY_FILTER_USE_BOTH); }
	public function data_seek($row) { $this->position = max(0, (int)$row); }
	public function num_rows() { return count($this->rows); }
}

class PreparedStatement {
	protected $stmt;
	protected $db;
	protected $query;
	protected $sqlite = false;
	protected $fatal = true;
	protected $types = "";
	protected $params = array();
	protected $result = false;
	protected $executed = false;
	protected $affected = 0;

	public function __construct($stmt, $db, $query, $sqlite = false, $fatal = true)
	{
		$this->stmt = $stmt;
		$this->db = $db;
		$this->query = $query;
		$this->sqlite = $sqlite;
		$this->fatal = $fatal;
	}

	public function bindParams($types, ...$params)
	{
		if (is_array($types) && count($types) > 0 && is_string($types[0])) {
			$this->types = $types[0];
			$this->params = array_slice($types, 1);
		} elseif (count($params) == 1 && is_array($params[0])) {
			$this->types = $types;
			$this->params = $params[0];
		} else {
			$this->types = $types;
			$this->params = $params;
		}
		if (!$this->sqlite && !empty($this->params)) {
			$refs = array();
			foreach ($this->params as $key => &$value) $refs[$key] = &$value;
			array_unshift($refs, $this->types);
			call_user_func_array(array($this->stmt, 'bind_param'), $refs);
		}
		return $this;
	}

	public function execute($fatal = true)
	{
		global $config;
		$queryString = $this->getQueryString();
		if ($this->db->eso && method_exists($this->db->eso, "callHook")) $this->db->eso->callHook("beforeDatabaseQuery", array(&$queryString));
			if ($this->sqlite) {
				try {
					$sql = $this->db->rewriteSQLiteQuery($this->query);
					if (preg_match('/\\bON\\s+CONFLICT(?:\\s*\\([^)]*\\))?\\s+DO\\s+UPDATE\\s+SET\\b/i', $sql)) {
						$stmt = $this->db->executeSQLiteUpsert($this->db->connectionHandle(), $this->db->rewriteSQLiteQuery($queryString));
						$this->result = ($stmt instanceof PDOStatement && $stmt->columnCount() > 0) ? new SQLiteResult($stmt) : false;
						$this->affected = ($stmt instanceof PDOStatement) ? $stmt->rowCount() : 0;
					} else {
						$stmt = $this->db->connectionHandle()->prepare($sql);
						foreach ($this->params as $i => $value) {
							$type = $this->types[$i] ?? "s";
							$pdoType = $type === "i" ? PDO::PARAM_INT : ($type === "b" ? PDO::PARAM_LOB : PDO::PARAM_STR);
							$stmt->bindValue($i + 1, $value, $pdoType);
						}
						$stmt->execute();
						$this->result = $stmt->columnCount() > 0 ? new SQLiteResult($stmt) : false;
						$this->affected = $stmt->rowCount();
					}
					$this->executed = true;
								} catch (Throwable $e) {
					$this->db->setLastError($e->getMessage());
					if (($fatal || $this->fatal) && $this->db->eso && method_exists($this->db->eso, "fatalError")) $this->db->eso->fatalError(!empty($config["verboseFatalErrors"]) ? $e->getMessage() : "", "mysql");
				return false;
			}
		} else {
			if (!mysqli_stmt_execute($this->stmt)) {
				if (($fatal || $this->fatal) && $this->db->eso && method_exists($this->db->eso, "fatalError")) $this->db->eso->fatalError(!empty($config["verboseFatalErrors"]) ? mysqli_stmt_error($this->stmt) : "", "mysql");
				return false;
			}
			$this->result = mysqli_stmt_get_result($this->stmt);
			$this->affected = mysqli_stmt_affected_rows($this->stmt);
			$this->executed = true;
		}
		if ($this->db->eso && method_exists($this->db->eso, "callHook")) $this->db->eso->callHook("afterDatabaseQuery", array($queryString, &$this->result));
		return true;
	}

	protected function getQueryString()
	{
		$query = $this->query;
		foreach ($this->params as $param) {
			$escaped = is_null($param) ? "NULL" : (is_int($param) ? $param : "'".$this->db->escape($param)."'");
			$query = preg_replace('/\\?/', $escaped, $query, 1);
		}
		return $query;
	}
	public function fetchAssoc() { return $this->result instanceof SQLiteResult ? $this->result->fetch_assoc() : ($this->result ? mysqli_fetch_assoc($this->result) : false); }
	public function fetchRow() { return $this->result instanceof SQLiteResult ? $this->result->fetch_row() : ($this->result ? mysqli_fetch_row($this->result) : false); }
	public function fetchObject() { return $this->result instanceof SQLiteResult ? $this->result->fetch_object() : ($this->result ? mysqli_fetch_object($this->result) : false); }
	public function result($row = 0, $field = 0) { if (!$this->executed || !$this->result) return false; if ($this->result instanceof SQLiteResult) $this->result->data_seek($row); else mysqli_data_seek($this->result, $row); $data = $this->result instanceof SQLiteResult ? $this->result->fetch_array() : mysqli_fetch_array($this->result); return $data[$field] ?? false; }
	public function numRows() { return !$this->executed || !$this->result ? false : ($this->result instanceof SQLiteResult ? $this->result->num_rows() : mysqli_num_rows($this->result)); }
	public function affectedRows() { return $this->executed ? $this->affected : false; }
	public function insertId() { return $this->executed ? $this->db->lastInsertId() : false; }
	public function getResult() { return $this->result; }
	public function close() { if (!$this->sqlite && $this->stmt) { mysqli_stmt_close($this->stmt); $this->stmt = null; } }
	public function __destruct() { $this->close(); }
}
?>
