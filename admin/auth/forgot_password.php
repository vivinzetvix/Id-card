<?php
/**
 * Forgot Password — Request Reset Link
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

$csrfToken = auth_generate_csrf_token();
$message   = '';
$msgType   = '';
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = true;
    $token = $_POST['csrf_token'] ?? '';

    if (!auth_validate_csrf_token($token)) {
        $message = 'Invalid form submission. Please try again.';
        $msgType = 'error';
    } else {
        $email = trim($_POST['email'] ?? '');

        if ($email === '') {
            $message = 'Email address is required.';
            $msgType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $msgType = 'error';
        } else {
            // Look up user by email
            $stmt = $pdo->prepare("SELECT id, full_name, username FROM users WHERE LOWER(email) = LOWER(?) AND status = 1 AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Always show a generic success message (don't reveal if email exists)
            $message = 'If a matching account is found, a password reset token has been generated. Please ask your system administrator to share it with you, or check your email if email delivery is configured.';
            $msgType = 'success';

            if ($user) {
                $resetToken = auth_create_reset_token($pdo, (int)$user['id']);
                // In a production system, send this via email. For now, log it to audit.
                auth_log_activity(
                    $pdo,
                    (int)$user['id'],
                    'Password Reset Requested',
                    'auth',
                    "Reset token generated for user '{$user['username']}' (email: {$email}). Token: {$resetToken}"
                );

                // For development: show the reset link directly (remove in production)
                $resetUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                    . str_replace('forgot_password.php', 'reset_password.php?token=' . $resetToken, $_SERVER['PHP_SELF']);
                $message .= '<br><br><strong>Development Mode — Reset Link:</strong><br><a href="' . htmlspecialchars($resetUrl) . '" style="color:#0a1a2f;word-break:break-all;">' . htmlspecialchars($resetUrl) . '</a>';
            }
        }
    }

    // Regenerate CSRF after POST
    $csrfToken = auth_generate_csrf_token();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password · ID Card System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #3f37c9;
            --light: #f8f9fa;
            --dark: #212529;
            --danger: #f72585;
            --success-bg: #d1fae5;
            --success-color: #065f46;
            --error-bg: #fde8e8;
            --error-color: #d32f2f;
            --border-radius: 12px;
            --box-shadow: 0 10px 30px rgba(0,0,0,.08);
            --transition: all .3s cubic-bezier(.4,0,.2,1);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 40px;
            width: 100%;
            max-width: 450px;
            position: relative;
            overflow: hidden;
            animation: fadeIn .5s ease-out;
        }
        .card::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 8px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .header { text-align: center; margin-bottom: 28px; }
        .header .icon {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, #e8f0fe, #d4e0f0);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 14px;
            font-size: 1.75rem; color: var(--primary);
        }
        .header h2 { font-size: 1.5rem; font-weight: 600; color: var(--dark); margin-bottom: 6px; }
        .header p  { color: #6c757d; font-size: .875rem; }
        .form-group { margin-bottom: 18px; position: relative; }
        .form-group label { display: block; margin-bottom: 7px; font-weight: 500; color: #495057; font-size: .875rem; }
        .input-icon { position: absolute; left: 14px; top: 38px; color: #6c757d; }
        input[type="email"] {
            width: 100%;
            padding: 11px 14px 11px 42px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: .875rem;
            transition: var(--transition);
            background: var(--light);
        }
        input[type="email"]:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67,97,238,.2); }
        .btn-submit {
            width: 100%;
            padding: 13px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-submit:hover { background: var(--primary-dark); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(67,97,238,.3); }
        .alert { padding: 12px 14px; border-radius: 8px; margin-bottom: 18px; font-size: .875rem; }
        .alert-success { background: var(--success-bg); color: var(--success-color); border: 1px solid #a7f3d0; }
        .alert-error   { background: var(--error-bg); color: var(--error-color); border: 1px solid #f5c6cb; }
        .back-link { text-align: center; margin-top: 20px; font-size: .875rem; }
        .back-link a { color: var(--primary); text-decoration: none; transition: var(--transition); }
        .back-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="card">
    <div class="header">
        <div class="icon"><i class="fas fa-key"></i></div>
        <h2>Forgot Password</h2>
        <p>Enter your email address and we'll generate a reset link.</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>">
            <i class="fas fa-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
            <?= $message ?>
        </div>
    <?php endif; ?>

    <?php if (!$submitted || $msgType === 'error'): ?>
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="form-group">
            <label for="email">Email Address</label>
            <i class="fas fa-envelope input-icon"></i>
            <input type="email" id="email" name="email" placeholder="Enter your registered email" required autofocus
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <button type="submit" class="btn-submit">
            <i class="fas fa-paper-plane"></i> Send Reset Link
        </button>
    </form>
    <?php endif; ?>

    <div class="back-link">
        <a href="../../index.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>
</div>

</body>
</html>
