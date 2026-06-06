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
    $customer_id = $_POST['customer_id'] ?? '';
    $product_id = $_POST['product_id'] ?? '';

    if (empty($customer_id) || empty($product_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing relational parameters.']);
        exit;
    }

    $sql = "INSERT INTO cart (customer_id, product_id) VALUES (?, ?)";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $customer_id, $product_id);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['status' => 'success', 'message' => 'Cart updated.']);
    } else {
        error_log("add_to_cart.php insert failed: " . mysqli_error($conn));
        echo json_encode(['status' => 'error', 'message' => 'Server error.']);
    }
    mysqli_stmt_close($stmt);
}
mysqli_close($conn);
?>
