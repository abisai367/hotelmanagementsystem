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
/** @var mysqli $conn */
if (!isset($conn) || !$conn) { error_log('add_employee: missing DB connection'); http_response_code(500); echo json_encode(['status'=>'error','message'=>'Database connection unavailable']); exit; }

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
    
    $full_name = $input['full_name'] ?? '';
    $phone = $input['phone'] ?? '';
    $role = $input['role'] ?? 'Employee';
    $shift_schedule = $input['shift_schedule'] ?? '';
    $profile_image_url = $input['profile_image_url'] ?? '';
    $password = $input['password'] ?? bin2hex(random_bytes(6)); // Default password if not provided

    if (empty($full_name) || empty($phone)) {
        echo json_encode(['status' => 'error', 'message' => 'Name and phone are required']);
        exit;
    }

    if ($role === 'customer' || $role === 'Customer') {
        echo json_encode(['status' => 'error', 'message' => 'Cannot add a customer role through this endpoint']);
        exit;
    }

    $chksql = "SELECT id FROM users WHERE phone = ?";
    $chkstmt = mysqli_prepare($conn, $chksql);
    mysqli_stmt_bind_param($chkstmt, "s", $phone);
    mysqli_stmt_execute($chkstmt);
    $result = mysqli_stmt_get_result($chkstmt);
    
    if (mysqli_num_rows($result) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Phone number already exists']);   
        exit;
    }
    mysqli_stmt_close($chkstmt);

    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    $sql = "INSERT INTO users (full_name, phone, password_hash, role, profile_image_url, shift_schedule) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!$stmt) {
        error_log('add_employee prepare error: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server error']);
        exit;
    }
    
    mysqli_stmt_bind_param($stmt, "ssssss", $full_name, $phone, $password_hash, $role, $profile_image_url, $shift_schedule);

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

