<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$data = json_decode(file_get_contents("php://input"), true);

$orderId = $data['orderId'];
$amount = $data['amount'];
$phone = $data['phoneNumber']; 

$consumerKey = $_ENV['MPESA_CONSUMER_KEY'];
$consumerSecret = $_ENV['MPESA_CONSUMER_SECRET'];
$shortCode = $_ENV['MPESA_SHORTCODE'];
$passkey = $_ENV['MPESA_PASSKEY'];
$callbackUrl = "https://railway.app";

$url = 'https://safaricom.co.ke';
$credentials = base64_encode($consumerKey . ':' . $consumerSecret);

$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $url);
curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_HEADER, false);
$response = curl_exec($curl);
$token = json_decode($response)->access_token;

$stkUrl = 'https://safaricom.co.ke';
$timestamp = date('YmdHis');
$password = base64_encode($shortCode . $passkey . $timestamp);

$curl_post_data = [
    'BusinessShortCode' => $shortCode,
    'Password' => $password,
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerPayBillOnline',
    'Amount' => $amount,
    'PartyA' => $phone,
    'PartyB' => $shortCode,
    'PhoneNumber' => $phone,
    'CallBackURL' => $callbackUrl,
    'AccountReference' => 'Order ' . $orderId,
    'TransactionDesc' => 'Payment for order'
];

$data_string = json_encode($curl_post_data);

curl_init();
curl_setopt($curl, CURLOPT_URL, $stkUrl);
curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type:application/json', 'Authorization:Bearer ' . $token]);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
$stk_response = curl_exec($curl);

$resData = json_decode($stk_response);

if (isset($resData->CheckoutRequestID)) {
    $stmt = $conn->prepare("UPDATE orders SET checkout_request_id = ? WHERE order_id = ?");
    $stmt->bind_param("si", $resData->CheckoutRequestID, $orderId);
    $stmt->execute();
    
    echo json_encode(["message" => "STK Push sent successfully."]);
} else {
    echo json_encode(["message" => "Failed to initiate STK Push."]);
}
