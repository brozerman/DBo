<?php
require_once "DBo.php";

class DBo_SomeTable {
}

/**
 * DBo test object methods
 */
class DBoObjectTest extends PHPUnit_Framework_TestCase {

	public function testCall() {
		$dbo = new DBo("hello", 42);
		$this->assertAttributeEquals([["table"=>"hello", "params"=>42]], "stack", $dbo);

		$dbo = (new DBo("hello"))->world();
		$this->assertAttributeEquals([["table"=>"world", "params"=>null], ["table"=>"hello", "params"=>null]], "stack", $dbo);
	}

	public function testCustomClass() {
		$dbo = DBo::SomeTable();
		$this->assertInstanceOf("DBo_SomeTable", $dbo);
	}
}