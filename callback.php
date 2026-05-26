<?php
include 'database.php';

header("Content-Type: application/json");header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

$data = file_get_contents("php://input");
file_put_contents("mpesa_callback_log.json", $data . PHP_EOL, FILE_APPEND);

$callback = json_decode($data, true);

if (!isset($callback['Body']['stkCallback'])) {
    echo json_encode(["ResultCode" => 0, "ResultDesc" => "Invalid payload"]);
    exit;
}

$stk = $callback['Body']['stkCallback'];

$resultCode = $stk['ResultCode'];
$checkoutRequestID = $stk['CheckoutRequestID'];

if ($resultCode == 0) {
    $status = "Completed";
} else {
    $status = "Failed";
}

$stmt = $conn->prepare("
    UPDATE payments
    SET payment_status = ?
    WHERE payment_ref = ?
");

$stmt->bind_param("ss", $status, $checkoutRequestID);
$stmt->execute();
$stmt->close();

$conn->close();


echo json_encode([
    "ResultCode" => 0,
    "ResultDesc" => "Accepted"
]);
?>