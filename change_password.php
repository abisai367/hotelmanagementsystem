<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

include 'database.php';
include_once 'hotel_helpers.php';

if (!isset($conn) || !$conn) { http_response_code(500); echo json_encode(['status'=>'error','message'=>'Database unavailable']); exit; }

$user_id = intval($_POST['user_id'] ?? 0);
$old_password = $_POST['old_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';

if ($user_id <= 0 || $old_password === '' || $new_password === '') { echo json_encode(['status'=>'error','message'=>'Missing parameters']); exit; }

$sql = "SELECT password FROM users WHERE id = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) { echo json_encode(['status'=>'error','message'=>'Server error']); exit; }
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
if (!$res || mysqli_num_rows($res) === 0) { mysqli_stmt_close($stmt); echo json_encode(['status'=>'error','message'=>'User not found']); exit; }
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!password_verify($old_password, $row['password'])) {
    echo json_encode(['status'=>'error','message'=>'Current password is incorrect']);
    exit;
}

$hash = password_hash($new_password, PASSWORD_BCRYPT);
$updateSql = "UPDATE users SET password = ? WHERE id = ? LIMIT 1";
$updateStmt = mysqli_prepare($conn, $updateSql);
if (!$updateStmt) { echo json_encode(['status'=>'error','message'=>'Server error']); exit; }
mysqli_stmt_bind_param($updateStmt, 'si', $hash, $user_id);
if (!mysqli_stmt_execute($updateStmt)) { mysqli_stmt_close($updateStmt); echo json_encode(['status'=>'error','message'=>'Failed to update password']); exit; }
mysqli_stmt_close($updateStmt);

echo json_encode(['status'=>'success','message'=>'Password updated']);
mysqli_close($conn);
?>
