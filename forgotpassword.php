<?php
session_start();
require 'config.php';

// Initialize variables
$message = '';
$error = '';
$step = isset($_GET['step']) ? $_GET['step'] : 'request'; // request, verify, reset

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Helper function to log audit activities
function logAuditActivity($conn, $username, $action, $action_type, $details) {
    // Get user ID
    $user_id = null;
    if ($username) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $user_id = (int)$result->fetch_assoc()['id'];
            }
            $stmt->close();
        }
    }

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, action_type, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("isssss", $user_id, $action, $action_type, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }
}

// Handle password reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_reset']) && $step === 'request') {
    $posted_token = $_POST['csrf_token'] ?? '';
    
    if (!is_string($posted_token) || !hash_equals($csrf_token, $posted_token)) {
        $error = 'Security validation failed. Please refresh and try again.';
    } else {
        $email_or_username = trim($_POST['email_or_username'] ?? '');
        
        if (empty($email_or_username)) {
            $error = 'Please enter your username or email address.';
        } else {
            // Check if user exists (by username or email)
            $stmt = $conn->prepare("SELECT id, username, email, full_name FROM users WHERE username = ? OR email = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("ss", $email_or_username, $email_or_username);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    
                    // Generate reset token
                    $reset_token = bin2hex(random_bytes(32));
                    $reset_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    
                    // Store reset token in database (you'll need to add this column)
                    // First, check if the column exists and add it if not
                    $check_column = $conn->query("SHOW COLUMNS FROM users LIKE 'reset_token'");
                    if ($check_column->num_rows === 0) {
                        $conn->query("ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) NULL, ADD COLUMN reset_expiry DATETIME NULL");
                    }
                    
                    $update_stmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_expiry = ? WHERE id = ?");
                    if ($update_stmt) {
                        $update_stmt->bind_param("ssi", $reset_token, $reset_expiry, $user['id']);
                        if ($update_stmt->execute()) {
                            // Get email settings
                            $email_settings = [];
                            $email_result = $conn->query("SELECT * FROM email_settings LIMIT 1");
                            if ($email_result && $email_result->num_rows > 0) {
                                $email_settings = $email_result->fetch_assoc();
                            }
                            
                            // Get system settings for organization name
                            $org_name = 'ID Card Generator';
                            $org_settings = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'organization_name' LIMIT 1");
                            if ($org_settings && $org_settings->num_rows > 0) {
                                $org_name = $org_settings->fetch_assoc()['setting_value'];
                            }
                            
                            // Send reset email
                            $to = $user['email'];
                            $subject = "Password Reset Request - $org_name";
                            
                            // Create reset link
                            $reset_link = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . 
                                         $_SERVER['HTTP_HOST'] . 
                                         rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . 
                                         '/forgotpassword.php?step=reset&token=' . urlencode($reset_token);
                            
                            // Email content
                            $message_body = "
                            <!DOCTYPE html>
                            <html>
                            <head>
                                <style>
                                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                    .header { background: #0a1a2f; color: white; padding: 20px; text-align: center; }
                                    .content { padding: 20px; background: #f9f9f9; }
                                    .button { display: inline-block; padding: 12px 24px; background: #0a1a2f; color: white; 
                                             text-decoration: none; border-radius: 5px; margin: 20px 0; }
                                    .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
                                </style>
                            </head>
                            <body>
                                <div class='container'>
                                    <div class='header'>
                                        <h2>$org_name</h2>
                                    </div>
                                    <div class='content'>
                                        <h3>Hello " . htmlspecialchars($user['full_name'] ?: $user['username']) . ",</h3>
                                        <p>We received a request to reset your password. Click the button below to proceed:</p>
                                        <p style='text-align: center;'>
                                            <a href='$reset_link' class='button'>Reset Password</a>
                                        </p>
                                        <p>Or copy this link to your browser:</p>
                                        <p style='word-break: break-all; background: #eee; padding: 10px;'>$reset_link</p>
                                        <p><strong>Note:</strong> This link will expire in 1 hour.</p>
                                        <p>If you didn't request this, please ignore this email or contact support.</p>
                                    </div>
                                    <div class='footer'>
                                        <p>&copy; " . date('Y') . " $org_name. All rights reserved.</p>
                                    </div>
                                </div>
                            </body>
                            </html>
                            ";
                            
                            $headers = "MIME-Version: 1.0\r\n";
                            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                            $headers .= "From: " . ($email_settings['from_name'] ?? $org_name) . " <" . ($email_settings['from_email'] ?? 'noreply@' . $_SERVER['HTTP_HOST']) . ">\r\n";
                            
                            if (mail($to, $subject, $message_body, $headers)) {
                                $message = "Password reset instructions have been sent to your email address.";
                                logAuditActivity($conn, $user['username'], 'Password reset requested', 'password_reset_request', 'Reset link sent to email');
                            } else {
                                $error = "Failed to send email. Please try again or contact administrator.";
                            }
                        } else {
                            $error = "Failed to generate reset token. Please try again.";
                        }
                        $update_stmt->close();
                    } else {
                        $error = "Database error. Please contact administrator.";
                    }
                } else {
                    // Don't reveal if user exists or not for security
                    $message = "If the provided username/email exists in our system, you will receive reset instructions.";
                }
                $stmt->close();
            } else {
                $error = "Database error. Please try again later.";
            }
        }
    }
}

