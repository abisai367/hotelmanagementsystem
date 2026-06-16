<?php
header("Content-Type: application/json");

$conn = null;
include 'database.php';
include_once 'hotel_helpers.php';
include_once 'mpesa_helpers.php';

if (!isset($conn) || !$conn) {
    error_log('callback: missing DB connection');
    logData("DB connection unavailable", "MpesaResponse.json");
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    exit;
}

$stkCallbackResponse = file_get_contents('php://input');
$logFile = "MpesaResponse.json";
logData($stkCallbackResponse, $logFile);

$data = json_decode($stkCallbackResponse, true);
if (!$data || !isset($data['Body']['stkCallback'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid callback format"]);
    exit;
}

function callbackMetaValue(array $items, string $name) {
    foreach ($items as $item) {
        if (($item['Name'] ?? '') === $name) {
            return $item['Value'] ?? null;
        }
    }
    return null;
}

function formatMpesaDate($raw): ?string {
    if (!$raw) {
        return null;
    }
    $date = DateTime::createFromFormat('YmdHis', (string)$raw);
    return $date ? $date->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
}

try {
    ensureCoreSchema($conn);

    $callback = $data['Body']['stkCallback'];
    $resultCode = $callback['ResultCode'] ?? null;
    $resultDesc = $callback['ResultDesc'] ?? '';
    $merchantRequestID = $callback['MerchantRequestID'] ?? '';
    $checkoutRequestID = $callback['CheckoutRequestID'] ?? null;

    if (!$checkoutRequestID) {
        throw new Exception("Missing CheckoutRequestID");
    }

    $metadata = $callback['CallbackMetadata']['Item'] ?? [];
    $amount = callbackMetaValue($metadata, 'Amount') ?: 0;
    $mpesaReceiptNumber = callbackMetaValue($metadata, 'MpesaReceiptNumber') ?: '';
    $transactionDate = formatMpesaDate(callbackMetaValue($metadata, 'TransactionDate'));
    $phoneNumber = callbackMetaValue($metadata, 'PhoneNumber') ?: '';

    $payrollStmt = $conn->prepare("SELECT id FROM payroll_batches WHERE checkout_request_id = ? LIMIT 1");
    $payrollStmt->bind_param('s', $checkoutRequestID);
    $payrollStmt->execute();
    $payroll = $payrollStmt->get_result()->fetch_assoc();
    $payrollStmt->close();

    if ($payroll) {
        $batchId = intval($payroll['id']);
        if (intval($resultCode) === 0) {
            $stmt = $conn->prepare("UPDATE payroll_batches SET status = 'Paid', mpesa_receipt_number = ?, transaction_date = ?, paid_at = NOW() WHERE id = ?");
            $stmt->bind_param('ssi', $mpesaReceiptNumber, $transactionDate, $batchId);
            $stmt->execute();
            $stmt->close();

            $items = $conn->prepare("UPDATE salary_payments SET payment_status = 'Paid', paid_at = NOW() WHERE batch_id = ?");
            $items->bind_param('i', $batchId);
            $items->execute();
            $items->close();

            // Send SMS notifications to all employees in this batch
            $smsCount = sendPaymentSMSToEmployees($conn, $batchId);
            error_log("Salary payment SMS sent to {$smsCount} employees for batch {$batchId}");

            echo json_encode(['status' => 'success', 'message' => 'Salary payment recorded', 'batchId' => $batchId, 'smsSent' => $smsCount]);
            exit;
        }

        $stmt = $conn->prepare("UPDATE payroll_batches SET status = 'Failed' WHERE id = ?");
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $stmt->close();

        $items = $conn->prepare("UPDATE salary_payments SET payment_status = 'Failed' WHERE batch_id = ?");
        $items->bind_param('i', $batchId);
        $items->execute();
        $items->close();

        echo json_encode(['status' => 'failed', 'message' => 'Salary payment failed: ' . $resultDesc, 'batchId' => $batchId]);
        exit;
    }

    $stmt = $conn->prepare("SELECT order_id, order_type FROM orders WHERE checkout_request_id = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param("s", $checkoutRequestID);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        throw new Exception("No order or payroll batch found for checkout request");
    }

    $orderId = intval($order['order_id']);

    if (intval($resultCode) === 0) {
        $updateStmt = $conn->prepare("UPDATE orders SET payment_status = 'Paid', transaction_date = ? WHERE order_id = ?");
        if (!$updateStmt) {
            throw new Exception('Update prepare failed: ' . $conn->error);
        }
        $updateStmt->bind_param("si", $transactionDate, $orderId);
        $updateStmt->execute();
        $updateStmt->close();

        $logStmt = $conn->prepare(
            "INSERT INTO mpesa_transactions (order_id, merchant_request_id, checkout_request_id, result_code, result_desc, amount, mpesa_receipt_number, transaction_date, phone_number)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$logStmt) {
            throw new Exception('Insert prepare failed: ' . $conn->error);
        }
        $logStmt->bind_param(
            "issisdsss",
            $orderId,
            $merchantRequestID,
            $checkoutRequestID,
            $resultCode,
            $resultDesc,
            $amount,
            $mpesaReceiptNumber,
            $transactionDate,
            $phoneNumber
        );
        $logStmt->execute();
        $logStmt->close();

        if ($order['order_type'] === 'dineIn') {
            rebalanceAssignments($conn, 'dineIn');
        } elseif ($order['order_type'] === 'delivery') {
            rebalanceAssignments($conn, 'delivery');
        }

        echo json_encode(["status" => "success", "message" => "Payment recorded successfully", "orderId" => $orderId]);
        exit;
    }

    $updateStmt = $conn->prepare("UPDATE orders SET payment_status = 'Failed' WHERE order_id = ?");
    $updateStmt->bind_param("i", $orderId);
    $updateStmt->execute();
    $updateStmt->close();

    $logStmt = $conn->prepare(
        "INSERT INTO mpesa_transactions (order_id, merchant_request_id, checkout_request_id, result_code, result_desc)
         VALUES (?, ?, ?, ?, ?)"
    );
    $logStmt->bind_param("issis", $orderId, $merchantRequestID, $checkoutRequestID, $resultCode, $resultDesc);
    $logStmt->execute();
    $logStmt->close();

    echo json_encode(["status" => "failed", "message" => "Payment failed: " . $resultDesc, "resultCode" => $resultCode]);
} catch (Exception $e) {
    logData("EXCEPTION: " . $e->getMessage() . " -- " . $e->getTraceAsString(), $logFile);
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Server error processing callback"]);
}

function logData($data, $filename) {
    $fp = fopen($filename, 'a');
    if ($fp) {
        fwrite($fp, "[" . date('Y-m-d H:i:s') . "] " . $data . "\n");
        fclose($fp);
    }
}

mysqli_close($conn);
?>
