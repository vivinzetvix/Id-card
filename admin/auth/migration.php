<?php
/**
 * Auth Module - Database Migration (Run Once)
 * Creates: login_history, failed_logins tables
 *
 * Run via CLI: php admin/auth/migration.php
 * Or visit in browser once: http://localhost/id/admin/auth/migration.php
 */

// CLI check — skip session when running from terminal
if (php_sapi_name() !== 'cli') {
    session_start();
    if (empty($_SESSION['logged_in'])) {
        http_response_code(403);
        die('Access denied. Please log in as an administrator to run this migration.');
    }
}

require_once __DIR__ . '/../../config.php';

$results = [];

// ─── 1. login_history ─────────────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_history (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id      INT UNSIGNED NULL,
            username     VARCHAR(100) NOT NULL,
            ip_address   VARCHAR(45)  NOT NULL DEFAULT '',
            user_agent   TEXT         NULL,
            browser      VARCHAR(100) NULL,
            os           VARCHAR(100) NULL,
            login_time   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            logout_time  TIMESTAMP    NULL,
            status       ENUM('success','failed','locked') NOT NULL DEFAULT 'success',
            INDEX idx_user_id   (user_id),
            INDEX idx_username  (username),
            INDEX idx_login_time(login_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $results[] = ['table' => 'login_history', 'status' => 'OK', 'message' => 'Table created or already exists.'];
} catch (PDOException $e) {
    $results[] = ['table' => 'login_history', 'status' => 'ERROR', 'message' => $e->getMessage()];
}

// ─── 2. failed_logins ─────────────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS failed_logins (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username     VARCHAR(100) NOT NULL,
            ip_address   VARCHAR(45)  NOT NULL DEFAULT '',
            attempt_time TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_username   (username),
            INDEX idx_ip_address (ip_address),
            INDEX idx_attempt_time(attempt_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $results[] = ['table' => 'failed_logins', 'status' => 'OK', 'message' => 'Table created or already exists.'];
} catch (PDOException $e) {
    $results[] = ['table' => 'failed_logins', 'status' => 'ERROR', 'message' => $e->getMessage()];
}

// ─── 3. Ensure password_reset_tokens table ────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            token      VARCHAR(128) NOT NULL UNIQUE,
            expires_at TIMESTAMP    NOT NULL,
            used       TINYINT(1)   NOT NULL DEFAULT 0,
            created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token    (token),
            INDEX idx_user_id  (user_id),
            INDEX idx_expires  (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $results[] = ['table' => 'password_reset_tokens', 'status' => 'OK', 'message' => 'Table created or already exists.'];
} catch (PDOException $e) {
    $results[] = ['table' => 'password_reset_tokens', 'status' => 'ERROR', 'message' => $e->getMessage()];
}

// ─── 4. Add last_login column to users (if missing) ──────────────────────────
try {
    $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_login'")->fetch();
    if (!$check) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_login TIMESTAMP NULL AFTER status");
        $results[] = ['table' => 'users.last_login', 'status' => 'OK', 'message' => 'Column added.'];
    } else {
        $results[] = ['table' => 'users.last_login', 'status' => 'OK', 'message' => 'Column already exists.'];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'users.last_login', 'status' => 'WARN', 'message' => $e->getMessage()];
}

// ─── Output ───────────────────────────────────────────────────────────────────

if (php_sapi_name() === 'cli') {
    foreach ($results as $r) {
        printf("[%s] %-35s %s\n", $r['status'], $r['table'], $r['message']);
    }
    echo "\nAuth migration complete.\n";
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Auth Migration · ID Card System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f3f4f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,.08); padding: 2rem; max-width: 600px; width: 100%; }
        h2 { color: #0a1a2f; margin-bottom: 1.5rem; display: flex; align-items: center; gap: .5rem; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; padding: .75rem 1rem; text-align: left; }
        td { padding: .75rem 1rem; border-bottom: 1px solid #e5e7eb; font-size: .875rem; }
        tr:last-child td { border-bottom: none; }
        .ok { color: #059669; font-weight: 600; }
        .error { color: #dc2626; font-weight: 600; }
        .warn { color: #d97706; font-weight: 600; }
        .back { display: inline-block; margin-top: 1.5rem; padding: .75rem 1.5rem; background: #0a1a2f; color: white; text-decoration: none; border-radius: 8px; font-size: .875rem; }
        .back:hover { background: #1e3a5f; }
    </style>
</head>
<body>
    <div class="card">
        <h2><i class="fas fa-database" style="color:#e53e3e"></i> Auth Module — Migration Results</h2>
        <table>
            <thead>
                <tr><th>Table / Column</th><th>Status</th><th>Details</th></tr>
            </thead>
            <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($r['table']) ?></code></td>
                        <td class="<?= strtolower($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></td>
                        <td><?= htmlspecialchars($r['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <a href="../../dashboard.php" class="back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>
</body>
</html>
