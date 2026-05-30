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
    $full_name = $_POST['full_name'] ?? '';
    $phone = $_POST['phone'] ?? ''; 
    $password = $_POST['password'] ?? '';
    $profile_image_url = $_POST['profile_image_url'] ?? '';

    if (empty($full_name) || empty($phone) || empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
        exit;
    }

    $chksql = "SELECT id FROM users WHERE phone_number = ?";
    $chkstmt = mysqli_prepare($conn, $chksql);
    mysqli_stmt_bind_param($chkstmt, "s", $phone);
    mysqli_stmt_execute($chkstmt);
    $result = mysqli_stmt_get_result($chkstmt);
    
    if (mysqli_num_rows($result) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'An account with this phone number already exists.']);   
        exit;
    }
    mysqli_stmt_close($chkstmt);

    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    $role = 'customer';
    $shift_schedule = null;

    $sql = "INSERT INTO users (full_name, phone_number, password, role, profile_image_url, shift_schedule) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssssss", $full_name, $phone, $password_hash, $role, $profile_image_url, $shift_schedule);

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['status' => 'success', 'message' => 'Account registered successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
    }
    mysqli_stmt_close($stmt);
}

mysqli_close($conn);
?>
