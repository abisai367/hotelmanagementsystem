<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

include 'database.php';
include_once 'hotel_helpers.php';

if (!isset($conn) || !$conn) { http_response_code(500); echo json_encode(['status'=>'error','message'=>'Database unavailable']); exit; }

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
if ($user_id <= 0) { echo json_encode(['status'=>'error','message'=>'Missing user id']); exit; }

$sql = "SELECT id, full_name, phone_number, email, profile_image_url, role, shift_schedule, salary FROM users WHERE id = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) { echo json_encode(['status'=>'error','message'=>'Server error']); exit; }
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
if (!$res || mysqli_num_rows($res) === 0) { echo json_encode(['status'=>'error','message'=>'User not found']); mysqli_stmt_close($stmt); exit; }
$user = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

echo json_encode(['status'=>'success','user'=>$user]);
mysqli_close($conn);
?>
