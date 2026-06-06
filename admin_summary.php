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

try {
    $range = $_GET['range'] ?? 'today';

    // totals
    $res = mysqli_query($conn, "SELECT COUNT(*) AS total_orders FROM orders");
    $totalOrders = ($row = mysqli_fetch_assoc($res)) ? intval($row['total_orders']) : 0;

    $res = mysqli_query($conn, "SELECT SUM(p.price * o.quantity) AS revenue FROM orders o JOIN products p ON o.product_id = p.product_id");
    $totalRevenue = ($row = mysqli_fetch_assoc($res)) ? floatval($row['revenue']) : 0.0;

    $res = mysqli_query($conn, "SELECT COUNT(*) AS total_products FROM products");
    $totalProducts = ($row = mysqli_fetch_assoc($res)) ? intval($row['total_products']) : 0;

    $res = mysqli_query($conn, "SELECT COUNT(*) AS new_customers FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $newCustomers = ($row = mysqli_fetch_assoc($res)) ? intval($row['new_customers']) : 0;

    $trending = [];
    $res = mysqli_query($conn, "SELECT p.product_id, p.product_name, p.product_path, SUM(o.quantity) AS orders_count FROM orders o JOIN products p ON o.product_id = p.product_id GROUP BY p.product_id ORDER BY orders_count DESC LIMIT 10");
    while ($r = mysqli_fetch_assoc($res)) {
        $trending[] = $r;
    }

    $employees = [];
    $res = mysqli_query($conn, "SELECT id, full_name, profile_image_url, role FROM users WHERE role IN ('Employee','Supervisor') LIMIT 6");
    while ($r = mysqli_fetch_assoc($res)) {
        $employees[] = $r;
    }
    $timeLabels = [];
    $series = ['dineIn'=>[], 'takeAway'=>[], 'delivery'=>[]];
    $breakdown = ['dineIn'=>0, 'takeAway'=>0, 'delivery'=>0];

    if ($range === 'today') {
        for ($h = 0; $h < 24; $h++) { $timeLabels[] = sprintf('%02d:00', $h); }
        $sql = "SELECT HOUR(IFNULL(pickup_time, NOW())) AS hr, order_type, SUM(o.quantity * IFNULL(p.price,0)) AS revenue FROM orders o JOIN products p ON o.product_id = p.product_id WHERE DATE(IFNULL(pickup_time, NOW())) = DATE(NOW()) GROUP BY hr, order_type";
        $res = mysqli_query($conn, $sql);
        while ($r = mysqli_fetch_assoc($res)) {
            $key = $r['order_type'];
            $idx = intval($r['hr']);
            if (!isset($series[$key][$idx])) $series[$key][$idx] = 0;
            $series[$key][$idx] += floatval($r['revenue']);
            $breakdown[$key] += floatval($r['revenue']);
        }
        foreach ($series as $k => $arr) {
            for ($i=0;$i<24;$i++) { $series[$k][$i] = isset($arr[$i]) ? $arr[$i] : 0; }
        }
    } elseif ($range === 'week') {
        for ($d = 6; $d >= 0; $d--) { $dt = date('Y-m-d', strtotime("-{$d} days")); $timeLabels[] = $dt; }
        $sql = "SELECT DATE(IFNULL(pickup_time, NOW())) AS dt, order_type, SUM(o.quantity * IFNULL(p.price,0)) AS revenue FROM orders o JOIN products p ON o.product_id = p.product_id WHERE DATE(IFNULL(pickup_time, NOW())) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY dt, order_type";
        $res = mysqli_query($conn, $sql);
        $map = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $key = $r['order_type'];
            $date = $r['dt'];
            if (!isset($map[$key])) $map[$key] = [];
            $map[$key][$date] = floatval($r['revenue']);
            $breakdown[$key] += floatval($r['revenue']);
        }
        foreach (['dineIn','takeAway','delivery'] as $k) {
            $series[$k] = [];
            foreach ($timeLabels as $lab) { $series[$k][] = isset($map[$k][$lab]) ? $map[$k][$lab] : 0; }
        }
    } elseif ($range === 'month') {
        for ($d = 29; $d >= 0; $d--) { $dt = date('Y-m-d', strtotime("-{$d} days")); $timeLabels[] = $dt; }
        $sql = "SELECT DATE(IFNULL(pickup_time, NOW())) AS dt, order_type, SUM(o.quantity * IFNULL(p.price,0)) AS revenue FROM orders o JOIN products p ON o.product_id = p.product_id WHERE DATE(IFNULL(pickup_time, NOW())) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY dt, order_type";
        $res = mysqli_query($conn, $sql);
        $map = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $key = $r['order_type'];
            $date = $r['dt'];
            if (!isset($map[$key])) $map[$key] = [];
            $map[$key][$date] = floatval($r['revenue']);
            $breakdown[$key] += floatval($r['revenue']);
        }
        foreach (['dineIn','takeAway','delivery'] as $k) {
            $series[$k] = [];
            foreach ($timeLabels as $lab) { $series[$k][] = isset($map[$k][$lab]) ? $map[$k][$lab] : 0; }
        }
    } else {
        for ($m=1;$m<=12;$m++) { $timeLabels[] = date('M', mktime(0,0,0,$m,1)); }
        $sql = "SELECT MONTH(IFNULL(pickup_time, NOW())) AS mon, order_type, SUM(o.quantity * IFNULL(p.price,0)) AS revenue FROM orders o JOIN products p ON o.product_id = p.product_id WHERE YEAR(IFNULL(pickup_time, NOW())) = YEAR(CURDATE()) GROUP BY mon, order_type";
        $res = mysqli_query($conn, $sql);
        $map = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $key = $r['order_type'];
            $mon = intval($r['mon']);
            if (!isset($map[$key])) $map[$key] = [];
            $map[$key][$mon] = floatval($r['revenue']);
            $breakdown[$key] += floatval($r['revenue']);
        }
        foreach (['dineIn','takeAway','delivery'] as $k) {
            $series[$k] = [];
            for ($m=1;$m<=12;$m++) { $series[$k][] = isset($map[$k][$m]) ? $map[$k][$m] : 0; }
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
