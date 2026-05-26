<?php
include "database.php"; 

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  
    $product_name = $_POST['product_name'] ?? '';
    $price = $_POST['price'] ?? '';
    
    $product_id = "SELECT product_id FROM products WHERE product_name = ?";
    $chkstmt = mysqli_prepare($conn, $chksql);
    mysqli_stmt_bind_param($chkstmt, "s", $product_name);
    mysqli_stmt_execute($chkstmt);
    $result = mysqli_stmt_get_result($chkstmt);
    if(mysqli_num_rows($result) > 0){
        echo json_encode(['status' => 'error', 
                        'message' => 'Product already exist']);   
        exit;
    }
    }