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
include_once 'mpesa_helpers.php';

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection unavailable']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

$adminId = isset($input['admin_id']) ? intval($input['admin_id']) : 0;
$paymentPhone = trim($input['payment_phone'] ?? $input['paymentNumber'] ?? '');
$employeeIds = $input['employee_ids'] ?? [];
$payAll = !empty($input['pay_all']);

if ($paymentPhone === '') {
    echo json_encode(['status' => 'error', 'message' => 'Payment number is required']);
    exit;
}

try {
    ensureCoreSchema($conn);
    normalizeExistingRoles($conn);

    $roles = roleSqlList($conn, payrollStaffRoles());
    $conditions = ["LOWER(role) IN ({$roles})", "COALESCE(salary, 0) > 0"];
    $types = '';
    $values = [];

    if (!$payAll) {
        if (!is_array($employeeIds) || count($employeeIds) === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Choose at least one employee to pay']);
            exit;
        }

        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $conditions[] = "id IN ({$placeholders})";
        $types .= str_repeat('i', count($employeeIds));
        $values = array_merge($values, $employeeIds);
    } else {
        $conditions[] = "NOT EXISTS (
            SELECT 1 FROM salary_payments sp
            WHERE sp.employee_id = users.id
            AND sp.payment_status = 'Paid'
            AND sp.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        )";
    }

    $sql = "SELECT id, full_name, phone_number, role, COALESCE(salary, 0) AS salary
        FROM users
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY full_name ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Employee query prepare failed: ' . $conn->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $employees = [];
    $total = 0.0;
    while ($row = $res->fetch_assoc()) {
        $row['salary'] = (float)$row['salary'];
        $employees[] = $row;
        $total += $row['salary'];
    }
    $stmt->close();

    if (count($employees) === 0 || $total <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'No unpaid staff with salary were found']);
        exit;
    }

    $conn->begin_transaction();

    $batchStmt = $conn->prepare("INSERT INTO payroll_batches (admin_id, payment_phone, total_amount, status) VALUES (?, ?, ?, 'Pending')");
    if (!$batchStmt) {
        throw new Exception('Payroll batch prepare failed: ' . $conn->error);
    }
    $batchStmt->bind_param('isd', $adminId, $paymentPhone, $total);
    $batchStmt->execute();
    $batchId = $conn->insert_id;
    $batchStmt->close();

    $itemStmt = $conn->prepare("INSERT INTO salary_payments (batch_id, employee_id, salary_amount, payment_status) VALUES (?, ?, ?, 'Pending')");
    if (!$itemStmt) {
        throw new Exception('Salary item prepare failed: ' . $conn->error);
    }
    foreach ($employees as $employee) {
        $employeeId = intval($employee['id']);
        $salary = (float)$employee['salary'];
        $itemStmt->bind_param('iid', $batchId, $employeeId, $salary);
        $itemStmt->execute();
    }
    $itemStmt->close();

    $conn->commit();

    $stk = sendHotelStkPush($paymentPhone, $total, 'Payroll' . $batchId, 'Hotel staff salary');

    if ($stk['status'] !== 'success') {
        $failStmt = $conn->prepare("UPDATE payroll_batches SET status = 'Failed' WHERE id = ?");
        $failStmt->bind_param('i', $batchId);
        $failStmt->execute();
        $failStmt->close();
        $failItems = $conn->prepare("UPDATE salary_payments SET payment_status = 'Failed' WHERE batch_id = ?");
        $failItems->bind_param('i', $batchId);
        $failItems->execute();
        $failItems->close();

        echo json_encode(['status' => 'error', 'message' => $stk['message'], 'batchId' => $batchId]);
        exit;
    }

    $checkout = $stk['checkoutRequestID'];
    $merchant = $stk['merchantRequestID'];
    $phone = $stk['phone'];
    $update = $conn->prepare("UPDATE payroll_batches SET checkout_request_id = ?, merchant_request_id = ?, payment_phone = ? WHERE id = ?");
    $update->bind_param('sssi', $checkout, $merchant, $phone, $batchId);
    $update->execute();
    $update->close();

    echo json_encode([
        'status' => 'success',
        'message' => 'Salary STK prompt sent',
        'batchId' => $batchId,
        'checkoutRequestID' => $checkout,
        'merchantRequestID' => $merchant,
        'totalAmount' => $total,
        'employees' => $employees,
    ]);
} catch (Exception $e) {
    @mysqli_rollback($conn);
    error_log('pay_salary error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}

mysqli_close($conn);
?>
