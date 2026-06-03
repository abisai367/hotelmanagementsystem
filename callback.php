<?php
header("Content-Type: application/json");
include 'database.php';

$stkCallbackResponse = file_get_contents('php://input');

$logFile = "MpesaResponse.json";
logData($stkCallbackResponse, $logFile);

$data = json_decode($stkCallbackResponse, true);

if (!$data || !isset($data['Body']['stkCallback'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid callback format"]);
    exit;
}

try {
    $callback = $data['Body']['stkCallback'];
    
    $resultCode = $callback['ResultCode'] ?? null;
    $resultDesc = $callback['ResultDesc'] ?? '';
    $merchantRequestID = $callback['MerchantRequestID'] ?? '';
    $checkoutRequestID = $callback['CheckoutRequestID'] ?? null;

    if (!$checkoutRequestID) {
        throw new Exception("Missing CheckoutRequestID");
    }

    $stmt = $conn->prepare("SELECT order_id FROM orders WHERE checkout_request_id = ?");
    $stmt->bind_param("s", $checkoutRequestID);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();
    $orderId = $order['order_id'] ?? 0;

    if ($resultCode == 0) {
        $callbackMetadata = $callback['CallbackMetadata']['Item'] ?? [];
        
        $amount = 0;
        $mpesaReceiptNumber = "";
        $transactionDate = "";
        $phoneNumber = "";

        foreach ($callbackMetadata as $item) {
            if ($item['Name'] === 'Amount') {
                $amount = $item['Value'];
            }
            if ($item['Name'] === 'MpesaReceiptNumber') {
                $mpesaReceiptNumber = $item['Value'];
            }
            if ($item['Name'] === 'TransactionDate') {
                $transactionDate = $item['Value'];
            }
            if ($item['Name'] === 'PhoneNumber') {
                $phoneNumber = $item['Value'];
            }
        }

        $formattedDate = DateTime::createFromFormat('YmdHis', $transactionDate)->format('Y-m-d H:i:s');

        $updateStmt = $conn->prepare("UPDATE orders SET payment_status = 'Paid', transaction_date = ? WHERE order_id = ?");
        $updateStmt->bind_param("si", $formattedDate, $orderId);
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update order: " . $updateStmt->error);
        }

        $logStmt = $conn->prepare(
            "INSERT INTO mpesa_transactions (order_id, merchant_request_id, checkout_request_id, result_code, result_desc, amount, mpesa_receipt_number, transaction_date, phone_number) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        
        $logStmt->bind_param(
            "issiidsss",
            $orderId,
            $merchantRequestID,
            $checkoutRequestID,
            $resultCode,
            $resultDesc,
            $amount,
            $mpesaReceiptNumber,
            $formattedDate,
            $phoneNumber
        );
        
        if (!$logStmt->execute()) {
            throw new Exception("Failed to log transaction: " . $logStmt->error);
        }

        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "Payment recorded successfully",
            "orderId" => $orderId
        ]);

    } else {
        $updateStmt = $conn->prepare("UPDATE orders SET payment_status = 'Failed' WHERE order_id = ?");
        $updateStmt->bind_param("i", $orderId);
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update order: " . $updateStmt->error);
        }
        $logStmt = $conn->prepare(
            "INSERT INTO mpesa_transactions (order_id, merchant_request_id, checkout_request_id, result_code, result_desc) 
             VALUES (?, ?, ?, ?, ?)"
        );
        
        $logStmt->bind_param(
            "issis",
            $orderId,
            $merchantRequestID,
            $checkoutRequestID,
            $resultCode,
            $resultDesc
        );
        
        $logStmt->execute();

        http_response_code(200);
        echo json_encode([
            "status" => "failed",
            "message" => "Payment failed: " . $resultDesc,
            "resultCode" => $resultCode
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Server error: " . $e->getMessage()
    ]);
}

function logData($data, $filename) {
    $fp = fopen($filename, 'a');
    if ($fp) {
        fwrite($fp, "[" . date('Y-m-d H:i:s') . "] " . $data . "\n");
        fclose($fp);
    }
}
