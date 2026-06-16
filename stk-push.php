<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include 'database.php';

// Load .env.production first if present, otherwise fall back to .env.
$dotenvPath = __DIR__ . '/../';
$dotenvFiles = ['.env.production', '.env'];
$dotenvToLoad = null;
foreach ($dotenvFiles as $filename) {
    if (file_exists($dotenvPath . $filename)) {
        $dotenvToLoad = $filename;
        break;
    }
}

if ($dotenvToLoad) {
    if (file_exists($dotenvPath . 'vendor/autoload.php')) {
        require_once $dotenvPath . 'vendor/autoload.php';
    }
    if (class_exists('Dotenv\\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable($dotenvPath, [$dotenvToLoad]);
        $dotenv->safeLoad();
    } else {
        loadEnvFile($dotenvPath . $dotenvToLoad);
    }
}

$inputData = file_get_contents("php://input");

function loadEnvFile(string $path): void {
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
            continue;
        }
        $name = $matches[1];
        $value = $matches[2];
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        if (getenv($name) === false) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

$data = json_decode($inputData, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON input"]);
    exit;
}


$phone = $data['phone'] ?? null;
$amount = $data['amount'] ?? null;
$customerId = $data['customerId'] ?? null;
$items = $data['items'] ?? [];
$orderType = $data['orderType'] ?? 'dineIn';
$tableNumber = $data['tableNumber'] ?? null;
$pickupTime = $data['pickupTime'] ?? null;
$deliveryAddress = $data['deliveryAddress'] ?? null;
$deliveryLatitude = isset($data['deliveryLatitude']) ? floatval($data['deliveryLatitude']) : null;
$deliveryLongitude = isset($data['deliveryLongitude']) ? floatval($data['deliveryLongitude']) : null;
$contactNumber = $data['contactNumber'] ?? null;

if (!$phone || !$amount || !$customerId) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing required fields: phone, amount, customerId"]);
    exit;
}

if (!in_array($orderType, ['dineIn', 'takeAway', 'delivery'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid order type"]);
    exit;
}

if ($orderType === 'dineIn' && !$tableNumber) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Table number is required for dine-in orders"]);
    exit;
}

if ($orderType === 'takeAway') {
    if (!$pickupTime) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Pickup time is required for take-away orders"]);
        exit;
    }
    $pickupDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $pickupTime);
    if (!$pickupDateTime || $pickupDateTime->format('Y-m-d H:i:s') !== $pickupTime) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Pickup time must be a valid datetime in Y-m-d H:i:s format"]);
        exit;
    }
    if ($pickupDateTime <= new DateTime()) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Pickup time must be later than the current time"]);
        exit;
    }
    $pickupTime = $pickupDateTime->format('Y-m-d H:i:s');
    if (!$contactNumber) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Contact number is required for take-away orders"]);
        exit;
    }
    $deliveryAddress = null;
    $deliveryLatitude = null;
    $deliveryLongitude = null;
}

if ($orderType === 'delivery') {
    if (!$deliveryAddress) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Delivery address is required for delivery orders"]);
        exit;
    }
    if ($deliveryLatitude === null || $deliveryLongitude === null) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Delivery location coordinates are required for delivery orders"]);
        exit;
    }
    if (!is_numeric($deliveryLatitude) || !is_numeric($deliveryLongitude)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid delivery coordinates"]);
        exit;
    }
    if ($deliveryLatitude < -90 || $deliveryLatitude > 90 || $deliveryLongitude < -180 || $deliveryLongitude > 180) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Delivery coordinates out of valid range"]);
        exit;
    }
    if (!$contactNumber) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Contact number is required for delivery orders"]);
        exit;
    }
    $pickupTime = null;
}

if ($orderType === 'dineIn') {
    $deliveryAddress = null;
    $deliveryLatitude = null;
    $deliveryLongitude = null;
    $contactNumber = null;
    $pickupTime = null;
}
if (!preg_match('/^254\d{9}$/', $phone)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid phone format. Use 254XXXXXXXX"]);
    exit;
}

if ($amount < 1) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Amount must be at least 1 KES"]);
    exit;
}

