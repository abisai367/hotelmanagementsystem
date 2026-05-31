<?php
include "database.php"; 

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

$message_id = $_POST['message_id'] ?? '';
$receiver_id = $_POST['receiver_id'] ?? '';

if (empty($message_id) || empty($receiver_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Message ID and receiver ID required.']);
    exit;
}

$sql = "UPDATE messages SET is_read = 1 WHERE id = ? AND receiver_id = ?";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "ii", $message_id, $receiver_id);
$result = mysqli_stmt_execute($stmt);

if ($result) {
    echo json_encode(['status' => 'success', 'message' => 'Message marked as read']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to mark message as read']);
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
?>
