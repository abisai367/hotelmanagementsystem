<?php
include "database.php"; 
include_once "hotel_helpers.php";

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureCoreSchema($conn);
    normalizeExistingRoles($conn);

    $phone = $_POST['phone'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($phone) || empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'Both phone number and password fields are required.']);
        exit;
    }

    $sql = "SELECT id, full_name, password, role, profile_image_url, shift_schedule, salary FROM users WHERE phone_number = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Database query preparation failed.']);
        exit;
    }

    mysqli_stmt_bind_param($stmt, "s", $phone);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($result)) {
        if (password_verify($password, $row['password'])) {
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Authentication successful.',
                'user' => [
                    'id' => $row['id'],
                    'full_name' => $row['full_name'],
                    'role' => normalizeHotelRole($row['role']),
                    'profile_image_url' => $row['profile_image_url'],
                    'shift_schedule' => $row['shift_schedule'],
                    'salary' => $row['salary'] ?? 0
                ]
            ]);
            
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid phone number or password.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid phone number or password.']);
    }

    mysqli_stmt_close($stmt);
}

mysqli_close($conn);
?>
