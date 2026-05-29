<?php
$db_server = "localhost";
$db_user = "root";
$db_pass = "";
$conn = "";
$db_name = "hotelmanagementsystem";
try {
    $conn = mysqli_connect($db_server, $db_user, $db_pass, $db_name, 3307);
} catch (Exception $e) {
}

?>