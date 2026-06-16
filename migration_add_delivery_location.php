<?php
/**
 * Migration: Add delivery location columns to orders table
 * 
 * This migration adds support for geolocation-based delivery:
 * - delivery_latitude: DECIMAL(10,8) - Latitude of delivery location
 * - delivery_longitude: DECIMAL(11,8) - Longitude of delivery location
 * 
 * Run this script once via browser or CLI to apply the migration
 */

include 'database.php';

header("Content-Type: application/json");

try {
    $migrations = [
        "ALTER TABLE orders ADD COLUMN delivery_latitude DECIMAL(10,8) NULL DEFAULT NULL",
        "ALTER TABLE orders ADD COLUMN delivery_longitude DECIMAL(11,8) NULL DEFAULT NULL"
    ];

    $applied = [];
    $skipped = [];
    $errors = [];

    foreach ($migrations as $sql) {
        try {
            if ($conn->query($sql)) {
                $applied[] = $sql;
            } else {
                $error = $conn->error;
                // Check if column already exists (1060 error)
                if (strpos($error, "Duplicate column name") !== false || $conn->errno === 1060) {
                    $skipped[] = "Column already exists: " . substr($sql, strpos($sql, "ADD COLUMN") + 11, 20);
                } else {
                    $errors[] = $sql . " - Error: " . $error;
                }
            }
        } catch (Exception $e) {
            $errors[] = $sql . " - Exception: " . $e->getMessage();
        }
    }

    if (count($errors) > 0) {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Migration completed with errors",
            "applied" => $applied,
            "skipped" => $skipped,
            "errors" => $errors
        ]);
    } else {
        echo json_encode([
            "status" => "success",
            "message" => "Migration applied successfully",
            "applied" => count($applied),
            "skipped" => count($skipped),
            "details" => [
                "applied_migrations" => $applied,
                "skipped_migrations" => $skipped
            ]
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Migration failed: " . $e->getMessage()
    ]);
}

$conn->close();
?>
