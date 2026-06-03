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
    $cart_item_id = $_POST['cart_item_id'] ?? '';
    $customer_id = $_POST['customer_id'] ?? '';

    if (empty($cart_item_id) || empty($customer_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing required cart identifiers.']);
        exit;
    }

    $sql = "DELETE FROM cart WHERE id = ? AND customer_id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
        exit;
    }

    mysqli_stmt_bind_param($stmt, "ii", $cart_item_id, $customer_id);

    if (mysqli_stmt_execute($stmt)) {
        if (mysqli_stmt_affected_rows($stmt) > 0) {
            echo json_encode(['status' => 'success', 'message' => 'Cart item removed successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Item not found or already removed.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
    }

    mysqli_stmt_close($stmt);
}

mysqli_close($conn);
?>