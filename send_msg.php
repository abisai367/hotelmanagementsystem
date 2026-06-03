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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sender_id = $_POST['sender_id'] ?? '';
    $receiver_id = $_POST['receiver_id'] ?? '';
    $encrypted_text = $_POST['encrypted_text'] ?? '';
    $iv_params = $_POST['iv_params'] ?? '';

    if (empty($sender_id) || empty($receiver_id) || empty($encrypted_text) || empty($iv_params)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing encrypted payload components.']);
        exit;
    }

    $sql = "INSERT INTO messages (sender_id, receiver_id, encrypted_text, iv_params) VALUES (?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iiss", $sender_id, $receiver_id, $encrypted_text, $iv_params);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['status' => 'success', 'message' => 'Encrypted ciphertext archived.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
    }
    mysqli_stmt_close($stmt);
}
mysqli_close($conn);
?>
