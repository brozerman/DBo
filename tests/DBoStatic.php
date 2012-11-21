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
}