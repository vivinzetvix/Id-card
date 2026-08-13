<?php
require_once 'middleware/session.php';
require_once 'config.php';
require_once 'admin/auth/functions.php';

// Redirect if already logged in
if (!empty($_SESSION['logged_in'])) {
    header('Location: dashboard.php');
    exit();
}

// Consume session error/success messages from authenticate.php
$error   = '';
$success = '';
if (!empty($_SESSION['auth_error'])) {
    $error = $_SESSION['auth_error'];
    unset($_SESSION['auth_error']);
}
if (!empty($_SESSION['auth_success'])) {
    $success = $_SESSION['auth_success'];
    unset($_SESSION['auth_success']);
}

// Show timeout/logout notices
if (isset($_GET['timeout'])) {
    $error = 'Your session has expired due to inactivity. Please log in again.';
} elseif (isset($_GET['logged_out'])) {
    $success = 'You have been logged out successfully.';
} elseif (isset($_GET['security'])) {
    $error = 'Your session was terminated for security reasons. Please log in again.';
}

// Generate CSRF token for the form
$csrfToken = auth_generate_csrf_token();
?>


<!DOCTYPE html>
<html lang="en">
    
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ID Card Generator | Admin Login</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #3f37c9;
            --light: #f8f9fa;
            --dark: #212529;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --border-radius: 12px;
            --box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
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
        
        .login-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 40px;
            width: 100%;
            max-width: 450px;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        
        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 8px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            z-index: 2;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header img {
            height: 60px;
            margin-bottom: 15px;
        }
        
        .login-header h2 {
            color: var(--dark);
            font-weight: 600;
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .login-header p {
            color: #6c757d;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #495057;
            font-size: 14px;
        }
        
        .input-icon {
            position: absolute;
            left: 15px;
            top: 40px;
            color: #6c757d;
            font-size: 16px;
        }
        
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            transition: var(--transition);
            background-color: var(--light);
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }
        
        .login-button {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .login-button:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        .forgot-password {
            text-align: right;
            margin-top: -10px;
            margin-bottom: 20px;
        }
        
        .forgot-password a {
            font-size: 13px;
            color: var(--primary);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .forgot-password a:hover {
            text-decoration: underline;
            color: var(--primary-dark);
        }
        
        .login-footer {
            text-align: center;
            margin-top: 25px;
            font-size: 13px;
            color: #6c757d;
        }
        
        .login-footer a {
            color: var(--primary);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
        
        .error-message {
            color: #d32f2f;
            background-color: #fde8e8;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 14px;
            border: 1px solid #f5c6cb;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .error-message i {
            font-size: 16px;
        }
        
        /* Security indicators */
        .security-info {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 25px;
            font-size: 13px;
            color: #6c757d;
            border: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .security-info i {
            color: var(--primary);
            font-size: 16px;
        }
        
        /* Responsive adjustments */
        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
            }
            
            .login-header h2 {
                font-size: 20px;
            }
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .login-container {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body>
    
    <div class="login-container">
        <div class="login-header">
            <img src="id.jpg" alt="ID Card Generator Logo">
            <h2>Admin Portal</h2>
            <p>Access your ID card generation system</p>
        </div>
        
        <?php if ($success): ?>
            <div class="error-message" style="background:#d1fae5;color:#065f46;border-color:#a7f3d0;">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        
        <form action="admin/auth/authenticate.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-group">
                <label for="username">Username</label>
                <i class="fas fa-user input-icon"></i>
                <input type="text" id="username" name="username" placeholder="Enter admin username" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <i class="fas fa-lock input-icon"></i>
                <input type="password" id="password" name="password" placeholder="Enter your password" required>
            </div>
            
            <div class="forgot-password">
                <a href="admin/auth/forgot_password.php"><i class="fas fa-key"></i> Forgot password?</a>
            </div>
            
            <button type="submit" class="login-button">
                <i class="fas fa-sign-in-alt"></i> Log In
            </button>
        </form>
        
        <div class="security-info">
            <i class="fas fa-shield-alt"></i>
            <span>Secure login with session protection</span>
        </div>
        
        <div class="login-footer">
            <p>© <?php echo date('Y'); ?> ID Card Generator System. <a href="#">Privacy Policy</a></p>
        </div>
    </div>

    <script>
        // Enhance form functionality
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const username = document.getElementById('username');
            const password = document.getElementById('password');
            
            // Prevent form submission on Enter key in username field
            username.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    password.focus();
                }
            });
            
            // Add password visibility toggle
            const passwordToggle = document.createElement('span');
            passwordToggle.innerHTML = '<i class="fas fa-eye"></i>';
            passwordToggle.style.position = 'absolute';
            passwordToggle.style.right = '15px';
            passwordToggle.style.top = '40px';
            passwordToggle.style.cursor = 'pointer';
            passwordToggle.style.color = '#6c757d';
            passwordToggle.addEventListener('click', function() {
                if (password.type === 'password') {
                    password.type = 'text';
                    passwordToggle.innerHTML = '<i class="fas fa-eye-slash"></i>';
                } else {
                    password.type = 'password';
                    passwordToggle.innerHTML = '<i class="fas fa-eye"></i>';
                }
            });
            
            password.parentNode.appendChild(passwordToggle);
            password.parentNode.style.position = 'relative';
            
            // Form validation
            form.addEventListener('submit', function(e) {
                if (username.value.trim() === '' || password.value.trim() === '') {
                    e.preventDefault();
                    if (username.value.trim() === '') {
                        username.style.borderColor = '#d32f2f';
                    }
                    if (password.value.trim() === '') {
                        password.style.borderColor = '#d32f2f';
                    }
                }
            });
            
            // Reset border color on input
            username.addEventListener('input', function() {
                this.style.borderColor = '#e0e0e0';
            });
            
            password.addEventListener('input', function() {
                this.style.borderColor = '#e0e0e0';
            });
        });
    </script>
</body>
<?php
