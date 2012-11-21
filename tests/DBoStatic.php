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

	public function testEscape() {
		$method = new ReflectionMethod($class, $method);
		$method->setAccessible(TRUE);

		$this->assertEquals(["10", "11"], $method->invoke(null, [10,11]));
		$this->assertEquals(["'0'","1"], $method->invoke(null, [0,1]));
		$this->assertEquals(["NULL","'0'","'0'","'NULL'"], $method->invoke(null, [null,0,'0','NULL']));
		$this->assertEquals(["'hello\\rworld\\n!'"], $method->invoke(null, ["hello\rworld\n!"]));
	}
}