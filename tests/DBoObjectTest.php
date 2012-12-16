<?php

require_once "DBo.php";

class mysqli_log extends mysqli {
	public static $queries = [];
	
	public function query($query) {
		self::$queries[] = $query;
		return parent::query($query);
	}
}

/**
 * DBo test object methods
 */
class DBoObjectTest extends PHPUnit_Framework_TestCase {

	protected function setUp() {
		mysqli_log::$queries = [];
		DBo::conn(new mysqli_log("127.0.0.1", "root", "", "test"));
	}

	public function testCall() {
	/* TODO test
		$dbo = new DBo("hello");
		$this->assertAttributeEquals([["table"=>"hello", "params"=>null]], "stack", $dbo);
	*/

		$dbo = new DBo("hello", "world");
		$this->assertAttributeEquals([["table"=>"hello", "params"=>"world"]], "stack", $dbo);

	/* TODO test
		$dbo = (new DBo("hello"))->world();
		$this->assertAttributeEquals([["table"=>"world", "params"=>null], ["table"=>"hello", "params"=>null]], "stack", $dbo);
	*/
	}
}