try {
    $consumerKey = $_ENV['MPESA_CONSUMER_KEY'] ?? getenv('MPESA_CONSUMER_KEY');
    $consumerSecret = $_ENV['MPESA_CONSUMER_SECRET'] ?? getenv('MPESA_CONSUMER_SECRET');
    $businessCode = $_ENV['MPESA_BUSINESS_CODE'] ?? getenv('MPESA_BUSINESS_CODE');
    $passkey = $_ENV['MPESA_PASSKEY'] ?? getenv('MPESA_PASSKEY');
    $callbackUrl = $_ENV['MPESA_CALLBACK_URL'] ?? getenv('MPESA_CALLBACK_URL');

    if (!$consumerKey || !$consumerSecret || !$businessCode || !$passkey || !$callbackUrl) {
        throw new Exception("M-Pesa credentials not properly configured in .env.production");
    }

    // Allow disabling SSL verification only when explicitly enabled via env var (for sandbox/testing).
    // By default SSL verification is enabled.
    $mpesaAllowInsecure = (isset($_ENV['MPESA_ALLOW_INSECURE']) ? $_ENV['MPESA_ALLOW_INSECURE'] : (getenv('MPESA_ALLOW_INSECURE') ?: 'false')) === 'true';
    $mpesaVerifyPeer = $mpesaAllowInsecure ? false : true;
    $tokenUrl = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    $credentials = base64_encode($consumerKey . ':' . $consumerSecret);

    $tokenCurl = curl_init();
    curl_setopt($tokenCurl, CURLOPT_URL, $tokenUrl);
    curl_setopt($tokenCurl, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
    curl_setopt($tokenCurl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($tokenCurl, CURLOPT_HEADER, false);
    curl_setopt($tokenCurl, CURLOPT_SSL_VERIFYPEER, $mpesaVerifyPeer);
    curl_setopt($tokenCurl, CURLOPT_TIMEOUT, 30);
    
    $tokenResponse = curl_exec($tokenCurl);
    $tokenHttpCode = curl_getinfo($tokenCurl, CURLINFO_HTTP_CODE);
    curl_close($tokenCurl);

    logDebug('MpesaDebug.log', "TOKEN_RESPONSE CODE={$tokenHttpCode} BODY={$tokenResponse}");

    if ($tokenHttpCode !== 200) {
        $errorData = json_decode($tokenResponse, true);
        $errorMessage = $errorData['errorMessage'] ?? $tokenResponse;
        throw new Exception("Failed to get access token: HTTP $tokenHttpCode - $errorMessage");
    }

    $tokenData = json_decode($tokenResponse, true);
    if (!isset($tokenData['access_token'])) {
        throw new Exception("No access token in response: $tokenResponse");
    }

    $accessToken = $tokenData['access_token'];

    $stkUrl = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    
    $timestamp = date('YmdHis');
    $password = base64_encode($businessCode . $passkey . $timestamp);

    $stkPayload = [
        'BusinessShortCode' => $businessCode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => intval($amount),
        'PartyA' => $phone,
        'PartyB' => $businessCode,
        'PhoneNumber' => $phone,
        'CallBackURL' => $callbackUrl,
        'AccountReference' => 'Order-' . time(),
        'TransactionDesc' => 'Hotel Order Payment'
    ];

    $dataString = json_encode($stkPayload);

    $stkCurl = curl_init();
    curl_setopt($stkCurl, CURLOPT_URL, $stkUrl);
    curl_setopt($stkCurl, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ]);
    curl_setopt($stkCurl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($stkCurl, CURLOPT_POST, true);
    curl_setopt($stkCurl, CURLOPT_POSTFIELDS, $dataString);
    curl_setopt($stkCurl, CURLOPT_SSL_VERIFYPEER, $mpesaVerifyPeer);
    curl_setopt($stkCurl, CURLOPT_TIMEOUT, 30);

    $stkResponse = curl_exec($stkCurl);
    $stkHttpCode = curl_getinfo($stkCurl, CURLINFO_HTTP_CODE);
    curl_close($stkCurl);

    logDebug('MpesaDebug.log', "STK_REQUEST URL={$stkUrl} PAYLOAD=" . json_encode($stkPayload) . " RESPONSE_CODE={$stkHttpCode} BODY={$stkResponse}");

    $stkData = json_decode($stkResponse, true);

    if ($stkHttpCode === 200 && isset($stkData['CheckoutRequestID'])) {
            // Try inserting order including amount; if the orders table doesn't have an 'amount' column,
            // fall back to an insert without amount so the flow continues.
            $orderId = 0;
            $insertStmt = $conn->prepare(
                "INSERT INTO orders (customer_id, amount, phone_number, order_type, table_number, pickup_time, delivery_address, delivery_latitude, delivery_longitude, contact_number, payment_status, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())"
            );

            if ($insertStmt) {
                $insertStmt->bind_param(
                    "isssssssddi",
                    $customerId,
                    $amount,
                    $phone,
                    $orderType,
                    $tableNumber,
                    $pickupTime,
                    $deliveryAddress,
                    $deliveryLatitude,
                    $deliveryLongitude,
                    $contactNumber
                );

                if (!$insertStmt->execute()) {
                    $err = $insertStmt->error;
                    // Unknown column (1054) or explicit message about 'amount' -> retry without amount
                    if (strpos($err, "Unknown column 'amount'") !== false || mysqli_errno($conn) === 1054) {
                        $insertStmt->close();
                        $insertStmt = $conn->prepare(
                            "INSERT INTO orders (customer_id, phone_number, order_type, table_number, pickup_time, delivery_address, delivery_latitude, delivery_longitude, contact_number, payment_status, created_at) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())"
                        );
                        if (!$insertStmt) {
                            throw new Exception('Database insert failed (fallback prepare): ' . $conn->error);
                        }
                        $insertStmt->bind_param(
                            "isssssddi",
                            $customerId,
                            $phone,
                            $orderType,
                            $tableNumber,
                            $pickupTime,
                            $deliveryAddress,
                            $deliveryLatitude,
                            $deliveryLongitude,
                            $contactNumber
                        );
                        if (!$insertStmt->execute()) {
                            throw new Exception('Database insert failed (fallback): ' . $insertStmt->error);
                        }
                    } else {
                        throw new Exception("Database insert failed: " . $err);
                    }
                }
                $orderId = $conn->insert_id;
            } else {
                throw new Exception('Database insert failed (prepare): ' . $conn->error);
            }
        
        $updateStmt = $conn->prepare("UPDATE orders SET checkout_request_id = ? WHERE order_id = ?");
        $updateStmt->bind_param("si", $stkData['CheckoutRequestID'], $orderId);
        $updateStmt->execute();
        
        // For each item, try to merge into existing pending order with `Attended to` = 'No' for this customer
        $newOrderHasItems = false;
        foreach ($items as $item) {
            $productId = $item['product_id'] ?? 0;
            $quantity = $item['quantity'] ?? 1;
            $price = $item['price'] ?? 0;

            $existingStmt = $conn->prepare(
                "SELECT oi.id AS oi_id, oi.quantity AS qty, o.order_id AS existing_order_id FROM order_items oi JOIN orders o ON oi.order_id = o.order_id WHERE o.customer_id = ? AND oi.product_id = ? AND COALESCE(o.`Attended to`, 'No') = 'No' LIMIT 1"
            );
            if ($existingStmt) {
                $existingStmt->bind_param('ii', $customerId, $productId);
                $existingStmt->execute();
                $res = $existingStmt->get_result();
                $found = $res->fetch_assoc();
                if ($found) {
                    // Update the existing order item quantity
                    $newQty = intval($found['qty']) + intval($quantity);
                    $updateOi = $conn->prepare("UPDATE order_items SET quantity = ? WHERE id = ?");
                    if ($updateOi) {
                        $updateOi->bind_param('ii', $newQty, $found['oi_id']);
                        $updateOi->execute();
                        $updateOi->close();
                    }
                    $existingStmt->close();
                    continue; // skip inserting into the newly created order
                }
                $existingStmt->close();
            }

            // Insert into the new order
            $itemInsertStmt = $conn->prepare(
                "INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)"
            );
            if ($itemInsertStmt) {
                $itemInsertStmt->bind_param("iidi", $orderId, $productId, $quantity, $price);
                $itemInsertStmt->execute();
                $itemInsertStmt->close();
                $newOrderHasItems = true;
            }
        }

        // If the newly created order ended up with no items (because they were merged into existing orders), delete it to keep DB tidy
        if (!$newOrderHasItems) {
            $del = $conn->prepare("DELETE FROM orders WHERE order_id = ?");
            if ($del) {
                $del->bind_param('i', $orderId);
                $del->execute();
                $del->close();
            }
        }

        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "STK Push sent successfully",
            "checkoutRequestID" => $stkData['CheckoutRequestID'],
            "orderId" => $orderId,
            "merchantRequestID" => $stkData['MerchantRequestID'] ?? null
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "STK Push failed: " . ($stkData['errorMessage'] ?? "Unknown error"),
            "responseCode" => $stkData['responseCode'] ?? null
        ]);
    }

} catch (Exception $e) {
    // Log full exception details for server operators, but do not expose internal errors to clients
    logDebug('MpesaDebug.log', "EXCEPTION: " . $e->getMessage() . " -- " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Server error. Please try again or contact support."
    ]);
}

function logDebug($filename, $data) {
    $fp = fopen($filename, 'a');
    if ($fp) {
        fwrite($fp, "[" . date('Y-m-d H:i:s') . "] " . $data . "\n");
        fclose($fp);
    }
}
