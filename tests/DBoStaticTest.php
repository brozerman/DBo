<?php
require_once "DBo.php";

class mysqli_log extends mysqli {
	public static $queries = [];

	public function query($query) {
		self::$queries[] = $query;
		return parent::query($query);
	}
}

class DBo_SomeTable extends DBo {
	function get_col() {
		return 42;
	}
}

/**
 * DBo test static methods
 */
class DBoStaticTest extends PHPUnit_Framework_TestCase {

	public static function setUpBeforeClass() {
		$db = new mysqli("127.0.0.1", "root", "", "test");
		$db->query("DROP TABLE IF EXISTS t1,t2,t3");
		$db->query("CREATE TABLE t1 (a INT AUTO_INCREMENT PRIMARY KEY, b INT, c VARCHAR(20))");
		$db->query("INSERT INTO t1 VALUES (1,2,'ab'),(3,4,'cd'),(5,6,'ef');");
		$db->query("CREATE TABLE t2 (a INT PRIMARY KEY, t1_a INT)");
		$db->query("INSERT INTO t2 VALUES (1,1)");
		$db->query("CREATE TABLE t3 (a INT PRIMARY KEY, b_arr VARCHAR(20), b_json VARCHAR(20))");
		$db->query("INSERT INTO t3 VALUES (1,'a,b,c','{\"a\":\"b\"}')");
		$db->close();
		DBo::conn(new mysqli_log("127.0.0.1", "root", "", "test"), "test");
		DBo::exportSchema();
	}

	protected function setUp() {
		mysqli_log::$queries = [];
		DBo::conn(new mysqli_log("127.0.0.1", "root", "", "test"), "test");
	}

	public function testConn() {
		$this->assertEquals(DBo::query("SELECT 1=1")->fetch_row(), ["1"]);
	}

	public function testConnException() {
		$this->setExpectedException("mysqli_sql_exception");
		DBo::conn(new mysqli("127.0.0.1", "root", "invalid", "test"), "test");
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
		DBo::query("CREATE TABLE test.t4 (a INT AUTO_INCREMENT PRIMARY KEY)");

		$id = DBo::query("INSERT INTO test.t4 VALUES ()");
		$this->assertEquals($id, "1");

		$id = DBo::query("INSERT INTO test.t4 VALUES ()");
		$this->assertEquals($id, "2");
		$this->assertEquals(DBo::query("UPDATE test.t4 SET a=a-1"), 2);

		DBo::query("INSERT INTO test.t4 VALUES ()");
		$this->assertEquals(DBo::query("DELETE FROM test.t4"), 3);

		DBo::query("INSERT INTO test.t4 VALUES ()");
		$this->assertEquals(DBo::query("REPLACE INTO test.t4 VALUES (4)"), 4);
		DBo::query("DROP TABLE test.t4");
	}

	public function testOne() {
		$row = DBo::one("SELECT * FROM test.t1 WHERE a=3");
		$this->assertEquals($row, ["a"=>"3", "b"=>"4", "c"=>"cd"]);
		$row = DBo::one("SELECT * FROM test.t1 WHERE a=?", [3]);
		$this->assertEquals($row, ["a"=>"3", "b"=>"4", "c"=>"cd"]);
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
		$this->assertEquals(["(1,2)","(3,4)"], self::_testEscape([[1,2],[3,4]]));
	}

	public function testInit() {
		$stack = ["sel"=>"a.*", "table"=>"hello", "params"=>[42], "db"=>"test"];
		$dbo = DBo::init("hello", [42]);
		$this->assertInstanceOf("DBo", $dbo);
		$this->assertAttributeEquals([(object)$stack], "stack", $dbo);

		$dbo = DBo::hello(42);
		$this->assertInstanceOf("DBo", $dbo);
		$this->assertAttributeEquals([(object)$stack], "stack", $dbo);

		$stack["params"] = [[42,13]];
		$dbo = DBo::hello([42,13]);
		$this->assertInstanceOf("DBo", $dbo);
		$this->assertAttributeEquals([(object)$stack], "stack", $dbo);
	}

