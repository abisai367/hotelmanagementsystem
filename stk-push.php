<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once __DIR__ . '/../vendor/autoload.php';
include 'database.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Get JSON input
$inputData = file_get_contents("php://input");
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
$contactNumber = $data['contactNumber'] ?? null;

// Validate required fields
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
}

if ($orderType === 'delivery') {
    if (!$deliveryAddress) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Delivery address is required for delivery orders"]);
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
    $contactNumber = null;
    $pickupTime = null;
}

// Validate phone format (should be 254XXXXXXXX)
if (!preg_match('/^254\d{9}$/', $phone)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid phone format. Use 254XXXXXXXX"]);
    exit;
}

// Validate amount
if ($amount < 1) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Amount must be at least 1 KES"]);
    exit;
}

try {
    $consumerKey = $_ENV['MPESA_CONSUMER_KEY'] ?? null;
    $consumerSecret = $_ENV['MPESA_CONSUMER_SECRET'] ?? null;
    $businessCode = $_ENV['MPESA_BUSINESS_CODE'] ?? null;
    $passkey = $_ENV['MPESA_PASSKEY'] ?? null;
    $callbackUrl = $_ENV['MPESA_CALLBACK_URL'] ?? null;

    if (!$consumerKey || !$consumerSecret || !$businessCode || !$passkey || !$callbackUrl) {
        throw new Exception("M-Pesa credentials not properly configured in .env.production");
    }
    $tokenUrl = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    $credentials = base64_encode($consumerKey . ':' . $consumerSecret);

    $tokenCurl = curl_init();
    curl_setopt($tokenCurl, CURLOPT_URL, $tokenUrl);
    curl_setopt($tokenCurl, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
    curl_setopt($tokenCurl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($tokenCurl, CURLOPT_HEADER, false);
    curl_setopt($tokenCurl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($tokenCurl, CURLOPT_TIMEOUT, 30);
    
    $tokenResponse = curl_exec($tokenCurl);
    $tokenHttpCode = curl_getinfo($tokenCurl, CURLINFO_HTTP_CODE);
    curl_close($tokenCurl);

    if ($tokenHttpCode !== 200) {
        throw new Exception("Failed to get access token: HTTP $tokenHttpCode");
    }

    $tokenData = json_decode($tokenResponse, true);
    if (!isset($tokenData['access_token'])) {
        throw new Exception("No access token in response");
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
    curl_setopt($stkCurl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($stkCurl, CURLOPT_TIMEOUT, 30);

    $stkResponse = curl_exec($stkCurl);
    $stkHttpCode = curl_getinfo($stkCurl, CURLINFO_HTTP_CODE);
    curl_close($stkCurl);

    $stkData = json_decode($stkResponse, true);

    if ($stkHttpCode === 200 && isset($stkData['CheckoutRequestID'])) {
        $accountRef = 'Order-' . time();
        
        $insertStmt = $conn->prepare(
            "INSERT INTO orders (customer_id, amount, phone_number, order_type, table_number, pickup_time, delivery_address, contact_number, checkout_request_id, payment_status, created_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())"
        );
        
        $insertStmt->bind_param(
            "isssssss",
            $customerId,
            $amount,
            $phone,
            $orderType,
            $tableNumber,
            $pickupTime,
            $deliveryAddress,
            $contactNumber
        );

        if (!$insertStmt->execute()) {
            throw new Exception("Database insert failed: " . $insertStmt->error);
        }

        $orderId = $conn->insert_id;
        
        $updateStmt = $conn->prepare("UPDATE orders SET checkout_request_id = ? WHERE order_id = ?");
        $updateStmt->bind_param("si", $stkData['CheckoutRequestID'], $orderId);
        $updateStmt->execute();
        
        foreach ($items as $item) {
            $itemInsertStmt = $conn->prepare(
                "INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)"
            );
            $productId = $item['product_id'] ?? 0;
            $quantity = $item['quantity'] ?? 1;
            $price = $item['price'] ?? 0;
            
            $itemInsertStmt->bind_param("iidi", $orderId, $productId, $quantity, $price);
            $itemInsertStmt->execute();
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
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Server error: " . $e->getMessage()
    ]);
}
