<?php

/**
 * DBo Efficient ORM
 *
 * @see http://we-love-php.blogspot.de/2012/08/how-to-implement-small-and-fast-orm.html
 */
class DBo {

public static $conn = null;

public static function conn(mysqli $conn) {
	mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
	self::$conn = $conn;
}

private static function _escape($params) {
	foreach ($params as $key=>$param) {
		if (is_array($param)) {
			$params[$key] = "(".implode(",", self::_escape($param)).")";
		} else if ($param===null) {
			$params[$key] = "NULL";
		} else if ($param==="0" or $param===0) {
			$params[$key] = "'0'";
		} else if (!is_numeric($param)) {
			$params[$key] = "'".self::$conn->real_escape_string($param)."'";
		}
	}
	return $params;
}

public static function query($query, $params=null) {
	if ($params) $query = vsprintf(str_replace("?", "%s", $query), self::_escape($params));

	if (preg_match('!^(?:insert|update|delete|replace) !i', $query)) {
		self::$conn->query($query);
		return self::$conn->insert_id ?: self::$conn->affected_rows;
	}
	return self::$conn->query($query);
}

public static function one($query, $params=null) {
	if ($params) $query = vsprintf(str_replace("?", "%s", $query), self::_escape($params));
	return self::$conn->query($query)->fetch_assoc();
}

public static function object($query, $params=null) {
	if ($params) $query = vsprintf(str_replace("?", "%s", $query), self::_escape($params));
	return self::$conn->query($query)->fetch_object();
}

public static function keyValue($query, $params=null) {
	if ($params) $query = vsprintf(str_replace("?", "%s", $query), self::_escape($params));
	$return = [];
	$result = self::$conn->query($query);
	while ($row = $result->fetch_row()) $return[$row[0]] = $row[1];
	return $return;
}

public static function keyValues($query, $params=null) {
	if ($params) $query = vsprintf(str_replace("?", "%s", $query), self::_escape($params));
	$return = [];
	$result = self::$conn->query($query);
	while ($row = $result->fetch_assoc()) $return[array_shift($row)] = $row;
	return $return;
}

public static function value($query, $params=null) {
	if ($params) $query = vsprintf(str_replace("?", "%s", $query), self::_escape($params));
	return self::$conn->query($query)->fetch_row()[0];
}

public static function values($query, $params=null) {
	if ($params) $query = vsprintf(str_replace("?", "%s", $query), self::_escape($params));
	$return = [];
	$result = self::$conn->query($query);
	while ($row = $result->fetch_row()[0]) $return[] = $row;
	return $return;
}

/* PHP 5.5
public static function keyValueY($query, $params=null) {
	if ($params) $query = vsprintf(str_replace("?", "%s", $query), self::_escape($params));
	$result = self::$conn->query($query);
	while ($row = $result->fetch_row()) yield $row[0] => $row[1];
}

public static function valuesY($query, $params=null) {
	if ($params) $query = vsprintf(str_replace("?", "%s", $query), self::_escape($params));
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
}