// Handle password reset (with token)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password']) && $step === 'reset') {
    $posted_token = $_POST['csrf_token'] ?? '';
    $reset_token = $_POST['reset_token'] ?? '';
    
    if (!is_string($posted_token) || !hash_equals($csrf_token, $posted_token)) {
        $error = 'Security validation failed. Please refresh and try again.';
    } else {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate password
        $validation_errors = [];
        if (strlen($new_password) < 8 || strlen($new_password) > 128) {
            $validation_errors[] = 'Password must be between 8 and 128 characters.';
        }
        if (!preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
            $validation_errors[] = 'Password must include uppercase, lowercase, and number.';
        }
        if ($new_password !== $confirm_password) {
            $validation_errors[] = 'Passwords do not match.';
        }
        
        if (!empty($validation_errors)) {
            $error = implode('<br>', $validation_errors);
        } else {
            // Verify token and get user
            $stmt = $conn->prepare("SELECT id, username FROM users WHERE reset_token = ? AND reset_expiry > NOW() LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $reset_token);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    
                    // Update password and clear token
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expiry = NULL WHERE id = ?");
                    if ($update_stmt) {
                        $update_stmt->bind_param("si", $hashed_password, $user['id']);
                        if ($update_stmt->execute()) {
                            $message = "Your password has been reset successfully. You can now login with your new password.";
                            logAuditActivity($conn, $user['username'], 'Password reset completed', 'password_reset_complete', 'Password changed via reset link');
                            
                            // Redirect to login after 3 seconds
                            header("refresh:3;url=login.php");
                            $step = 'success';
                        } else {
                            $error = "Failed to update password. Please try again.";
                        }
                        $update_stmt->close();
                    } else {
                        $error = "Database error. Please try again.";
                    }
                } else {
                    $error = "Invalid or expired reset token. Please request a new password reset.";
                }
                $stmt->close();
            } else {
                $error = "Database error. Please try again.";
            }
        }
    }
}

