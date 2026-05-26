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
    $description = $_POST['description'] ?? '';
    $product_name = $_POST['product_name'] ?? '';
    $price = $_POST['price'] ?? '';
    
    $chksql = "SELECT product_name FROM products WHERE product_name = ?";
    $chkstmt = mysqli_prepare($conn, $chksql);
    mysqli_stmt_bind_param($chkstmt, "s", $product_name);
    mysqli_stmt_execute($chkstmt);
    $result = mysqli_stmt_get_result($chkstmt);
    if(mysqli_num_rows($result) > 0){
        echo json_encode(['status' => 'error', 
                        'message' => 'Product already exist']);   
        exit;
    }

    
    if (!isset($_FILES['file'])) {
        echo json_encode(["status" => "error", "message" => "No file uploaded"]);
        exit;
    }

    $file = $_FILES['file'];
    $file_name = time() . "_" . $file['name'];
    $file_tmp = $file['tmp_name'];
    

    $uploadDirectory = '../public/uploads/products/';
    
    if (!is_dir($uploadDirectory)) {
        mkdir($uploadDirectory, 0777, true);
    }

    $targetFilePath = $uploadDirectory . basename($file_name);

    if (move_uploaded_file($file_tmp, $targetFilePath)) {

        $sql = "INSERT INTO products (description, product_name, price, product_path) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssds", $description, $product_name, $price, $file_name);

        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['status' => 'success', 'message' => 'Product added successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
        }
        mysqli_stmt_close($stmt);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to move uploaded file. Check folder permissions.']);
    }
}

mysqli_close($conn);
?>