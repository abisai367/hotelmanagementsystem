<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
$conn = null;
include 'database.php';
/** @var mysqli $conn */
if (!isset($conn) || !$conn) { error_log('get_employees: missing DB connection'); http_response_code(500); echo json_encode(['status'=>'error','message'=>'Database connection unavailable']); exit; }

function columnExists($table, $column) {
    global $conn;
    $res = mysqli_query($conn, "SHOW COLUMNS FROM " . mysqli_real_escape_string($conn, $table) . " LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
    return $res && mysqli_num_rows($res) > 0;
}

function tableExists($table) {
    global $conn;
    $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    return $res && mysqli_num_rows($res) > 0;
}

try {
    if (!tableExists('users')) {
        echo json_encode(['status' => 'success', 'employees' => []]);
        exit;
    }

    $phoneField = '"" AS phone';
    if (columnExists('users', 'phone') && columnExists('users', 'phone_number')) {
        $phoneField = "COALESCE(phone, phone_number, '') AS phone";
    } elseif (columnExists('users', 'phone')) {
        $phoneField = "phone AS phone";
    } elseif (columnExists('users', 'phone_number')) {
        $phoneField = "phone_number AS phone";
    }

    $shiftField = columnExists('users', 'shift_schedule') ? 'shift_schedule' : "'' AS shift_schedule";
    $salaryField = columnExists('users', 'salary') ? 'COALESCE(salary, 0) AS salary' : '0 AS salary';
    $createdAtField = columnExists('users', 'created_at') ? 'created_at' : "'' AS created_at";

    $sql = "SELECT id, full_name, $phoneField, role, profile_image_url, $shiftField, $createdAtField, $salaryField FROM users WHERE role IN ('Employee','Supervisor') ORDER BY id DESC";
    $res = mysqli_query($conn, $sql);
    
    if (!$res) {
        error_log('get_employees SQL error: ' . mysqli_error($conn) . " -- SQL: $sql");
        throw new Exception('Query failed');
    }
    
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $out[] = $r;
    }
    echo json_encode(['status'=>'success','employees'=>$out]);
} catch (Exception $e) {
    error_log('get_employees error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Server error']);
}

mysqli_close($conn);
?>