// Verify token if in reset step
if ($step === 'reset' && isset($_GET['token'])) {
    $reset_token = $_GET['token'];
    
    // Check if token is valid
    $stmt = $conn->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expiry > NOW() LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $reset_token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $error = "Invalid or expired reset token. Please request a new password reset.";
            $step = 'request';
        }
        $stmt->close();
    } else {
        $error = "Database error. Please try again.";
        $step = 'request';
    }
} elseif ($step === 'reset' && !isset($_GET['token'])) {
    $step = 'request';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - ID Card Generator</title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #0a1a2f;
            --primary-light: #1e3a5f;
            --primary-soft: #e8f0fe;
            --accent: #e53e3e;
            --success: #0e9f6e;
            --success-soft: #e3f9ee;
            --warning: #f4b740;
            --danger: #dc2626;
            --danger-soft: #fee2e2;
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
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            width: 100%;
            max-width: 500px;
            animation: fadeIn 0.5s ease-out;
        }
        
        .card {
            background: white;
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            position: relative;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 8px;
            background: linear-gradient(to right, var(--primary), var(--primary-light));
        }
        
        .card-header {
            padding: 2rem 2rem 1rem;
            text-align: center;
        }
        
        .card-header img {
            height: 60px;
            margin-bottom: 1rem;
        }
        
        .card-header h2 {
            color: var(--neutral-800);
            font-weight: 700;
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }
        
        .card-header p {
            color: var(--neutral-500);
            font-size: 0.95rem;
        }
        
        .card-body {
            padding: 1.5rem 2rem 2rem;
        }
        
        .alert {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1.5rem;
        }
        
        .alert-success {
            background: var(--success-soft);
            color: var(--success);
            border: 1px solid var(--success);
        }
        
        .alert-error {
            background: var(--danger-soft);
            color: var(--danger);
            border: 1px solid var(--danger);
        }
        
        .alert i {
            font-size: 1.25rem;
        }
        
        .alert-content {
            flex: 1;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--neutral-700);
            font-size: 0.9375rem;
        }
        
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 2.7rem;
            color: var(--neutral-400);
            font-size: 1rem;
        }
        
        .form-control {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 2.5rem;
            border: 1px solid var(--neutral-300);
            border-radius: var(--radius-lg);
            font-family: 'Inter', sans-serif;
            font-size: 0.9375rem;
            transition: all 0.2s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 26, 47, 0.1);
        }
        
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 2.7rem;
            cursor: pointer;
            color: var(--neutral-400);
            transition: color 0.2s;
        }
        
        .password-toggle:hover {
            color: var(--primary);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: var(--radius-lg);
            font-weight: 500;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
        }
        
        .btn-primary:hover {
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
        }
        
        .password-requirements {
            background: var(--neutral-100);
            border-radius: var(--radius-lg);
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }
        
        .password-requirements h4 {
            color: var(--neutral-700);
            margin-bottom: 0.5rem;
            font-size: 0.9375rem;
        }
        
        .requirement {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--neutral-500);
            margin-bottom: 0.25rem;
        }
        
        .requirement i {
            width: 16px;
            font-size: 0.75rem;
        }
        
        .requirement.valid {
            color: var(--success);
        }
        
        .requirement.invalid {
            color: var(--danger);
        }
        
        .footer-links {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--neutral-200);
        }
        
        .footer-links a {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.9375rem;
            transition: color 0.2s;
        }
        
        .footer-links a:hover {
            text-decoration: underline;
        }
        
        .footer-links i {
            margin-right: 0.25rem;
        }
        
        .security-info {
            background: var(--neutral-100);
            border-radius: var(--radius-lg);
            padding: 1rem;
            margin-top: 1.5rem;
            font-size: 0.8125rem;
            color: var(--neutral-500);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .security-info i {
            color: var(--primary);
            font-size: 1rem;
        }
        
        .timer {
            text-align: center;
            color: var(--success);
            font-weight: 500;
            margin-top: 1rem;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 480px) {
            .card-header {
                padding: 1.5rem 1.5rem 1rem;
            }
            
            .card-body {
                padding: 1rem 1.5rem 1.5rem;
            }
            
            .card-header h2 {
                font-size: 1.5rem;
            }
        }
        
        /* Loading state */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <img src="id.jpg" alt="ID Card Generator Logo">
                <h2>Forgot Password?</h2>
                <p>
                    <?php if ($step === 'request'): ?>
                        Enter your username or email to reset your password
                    <?php elseif ($step === 'reset'): ?>
                        Create a new password for your account
                    <?php elseif ($step === 'success'): ?>
                        Password Reset Successful!
                    <?php endif; ?>
                </p>
            </div>
            
            <div class="card-body">
                <!-- Alert Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div class="alert-content"><?= htmlspecialchars($message) ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <div class="alert-content"><?= htmlspecialchars($error) ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if ($step === 'request'): ?>
                    <!-- Request Form -->
                    <form method="POST" action="?step=request" id="requestForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="request_reset" value="1">
                        
                        <div class="form-group">
                            <label for="email_or_username">Username or Email Address</label>
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" 
                                   id="email_or_username" 
                                   name="email_or_username" 
                                   class="form-control" 
                                   placeholder="Enter your username or email"
                                   required 
                                   autofocus>
                        </div>
                        
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-paper-plane"></i> Send Reset Instructions
                        </button>
                        
                        <div class="footer-links">
                            <a href="login.php">
                                <i class="fas fa-arrow-left"></i> Back to Login
                            </a>
                        </div>
                    </form>
                    
                <?php elseif ($step === 'reset' && isset($_GET['token'])): ?>
                    <!-- Reset Form -->
                    <form method="POST" action="?step=reset" id="resetForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="reset_password" value="1">
                        <input type="hidden" name="reset_token" value="<?= htmlspecialchars($_GET['token'], ENT_QUOTES, 'UTF-8') ?>">
                        
                        <!-- Password Requirements -->
                        <div class="password-requirements">
                            <h4>Password Requirements:</h4>
                            <div class="requirement" id="req-length">
                                <i class="fas fa-circle"></i> At least 8 characters
                            </div>
                            <div class="requirement" id="req-uppercase">
                                <i class="fas fa-circle"></i> At least one uppercase letter
                            </div>
                            <div class="requirement" id="req-lowercase">
                                <i class="fas fa-circle"></i> At least one lowercase letter
                            </div>
                            <div class="requirement" id="req-number">
                                <i class="fas fa-circle"></i> At least one number
                            </div>
                            <div class="requirement" id="req-match">
                                <i class="fas fa-circle"></i> Passwords match
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" 
                                   id="new_password" 
                                   name="new_password" 
                                   class="form-control" 
                                   placeholder="Enter new password"
                                   required>
                            <span class="password-toggle" onclick="togglePassword('new_password', this)">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" 
                                   id="confirm_password" 
                                   name="confirm_password" 
                                   class="form-control" 
                                   placeholder="Confirm new password"
                                   required>
                            <span class="password-toggle" onclick="togglePassword('confirm_password', this)">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                        
                        <button type="submit" class="btn btn-primary" id="resetBtn" disabled>
                            <i class="fas fa-sync-alt"></i> Reset Password
                        </button>
                        
                        <div class="footer-links">
                            <a href="login.php">
                                <i class="fas fa-arrow-left"></i> Back to Login
                            </a>
                        </div>
                    </form>
                    
                <?php elseif ($step === 'success'): ?>
                    <!-- Success Message -->
                    <div style="text-align: center;">
                        <i class="fas fa-check-circle" style="font-size: 4rem; color: var(--success); margin-bottom: 1rem;"></i>
                        <p style="color: var(--neutral-600); margin-bottom: 1.5rem;">
                            Your password has been reset successfully. You will be redirected to the login page in 3 seconds...
                        </p>
                        <div class="timer" id="timer">Redirecting in 3 seconds...</div>
                        <a href="login.php" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-sign-in-alt"></i> Go to Login Now
                        </a>
                    </div>
                <?php endif; ?>
                
                <!-- Security Info -->
                <div class="security-info">
                    <i class="fas fa-shield-alt"></i>
                    <span>Your information is protected by industry-standard security measures.</span>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Password visibility toggle
        function togglePassword(fieldId, element) {
            const field = document.getElementById(fieldId);
            const icon = element.querySelector('i');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        <?php if ($step === 'reset'): ?>
        // Password validation
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const resetBtn = document.getElementById('resetBtn');
        
        const reqLength = document.getElementById('req-length');
        const reqUppercase = document.getElementById('req-uppercase');
        const reqLowercase = document.getElementById('req-lowercase');
        const reqNumber = document.getElementById('req-number');
        const reqMatch = document.getElementById('req-match');
        
        function validatePassword() {
            const password = newPassword.value;
            const confirm = confirmPassword.value;
            
            // Length check
            const lengthValid = password.length >= 8;
            updateRequirement(reqLength, lengthValid, lengthValid ? 'fa-check-circle' : 'fa-circle');
            
            // Uppercase check
            const uppercaseValid = /[A-Z]/.test(password);
            updateRequirement(reqUppercase, uppercaseValid, uppercaseValid ? 'fa-check-circle' : 'fa-circle');
            
            // Lowercase check
            const lowercaseValid = /[a-z]/.test(password);
            updateRequirement(reqLowercase, lowercaseValid, lowercaseValid ? 'fa-check-circle' : 'fa-circle');
            
            // Number check
            const numberValid = /[0-9]/.test(password);
            updateRequirement(reqNumber, numberValid, numberValid ? 'fa-check-circle' : 'fa-circle');
            
            // Match check
            const matchValid = password !== '' && password === confirm;
            updateRequirement(reqMatch, matchValid, matchValid ? 'fa-check-circle' : 'fa-circle');
            
            // Enable/disable reset button
            resetBtn.disabled = !(lengthValid && uppercaseValid && lowercaseValid && numberValid && matchValid);
        }
        
        function updateRequirement(element, isValid, iconClass) {
            element.querySelector('i').className = `fas ${iconClass}`;
            if (isValid) {
                element.classList.add('valid');
                element.classList.remove('invalid');
            } else {
                element.classList.add('invalid');
                element.classList.remove('valid');
            }
        }
        
        newPassword.addEventListener('input', validatePassword);
        confirmPassword.addEventListener('input', validatePassword);
        <?php endif; ?>
        
        <?php if ($step === 'success'): ?>
        // Countdown timer
        let seconds = 3;
        const timerElement = document.getElementById('timer');
        
        const countdown = setInterval(function() {
            seconds--;
            timerElement.textContent = `Redirecting in ${seconds} second${seconds !== 1 ? 's' : ''}...`;
            
            if (seconds <= 0) {
                clearInterval(countdown);
                window.location.href = 'login.php';
            }
        }, 1000);
        <?php endif; ?>
        
        // Form submission loading state
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn && !submitBtn.disabled) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<span class="loading"></span> Processing...';
                    submitBtn.disabled = true;
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
            // Escape to go back to login
            if (e.key === 'Escape') {
                window.location.href = 'login.php';
            }
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>