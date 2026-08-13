<?php
/**
 * Users Management Module - One-Time Database Migration Script
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';

function run_user_migration(PDO $pdo): array
{
    $log = [];

    // Ensure users table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(120) NULL,
        full_name VARCHAR(120) NULL,
        avatar VARCHAR(255) NULL,
        role VARCHAR(50) DEFAULT 'admin',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $log[] = "Table 'users' checked/created.";

    // Inspect columns in users table
    $stmt = $pdo->query("SHOW COLUMNS FROM users");
    $userColumns = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

    $columnDefs = [
        'organization_id' => "ALTER TABLE users ADD COLUMN organization_id INT NULL AFTER id",
        'role_id'         => "ALTER TABLE users ADD COLUMN role_id INT NULL AFTER organization_id",
        'mobile'          => "ALTER TABLE users ADD COLUMN mobile VARCHAR(20) NULL AFTER email",
        'avatar'          => "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL AFTER mobile",
        'status'          => "ALTER TABLE users ADD COLUMN status TINYINT(1) DEFAULT 1 AFTER role_id",
        'last_login'      => "ALTER TABLE users ADD COLUMN last_login DATETIME NULL AFTER status",
        'deleted_at'      => "ALTER TABLE users ADD COLUMN deleted_at TIMESTAMP NULL AFTER last_login",
        'updated_at'      => "ALTER TABLE users ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    ];

    foreach ($columnDefs as $colName => $alterSql) {
        if (!in_array($colName, $userColumns, true)) {
            try {
                $pdo->exec($alterSql);
                $log[] = "Added column '{$colName}' to 'users' table.";
            } catch (Throwable $e) {
                $log[] = "Warning adding '{$colName}': " . $e->getMessage();
            }
        }
    }

    // Ensure foreign key constraints if roles & organizations exist
    try {
        // Find Super Admin role ID
        $superAdminRoleId = (int)$pdo->query("SELECT id FROM roles WHERE LOWER(role_name) IN ('super admin', 'super_admin') LIMIT 1")->fetchColumn();
        if ($superAdminRoleId) {
            $pdo->exec("UPDATE users SET role_id = {$superAdminRoleId} WHERE username = 'admin' AND (role_id IS NULL OR role_id = 0)");
            $log[] = "Linked default 'admin' user to Super Admin role ID {$superAdminRoleId}.";
        }
    } catch (Throwable $e) {
        // Non-critical
    }

    return $log;
}

$cliMode = (php_sapi_name() === 'cli');
$results = [];

try {
    $results = run_user_migration($pdo);
} catch (Exception $e) {
    $results[] = "ERROR: " . $e->getMessage();
}

if ($cliMode) {
    echo "=== USERS MANAGEMENT MIGRATION ===\n";
    foreach ($results as $msg) {
        echo "- {$msg}\n";
    }
    echo "Done.\n";
    exit(0);
}

// Web Output
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Module Migration</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light py-5">
<div class="container">
    <div class="card shadow-sm max-w-600 mx-auto" style="max-width: 650px;">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0"><i class="fas fa-users me-2"></i>Users Module Database Migration</h4>
        </div>
        <div class="card-body">
            <p class="text-muted">The users management database migration has completed. Log output below:</p>
            <ul class="list-group mb-4">
                <?php foreach ($results as $res): ?>
                    <li class="list-group-item <?= str_contains($res, 'ERROR') ? 'list-group-item-danger' : 'list-group-item-success' ?>">
                        <?= htmlspecialchars($res) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="d-flex justify-content-between">
                <a href="../../dashboard.php" class="btn btn-outline-secondary">Go to Dashboard</a>
                <a href="index.php" class="btn btn-primary">Go to Users Management</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
