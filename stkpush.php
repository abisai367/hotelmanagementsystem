<?php
include 'database.php';

header("Content-Type: application/json");

// CORS (keep if frontend is separate domain)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Check DB
if (!$conn) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

// Get input
$raw = file_get_contents("php://input");
$payload = json_decode($raw, true) ?? $_POST;

$phone  = $payload['phone'] ?? '';
$amount = $payload['amount'] ?? '';
$items  = $payload['items'] ?? [];

// Validation
if (empty($phone) || empty($amount)) {
    echo json_encode(["status" => "error", "message" => "Phone and amount required"]);
    exit;
}

// Clean phone number
$phone = preg_replace('/[^0-9]/', '', $phone);

if (strlen($phone) == 10 && $phone[0] == '0') {
    $phone = '254' . substr($phone, 1);
} elseif (strlen($phone) == 9) {
    $phone = '254' . $phone;
}

if (strlen($phone) != 12) {
    echo json_encode(["status" => "error", "message" => "Invalid phone format"]);
    exit;
}

// Order ID
$order_id = 'ORD' . date('YmdHis') . rand(100, 999);

// Save order first
$items_json = json_encode($items);
$status = "initiated";

$stmt = $conn->prepare("
    INSERT INTO orders (order_id, phone, amount, items, status, created_at)
    VALUES (?, ?, ?, ?, ?, NOW())
");
$stmt->bind_param("sdsss", $order_id, $amount, $phone, $items_json, $status);
if (!$stmt->execute()) {
    echo json_encode(["status" => "error", "message" => "Failed to save order"]);
    exit;
}
$stmt->close();

/* =========================
   MPESA CREDENTIALS
========================= */
// Get from M-Pesa Developer Console (https://developer.safaricom.co.ke)
$consumerKey    = getenv('MPESA_CONSUMER_KEY') ?: "YOUR_CONSUMER_KEY_HERE";
$consumerSecret = getenv('MPESA_CONSUMER_SECRET') ?: "YOUR_CONSUMER_SECRET_HERE";
$BusinessShortCode = getenv('MPESA_BUSINESS_CODE') ?: "174379"; // Test: 174379
$Passkey = getenv('MPESA_PASSKEY') ?: "YOUR_PASSKEY_HERE";

/* IMPORTANT: MUST BE PUBLIC HTTPS URL - Test env or production */
$callbackUrl = getenv('MPESA_CALLBACK_URL') ?: "https://fivestarhotel.rf.gd/api/callback.php";

// Validate credentials are set
if (strpos($consumerKey, 'YOUR_') !== false || empty($consumerKey)) {
    echo json_encode(["status" => "error", "message" => "M-Pesa credentials not configured. Contact admin."]);
    exit;
}

/* =========================
   GET ACCESS TOKEN
========================= */
$authUrl = "https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials";

$ch = curl_init($authUrl);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, $consumerKey . ":" . $consumerSecret);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$result = curl_exec($ch);

if (!$result) {
    echo json_encode(["status" => "error", "message" => curl_error($ch)]);
    exit;
}

$json = json_decode($result);
curl_close($ch);

if (!isset($json->access_token)) {
    echo json_encode([
        "status" => "error",
        "message" => "Failed to get M-Pesa access token. Check credentials.",
        "debug" => $json
    ]);
    exit;
}

$accessToken = $json->access_token;

/* =========================
   STK PUSH REQUEST
========================= */
$timestamp = date("YmdHis");
$password = base64_encode($BusinessShortCode . $Passkey . $timestamp);

$url = "https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest";

$data = [
    "BusinessShortCode" => $BusinessShortCode,
    "Password" => $password,
    "Timestamp" => $timestamp,
    "TransactionType" => "CustomerPayBillOnline",
    "Amount" => (string)$amount,
    "PartyA" => $phone,
    "PartyB" => $BusinessShortCode,
    "PhoneNumber" => $phone,
    "CallBackURL" => $callbackUrl,
    "AccountReference" => $order_id,
    "TransactionDesc" => "Order Payment"
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $accessToken
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (!$response) {
    echo json_encode(["status" => "error", "message" => "STK push request failed: " . curl_error($ch)]);
    exit;
}

// Log response for debugging
file_put_contents("stk_debug.json", "[" . date('Y-m-d H:i:s') . "] HTTP $httpCode\n" . $response . "\n\n", FILE_APPEND);

$resp = json_decode($response);
curl_close($ch);

/* =========================
   RESPONSE HANDLING
========================= */
if (isset($resp->ResponseCode) && $resp->ResponseCode == "0") {

    $checkoutRequestID = $resp->CheckoutRequestID;

    $stmt = $conn->prepare("
        INSERT INTO payments (payment_ref, order_id, phone, amount, payment_status, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $payment_status = "pending";
    $stmt->bind_param("sssss", $checkoutRequestID, $order_id, $phone, $amount, $payment_status);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        "status" => "success",
        "message" => "STK Push sent",
        "checkoutRequestID" => $checkoutRequestID
    ]);

} else {
    $errorMsg = $resp->errorMessage ?? $resp->ResponseDescription ?? "Unknown error";
    echo json_encode([
        "status" => "failed",
        "message" => "STK Push failed: $errorMsg",
        "errorCode" => $resp->errorCode ?? $resp->ResponseCode ?? null
    ]);
}

$conn->close();
?>