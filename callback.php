<?php
header("Content-Type: application/json");

$stkCallbackResponse = file_get_contents('php://input');
$logFile = "MpesaResponse.json";
logData($stkCallbackResponse, $logFile);

$data = json_decode($stkCallbackResponse, true);

$resultCode = $data['Body']['stkCallback']['ResultCode'];
$resultDesc = $data['Body']['stkCallback']['ResultDesc'];
$merchantRequestID = $data['Body']['stkCallback']['MerchantRequestID'];
$checkoutRequestID = $data['Body']['stkCallback']['CheckoutRequestID'];

$stmt = $conn->prepare("SELECT order_id FROM orders WHERE checkout_request_id = ?");
$stmt->bind_param("s", $checkoutRequestID);
$stmt->execute();
$order = $stmt->get_get_result()->fetch_assoc();
$orderId = $order['order_id'] ?? 0;

if ($resultCode == 0) {
    $callbackMetadata = $data['Body']['stkCallback']['CallbackMetadata']['Item'];
    
    $amount = 0; $mpesaReceiptNumber = ""; $transactionDate = ""; $phoneNumber = "";

    foreach ($callbackMetadata as $item) {
        if ($item['Name'] === 'Amount') $amount = $item['Value'];
        if ($item['Name'] === 'MpesaReceiptNumber') $mpesaReceiptNumber = $item['Value'];
        if ($item['Name'] === 'TransactionDate') $transactionDate = $item['Value'];
        if ($item['Name'] === 'PhoneNumber') $phoneNumber = $item['Value'];
    }
    $formattedDate = DateTime::createFromFormat('YmdHis', $transactionDate)->format('Y-m-d H:i:s');
    $updateStmt = $conn->prepare("UPDATE orders SET payment_status = 'Paid' WHERE order_id = ?");
    $updateStmt->bind_param("i", $orderId);
    $updateStmt->execute();
    $logStmt = $conn->prepare("INSERT INTO mpesa_transactions (order_id, merchant_request_id, checkout_request_id, result_code, result_desc, amount, mpesa_receipt_number, transaction_date, phone_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $logStmt->bind_param("issisdsss", $orderId, $merchantRequestID, $checkoutRequestID, $resultCode, $resultDesc, $amount, $mpesaReceiptNumber, $formattedDate, $phoneNumber);
    $logStmt->execute();
} else {
    $updateStmt = $conn->prepare("UPDATE orders SET payment_status = 'Failed' WHERE order_id = ?");
    $updateStmt->bind_param("i", $orderId);
    $updateStmt->execute();
}

function logData($data, $filename) {
    $fp = fopen($filename, 'a');
    fwrite($fp, $data . "\n");
    fclose($fp);
}
