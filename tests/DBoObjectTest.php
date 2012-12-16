<?php

require_once "DBo.php";

/**
 * DBo test object methods
 */
class DBoObjectTest extends PHPUnit_Framework_TestCase {

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