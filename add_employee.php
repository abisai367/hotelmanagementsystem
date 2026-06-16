<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

$conn = null;
include 'database.php';
include_once 'hotel_helpers.php';
/** @var mysqli $conn */
if (!isset($conn) || !$conn) { error_log('add_employee: missing DB connection'); http_response_code(500); echo json_encode(['status'=>'error','message'=>'Database connection unavailable']); exit; }
ensureCoreSchema($conn);
normalizeExistingRoles($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Accept both JSON and form POST
    $input = null;
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($content_type, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
    } else {
        $input = $_POST;
    }
    
    if (!$input) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
        exit;
    }
    
    $full_name = trim($input['full_name'] ?? '');
    $phone = trim($input['phone'] ?? $input['phone_number'] ?? '');
    $email = trim($input['email'] ?? '');
    $role = normalizeHotelRole($input['role'] ?? 'waiter');
    $shift_schedule = trim($input['shift_schedule'] ?? '');
    $profile_image_url = trim($input['profile_image_url'] ?? '');
    $salary = isset($input['salary']) && $input['salary'] !== '' ? floatval($input['salary']) : null;
    $password = trim($input['password'] ?? bin2hex(random_bytes(6)));

    if ($full_name === '' || $phone === '' || $email === '') {
        echo json_encode(['status' => 'error', 'message' => 'Name, phone and email are required']);
        exit;
    }

    $role = strtolower($role) === 'customer' ? 'waiter' : $role;
    if (!in_array($role, hotelStaffRoles(), true)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid staff role']);
        exit;
    }

    $chksql = "SELECT id FROM users WHERE phone_number = ? OR email = ? LIMIT 1";
    $chkstmt = mysqli_prepare($conn, $chksql);
    if (!$chkstmt) {
        error_log('add_employee check prepare failed: ' . mysqli_error($conn));
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server error']);
        exit;
    }
    mysqli_stmt_bind_param($chkstmt, "ss", $phone, $email);
    mysqli_stmt_execute($chkstmt);
    $result = mysqli_stmt_get_result($chkstmt);
    if ($result && mysqli_num_rows($result) > 0) {
        mysqli_stmt_close($chkstmt);
        echo json_encode(['status' => 'error', 'message' => 'Phone number or email already exists']);
        exit;
    }
    mysqli_stmt_close($chkstmt);

    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    if (!is_null($salary)) {
        $sql = "INSERT INTO users (full_name, phone_number, email, password, role, profile_image_url, shift_schedule, salary) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            error_log('add_employee prepare error: ' . mysqli_error($conn));
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Server error']);
            exit;
        }
        mysqli_stmt_bind_param($stmt, "sssssssd", $full_name, $phone, $email, $password_hash, $role, $profile_image_url, $shift_schedule, $salary);
    } else {
        $sql = "INSERT INTO users (full_name, phone_number, email, password, role, profile_image_url, shift_schedule) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            error_log('add_employee prepare error: ' . mysqli_error($conn));
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Server error']);
            exit;
        }
        mysqli_stmt_bind_param($stmt, "sssssss", $full_name, $phone, $email, $password_hash, $role, $profile_image_url, $shift_schedule);
    }

    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['status' => 'success', 'message' => 'Employee added successfully']);
    } else {
        error_log('add_employee execute error: ' . $stmt->error);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to add employee']);
    }
    mysqli_stmt_close($stmt);
}

mysqli_close($conn);
?>
