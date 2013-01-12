<?php
// throw mysqli_sql_exception on connection or query error
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

/**
 * DBo efficient ORM
 *
 * @see http://we-love-php.blogspot.de/2012/08/how-to-implement-small-and-fast-orm.html
 */
class DBo implements IteratorAggregate {

public static $conn = null;
protected static $conn_db = "";
protected static $schema = null;
protected static $usage_col = []; // track used columns

protected $stack = []; // join stack
protected $db = "";
protected $table = "";
protected $data = false;
protected $usage_id = false;

// forward DBo::SomeTable($args) to DBo::init("SomeTable", $args)
public static function __callStatic($method, $args) {
	return call_user_func("static::init", $method, $args);
}

// forward $dbo->SomeTable($args) to DBo::init("SomeTable", $args)
public function __call($method, $args) {
	$obj = call_user_func("static::init", $method, $args);
	foreach ($this->stack as $elem) $obj->stack[] = $elem;
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

	if (self::$schema==null) { // load schema once
		require __DIR__."/schema.php";
		self::$schema = new stdClass();
		self::$schema->col = &$col;
		self::$schema->pkey = &$pkey;
		self::$schema->pkey_k = &$pkey_k;
		self::$schema->idx = &$idx;
		self::$schema->autoinc = &$autoinc;
	}
	$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
	$this->usage_id = implode(",", end($trace));
	if (isset(self::$usage_col[$this->usage_id])) {
		$this->stack[0]->sel = "a.".implode(",a.", array_keys(self::$usage_col[$this->usage_id]));
	}
}

public function buildQuery($op=null, $sel=null, $set=null) {
	$from = [];
	$where = [];
	$got_pkey = [];
	foreach ($this->stack as $key=>$elem) {
		$alias = chr($key+97); // a,b,c...
		$from[] = $elem->db.".".$elem->table." ".$alias;
		$pkeys = &self::$schema->pkey[$elem->db][$elem->table];
		$pkeys_k = &self::$schema->pkey_k[$elem->db][$elem->table];

		$skip_join = true;
		foreach ($elem->params as $i=>$param) {
			if (is_numeric($param)) { // pkey given as const // TODO check is_numeric
				$where[] = $alias.".".$pkeys[0]."=".($param==0 ? "'0'" : $param);
			} else if (is_array($param) and isset($param[0])) { // [[1,2],[3,4]] => (id,id2) in ((1,2),(3,4))
				// incomplete keys, e.g. 2 columns in array but primary key with 3 columns
				$cols = is_array($param[0]) ? implode(",".$alias.".", array_slice($pkeys, 0, count($param[0]))) : $pkeys[0];
				self::_escape($param);
				$where[] = "(".$alias.".".$cols.") IN (".implode(",", $param).")";
			} else if (is_array($param)) { // [id=>[1,2,3]] or [id=>42]
				self::_escape($param);
				foreach ($param as $k=>$v) {
					$where[] = $alias.".".$k.($v[0]=="(" ? " IN " : "=").$v;
					if (!isset($pkeys_k[$k])) $skip_join = false;
				}
			} else if ($param===null) {
				$where[] = $alias.".".$pkeys[0]." IS NULL";
			} else { // "id=? and id2=?",42,43
				if (count($elem->params)>$i+1) {
					$params = array_slice($elem->params, $i+1);
					self::_escape($params);
					$where[] = vsprintf(str_replace(["@", "?"], [$alias.".", "%s"], $param), $params);
				} else {
					$where[] = str_replace("@", $alias.".", $param);
				}
				$skip_join = false;
				break;
			}
		}
		if ($skip_join and !$got_pkey and count($elem->params)) $got_pkey = [$from, $where];
		if (!$skip_join and $got_pkey) $got_pkey = [$from, $where];

		if (isset($this->stack[$key+1])) { // build join: sometable.sales_id = sales.id
			$next = &$this->stack[$key+1];
			$next_col = &self::$schema->col[$next->db][$next->table];
			$next_pkeys = &self::$schema->pkey[$next->db][$next->table];

			$next_params = [];
			foreach ($next->params as $param) { // prepare params of next table in join
				if (is_numeric($param)) { // TODO check is_numeric
					$next_params[$next_pkeys[0]] = "=".($param==0 ? "'0'" : $param);
				} else if (is_array($param) and isset($param[0])) {
					if (is_array($param[0])) { // [[1,2],[3,4]] => id in (1,3), id2 in (2,4)
						$param_t = [];
						foreach ($next_pkeys as $pk=>$pv) {
							if (!isset($param[0][$pk])) break; // TODO check null
							foreach ($param as $p) $param_t[$pv][] = $p[$pk];
						}
						self::_escape($param_t);
						foreach ($param_t as $k=>$v) $next_params[$k] = " IN ".$v;
					} else { // [1,2,3] => id in (1,2,3)
						self::_escape($param);
						$next_params[$next_pkeys[0]] = " IN (".implode(",", $param).")";
					}
				} else if (is_array($param)) { // [id=>[1,2,3]] or [id=>42]
					self::_escape($param);
					foreach ($param as $k=>$v) $next_params[$k] = ($v[0]=="(" ? " IN " : "=").$v;
				} else if ($param===null) {
					$next_params[$next_pkeys[0]] = " IS NULL";
				}
			}
			$need_join = false;
			$match = false;
			$where_count = count($where);
			foreach ($pkeys as $pkey) {
				if (isset($next_col[$elem->table."_".$pkey])) {
					$match = true;
					if (isset($next_params[$elem->table."_".$pkey])) { // skip join, a.id=b.t_id and b.t_id=42 => a.id=42
						$where[] = $alias.".".$pkey.$next_params[$elem->table."_".$pkey];
					} else {
						$need_join = true;
						// if ($op===null) $op = "SELECT DISTINCT"; // TODO2 check automatic distinct for n:1
						$where[] = $alias.".".$pkey."=".chr($key+98).".".$elem->table."_".$pkey;
			}	}	}
			if (!$match) {
				$col = &self::$schema->col[$elem->db][$elem->table];
				foreach ($next_pkeys as $pkey) {
					if (isset($col[$next->table."_".$pkey])) {
						if (isset($next_params[$pkey])) { // skip join, a.t_id=b.id and b.id=42 => a.t_id=42
							$where[] = $alias.".".$next->table."_".$pkey.$next_params[$pkey];
						} else {
							$need_join = true;
							$where[] = $alias.".".$next->table."_".$pkey."=".chr($key+98).".".$pkey;
			}	}	}	}
			if ($where_count == count($where)) throw new Exception("Error: producing cross product: ".$elem->table);
			if (!$need_join and !$got_pkey) $got_pkey = [$from, $where];
		}
	}
	if ($got_pkey) { // break join chain when primary key is complete
		$from = $got_pkey[0];
		$where = $got_pkey[1];
	}
	if ($op=="UPDATE") {
		$query = "UPDATE ".implode(",", $from)." SET ".$set;
	} else {
		$query = ($op ?: "SELECT")." ".($sel ?: $this->stack[0]->sel)." FROM ".implode(",", $from);
	}
	if ($where) $query .= " WHERE ".implode(" AND ", $where);
	if (isset($this->stack[0]->limit)) $query .= " LIMIT ".$this->stack[0]->limit;
	return $query;
}

public function __get($name) {
	if (method_exists($this, "get_".$name)) return $this->{"get_".$name}();
	if (!isset(self::$schema->col[$this->db][$this->table][$name])) return false;
	if ($this->data===false) {
		$this->stack[0]->limit = 1;
		$this->data = self::$conn->query($this->buildQuery())->fetch_assoc();
	}
	self::$usage_col[$this->usage_id][$name] = 1; // track used columns, reuse in 2nd run
	if (substr($name, -4)==="_arr") {
		$this->$name = explode(",", $this->data[$name]);
	} else if (substr($name, -5)==="_json") {
		$this->$name = json_decode($this->data[$name], true);
	} else {
		$this->$name = $this->data[$name];
	}
	return $this->$name;
}

public function setFrom($arr) {
	foreach ($arr as $key=>$val) $this->$key = $val;
	return $this;
}

public function setParams() {
	$pkeys = &self::$schema->pkey[$this->db][$this->table];
	foreach ($pkeys as $pkey) { // TODO check null, clear params first?
		$this->$pkey;
		if (isset($this->$pkey)) $this->stack[0]->params[] = [$pkey=>$this->$pkey];
	}
	return $this;
}

public function buildData($insert=false) {
	$data = [];
	$cols = &self::$schema->col[$this->db][$this->table];
	$pkeys = &self::$schema->pkey_k[$this->db][$this->table];
	foreach (DBo__::getPublicVars($this) as $key=>$value) {
		if ($value!==false) {
			if (substr($key, -4)==="_arr") {
				$value = implode(",", $value);
			} else if (substr($key, -5)==="_json") {
				$value = json_encode($value);
			}
		}
		if (method_exists($this, "set_".$key)) $value = $this->{"set_".$key}($value); // TODO2 document
		if ((isset($cols[$key]) and ($insert or !isset($pkeys[$key]))) or $value===false) $data[$key] = $value; // do not update pkeys
	}
	return $data;
}

public function insert($arr=null) {
	if ($arr!=null) $this->setFrom($arr);
	$data = $this->buildData(true);
	self::_escape($data);
	foreach ($data as $key=>$val) $data[$key] = $val===false ? $key : $key."=".$val;
	$id = self::query("INSERT INTO ".$this->db.".".$this->table." SET ".implode(",", $data));
	$autoinc = @self::$schema->autoinc[$this->db][$this->table];
	if ($autoinc) $this->$autoinc = $id;
	$this->setParams();
	return $id;
}

public function update($key=null, $value=false) {
	if ($key!=null) {
		if (is_array($key)) $this->setFrom($key); else $this->$key = $value;
	}
	$data = $this->buildData();
	self::_escape($data);
	foreach ($data as $key=>$val) {
		$data[$key] = $val===false ? str_replace("@", "a.", $key) : "a.".$key."=".$val;
	}
	return self::query($this->buildQuery("UPDATE", null, implode(",", $data)));
}

public function exists() {
	$pkey = self::$schema->pkey[$this->db][$this->table][0];
	$var = $this->$pkey;
	return isset($var);
}

public function count() {
	return self::value($this->buildQuery("SELECT", "count(*)"));
}

public function avg($column) {
	return self::value($this->buildQuery("SELECT", "avg(a.".$column.")"));
}

public function sum($column) {
	return self::value($this->buildQuery("SELECT", "sum(a.".$column.")"));
}

public function stddev($column) {
	return self::value($this->buildQuery("SELECT", "stddev(a.".$column.")"));
}

public function delete() {
	return self::query($this->buildQuery("DELETE"));
}

public function __toString() {
	return $this->buildQuery();
}

public function explain() {
	return self::queryToText("EXPLAIN ".$this->buildQuery());
}

public function print_r() {
	foreach (self::$conn->query($this->buildQuery()) as $item) print_r($item);
}

public function db($database) {
	$this->stack[0]->db = $database;
	return $this;
}

public function limit($count, $offset=0) {
	$this->stack[0]->limit = $offset==0 ? (int)$count : (int)$offset.",".(int)$count;
	return $this;
}

public function select(array $cols) { // TODO2 document
	$this->stack[0]->sel = "a.".implode(",a.", $cols);
	return $this;
}

public function getIterator() {
	$result = self::$conn->query($this->buildQuery());
	$meta = $result->fetch_field();
	return new DBo_($result, $meta->db, $meta->orgtable, $this->usage_id);
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
	if (preg_match("!^(?:insert|update|delete|replace) !i", $query)) {
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
	$result = self::$conn->query($query);
	$meta = $result->fetch_field();
	return new DBo_($result, $meta->db, $meta->orgtable, null);
}

public static function keyValue($query, $params=null, $cache=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	$return = [];
	$result = self::$conn->query($query);
	while ($row = $result->fetch_row()) $return[$row[0]] = $row[1];
	// TODO cache
	return $return;
}

public function okeyValue($column_key, $column_value, $cache=null) {
	$this->stack[0]->sel = "a.".$column_key.", a.".$column_value;
	return self::keyValue($this->buildQuery(), null, $cache);
}

public static function keyValues($query, $params=null, $cache=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	$return = [];
	$result = self::$conn->query($query);
	while ($row = $result->fetch_assoc()) $return[array_shift($row)] = $row;
	// TODO cache
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
public static function value($query, array $params=null, $cache=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	// TODO cache
	return self::$conn->query($query)->fetch_row()[0];
}

public static function values($query, $params=null, $cache=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	$return = [];
	$result = self::$conn->query($query);
	while ($value = $result->fetch_row()[0]) $return[] = $value;
	// TODO cache
	return $return;
}

public function ovalues($column, $cache=null) {
	$this->stack[0]->sel = "a.".$column; // TODO distinct?
	return self::values($this->buildQuery(), null, $cache);
}

/* TODO implement, cache, static cache?, arrayY?
public function cache($cache=null) {
	$result = [];
	foreach ($this->getIterator() as $row) $result[] = $row;
	return $result;
}
public function oarray($cache=null) {
	$result = [];
	foreach ($this->getIterator() as $row) $result[] = $row;
	return $result;
}
*/

public static function begin() {
	self::$conn->query("begin");
}

public static function rollback() {
	self::$conn->query("rollback");
}

public static function commit() {
	self::$conn->query("commit");
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
	$pkey_k = [];
	$autoinc = [];
	$idx = [];
	foreach (self::query("SELECT * FROM information_schema.columns WHERE table_schema NOT IN ?", [$exclude_db]) as $row) {
		$col[ $row["TABLE_SCHEMA"] ][ $row["TABLE_NAME"] ][ $row["COLUMN_NAME"] ] = 1;
		if ($row["COLUMN_KEY"] == "PRI") {
			$pkey[ $row["TABLE_SCHEMA"] ][ $row["TABLE_NAME"] ][] = $row["COLUMN_NAME"];
			$pkey_k[ $row["TABLE_SCHEMA"] ][ $row["TABLE_NAME"] ][ $row["COLUMN_NAME"] ] = 1;
		}
		if ($row["COLUMN_KEY"] != "") $idx[ $row["TABLE_SCHEMA"] ][ $row["TABLE_NAME"] ][ $row["COLUMN_NAME"] ] = 1;
		if ($row["EXTRA"] == "auto_increment") $autoinc[ $row["TABLE_SCHEMA"] ][ $row["TABLE_NAME"] ] = $row["COLUMN_NAME"];
	}
	$schema = "<?php \$col=".var_export($col, true)."; \$pkey=".var_export($pkey, true)."; \$pkey_k=".var_export($pkey_k, true).";".
		"\$idx=".var_export($idx, true)."; \$autoinc=".var_export($autoinc, true).";";
	$schema = str_replace([" ", "\n", "array(", ",)", "\$"], ["", "", "[", "]", "\n\$"], $schema);
	file_put_contents(__DIR__."/schema_new.php", $schema, LOCK_EX);
	$col = null;
	require "schema_new.php";
	if (empty($col)) throw new Exception("Error creating static schema data.");
	rename("schema_new.php", "schema.php");
}
}

class DBo_ extends IteratorIterator {
	public function __construct (Traversable $iterator, $db, $table, $usage_id) {
		parent::__construct($iterator);
		$this->db = $db;
		$this->table = $table;
		$this->usage_id = $usage_id;
	}
	public function current() {
		$set = ["db"=>$this->db, "data"=>parent::current()];
		if ($this->usage_id) $set["usage_id"] = $this->usage_id;
		$obj = DBo::init($this->table);
		return $obj->setFrom($set)->setParams();
	}
}
class DBo__ { // call get_object_vars from outside to get only public vars
	public static function getPublicVars($obj) {
		return get_object_vars($obj);
	}
}

/* PHP 5.5 generators
public static function valuesY($query, $params=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	$result = self::$conn->query($query);
	while ($row = $result->fetch_row()[0]) yield $row;
}
public static function keyValueY($query, $params=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	$result = self::$conn->query($query);
	while ($row = $result->fetch_row()) yield $row[0] => $row[1];
}
public static function keyValuesY($query, $params=null) {
	if ($params) {
		self::_escape($params);
		$query = vsprintf(str_replace("?", "%s", $query), $params);
	}
	$return = [];
	$result = self::$conn->query($query);
	while ($row = $result->fetch_assoc()) yield array_shift($row) => $row;
}
public function getIterator() { // TODO2 check if faster?
	$result = self::$conn->query($this->buildQuery());
	$meta = $result->fetch_field();
	foreach ($result as $row) {
		$obj = DBo::init($meta->orgtable)->db($meta->db);
		$obj->data = $row;
		$obj->usage_id = $this->usage_id;
		yield $obj->setParams();
	}
}

*/

// TODO2 add option to disable usage_col, populate members directly
// TODO2 implement copyTo(), moveTo(), archive()
// TODO2 load/store usage_col in apc