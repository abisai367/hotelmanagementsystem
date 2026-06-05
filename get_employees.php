<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
$conn = null;
include 'database.php';
/** @var mysqli $conn */
if (!isset($conn) || !$conn) { error_log('get_employees: missing DB connection'); http_response_code(500); echo json_encode(['status'=>'error','message'=>'Database connection unavailable']); exit; }

try {
    $sql = "SELECT id, full_name, COALESCE(phone, phone_number, '') AS phone, role, profile_image_url, shift_schedule, created_at, COALESCE(salary, 0) AS salary FROM users WHERE role IN ('Employee','Supervisor') ORDER BY id DESC";
    $res = mysqli_query($conn, $sql);
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