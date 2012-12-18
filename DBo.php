<?php

// throw mysqli_sql_exception on connection or query error
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

/**
 * DBo Efficient ORM
 *
 * @see http://we-love-php.blogspot.de/2012/08/how-to-implement-small-and-fast-orm.html
 */
class DBo {

public static $conn = null;
protected static $schema = null;

// join stack
protected $stack = [];

// forward DBo::SomeTable($args) to DBo::init("SomeTable", $args)
public static function __callStatic($method, $args) {
	array_unshift($args, $method);
	return call_user_func_array([get_called_class(), "init"], $args);
}

// forward $dbo->SomeTable($args) to DBo::init("SomeTable", $args)
public function __call($method, $args) {
	array_unshift($args, $method);
	$obj = call_user_func_array([get_class($this), "init"], $args);
	$obj->stack = array_merge($obj->stack, $this->stack);
	return $obj;
}

// do "new DBo_SomeTable()" if class "DBo_Guestbook" exists, uses auto-loader, loads schema
public static function init($table, $params=null) {
	if (class_exists("DBo_".$table)) {
		$class = "DBo_".$table;
		return new $class($table, $params);
	}
	return new self($table, $params);
}

public function __construct($table, $params=null) {
	$this->stack = [["table"=>$table, "params"=>$params]];
	if (empty(self::$schema)) {
		require __DIR__."/schema.php";
		self::$schema = new stdclass();
		self::$schema->col = &$col;
		self::$schema->pkey = &$pkey;
		self::$schema->idx = &$idx;
	}
}

public static function conn(mysqli $conn) {
	self::$conn = $conn;
}

private static function _escape(&$params) {
	foreach ($params as $key=>$param) {
		if (is_array($param)) {
			self::_escape($param);
			$params[$key] = "(".implode(",", $param).")";
		} else if ($param===null) {
			$params[$key] = "NULL";
		} else if ($param==="0" or $param===0) {
			$params[$key] = "'0'";
		} else if (!is_numeric($param)) {
			$params[$key] = "'".self::$conn->real_escape_string($param)."'";
}	}	}

public static function query($query, $params=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	if (preg_match('!^(?:insert|update|delete|replace) !i', $query)) {
		self::$conn->query($query);
		return self::$conn->insert_id ?: self::$conn->affected_rows;
	}
	return self::$conn->query($query);
}

public static function one($query, $params=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	return self::$conn->query($query)->fetch_assoc();
}

public static function object($query, $params=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	return self::$conn->query($query)->fetch_object();
}

public static function keyValue($query, $params=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	$return = [];
	$result = self::$conn->query($query);
	while ($row = $result->fetch_row()) $return[$row[0]] = $row[1];
	return $return;
}

public static function keyValues($query, $params=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	$return = [];
	$result = self::$conn->query($query);
	while ($row = $result->fetch_assoc()) $return[array_shift($row)] = $row;
	return $return;
}

/* value(query, param1, param2, ...)
public static function value($query, $params=null) {
	if ($params) {
		$params = func_get_args();
		array_shift($params);
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	return self::$conn->query($query)->fetch_row()[0];
}
*/

// value(query, [param1, param2, ...])
public static function value($query, array $params=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	return self::$conn->query($query)->fetch_row()[0];
}

public static function values($query, $params=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	$return = [];
	$result = self::$conn->query($query);
	while ($row = $result->fetch_row()[0]) $return[] = $row;
	return $return;
}

/* PHP 5.5
public static function keyValueY($query, $params=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	$result = self::$conn->query($query);
	while ($row = $result->fetch_row()) yield $row[0] => $row[1];
}

public static function valuesY($query, $params=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	$result = self::$conn->query($query);
	while ($row = $result->fetch_row()[0]) yield $row;
}
*/

public static function begin() {
	self::$conn->query('begin');
}

public static function rollback() {
	self::$conn->query('rollback');
}

public static function commit() {
	self::$conn->query('commit');
}

public static function queryToText($query, $params=null) {
	$data = self::query($query, $params);
	$infos = $data->fetch_fields();
	$result = $query." | ".$data->num_rows." rows\n\n";
	foreach ($infos as $info) $result .= sprintf("% ".$info->max_length."s | ", $info->name);
	$result .= "\n";
	foreach ($data->fetch_all() as $item) {
		foreach ($item as $key=>$val) $result .= sprintf("% ".max(strlen($infos[$key]->name), $infos[$key]->max_length)."s | ", $val);
		$result .= "\n";
	}
	return $result;
}

public static function exportSchema($exclude_db=["information_schema", "performance_schema", "mysql"]) {
	$col = [];
	$pkey = [];
	$idx = [];
	foreach (self::query("SELECT * FROM information_schema.columns WHERE table_schema NOT IN ?", [$exclude_db]) as $row) {
		$col[ $row["TABLE_SCHEMA"] ][ $row["TABLE_NAME"] ][ $row["COLUMN_NAME"] ] = 1;
	}
	foreach (self::query("SELECT * FROM information_schema.key_column_usage WHERE table_schema NOT IN ?", [$exclude_db]) as $row) {
		if ($row["CONSTRAINT_NAME"] == "PRIMARY") $pkey[ $row["TABLE_SCHEMA"] ][ $row["TABLE_NAME"] ][] = $row["COLUMN_NAME"];
		$idx[ $row["TABLE_SCHEMA"] ][ $row["TABLE_NAME"] ][] = $row["COLUMN_NAME"];
	}
	$schema = "<?php \$col=".var_export($col, true)."; \$pkey=".var_export($pkey, true)."; \$idx=".var_export($idx, true).";";
	$schema = str_replace([" ", "\n", "array(", ",)", "\$"], ["", "", "[", "]", "\n\$"], $schema);
	file_put_contents(__DIR__."/schema.php", $schema, LOCK_EX);
	$col = null;
	require "schema.php";
	if (empty($col)) throw new Exception("Error creating static schema data.");
}
}