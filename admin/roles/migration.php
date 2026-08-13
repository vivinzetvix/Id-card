<?php
/**
 * Role Management Module - One-Time Database Migration & Seed Script
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';

function run_role_migration(PDO $pdo): array
{
    $log = [];

    // 1. Create or update roles table
    $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_name VARCHAR(50) NOT NULL UNIQUE,
        description VARCHAR(255) NULL,
        status TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $log[] = "Table 'roles' checked/created.";

    // Ensure status, created_at, updated_at columns exist in roles table
    $stmt = $pdo->query("SHOW COLUMNS FROM roles");
    $roleColumns = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

    if (!in_array('status', $roleColumns, true)) {
        $pdo->exec("ALTER TABLE roles ADD COLUMN status TINYINT(1) DEFAULT 1 AFTER description");
        $log[] = "Added column 'status' to table 'roles'.";
    }
    if (!in_array('created_at', $roleColumns, true)) {
        $pdo->exec("ALTER TABLE roles ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        $log[] = "Added column 'created_at' to table 'roles'.";
    }
    if (!in_array('updated_at', $roleColumns, true)) {
        $pdo->exec("ALTER TABLE roles ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        $log[] = "Added column 'updated_at' to table 'roles'.";
    }

    // 2. Create permissions table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        permission_name VARCHAR(120) NOT NULL,
        module_name VARCHAR(50) NOT NULL,
        description VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_module_perm (module_name, permission_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $log[] = "Table 'permissions' checked/created.";

    // 3. Create role_permissions table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_id INT NOT NULL,
        permission_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_role_perm (role_id, permission_id),
        CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $log[] = "Table 'role_permissions' checked/created.";

    // 4. Seed Default Roles
    $defaultRoles = [
        ['role_name' => 'Super Admin', 'description' => 'Full System Access', 'status' => 1],
        ['role_name' => 'Organization Admin', 'description' => 'Organization Administrator', 'status' => 1],
        ['role_name' => 'Registrar', 'description' => 'Member Registration', 'status' => 1],
    ];

    $roleIdMap = [];
    foreach ($defaultRoles as $r) {
        // Check if role exists by exact name or slug variation
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE LOWER(role_name) = LOWER(?) OR LOWER(role_name) = LOWER(?) LIMIT 1");
        $altName = strtolower(str_replace(' ', '_', $r['role_name']));
        $stmt->execute([$r['role_name'], $altName]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $roleIdMap[$r['role_name']] = (int)$existing['id'];
            // Update name to standard display name if needed
            $upd = $pdo->prepare("UPDATE roles SET role_name = ?, description = ? WHERE id = ?");
            $upd->execute([$r['role_name'], $r['description'], $existing['id']]);
        } else {
            $ins = $pdo->prepare("INSERT INTO roles (role_name, description, status) VALUES (?, ?, ?)");
            $ins->execute([$r['role_name'], $r['description'], $r['status']]);
            $roleIdMap[$r['role_name']] = (int)$pdo->lastInsertId();
            $log[] = "Seeded role: {$r['role_name']}";
        }
    }

    // 5. Seed Default Modules & Permissions
    $modulePermissions = [
        'Dashboard' => ['View', 'Export'],
        'Organizations' => ['View', 'Create', 'Edit', 'Delete', 'Print', 'Export', 'Import'],
        'Users' => ['View', 'Create', 'Edit', 'Delete', 'Print', 'Export', 'Import'],
        'Roles' => ['View', 'Create', 'Edit', 'Delete', 'Print', 'Export', 'Import'],
        'Members' => ['View', 'Create', 'Edit', 'Delete', 'Print', 'Export', 'Import'],
        'Templates' => ['View', 'Create', 'Edit', 'Delete', 'Print', 'Export', 'Import'],
        'Generate ID' => ['View', 'Create', 'Edit', 'Delete', 'Print', 'Export'],
        'Reports' => ['View', 'Print', 'Export'],
        'Settings' => ['View', 'Edit', 'Manage Settings']
    ];

    $permIdMap = [];
    foreach ($modulePermissions as $module => $actions) {
        foreach ($actions as $action) {
            $desc = "{$action} {$module}";
            $stmt = $pdo->prepare("SELECT id FROM permissions WHERE module_name = ? AND permission_name = ? LIMIT 1");
            $stmt->execute([$module, $action]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $permIdMap["{$module}:{$action}"] = (int)$row['id'];
            } else {
                $ins = $pdo->prepare("INSERT INTO permissions (permission_name, module_name, description) VALUES (?, ?, ?)");
                $ins->execute([$action, $module, $desc]);
                $permIdMap["{$module}:{$action}"] = (int)$pdo->lastInsertId();
            }
        }
    }
    $log[] = "Seeded permissions for all 9 default modules.";

    // 6. Assign Default Permissions to Roles
    // Super Admin: All permissions
    $superAdminId = $roleIdMap['Super Admin'] ?? null;
    if ($superAdminId) {
        $insRp = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        foreach ($permIdMap as $pId) {
            $insRp->execute([$superAdminId, $pId]);
        }
        $log[] = "Assigned all permissions to Super Admin role.";
    }

    // Organization Admin: Dashboard, Organizations, Members, Templates, Generate ID, Reports
    $orgAdminId = $roleIdMap['Organization Admin'] ?? null;
    if ($orgAdminId) {
        $orgAdminModules = ['Dashboard', 'Organizations', 'Members', 'Templates', 'Generate ID', 'Reports'];
        $insRp = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        foreach ($modulePermissions as $mod => $actions) {
            if (in_array($mod, $orgAdminModules, true)) {
                foreach ($actions as $act) {
                    if (isset($permIdMap["{$mod}:{$act}"])) {
                        $insRp->execute([$orgAdminId, $permIdMap["{$mod}:{$act}"]]);
                    }
                }
            }
        }
        $log[] = "Assigned default permissions to Organization Admin role.";
    }

    // Registrar: Dashboard, Members, Generate ID
    $registrarId = $roleIdMap['Registrar'] ?? null;
    if ($registrarId) {
        $registrarModules = ['Dashboard', 'Members', 'Generate ID'];
        $insRp = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        foreach ($modulePermissions as $mod => $actions) {
            if (in_array($mod, $registrarModules, true)) {
                foreach ($actions as $act) {
                    if (isset($permIdMap["{$mod}:{$act}"])) {
                        $insRp->execute([$registrarId, $permIdMap["{$mod}:{$act}"]]);
                    }
                }
            }
        }
        $log[] = "Assigned default permissions to Registrar role.";
    }

    return $log;
}

$cliMode = (php_sapi_name() === 'cli');
$results = [];

try {
    $results = run_role_migration($pdo);
} catch (Exception $e) {
    $results[] = "ERROR: " . $e->getMessage();
}

if ($cliMode) {
    echo "=== ROLE MANAGEMENT MIGRATION ===\n";
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
    <title>Role Module Migration</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light py-5">
<div class="container">
    <div class="card shadow-sm max-w-600 mx-auto" style="max-width: 650px;">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0"><i class="fas fa-database me-2"></i>Role Module Database Migration</h4>
        </div>
        <div class="card-body">
            <p class="text-muted">The role management database migration has completed. Log output below:</p>
            <ul class="list-group mb-4">
                <?php foreach ($results as $res): ?>
                    <li class="list-group-item <?= str_contains($res, 'ERROR') ? 'list-group-item-danger' : 'list-group-item-success' ?>">
                        <?= htmlspecialchars($res) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="d-flex justify-content-between">
                <a href="../../dashboard.php" class="btn btn-outline-secondary">Go to Dashboard</a>
                <a href="index.php" class="btn btn-primary">Go to Role Management</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
