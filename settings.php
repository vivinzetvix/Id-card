<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}
require_once 'config.php';

$message = '';
$error = '';
$backup_dir = __DIR__ . DIRECTORY_SEPARATOR . 'backups';

define('MAX_RESTORE_FILE_SIZE', 20 * 1024 * 1024); // 20MB
define('MAX_RESTORE_STATEMENTS', 25000);

// Check if user is admin
$is_admin = ($_SESSION['username'] === 'admin' || $_SESSION['role'] === 'Super Admin' || $_SESSION['role'] === 'admin');

// Helper Functions
function startsWith($haystack, $needle) {
    return strpos($haystack, $needle) === 0;
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function getCurrentUserId($conn, $username) {
    if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }

    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    if (!$stmt) return null;

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_id = null;

    if ($result && $result->num_rows > 0) {
        $user_id = (int)$result->fetch_assoc()['id'];
    }

    $stmt->close();
    return $user_id;
}

function logAuditActivity($conn, $username, $action, $action_type, $details) {
    $user_id = getCurrentUserId($conn, $username);
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, action_type, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("isssss", $user_id, $action, $action_type, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }
}

function isAllowedRestoreStatement($sql) {
    $normalized = strtoupper(ltrim($sql));

    $blocked_patterns = [
        'INTO OUTFILE',
        'INTO DUMPFILE',
        'LOAD_FILE(',
        'LOAD DATA INFILE',
        'GRANT ',
        'REVOKE ',
        'CREATE USER',
        'ALTER USER',
        'DROP USER'
    ];

    foreach ($blocked_patterns as $pattern) {
        if (strpos($normalized, $pattern) !== false) {
            return false;
        }
    }

    $allowed_prefixes = [
        'SET ',
        'START TRANSACTION',
        'COMMIT',
        'ROLLBACK',
        'LOCK TABLES',
        'UNLOCK TABLES',
        'CREATE TABLE',
        'DROP TABLE',
        'ALTER TABLE',
        'TRUNCATE TABLE',
        'INSERT INTO',
        'UPDATE ',
        'DELETE FROM'
    ];

    foreach ($allowed_prefixes as $prefix) {
        if (startsWith($normalized, $prefix)) {
            return true;
        }
    }

    return false;
}

function extractCreateTableName($sql) {
    $pattern = '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`[^`]+`\.)?`?([a-zA-Z0-9_]+)`?/i';
    if (preg_match($pattern, $sql, $matches)) {
        return $matches[1];
    }
    return null;
}

function executeSqlRestoreFile($conn, $sql_file_path, $max_statements = MAX_RESTORE_STATEMENTS) {
    $handle = fopen($sql_file_path, 'rb');
    if (!$handle) {
        return ['success' => false, 'executed' => 0, 'error' => 'Unable to read uploaded file.'];
    }

    $statement = '';
    $executed = 0;
    $in_multiline_comment = false;

    while (($line = fgets($handle)) !== false) {
        $trimmed_left = ltrim($line);
        $trimmed = trim($line);

        if ($in_multiline_comment) {
            if (strpos($trimmed_left, '*/') !== false) {
                $in_multiline_comment = false;
            }
            continue;
        }

        if ($trimmed === '' || startsWith($trimmed_left, '--') || startsWith($trimmed_left, '#')) {
            continue;
        }

        if (startsWith($trimmed_left, '/*')) {
            if (strpos($trimmed_left, '*/') === false) {
                $in_multiline_comment = true;
            }
            continue;
        }

        $statement .= $line;

        if (substr(rtrim($line), -1) === ';') {
            $sql = trim($statement);
            $statement = '';

            if ($sql === '') continue;

            if (!isAllowedRestoreStatement($sql)) {
                fclose($handle);
                return ['success' => false, 'executed' => $executed, 'error' => 'Blocked unsupported or unsafe SQL command in backup file.'];
            }

            if (startsWith(strtoupper(ltrim($sql)), 'CREATE TABLE')) {
                $table_name = extractCreateTableName($sql);
                if ($table_name !== null) {
                    $conn->query("DROP TABLE IF EXISTS `{$table_name}`");
                }
            }

            if (!$conn->query($sql)) {
                $db_error = $conn->error;
                fclose($handle);
                return ['success' => false, 'executed' => $executed, 'error' => 'Restore failed while executing SQL: ' . $db_error];
            }

            $executed++;

            if ($executed > $max_statements) {
                fclose($handle);
                return ['success' => false, 'executed' => $executed, 'error' => 'Restore stopped: too many SQL statements in file.'];
            }
        }
    }

    fclose($handle);

    if (trim($statement) !== '') {
        $sql = trim($statement);
        if (!isAllowedRestoreStatement($sql)) {
            return ['success' => false, 'executed' => $executed, 'error' => 'Blocked unsupported or unsafe SQL command in backup file.'];
        }

        if (startsWith(strtoupper(ltrim($sql)), 'CREATE TABLE')) {
            $table_name = extractCreateTableName($sql);
            if ($table_name !== null) {
                $conn->query("DROP TABLE IF EXISTS `{$table_name}`");
            }
        }

        if (!$conn->query($sql)) {
            return ['success' => false, 'executed' => $executed, 'error' => 'Restore failed while executing SQL: ' . $conn->error];
        }
        $executed++;
    }

    return ['success' => true, 'executed' => $executed, 'error' => null];
}

