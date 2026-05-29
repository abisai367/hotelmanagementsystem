<?php
$db_server = getenv('MYSQLHOST') ?: "localhost";
$db_user   = getenv('MYSQLUSER') ?: "root";
$db_pass   = getenv('MYSQLPASSWORD') ?: "";
$db_name   = getenv('MYSQLDATABASE') ?: "hotelmanagementsystem";
$db_port   = getenv('MYSQLPORT') ?: 3307; 

try {
    $conn = mysqli_connect($db_server, $db_user, $db_pass, $db_name, $db_port);
} catch (Exception $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
