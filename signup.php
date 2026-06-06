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
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? ''); 
    $password = $_POST['password'] ?? '';
    $defaultProfile = 'https://res.cloudinary.com/dmae5wpe9/image/upload/v1780127792/esi53lgjgdwvr9jcbno4.png';

    $rawProfile = trim($_POST['profile_image_url'] ?? '');
    $profile_image_url = $rawProfile !== '' ? $rawProfile : $defaultProfile;

    if ($full_name === '' || $phone === '' || $password === '') {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
        exit;
    }

    $chksql = "SELECT id FROM users WHERE phone_number = ? LIMIT 1";
    $chkstmt = mysqli_prepare($conn, $chksql);
    if (!$chkstmt) {
        error_log("signup.php prepare check failed: " . mysqli_error($conn));
        echo json_encode(['status' => 'error', 'message' => 'Server error.']);
        exit;
    }
    mysqli_stmt_bind_param($chkstmt, "s", $phone);
    mysqli_stmt_execute($chkstmt);
    $result = mysqli_stmt_get_result($chkstmt);
    
    if ($result && mysqli_num_rows($result) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'An account with this phone number already exists.']);   
        mysqli_stmt_close($chkstmt);
        exit;
    }
    mysqli_stmt_close($chkstmt);

    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    $role = 'customer';
    $shift_schedule = '';

    $sql = "INSERT INTO users (full_name, phone_number, password, role, profile_image_url, shift_schedule) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log("signup.php insert prepare failed: " . mysqli_error($conn));
        echo json_encode(['status' => 'error', 'message' => 'Server error.']);
        exit;
    }
    mysqli_stmt_bind_param($stmt, "ssssss", $full_name, $phone, $password_hash, $role, $profile_image_url, $shift_schedule);

    if (mysqli_stmt_execute($stmt)) {
        $newId = mysqli_insert_id($conn);
        $user = [
            'id' => $newId,
            'full_name' => $full_name,
            'role' => $role,
            'profile_image_url' => $profile_image_url,
            'shift_schedule' => $shift_schedule,
        ];
        echo json_encode(['status' => 'success', 'message' => 'Account registered successfully.', 'user' => $user]);
    } else {
        error_log("signup.php insert execute failed: " . mysqli_error($conn));
        echo json_encode(['status' => 'error', 'message' => 'Server error.']);
    }
    mysqli_stmt_close($stmt);
}

mysqli_close($conn);
?>
