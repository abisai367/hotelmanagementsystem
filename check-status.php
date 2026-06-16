<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

$conn = null;
include "database.php";
include_once "hotel_helpers.php";

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
if ($order_id <= 0) {
    echo json_encode(["error" => "Missing order ID"]);
    exit;
}

ensureCoreSchema($conn);
$attended = attendedColumn($conn);
$attendedSelect = $attended ? "`{$attended}` AS attended_to" : "'No' AS attended_to";

$stmt = $conn->prepare("SELECT payment_status, {$attendedSelect} FROM orders WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if ($result) {
    echo json_encode([
        "status" => $result['payment_status'] ?? 'Pending',
        "attended_to" => $result['attended_to'] ?? 'No'
    ]);
} else {
    echo json_encode(["status" => "NotFound", "attended_to" => "No"]);
}
?>
