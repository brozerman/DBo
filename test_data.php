<?php
/**
 * create some test schemas and example datasets
 * @author Thomas Bley
 * @license MIT Public License
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('date.timezone', 'Europe/Berlin');
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

$db = new mysqli('127.0.0.1', 'root', '', 'test');

$order = "
	CREATE TABLE IF NOT EXISTS `order` (id int primary key auto_increment, customer_id int, price numeric(10,2), modified timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP);
";
$product = "
	CREATE TABLE IF NOT EXISTS product (id int primary key auto_increment, name varchar(50), price numeric(10,2), vendor varchar(50), stock int, modified timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP);
";
$customer = "
	CREATE TABLE IF NOT EXISTS customer (id int primary key auto_increment, lastname varchar(50), firstname varchar(50), birthdate date, modified timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP);
";
$orderpos = "
	CREATE TABLE IF NOT EXISTS orderpos (order_id int, pos int, product_id int, amount int, price numeric(10,2), modified timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (order_id, pos));
";
$db->query($order);
$db->query($product);
$db->query($customer);
$db->query($orderpos);

$db->query("INSERT INTO product SET name='Notebook', price=200.12, vendor='Acer', stock=10");
$notebook = $db->insert_id;
$db->query("INSERT INTO customer SET lastname='Doe', firstname='John', birthdate='1930-01-01'");
$db->query("INSERT INTO `order` SET customer_id={$db->insert_id}, price=10");
$db->query("INSERT INTO orderpos SET order_id={$db->insert_id}, pos=0, product_id={$notebook}, amount=1, price=200.12");
