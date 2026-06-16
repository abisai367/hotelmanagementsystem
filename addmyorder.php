<?php
include "database.php"; 
include_once "hotel_helpers.php";

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    $total_price = isset($_POST['total_price']) ? floatval($_POST['total_price']) : 0;
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    $order_type = trim($_POST['order_type'] ?? 'dineIn');
    $table_number = (isset($_POST['table_number']) && $_POST['table_number'] !== '') ? intval($_POST['table_number']) : null;
    $phone_number = trim($_POST['phone_number'] ?? '');
    $checkout_request_id = trim($_POST['checkout_request_id'] ?? '');

    if ($product_id <= 0 || $customer_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'product_id and customer_id are required and must be valid integers']);
        exit;
    }

    try {
        ensureCoreSchema($conn);

        $total_price = number_format($total_price, 2, '.', '');

        $phonePart = $phone_number !== '' ? "'" . mysqli_real_escape_string($conn, $phone_number) . "'" : "NULL";
        $checkoutPart = $checkout_request_id !== '' ? "'" . mysqli_real_escape_string($conn, $checkout_request_id) . "'" : "NULL";
        $tablePart = is_null($table_number) ? "NULL" : intval($table_number);
        $orderTypeEsc = mysqli_real_escape_string($conn, $order_type);

        $sql = "INSERT INTO orders (product_id, quantity, total_price, customer_id, amount, phone_number, order_type, table_number, checkout_request_id, payment_status, created_at)
                VALUES (" . intval($product_id) . ", " . intval($quantity) . ", " . $total_price . ", " . intval($customer_id) . ", " . $total_price . ", " . $phonePart . ", '" . $orderTypeEsc . "', " . $tablePart . ", " . $checkoutPart . ", 'Pending', NOW())";

        if ($conn->query($sql)) {
            $orderId = $conn->insert_id;

            // If using order_items elsewhere, you may want to insert them here.
            if ($order_type === 'dineIn') {
                rebalanceAssignments($conn, 'dineIn');
            } elseif ($order_type === 'delivery') {
                rebalanceAssignments($conn, 'delivery');
            }

            echo json_encode(['status' => 'success', 'message' => 'Order added successfully', 'orderId' => $orderId]);
        } else {
            error_log('addmyorder insert failed: ' . $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Failed to add order']);
        }
    } catch (Exception $e) {
        error_log('addmyorder error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server error']);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
}
mysqli_close($conn);
?>