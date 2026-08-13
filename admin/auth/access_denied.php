<?php
/**
 * Access Denied — 403
 * Shown when a user tries to access a page or action they don't have permission for.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

http_response_code(403);

$username = $_SESSION['username'] ?? 'User';
$depth    = substr_count($_SERVER['PHP_SELF'] ?? '', '/') - 1;
$basePath = str_repeat('../', max(0, $depth - 1));

$backUrl = $_SERVER['HTTP_REFERER'] ?? $basePath . 'dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 — Access Denied · ID Card System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary:       #0a1a2f;
            --primary-light: #1e3a5f;
            --accent:        #e53e3e;
            --success:       #0e9f6e;
            --neutral-100:   #f3f4f6;
            --neutral-200:   #e5e7eb;
            --neutral-500:   #6b7280;
            --neutral-700:   #374151;
            --neutral-800:   #1f2937;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0a1a2f 0%, #1e3a5f 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 1.5rem;
        }

        .card {
            background: white;
            border-radius: 20px;
            padding: 3rem 2.5rem;
            max-width: 520px;
            width: 100%;
            text-align: center;
            box-shadow: 0 25px 60px rgba(0,0,0,.3);
            animation: fadeUp .5s ease-out;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0);    }
        }

        .icon-wrap {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
            color: var(--accent);
        }

        .code {
            font-size: 5rem;
            font-weight: 700;
            line-height: 1;
            color: var(--primary);
            letter-spacing: -2px;
            margin-bottom: .5rem;
        }

        .code span { color: var(--accent); }

        h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--neutral-800);
            margin-bottom: .75rem;
        }

        p {
            color: var(--neutral-500);
            font-size: .9375rem;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        p strong { color: var(--neutral-700); }

        .actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .75rem 1.5rem;
            border-radius: 10px;
            font-size: .9375rem;
            font-weight: 500;
            text-decoration: none;
            transition: all .25s ease;
            cursor: pointer;
            border: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(10,26,47,.3);
        }

        .btn-outline {
            background: white;
            color: var(--neutral-700);
            border: 1.5px solid var(--neutral-200);
        }

        .btn-outline:hover {
            background: var(--neutral-100);
            transform: translateY(-2px);
        }

        .divider {
            height: 1px;
            background: var(--neutral-200);
            margin: 2rem 0;
        }

        .tip {
            font-size: .8125rem;
            color: var(--neutral-500);
        }

        .tip i { color: var(--success); }
    </style>
</head>
<body>

    <div class="card">
        <div class="icon-wrap">
            <i class="fas fa-shield-alt"></i>
        </div>

        <div class="code">4<span>0</span>3</div>

        <h1>Access Denied</h1>

        <p>
            Sorry, <strong><?= htmlspecialchars($username) ?></strong>, you don't have permission to access
            this page or perform this action. This area requires elevated privileges.
        </p>

        <div class="actions">
            <a href="<?= htmlspecialchars($basePath . 'dashboard.php') ?>" class="btn btn-primary">
                <i class="fas fa-tachometer-alt"></i> Go to Dashboard
            </a>
            <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-outline" id="backBtn">
                <i class="fas fa-arrow-left"></i> Go Back
            </a>
        </div>

        <div class="divider"></div>

        <p class="tip">
            <i class="fas fa-info-circle"></i>
            If you believe this is an error, please contact your system administrator.
        </p>
    </div>

    <script>
        // If the referrer equals this page, disable the Go Back button
        if (document.referrer === window.location.href || !document.referrer) {
            var btn = document.getElementById('backBtn');
            btn.href = '<?= htmlspecialchars($basePath . 'dashboard.php') ?>';
        }
    </script>

</body>
</html>
