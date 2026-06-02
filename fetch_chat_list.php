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

$user_id = $_GET['user_id'] ?? '';

if (empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'User ID missing.']);
    exit;
}

$sql = "SELECT DISTINCT u.id, u.phone_number, u.profile_image_url,
        COALESCE((SELECT saved_name FROM contacts WHERE user_id = ? AND contact_user_id = u.id LIMIT 1), u.phone_number) AS display_name,
        COALESCE((SELECT encrypted_text FROM messages WHERE (sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?) ORDER BY id DESC LIMIT 1), '') AS last_message,
        COALESCE((SELECT iv_params FROM messages WHERE (sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?) ORDER BY id DESC LIMIT 1), '') AS last_iv,
        (SELECT created_at FROM messages WHERE (sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?) ORDER BY id DESC LIMIT 1) AS last_time,
        COALESCE((SELECT sender_id FROM messages WHERE (sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?) ORDER BY id DESC LIMIT 1), NULL) AS last_sender_id,
        COALESCE((SELECT COUNT(*) FROM messages WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0), 0) AS unread_count,
        COALESCE((SELECT 1 FROM contacts WHERE user_id = ? AND contact_user_id = u.id LIMIT 1), 0) AS is_saved
        FROM users u
        WHERE u.id IN (
            SELECT contact_user_id FROM contacts WHERE user_id = ?
            UNION
            SELECT DISTINCT receiver_id FROM messages WHERE sender_id = ?
            UNION
            SELECT DISTINCT sender_id FROM messages WHERE receiver_id = ?
        ) AND u.id != ?
        ORDER BY last_time DESC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "iiiiiiiiiiiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$list = [];
while ($row = mysqli_fetch_assoc($result)) {
    $list[] = $row;
}

echo json_encode(['status' => 'success', 'chats' => $list]);
mysqli_stmt_close($stmt);
mysqli_close($conn);
?>
