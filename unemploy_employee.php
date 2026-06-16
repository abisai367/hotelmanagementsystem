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
if (!isset($conn) || !$conn) { error_log('unemploy_employee: missing DB connection'); http_response_code(500); echo json_encode(['status'=>'error','message'=>'Database connection unavailable']); exit; }
ensureCoreSchema($conn);
normalizeExistingRoles($conn);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['status'=>'error','message'=>'Invalid JSON']); exit; }

$id = isset($input['id']) ? intval($input['id']) : 0;
if ($id <= 0) { echo json_encode(['status'=>'error','message'=>'Missing id']); exit; }

try {
    // Convert role to Customer (unemploy), with fallback for missing columns
    $hasShiftSchedule = columnExists($conn, 'users', 'shift_schedule');
    $hasSalary = columnExists($conn, 'users', 'salary');
    
    $updates = ["role = 'customer'"];
    if ($hasShiftSchedule) $updates[] = "shift_schedule = ''";
    if ($hasSalary) $updates[] = "salary = 0";
    
    $updateClause = implode(', ', $updates);
    $stmt = $conn->prepare("UPDATE users SET $updateClause WHERE id = ? LIMIT 1");
    if (!$stmt) { throw new Exception('Prepare failed: '.$conn->error); }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) { throw new Exception('Execute failed: '.$stmt->error); }
    rebalanceAssignments($conn, 'dineIn');
    rebalanceAssignments($conn, 'delivery');
    echo json_encode(['status'=>'success','message'=>'Employee unemployed successfully']);
} catch (Exception $e) {
    error_log('unemploy_employee error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Server error']);
}

mysqli_close($conn);
?>
