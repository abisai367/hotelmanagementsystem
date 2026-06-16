<?php
include 'database.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function q($sql) {
    global $conn;
    $res = mysqli_query($conn, $sql);
    if ($res === false) {
        $err = mysqli_error($conn);
        error_log("admin_summary.php SQL error: $err -- SQL: $sql");
        if (isset($_GET['debug']) && $_GET['debug'] === '1') {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'SQL error', 'detail' => $err, 'sql' => $sql]);
            mysqli_close($conn);
            exit;
        }
        throw new Exception('Database query failed');
    }
    return $res;
}



function getOrderDateExpression() {
    global $conn;
    if (columnExists($conn, 'orders', 'day_created')) {
        return "IFNULL(o.day_created, IFNULL(o.pickup_time, o.created_at))";
    }
    return "IFNULL(o.pickup_time, o.created_at)";
}

function getDateRangeWhere($range) {
    $orderDate = getOrderDateExpression();
    if ($range === 'today') {
        return "DATE($orderDate) = DATE(NOW())";
    }
    if ($range === 'week') {
        return "DATE($orderDate) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
    }
    if ($range === 'month') {
        return "DATE($orderDate) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)";
    }
    return "YEAR($orderDate) = YEAR(CURDATE())";
}

function buildRevenueQuery($range = 'today') {
    global $conn;
    $dateWhere = getDateRangeWhere($range);
    
    if (tableExists($conn, 'order_items')) {
        return "SELECT SUM(oi.quantity * IFNULL(oi.price,0)) AS revenue FROM order_items oi JOIN orders o ON oi.order_id = o.order_id WHERE $dateWhere";
    }
    if (columnExists($conn, 'orders', 'product_id') && columnExists($conn, 'orders', 'quantity')) {
        return "SELECT SUM(p.price * o.quantity) AS revenue FROM orders o JOIN products p ON o.product_id = p.product_id WHERE $dateWhere";
    }
    if (columnExists($conn, 'orders', 'total_price')) {
        return "SELECT SUM(total_price) AS revenue FROM orders o WHERE $dateWhere";
    }
    return "SELECT 0 AS revenue";
}

function buildTrendingQuery($range = 'today') {
    global $conn;
    $dateWhere = getDateRangeWhere($range);
    
    if (tableExists($conn, 'order_items')) {
        return "SELECT p.product_id, p.product_name, p.product_path, SUM(oi.quantity) AS orders_count FROM order_items oi JOIN orders o ON oi.order_id = o.order_id JOIN products p ON oi.product_id = p.product_id WHERE $dateWhere GROUP BY p.product_id ORDER BY orders_count DESC LIMIT 10";
    }
    if (columnExists($conn, 'orders', 'product_id') && columnExists($conn, 'orders', 'quantity')) {
        return "SELECT p.product_id, p.product_name, p.product_path, SUM(o.quantity) AS orders_count FROM orders o JOIN products p ON o.product_id = p.product_id WHERE $dateWhere GROUP BY p.product_id ORDER BY orders_count DESC LIMIT 10";
    }
    return "SELECT p.product_id, p.product_name, p.product_path, 0 AS orders_count FROM products p ORDER BY p.product_id DESC LIMIT 10";
}

function buildRangeQuery($range) {
    global $conn;
    if (!columnExists($conn, 'orders', 'order_type') || !columnExists($conn, 'orders', 'pickup_time')) {
        return null;
    }

    $timeQuery = tableExists($conn, 'order_items')
        ? "SUM(oi.quantity * IFNULL(oi.price,0)) AS revenue FROM orders o JOIN order_items oi ON oi.order_id = o.order_id"
        : "SUM(o.quantity * IFNULL(p.price,0)) AS revenue FROM orders o JOIN products p ON o.product_id = p.product_id";

    $orderDate = getOrderDateExpression();
    if ($range === 'today') {
        return "SELECT HOUR($orderDate) AS hr, o.order_type, $timeQuery WHERE DATE($orderDate) = DATE(NOW()) GROUP BY hr, o.order_type";
    }
    if ($range === 'week') {
        return "SELECT DATE($orderDate) AS dt, o.order_type, $timeQuery WHERE DATE($orderDate) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY dt, o.order_type";
    }
    if ($range === 'month') {
        return "SELECT DATE($orderDate) AS dt, o.order_type, $timeQuery WHERE DATE($orderDate) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY dt, o.order_type";
    }
    return "SELECT MONTH($orderDate) AS mon, o.order_type, $timeQuery WHERE YEAR($orderDate) = YEAR(CURDATE()) GROUP BY mon, o.order_type";
}

