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
 * DBo test static methods
 */
class DBoStaticTest extends PHPUnit_Framework_TestCase {

	public static function setUpBeforeClass() {
		$db = new mysqli("127.0.0.1", "root", "", "test");
		$db->query("CREATE TABLE test.t1 (a INT, b INT, c VARCHAR(20))");
		$db->query("INSERT INTO test.t1 VALUES (1,2,'ab'),(3,4,'cd'),(5,6,'ef');");
		$db->query("CREATE TABLE test.t2 (a INT AUTO_INCREMENT PRIMARY KEY)");
		$db->close();
	}

	protected function setUp() {
		mysqli_log::$queries = [];
		DBo::conn(new mysqli_log("127.0.0.1", "root", "", "test"));
	}

	public function testConn() {
		$this->assertEquals(DBo::query("SELECT 1=1")->fetch_row(), ["1"]);
	}

	public function testConnException() {
		$this->setExpectedException("mysqli_sql_exception");
		DBo::conn(new mysqli("127.0.0.1", "root", "invalid", "test"));
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

	public function testQueryException() {
		$this->setExpectedException("mysqli_sql_exception");
		DBo::query("SELECT * FROM test_invalid")->fetch_array();
	}

	public function testInsertUpdateDeleteReplace() {
		$id = DBo::query("INSERT INTO test.t2 VALUES ()");
		$this->assertEquals($id, "1");

		$id = DBo::query("INSERT INTO test.t2 VALUES ()");
		$this->assertEquals($id, "2");
		$this->assertEquals(DBo::query("UPDATE test.t2 SET a=a-1"), 2);

		DBo::query("INSERT INTO test.t2 VALUES ()");
		$this->assertEquals(DBo::query("DELETE FROM test.t2"), 3);

		DBo::query("INSERT INTO test.t2 VALUES ()");
		$this->assertEquals(DBo::query("REPLACE INTO test.t2 VALUES (4)"), 4);
	}

	public function testOne() {
		$row = DBo::one("SELECT * FROM test.t1 WHERE a=3");
		$this->assertEquals($row, ["a"=>"3", "b"=>"4", "c"=>"cd"]);
		$row = DBo::one("SELECT * FROM test.t1 WHERE a=?", [3]);
		$this->assertEquals($row, ["a"=>"3", "b"=>"4", "c"=>"cd"]);
	}

	public function testObject() {
		$obj = DBo::object("SELECT * FROM test.t1 WHERE a=3");
		$this->assertEquals($obj, (object)["a"=>"3", "b"=>"4", "c"=>"cd"]);
		$obj = DBo::object("SELECT * FROM test.t1 WHERE a=?", [3]);
		$this->assertEquals($obj, (object)["a"=>"3", "b"=>"4", "c"=>"cd"]);
	}

	public function testValue() {
		$value = DBo::value("SELECT a FROM test.t1 WHERE a=3");
		$this->assertEquals($value, "3");
		$value = DBo::value("SELECT a FROM test.t1 WHERE a=?", [3]);
		$this->assertEquals($value, "3");
	}

	public function testValues() {
		$values = DBo::values("SELECT a FROM test.t1");
		$this->assertEquals($values, ["1", "3", "5"]);
		$values = DBo::values("SELECT a FROM test.t1 WHERE b IN ?", [[2,4]]);
		$this->assertEquals($values, ["1", "3"]);
	}

	public function testKeyValue() {
		$kv = DBo::keyValue("SELECT a,b FROM test.t1");
		$this->assertEquals($kv, ["1"=>"2", "3"=>"4", "5"=>"6"]);
		$kv = DBo::keyValue("SELECT a,b FROM test.t1 WHERE a IN ?", [[1,3]]);
		$this->assertEquals($kv, ["1"=>"2", "3"=>"4"]);
	}

	public function testKeyValues() {
		$kv = DBo::keyValues("SELECT a,b,c FROM test.t1 LIMIT 2");
		$this->assertEquals($kv, ["1"=>["b"=>"2", "c"=>"ab"], "3"=>["b"=>"4", "c"=>"cd"]]);
		$kv = DBo::keyValues("SELECT a,b,c FROM test.t1 WHERE a=?", [1]);
		$this->assertEquals($kv, ["1"=>["b"=>"2", "c"=>"ab"]]);
	}

	public function testQueryToText() {
		$str = DBo::queryToText("SELECT a,b FROM test.t1");
		$this->assertEquals($str, "SELECT a,b FROM test.t1 | 3 rows\n\na | b | \n1 | 2 | \n3 | 4 | \n5 | 6 | \n");
	}

	private function _testEscape($param) {
		$method = new ReflectionMethod("DBo", "_escape");
		$method->setAccessible(true);
		$method->invokeArgs(null, [&$param]); // reference!
		return $param;
	}

	public function testEscape() {
		$this->assertEquals(["10", "11"], self::_testEscape([10,11]));
		$this->assertEquals(["'0'","1"], self::_testEscape([0,1]));
		$this->assertEquals(["NULL","'0'","'0'","'NULL'"], self::_testEscape([null,0,"0","NULL"]));
		$this->assertEquals(["'hello\\rworld\\n!'"], self::_testEscape(["hello\rworld\n!"]));
		$this->assertEquals(["(1,2,3)"], self::_testEscape([[1,2,3]]));
		$this->assertEquals(["(1,2,(3,4))"], self::_testEscape([[1,2,[3,4]]]));
	}

	public function testInit() {
		$dbo = DBo::init("hello", 42);
		$this->assertInstanceOf("DBo", $dbo);
		$this->assertAttributeEquals([["table"=>"hello", "params"=>42]], "stack", $dbo);

		$dbo = DBo::hello(42);
		$this->assertInstanceOf("DBo", $dbo);
		$this->assertAttributeEquals([["table"=>"hello", "params"=>42]], "stack", $dbo);
	}

	public function testExportSchema() {
		DBo::exportSchema();
		$this->assertEquals(file_get_contents("../schema.php"), "<?php\n".
			"\$cols=['test'=>['t1'=>['a'=>1,'b'=>1,'c'=>1],'t2'=>['a'=>1]]];\n".
			"\$pkeys=['test'=>['t2'=>[0=>'a']]];\n".
			"\$idx=['test'=>['t2'=>[0=>'a']]];");
	}

	public function testLoadSchema() {
		DBo::hello();
		$schema = (object)[
			"cols"=>["test"=>["t1"=>["a"=>1, "b"=>1, "c"=>1], "t2"=>["a"=>1]]],
			"pkeys"=>["test"=>["t2"=>["a"]]],
			"idx"=>["test"=>["t2"=>["a"]]],
		];
		$this->assertAttributeEquals($schema, "schema", "DBo");
	}
}