// Create settings tables
$conn->query("
    CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        setting_type ENUM('text','number','boolean','json','color') DEFAULT 'text',
        description TEXT,
        updated_by VARCHAR(50),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS backup_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        file_size INT,
        tables TEXT,
        created_by VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// Load current settings
$settings = [];
$settings_result = $conn->query("SELECT * FROM system_settings");
while ($row = $settings_result->fetch_assoc()) {
    $settings[$row['setting_key']] = [
        'value' => $row['setting_value'],
        'type' => $row['setting_type']
    ];
}

// Load email settings
$email_settings = [];
$email_result = $conn->query("SELECT * FROM email_settings LIMIT 1");
if ($email_result && $email_result->num_rows > 0) {
    $email_settings = $email_result->fetch_assoc();
}

// Handle general settings update
if (isset($_POST['update_general']) && $is_admin) {
    $organization_name = $_POST['organization_name'] ?? '';
    $organization_address = $_POST['organization_address'] ?? '';
    $organization_phone = $_POST['organization_phone'] ?? '';
    $organization_email = $_POST['organization_email'] ?? '';
    $organization_website = $_POST['organization_website'] ?? '';
    $date_format = $_POST['date_format'] ?? 'd/m/Y';
    $timezone = $_POST['timezone'] ?? 'Asia/Kolkata';
    $items_per_page = (int)($_POST['items_per_page'] ?? 25);
    $default_template = (int)($_POST['default_template'] ?? 1);
    $enable_notifications = isset($_POST['enable_notifications']) ? 1 : 0;
    $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;
    
    if (in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        date_default_timezone_set($timezone);
    }

    $updates = [
        'organization_name' => [$organization_name, 'text'],
        'organization_address' => [$organization_address, 'text'],
        'organization_phone' => [$organization_phone, 'text'],
        'organization_email' => [$organization_email, 'text'],
        'organization_website' => [$organization_website, 'text'],
        'date_format' => [$date_format, 'text'],
        'timezone' => [$timezone, 'text'],
        'items_per_page' => [$items_per_page, 'number'],
        'default_template' => [$default_template, 'number'],
        'enable_notifications' => [$enable_notifications, 'boolean'],
        'maintenance_mode' => [$maintenance_mode, 'boolean']
    ];
    
    $success = true;
    foreach ($updates as $key => $value) {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_by) 
                                VALUES (?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?");
        $stmt->bind_param("ssssss", $key, $value[0], $value[1], $_SESSION['username'], $value[0], $_SESSION['username']);
        if (!$stmt->execute()) {
            $success = false;
        }
        $stmt->close();
    }
    
    if ($success) {
        $message = "General settings updated successfully!";
        logAuditActivity($conn, $_SESSION['username'], 'Updated general settings', 'settings', 'General settings updated');
    } else {
        $error = "Failed to update some settings.";
    }
}

// Handle email settings update
if (isset($_POST['update_email']) && $is_admin) {
    $mail_type = $_POST['mail_type'] ?? 'mail';
    $smtp_host = $_POST['smtp_host'] ?? '';
    $smtp_port = (int)($_POST['smtp_port'] ?? 587);
    $smtp_encryption = $_POST['smtp_encryption'] ?? 'tls';
    $smtp_username = $_POST['smtp_username'] ?? '';
    $smtp_password = $_POST['smtp_password'] ?? '';
    $from_email = $_POST['from_email'] ?? '';
    $from_name = $_POST['from_name'] ?? '';
    
    $check = $conn->query("SELECT id FROM email_settings LIMIT 1");
    
    if ($check && $check->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE email_settings SET 
                                mail_type = ?, smtp_host = ?, smtp_port = ?, smtp_encryption = ?,
                                smtp_username = ?, smtp_password = ?, from_email = ?, from_name = ?");
        $stmt->bind_param("ssisssss", $mail_type, $smtp_host, $smtp_port, $smtp_encryption, 
                         $smtp_username, $smtp_password, $from_email, $from_name);
    } else {
        $stmt = $conn->prepare("INSERT INTO email_settings 
                                (mail_type, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, from_email, from_name) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssisssss", $mail_type, $smtp_host, $smtp_port, $smtp_encryption, 
                         $smtp_username, $smtp_password, $from_email, $from_name);
    }
    
    if ($stmt->execute()) {
        $message = "Email settings updated successfully!";
        logAuditActivity($conn, $_SESSION['username'], 'Updated email settings', 'settings', 'Email settings updated');
        $email_result = $conn->query("SELECT * FROM email_settings LIMIT 1");
        if ($email_result) {
            $email_settings = $email_result->fetch_assoc();
        }
    } else {
        $error = "Failed to update email settings: " . $conn->error;
    }
    $stmt->close();
}

// Handle backup creation
if (isset($_POST['create_backup']) && $is_admin) {
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    
    $backup_file = 'backup_' . date('Y-m-d_His') . '.sql';
    $backup_path = $backup_dir . DIRECTORY_SEPARATOR . $backup_file;

    if (!is_dir($backup_dir)) {
        if (!mkdir($backup_dir, 0775, true) && !is_dir($backup_dir)) {
            $error = "Failed to create backup directory.";
        }
    }

    if (!$error && !is_writable($backup_dir)) {
        $error = "Backup directory is not writable.";
    }

    $handle = null;
    if (!$error) {
        $handle = fopen($backup_path, 'wb');
        if (!$handle) {
            $error = "Unable to create backup file.";
        }
    }

    if (!$error && $handle) {
        fwrite($handle, "-- Backup generated on " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $create_table = $conn->query("SHOW CREATE TABLE `{$table}`")->fetch_assoc();
            fwrite($handle, "\n\n" . $create_table['Create Table'] . ";\n\n");

            $rows = $conn->query("SELECT * FROM `{$table}`");
            while ($row = $rows->fetch_assoc()) {
                $values = array_map(function ($value) use ($conn) {
                    if ($value === null) return "NULL";
                    return "'" . $conn->real_escape_string($value) . "'";
                }, array_values($row));
                fwrite($handle, "INSERT INTO `{$table}` VALUES (" . implode(", ", $values) . ");\n");
            }
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        $file_size = filesize($backup_path);

        $tables_str = implode(', ', $tables);
        $stmt = $conn->prepare("INSERT INTO backup_history (filename, file_size, tables, created_by) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("siss", $backup_file, $file_size, $tables_str, $_SESSION['username']);
            $stmt->execute();
            $stmt->close();
        }

        $message = "Database backup created successfully!";
        logAuditActivity($conn, $_SESSION['username'], 'Created backup', 'backup', 'Backup created: ' . $backup_file);
    }
}

// Handle backup restore
if (isset($_POST['restore_backup']) && $is_admin) {
    if (!isset($_FILES['restore_file'])) {
        $error = 'Please upload a .sql backup file.';
    } else {
        $upload = $_FILES['restore_file'];

        if ($upload['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'Upload failed: file exceeds server upload_max_filesize.',
                UPLOAD_ERR_FORM_SIZE => 'Upload failed: file exceeds form upload limit.',
                UPLOAD_ERR_PARTIAL => 'Upload failed: file was partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'Please select a .sql file to restore.',
                UPLOAD_ERR_NO_TMP_DIR => 'Upload failed: missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Upload failed: cannot write file to disk.',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by a PHP extension.'
            ];
            $error = $upload_errors[$upload['error']] ?? 'Upload failed due to an unknown error.';
        } elseif (!is_uploaded_file($upload['tmp_name'])) {
            $error = 'Invalid upload source.';
        } else {
            $original_name = basename((string)$upload['name']);
            $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $file_size = (int)$upload['size'];

            if ($extension !== 'sql') {
                $error = 'Only .sql files are allowed for restore.';
            } elseif ($file_size <= 0) {
                $error = 'Uploaded file is empty.';
            } elseif ($file_size > MAX_RESTORE_FILE_SIZE) {
                $error = 'File is too large. Maximum allowed size is ' . formatBytes(MAX_RESTORE_FILE_SIZE) . '.';
            } else {
                @set_time_limit(300);
                $restore_result = executeSqlRestoreFile($conn, $upload['tmp_name']);

                if ($restore_result['success']) {
                    $message = 'Backup restored successfully. Executed ' . (int)$restore_result['executed'] . ' SQL statements.';
                    logAuditActivity($conn, $_SESSION['username'], 'Restored backup: ' . $original_name, 'restore', 'Restore completed. Statements executed: ' . (int)$restore_result['executed']);
                } else {
                    $error = $restore_result['error'];
                    logAuditActivity($conn, $_SESSION['username'], 'Backup restore failed: ' . $original_name, 'restore', 'Restore failed. ' . $restore_result['error']);
                }
            }
        }
    }
}

// Handle backup download
if (isset($_GET['download_backup']) && $is_admin) {
    $backup_file = basename((string)$_GET['download_backup']);
    $backup_dir_real = realpath($backup_dir);
    $requested_path = $backup_dir_real ? $backup_dir_real . DIRECTORY_SEPARATOR . $backup_file : '';
    $backup_path = $requested_path !== '' ? realpath($requested_path) : false;
    
    if ($backup_path && $backup_dir_real && startsWith($backup_path, $backup_dir_real . DIRECTORY_SEPARATOR) && is_file($backup_path)) {
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . basename($backup_path) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($backup_path));
        readfile($backup_path);
        exit();
    } else {
        $error = 'Backup file not found or invalid path.';
    }
}

// Handle backup deletion
if (isset($_POST['delete_backup']) && $is_admin) {
    $backup_id = (int)$_POST['backup_id'];
    $backup_result = $conn->query("SELECT filename FROM backup_history WHERE id = $backup_id");
    if ($backup_result && $backup_result->num_rows > 0) {
        $backup = $backup_result->fetch_assoc();
        $backup_path = $backup_dir . DIRECTORY_SEPARATOR . $backup['filename'];
        if (file_exists($backup_path)) {
            unlink($backup_path);
        }
        $conn->query("DELETE FROM backup_history WHERE id = $backup_id");
        $message = "Backup deleted successfully!";
        logAuditActivity($conn, $_SESSION['username'], 'Deleted backup', 'backup', 'Backup deleted: ' . $backup['filename']);
    }
}

// Handle system cleanup
if (isset($_POST['cleanup_system']) && $is_admin) {
    $days = (int)($_POST['cleanup_days'] ?? 30);
    
    // Clean old audit logs
    $stmt = $conn->prepare("DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $audit_deleted = $stmt->affected_rows;
    $stmt->close();
    
    // Clean old backups (keep last 10)
    $backups = $conn->query("SELECT id, filename FROM backup_history ORDER BY created_at DESC");
    $count = 0;
    if ($backups) {
        while ($backup = $backups->fetch_assoc()) {
            $count++;
            if ($count > 10) {
                $backup_path = $backup_dir . DIRECTORY_SEPARATOR . $backup['filename'];
                if (file_exists($backup_path)) {
                    unlink($backup_path);
                }
                $conn->query("DELETE FROM backup_history WHERE id = {$backup['id']}");
            }
        }
    }
    
    $message = "System cleanup completed! Deleted $audit_deleted old audit logs.";
    logAuditActivity($conn, $_SESSION['username'], 'System cleanup', 'maintenance', 'Cleanup completed. Deleted ' . $audit_deleted . ' audit logs.');
}

// Handle test email
if (isset($_POST['test_email']) && $is_admin) {
    $test_email = $_POST['test_email_address'] ?? '';
    
    if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address";
    } else {
        $subject = "Test Email from ID Card System";
        $message_body = "This is a test email from your ID Card System.\n\n";
        $message_body .= "If you received this, your email configuration is working correctly.\n\n";
        $message_body .= "Sent at: " . date('Y-m-d H:i:s');
        
        $headers = "From: " . ($email_settings['from_email'] ?? 'noreply@example.com') . "\r\n";
        $headers .= "Reply-To: " . ($email_settings['from_email'] ?? 'noreply@example.com') . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        if (mail($test_email, $subject, $message_body, $headers)) {
            $message = "Test email sent successfully to $test_email";
            logAuditActivity($conn, $_SESSION['username'], 'Sent test email', 'email', 'Test email sent to ' . $test_email);
        } else {
            $error = "Failed to send test email. Please check your email settings.";
        }
    }
}

// Handle cache clear
if (isset($_POST['clear_cache']) && $is_admin) {
    $cache_type = $_POST['cache_type'] ?? 'all';
    $cleared = [];
    
    // Clear template cache
    if ($cache_type === 'templates' || $cache_type === 'all') {
        $template_cache_dir = __DIR__ . '/../cache/templates';
        if (is_dir($template_cache_dir)) {
            $files = glob($template_cache_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            $cleared[] = 'Templates';
        }
    }
    
    // Clear thumbnail cache
    if ($cache_type === 'thumbnails' || $cache_type === 'all') {
        $thumb_cache_dir = __DIR__ . '/../images/uploads/thumbnails';
        if (is_dir($thumb_cache_dir)) {
            $files = glob($thumb_cache_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            $cleared[] = 'Thumbnails';
        }
    }
    
    // Clear session cache
    if ($cache_type === 'all') {
        // Clear template cache in session
        unset($_SESSION['template_cache']);
        $cleared[] = 'Session cache';
    }
    
    if (!empty($cleared)) {
        $message = "Cache cleared: " . implode(', ', $cleared);
        logAuditActivity($conn, $_SESSION['username'], 'Cleared cache', 'maintenance', 'Cache cleared: ' . implode(', ', $cleared));
    } else {
        $message = "No cache found to clear.";
    }
}

// Get backup history
$backups = $conn->query("SELECT * FROM backup_history ORDER BY created_at DESC LIMIT 20");

// Get available timezones
$timezones = DateTimeZone::listIdentifiers();

// Get templates for default template setting
$templates = [];
$custom_templates = $conn->query("SELECT id, name, is_default FROM card_templates WHERE status = 1");
if ($custom_templates) {
    while ($row = $custom_templates->fetch_assoc()) {
        $templates[$row['id']] = $row['name'] . ($row['is_default'] ? ' ⭐' : '');
    }
}

// Get system info
$system_info = [
    'php_version' => phpversion(),
    'mysql_version' => $conn->server_info,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'max_upload_size' => ini_get('upload_max_filesize'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time') . ' seconds',
    'disk_free_space' => function_exists('disk_free_space') ? formatBytes(disk_free_space('/')) : 'N/A',
    'disk_total_space' => function_exists('disk_total_space') ? formatBytes(disk_total_space('/')) : 'N/A',
    'server_time' => date('Y-m-d H:i:s'),
    'server_timezone' => date_default_timezone_get()
];

// Get action types for dropdown
$action_types = $conn->query("SELECT DISTINCT action_type FROM audit_log ORDER BY action_type");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>System Settings · ID Card Generator</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0a1a2f;
            --primary-light: #1e3a5f;
            --primary-soft: #e8f0fe;
            --accent: #e53e3e;
            --accent-soft: #fee2e2;
            --success: #0e9f6e;
            --success-soft: #e3f9ee;
            --warning: #f4b740;
            --warning-soft: #fef5e0;
            --danger: #dc2626;
            --danger-soft: #fee2e2;
            --info: #3b82f6;
            --info-soft: #dbeafe;
            --neutral-50: #f9fafb;
            --neutral-100: #f3f4f6;
            --neutral-200: #e5e7eb;
            --neutral-300: #d1d5db;
            --neutral-400: #9ca3af;
            --neutral-500: #6b7280;
            --neutral-600: #4b5563;
            --neutral-700: #374151;
            --neutral-800: #1f2937;
            --neutral-900: #111827;
            
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05), 0 1px 2px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--neutral-50);
            color: var(--neutral-800);
            line-height: 1.5;
        }

        /* ===== LAYOUT ===== */
        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ----- Main Content ----- */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            background: var(--neutral-50);
        }

        /* ----- Top Header ----- */
        .top-header {
            background: white;
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--neutral-200);
            position: sticky;
            top: 0;
            z-index: 40;
            box-shadow: var(--shadow-sm);
        }

        .menu-toggle {
            display: none;
            font-size: 1.5rem;
            color: var(--neutral-600);
            cursor: pointer;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--neutral-800);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .notification-btn {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--neutral-100);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--neutral-600);
            text-decoration: none;
            transition: all 0.2s;
        }

        .notification-btn:hover {
            background: var(--neutral-200);
            color: var(--neutral-800);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--accent);
            color: white;
            font-size: 0.75rem;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0.75rem;
            background: var(--neutral-100);
            border-radius: var(--radius-lg);
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .user-menu:hover {
            background: var(--neutral-200);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .user-info {
            line-height: 1.4;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.875rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--neutral-500);
        }

        /* ----- Content Area ----- */
        .content-area {
            padding: 2rem;
            max-width: 1600px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .page-head h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--neutral-800);
            letter-spacing: -0.02em;
        }

        .head-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: var(--neutral-500);
            font-size: 0.95rem;
        }

        /* Alert Messages */
        .alert {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1.5rem;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            color: white;
        }

        .alert-error {
            background: linear-gradient(135deg, var(--danger) 0%, #b91c1c 100%);
            color: white;
        }

        .alert i {
            font-size: 1.25rem;
        }

        .alert-content {
            flex: 1;
        }

        .alert-close {
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .alert-close:hover {
            opacity: 1;
        }

        /* Settings Navigation */
        .settings-nav {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--neutral-200);
            padding-bottom: 1rem;
        }

        .settings-tab {
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            background: transparent;
            color: var(--neutral-600);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
            border-bottom: none;
        }

        .settings-tab:hover {
            background: var(--neutral-100);
            color: var(--neutral-800);
        }

        .settings-tab.active {
            background: white;
            color: var(--primary);
            border-color: var(--neutral-200);
            border-bottom-color: white;
            margin-bottom: -1px;
        }

        .settings-tab i {
            margin-right: 0.5rem;
        }

        /* Settings Cards */
        .settings-card {
            background: white;
            border-radius: var(--radius-2xl);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--neutral-200);
            margin-bottom: 2rem;
            display: none;
        }

        .settings-card.active {
            display: block;
        }

        .card-header {
            padding: 1.5rem 2rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
        }

        .card-header h2 {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .card-header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .card-body {
            padding: 2rem;
        }

        .card-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid var(--neutral-200);
            background: var(--neutral-50);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            font-weight: 500;
            font-size: 0.9375rem;
            color: var(--neutral-700);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-label i {
            color: var(--accent);
            width: 20px;
        }

        .form-control {
            padding: 0.875rem 1rem;
            border: 1px solid var(--neutral-300);
            border-radius: var(--radius-lg);
            font-size: 0.9375rem;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
            width: 100%;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 26, 47, 0.1);
        }

        .form-control[readonly] {
            background: var(--neutral-100);
            cursor: not-allowed;
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-position: right 1rem center;
            background-repeat: no-repeat;
            background-size: 1.25rem;
            padding-right: 2.5rem;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .checkbox-group label {
            cursor: pointer;
        }

        .input-hint {
            font-size: 0.8125rem;
            color: var(--neutral-500);
            margin-top: 0.25rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            border-radius: var(--radius-lg);
            font-weight: 500;
            font-size: 0.9375rem;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning) 0%, #f59e0b 100%);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, #b91c1c 100%);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--neutral-300);
            color: var(--neutral-600);
        }

        .btn-outline:hover {
            background: var(--neutral-100);
            border-color: var(--neutral-400);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        /* Backup Table */
        .backup-table {
            width: 100%;
            border-collapse: collapse;
        }

        .backup-table th {
            text-align: left;
            padding: 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--neutral-600);
            background: var(--neutral-100);
            border-bottom: 1px solid var(--neutral-200);
        }

        .backup-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--neutral-200);
            color: var(--neutral-600);
            font-size: 0.875rem;
        }

        .backup-table tr:last-child td {
            border-bottom: none;
        }

        .backup-table tr:hover td {
            background: var(--neutral-50);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }

        .action-btn.download {
            background: var(--success);
        }

        .action-btn.download:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .action-btn.delete {
            background: var(--danger);
        }

        .action-btn.delete:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* System Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }

        .info-item {
            background: var(--neutral-100);
            border-radius: var(--radius-lg);
            padding: 1rem;
        }

        .info-label {
            font-size: 0.75rem;
            color: var(--neutral-500);
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-weight: 500;
            color: var(--neutral-700);
            font-size: 1rem;
        }

        /* Access Denied */
        .access-denied {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: var(--radius-2xl);
            border: 1px solid var(--neutral-200);
        }

        .access-denied i {
            font-size: 4rem;
            color: var(--danger);
            margin-bottom: 1rem;
        }

        .access-denied h3 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--neutral-700);
            margin-bottom: 0.5rem;
        }

        .access-denied p {
            color: var(--neutral-500);
            margin-bottom: 2rem;
        }

        /* Upload Zone */
        .upload-zone {
            border: 2px dashed var(--neutral-300);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s;
            background: var(--neutral-50);
            cursor: pointer;
        }

        .upload-zone:hover {
            border-color: var(--primary);
            background: var(--primary-soft);
        }

        .upload-zone.dragover {
            border-color: var(--success);
            background: var(--success-soft);
        }

        .upload-zone i {
            font-size: 2rem;
            color: var(--neutral-400);
            margin-bottom: 0.5rem;
        }

        .upload-zone p {
            color: var(--neutral-500);
            margin: 0;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-2xl);
            max-width: 500px;
            width: 90%;
            box-shadow: var(--shadow-xl);
            animation: slideIn 0.3s ease;
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--neutral-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-weight: 600;
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--neutral-500);
            transition: color 0.2s;
        }

        .modal-close:hover {
            color: var(--neutral-800);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.25rem 1.5rem;
            border-top: 1px solid var(--neutral-200);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }
        }

        @media (max-width: 768px) {
            .content-area {
                padding: 1rem;
            }

            .page-head h1 {
                font-size: 1.5rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .settings-nav {
                flex-direction: column;
            }

            .settings-tab {
                text-align: center;
                border-radius: var(--radius-lg);
                border: 1px solid var(--neutral-200);
            }

            .settings-tab.active {
                border-color: var(--primary);
                background: var(--primary-soft);
            }

            .card-footer {
                flex-direction: column;
            }

            .card-footer .btn {
                width: 100%;
                justify-content: center;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .page-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .head-meta {
                width: 100%;
                flex-wrap: wrap;
            }

            .backup-table {
                font-size: 0.75rem;
            }

            .backup-table th,
            .backup-table td {
                padding: 0.5rem;
            }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <?php include 'includes/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Header -->
            <?php include 'includes/header.php'; ?>

            <!-- Content Area -->
            <div class="content-area">
                <!-- Page Header -->
                <div class="page-head">
                    <h1>System Settings</h1>
                    <div class="head-meta">
                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['username']) ?></span>
                        <span><i class="fas fa-cog"></i> Configuration</span>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-success" id="successAlert">
                        <i class="fas fa-check-circle"></i>
                        <div class="alert-content"><?= htmlspecialchars($message) ?></div>
                        <i class="fas fa-times alert-close" onclick="this.parentElement.remove()"></i>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error" id="errorAlert">
                        <i class="fas fa-exclamation-circle"></i>
                        <div class="alert-content"><?= htmlspecialchars($error) ?></div>
                        <i class="fas fa-times alert-close" onclick="this.parentElement.remove()"></i>
                    </div>
                <?php endif; ?>

                <?php if (!$is_admin): ?>
                    <!-- Access Denied for non-admin users -->
                    <div class="access-denied">
                        <i class="fas fa-shield-alt"></i>
                        <h3>Access Denied</h3>
                        <p>You don't have permission to access system settings.<br>Please contact your administrator.</p>
                        <a href="dashboard.php" class="btn btn-primary">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                <?php else: ?>

                <!-- Settings Navigation -->
                <div class="settings-nav">
                    <div class="settings-tab active" onclick="showTab('general', event)">
                        <i class="fas fa-sliders-h"></i> General
                    </div>
                    <div class="settings-tab" onclick="showTab('email', event)">
                        <i class="fas fa-envelope"></i> Email
                    </div>
                    <div class="settings-tab" onclick="showTab('backup', event)">
                        <i class="fas fa-database"></i> Backup
                    </div>
                    <div class="settings-tab" onclick="showTab('system', event)">
                        <i class="fas fa-info-circle"></i> System Info
                    </div>
                    <div class="settings-tab" onclick="showTab('maintenance', event)">
                        <i class="fas fa-tools"></i> Maintenance
                    </div>
                </div>

                <!-- General Settings -->
                <div class="settings-card active" id="tab-general">
                    <div class="card-header">
                        <h2>
                            <i class="fas fa-sliders-h"></i>
                            General Settings
                        </h2>
                        <p>Configure general system preferences and organization information</p>
                    </div>
                    
                    <form method="POST" action="">
                        <div class="card-body">
                            <div class="form-grid">
                                <div class="form-group full-width">
                                    <label class="form-label">
                                        <i class="fas fa-building"></i>
                                        Organization Name
                                    </label>
                                    <input type="text" name="organization_name" class="form-control" 
                                           value="<?= htmlspecialchars($settings['organization_name']['value'] ?? 'ABC International Organization') ?>"
                                           placeholder="Enter organization name">
                                </div>
                                
                                <div class="form-group full-width">
                                    <label class="form-label">
                                        <i class="fas fa-map-marker-alt"></i>
                                        Organization Address
                                    </label>
                                    <textarea name="organization_address" class="form-control" 
                                              placeholder="Enter organization address"><?= htmlspecialchars($settings['organization_address']['value'] ?? '') ?></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-phone"></i>
                                        Phone Number
                                    </label>
                                    <input type="text" name="organization_phone" class="form-control" 
                                           value="<?= htmlspecialchars($settings['organization_phone']['value'] ?? '') ?>"
                                           placeholder="Enter phone number">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-envelope"></i>
                                        Email Address
                                    </label>
                                    <input type="email" name="organization_email" class="form-control" 
                                           value="<?= htmlspecialchars($settings['organization_email']['value'] ?? '') ?>"
                                           placeholder="organization@example.com">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-globe"></i>
                                        Website
                                    </label>
                                    <input type="url" name="organization_website" class="form-control" 
                                           value="<?= htmlspecialchars($settings['organization_website']['value'] ?? '') ?>"
                                           placeholder="https://example.com">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-calendar-alt"></i>
                                        Date Format
                                    </label>
                                    <select name="date_format" class="form-control">
                                        <option value="d/m/Y" <?= ($settings['date_format']['value'] ?? 'd/m/Y') == 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                                        <option value="m/d/Y" <?= ($settings['date_format']['value'] ?? '') == 'm/d/Y' ? 'selected' : '' ?>>MM/DD/YYYY</option>
                                        <option value="Y-m-d" <?= ($settings['date_format']['value'] ?? '') == 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-clock"></i>
                                        Timezone
                                    </label>
                                    <select name="timezone" class="form-control">
                                        <?php foreach ($timezones as $tz): ?>
                                            <option value="<?= $tz ?>" <?= ($settings['timezone']['value'] ?? 'Asia/Kolkata') == $tz ? 'selected' : '' ?>>
                                                <?= $tz ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-list"></i>
                                        Items Per Page
                                    </label>
                                    <input type="number" name="items_per_page" class="form-control" 
                                           value="<?= (int)($settings['items_per_page']['value'] ?? 25) ?>"
                                           min="10" max="500">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-paint-brush"></i>
                                        Default Template
                                    </label>
                                    <select name="default_template" class="form-control">
                                        <option value="0">Select Template</option>
                                        <?php foreach ($templates as $id => $name): ?>
                                            <option value="<?= $id ?>" <?= ($settings['default_template']['value'] ?? 0) == $id ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($name) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group full-width">
                                    <div class="checkbox-group">
                                        <input type="checkbox" name="enable_notifications" id="enable_notifications" 
                                               <?= isset($settings['enable_notifications']['value']) && $settings['enable_notifications']['value'] ? 'checked' : '' ?>>
                                        <label for="enable_notifications">Enable Email Notifications</label>
                                    </div>
                                    <div class="input-hint">Send email alerts for expiring cards and system events</div>
                                </div>
                                
                                <div class="form-group full-width">
                                    <div class="checkbox-group">
                                        <input type="checkbox" name="maintenance_mode" id="maintenance_mode"
                                               <?= isset($settings['maintenance_mode']['value']) && $settings['maintenance_mode']['value'] ? 'checked' : '' ?>>
                                        <label for="maintenance_mode">Maintenance Mode</label>
                                    </div>
                                    <div class="input-hint">Only administrators can access the system</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-footer">
                            <button type="reset" class="btn btn-outline">
                                <i class="fas fa-undo"></i> Reset
                            </button>
                            <button type="submit" name="update_general" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Email Settings -->
                <div class="settings-card" id="tab-email">
                    <div class="card-header">
                        <h2>
                            <i class="fas fa-envelope"></i>
                            Email Configuration
                        </h2>
                        <p>Configure SMTP settings for sending emails</p>
                    </div>
                    
                    <form method="POST" action="">
                        <div class="card-body">
                            <div class="form-grid">
                                <div class="form-group full-width">
                                    <label class="form-label">
                                        <i class="fas fa-mail-bulk"></i>
                                        Mail Type
                                    </label>
                                    <select name="mail_type" class="form-control" id="mailType">
                                        <option value="mail" <?= ($email_settings['mail_type'] ?? 'mail') == 'mail' ? 'selected' : '' ?>>PHP Mail</option>
                                        <option value="sendmail" <?= ($email_settings['mail_type'] ?? '') == 'sendmail' ? 'selected' : '' ?>>Sendmail</option>
                                        <option value="smtp" <?= ($email_settings['mail_type'] ?? '') == 'smtp' ? 'selected' : '' ?>>SMTP</option>
                                    </select>
                                </div>
                                
                                <div class="form-group smtp-field" style="<?= ($email_settings['mail_type'] ?? 'mail') == 'smtp' ? '' : 'display:none;' ?>">
                                    <label class="form-label">
                                        <i class="fas fa-server"></i>
                                        SMTP Host
                                    </label>
                                    <input type="text" name="smtp_host" class="form-control" 
                                           value="<?= htmlspecialchars($email_settings['smtp_host'] ?? '') ?>"
                                           placeholder="smtp.gmail.com">
                                </div>
                                
                                <div class="form-group smtp-field" style="<?= ($email_settings['mail_type'] ?? 'mail') == 'smtp' ? '' : 'display:none;' ?>">
                                    <label class="form-label">
                                        <i class="fas fa-plug"></i>
                                        SMTP Port
                                    </label>
                                    <input type="number" name="smtp_port" class="form-control" 
                                           value="<?= $email_settings['smtp_port'] ?? 587 ?>"
                                           placeholder="587">
                                </div>
                                
                                <div class="form-group smtp-field" style="<?= ($email_settings['mail_type'] ?? 'mail') == 'smtp' ? '' : 'display:none;' ?>">
                                    <label class="form-label">
                                        <i class="fas fa-lock"></i>
                                        Encryption
                                    </label>
                                    <select name="smtp_encryption" class="form-control">
                                        <option value="tls" <?= ($email_settings['smtp_encryption'] ?? 'tls') == 'tls' ? 'selected' : '' ?>>TLS</option>
                                        <option value="ssl" <?= ($email_settings['smtp_encryption'] ?? '') == 'ssl' ? 'selected' : '' ?>>SSL</option>
                                        <option value="none" <?= ($email_settings['smtp_encryption'] ?? '') == 'none' ? 'selected' : '' ?>>None</option>
                                    </select>
                                </div>
                                
                                <div class="form-group smtp-field" style="<?= ($email_settings['mail_type'] ?? 'mail') == 'smtp' ? '' : 'display:none;' ?>">
                                    <label class="form-label">
                                        <i class="fas fa-user"></i>
                                        SMTP Username
                                    </label>
                                    <input type="text" name="smtp_username" class="form-control" 
                                           value="<?= htmlspecialchars($email_settings['smtp_username'] ?? '') ?>"
                                           placeholder="your@email.com">
                                </div>
                                
                                <div class="form-group smtp-field" style="<?= ($email_settings['mail_type'] ?? 'mail') == 'smtp' ? '' : 'display:none;' ?>">
                                    <label class="form-label">
                                        <i class="fas fa-key"></i>
                                        SMTP Password
                                    </label>
                                    <input type="password" name="smtp_password" class="form-control" 
                                           value="<?= htmlspecialchars($email_settings['smtp_password'] ?? '') ?>"
                                           placeholder="Enter password">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-envelope"></i>
                                        From Email
                                    </label>
                                    <input type="email" name="from_email" class="form-control" 
                                           value="<?= htmlspecialchars($email_settings['from_email'] ?? '') ?>"
                                           placeholder="noreply@example.com">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="fas fa-tag"></i>
                                        From Name
                                    </label>
                                    <input type="text" name="from_name" class="form-control" 
                                           value="<?= htmlspecialchars($email_settings['from_name'] ?? '') ?>"
                                           placeholder="ID Card System">
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-footer">
                            <button type="button" class="btn btn-outline" onclick="openTestEmailModal()">
                                <i class="fas fa-paper-plane"></i> Test Email
                            </button>
                            <button type="submit" name="update_email" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Backup Settings -->
                <div class="settings-card" id="tab-backup">
                    <div class="card-header">
                        <h2>
                            <i class="fas fa-database"></i>
                            Database Backup
                        </h2>
                        <p>Create, restore and manage database backups</p>
                    </div>
                    
                    <div class="card-body">
                        <!-- Create Backup -->
                        <form method="POST" style="margin-bottom: 2rem;">
                            <button type="submit" name="create_backup" class="btn btn-success">
                                <i class="fas fa-database"></i> Create New Backup
                            </button>
                        </form>

                        <!-- Restore Backup -->
                        <form method="POST" enctype="multipart/form-data" style="margin-bottom: 2rem; padding: 1.5rem; border: 1px solid var(--neutral-200); border-radius: var(--radius-lg); background: var(--neutral-50);">
                            <h3 style="margin-bottom: 1rem; font-size: 1.1rem;">
                                <i class="fas fa-upload"></i> Restore Backup
                            </h3>
                            <div class="form-grid" style="grid-template-columns: 1fr auto; align-items: end; gap: 1rem;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label class="form-label">
                                        <i class="fas fa-file-upload"></i>
                                        Upload SQL File
                                    </label>
                                    <div class="upload-zone" id="uploadZone" onclick="document.getElementById('restoreFile').click()">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <p>Drop your .sql file here or click to browse</p>
                                        <input type="file" name="restore_file" id="restoreFile" class="form-control" accept=".sql" required style="display:none;">
                                    </div>
                                    <div class="input-hint">Only .sql files are allowed. Maximum size: <?= formatBytes(MAX_RESTORE_FILE_SIZE) ?></div>
                                </div>
                                <button type="submit" name="restore_backup" class="btn btn-warning"
                                        onclick="return confirm('This will modify your current database. Continue restore?')">
                                    <i class="fas fa-upload"></i> Restore Backup
                                </button>
                            </div>
                        </form>
                        
                        <!-- Backup History -->
                        <h3 style="margin-bottom: 1rem; font-size: 1.1rem;">
                            <i class="fas fa-history"></i> Recent Backups
                        </h3>
                        
                        <?php if ($backups && $backups->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="backup-table">
                                    <thead>
                                        <tr>
                                            <th>Filename</th>
                                            <th>Size</th>
                                            <th>Created By</th>
                                            <th>Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($backup = $backups->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($backup['filename']) ?></td>
                                                <td><?= formatBytes($backup['file_size']) ?></td>
                                                <td><?= htmlspecialchars($backup['created_by']) ?></td>
                                                <td><?= date('d/m/Y H:i', strtotime($backup['created_at'])) ?></td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="?download_backup=<?= urlencode($backup['filename']) ?>" class="action-btn download">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="backup_id" value="<?= $backup['id'] ?>">
                                                            <button type="submit" name="delete_backup" class="action-btn delete" 
                                                                    onclick="return confirm('Delete this backup?')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="text-align: center; padding: 2rem; color: var(--neutral-500);">
                                <i class="fas fa-database" style="font-size: 2rem; display: block; margin-bottom: 0.5rem;"></i>
                                No backups found. Create your first backup.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- System Info -->
                <div class="settings-card" id="tab-system">
                    <div class="card-header">
                        <h2>
                            <i class="fas fa-info-circle"></i>
                            System Information
                        </h2>
                        <p>Technical details about your system environment</p>
                    </div>
                    
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">PHP Version</div>
                                <div class="info-value"><?= $system_info['php_version'] ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">MySQL Version</div>
                                <div class="info-value"><?= $system_info['mysql_version'] ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Server Software</div>
                                <div class="info-value"><?= $system_info['server_software'] ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Max Upload Size</div>
                                <div class="info-value"><?= $system_info['max_upload_size'] ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Memory Limit</div>
                                <div class="info-value"><?= $system_info['memory_limit'] ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Max Execution Time</div>
                                <div class="info-value"><?= $system_info['max_execution_time'] ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Disk Free Space</div>
                                <div class="info-value"><?= $system_info['disk_free_space'] ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Disk Total Space</div>
                                <div class="info-value"><?= $system_info['disk_total_space'] ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Server Time</div>
                                <div class="info-value"><?= $system_info['server_time'] ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Server Timezone</div>
                                <div class="info-value"><?= $system_info['server_timezone'] ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Maintenance -->
                <div class="settings-card" id="tab-maintenance">
                    <div class="card-header">
                        <h2>
                            <i class="fas fa-tools"></i>
                            System Maintenance
                        </h2>
                        <p>Clean up old data and optimize system performance</p>
                    </div>
                    
                    <div class="card-body">
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <h3 style="margin-bottom: 1rem;">Cleanup Options</h3>
                                <form method="POST">
                                    <div class="form-group">
                                        <label class="form-label">Delete audit logs older than (days)</label>
                                        <input type="number" name="cleanup_days" class="form-control" value="30" min="1" max="365">
                                        <div class="input-hint">This will permanently delete old audit log entries</div>
                                    </div>
                                    <button type="submit" name="cleanup_system" class="btn btn-warning" 
                                            onclick="return confirm('This will delete old audit logs and old backups. Continue?')">
                                        <i class="fas fa-broom"></i> Run Cleanup
                                    </button>
                                </form>
                            </div>
                            
                            <div class="form-group full-width" style="margin-top: 2rem;">
                                <h3 style="margin-bottom: 1rem;">Cache Management</h3>
                                <form method="POST">
                                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                                        <button type="submit" name="clear_cache" value="templates" class="btn btn-outline">
                                            <i class="fas fa-paint-brush"></i> Clear Template Cache
                                        </button>
                                        <button type="submit" name="clear_cache" value="thumbnails" class="btn btn-outline">
                                            <i class="fas fa-images"></i> Clear Thumbnails
                                        </button>
                                        <button type="submit" name="clear_cache" value="all" class="btn btn-outline">
                                            <i class="fas fa-trash-alt"></i> Clear All Cache
                                        </button>
                                    </div>
                                    <input type="hidden" name="cache_type" id="cacheType" value="all">
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <?php endif; ?>
            </div>
            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <!-- Test Email Modal -->
    <div class="modal" id="testEmailModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-paper-plane"></i> Send Test Email</h3>
                <button class="modal-close" onclick="closeTestEmailModal()">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="test_email_address" class="form-control" required 
                               placeholder="test@example.com">
                        <div class="input-hint">A test email will be sent to this address</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeTestEmailModal()">Cancel</button>
                    <button type="submit" name="test_email" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Send Test
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab switching
        function showTab(tabName, event) {
            // Hide all tabs
            document.querySelectorAll('.settings-card').forEach(card => {
                card.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.settings-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            const targetTab = document.getElementById(`tab-${tabName}`);
            if (targetTab) {
                targetTab.classList.add('active');
            }
            
            // Add active class to clicked tab
            if (event && event.currentTarget) {
                event.currentTarget.classList.add('active');
            } else {
                // Find and activate the tab button
                document.querySelectorAll('.settings-tab').forEach(tab => {
                    if (tab.textContent.trim().toLowerCase().includes(tabName)) {
                        tab.classList.add('active');
                    }
                });
            }
            
            // Save active tab to localStorage
            localStorage.setItem('activeSettingsTab', tabName);
        }

        // Load saved tab
        document.addEventListener('DOMContentLoaded', function() {
            const savedTab = localStorage.getItem('activeSettingsTab');
            if (savedTab) {
                const tabButton = document.querySelector(`.settings-tab[onclick*="${savedTab}"]`);
                if (tabButton) {
                    showTab(savedTab, { currentTarget: tabButton });
                }
            }

            // Initialize SMTP fields visibility
            toggleSmtpFields();
        });

        // Toggle SMTP fields based on mail type
        function toggleSmtpFields() {
            const mailType = document.getElementById('mailType');
            if (!mailType) return;
            
            const smtpFields = document.querySelectorAll('.smtp-field');
            if (mailType.value === 'smtp') {
                smtpFields.forEach(field => {
                    field.style.display = 'block';
                });
            } else {
                smtpFields.forEach(field => {
                    field.style.display = 'none';
                });
            }
        }

        // Add event listener for mail type change
        document.addEventListener('DOMContentLoaded', function() {
            const mailType = document.getElementById('mailType');
            if (mailType) {
                mailType.addEventListener('change', toggleSmtpFields);
            }
        });

        // Test email modal
        function openTestEmailModal() {
            document.getElementById('testEmailModal').classList.add('active');
        }

        function closeTestEmailModal() {
            document.getElementById('testEmailModal').classList.remove('active');
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('testEmailModal');
            if (event.target === modal) {
                closeTestEmailModal();
            }
        });

        // File upload zone
        document.addEventListener('DOMContentLoaded', function() {
            const uploadZone = document.getElementById('uploadZone');
            const fileInput = document.getElementById('restoreFile');

            if (uploadZone && fileInput) {
                // Click to browse
                uploadZone.addEventListener('click', function(e) {
                    if (e.target !== uploadZone) return;
                    fileInput.click();
                });

                // File selected
                fileInput.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        uploadZone.innerHTML = `
                            <i class="fas fa-file-alt" style="font-size:2rem;color:var(--success);margin-bottom:0.5rem;"></i>
                            <p style="color:var(--success);font-weight:500;">${this.files[0].name}</p>
                            <p style="font-size:0.75rem;color:var(--neutral-500);">${(this.files[0].size / 1024).toFixed(2)} KB</p>
                            <p style="font-size:0.75rem;color:var(--neutral-500);">Click to change file</p>
                            <input type="file" name="restore_file" class="form-control" accept=".sql" required style="display:none;">
                        `;
                        // Re-bind file input
                        const newInput = uploadZone.querySelector('input[type="file"]');
                        if (newInput) {
                            newInput.addEventListener('change', function() {
                                this.closest('.upload-zone').click();
                            });
                        }
                    }
                });

                // Drag and drop
                uploadZone.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    this.classList.add('dragover');
                });

                uploadZone.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    this.classList.remove('dragover');
                });

                uploadZone.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.classList.remove('dragover');
                    
                    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                        fileInput.files = e.dataTransfer.files;
                        fileInput.dispatchEvent(new Event('change'));
                    }
                });
            }
        });

        // Clear cache handler
        document.querySelectorAll('button[name="clear_cache"]').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const cacheType = this.value;
                document.getElementById('cacheType').value = cacheType;
                if (confirm(`Clear ${cacheType} cache?`)) {
                    this.closest('form').submit();
                }
            });
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + S to save current form
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                const activeTab = document.querySelector('.settings-card.active form');
                if (activeTab) {
                    const submitBtn = activeTab.querySelector('button[type="submit"]');
                    if (submitBtn) submitBtn.click();
                }
            }
            
            // Escape to close modals
            if (e.key === 'Escape') {
                closeTestEmailModal();
                const sidebar = document.querySelector('.sidebar');
                if (sidebar && window.innerWidth <= 1024) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Touch-friendly
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .form-control, .settings-tab').forEach(el => {
                el.addEventListener('touchstart', function() {
                    this.style.opacity = '0.8';
                });
                el.addEventListener('touchend', function() {
                    this.style.opacity = '1';
                });
            });
        }
    </script>
</body>
</html>