	public function testExportSchema() {
		DBo::exportSchema();
		$this->assertEquals(file_get_contents(__DIR__."/../schema.php"), "<?php\n".
			"\$col=['test'=>['t1'=>['a'=>1,'b'=>1,'c'=>1],'t2'=>['a'=>1,'t1_a'=>1],'t3'=>['a'=>1,'b_arr'=>1,'b_json'=>1]]];\n".
			"\$pkey=['test'=>['t1'=>[0=>'a'],'t2'=>[0=>'a'],'t3'=>[0=>'a']]];\n".
			"\$pkey_k=['test'=>['t1'=>['a'=>1],'t2'=>['a'=>1],'t3'=>['a'=>1]]];\n".
			"\$idx=['test'=>['t1'=>['a'=>1],'t2'=>['a'=>1],'t3'=>['a'=>1]]];\n".
			"\$autoinc=['test'=>['t1'=>'a']];");
	}

	public function testLoadSchema() {
		DBo::hello();
		$schema = (object)[
			"col"=>["test"=>["t1"=>["a"=>1, "b"=>1, "c"=>1],"t2"=>["a"=>1,"t1_a"=>1],"t3"=>["a"=>1,"b_arr"=>1,"b_json"=>1]]],
			"pkey"=>["test"=>["t1"=>["a"],"t2"=>["a"],"t3"=>["a"]]],
			"pkey_k"=>["test"=>["t1"=>["a"=>1],"t2"=>["a"=>1],"t3"=>["a"=>1]]],
			"idx"=>["test"=>["t1"=>["a"=>1],"t2"=>["a"=>1],"t3"=>["a"=>1]]],
			"autoinc"=>["test"=>["t1"=>"a"]]
		];
		$this->assertAttributeEquals($schema, "schema", "DBo");
	}

	public function testCustomClass() {
		$dbo = DBo::SomeTable();
		$this->assertInstanceOf("DBo_SomeTable", $dbo);
		$this->assertEquals($dbo->col, 42); // calls DBo_SomeTable::col()
	}

	public function testBuildQuery() {
		$this->assertEquals((string)DBo::t1(42), "SELECT a.* FROM test.t1 a WHERE a.a=42");
		$this->assertEquals((string)DBo::t1([1,2,3]), "SELECT a.* FROM test.t1 a WHERE (a.a) IN (1,2,3)");
		$this->assertEquals((string)DBo::t1([[1,2],[3,4]]), "SELECT a.* FROM test.t1 a WHERE (a.a) IN ((1,2),(3,4))");
		$this->assertEquals(DBo::t1(42)->buildQuery(), "SELECT a.* FROM test.t1 a WHERE a.a=42");
		$this->assertEquals(DBo::t1(0)->buildQuery(), "SELECT a.* FROM test.t1 a WHERE a.a='0'");
		$this->assertEquals(DBo::t1(null)->buildQuery(), "SELECT a.* FROM test.t1 a WHERE a.a IS NULL");
		$this->assertEquals(DBo::t1(["a"=>42])->buildQuery(), "SELECT a.* FROM test.t1 a WHERE a.a=42");
		$this->assertEquals(DBo::t1("a=42")->buildQuery(), "SELECT a.* FROM test.t1 a WHERE a=42");
		$this->assertEquals(DBo::t1("@a=42")->buildQuery(), "SELECT a.* FROM test.t1 a WHERE a.a=42");
		$this->assertEquals(DBo::t1("@a=?",42)->buildQuery(), "SELECT a.* FROM test.t1 a WHERE a.a=42");

		$this->assertEquals(DBo::t1(42)->limit(3)->buildQuery(), "SELECT a.* FROM test.t1 a WHERE a.a=42 LIMIT 3");
		$this->assertEquals(DBo::t1(42)->limit(3,2)->buildQuery(), "SELECT a.* FROM test.t1 a WHERE a.a=42 LIMIT 2,3");
		$this->assertEquals(DBo::t1()->select(["b","c"])->buildQuery(), "SELECT a.b,a.c FROM test.t1 a");

		// t1:t2 = 1:n, t1.a = t2.t1_a (pkey = fkey)
		// reduce joins: break join chain if primary key is complete, assume data is always consistent
		$this->assertEquals(DBo::t1()->t2()->buildQuery(), "SELECT a.* FROM test.t2 a,test.t1 b WHERE a.t1_a=b.a");
		$this->assertEquals(DBo::t1(1)->t2()->buildQuery(), "SELECT a.* FROM test.t2 a WHERE a.t1_a=1");
		$this->assertEquals(DBo::t1(0)->t2()->buildQuery(), "SELECT a.* FROM test.t2 a WHERE a.t1_a='0'");
		$this->assertEquals(DBo::t1(null)->t2()->buildQuery(), "SELECT a.* FROM test.t2 a WHERE a.t1_a IS NULL");
		$this->assertEquals(DBo::t1([1,2])->t2()->buildQuery(), "SELECT a.* FROM test.t2 a WHERE a.t1_a IN (1,2)");
		$this->assertEquals(DBo::t1([[1,2],[3,4]])->t2()->buildQuery(), "SELECT a.* FROM test.t2 a WHERE a.t1_a IN (1,3)");
		$this->assertEquals(DBo::t1(["a"=>1])->t2()->buildQuery(), "SELECT a.* FROM test.t2 a WHERE a.t1_a=1");
		$this->assertEquals(DBo::t1()->t2(1)->buildQuery(), "SELECT a.* FROM test.t2 a WHERE a.a=1");
		$this->assertEquals(DBo::t1(1)->t2(1)->buildQuery(), "SELECT a.* FROM test.t2 a WHERE a.a=1");

		// t2:t1 = n:1, t2.t1_a = t1.a (fkey = pkey)
		$this->assertEquals(DBo::t2()->t1()->buildQuery(), "SELECT a.* FROM test.t1 a,test.t2 b WHERE a.a=b.t1_a");
		$this->assertEquals(DBo::t2(1)->t1()->buildQuery(), "SELECT a.* FROM test.t1 a,test.t2 b WHERE a.a=b.t1_a AND b.a=1");
		$this->assertEquals(DBo::t2()->t1(1)->buildQuery(), "SELECT a.* FROM test.t1 a WHERE a.a=1");
		$this->assertEquals(DBo::t2(1)->t1(1)->buildQuery(), "SELECT a.* FROM test.t1 a WHERE a.a=1");
		$this->assertEquals(DBo::t2(["t1_a"=>1])->t1()->buildQuery(), "SELECT a.* FROM test.t1 a,test.t2 b WHERE a.a=1 AND b.t1_a=1");
		// TODO add join reduce breaker
		
		$this->assertEquals(DBo::t1(42)->buildQuery("UPDATE", null, "set"), "UPDATE test.t1 a SET set WHERE a.a=42");
	}

