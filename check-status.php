<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once __DIR__ . '/../vendor/autoload.php';

include "database.php"; 

$order_id = $_GET['order_id'] ?? null;

if (!$order_id) {
    echo json_encode(["error" => "Missing order ID"]);
    exit;
}

$stmt = $conn->prepare("SELECT payment_status, Attended_to FROM orders WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if ($result) {
    echo json_encode([
        "status" => $result['payment_status'],
        "attended_to" => $result['Attended_to']
    ]);
} else {
    echo json_encode(["status" => "NotFound", "attended_to" => "No"]);
}
