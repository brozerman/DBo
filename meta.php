<?php
/**
 * materialize schema data to PHP array
 * @author Thomas Bley
 * @license MIT Public License
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

$cols = [];
$pkeys = [];
$fkeys = [];
$idx = [];
$db = new mysqli('127.0.0.1', 'root', '');
foreach ($db->query("SELECT * FROM information_schema.columns WHERE table_schema NOT IN ('information_schema','performance_schema','mysql')") as $row) {
	$cols[ $row['TABLE_SCHEMA'] ][ $row['TABLE_NAME'] ][ $row['COLUMN_NAME'] ] = 1;
}
foreach ($db->query("SELECT * FROM information_schema.key_column_usage WHERE table_schema NOT IN ('information_schema','performance_schema','mysql')") as $row) {
	if ($row['CONSTRAINT_NAME'] == 'PRIMARY') {
		$pkeys[ $row['TABLE_SCHEMA'] ][ $row['TABLE_NAME'] ][] = $row['COLUMN_NAME'];
	} else if (!empty($row['REFERENCED_COLUMN_NAME'])) {
		$fkeys[ $row['TABLE_SCHEMA'] ][ $row['TABLE_NAME'] ][ $row['COLUMN_NAME'] ][ $row['REFERENCED_TABLE_SCHEMA'].'.'.$row['REFERENCED_TABLE_NAME'] ] = $row['REFERENCED_COLUMN_NAME'];
	}
	$idx[ $row['TABLE_SCHEMA'] ][ $row['TABLE_NAME'] ][] = $row['COLUMN_NAME'];
}

$from = [' ', "\n", 'array(', ',)', '$'];
$to = ['', '', '[', ']', "\n\$"];
file_put_contents('meta_data.php', str_replace($from, $to, '<?php'.
	'$cols='.var_export($cols, true).';'.
	'$pkeys='.var_export($pkeys, true).';'.
	'$fkeys='.var_export($fkeys, true).';'.
	'$idx='.var_export($idx, true).';'), LOCK_EX
);

$cols = null;
include 'meta_data.php';
if (!empty($cols)) echo 'Meta data created'; else 'Error';