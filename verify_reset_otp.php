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
    error_log('verify_reset_otp: missing DB connection');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection unavailable']);
    exit;
}

$email = trim($_POST['email'] ?? '');
$otp_code = trim($_POST['otp_code'] ?? '');
$new_password = $_POST['new_password'] ?? '';

if ($email === '' || $otp_code === '') {
    echo json_encode(['status' => 'error', 'message' => 'Email and OTP code are required.']);
    exit;
}

$isPasswordUpdate = !empty($new_password) && $new_password !== 'verify_only';
if ($isPasswordUpdate && strlen($new_password) < 6) {
    echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters.']);
    exit;
}

$lowerEmail = strtolower($email);
$sql = "SELECT pr.id, pr.user_id, pr.expires_at, pr.used FROM password_resets pr WHERE pr.email = ? AND pr.otp_code = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    error_log('verify_reset_otp prepare failed: ' . mysqli_error($conn));
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    exit;
}
mysqli_stmt_bind_param($stmt, 'ss', $lowerEmail, $otp_code);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
if (!$result || mysqli_num_rows($result) === 0) {
    mysqli_stmt_close($stmt);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or OTP code.']);
    exit;
}
$resetRow = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if ((int)$resetRow['used'] === 1) {
    echo json_encode(['status' => 'error', 'message' => 'This OTP has already been used.']);
    exit;
}
if (strtotime($resetRow['expires_at']) < time()) {
    echo json_encode(['status' => 'error', 'message' => 'This OTP has expired.']);
    exit;
}

$hashedPassword = password_hash($new_password, PASSWORD_BCRYPT);
$updateSql = "UPDATE users SET password = ? WHERE id = ? LIMIT 1";
$updateStmt = mysqli_prepare($conn, $updateSql);
if (!$updateStmt) {
    error_log('verify_reset_otp update prepare failed: ' . mysqli_error($conn));
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    exit;
}
mysqli_stmt_bind_param($updateStmt, 'si', $hashedPassword, $resetRow['user_id']);
if (!mysqli_stmt_execute($updateStmt)) {
    error_log('verify_reset_otp update execute failed: ' . mysqli_error($conn));
    mysqli_stmt_close($updateStmt);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    exit;
}
mysqli_stmt_close($updateStmt);

$markSql = "UPDATE password_resets SET used = 1 WHERE id = ? LIMIT 1";
$markStmt = mysqli_prepare($conn, $markSql);
if ($markStmt) {
    mysqli_stmt_bind_param($markStmt, 'i', $resetRow['id']);
    mysqli_stmt_execute($markStmt);
    mysqli_stmt_close($markStmt);
}

echo json_encode(['status' => 'success', 'message' => 'Password updated successfully. Please log in with your new password.']);

mysqli_close($conn);
?>