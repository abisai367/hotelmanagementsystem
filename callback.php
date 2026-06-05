<?php
header("Content-Type: application/json");
$conn = null;
include 'database.php';
/** @var mysqli $conn */
if (!isset($conn) || !$conn) { error_log('callback: missing DB connection'); logData("DB connection unavailable", "MpesaResponse.json"); http_response_code(500); echo json_encode(['status'=>'error','message'=>'Server error']); exit; }

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
    if (!$stmt) { throw new Exception('Prepare failed: ' . $conn->error); }
    $stmt->bind_param("s", $checkoutRequestID);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();
    $stmt->close();
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
        if (!$updateStmt) { throw new Exception('Update prepare failed: ' . $conn->error); }
        $updateStmt->bind_param("si", $formattedDate, $orderId);
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update order: " . $updateStmt->error);
        }
        $updateStmt->close();

        $logStmt = $conn->prepare(
            "INSERT INTO mpesa_transactions (order_id, merchant_request_id, checkout_request_id, result_code, result_desc, amount, mpesa_receipt_number, transaction_date, phone_number) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        
        if (!$logStmt) { throw new Exception('Insert prepare failed: ' . $conn->error); }
        
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
        $logStmt->close();

        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "Payment recorded successfully",
            "orderId" => $orderId
        ]);

    } else {
        $updateStmt = $conn->prepare("UPDATE orders SET payment_status = 'Failed' WHERE order_id = ?");
        if (!$updateStmt) { throw new Exception('Update prepare failed: ' . $conn->error); }
        $updateStmt->bind_param("i", $orderId);
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update order: " . $updateStmt->error);
        }
        $updateStmt->close();

        $logStmt = $conn->prepare(
            "INSERT INTO mpesa_transactions (order_id, merchant_request_id, checkout_request_id, result_code, result_desc) 
             VALUES (?, ?, ?, ?, ?)"
        );
        
        if (!$logStmt) { throw new Exception('Insert prepare failed: ' . $conn->error); }
        
        $logStmt->bind_param(
            "issis",
            $orderId,
            $merchantRequestID,
            $checkoutRequestID,
            $resultCode,
            $resultDesc
        );
        
        if (!$logStmt->execute()) {
            throw new Exception("Failed to log failed transaction: " . $logStmt->error);
        }
        $logStmt->close();

        http_response_code(200);
        echo json_encode([
            "status" => "failed",
            "message" => "Payment failed: " . $resultDesc,
            "resultCode" => $resultCode
        ]);
    }

} catch (Exception $e) {
    // Log full error for debugging, but return a generic message to clients
    logData("EXCEPTION: " . $e->getMessage() . " -- " . $e->getTraceAsString(), $logFile);
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Server error processing callback"
    ]);
}

function logData($data, $filename) {
    $fp = fopen($filename, 'a');
    if ($fp) {
        fwrite($fp, "[" . date('Y-m-d H:i:s') . "] " . $data . "\n");
        fclose($fp);
    }
}
?>