	public function testExplain() {
		$explain = "EXPLAIN SELECT a.* FROM test.t1 a WHERE a.a=1 | 1 rows\n\n".
			"id | select_type | table |  type | possible_keys |     key | key_len |   ref | rows | Extra | \n".
			" 1 |      SIMPLE |     a | const |       PRIMARY | PRIMARY |       4 | const |    1 |       | \n";
		$this->assertEquals(DBo::t1(1)->explain(), $explain);
	}

	public function testDb() {
		$this->assertEquals(DBo::sometable()->db("somedb"), "SELECT a.* FROM somedb.sometable a");
	}

	public function testExists() {
		$this->assertTrue(DBo::t1(1)->exists());
		$this->assertFalse(DBo::t1(42)->exists());
	}

	public function testInsert() {
		DBo::t1()->insert(["a"=>100, "b"=>"hello"]);
		$this->assertEquals(end(mysqli_log::$queries), "INSERT INTO test.t1 SET a=100,b='hello'");

		$obj = DBo::t3();
		$obj->a = 2;
		$obj->b_arr = [1,2,3];
		$obj->b_json = ["a"=>"b"];
		$obj->insert();
		$this->assertEquals(end(mysqli_log::$queries), "INSERT INTO test.t3 SET a=2,b_arr='1,2,3',b_json='{\\\"a\\\":\\\"b\\\"}'");

		$this->assertEquals(DBo::t1()->insert(["b"=>"world"]), 101);
		$this->assertEquals(end(mysqli_log::$queries), "INSERT INTO test.t1 SET b='world'");

		DBo::t1()->insert(["b=now()"=>false]);
		$this->assertEquals(end(mysqli_log::$queries), "INSERT INTO test.t1 SET b=now()");
	}

	public function testUpdate() {
		DBo::query("INSERT INTO test.t1 (a,b) VALUES (103,'hello')");
		DBo::t1(103)->update("b", "world");
		$this->assertEquals(end(mysqli_log::$queries), "UPDATE test.t1 a SET a.b='world' WHERE a.a=103");

		DBo::t1(103)->update("@b=now()");
		$this->assertEquals(end(mysqli_log::$queries), "UPDATE test.t1 a SET a.b=now() WHERE a.a=103");

		DBo::t1(103)->update(["b"=>"world2"]);
		$this->assertEquals(end(mysqli_log::$queries), "UPDATE test.t1 a SET a.b='world2' WHERE a.a=103");

		$obj = DBo::t1(103);
		$obj->b = "world3";
		$obj->update();
		$this->assertEquals(end(mysqli_log::$queries), "UPDATE test.t1 a SET a.b='world3' WHERE a.a=103");
	}

