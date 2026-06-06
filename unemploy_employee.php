<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
$conn = null;
include 'database.php';
/** @var mysqli $conn */
if (!isset($conn) || !$conn) { error_log('unemploy_employee: missing DB connection'); http_response_code(500); echo json_encode(['status'=>'error','message'=>'Database connection unavailable']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['status'=>'error','message'=>'Invalid JSON']); exit; }

$id = isset($input['id']) ? intval($input['id']) : 0;
if ($id <= 0) { echo json_encode(['status'=>'error','message'=>'Missing id']); exit; }

function columnExists($table, $column) {
    global $conn;
    $res = mysqli_query($conn, "SHOW COLUMNS FROM " . mysqli_real_escape_string($conn, $table) . " LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
    return $res && mysqli_num_rows($res) > 0;
}

try {
    // Convert role to Customer (unemploy), with fallback for missing columns
    $hasShiftSchedule = columnExists('users', 'shift_schedule');
    $hasSalary = columnExists('users', 'salary');
    
    $updates = ["role = 'Customer'"];
    if ($hasShiftSchedule) $updates[] = "shift_schedule = ''";
    if ($hasSalary) $updates[] = "salary = NULL";
    
    $updateClause = implode(', ', $updates);
    $stmt = $conn->prepare("UPDATE users SET $updateClause WHERE id = ? LIMIT 1");
    if (!$stmt) { throw new Exception('Prepare failed: '.$conn->error); }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) { throw new Exception('Execute failed: '.$stmt->error); }
    echo json_encode(['status'=>'success','message'=>'Employee unemployed successfully']);
} catch (Exception $e) {
    error_log('unemploy_employee error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Server error']);
}

mysqli_close($conn);
?>