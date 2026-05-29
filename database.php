<?php
if (getenv('MYSQLURL') || getenv('MYSQL_URL')) {
    $db_server = getenv('MYSQLHOST') ?: 'roundhouse.proxy.rlwy.net';
    $db_user   = getenv('MYSQLUSER')   ?: 'root';
    $db_pass   = getenv('MYSQLPASSWORD') ?: '';
    $db_name   = getenv('MYSQLDATABASE') ?: 'railway';
    $db_port   = getenv('MYSQLPORT')     ?: 3306;
} else {
    $db_server = "localhost";
    $db_user   = "root";
    $db_pass   = "";
    $db_name   = "hotelmanagementsystem";
    $db_port   = 3307;
}

try {
    $conn = mysqli_connect($db_server, $db_user, $db_pass, $db_name, $db_port);
    if (!$conn) {
        echo json_encode(["status" => "error", "message" => "Database link down: " . mysqli_connect_error()]);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Connection execution exception: " . $e->getMessage()]);
    exit;
}
?>
