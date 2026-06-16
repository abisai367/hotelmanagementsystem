<?php
function loadHotelEnv(): void {
    $root = __DIR__ . '/../';
    $files = ['.env.production', '.env.local', '.env'];

    foreach ($files as $file) {
        $path = $root . $file;
        if (!is_readable($path)) {
            continue;
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
}

function normalizeMpesaPhone(string $phone): string {
    $phone = preg_replace('/\D+/', '', $phone);
    if (str_starts_with($phone, '0')) {
        return '254' . substr($phone, 1);
    }
    if (!str_starts_with($phone, '254')) {
        return '254' . $phone;
    }
    return $phone;
}

function sendHotelStkPush(string $phone, float $amount, string $accountReference, string $description): array {
    loadHotelEnv();

    $consumerKey = $_ENV['MPESA_CONSUMER_KEY'] ?? getenv('MPESA_CONSUMER_KEY');
    $consumerSecret = $_ENV['MPESA_CONSUMER_SECRET'] ?? getenv('MPESA_CONSUMER_SECRET');
    $businessCode = $_ENV['MPESA_BUSINESS_CODE'] ?? getenv('MPESA_BUSINESS_CODE');
    $passkey = $_ENV['MPESA_PASSKEY'] ?? getenv('MPESA_PASSKEY');
    $callbackUrl = $_ENV['MPESA_CALLBACK_URL'] ?? getenv('MPESA_CALLBACK_URL');

    if (!$consumerKey || !$consumerSecret || !$businessCode || !$passkey || !$callbackUrl) {
        return ['status' => 'error', 'message' => 'M-Pesa credentials are not configured.'];
    }

    $phone = normalizeMpesaPhone($phone);
    if (!preg_match('/^254\d{9}$/', $phone)) {
        return ['status' => 'error', 'message' => 'Payment number must be 254XXXXXXXXX.'];
    }

    if ($amount < 1) {
        return ['status' => 'error', 'message' => 'Amount must be at least 1 KES.'];
    }

    $allowInsecure = (isset($_ENV['MPESA_ALLOW_INSECURE']) ? $_ENV['MPESA_ALLOW_INSECURE'] : (getenv('MPESA_ALLOW_INSECURE') ?: 'false')) === 'true';
    $verifyPeer = !$allowInsecure;

    $credentials = base64_encode($consumerKey . ':' . $consumerSecret);
    $tokenCurl = curl_init('https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt($tokenCurl, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
    curl_setopt($tokenCurl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($tokenCurl, CURLOPT_SSL_VERIFYPEER, $verifyPeer);
    curl_setopt($tokenCurl, CURLOPT_TIMEOUT, 30);

    $tokenResponse = curl_exec($tokenCurl);
    $tokenCode = curl_getinfo($tokenCurl, CURLINFO_HTTP_CODE);
    $tokenError = curl_error($tokenCurl);
    curl_close($tokenCurl);

    if ($tokenCode !== 200) {
        return ['status' => 'error', 'message' => 'Unable to get M-Pesa access token.', 'detail' => $tokenError ?: $tokenResponse];
    }

    $tokenData = json_decode($tokenResponse, true);
    if (!isset($tokenData['access_token'])) {
        return ['status' => 'error', 'message' => 'M-Pesa token response was invalid.'];
    }

    $timestamp = date('YmdHis');
    $password = base64_encode($businessCode . $passkey . $timestamp);
    $payload = [
        'BusinessShortCode' => $businessCode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => intval(round($amount)),
        'PartyA' => $phone,
        'PartyB' => $businessCode,
        'PhoneNumber' => $phone,
        'CallBackURL' => $callbackUrl,
        'AccountReference' => substr($accountReference, 0, 12),
        'TransactionDesc' => substr($description, 0, 40),
    ];

    $stkCurl = curl_init('https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
    curl_setopt($stkCurl, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $tokenData['access_token'],
    ]);
    curl_setopt($stkCurl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($stkCurl, CURLOPT_POST, true);
    curl_setopt($stkCurl, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($stkCurl, CURLOPT_SSL_VERIFYPEER, $verifyPeer);
    curl_setopt($stkCurl, CURLOPT_TIMEOUT, 30);

    $stkResponse = curl_exec($stkCurl);
    $stkCode = curl_getinfo($stkCurl, CURLINFO_HTTP_CODE);
    $stkError = curl_error($stkCurl);
    curl_close($stkCurl);

    $stkData = json_decode($stkResponse, true);
    if ($stkCode === 200 && isset($stkData['CheckoutRequestID'])) {
        return [
            'status' => 'success',
            'message' => 'STK Push sent successfully',
            'checkoutRequestID' => $stkData['CheckoutRequestID'],
            'merchantRequestID' => $stkData['MerchantRequestID'] ?? null,
            'phone' => $phone,
            'raw' => $stkData,
        ];
    }

    $failureMessage = $stkData['errorMessage'] ?? $stkData['ResponseDescription'] ?? $stkError ?? 'Unknown error';

    return [
        'status' => 'error',
        'message' => 'STK Push failed: ' . $failureMessage,
        'raw' => $stkData ?: $stkResponse,
    ];
}

/**
 * Send SMS notification to employees for salary payment
 * Supports Africa's Talking SMS API
 */
function sendSalaryPaymentSMS(string $phoneNumber, float $amount, string $employeeName = ''): array {
    loadHotelEnv();

    $smsApiKey = $_ENV['SMS_API_KEY'] ?? getenv('SMS_API_KEY');
    $smsUsername = $_ENV['SMS_USERNAME'] ?? getenv('SMS_USERNAME') ?? 'sandbox';
    
    // If no SMS credentials configured, return success (don't fail the payment process)
    if (!$smsApiKey) {
        error_log('SMS_API_KEY not configured - skipping SMS notification');
        return ['status' => 'success', 'message' => 'SMS credentials not configured'];
    }

    // Normalize phone number
    $phone = normalizeMpesaPhone($phoneNumber);
    if (!preg_match('/^254\d{9}$/', $phone)) {
        return ['status' => 'error', 'message' => 'Invalid phone number format'];
    }

    $formattedAmount = number_format($amount, 2);
    $message = "Congratulations! Your salary of KES {$formattedAmount} has been paid. Please check your M-Pesa account. Thank you.";
    if (!empty($employeeName)) {
        $message = "Hi {$employeeName}, " . $message;
    }

    $allowInsecure = (isset($_ENV['SMS_ALLOW_INSECURE']) ? $_ENV['SMS_ALLOW_INSECURE'] : (getenv('SMS_ALLOW_INSECURE') ?: 'false')) === 'true';
    $verifyPeer = !$allowInsecure;

    $smsUrl = 'https://api.sandbox.africastalking.com/version1/messaging';
    if ($_ENV['SMS_PRODUCTION'] ?? getenv('SMS_PRODUCTION') === 'true') {
        $smsUrl = 'https://api.africastalking.com/version1/messaging';
    }

    $postData = [
        'username' => $smsUsername,
        'message' => $message,
        'recipients' => $phone,
    ];

    $ch = curl_init($smsUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
        'apiKey: ' . $smsApiKey,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyPeer);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 || $httpCode === 201) {
        $data = json_decode($response, true);
        error_log("SMS sent to {$phone}: " . json_encode($data));
        return [
            'status' => 'success',
            'message' => 'SMS sent successfully',
            'phone' => $phone,
        ];
    }

    error_log("SMS sending failed for {$phone}: HTTP {$httpCode}, Error: {$error}, Response: {$response}");
    return [
        'status' => 'error',
        'message' => 'Failed to send SMS: ' . ($error ?: $response),
        'httpCode' => $httpCode,
    ];
}

/**
 * Send salary payment SMS to all employees in a batch
 */
function sendPaymentSMSToEmployees(mysqli $conn, int $batchId): int {
    $stmt = $conn->prepare("
        SELECT u.id, u.full_name, u.phone_number, sp.salary_amount
        FROM salary_payments sp
        JOIN users u ON u.id = sp.employee_id
        WHERE sp.batch_id = ? AND sp.payment_status = 'Paid'
        ORDER BY u.full_name ASC
    ");
    
    if (!$stmt) {
        error_log('sendPaymentSMSToEmployees: prepare failed - ' . $conn->error);
        return 0;
    }

    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $result = $stmt->get_result();

    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $sendResult = sendSalaryPaymentSMS(
            $row['phone_number'],
            floatval($row['salary_amount']),
            $row['full_name']
        );
        
        if ($sendResult['status'] === 'success') {
            $count++;
            error_log("Payment SMS sent to {$row['full_name']} ({$row['phone_number']})");
        } else {
            error_log("Failed to send SMS to {$row['full_name']}: " . $sendResult['message']);
        }
        
        // Small delay to avoid rate limiting
        usleep(100000); // 0.1 second
    }

    $stmt->close();
    return $count;
}
?>
