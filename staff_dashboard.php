<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$conn = null;
include 'database.php';
include_once 'hotel_helpers.php';

// Enable detailed errors for local debugging (remove or disable in production)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection unavailable']);
    exit;
}

function assignmentSelectSql(mysqli $conn, string $where, string $orderBy): string {
    $tableExpr = tableColumnExpression($conn);
    
    // Build items expression safely
    $itemsExpr = "NULL";
    if (tableExists($conn, 'order_items')) {
        $itemsExpr = "(SELECT GROUP_CONCAT(CONCAT(COALESCE(p2.product_name, 'Item'), ' x', oi.quantity) SEPARATOR ', ') 
                      FROM order_items oi 
                      LEFT JOIN products p2 ON p2.product_id = oi.product_id 
                      WHERE oi.order_id = o.order_id)";
    }
    
    // Build legacy product join if needed
    $legacyProduct = "NULL";
    $legacyJoin = "";
    if (columnExists($conn, 'orders', 'product_id') && tableExists($conn, 'products')) {
        $legacyProduct = "p.product_name";
        $legacyJoin = "LEFT JOIN products p ON p.product_id = o.product_id";
    }

    return "SELECT
            wa.id AS assignment_id,
            wa.work_type,
            wa.status AS assignment_status,
            wa.assigned_by,
            wa.assigned_at,
            wa.completed_at,
            wa.employee_id,
            assignee.full_name AS assigned_employee,
            o.order_id,
            o.customer_id,
            customer.full_name AS customer_name,
            o.order_type,
            {$tableExpr} AS table_number,
            o.delivery_address,
            o.delivery_latitude,
            o.delivery_longitude,
            o.contact_number,
            o.payment_status,
            o.created_at,
            COALESCE({$itemsExpr}, {$legacyProduct}, 'Order items') AS items
        FROM work_assignments wa
        JOIN orders o ON o.order_id = wa.order_id
        LEFT JOIN users customer ON customer.id = o.customer_id
        LEFT JOIN users assignee ON assignee.id = wa.employee_id
        {$legacyJoin}
        WHERE {$where}
        ORDER BY {$orderBy}";
}
}

function fetchAssignments(mysqli $conn, string $sql, string $types = '', array $values = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('fetchAssignments prepare failed: ' . $conn->error . ' SQL: ' . $sql);
        return [];
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    
    if (!$stmt->execute()) {
        error_log('fetchAssignments execute failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    
    $res = $stmt->get_result();
    if (!$res) {
        error_log('fetchAssignments get_result failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

$employeeId = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$role = normalizeHotelRole($_GET['role'] ?? '');
$range = $_GET['range'] ?? 'today';

if ($employeeId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing employee id']);
    exit;
}

try {
    ensureCoreSchema($conn);
    normalizeExistingRoles($conn);
    
    // Only rebalance if tables exist
    if (tableExists($conn, 'work_assignments') && tableExists($conn, 'orders')) {
        @rebalanceAssignments($conn, 'dineIn');
        @rebalanceAssignments($conn, 'delivery');
    }

    // Fetch user data
    $userStmt = $conn->prepare("SELECT id, full_name, role, COALESCE(salary, 0) AS salary FROM users WHERE id = ? LIMIT 1");
    if (!$userStmt) {
        throw new Exception('Failed to prepare user query: ' . $conn->error);
    }
    $userStmt->bind_param('i', $employeeId);
    $userStmt->execute();
    $user = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Staff account not found']);
        exit;
    }

    $role = normalizeHotelRole($user['role'] ?: $role);
    $salaryStatus = salaryStatusForEmployee($conn, $employeeId);
    
    // Initialize defaults
    $assignments = [];
    $stats = ['served_count' => 0];
    $active = ['active_count' => 0];
    $supervisor = null;

    // Fetch assignments only if tables exist
    if (tableExists($conn, 'work_assignments') && tableExists($conn, 'orders')) {
        $tableExpr = tableColumnExpression($conn);
        $ownOrder = $role === 'waiter' ? "CAST(COALESCE({$tableExpr}, 0) AS UNSIGNED), o.order_id" : "o.order_id";
        $ownSql = assignmentSelectSql($conn, "wa.employee_id = ? AND wa.status = 'assigned'", $ownOrder);
        
        try {
            $assignments = fetchAssignments($conn, $ownSql, 'i', [$employeeId]);
        } catch (Exception $e) {
            error_log('Failed to fetch assignments: ' . $e->getMessage());
            $assignments = [];
        }

        // Fetch statistics
        $rangeWhere = rangeCondition($range, 'wa.completed_at');
        $statsStmt = $conn->prepare("SELECT COALESCE(COUNT(*), 0) AS served_count
            FROM work_assignments wa
            WHERE wa.employee_id = ?
            AND wa.status = 'completed'
            AND wa.completed_at IS NOT NULL
            AND {$rangeWhere}");
        if ($statsStmt) {
            $statsStmt->bind_param('i', $employeeId);
            $statsStmt->execute();
            $stats = $statsStmt->get_result()->fetch_assoc() ?: ['served_count' => 0];
            $statsStmt->close();
        }

        $activeStmt = $conn->prepare("SELECT COALESCE(COUNT(*), 0) AS active_count FROM work_assignments WHERE employee_id = ? AND status = 'assigned'");
        if ($activeStmt) {
            $activeStmt->bind_param('i', $employeeId);
            $activeStmt->execute();
            $active = $activeStmt->get_result()->fetch_assoc() ?: ['active_count' => 0];
            $activeStmt->close();
        }

        // Fetch supervisor data if applicable
        if (in_array($role, ['supervisor', 'manager', 'admin'], true)) {
            try {
                $allOrder = "wa.work_type ASC, CAST(COALESCE({$tableExpr}, 0) AS UNSIGNED), o.order_id";
                $allSql = assignmentSelectSql($conn, "wa.status = 'assigned'", $allOrder);
                $allAssignments = fetchAssignments($conn, $allSql);

                $staff = [];
                $staffRes = mysqli_query($conn, "SELECT id, full_name, role FROM users WHERE LOWER(role) IN ('waiter','delivery person') ORDER BY role, full_name");
                while ($staffRes && ($row = mysqli_fetch_assoc($staffRes))) {
                    $staff[] = $row;
                }
                $supervisor = ['assignments' => $allAssignments, 'staff' => $staff];
            } catch (Exception $e) {
                error_log('Failed to fetch supervisor data: ' . $e->getMessage());
                $supervisor = ['assignments' => [], 'staff' => []];
            }
        }
    }

    echo json_encode([
        'status' => 'success',
        'user' => [
            'id' => $user['id'],
            'full_name' => $user['full_name'],
            'role' => $role,
            'salary' => (float)$user['salary'],
            'salary_status' => $salaryStatus['status'],
            'last_salary_paid_at' => $salaryStatus['paid_at'],
        ],
        'range' => $range,
        'stats' => [
            'servedCustomers' => intval($stats['served_count'] ?? 0),
            'activeAssignments' => intval($active['active_count'] ?? 0),
        ],
        'assignments' => $assignments,
        'supervisor' => $supervisor,
    ]);
} catch (Exception $e) {
    $msg = $e->getMessage();
    error_log('staff_dashboard error: ' . $msg . " -- " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error', 'detail' => $msg]);
}

mysqli_close($conn);
?>
