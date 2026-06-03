<?php
include "database.php"; 

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

$customer_id = $_GET['customer_id'] ?? ($_POST['customer_id'] ?? '');

if (empty($customer_id)) {
    echo json_encode(['status' => 'error', 'message' => 'User identity missing.']);
    exit;
}

$sql = "SELECT c.id AS cart_item_id, c.product_id, c.quantity, p.product_name, p.description, p.price, p.product_path 
        FROM cart c 
        INNER JOIN products p ON c.product_id = p.product_id 
        WHERE c.customer_id = ? 
        ORDER BY c.id DESC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $customer_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$cart_items = [];
while ($row = mysqli_fetch_assoc($result)) {
    $cart_items[] = $row;
}

echo json_encode(['status' => 'success', 'cart' => $cart_items]);
mysqli_stmt_close($stmt);
mysqli_close($conn);
?>
