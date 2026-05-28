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
    
    if (empty($description) || empty($product_name) || empty($price) || !isset($_FILES['file'])) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
        exit;
    }

    $chksql = "SELECT product_name FROM products WHERE product_name = ?";
    $chkstmt = mysqli_prepare($conn, $chksql);
    mysqli_stmt_bind_param($chkstmt, "s", $product_name);
    mysqli_stmt_execute($chkstmt);
    $result = mysqli_stmt_get_result($chkstmt);
    if(mysqli_num_rows($result) > 0){
        echo json_encode(['status' => 'error', 'message' => 'Product already exists']);   
        exit;
    }

    $file_tmp = $_FILES['file']['tmp_name'];
    $file_type = $_FILES['file']['type'];
    $file_name = $_FILES['file']['name'];

    $cfile = new CURLFile($file_tmp, $file_type, $file_name);
    
    $fields = [
        'file' => $cfile,
        'upload_preset' => 'edn61l8y'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://cloudinary.com");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo json_encode(['status' => 'error', 'message' => 'Cloudinary connection error: ' . $err]);
        exit;
    }

    $json_res = json_decode($response, true);

    if (isset($json_res['secure_url'])) {
        $uploaded_url = $json_res['secure_url'];

        $sql = "INSERT INTO products (description, product_name, price, product_path) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssds", $description, $product_name, $price, $uploaded_url);

        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['status' => 'success', 'message' => 'Product added successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
        }
        mysqli_stmt_close($stmt);
    } else {
        $debug_error = isset($json_res['error']['message']) ? $json_res['error']['message'] : 'Raw response failure.';
        echo json_encode(['status' => 'error', 'message' => 'Cloud upload failed: ' . $debug_error]);
    }
}

mysqli_close($conn);
?>
