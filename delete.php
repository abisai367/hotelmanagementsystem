<?php
include 'database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

if (!$conn) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// Support JSON payload for DELETE and POST bodies
$body = json_decode(file_get_contents('php://input'), true);
$id = $_POST['id'] ?? $body['id'] ?? null;
$product_name = $_POST['product_name'] ?? $body['product_name'] ?? null;
$product_path = $_POST['product_path'] ?? $body['product_path'] ?? null;

if (!$id && !$product_name && !$product_path) {
    echo json_encode(['status' => 'error', 'message' => 'Missing deletion identifier']);
    exit;
}

$selectSql = '';
$params = [];
$paramTypes = '';

if ($id) {
    $selectSql = 'SELECT product_path FROM products WHERE id = ?';
    $params = [$id];
    $paramTypes = 'i';
} elseif ($product_name) {
    $selectSql = 'SELECT product_path FROM products WHERE LOWER(product_name) = LOWER(?)';
    $params = [$product_name];
    $paramTypes = 's';
} else {
    $selectSql = 'SELECT product_path FROM products WHERE product_path = ?';
    $params = [$product_path];
    $paramTypes = 's';
}

$stmt = mysqli_prepare($conn, $selectSql);
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, $paramTypes, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$product = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$product) {
    echo json_encode(['status' => 'error', 'message' => 'Product not found']);
    exit;
}

$deleteSql = '';
if ($id) {
    $deleteSql = 'DELETE FROM products WHERE id = ?';
} elseif ($product_name) {
    $deleteSql = 'DELETE FROM products WHERE LOWER(product_name) = LOWER(?)';
} else {
    $deleteSql = 'DELETE FROM products WHERE product_path = ?';
}

$stmt = mysqli_prepare($conn, $deleteSql);
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, $paramTypes, ...$params);
if (!mysqli_stmt_execute($stmt)) {
    echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
    mysqli_stmt_close($stmt);
    exit;
}

mysqli_stmt_close($stmt);

if (!empty($product['product_path'])) {
    $filePath = __DIR__ . '/../public/uploads/products/' . basename($product['product_path']);
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
}

echo json_encode(['status' => 'success', 'message' => 'Product deleted successfully']);
exit;
