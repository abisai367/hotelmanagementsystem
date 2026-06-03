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
    $current_user_id = $_POST['user_id'] ?? '';
    $target_phone = $_POST['phone_number'] ?? '';
    $custom_name = $_POST['saved_name'] ?? '';

    if (empty($current_user_id) || empty($target_phone) || empty($custom_name)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing parameter definitions.']);
        exit;
    }
    
    $user_sql = "SELECT id, full_name, profile_image_url FROM users WHERE phone_number = ? LIMIT 1";
    $user_stmt = mysqli_prepare($conn, $user_sql);
    mysqli_stmt_bind_param($user_stmt, "s", $target_phone);
    mysqli_stmt_execute($user_stmt);
    $user_result = mysqli_stmt_get_result($user_stmt);

    if ($target_user = mysqli_fetch_assoc($user_result)) {
        $contact_user_id = $target_user['id'];

        if ($current_user_id == $contact_user_id) {
            echo json_encode(['status' => 'error', 'message' => 'You cannot add your own number.']);
            exit;
        }
        $contact_sql = "INSERT INTO contacts (user_id, contact_user_id, saved_name) VALUES (?, ?, ?) 
                        ON DUPLICATE KEY UPDATE saved_name = VALUES(saved_name)";
        $contact_stmt = mysqli_prepare($conn, $contact_sql);
        mysqli_stmt_bind_param($contact_stmt, "iis", $current_user_id, $contact_user_id, $custom_name);
        
        if (mysqli_stmt_execute($contact_stmt)) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Contact bound successfully.',
                'contact' => [
                    'contact_user_id' => $contact_user_id,
                    'saved_name' => $custom_name,
                    'profile_image_url' => $target_user['profile_image_url']
                ]
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
        }
        mysqli_stmt_close($contact_stmt);
    } else {
        
        echo json_encode(['status' => 'not_found', 'message' => 'The phone number you entered is not in Five Star Hotel (User not yet joined Five Star Hotel).']);
    }
    mysqli_stmt_close($user_stmt);
}
mysqli_close($conn);
?>
