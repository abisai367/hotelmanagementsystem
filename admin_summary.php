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

function tableExists($table) {
    global $conn;
    $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    return $res && mysqli_num_rows($res) > 0;
}

function columnExists($table, $column) {
    global $conn;
    $res = mysqli_query($conn, "SHOW COLUMNS FROM " . mysqli_real_escape_string($conn, $table) . " LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
    return $res && mysqli_num_rows($res) > 0;
}

function buildRevenueQuery() {
    global $conn;
    if (columnExists('orders', 'product_id') && columnExists('orders', 'quantity')) {
        return "SELECT SUM(p.price * o.quantity) AS revenue FROM orders o JOIN products p ON o.product_id = p.product_id";
    }
    if (tableExists('order_items')) {
        return "SELECT SUM(oi.quantity * IFNULL(oi.price,0)) AS revenue FROM order_items oi";
    }
    if (columnExists('orders', 'total_price')) {
        return "SELECT SUM(total_price) AS revenue FROM orders";
    }
    return "SELECT 0 AS revenue";
}

function buildTrendingQuery() {
    global $conn;
    if (columnExists('orders', 'product_id') && columnExists('orders', 'quantity')) {
        return "SELECT p.product_id, p.product_name, p.product_path, SUM(o.quantity) AS orders_count FROM orders o JOIN products p ON o.product_id = p.product_id GROUP BY p.product_id ORDER BY orders_count DESC LIMIT 10";
    }
    if (tableExists('order_items')) {
        return "SELECT p.product_id, p.product_name, p.product_path, SUM(oi.quantity) AS orders_count FROM order_items oi JOIN products p ON oi.product_id = p.product_id GROUP BY p.product_id ORDER BY orders_count DESC LIMIT 10";
    }
    return "SELECT p.product_id, p.product_name, p.product_path, 0 AS orders_count FROM products p ORDER BY p.product_id DESC LIMIT 10";
}

function buildRangeQuery($range) {
    if (!columnExists('orders', 'order_type') || !columnExists('orders', 'pickup_time')) {
        return null;
    }

    $timeQuery = tableExists('order_items')
        ? "SUM(oi.quantity * IFNULL(oi.price,0)) AS revenue FROM orders o JOIN order_items oi ON oi.order_id = o.order_id"
        : "SUM(o.quantity * IFNULL(p.price,0)) AS revenue FROM orders o JOIN products p ON o.product_id = p.product_id";

    if ($range === 'today') {
        return "SELECT HOUR(IFNULL(o.pickup_time, NOW())) AS hr, o.order_type, $timeQuery WHERE DATE(IFNULL(o.pickup_time, NOW())) = DATE(NOW()) GROUP BY hr, o.order_type";
    }
    if ($range === 'week') {
        return "SELECT DATE(IFNULL(o.pickup_time, NOW())) AS dt, o.order_type, $timeQuery WHERE DATE(IFNULL(o.pickup_time, NOW())) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY dt, o.order_type";
    }
    if ($range === 'month') {
        return "SELECT DATE(IFNULL(o.pickup_time, NOW())) AS dt, o.order_type, $timeQuery WHERE DATE(IFNULL(o.pickup_time, NOW())) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY dt, o.order_type";
    }
    return "SELECT MONTH(IFNULL(o.pickup_time, NOW())) AS mon, o.order_type, $timeQuery WHERE YEAR(IFNULL(o.pickup_time, NOW())) = YEAR(CURDATE()) GROUP BY mon, o.order_type";
}

try {
    $range = $_GET['range'] ?? 'today';

    // totals
    $res = q("SELECT COUNT(*) AS total_orders FROM orders");
    $totalOrders = ($row = mysqli_fetch_assoc($res)) ? intval($row['total_orders']) : 0;

    $res = q(buildRevenueQuery());
    $totalRevenue = ($row = mysqli_fetch_assoc($res)) ? floatval($row['revenue']) : 0.0;

    $res = q("SELECT COUNT(*) AS total_products FROM products");
    $totalProducts = ($row = mysqli_fetch_assoc($res)) ? intval($row['total_products']) : 0;

    $res = q("SELECT COUNT(*) AS new_customers FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $newCustomers = ($row = mysqli_fetch_assoc($res)) ? intval($row['new_customers']) : 0;

    $trending = [];
    $res = q(buildTrendingQuery());
    while ($r = mysqli_fetch_assoc($res)) {
        $trending[] = $r;
    }

    $employees = [];
    $res = q("SELECT id, full_name, profile_image_url, role FROM users WHERE role IN ('Employee','Supervisor') LIMIT 6");
    while ($r = mysqli_fetch_assoc($res)) {
        $employees[] = $r;
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
