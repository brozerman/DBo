<?php
/**
 * materialize schema data to PHP array
 * @author Thomas Bley
 * @license MIT Public License
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = new mysqli('127.0.0.1', 'root', '');
foreach ($db->query("SELECT * FROM information_schema.columns WHERE table_schema NOT IN ('information_schema','performance_schema','mysql')") as $row) {
	$columns[ $row['TABLE_SCHEMA'] ][ $row['TABLE_NAME'] ][] = $row['COLUMN_NAME'];
	if ($row['COLUMN_KEY'] == 'PRI') {
		$primaries[ $row['TABLE_SCHEMA'] ][ $row['TABLE_NAME'] ][] = $row['COLUMN_NAME'];
	}
}

file_put_contents('meta_data.php', str_replace([' ',"\n",'array(',',)','$'], ['','','[',']',"\n$"], '<?php'.
	'$columns='.var_export($columns, true).';'.
	'$primaries='.var_export($columns, true).';'), LOCK_EX);

$columns = null;
include 'meta_data.php';
if (!empty($columns)) echo 'Meta data created'; else 'Error';