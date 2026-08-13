<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config.php';

if (!function_exists('ensure_organization_schema')) {
    function ensure_organization_schema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS organizations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            organization_name VARCHAR(150) NOT NULL,
            organization_code VARCHAR(50) UNIQUE,
            logo VARCHAR(255) NULL,
            address TEXT NULL,
            phone VARCHAR(20) NULL,
            email VARCHAR(100) NULL,
            website VARCHAR(150) NULL,
            organization_type ENUM('school','college','company','government','hospital','ngo','other') DEFAULT 'company',
            project_type ENUM('residence','corporate') DEFAULT 'corporate',
            status TINYINT(1) DEFAULT 1,
            created_by INT NULL,
            updated_by INT NULL,
            deleted_by INT NULL,
            deleted_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $tables = ['organizations' => 'organizations', 'id_members' => 'id_members', 'card_templates' => 'card_templates', 'generated_cards' => 'generated_cards'];
        foreach ($tables as $tableName => $tableLabel) {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}`");
            $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

            if ($tableName === 'organizations') {
                foreach (['created_by', 'updated_by', 'deleted_by', 'deleted_at'] as $columnName) {
                    if (!in_array($columnName, $columns, true)) {
                        $pdo->exec("ALTER TABLE `{$tableName}` ADD COLUMN `{$columnName}` " . ($columnName === 'deleted_at' ? 'TIMESTAMP NULL' : 'INT NULL'));
                    }
                }
            }

            if ($tableName === 'id_members' && !in_array('organization_id', $columns, true)) {
                $pdo->exec("ALTER TABLE `{$tableName}` ADD COLUMN organization_id INT NULL");
            }

            if ($tableName === 'card_templates' && !in_array('organization_id', $columns, true)) {
                $pdo->exec("ALTER TABLE `{$tableName}` ADD COLUMN organization_id INT NULL");
            }

            if ($tableName === 'generated_cards' && !in_array('organization_id', $columns, true)) {
                $pdo->exec("ALTER TABLE `{$tableName}` ADD COLUMN organization_id INT NULL");
            }
        }
    }
}

if (!function_exists('require_admin_access')) {
    function require_admin_access(PDO $pdo): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['logged_in']) || empty($_SESSION['username'])) {
            header('Location: ../index.php');
            exit();
        }

        $userName = $_SESSION['username'];
        $stmt = $pdo->prepare("SELECT role FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$userName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !in_array(strtolower((string)($row['role'] ?? '')), ['admin', 'super_admin', 'organization_admin'], true)) {
            header('Location: ../dashboard.php');
            exit();
        }
    }
}

if (!function_exists('organization_project_type_is_valid')) {
    function organization_project_type_is_valid(string $projectType): bool
    {
        return in_array(strtolower(trim($projectType)), ['residence', 'corporate'], true);
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(PDO $pdo): ?int
    {
        if (!empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }

        $username = $_SESSION['username'] ?? '';
        if ($username === '') {
            return null;
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }
}

if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token(): string
    {
        if (empty($_SESSION['organization_csrf_token']) && empty($_SESSION['csrf_token'])) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['organization_csrf_token'] = $token;
            $_SESSION['csrf_token'] = $token;
        }

        if (empty($_SESSION['organization_csrf_token']) && !empty($_SESSION['csrf_token'])) {
            $_SESSION['organization_csrf_token'] = $_SESSION['csrf_token'];
        }

        if (empty($_SESSION['csrf_token']) && !empty($_SESSION['organization_csrf_token'])) {
            $_SESSION['csrf_token'] = $_SESSION['organization_csrf_token'];
        }

        return $_SESSION['organization_csrf_token'];
    }
}

if (!function_exists('validate_csrf_token')) {
    function validate_csrf_token(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $organizationToken = $_SESSION['organization_csrf_token'] ?? '';
        $csrfToken = $_SESSION['csrf_token'] ?? '';

        if ($organizationToken !== '' && hash_equals($organizationToken, $token)) {
            return true;
        }

        if ($csrfToken !== '' && hash_equals($csrfToken, $token)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('log_organization_activity')) {
    function log_organization_activity(PDO $pdo, string $action, string $actionType, string $details, ?int $organizationId = null): void
    {
        $userId = get_current_user_id($pdo);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, action_type, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $actionType, $details, $ip, $ua]);
    }
}

if (!function_exists('get_organization_logo_path')) {
    function get_organization_logo_path(?string $logoName): string
    {
        if (empty($logoName)) {
            return 'assets/uploads/logo/default.svg';
        }

        $candidate = __DIR__ . '/assets/uploads/logo/' . basename($logoName);
        if (file_exists($candidate)) {
            return 'assets/uploads/logo/' . basename($logoName);
        }

        return 'assets/uploads/logo/default.svg';
    }
}

if (!function_exists('upload_organization_logo')) {
    function upload_organization_logo(array $file, string $targetDir): array
    {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'No logo file was uploaded.'];
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            return ['success' => false, 'message' => 'Only JPG, JPEG, PNG, and WEBP files are allowed.'];
        }

        if ($file['size'] > 2 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Logo must be 2MB or smaller.'];
        }

        $dir = rtrim($targetDir, DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $newName = 'org_' . uniqid('', true) . '.' . $ext;
        $destination = $dir . DIRECTORY_SEPARATOR . $newName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['success' => false, 'message' => 'The logo could not be uploaded.'];
        }

        return ['success' => true, 'file' => $newName, 'path' => $destination];
    }
}

if (!function_exists('delete_logo_file')) {
    function delete_logo_file(?string $logoName): void
    {
        if (empty($logoName)) {
            return;
        }

        $file = __DIR__ . '/assets/uploads/logo/' . basename($logoName);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

if (!function_exists('get_organization_counts')) {
    function get_organization_counts(PDO $pdo): array
    {
        $total = $pdo->query("SELECT COUNT(*) FROM organizations WHERE deleted_at IS NULL")->fetchColumn();
        $active = $pdo->query("SELECT COUNT(*) FROM organizations WHERE deleted_at IS NULL AND status = 1")->fetchColumn();
        $inactive = $pdo->query("SELECT COUNT(*) FROM organizations WHERE deleted_at IS NULL AND status = 0")->fetchColumn();

        return [
            'total' => (int)$total,
            'active' => (int)$active,
            'inactive' => (int)$inactive,
        ];
    }
}

if (!function_exists('get_organization_status_badge')) {
    function get_organization_status_badge(int $status): string
    {
        return $status === 1
            ? '<span class="badge bg-success">Active</span>'
            : '<span class="badge bg-secondary">Inactive</span>';
    }
}

ensure_organization_schema($pdo);
?>
