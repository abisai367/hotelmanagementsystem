<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$conn = null;
include 'database.php';
include_once 'hotel_helpers.php';

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection unavailable']);
    exit;
}

$batchId = isset($_GET['batchId']) ? intval($_GET['batchId']) : 0;
$checkout = $_GET['checkoutRequestID'] ?? '';

try {
    ensureCoreSchema($conn);

    if ($batchId > 0) {
        $stmt = $conn->prepare("SELECT * FROM payroll_batches WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $batchId);
    } elseif ($checkout !== '') {
        $stmt = $conn->prepare("SELECT * FROM payroll_batches WHERE checkout_request_id = ? LIMIT 1");
        $stmt->bind_param('s', $checkout);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Missing payroll batch identifier']);
        exit;
    }

    $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$batch) {
        echo json_encode(['status' => 'error', 'message' => 'Payroll batch not found']);
        exit;
    }

    $items = [];
    $itemStmt = $conn->prepare("SELECT sp.*, u.full_name, u.phone_number, u.role
        FROM salary_payments sp
        JOIN users u ON u.id = sp.employee_id
        WHERE sp.batch_id = ?
        ORDER BY u.full_name ASC");
    $id = intval($batch['id']);
    $itemStmt->bind_param('i', $id);
    $itemStmt->execute();
    $res = $itemStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $itemStmt->close();

    echo json_encode([
        'status' => 'success',
        'paymentStatus' => $batch['status'],
        'batch' => $batch,
        'items' => $items,
    ]);
} catch (Exception $e) {
    error_log('check_salary_payment error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}

mysqli_close($conn);
?>
