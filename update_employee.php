<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
$conn = null;
include 'database.php';
include_once 'hotel_helpers.php';
/** @var mysqli $conn */
if (!isset($conn) || !$conn) { error_log('update_employee: missing DB connection'); http_response_code(500); echo json_encode(['status'=>'error','message'=>'Database connection unavailable']); exit; }
ensureCoreSchema($conn);
normalizeExistingRoles($conn);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['status'=>'error','message'=>'Invalid JSON']); exit; }

$id = isset($input['id']) ? intval($input['id']) : 0;
if ($id <= 0) { echo json_encode(['status'=>'error','message'=>'Missing id']); exit; }

$fields = [];
$types = '';
$values = [];
if (isset($input['full_name']) && columnExists($conn, 'users', 'full_name')) { $fields[]='full_name=?'; $types.='s'; $values[]=$input['full_name']; }

$phoneValue = isset($input['phone']) ? $input['phone'] : null;
$phoneColumns = [];
if ($phoneValue !== null) {
    if (columnExists($conn, 'users', 'phone')) { $phoneColumns[] = 'phone'; }
    if (columnExists($conn, 'users', 'phone_number')) { $phoneColumns[] = 'phone_number'; }
    foreach ($phoneColumns as $column) {
        $fields[] = "$column=?";
        $types .= 's';
        $values[] = $phoneValue;
    }
}

if (isset($input['email']) && columnExists($conn, 'users', 'email')) {
    $emailValue = trim($input['email']);
    if ($emailValue !== '') {
        $dupStmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        if ($dupStmt) {
            $dupStmt->bind_param('si', $emailValue, $id);
            $dupStmt->execute();
            $dupResult = $dupStmt->get_result();
            if ($dupResult && $dupResult->num_rows > 0) {
                echo json_encode(['status'=>'error','message'=>'Email already belongs to another account']);
                $dupStmt->close();
                mysqli_close($conn);
                exit;
            }
            $dupStmt->close();
        }
        $fields[] = 'email=?';
        $types .= 's';
        $values[] = $emailValue;
    }
}

if (isset($input['role']) && columnExists($conn, 'users', 'role')) {
    $role = normalizeHotelRole($input['role']);
    if (!in_array($role, hotelStaffRoles(), true)) {
        echo json_encode(['status'=>'error','message'=>'Invalid staff role']);
        exit;
    }
    $fields[]='role=?'; $types.='s'; $values[]=$role;
}
if (isset($input['shift_schedule']) && columnExists($conn, 'users', 'shift_schedule')) { $fields[]='shift_schedule=?'; $types.='s'; $values[]=$input['shift_schedule']; }
if (isset($input['profile_image_url']) && columnExists($conn, 'users', 'profile_image_url')) { $fields[]='profile_image_url=?'; $types.='s'; $values[]=$input['profile_image_url']; }
if (isset($input['salary'])) {
    if (columnExists($conn, 'users', 'salary')) {
        $fields[]='salary=?'; $types.='d'; $values[]=$input['salary'];
    }
}

try {
    if (count($fields) === 0) { echo json_encode(['status'=>'error','message'=>'No fields to update']); exit; }

    $sql = 'UPDATE users SET '.implode(', ', $fields).' WHERE id = ? LIMIT 1';
    $types .= 'i'; $values[] = $id;

    $stmt = $conn->prepare($sql);
    if (!$stmt) { throw new Exception('Prepare failed: '.$conn->error); }
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) { throw new Exception('Execute failed: '.$stmt->error); }

    echo json_encode(['status'=>'success','message'=>'Employee updated']);
} catch (Exception $e) {
    error_log('update_employee error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Server error']);
}

mysqli_close($conn);
?>
