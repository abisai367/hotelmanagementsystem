<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$conn = null;
include 'database.php';
include_once 'hotel_helpers.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

$assignmentId = isset($input['assignment_id']) ? intval($input['assignment_id']) : 0;
$toEmployeeId = isset($input['to_employee_id']) ? intval($input['to_employee_id']) : 0;
$supervisorId = isset($input['supervisor_id']) ? intval($input['supervisor_id']) : 0;

if ($assignmentId <= 0 || $toEmployeeId <= 0 || $supervisorId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing transfer details']);
    exit;
}

try {
    ensureCoreSchema($conn);
    normalizeExistingRoles($conn);

    $supStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $supStmt->bind_param('i', $supervisorId);
    $supStmt->execute();
    $sup = $supStmt->get_result()->fetch_assoc();
    $supStmt->close();
    $supervisorRole = normalizeHotelRole($sup['role'] ?? '');
    if (!in_array($supervisorRole, ['supervisor', 'manager', 'admin'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'Only a supervisor, manager, or admin can transfer work']);
        exit;
    }

    $assignmentStmt = $conn->prepare("SELECT work_type FROM work_assignments WHERE id = ? AND status = 'assigned' LIMIT 1");
    $assignmentStmt->bind_param('i', $assignmentId);
    $assignmentStmt->execute();
    $assignment = $assignmentStmt->get_result()->fetch_assoc();
    $assignmentStmt->close();

    if (!$assignment) {
        echo json_encode(['status' => 'error', 'message' => 'Active assignment not found']);
        exit;
    }

    $targetStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $targetStmt->bind_param('i', $toEmployeeId);
    $targetStmt->execute();
    $target = $targetStmt->get_result()->fetch_assoc();
    $targetStmt->close();
    $targetRole = normalizeHotelRole($target['role'] ?? '');
    $requiredRole = $assignment['work_type'] === 'delivery' ? 'delivery person' : 'waiter';

    if ($targetRole !== $requiredRole) {
        echo json_encode(['status' => 'error', 'message' => "This work can only be assigned to a {$requiredRole}"]);
        exit;
    }

    $update = $conn->prepare("UPDATE work_assignments SET employee_id = ?, assigned_by = 'supervisor', assigned_at = NOW() WHERE id = ?");
    $update->bind_param('ii', $toEmployeeId, $assignmentId);
    $update->execute();
    $update->close();

    echo json_encode(['status' => 'success', 'message' => 'Assignment transferred']);
} catch (Exception $e) {
    error_log('transfer_assignment error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}

mysqli_close($conn);
?>