try {
    $range = $_GET['range'] ?? 'today';
    $staffRoles = "'waiter','cooks','security','delivery person','supervisor','manager','admin'";
    $payrollRoles = "'waiter','cooks','security','delivery person','supervisor','manager'";
    $dateWhere = getDateRangeWhere($range);
    
    if (!columnExists($conn, 'users', 'salary')) {
        @mysqli_query($conn, "ALTER TABLE users ADD COLUMN salary DECIMAL(10,2) NOT NULL DEFAULT 0");
    }
    if (!tableExists($conn, 'salary_payments')) {
        @mysqli_query($conn, "CREATE TABLE salary_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id INT NOT NULL,
            employee_id INT NOT NULL,
            salary_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            payment_status VARCHAR(30) NOT NULL DEFAULT 'Pending',
            paid_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_salary_employee (employee_id),
            INDEX idx_salary_batch (batch_id),
            INDEX idx_salary_status (payment_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    @mysqli_query($conn, "UPDATE users SET role = 'waiter' WHERE LOWER(role) = 'employee'");
    @mysqli_query($conn, "UPDATE users SET role = 'supervisor' WHERE LOWER(role) = 'supervisor'");
    @mysqli_query($conn, "UPDATE users SET role = 'admin' WHERE LOWER(role) = 'admin'");

    // totals - filtered by range
    $res = q("SELECT COUNT(*) AS total_orders FROM orders o WHERE $dateWhere");
    $totalOrders = ($row = mysqli_fetch_assoc($res)) ? intval($row['total_orders']) : 0;

    $res = q(buildRevenueQuery($range));
    $totalRevenue = ($row = mysqli_fetch_assoc($res)) ? floatval($row['revenue']) : 0.0;

    $res = q("SELECT COUNT(*) AS total_products FROM products");
    $totalProducts = ($row = mysqli_fetch_assoc($res)) ? intval($row['total_products']) : 0;

    // new customers in the range
    if ($range === 'today') {
        $customerWhere = "DATE(u.created_at) = DATE(NOW())";
    } elseif ($range === 'week') {
        $customerWhere = "DATE(u.created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
    } elseif ($range === 'month') {
        $customerWhere = "DATE(u.created_at) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)";
    } else {
        $customerWhere = "YEAR(u.created_at) = YEAR(CURDATE())";
    }
    $res = q("SELECT COUNT(*) AS new_customers FROM users u WHERE $customerWhere");
    $newCustomers = ($row = mysqli_fetch_assoc($res)) ? intval($row['new_customers']) : 0;

    $trending = [];
    $res = q(buildTrendingQuery($range));
    while ($r = mysqli_fetch_assoc($res)) {
        $trending[] = $r;
    }

    $employees = [];
    $orderDate = getOrderDateExpression();
    if (tableExists($conn, 'work_assignments')) {
        $employeeSql = "SELECT u.id, u.full_name, u.profile_image_url, u.role, COUNT(DISTINCT wa.order_id) AS assignments_count
            FROM users u
            JOIN work_assignments wa ON wa.employee_id = u.id
            JOIN orders o ON o.order_id = wa.order_id
            WHERE LOWER(u.role) IN ({$staffRoles})
            AND wa.status IN ('assigned','completed')
            AND " . getDateRangeWhere($range) . "
            GROUP BY u.id
            ORDER BY assignments_count DESC
            LIMIT 6";
        $res = q($employeeSql);
        while ($r = mysqli_fetch_assoc($res)) {
            $employees[] = $r;
        }
    }

    if (count($employees) === 0) {
        $res = q("SELECT id, full_name, profile_image_url, role FROM users WHERE LOWER(role) IN ({$staffRoles}) LIMIT 6");
        while ($r = mysqli_fetch_assoc($res)) {
            $employees[] = $r;
        }
    }

    $unpaidEmployees = [];
    $unpaidSql = "SELECT u.id, u.full_name, u.role, COALESCE(u.salary, 0) AS salary
        FROM users u
        WHERE LOWER(u.role) IN ({$payrollRoles})
        AND COALESCE(u.salary, 0) > 0
        AND NOT EXISTS (
            SELECT 1
            FROM salary_payments sp
            WHERE sp.employee_id = u.id
            AND sp.payment_status = 'Paid'
            AND sp.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        )
        ORDER BY u.full_name ASC
        LIMIT 12";
    $res = q($unpaidSql);
    while ($r = mysqli_fetch_assoc($res)) {
        $unpaidEmployees[] = $r;
    }
    $timeLabels = [];
    $series = ['dineIn'=>[], 'takeAway'=>[], 'delivery'=>[]];
    $breakdown = ['dineIn'=>0, 'takeAway'=>0, 'delivery'=>0];

    $rangeSql = buildRangeQuery($range);
    if ($rangeSql !== null) {
        if ($range === 'today') {
            for ($h = 0; $h < 24; $h++) { $timeLabels[] = sprintf('%02d:00', $h); }
        } elseif ($range === 'week') {
            for ($d = 6; $d >= 0; $d--) { $dt = date('Y-m-d', strtotime("-{$d} days")); $timeLabels[] = $dt; }
        } elseif ($range === 'month') {
            for ($d = 29; $d >= 0; $d--) { $dt = date('Y-m-d', strtotime("-{$d} days")); $timeLabels[] = $dt; }
        } else {
            for ($m = 1; $m <= 12; $m++) { $timeLabels[] = date('M', mktime(0,0,0,$m,1)); }
        }

        $res = q($rangeSql);
        $map = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $key = $r['order_type'];
            $value = floatval($r['revenue']);
            $breakdown[$key] += $value;
            if ($range === 'today') {
                $idx = intval($r['hr']);
                if (!isset($series[$key][$idx])) $series[$key][$idx] = 0;
                $series[$key][$idx] += $value;
            } elseif ($range === 'week' || $range === 'month') {
                $date = $r['dt'];
                if (!isset($map[$key])) $map[$key] = [];
                $map[$key][$date] = $value;
            } else {
                $mon = intval($r['mon']);
                if (!isset($map[$key])) $map[$key] = [];
                $map[$key][$mon] = $value;
            }
        }

        if ($range === 'today') {
            foreach ($series as $k => $arr) {
                for ($i = 0; $i < 24; $i++) { $series[$k][$i] = isset($arr[$i]) ? $arr[$i] : 0; }
            }
        } elseif ($range === 'week' || $range === 'month') {
            foreach (['dineIn','takeAway','delivery'] as $k) {
                $series[$k] = [];
                foreach ($timeLabels as $lab) { $series[$k][] = isset($map[$k][$lab]) ? $map[$k][$lab] : 0; }
            }
        } else {
            foreach (['dineIn','takeAway','delivery'] as $k) {
                $series[$k] = [];
                for ($m = 1; $m <= 12; $m++) { $series[$k][] = isset($map[$k][$m]) ? $map[$k][$m] : 0; }
            }
        }
    } else {
        if ($range === 'today') {
            for ($h = 0; $h < 24; $h++) { $timeLabels[] = sprintf('%02d:00', $h); }
        } elseif ($range === 'week') {
            for ($d = 6; $d >= 0; $d--) { $dt = date('Y-m-d', strtotime("-{$d} days")); $timeLabels[] = $dt; }
        } elseif ($range === 'month') {
            for ($d = 29; $d >= 0; $d--) { $dt = date('Y-m-d', strtotime("-{$d} days")); $timeLabels[] = $dt; }
        } else {
            for ($m = 1; $m <= 12; $m++) { $timeLabels[] = date('M', mktime(0,0,0,$m,1)); }
        }
        foreach (['dineIn','takeAway','delivery'] as $k) {
            if ($range === 'today') {
                $series[$k] = array_fill(0, 24, 0);
            } elseif ($range === 'week') {
                $series[$k] = array_fill(0, 7, 0);
            } elseif ($range === 'month') {
                $series[$k] = array_fill(0, 30, 0);
            } else {
                $series[$k] = array_fill(0, 12, 0);
            }
        }
    }

    echo json_encode([
        'status' => 'success',
        'totalOrders' => $totalOrders,
        'totalRevenue' => $totalRevenue,
        'totalProducts' => $totalProducts,
        'newCustomers' => $newCustomers,
        'trending' => $trending,
        'employees' => $employees,
        'unpaidEmployees' => $unpaidEmployees,
        'unpaidPayrollTotal' => array_reduce($unpaidEmployees, fn($sum, $employee) => $sum + floatval($employee['salary']), 0),
        'labels' => $timeLabels,
        'series' => $series,
        'breakdown' => $breakdown,
        'range' => $range
    ]);

} catch (Exception $e) {
    // Do not expose internal exception messages to clients. Log for operators instead.
    error_log("admin_summary.php exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error.']);
}

mysqli_close($conn);

?>
