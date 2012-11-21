<?php

require "DBo.php";

class mysqli_log extends mysqli {
	public static $queries = [];
	
	public function query($query) {
		self::$queries[] = $query;
		return parent::query($query);
	}
}

class DBoStatic extends PHPUnit_Framework_TestCase {

	public static function setUpBeforeClass() {
		$db = new mysqli("127.0.0.1", "root", "", "test");
		$db->query("CREATE TABLE test.t1 (a INT, b INT, c VARCHAR(20))");
		$db->query("INSERT INTO test.t1 VALUES (1,2,'ab'),(3,4,'cd'),(5,6,'ef');");
		$db->close();
	}

	protected function setUp() {
		mysqli_log::$queries = [];
		DBo::conn(new mysqli_log("127.0.0.1", "root", "", "test"));
	}

	public function testBegin() {
		DBo::begin();
		$this->assertEquals(mysqli_log::$queries, ["begin"]);
	}

	public function testCommit() {
		DBo::commit();
		$this->assertEquals(mysqli_log::$queries, ["commit"]);
	}

	public function testRollback() {
		DBo::rollback();
		$this->assertEquals(mysqli_log::$queries, ["rollback"]);
	}

	public function testQuery() {
		$rows = [];
		foreach (DBo::query("SELECT * FROM test.t1 WHERE a=3") as $row) {
			$rows[] = $row;
		}
		$this->assertEquals($rows, [["a"=>"3", "b"=>"4", "c"=>"cd"]]);
	}

	public function testOne() {
		$row = DBo::one("SELECT * FROM test.t1 WHERE a=3");
		$this->assertEquals($row, ["a"=>"3", "b"=>"4", "c"=>"cd"]);
		// TODO test params
	}

	public function testObject() {
		$obj = DBo::object("SELECT * FROM test.t1 WHERE a=3");
		$this->assertEquals($obj, (object)["a"=>"3", "b"=>"4", "c"=>"cd"]);
	}

	public function testValue() {
		$value = DBo::value("SELECT a FROM test.t1 WHERE a=3");
		$this->assertEquals($value, "3");
	}

	public function testValues() {
		$values = DBo::values("SELECT a FROM test.t1");
		$this->assertEquals($values, ["1", "3", "5"]);
	}

	public function testKeyValue() {
		$kv = DBo::keyValue("SELECT a,b FROM test.t1");
		$this->assertEquals($kv, ["1"=>"2", "3"=>"4", "5"=>"6"]);
	}

	public function testQueryToText() {
		$str = DBo::queryToText("SELECT a,b FROM test.t1");
		$this->assertEquals($str, "SELECT a,b FROM test.t1 | 3 rows\n\na | b |\n1 | 2 |\n3 | 4 |\n\n5 | 6 |\n");
	}

	public function testEscape() {
		$method = new ReflectionMethod("DBo", "_escape");
		$method->setAccessible(TRUE);

		$this->assertEquals(["10", "11"], $method->invoke(null, [10,11]));
		$this->assertEquals(["'0'","1"], $method->invoke(null, [0,1]));
		$this->assertEquals(["NULL","'0'","'0'","'NULL'"], $method->invoke(null, [null,0,"0","NULL"]));
		$this->assertEquals(["'hello\\rworld\\n!'"], $method->invoke(null, ["hello\rworld\n!"]));
		$this->assertEquals(["(1,2,3)"], $method->invoke(null, [[1,2,3]]));
		$this->assertEquals(["(1,2,(3,4))"], $method->invoke(null, [[1,2,[3,4]]]));
	}
}