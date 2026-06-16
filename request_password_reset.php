<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

include 'database.php';
include_once 'hotel_helpers.php';

if (!isset($conn) || !$conn) {
    error_log('request_password_reset: missing DB connection');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection unavailable']);
    exit;
}

$email = trim($_POST['email'] ?? '');
if ($email === '') {
    echo json_encode(['status' => 'error', 'message' => 'Email is required.']);
    exit;
}

$lowerEmail = strtolower($email);
$sql = "SELECT id, full_name FROM users WHERE LOWER(email) = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    error_log('request_password_reset prepare failed: ' . mysqli_error($conn));
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    exit;
}
mysqli_stmt_bind_param($stmt, 's', $lowerEmail);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
if (!$result || mysqli_num_rows($result) === 0) {
    echo json_encode(['status' => 'error', 'message' => 'No account found for that email address.']);
    mysqli_stmt_close($stmt);
    exit;
}
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

$otpCode = generateOtp(6);
$expiresAt = date('Y-m-d H:i:s', time() + 300);

$markSql = "UPDATE password_resets SET used = 1 WHERE email = ? AND used = 0";
$markStmt = mysqli_prepare($conn, $markSql);
if ($markStmt) {
    mysqli_stmt_bind_param($markStmt, 's', $lowerEmail);
    mysqli_stmt_execute($markStmt);
    mysqli_stmt_close($markStmt);
}

$insertSql = "INSERT INTO password_resets (user_id, email, otp_code, expires_at, used) VALUES (?, ?, ?, ?, 0)";
$insertStmt = mysqli_prepare($conn, $insertSql);
if (!$insertStmt) {
    error_log('request_password_reset insert prepare failed: ' . mysqli_error($conn));
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    exit;
}
mysqli_stmt_bind_param($insertStmt, 'isss', $user['id'], $lowerEmail, $otpCode, $expiresAt);
if (!mysqli_stmt_execute($insertStmt)) {
    error_log('request_password_reset insert execute failed: ' . mysqli_error($conn));
    mysqli_stmt_close($insertStmt);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    exit;
}
mysqli_stmt_close($insertStmt);

$subject = 'Your password reset OTP';
$htmlContent = "<p>Hi " . htmlspecialchars($user['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ",</p>" .
    "<p>Your password reset code is: <strong>{$otpCode}</strong></p>" .
    "<p>This code expires in 5 minutes.</p>" .
    "<p>If you did not request a password reset, please ignore this email.</p>";

$sendResult = sendBrevoEmail($lowerEmail, $subject, $htmlContent);
if (!$sendResult['success']) {
    error_log('request_password_reset Brevo error: ' . ($sendResult['message'] ?? json_encode($sendResult)));
    // If debug=1 is provided, return Brevo response for debugging (do NOT enable in production)
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        echo json_encode(['status' => 'error', 'message' => 'Unable to send reset email. Debug output included.', 'brevo' => $sendResult]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Unable to send reset email. Please try again later.']);
    }
    exit;
}

echo json_encode(['status' => 'success', 'message' => 'We have sent an OTP to your email address.']);

mysqli_close($conn);
?>