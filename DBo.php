<?php

// throw mysqli_sql_exception on connection or query error
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

/**
 * DBo Efficient ORM
 *
 * @see http://we-love-php.blogspot.de/2012/08/how-to-implement-small-and-fast-orm.html
 */
class DBo implements IteratorAggregate {

public static $conn = null;
protected static $conn_db = "";
protected static $schema = null;
protected static $usage_col = [];

// join stack
protected $stack = [];

protected $data = false;
protected $db = "";
protected $table = "";

// forward DBo::SomeTable($args) to DBo::init("SomeTable", $args)
public static function __callStatic($method, $args) {
	return call_user_func("static::init", $method, $args);
}

// forward $dbo->SomeTable($args) to DBo::init("SomeTable", $args)
public function __call($method, $args) {
	$obj = call_user_func("static::init", $method, $args);
	$obj->stack = array_merge($obj->stack, $this->stack);
	return $obj;
}

// do "new DBo_SomeTable()" if class "DBo_Guestbook" exists, uses auto-loader
public static function init($table, $params=[]) {
	if (class_exists("DBo_".$table)) {
		$class = "DBo_".$table;
		return new $class($table, $params);
	}
	return new self($table, $params);
}

// protected: new DBo("Sales") not instanceof DBo_Sales
protected function __construct($table, $params) {
	$this->stack = [(object)["sel"=>"a.*", "table"=>$table, "params"=>$params, "db"=>self::$conn_db]];
	$this->db = &$this->stack[0]->db;
	$this->table = &$this->stack[0]->table;

	// load schema once
	if (self::$schema==null) {
		require __DIR__."/schema.php";
		self::$schema = new stdclass();
		self::$schema->col = &$col;
		self::$schema->pkey = &$pkey;
		self::$schema->idx = &$idx;
		self::$schema->autoinc = &$autoinc;
	}
	$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
	$this->usage_id = implode(",", end($trace));
}

public function build_query($op=null) {
	$from = [];
	$where = [];
	foreach ($this->stack as $key=>$elem) {
		$alias = chr($key+97); // a,b,c...
		$from[] = $elem->db.".".$elem->table." ".$alias;
		$pkeys = &self::$schema->pkey[$elem->db][$elem->table];

		$skip_join = true;
		foreach ($elem->params as $i=>$param) { // TODO2 reference?
			if ($param==="0" or $param===0) {
				$where[] = $alias.".".$pkeys[0]."='0'";
			} else if (is_numeric($param)) { // pkey given as const
				$where[] = $alias.".".$pkeys[0]."=".$param;
			} else if (is_array($param) and is_numeric(key($param))) {
				self::_escape($param);
				$where[] = "(".$alias.".".implode(",".$alias.".", $pkeys).") IN (".implode(",", $param).")";
			} else if (is_array($param)) {
				self::_escape($param);
				foreach ($param as $k=>$v) $where[] = $alias.".".$k.($v[0]=="(" ? " IN " : "=").$v;
				if (array_diff_key($param, array_flip($pkeys))) $skip_join = false;
			} else {
				if (count($elem->params)>$i+1) {
					$params = array_slice($elem->params, $i+1);
					self::_escape($params);
					$where[] = vsprintf(str_replace(["@","?"], [$alias.".","%s"], $param), $params);
				} else {
					$where[] = str_replace("@", $alias.".", $param);
				}
				$skip_join = false;
				break;
			}
		}
		// if ($skip_join and count($elem->params)>0) break; // TODO check again

		if (isset($this->stack[$key+1])) { // build join: sometable.sales_id = sales.id
			$where_count = count($where);

			$next = &$this->stack[$key+1];
			$next_col = &self::$schema->col[$next->db][$next->table];
			$next_pkeys = &self::$schema->pkey[$next->db][$next->table];

			$join = false;
			$match = false;
			foreach ($pkeys as $pkey) {
				if (isset($next_col[$elem->table."_".$pkey])) {
					$match = true;

					// join can be skipped, e.g. a.id=42 and a.id=b.some_col
					$i = array_search($elem->table."_".$pkey, $next_pkeys);
					if ($i!==false and isset($next->params[$i]) and (is_numeric($next->params[$i]) or $next->params[$i]==null or is_array($next->params[$i]))) {
						$where[] = $alias.".".$pkey."=".$next->params[$i]; // TODO escape, fix
					} else {
						$join = true;
						$where[] = $alias.".".$pkey."=".chr($key+98).".".$elem->table."_".$pkey;
					}
				}
			}
			if (!$match) {
				$col = &self::$schema->col[$elem->db][$elem->table];
				foreach ($next_pkeys as $i=>$pkey) {
					if (isset($col[$next->table."_".$pkey])) {
						// join can be skipped, e.g. a.id=42 and a.id=b.some_col
						if (isset($next->params[$i]) and (is_numeric($next->params[$i]) or $next->params[$i]==null or is_array($next->params[$i]))) {
							$where[] = $alias.".".$next->table."_".$pkey."=".$next->params[$i]; // TODO escape, fix
						} else {
							$join = true;
							$where[] = $alias.".".$next->table."_".$pkey."=".chr($key+98).".".$pkey;
						}
					}
				}
			}
			if ($where_count == count($where)) throw new Exception("Error: producing cross product");
			if (!$join) break; // TODO check again, custom predicate
		}
	}
	$query = ($op ?: "SELECT ".$this->stack[0]->sel)." FROM ".implode(",", $from);
	if ($where) $query .= " WHERE ".implode(" AND ", $where);
	if (isset($this->stack[0]->limit)) $query .= " LIMIT ".$this->stack[0]->limit;
	return $query;
}

public function __get($name) {
	if (method_exists($this, "get_".$name)) return $this->{"get_".$name}();
	if (strpos($name, "arr_")===0) {
		$this->$name = explode(",", $this->__get(substr($name, 4)));
		return $this->$name;
	} else if (strpos($name, "json_")===0) {
		$this->$name = json_decode($this->__get(substr($name, 5)), true);
		return $this->$name;
	}
	if (!isset(self::$schema->col[$this->db][$this->table][$name])) return false;
	if ($this->data===false) {
		// TODO2 add option to disable usage_col
		if (isset(self::$usage_col[$this->usage_id]) and $this->stack[0]->sel=="a.*") {
			$this->select(array_keys(self::$usage_col[$this->usage_id]));
		}
		$this->data = self::$conn->query($this->build_query())->fetch_assoc();
	}
	self::$usage_col[$this->usage_id][$name] = 1;
	$this->$name = $this->data[$name];
	return $this->$name;
}

public function __toString() {
	return self::queryToText("explain ".$this->build_query());
}

public function setFrom($arr) {
	foreach ($arr as $key=>$val) $this->$key = $val;
	// TODO optimize
	$pkeys = &self::$schema->pkey[$this->db][$this->table];
	foreach ($pkeys as $pkey) if (isset($arr[$pkey])) $this->stack[0]->params[] = [$pkey=>$arr[$pkey]];
	return $this;
}

public function save($key=null, $value=false) {
	if ($key!=null) {
		if (is_array($key)) $this->setFrom($arr); else $this->$key = $value;
	}
	$data = [];
	// TODO2 check array_intersect
	foreach (get_object_vars($this) as $key=>$param) {
		if (strpos($key, "arr_")===0) {
			$param = implode(",", $param);
			$key = substr($key, 4);
		} else if (strpos($key, "json_")===0) {
			$param = json_encode($param);
			$key = substr($key, 5);
		}
		if (isset(self::$schema->col[$this->db][$this->table][$key])) $data[$key] = $param;
	}
	self::_escape($data);

	foreach ($data as $key=>$value) {
		if ($value===false) $data[$key] = $key; else $data[$key] = $key."=".$value;
	}
	$pkey = self::$schema->pkey[$this->db][$this->table][0];
	if (!empty($data->$pkey)) { // pkey given
		// TODO implement multi-table update join
		/*
		select a.* from bla where x=y
		update bla set a=b where x=y
		$this->build_query("UPDATE");
		*/
		return self::query("UPDATE ".$this->db.".".$this->table." SET ".implode(",", $data)." WHERE ");
	} else {
		$id = self::query("INSERT INTO ".$this->db.".".$this->table." SET ".implode(",", $data));
		if ($field = self::$schema->autoinc[$this->db][$this->table]) $this->$field = $id;
		return $id;
	}
}

public function exists() {
	$pkey = self::$schema->pkey[$this->db][$this->table][0];
	$var = $this->$pkey;
	return isset($var);
}

public function count() {
	return self::value($this->build_query("SELECT count(*)"));
}

public function delete() {
	return self::query($this->build_query("DELETE"));
}

public function print_r() {
	foreach (self::$conn->query($this->build_query()) as $item) print_r($item);
}

public function db($database) {
	$this->stack[0]->db = $database;
	return $this;
}

public function limit($count) {
	$this->stack[0]->limit = $count;
	return $this;
}

public function parent() {
// TODO implement
	$this->stack[0]->sel++;
	return $this;
}

// TODO2 document
public function select(array $cols) {
	$this->stack[0]->sel = "a.".implode(",a.", $cols);
	return $this;
}

public function getIterator() {
	// TODO2 use generator from PHP 5.5 ?
	$result = self::$conn->query($this->build_query());
	$meta = $result->fetch_field();
	return new DBo_($result, $meta->db, $meta->orgtable);
}

public static function conn(mysqli $conn, $db) {
	self::$conn = $conn;
	self::$conn_db = $db;
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
		} else if ($param!==false and !is_numeric($param)) {
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

// TODO2 document
public static function object($query, $params=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	$result = self::$conn->query($query);
	$meta = $result->fetch_field();
	return new DBo_($result, $meta->db, $meta->orgtable);
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
	while ($value = $result->fetch_row()[0]) $return[] = $value;
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
	$autoinc = [];
	$idx = [];
	foreach (self::query("SELECT * FROM information_schema.columns WHERE table_schema NOT IN ?", [$exclude_db]) as $row) {
		$col[ $row["TABLE_SCHEMA"] ][ $row["TABLE_NAME"] ][ $row["COLUMN_NAME"] ] = 1;
		if ($row["COLUMN_KEY"] == "PRI") $pkey[ $row["TABLE_SCHEMA"] ][ $row["TABLE_NAME"] ][] = $row["COLUMN_NAME"];
		if ($row["COLUMN_KEY"] != "") $idx[ $row["TABLE_SCHEMA"] ][ $row["TABLE_NAME"] ][] = $row["COLUMN_NAME"];
		if ($row["EXTRA"] == "auto_increment") $autoinc[ $row["TABLE_SCHEMA"] ][ $row["TABLE_NAME"] ] = $row["COLUMN_NAME"];
	}
	$schema = "<?php \$col=".var_export($col, true)."; \$pkey=".var_export($pkey, true)."; \$idx=".var_export($idx, true)."; \$autoinc=".var_export($autoinc, true).";";
	$schema = str_replace([" ", "\n", "array(", ",)", "\$"], ["", "", "[", "]", "\n\$"], $schema);
	file_put_contents(__DIR__."/schema_new.php", $schema, LOCK_EX);
	$col = null;
	require "schema_new.php";
	if (empty($col)) throw new Exception("Error creating static schema data.");
	rename("schema_new.php", "schema.php");
}
}

class DBo_ extends IteratorIterator {
	public function __construct (Traversable $iterator, $db, $table) {
		parent::__construct($iterator);
		$this->db = $db;
		$this->table = $table;
	}

	public function current() {
		return DBo::init($this->table)->db($this->db)->setFrom(parent::current());
	}
}

/* TODO implement
$payments = DBo::Categories()->cache(60);
// [{id=>0, name=>Sports}, {id=>1, name=>Movies}, ...]

$payments = DBo::Categories()->array(60);
// [[id=>0, name=>Sports], [id=>1, name=>Movies], ...]

$payments = DBo::Categories()->values("col_name", 60);
// [Sports, Movies, ...]

$payments = DBo::Categories()->keyValue("col_id", "col_name", 60);
// [0=>Sports, 1=>Movies, ...]

DBo::sale(10)->salepos()->logistics('shipped=1')->parent()->save('complete', 1);

DBo::Guestbook(42)->save('likes=likes+1');
*/