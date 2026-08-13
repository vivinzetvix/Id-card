<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}
require_once 'config.php';

$page_title = 'Contact & Support';
$message = '';
$error = '';

// Get current user info
$username = $_SESSION['username'];
$userEmail = $_SESSION['email'] ?? '';
$userFullName = $_SESSION['full_name'] ?? $username;

// Handle contact form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid security token. Please refresh the page.";
    } else {
        $subject = trim($_POST['subject'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $message_text = trim($_POST['message'] ?? '');
        $priority = trim($_POST['priority'] ?? 'normal');
        $attachments = $_FILES['attachments'] ?? null;
        
        // Validate
        if (empty($subject)) {
            $error = "Please enter a subject.";
        } elseif (empty($message_text)) {
            $error = "Please enter your message.";
        } elseif (strlen($message_text) < 10) {
            $error = "Message must be at least 10 characters long.";
        } else {
            // Save to database (support_tickets table)
            $ticket_id = 'TKT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            
            // Check if support_tickets table exists, create if not
$stmt = $conn->prepare("
INSERT INTO support_tickets
(ticket_id, user_id, username, email, category, subject, message, priority)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
            
         
            
            $userId = $_SESSION['user_id'] ?? 0;
            $stmt->bind_param("sissssss", $ticket_id, $userId, $username, $userEmail, $category, $subject, $message_text, $priority);
            
            if ($stmt->execute()) {
                $message = "Your support ticket has been submitted successfully!<br>";
                $message .= "Ticket ID: <strong>$ticket_id</strong><br>";
                $message .= "We will get back to you within 24-48 hours.";
                
                // Send email notification
                sendSupportEmail($userEmail, $username, $ticket_id, $subject, $message_text);
                
                // Log activity
                logAuditActivity($conn, $username, 'Created support ticket', 'support', "Ticket: $ticket_id");
                
                // Clear form data
                $_POST = [];
            } else {
                $error = "Failed to submit ticket. Please try again.";
            }
            $stmt->close();
        }
    }
}

// Fetch user's tickets
$userTickets = [];
$userId = $_SESSION['user_id'] ?? 0;
if ($userId > 0) {
    $stmt = $conn->prepare("
        SELECT * FROM support_tickets 
        WHERE user_id = ? OR username = ? 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $stmt->bind_param("is", $userId, $username);
    $stmt->execute();
    $userTickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Helper functions
function logAuditActivity($conn, $username, $action, $action_type, $details) {
    $user_id = $_SESSION['user_id'] ?? null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, action_type, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("isssss", $user_id, $action, $action_type, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }
}

function sendSupportEmail($to, $name, $ticket_id, $subject, $message) {
    $email_subject = "Support Ticket: $ticket_id - $subject";
    $email_message = "Dear $name,\n\n";
    $email_message .= "Thank you for contacting support. Your ticket has been created successfully.\n\n";
    $email_message .= "Ticket ID: $ticket_id\n";
    $email_message .= "Subject: $subject\n\n";
    $email_message .= "We will review your query and get back to you shortly.\n\n";
    $email_message .= "Message:\n" . $message . "\n\n";
    $email_message .= "Best regards,\nID Card System Support Team";
    
    $headers = "From: support@idcardsystem.com\r\n";
    $headers .= "Reply-To: support@idcardsystem.com\r\n";
    
    mail($to, $email_subject, $email_message, $headers);
}

// Get system info for support
$systemInfo = [
    'php_version' => phpversion(),
    'mysql_version' => $conn->server_info,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'server_time' => date('Y-m-d H:i:s'),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
];

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Contact & Support · ID Card Generator</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #0a1a2f;
            --primary-light: #1e3a5f;
            --primary-soft: #e8f0fe;
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
            --neutral-500: #6b7280;
            --neutral-600: #4b5563;
            --neutral-700: #374151;
            --neutral-800: #1f2937;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;
        }

        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--neutral-50);
            color: var(--neutral-800);
            margin: 0;
            padding: 0;
        }

        .dashboard-wrapper { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: 280px; min-height: 100vh; background: var(--neutral-50); }
        .dashboard-content { padding: 2rem; max-width: 1600px; margin: 0 auto; }

        @media (max-width: 1024px) {
            .main-content { margin-left: 0; }
        }
        @media (max-width: 768px) {
            .dashboard-content { padding: 1rem; }
        }

        .breadcrumb {
            display: flex;
            gap: 0.5rem;
            list-style: none;
            padding: 0;
            margin: 0 0 1.5rem 0;
            font-size: 0.875rem;
        }
        .breadcrumb a { color: var(--primary); text-decoration: none; }
        .breadcrumb .active { color: var(--neutral-500); }

        /* Alert */
        .alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1rem;
            animation: slideIn 0.3s ease;
        }
        .alert-success { background: var(--success-soft); color: var(--success); }
        .alert-danger { background: var(--danger-soft); color: var(--danger); }
        .alert .btn-close-custom {
            cursor: pointer;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: inherit;
            opacity: 0.5;
            padding: 0 0.25rem;
        }
        .alert .btn-close-custom:hover { opacity: 1; }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .main-card {
            background: white;
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .card-header-custom {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--neutral-200);
            background: var(--neutral-50);
        }

        .card-header-custom h5 {
            font-weight: 600;
            margin: 0;
            color: var(--neutral-800);
        }
        .card-header-custom h5 i { color: var(--primary); margin-right: 0.5rem; }

        .card-body-custom { padding: 1.5rem; }

        .form-label {
            font-weight: 500;
            font-size: 0.875rem;
            color: var(--neutral-700);
        }
        .form-label .required { color: var(--danger); }

        .form-control, .form-select {
            border-radius: var(--radius-lg);
            border: 1px solid var(--neutral-300);
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            width: 100%;
            transition: all 0.2s;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10,26,47,0.1);
            outline: none;
        }
        textarea.form-control {
            resize: vertical;
            min-height: 120px;
        }

        .btn {
            border-radius: var(--radius-lg);
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-light); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #0d8b5e; }
        .btn-outline-secondary { background: transparent; border: 1px solid var(--neutral-300); color: var(--neutral-600); }
        .btn-outline-secondary:hover { background: var(--neutral-100); }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--neutral-200);
            flex-wrap: wrap;
        }

        /* Tickets Table */
        .tickets-table { font-size: 0.875rem; }
        .tickets-table th {
            font-size: 0.688rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--neutral-500);
            background: var(--neutral-100);
        }
        .tickets-table td { vertical-align: middle; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.688rem;
            font-weight: 500;
        }
        .status-badge.open { background: var(--info-soft); color: var(--info); }
        .status-badge.in_progress { background: var(--warning-soft); color: var(--warning); }
        .status-badge.resolved { background: var(--success-soft); color: var(--success); }
        .status-badge.closed { background: var(--neutral-100); color: var(--neutral-500); }

        .priority-badge {
            padding: 0.15rem 0.4rem;
            border-radius: var(--radius-sm);
            font-size: 0.625rem;
            font-weight: 600;
        }
        .priority-badge.low { background: var(--info-soft); color: var(--info); }
        .priority-badge.normal { background: var(--success-soft); color: var(--success); }
        .priority-badge.high { background: var(--warning-soft); color: var(--warning); }
        .priority-badge.urgent { background: var(--danger-soft); color: var(--danger); }

        /* Info Cards */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .info-card {
            background: var(--neutral-100);
            border-radius: var(--radius-lg);
            padding: 1rem;
            text-align: center;
        }

        .info-card .info-icon {
            font-size: 1.5rem;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }
        .info-card .info-label {
            font-size: 0.688rem;
            color: var(--neutral-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .info-card .info-value {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--neutral-700);
        }

        .faq-item {
            border-bottom: 1px solid var(--neutral-200);
            padding: 0.75rem 0;
        }
        .faq-item:last-child { border-bottom: none; }
        .faq-item .question {
            font-weight: 600;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .faq-item .question:hover { color: var(--primary); }
        .faq-item .answer {
            display: none;
            padding-top: 0.5rem;
            color: var(--neutral-600);
            font-size: 0.875rem;
        }
        .faq-item .answer.active { display: block; }

        @media (max-width: 768px) {
            .form-actions { flex-direction: column; }
            .form-actions .btn { width: 100%; justify-content: center; }
            .info-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 480px) {
            .info-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include 'includes/header.php'; ?>

            <div class="dashboard-content">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Contact & Support</li>
                    </ol>
                </nav>

                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div class="flex-1"><?= $message ?></div>
                        <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <div class="flex-1"><?= htmlspecialchars($error) ?></div>
                        <button type="button" class="btn-close-custom" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <!-- Quick Info -->
                <div class="info-grid mb-4">
                    <div class="info-card">
                        <div class="info-icon"><i class="fas fa-clock"></i></div>
                        <div class="info-label">Response Time</div>
                        <div class="info-value">24-48 Hours</div>
                    </div>
                    <div class="info-card">
                        <div class="info-icon"><i class="fas fa-envelope"></i></div>
                        <div class="info-label">Support Email</div>
                        <div class="info-value">support@idcardsystem.com</div>
                    </div>
                    <div class="info-card">
                        <div class="info-icon"><i class="fas fa-phone"></i></div>
                        <div class="info-label">Support Phone</div>
                        <div class="info-value">+1 (800) 555-0199</div>
                    </div>
                    <div class="info-card">
                        <div class="info-icon"><i class="fas fa-clock"></i></div>
                        <div class="info-label">Working Hours</div>
                        <div class="info-value">Mon-Fri 9AM-6PM</div>
                    </div>
                </div>

                <!-- Contact Form -->
                <div class="main-card">
                    <div class="card-header-custom">
                        <h5><i class="fas fa-headset text-primary me-2"></i>Contact Support</h5>
                        <p style="color:var(--neutral-500);font-size:0.875rem;margin:0;">
                            Submit a support ticket and our team will get back to you
                        </p>
                    </div>

                    <div class="card-body-custom">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Your Name</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($userFullName) ?>" readonly>
                                        <div class="form-text text-muted small">This will be sent with your ticket</div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Email Address</label>
                                        <input type="email" class="form-control" value="<?= htmlspecialchars($userEmail) ?>" readonly>
                                        <div class="form-text text-muted small">We'll reply to this email</div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Category <span class="required">*</span></label>
                                        <select name="category" class="form-select" required>
                                            <option value="general" <?= isset($_POST['category']) && $_POST['category'] === 'general' ? 'selected' : '' ?>>General Inquiry</option>
                                            <option value="technical" <?= isset($_POST['category']) && $_POST['category'] === 'technical' ? 'selected' : '' ?>>Technical Issue</option>
                                            <option value="billing" <?= isset($_POST['category']) && $_POST['category'] === 'billing' ? 'selected' : '' ?>>Billing / Payment</option>
                                            <option value="feature" <?= isset($_POST['category']) && $_POST['category'] === 'feature' ? 'selected' : '' ?>>Feature Request</option>
                                            <option value="bug" <?= isset($_POST['category']) && $_POST['category'] === 'bug' ? 'selected' : '' ?>>Bug Report</option>
                                            <option value="account" <?= isset($_POST['category']) && $_POST['category'] === 'account' ? 'selected' : '' ?>>Account Issue</option>
                                            <option value="other" <?= isset($_POST['category']) && $_POST['category'] === 'other' ? 'selected' : '' ?>>Other</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Priority</label>
                                        <select name="priority" class="form-select">
                                            <option value="low" <?= isset($_POST['priority']) && $_POST['priority'] === 'low' ? 'selected' : '' ?>>Low</option>
                                            <option value="normal" <?= (!isset($_POST['priority']) || $_POST['priority'] === 'normal') ? 'selected' : '' ?>>Normal</option>
                                            <option value="high" <?= isset($_POST['priority']) && $_POST['priority'] === 'high' ? 'selected' : '' ?>>High</option>
                                            <option value="urgent" <?= isset($_POST['priority']) && $_POST['priority'] === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <div class="mb-3">
                                        <label class="form-label">Subject <span class="required">*</span></label>
                                        <input type="text" name="subject" class="form-control" required 
                                               value="<?= isset($_POST['subject']) ? htmlspecialchars($_POST['subject']) : '' ?>"
                                               placeholder="Brief summary of your issue">
                                    </div>
                                </div>

                                <div class="col-12">
                                    <div class="mb-3">
                                        <label class="form-label">Message <span class="required">*</span></label>
                                        <textarea name="message" class="form-control" required 
                                                  placeholder="Describe your issue in detail..."><?= isset($_POST['message']) ? htmlspecialchars($_POST['message']) : '' ?></textarea>
                                        <div class="form-text text-muted small">Please provide as much detail as possible</div>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <div class="mb-3">
                                        <label class="form-label">Attachments (Optional)</label>
                                        <input type="file" name="attachments" class="form-control" multiple>
                                        <div class="form-text text-muted small">Max file size: 5MB. Allowed: JPG, PNG, PDF, DOC</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="fas fa-undo"></i> Reset
                                </button>
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-paper-plane"></i> Submit Ticket
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- FAQ Section -->
                <div class="main-card">
                    <div class="card-header-custom">
                        <h5><i class="fas fa-question-circle text-primary me-2"></i>Frequently Asked Questions</h5>
                    </div>
                    <div class="card-body-custom">
                        <div class="faq-item">
                            <div class="question" onclick="toggleFAQ(this)">
                                <span>How do I generate an ID card for a member?</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="answer">
                                Go to <strong>Members</strong> &gt; <strong>View Members</strong>, click on a member, then click <strong>Generate ID Card</strong>. Select a template and click Generate.
                            </div>
                        </div>

                        <div class="faq-item">
                            <div class="question" onclick="toggleFAQ(this)">
                                <span>What are the supported ID card sizes?</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="answer">
                                <strong>Portrait:</strong> 5.33 × 8.64 cm<br>
                                <strong>Landscape:</strong> 8.64 × 5.33 cm<br>
                                You can also customize the size in template settings.
                            </div>
                        </div>

                        <div class="faq-item">
                            <div class="question" onclick="toggleFAQ(this)">
                                <span>How do I print ID cards in bulk?</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="answer">
                                Go to <strong>Bulk Print</strong> from the sidebar, select the members you want to print, and click <strong>Print Selected</strong>. You can also use mirror print option for transparent cards.
                            </div>
                        </div>

                        <div class="faq-item">
                            <div class="question" onclick="toggleFAQ(this)">
                                <span>What happens when a card expires?</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="answer">
                                When a card expires, the member's status changes to "Expired". You can renew the member by editing their profile and updating the expiry date, then regenerate the ID card.
                            </div>
                        </div>

                        <div class="faq-item">
                            <div class="question" onclick="toggleFAQ(this)">
                                <span>How do I add custom fields to ID cards?</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="answer">
                                Go to <strong>Templates</strong> &gt; <strong>Edit Template</strong> &gt; <strong>Input Fields</strong> section. Click <strong>Add Input Field</strong> to create custom fields like Barcode, QR Code, Signature, etc.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- My Tickets -->
                <?php if (!empty($userTickets)): ?>
                    <div class="main-card">
                        <div class="card-header-custom">
                            <h5><i class="fas fa-ticket-alt text-primary me-2"></i>My Support Tickets</h5>
                            <span class="text-muted small"><?= count($userTickets) ?> tickets</span>
                        </div>
                        <div class="card-body-custom">
                            <div class="table-responsive">
                                <table class="table tickets-table">
                                    <thead>
                                        <tr>
                                            <th>Ticket ID</th>
                                            <th>Subject</th>
                                            <th>Category</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($userTickets as $ticket): ?>
                                            <tr>
                                                <td><code><?= htmlspecialchars($ticket['ticket_id']) ?></code></td>
                                                <td><?= htmlspecialchars($ticket['subject']) ?></td>
                                                <td><?= ucfirst($ticket['category']) ?></td>
                                                <td>
                                                    <span class="priority-badge <?= $ticket['priority'] ?>">
                                                        <?= ucfirst($ticket['priority']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?= str_replace('_', '', $ticket['status']) ?>">
                                                        <?= ucfirst(str_replace('_', ' ', $ticket['status'])) ?>
                                                    </span>
                                                </td>
                                                <td class="small text-muted">
                                                    <?= date('M d, Y', strtotime($ticket['created_at'])) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Toggle FAQ
        function toggleFAQ(element) {
            const answer = element.nextElementSibling;
            const icon = element.querySelector('.fa-chevron-down');
            
            if (answer.classList.contains('active')) {
                answer.classList.remove('active');
                icon.style.transform = 'rotate(0deg)';
            } else {
                // Close all other answers
                document.querySelectorAll('.faq-item .answer').forEach(a => {
                    a.classList.remove('active');
                    a.previousElementSibling.querySelector('.fa-chevron-down').style.transform = 'rotate(0deg)';
                });
                answer.classList.add('active');
                icon.style.transform = 'rotate(180deg)';
            }
        }

        // Auto-dismiss alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('submitBtn').click();
            }
        });

        // Touch-friendly
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .form-control, .form-select, .faq-item .question').forEach(el => {
                el.addEventListener('touchstart', function() {
                    this.style.opacity = '0.8';
                });
                el.addEventListener('touchend', function() {
                    this.style.opacity = '1';
                });
            });
        }

        // Form validation
        document.querySelector('form')?.addEventListener('submit', function(e) {
            const subject = document.querySelector('input[name="subject"]');
            const message = document.querySelector('textarea[name="message"]');
            
            if (!subject.value.trim()) {
                e.preventDefault();
                alert('Please enter a subject');
                subject.focus();
                return false;
            }
            
            if (!message.value.trim()) {
                e.preventDefault();
                alert('Please enter your message');
                message.focus();
                return false;
            }
            
            if (message.value.trim().length < 10) {
                e.preventDefault();
                alert('Message must be at least 10 characters long');
                message.focus();
                return false;
            }
            
            // Show loading state
            const btn = document.getElementById('submitBtn');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Submitting...';
            btn.disabled = true;
        });
    </script>
</body>
</html>