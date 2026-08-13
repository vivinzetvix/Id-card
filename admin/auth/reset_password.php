<?php
/**
 * Reset Password — Token-Based Handler
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');
$message  = '';
$msgType  = '';
$tokenRow = null;

// ─── Validate Token ───────────────────────────────────────────────────────────

if ($rawToken !== '') {
    $tokenRow = auth_verify_reset_token($pdo, $rawToken);
    if (!$tokenRow) {
        $message = 'This reset link is invalid or has expired. Please request a new one.';
        $msgType = 'error';
    }
}

// ─── Handle Form Submission ───────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenRow) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!auth_validate_csrf_token($csrfToken)) {
        $message = 'Invalid form submission. Please try again.';
        $msgType = 'error';
    } else {
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($newPassword) < 8) {
            $message = 'Password must be at least 8 characters long.';
            $msgType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'Passwords do not match.';
            $msgType = 'error';
        } else {
            // Consume token and update password
            if (auth_consume_reset_token($pdo, (int)$tokenRow['id'], $newPassword)) {
                auth_log_activity(
                    $pdo,
                    (int)$tokenRow['user_id'],
                    'Password Reset Completed',
                    'auth',
                    "Password successfully reset for user '{$tokenRow['username']}'"
                );
                $message  = 'Your password has been reset successfully. You can now log in with your new password.';
                $msgType  = 'success';
                $tokenRow = null; // Don't show form again
                $rawToken = '';
            } else {
                $message = 'Failed to reset password. Please try again.';
                $msgType = 'error';
            }
        }
    }
}

$csrfToken = auth_generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password · ID Card System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee; --primary-dark: #3a56d4; --secondary: #3f37c9;
            --light: #f8f9fa; --dark: #212529;
            --success-bg: #d1fae5; --success-color: #065f46;
            --error-bg: #fde8e8; --error-color: #d32f2f;
            --border-radius: 12px;
            --box-shadow: 0 10px 30px rgba(0,0,0,.08);
            --transition: all .3s cubic-bezier(.4,0,.2,1);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .card { background: white; border-radius: var(--border-radius); box-shadow: var(--box-shadow); padding: 40px; width: 100%; max-width: 450px; position: relative; overflow: hidden; animation: fadeIn .5s ease-out; }
        .card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 8px; background: linear-gradient(to right, var(--primary), var(--secondary)); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .header { text-align: center; margin-bottom: 28px; }
        .header .icon { width: 64px; height: 64px; background: linear-gradient(135deg, #e8f0fe, #d4e0f0); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; font-size: 1.75rem; color: var(--primary); }
        .header h2 { font-size: 1.5rem; font-weight: 600; color: var(--dark); margin-bottom: 6px; }
        .header p  { color: #6c757d; font-size: .875rem; }
        .form-group { margin-bottom: 18px; position: relative; }
        .form-group label { display: block; margin-bottom: 7px; font-weight: 500; color: #495057; font-size: .875rem; }
        .input-icon { position: absolute; left: 14px; top: 38px; color: #6c757d; }
        input[type="password"] { width: 100%; padding: 11px 14px 11px 42px; border: 1px solid #e0e0e0; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: .875rem; transition: var(--transition); background: var(--light); }
        input[type="password"]:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67,97,238,.2); }
        .btn-submit { width: 100%; padding: 13px; background: var(--primary); color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 500; cursor: pointer; transition: var(--transition); display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-submit:hover { background: var(--primary-dark); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(67,97,238,.3); }
        .alert { padding: 12px 14px; border-radius: 8px; margin-bottom: 18px; font-size: .875rem; }
        .alert-success { background: var(--success-bg); color: var(--success-color); border: 1px solid #a7f3d0; }
        .alert-error   { background: var(--error-bg); color: var(--error-color); border: 1px solid #f5c6cb; }
        .strength-bar { height: 4px; border-radius: 4px; margin-top: 6px; background: #e5e7eb; overflow: hidden; }
        .strength-fill { height: 100%; border-radius: 4px; transition: width .3s ease, background .3s ease; width: 0; }
        .back-link { text-align: center; margin-top: 20px; font-size: .875rem; }
        .back-link a { color: var(--primary); text-decoration: none; transition: var(--transition); }
        .back-link a:hover { text-decoration: underline; }
        .pwd-hint { font-size: .75rem; color: #6c757d; margin-top: 4px; }
    </style>
</head>
<body>

<div class="card">
    <div class="header">
        <div class="icon"><i class="fas fa-lock"></i></div>
        <h2>Reset Password</h2>
        <p>
            <?php if ($tokenRow): ?>
                Hello, <strong><?= htmlspecialchars($tokenRow['full_name'] ?? $tokenRow['username']) ?></strong>. Set your new password below.
            <?php else: ?>
                Create a new secure password.
            <?php endif; ?>
        </p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>">
            <i class="fas fa-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($tokenRow): ?>
    <form method="POST" action="" id="resetForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="token"      value="<?= htmlspecialchars($rawToken) ?>">

        <div class="form-group">
            <label for="new_password">New Password</label>
            <i class="fas fa-lock input-icon"></i>
            <input type="password" id="new_password" name="new_password" placeholder="Minimum 8 characters" required autofocus>
            <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
            <div class="pwd-hint" id="pwdHint">Password strength indicator</div>
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <i class="fas fa-lock input-icon"></i>
            <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your new password" required>
        </div>

        <button type="submit" class="btn-submit">
            <i class="fas fa-check"></i> Reset Password
        </button>
    </form>
    <?php endif; ?>

    <?php if ($msgType === 'success'): ?>
        <div class="back-link">
            <a href="../../index.php"><i class="fas fa-sign-in-alt"></i> Go to Login</a>
        </div>
    <?php else: ?>
        <div class="back-link">
            <a href="forgot_password.php"><i class="fas fa-arrow-left"></i> Request a new link</a>
        </div>
    <?php endif; ?>
</div>

<script>
    var pwd = document.getElementById('new_password');
    var fill = document.getElementById('strengthFill');
    var hint = document.getElementById('pwdHint');

    if (pwd) {
        pwd.addEventListener('input', function () {
            var v = this.value;
            var score = 0;
            if (v.length >= 8)  score++;
            if (/[A-Z]/.test(v)) score++;
            if (/[0-9]/.test(v)) score++;
            if (/[^A-Za-z0-9]/.test(v)) score++;

            var pct = score * 25;
            var colors = ['#dc2626','#f59e0b','#3b82f6','#10b981'];
            var labels = ['Weak','Fair','Good','Strong'];

            fill.style.width = pct + '%';
            fill.style.background = colors[score - 1] || '#e5e7eb';
            hint.textContent = score > 0 ? labels[score - 1] : 'Password strength indicator';
        });
    }
</script>

</body>
</html>
