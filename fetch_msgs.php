<?php
include "database.php"; 

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

$user_1 = $_GET['user_id'] ?? '';
$user_2 = $_GET['recipient_id'] ?? '';

if (empty($user_1) || empty($user_2)) {
    echo json_encode(['status' => 'error', 'message' => 'Conversation scope keys required.']);
    exit;
}

$sql = "SELECT id, sender_id, receiver_id, encrypted_text, iv_params, created_at, is_read 
        FROM messages 
        WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) 
        ORDER BY id ASC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "iiii", $user_1, $user_2, $user_2, $user_1);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$history = [];
while ($row = mysqli_fetch_assoc($result)) {
    $history[] = $row;
}

echo json_encode(['status' => 'success', 'messages' => $history]);
mysqli_stmt_close($stmt);
mysqli_close($conn);
?>
