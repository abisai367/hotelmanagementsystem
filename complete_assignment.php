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
$employeeId = isset($input['employee_id']) ? intval($input['employee_id']) : 0;

if ($assignmentId <= 0 || $employeeId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing assignment details']);
    exit;
}

try {
    ensureCoreSchema($conn);

    $stmt = $conn->prepare("SELECT order_id, employee_id FROM work_assignments WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Failed to prepare query: ' . $conn->error);
    }
    $stmt->bind_param('i', $assignmentId);
    $stmt->execute();
    $assignment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$assignment) {
        echo json_encode(['status' => 'error', 'message' => 'Assignment not found']);
        exit;
    }

    if (intval($assignment['employee_id']) !== $employeeId) {
        $roleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        if ($roleStmt) {
            $roleStmt->bind_param('i', $employeeId);
            $roleStmt->execute();
            $roleRow = $roleStmt->get_result()->fetch_assoc();
            $roleStmt->close();
            $role = normalizeHotelRole($roleRow['role'] ?? '');
            if (!in_array($role, ['supervisor', 'manager', 'admin'], true)) {
                echo json_encode(['status' => 'error', 'message' => 'This assignment belongs to another employee']);
                exit;
            }
        }
    }

    $update = $conn->prepare("UPDATE work_assignments SET status = 'completed', completed_at = NOW() WHERE id = ?");
    if (!$update) {
        throw new Exception('Failed to prepare update: ' . $conn->error);
    }
    $update->bind_param('i', $assignmentId);
    $update->execute();
    $update->close();

    setOrderAttended($conn, intval($assignment['order_id']), 'Yes');
    
    // Rebalance assignments with error suppression
    if (tableExists($conn, 'work_assignments') && tableExists($conn, 'orders')) {
        @rebalanceAssignments($conn, 'dineIn');
        @rebalanceAssignments($conn, 'delivery');
    }

    echo json_encode(['status' => 'success', 'message' => 'Assignment completed']);
} catch (Exception $e) {
    error_log('complete_assignment error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}

mysqli_close($conn);
?>
