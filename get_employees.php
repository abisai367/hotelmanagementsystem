<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$conn = null;
include 'database.php';
include_once 'hotel_helpers.php';

if (!isset($conn) || !$conn) {
    error_log('get_employees: missing DB connection');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection unavailable']);
    exit;
}

try {
    ensureCoreSchema($conn);
    normalizeExistingRoles($conn);

    if (!tableExists($conn, 'users')) {
        echo json_encode(['status' => 'success', 'employees' => []]);
        exit;
    }

    $phoneField = columnExists($conn, 'users', 'phone_number') ? 'phone_number AS phone' : "'' AS phone";
    $shiftField = columnExists($conn, 'users', 'shift_schedule') ? 'shift_schedule' : "'' AS shift_schedule";
    $createdAtField = columnExists($conn, 'users', 'created_at') ? 'created_at' : "'' AS created_at";
    $salaryField = columnExists($conn, 'users', 'salary') ? 'COALESCE(salary, 0) AS salary' : '0 AS salary';
    $roles = roleSqlList($conn, hotelStaffRoles());

    $sql = "SELECT id, full_name, {$phoneField}, role, profile_image_url, {$shiftField}, {$createdAtField}, {$salaryField}
        FROM users
        WHERE LOWER(role) IN ({$roles})
        ORDER BY id DESC";
    $res = mysqli_query($conn, $sql);

    if (!$res) {
        error_log('get_employees SQL error: ' . mysqli_error($conn) . " -- SQL: $sql");
        throw new Exception('Query failed');
    }

    $out = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $row['role'] = normalizeHotelRole($row['role']);
        $salaryStatus = salaryStatusForEmployee($conn, intval($row['id']));
        $row['salary_status'] = $salaryStatus['status'];
        $row['last_salary_paid_at'] = $salaryStatus['paid_at'];
        $out[] = $row;
    }

    echo json_encode([
        'status' => 'success',
        'employees' => $out,
        'roles' => hotelStaffRoles(),
    ]);
} catch (Exception $e) {
    error_log('get_employees error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}

mysqli_close($conn);
?>
