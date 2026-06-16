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
$full_name = trim($_POST['full_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$profile_image_url = trim($_POST['profile_image_url'] ?? '');

if ($user_id <= 0) { echo json_encode(['status'=>'error','message'=>'Missing user id']); exit; }
if ($full_name === '' || $phone === '' || $email === '') { echo json_encode(['status'=>'error','message'=>'Full name, phone and email are required']); exit; }

// prevent duplicate email for other users
$dupSql = "SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1";
$dupStmt = mysqli_prepare($conn, $dupSql);
if ($dupStmt) {
    mysqli_stmt_bind_param($dupStmt, 'si', $email, $user_id);
    mysqli_stmt_execute($dupStmt);
    $dupRes = mysqli_stmt_get_result($dupStmt);
    if ($dupRes && mysqli_num_rows($dupRes) > 0) { mysqli_stmt_close($dupStmt); echo json_encode(['status'=>'error','message'=>'Email already in use']); exit; }
    mysqli_stmt_close($dupStmt);
}

$sql = "UPDATE users SET full_name = ?, phone_number = ?, email = ?, profile_image_url = ? WHERE id = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) { echo json_encode(['status'=>'error','message'=>'Server error']); exit; }
mysqli_stmt_bind_param($stmt, 'ssssi', $full_name, $phone, $email, $profile_image_url, $user_id);
if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); echo json_encode(['status'=>'error','message'=>'Failed to update profile']); exit; }
mysqli_stmt_close($stmt);

echo json_encode(['status'=>'success','message'=>'Profile updated']);
mysqli_close($conn);
?>
