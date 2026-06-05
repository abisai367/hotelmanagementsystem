<?php
$conn = null;
include "database.php"; 
/** @var mysqli $conn */
if (!isset($conn) || !$conn) { error_log('addcategory: missing DB connection'); http_response_code(500); echo json_encode(['status'=>'error','message'=>'Database connection unavailable']); exit; }

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = $_POST['description'] ?? '';
    $product_name = $_POST['product_name'] ?? '';
    $price = $_POST['price'] ?? '';
    $file_url = $_POST['file_url'] ?? ''; 
    
    if (empty($description) || empty($product_name) || empty($price) || empty($file_url)) {
        echo json_encode(['status' => 'error', 'message' => 'All fields including image are required.']);
        exit;
    }

    if (!is_numeric($price) || floatval($price) <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Price must be a positive number']);
        exit;
    }

    $chksql = "SELECT product_name FROM products WHERE product_name = ?";
    $chkstmt = mysqli_prepare($conn, $chksql);
    if (!$chkstmt) {
        error_log('addcategory check prepare error: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server error']);
        exit;
    }
    mysqli_stmt_bind_param($chkstmt, "s", $product_name);
    mysqli_stmt_execute($chkstmt);
    $result = mysqli_stmt_get_result($chkstmt);
    if(mysqli_num_rows($result) > 0){
        echo json_encode(['status' => 'error', 'message' => 'Product already exists']);   
        exit;
    }
    mysqli_stmt_close($chkstmt);

    $sql = "INSERT INTO products (description, product_name, price, product_path) VALUES (?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('addcategory insert prepare error: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server error']);
        exit;
    }
    
    mysqli_stmt_bind_param($stmt, "ssds", $description, $product_name, $price, $file_url);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['status' => 'success', 'message' => 'Product added successfully']);
    } else {
        error_log('addcategory insert execute error: ' . $stmt->error);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to add product']);
    }
    mysqli_stmt_close($stmt);
}

mysqli_close($conn);
?>