	public function testDelete() {
		DBo::query("INSERT INTO test.t1 (a) VALUES (44)");
		$this->assertEquals(DBo::value("SELECT count(*) FROM test.t1 WHERE a=44"), 1);
		$this->assertEquals(DBo::t1(44)->delete(), 1);
		$this->assertEquals(end(mysqli_log::$queries), "DELETE a.* FROM test.t1 a WHERE a.a=44");
		$this->assertEquals(DBo::value("SELECT count(*) FROM test.t1 WHERE a=44"), 0);
	}

	public function testCount() {
		DBo::query("INSERT INTO test.t1 (a) VALUES (45),(46),(47)");
		$this->assertEquals(DBo::t1([-1,45,46,47])->count(), 3);
	}

	public function testSum() {
		DBo::query("INSERT INTO test.t1 (a) VALUES (48),(49)");
		$this->assertEquals(DBo::t1([-1,48,49])->sum("a"), 97);
	}

	public function testAvg() {
		DBo::query("INSERT INTO test.t1 (a) VALUES (50),(54)");
		$this->assertEquals(DBo::t1([-1,50,54])->avg("a"), 52);
		$this->assertEquals(end(mysqli_log::$queries), "SELECT avg(a.a) FROM test.t1 a WHERE (a.a) IN (-1,50,54)");
	}

	public function testStddev() {
		DBo::query("INSERT INTO test.t1 (a) VALUES (52),(56)");
		$this->assertEquals(DBo::t1([-1,52,56])->stddev("a"), "2.0000");
		$this->assertEquals(end(mysqli_log::$queries), "SELECT stddev(a.a) FROM test.t1 a WHERE (a.a) IN (-1,52,56)");
	}

	public function testObject() {
		foreach (DBo::object("SELECT a FROM test.t1 WHERE a=1") as $o) {
			$this->assertInstanceOf("DBo", $o);
			$this->assertEquals($o->a, '1');
		}
		foreach (DBo::object("SELECT b FROM test.t1 WHERE a=?", [1]) as $o) {
			$this->assertInstanceOf("DBo", $o);
			$this->assertEquals($o->c, "ab");
		}
	}

	public function testOkeyValue() {
		DBo::query("INSERT INTO test.t1 (a,b) VALUES (57,1),(58,2)");
		$this->assertEquals(DBo::t1([-1,57,58])->okeyValue("a", "b"), ["57"=>"1","58"=>"2"]);
	}

	public function testOvalues() {
		DBo::query("INSERT INTO test.t1 (a) VALUES (59),(60)");
		$this->assertEquals(DBo::t1([59,60])->ovalues("a"), ["59","60"]);
	}

	public function testIterator() {
		$a = ["-1","1"];                 
		foreach (DBo::t1($a) as $o) {
			$this->assertInstanceOf("DBo", $o);
			$this->assertEquals($o->a, next($a));
		}
		$this->assertEquals(end(mysqli_log::$queries), "SELECT a.* FROM test.t1 a WHERE (a.a) IN (-1,1)");
	}

	public function testGet() {
		$this->assertEquals(DBo::t1(1)->a, 1);
		$this->assertEquals(DBo::t1(1)->invalid, false);

		DBo::query("INSERT INTO test.t1 VALUES (2,'a,b,c')");
		DBo::query("INSERT INTO test.t1 VALUES (3,?)", ['{"a":"b"}']);

		$this->assertEquals(DBo::t3(1)->b_arr, ["a","b","c"]);
		$this->assertEquals(DBo::t3(1)->b_json, ["a"=>"b"]);

		// utilize used columns in second run
		$q = [null, "SELECT a.* FROM test.t1 a WHERE a.a=1 LIMIT 1", "SELECT a.b FROM test.t1 a WHERE a.a=1 LIMIT 1"];
		for ($i=0; $i<2; $i++) {
			$this->assertEquals(DBo::t1(1)->b, "a");
			$this->assertEquals(end(mysqli_log::$queries), next($q));
		}
	}

	public function testPrint_r() {
		$this->expectOutputString("Array\n(\n    [a] => 1\n    [b] => 2\n    [c] => ab\n)\n");
		DBo::t1(1)->print_r();
	}
}