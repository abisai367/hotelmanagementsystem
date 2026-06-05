<?php
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

$conn = null;
include 'database.php';
/** @var mysqli $conn */
if (!isset($conn) || !$conn) { error_log('delete: missing DB connection'); http_response_code(500); echo json_encode(['status'=>'error','message'=>'Database connection unavailable']); exit; }

$cloudinaryCloudName = getenv('CLOUDINARY_CLOUD_NAME') ?: 'hotel_cloud';
$cloudinaryApiKey = getenv('CLOUDINARY_API_KEY') ?: '';
$cloudinaryApiSecret = getenv('CLOUDINARY_API_SECRET') ?: '';

function getCloudinaryPublicId($url) {
    $parsedPath = parse_url($url, PHP_URL_PATH);
    if (!$parsedPath) {
        return null;
    }

    if (preg_match('#/upload/(?:v\d+/)?(.+)$#', $parsedPath, $matches)) {
        $publicId = $matches[1];
        $publicId = preg_replace('/\.[^\.]+$/', '', $publicId);
        return $publicId;
    }

    return null;
}

function destroyCloudinaryImage($cloudName, $apiKey, $apiSecret, $publicId) {
    if (empty($cloudName) || empty($apiKey) || empty($apiSecret) || empty($publicId)) {
        return false;
    }

    $url = "https://api.cloudinary.com/v1_1/{$cloudName}/image/destroy";
    $payload = http_build_query([
        'public_id' => $publicId,
        'invalidate' => 'true',
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_USERPWD, "{$apiKey}:{$apiSecret}");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $statusCode === 200;
}

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
    error_log('delete.php select error: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
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
    error_log('delete.php delete prepare error: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    exit;
}

mysqli_stmt_bind_param($stmt, $paramTypes, ...$params);
if (!mysqli_stmt_execute($stmt)) {
    error_log('delete.php delete execute error: ' . $stmt->error);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    mysqli_stmt_close($stmt);
    exit;
}

mysqli_stmt_close($stmt);

if (!empty($product['product_path'])) {
    $publicId = getCloudinaryPublicId($product['product_path']);
    if ($publicId && $cloudinaryApiKey && $cloudinaryApiSecret) {
        destroyCloudinaryImage($cloudinaryCloudName, $cloudinaryApiKey, $cloudinaryApiSecret, $publicId);
    }
}

echo json_encode(['status' => 'success', 'message' => 'Product deleted successfully']);
exit;
