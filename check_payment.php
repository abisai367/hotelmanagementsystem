<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
$conn = null;
include 'database.php';
/** @var mysqli $conn */
if (!isset($conn) || !$conn) { error_log('check_payment: missing DB connection'); http_response_code(500); echo json_encode(['status'=>'error','message'=>'Database connection unavailable']); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$checkout = $_GET['checkoutRequestID'] ?? null;
$orderId = isset($_GET['orderId']) ? intval($_GET['orderId']) : null;

try {
    if ($orderId) {
        $stmt = $conn->prepare("SELECT payment_status, checkout_request_id FROM orders WHERE order_id = ? LIMIT 1");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        if (!$row) {
            echo json_encode(['status' => 'error', 'message' => 'Order not found']);
            exit;
        }
        $paymentStatus = $row['payment_status'] ?? 'Pending';
        $checkout = $row['checkout_request_id'] ?? $checkout;
    } elseif (!$checkout) {
        echo json_encode(['status' => 'error', 'message' => 'Missing identifiers']);
        exit;
    }

    // If a checkoutRequestID was supplied, look up mpesa_transactions
    if ($checkout) {
        $stmt = $conn->prepare("SELECT result_code, result_desc, amount, mpesa_receipt_number, transaction_date FROM mpesa_transactions WHERE checkout_request_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('s', $checkout);
        $stmt->execute();
        $res = $stmt->get_result();
        $tx = $res->fetch_assoc();
        if ($tx) {
            $status = (intval($tx['result_code']) === 0) ? 'Paid' : 'Failed';
            echo json_encode(['status' => 'success', 'paymentStatus' => $status, 'transaction' => $tx]);
            exit;
        }
    }

    // fallback to orders table payment_status
    if ($orderId) {
        echo json_encode(['status' => 'success', 'paymentStatus' => $paymentStatus]);
    } else {
        echo json_encode(['status' => 'success', 'paymentStatus' => 'Pending']);
    }

} catch (Exception $e) {
    error_log('check_payment error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}

?>
