<?php
function tableExists(mysqli $conn, string $table): bool {
    $table = mysqli_real_escape_string($conn, $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '{$table}'");
    return $res && mysqli_num_rows($res) > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && mysqli_num_rows($res) > 0;
}

function addColumnIfMissing(mysqli $conn, string $table, string $column, string $definition): void {
    if (!columnExists($conn, $table, $column)) {
        mysqli_query($conn, "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function hotelStaffRoles(): array {
    return ['waiter', 'cooks', 'security', 'delivery person', 'supervisor', 'manager', 'admin'];
}

function payrollStaffRoles(): array {
    return ['waiter', 'cooks', 'security', 'delivery person', 'supervisor', 'manager'];
}

function normalizeHotelRole(?string $role): string {
    $value = strtolower(trim((string)$role));
    $value = preg_replace('/\s+/', ' ', $value);

    $map = [
        'employee' => 'waiter',
        'waitress' => 'waiter',
        'waiter' => 'waiter',
        'cook' => 'cooks',
        'cooks' => 'cooks',
        'chef' => 'cooks',
        'security' => 'security',
        'guard' => 'security',
        'delivery' => 'delivery person',
        'delivery guy' => 'delivery person',
        'delivery person' => 'delivery person',
        'rider' => 'delivery person',
        'supervisor' => 'supervisor',
        'manager' => 'manager',
        'admin' => 'admin',
        'administrator' => 'admin',
    ];

    return $map[$value] ?? $value;
}

function roleSqlList(mysqli $conn, array $roles): string {
    $quoted = array_map(fn($role) => "'" . mysqli_real_escape_string($conn, $role) . "'", $roles);
    return implode(',', $quoted);
}

function ensureCoreSchema(mysqli $conn): void {
    if (tableExists($conn, 'users')) {
        addColumnIfMissing($conn, 'users', 'salary', 'DECIMAL(10,2) NOT NULL DEFAULT 0');
        addColumnIfMissing($conn, 'users', 'email', 'VARCHAR(255) NULL DEFAULT NULL');
    }

    if (!tableExists($conn, 'password_resets')) {
        mysqli_query($conn, "CREATE TABLE password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            email VARCHAR(255) NOT NULL,
            otp_code VARCHAR(10) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_resets_user_id (user_id),
            INDEX idx_password_resets_email (email),
            INDEX idx_password_resets_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (tableExists($conn, 'orders')) {
        addColumnIfMissing($conn, 'orders', 'amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0');
        addColumnIfMissing($conn, 'orders', 'phone_number', 'VARCHAR(15) NULL DEFAULT NULL');
        addColumnIfMissing($conn, 'orders', 'table_number', 'INT(7) NULL DEFAULT NULL');
        addColumnIfMissing($conn, 'orders', 'payment_status', "VARCHAR(30) NOT NULL DEFAULT 'Pending'");
        addColumnIfMissing($conn, 'orders', 'checkout_request_id', 'VARCHAR(100) NULL DEFAULT NULL');
        addColumnIfMissing($conn, 'orders', 'transaction_date', 'DATETIME NULL DEFAULT NULL');
        addColumnIfMissing($conn, 'orders', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

        @mysqli_query($conn, "ALTER TABLE orders MODIFY product_id INT(11) NULL DEFAULT NULL");
        @mysqli_query($conn, "ALTER TABLE orders MODIFY quantity INT(6) NULL DEFAULT NULL");
        @mysqli_query($conn, "ALTER TABLE orders MODIFY order_type VARCHAR(30) NOT NULL DEFAULT 'dineIn'");

        if (columnExists($conn, 'orders', 'table_no') && columnExists($conn, 'orders', 'table_number')) {
            @mysqli_query($conn, "UPDATE orders SET table_number = table_no WHERE table_number IS NULL AND table_no IS NOT NULL");
        }
    }

    if (!tableExists($conn, 'order_items')) {
        mysqli_query($conn, "CREATE TABLE order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_items_order (order_id),
            INDEX idx_order_items_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tableExists($conn, 'work_assignments')) {
        mysqli_query($conn, "CREATE TABLE work_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            employee_id INT NOT NULL,
            work_type VARCHAR(30) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'assigned',
            assigned_by VARCHAR(30) NOT NULL DEFAULT 'system',
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL DEFAULT NULL,
            notes VARCHAR(255) NULL DEFAULT NULL,
            UNIQUE KEY uniq_order_work (order_id, work_type),
            INDEX idx_work_employee (employee_id),
            INDEX idx_work_type_status (work_type, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tableExists($conn, 'payroll_batches')) {
        mysqli_query($conn, "CREATE TABLE payroll_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NULL DEFAULT NULL,
            payment_phone VARCHAR(15) NOT NULL,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            checkout_request_id VARCHAR(100) NULL DEFAULT NULL,
            merchant_request_id VARCHAR(100) NULL DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Pending',
            mpesa_receipt_number VARCHAR(50) NULL DEFAULT NULL,
            transaction_date DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME NULL DEFAULT NULL,
            INDEX idx_payroll_checkout (checkout_request_id),
            INDEX idx_payroll_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tableExists($conn, 'salary_payments')) {
        mysqli_query($conn, "CREATE TABLE salary_payments (
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
}

function normalizeExistingRoles(mysqli $conn): void {
    if (!tableExists($conn, 'users') || !columnExists($conn, 'users', 'role')) {
        return;
    }

    mysqli_query($conn, "UPDATE users SET role = 'waiter' WHERE LOWER(role) = 'employee'");
    mysqli_query($conn, "UPDATE users SET role = 'supervisor' WHERE LOWER(role) = 'supervisor'");
    mysqli_query($conn, "UPDATE users SET role = 'admin' WHERE LOWER(role) = 'admin'");
}

function tableColumnExpression(mysqli $conn): string {
    if (columnExists($conn, 'orders', 'table_number') && columnExists($conn, 'orders', 'table_no')) {
        return 'COALESCE(o.table_number, o.table_no)';
    }
    if (columnExists($conn, 'orders', 'table_number')) {
        return 'o.table_number';
    }
    if (columnExists($conn, 'orders', 'table_no')) {
        return 'o.table_no';
    }
    return 'NULL';
}

function attendedColumn(mysqli $conn): ?string {
    foreach (['attended_to', 'Attended_to', 'Attended to'] as $column) {
        if (columnExists($conn, 'orders', $column)) {
            return $column;
        }
    }
    return null;
}

function loadEnvFile(string $path): array {
    if (!file_exists($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_contains($line, '=')) {
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            $value = preg_replace('/^(["\'])(.*)\1$/s', '$2', $value);
            $env[$name] = $value;
        }
    }
    return $env;
}

function getEnvValue(string $name): ?string {
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return $value;
    }

    $fallbackKeys = [
        'BREVO_API_KEY' => ['BREVO_API_KEY', 'BREVO_KEY', 'BREVO'],
        'BREVO' => ['BREVO_API_KEY', 'BREVO_KEY', 'BREVO'],
        'BREVO_SENDER_EMAIL' => ['BREVO_SENDER_EMAIL', 'BREVO_SENDER', 'SENDER_EMAIL'],
        'BREVO_SENDER_NAME' => ['BREVO_SENDER_NAME', 'BREVO_SENDER', 'SENDER_NAME'],
    ];

    $keysToCheck = [$name];
    if (isset($fallbackKeys[$name])) {
        $keysToCheck = $fallbackKeys[$name];
    }

    $root = realpath(__DIR__ . '/../');
    foreach (['.env.local', '.env.production', '.env'] as $fileName) {
        $path = $root . DIRECTORY_SEPARATOR . $fileName;
        $values = loadEnvFile($path);
        foreach ($keysToCheck as $key) {
            if (isset($values[$key]) && $values[$key] !== '') {
                return $values[$key];
            }
        }
    }

    return null;
}

function sendBrevoEmail(string $recipientEmail, string $subject, string $htmlContent): array {
    $apiKey = getEnvValue('BREVO_API_KEY');
    if (!$apiKey) {
        return ['success' => false, 'message' => 'Brevo API key is missing from the environment.'];
    }

    $senderEmail = getEnvValue('BREVO_SENDER_EMAIL') ?: 'no-reply@hotelmanagementsystem.local';
    $senderName = getEnvValue('BREVO_SENDER_NAME') ?: 'Hotel Management System';

    $payload = [
        'sender' => ['name' => $senderName, 'email' => $senderEmail],
        'to' => [[
            'email' => $recipientEmail,
            'name' => $recipientEmail,
        ]],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
    ];

    $url = 'https://api.brevo.com/v3/smtp/email';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
        'api-key: ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $responseBody = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false || $curlError) {
        return ['success' => false, 'message' => 'Brevo request failed: ' . $curlError, 'http_status' => $httpStatus, 'response' => $responseBody];
    }

    $parsed = json_decode($responseBody, true);
    if ($httpStatus >= 200 && $httpStatus < 300) {
        return ['success' => true, 'data' => $parsed];
    }

    return ['success' => false, 'message' => $parsed['message'] ?? 'Brevo email send failed', 'http_status' => $httpStatus, 'response' => $parsed ?? $responseBody];
}

function generateOtp(int $digits = 6): string {
    $max = (int) str_repeat('9', $digits);
    return str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);
}

function setOrderAttended(mysqli $conn, int $orderId, string $value): void {
    foreach (['attended_to', 'Attended_to', 'Attended to'] as $column) {
        if (columnExists($conn, 'orders', $column)) {
            $stmt = $conn->prepare("UPDATE orders SET `{$column}` = ? WHERE order_id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $value, $orderId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

function rangeCondition(string $range, string $field = 'wa.assigned_at'): string {
    if ($range === 'today' || $range === 'day') {
        return "DATE({$field}) = CURDATE()";
    }
    if ($range === 'week') {
        return "DATE({$field}) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
    }
    if ($range === 'month') {
        return "DATE({$field}) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)";
    }
    return "YEAR({$field}) = YEAR(CURDATE())";
}

function orderTypeWhere(string $workType): string {
    if ($workType === 'delivery') {
        return "LOWER(o.order_type) = 'delivery'";
    }
    return "LOWER(REPLACE(REPLACE(REPLACE(o.order_type, '-', ''), ' ', ''), '\"', '')) = 'dinein'";
}

function rebalanceAssignments(mysqli $conn, string $workType): array {
    ensureCoreSchema($conn);

    // Check if necessary tables exist
    if (!tableExists($conn, 'work_assignments') || !tableExists($conn, 'orders') || !tableExists($conn, 'users')) {
        return ['assigned' => 0, 'employees' => 0];
    }

    $role = $workType === 'delivery' ? 'delivery person' : 'waiter';
    $roleEsc = mysqli_real_escape_string($conn, $role);
    $employees = [];
    $empRes = mysqli_query($conn, "SELECT id FROM users WHERE LOWER(role) = '{$roleEsc}' ORDER BY id ASC");
    while ($empRes && ($row = mysqli_fetch_assoc($empRes))) {
        $employees[] = intval($row['id']);
    }

    if (count($employees) === 0) {
        return ['assigned' => 0, 'employees' => 0];
    }

    $attended = attendedColumn($conn);
    $attendedWhere = '';
    
    if ($attended) {
        $attendedEsc = mysqli_real_escape_string($conn, $attended);
        // Simplified logic to avoid query errors
        $attendedWhere = "AND COALESCE(o.`{$attendedEsc}`, 'No') <> 'Yes'";
    }
    
    $tableExpr = tableColumnExpression($conn);
    $typeWhere = orderTypeWhere($workType);
    $sort = $workType === 'dineIn'
        ? "CAST(COALESCE({$tableExpr}, 0) AS UNSIGNED), o.order_id"
        : "o.order_id";

    $orders = [];
    $sql = "SELECT o.order_id FROM orders o
        WHERE {$typeWhere}
        AND COALESCE(o.payment_status, 'Pending') <> 'Failed'
        {$attendedWhere}
        ORDER BY {$sort}";
    
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        error_log('rebalanceAssignments query error: ' . mysqli_error($conn) . ' SQL: ' . $sql);
        return ['assigned' => 0, 'employees' => count($employees)];
    }
    
    while ($row = mysqli_fetch_assoc($res)) {
        $orders[] = intval($row['order_id']);
    }

    $totalOrders = count($orders);
    if ($totalOrders === 0) {
        return ['assigned' => 0, 'employees' => count($employees)];
    }

    $assigned = 0;
    $employeeCount = count($employees);
    foreach ($orders as $index => $orderId) {
        $employeeIndex = (int)floor(($index * $employeeCount) / $totalOrders);
        $employeeId = $employees[min($employeeIndex, $employeeCount - 1)];

        $existing = null;
        $check = $conn->prepare("SELECT id, assigned_by FROM work_assignments WHERE order_id = ? AND work_type = ? LIMIT 1");
        if ($check) {
            $check->bind_param('is', $orderId, $workType);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            $check->close();
        }

        if ($existing && $existing['assigned_by'] === 'supervisor') {
            continue;
        }

        if ($existing) {
            $stmt = $conn->prepare("UPDATE work_assignments SET employee_id = ?, status = 'assigned', assigned_by = 'system' WHERE id = ?");
            if ($stmt) {
                $id = intval($existing['id']);
                $stmt->bind_param('ii', $employeeId, $id);
                $stmt->execute();
                $stmt->close();
                $assigned++;
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO work_assignments (order_id, employee_id, work_type, status, assigned_by) VALUES (?, ?, ?, 'assigned', 'system')");
            if ($stmt) {
                $stmt->bind_param('iis', $orderId, $employeeId, $workType);
                $stmt->execute();
                $stmt->close();
                $assigned++;
            }
        }
    }

    return ['assigned' => $assigned, 'employees' => count($employees)];
}

function salaryStatusForEmployee(mysqli $conn, int $employeeId): array {
    ensureCoreSchema($conn);
    $stmt = $conn->prepare("SELECT payment_status, salary_amount, paid_at, created_at
        FROM salary_payments
        WHERE employee_id = ?
        AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        ORDER BY id DESC
        LIMIT 1");
    if (!$stmt) {
        return ['status' => 'Unpaid', 'paid_at' => null, 'salary_amount' => 0];
    }
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['status' => 'Unpaid', 'paid_at' => null, 'salary_amount' => 0];
    }

    return [
        'status' => $row['payment_status'] ?: 'Unpaid',
        'paid_at' => $row['paid_at'],
        'salary_amount' => (float)$row['salary_amount'],
    ];
}
?>
