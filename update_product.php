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
/** @var mysqli $conn */
if (!isset($conn) || !$conn) { error_log('update_product: missing DB connection'); http_response_code(500); echo json_encode(['status'=>'error','message'=>'Database connection unavailable']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

$id = isset($input['id']) ? intval($input['id']) : 0;
$name = trim($input['product_name'] ?? '');
$desc = trim($input['description'] ?? '');
$price = isset($input['price']) ? floatval($input['price']) : null;
$path = trim($input['product_path'] ?? '');

if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing product id']);
    exit;
}

try {
    $fields = [];
    $types = '';
    $values = [];
    if ($name !== '') { $fields[] = 'product_name = ?'; $types .= 's'; $values[] = $name; }
    if ($desc !== '') { $fields[] = 'description = ?'; $types .= 's'; $values[] = $desc; }
    if (!is_null($price)) { $fields[] = 'price = ?'; $types .= 'd'; $values[] = $price; }
    if ($path !== '') { $fields[] = 'product_path = ?'; $types .= 's'; $values[] = $path; }

    if (count($fields) === 0) {
        echo json_encode(['status' => 'error', 'message' => 'No fields to update']);
        exit;
    }

    $sql = 'UPDATE products SET ' . implode(', ', $fields) . ' WHERE product_id = ? LIMIT 1';
    $types .= 'i';
    $values[] = $id;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    echo json_encode(['status' => 'success', 'message' => 'Product updated']);
} catch (Exception $e) {
    error_log('update_product error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}

?>
