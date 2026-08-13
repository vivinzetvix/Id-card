<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}
require_once 'config.php';
require_once __DIR__ . '/Members/functions.php';

// Get current user info
$currentUser = $_SESSION['user'] ?? [];
$userOrgId = $_SESSION['organization_id'] ?? 0;
$userRole = $_SESSION['role'] ?? '';
$isSuperAdmin = ($userRole === 'Super Admin' || $userRole === 'super_admin' || $userRole === 'admin');

// Check if Dompdf is installed
$dompdfAvailable = class_exists('Dompdf\Dompdf');

$message = '';
$error = '';

// Get date range from request
$date_from = (!empty($_GET['date_from'])) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = (!empty($_GET['date_to'])) ? $_GET['date_to'] : date('Y-m-d');
$report_type = (!empty($_GET['report_type'])) ? $_GET['report_type'] : 'members';
$export_format = (!empty($_GET['export'])) ? $_GET['export'] : '';
$orgFilter = (!empty($_GET['org_id'])) ? (int)$_GET['org_id'] : 0;

// Validate and sanitize dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = date('Y-m-d');
}

// Ensure date_from is not after date_to
if (strtotime($date_from) > strtotime($date_to)) {
    $temp = $date_from;
    $date_from = $date_to;
    $date_to = $temp;
}

// Validate report type
$valid_types = ['members', 'activity', 'expiry', 'templates', 'users', 'organizations', 'cards'];
if (!in_array($report_type, $valid_types)) {
    $report_type = 'members';
}

// Build organization filter
$orgWhere = '';
$orgParams = [];
if (!$isSuperAdmin && $userOrgId > 0) {
    $orgWhere = 'AND m.organization_id = ?';
    $orgParams[] = $userOrgId;
} elseif ($isSuperAdmin && $orgFilter > 0) {
    $orgWhere = 'AND m.organization_id = ?';
    $orgParams[] = $orgFilter;
}

// Get organizations for filter
$organizations = [];
if ($isSuperAdmin) {
    $organizations = $pdo->query("SELECT id, organization_name, project_type FROM organizations WHERE deleted_at IS NULL AND status = 1 ORDER BY organization_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Helper functions
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function getMemberTypeLabel($type) {
    $labels = [
        'student' => 'Student',
        'employee' => 'Employee',
        'staff' => 'Staff',
        'faculty' => 'Faculty',
        'visitor' => 'Visitor',
        'office' => 'Office'
    ];
    return $labels[$type] ?? ucfirst($type);
}

function getStatusLabel($expiry_date) {
    $today = date('Y-m-d');
    $next30 = date('Y-m-d', strtotime('+30 days'));
    
    if (!$expiry_date) return 'Active';
    if ($expiry_date >= $today) {
        if ($expiry_date <= $next30) return 'Expiring Soon';
        return 'Active';
    }
    return 'Expired';
}

function getStatusClass($status) {
    $classes = [
        'Active' => 'status-active',
        'Expiring Soon' => 'status-warning',
        'Expired' => 'status-expired',
        'Critical' => 'status-critical',
        'Warning' => 'status-warning',
        'Good' => 'status-good'
    ];
    return $classes[$status] ?? 'status-active';
}

// Handle export
if ($export_format && in_array($export_format, ['csv', 'excel', 'pdf'])) {
    set_time_limit(300);
    
    $data = [];
    $filename = 'report_' . $report_type . '_' . date('Y-m-d_His');
    
    // Get data based on report type
    switch ($report_type) {
        case 'members':
            $query = "SELECT 
                m.id,
                m.unique_id,
                m.name,
                m.member_type,
                m.class,
                m.department,
                m.company,
                m.email,
                m.emergency_contact,
                m.joined_date,
                m.expiry_date,
                CASE 
                    WHEN m.expiry_date < CURDATE() THEN 'Expired'
                    WHEN m.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Expiring Soon'
                    ELSE 'Active'
                END as status,
                DATEDIFF(m.expiry_date, CURDATE()) as days_left,
                o.organization_name,
                DATE_FORMAT(m.created_at, '%d/%m/%Y') as created_date
                FROM id_members m
                LEFT JOIN organizations o ON m.organization_id = o.id
                WHERE DATE(m.created_at) BETWEEN ? AND ? $orgWhere
                ORDER BY m.created_at DESC";
            $stmt = $pdo->prepare($query);
            $params = array_merge([$date_from, $date_to], $orgParams);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'activity':
            $query = "SELECT 
                a.id,
                a.action,
                a.action_type,
                a.details,
                a.ip_address,
                u.username as user,
                DATE_FORMAT(a.created_at, '%d/%m/%Y %H:%i') as created_at
                FROM audit_log a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE DATE(a.created_at) BETWEEN ? AND ?
                ORDER BY a.created_at DESC";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$date_from, $date_to]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'expiry':
            $query = "SELECT 
                m.id,
                m.unique_id,
                m.name,
                m.member_type,
                m.email,
                m.emergency_contact,
                m.joined_date,
                m.expiry_date,
                DATEDIFF(m.expiry_date, CURDATE()) as days_left,
                o.organization_name,
                CASE 
                    WHEN m.expiry_date < CURDATE() THEN 'Expired'
                    WHEN m.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Critical'
                    WHEN m.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Warning'
                    ELSE 'Good'
                END as urgency
                FROM id_members m
                LEFT JOIN organizations o ON m.organization_id = o.id
                WHERE m.expiry_date BETWEEN ? AND ? $orgWhere
                ORDER BY m.expiry_date ASC";
            $stmt = $pdo->prepare($query);
            $params = array_merge([$date_from, $date_to], $orgParams);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'templates':
            $query = "SELECT 
                t.id,
                t.name,
                t.orientation,
                t.downloads,
                t.rating,
                t.is_default,
                u.username as created_by_username,
                DATE_FORMAT(t.created_at, '%d/%m/%Y') as created_date,
                COUNT(m.id) as member_count
                FROM card_templates t
                LEFT JOIN users u ON t.created_by = u.id
                LEFT JOIN id_members m ON t.id = m.template_id
                WHERE t.status = 1
                GROUP BY t.id
                ORDER BY t.downloads DESC";
            $stmt = $pdo->query($query);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'users':
            $query = "SELECT 
                u.id,
                u.username,
                u.full_name,
                u.email,
                u.role,
                DATE_FORMAT(u.last_login, '%d/%m/%Y %H:%i') as last_login,
                DATE_FORMAT(u.created_at, '%d/%m/%Y') as created_at,
                COUNT(DISTINCT a.id) as action_count
                FROM users u
                LEFT JOIN audit_log a ON u.id = a.user_id
                WHERE u.deleted_at IS NULL
                GROUP BY u.id
                ORDER BY u.created_at DESC";
            $stmt = $pdo->query($query);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'organizations':
            $query = "SELECT 
                o.id,
                o.organization_name,
                o.organization_code,
                o.project_type,
                o.organization_type,
                o.status,
                COUNT(m.id) as member_count,
                SUM(CASE WHEN m.expiry_date >= CURDATE() THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN m.expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired_count
                FROM organizations o
                LEFT JOIN id_members m ON o.id = m.organization_id
                WHERE o.deleted_at IS NULL
                GROUP BY o.id
                ORDER BY member_count DESC";
            $stmt = $pdo->query($query);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'cards':
            $query = "SELECT 
                g.id,
                m.name,
                m.unique_id,
                t.name as template_name,
                DATE_FORMAT(g.created_at, '%d/%m/%Y %H:%i') as generated_at,
                g.print_count,
                DATE_FORMAT(g.last_printed_at, '%d/%m/%Y %H:%i') as last_printed_at
                FROM generated_cards g
                LEFT JOIN id_members m ON g.member_id = m.id
                LEFT JOIN card_templates t ON g.template_id = t.id
                WHERE DATE(g.created_at) BETWEEN ? AND ?
                ORDER BY g.created_at DESC";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$date_from, $date_to]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
    
    // CSV Export
    if ($export_format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, array_keys($data[0] ?? []));
        
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit();
    }
    
    // Excel Export
    if ($export_format === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        
        echo "<table border='1'>";
        if (!empty($data)) {
            echo "<tr>";
            foreach (array_keys($data[0]) as $header) {
                echo "<th>" . htmlspecialchars($header) . "</th>";
            }
            echo "</tr>";
            
            foreach ($data as $row) {
                echo "<tr>";
                foreach ($row as $cell) {
                    echo "<td>" . htmlspecialchars($cell) . "</td>";
                }
                echo "</tr>";
            }
        }
        echo "</table>";
        exit();
    }
    
    // PDF Export
    if ($export_format === 'pdf' && $dompdfAvailable) {
        $options = new Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        
        $dompdf = new Dompdf\Dompdf($options);
        
        $titleMap = [
            'members' => 'Member List Report',
            'activity' => 'Activity Log Report',
            'expiry' => 'Expiry Report',
            'templates' => 'Template Usage Report',
            'users' => 'User Activity Report',
            'organizations' => 'Organization Report',
            'cards' => 'Generated Cards Report'
        ];
        $reportTitle = $titleMap[$report_type] ?? 'Report';
        $generatedAt = date('d/m/Y H:i');
        
        $pdfHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            body{font-family: DejaVu Sans, Arial, sans-serif; font-size:11px; color:#1f2937;}
            h1{font-size:18px; margin:0 0 6px;}
            p{margin:0 0 10px; color:#4b5563;}
            table{width:100%; border-collapse:collapse; margin-top:12px;}
            th,td{border:1px solid #d1d5db; padding:6px 8px; vertical-align:top;}
            th{background:#f3f4f6; text-align:left;}
            tr:nth-child(even) td{background:#fafafa;}
            .meta{margin-bottom:12px;}
            .small{color:#6b7280; font-size:10px;}
        </style></head><body>';
        $pdfHtml .= '<h1>' . htmlspecialchars($reportTitle) . '</h1>';
        $pdfHtml .= '<div class="meta"><p>Date range: ' . htmlspecialchars($date_from) . ' to ' . htmlspecialchars($date_to) . '</p><p class="small">Generated at: ' . htmlspecialchars($generatedAt) . '</p></div>';
        
        if (!empty($data)) {
            $pdfHtml .= '<table><thead><tr>';
            foreach (array_keys($data[0]) as $header) {
                $pdfHtml .= '<th>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $header))) . '</th>';
            }
            $pdfHtml .= '</tr></thead><tbody>';
            
            foreach ($data as $row) {
                $pdfHtml .= '<tr>';
                foreach ($row as $cell) {
                    $cellValue = is_scalar($cell) ? (string)$cell : '';
                    $pdfHtml .= '<td>' . nl2br(htmlspecialchars($cellValue)) . '</td>';
                }
                $pdfHtml .= '</tr>';
            }
            $pdfHtml .= '</tbody></table>';
        } else {
            $pdfHtml .= '<p>No records found for the selected range.</p>';
        }
        
        $pdfHtml .= '</body></html>';
        
        $dompdf->loadHtml($pdfHtml);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
        echo $dompdf->output();
        exit();
    }
}

// Get statistics for dashboard
$today = date('Y-m-d');
$next30 = date('Y-m-d', strtotime('+30 days'));

// Total members by type
$stmt = $pdo->query("SELECT member_type, COUNT(*) as count FROM id_members GROUP BY member_type");
$members_by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Members by status
$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN expiry_date >= ? THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN expiry_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as expiring_soon,
        SUM(CASE WHEN expiry_date < ? THEN 1 ELSE 0 END) as expired
    FROM id_members
");
$stmt->execute([$today, $today, $next30, $today]);
$members_by_status = $stmt->fetch(PDO::FETCH_ASSOC);

// Recent activity count
$stmt = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$recent_activity = $stmt->fetchColumn();

// Template stats
$stmt = $pdo->query("SELECT COUNT(*) FROM card_templates WHERE status = 1");
$template_count = $stmt->fetchColumn();

// Organization stats
$stmt = $pdo->query("SELECT COUNT(*) FROM organizations WHERE deleted_at IS NULL AND status = 1");
$org_count = $stmt->fetchColumn();

// User stats
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL");
$user_count = $stmt->fetchColumn();

// Monthly trends (last 12 months)
$monthly_trends = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $month_name = date('M Y', strtotime("-$i months"));
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM id_members WHERE DATE_FORMAT(created_at, '%Y-%m') = ?");
    $stmt->execute([$month]);
    $count = $stmt->fetchColumn();
    
    $monthly_trends[] = [
        'month' => $month_name,
        'count' => $count
    ];
}

// Department/Class distribution
$dept_query = "SELECT 
    COALESCE(class, department, company, 'Other') as category,
    COUNT(*) as count 
    FROM id_members 
    GROUP BY category 
    ORDER BY count DESC 
    LIMIT 10";
$distribution = $pdo->query($dept_query)->fetchAll(PDO::FETCH_ASSOC);

// Get report data based on type
$report_data = [];
$report_columns = [];

// Build organization filter for report data
$reportOrgWhere = '';
$reportOrgParams = [];
if (!$isSuperAdmin && $userOrgId > 0) {
    $reportOrgWhere = 'AND m.organization_id = ?';
    $reportOrgParams[] = $userOrgId;
} elseif ($isSuperAdmin && $orgFilter > 0) {
    $reportOrgWhere = 'AND m.organization_id = ?';
    $reportOrgParams[] = $orgFilter;
}

switch ($report_type) {
    case 'members':
        $query = "SELECT 
            m.id,
            m.unique_id,
            m.name,
            m.member_type,
            m.class,
            m.department,
            m.company,
            m.email,
            m.emergency_contact,
            DATE_FORMAT(m.joined_date, '%d/%m/%Y') as joined_date,
            DATE_FORMAT(m.expiry_date, '%d/%m/%Y') as expiry_date,
            CASE 
                WHEN m.expiry_date < CURDATE() THEN 'Expired'
                WHEN m.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Expiring Soon'
                ELSE 'Active'
            END as status,
            DATEDIFF(m.expiry_date, CURDATE()) as days_left,
            o.organization_name,
            DATE_FORMAT(m.created_at, '%d/%m/%Y') as created_date
            FROM id_members m
            LEFT JOIN organizations o ON m.organization_id = o.id
            WHERE DATE(m.created_at) BETWEEN ? AND ? $reportOrgWhere
            ORDER BY m.created_at DESC";
        $stmt = $pdo->prepare($query);
        $params = array_merge([$date_from, $date_to], $reportOrgParams);
        $stmt->execute($params);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $report_columns = ['ID', 'Unique ID', 'Name', 'Type', 'Class/Dept', 'Email', 'Contact', 'Organization', 'Joined', 'Expiry', 'Status', 'Days Left'];
        break;
        
    case 'activity':
        $query = "SELECT 
            a.id,
            a.action,
            a.action_type,
            a.details,
            a.ip_address,
            u.username as user,
            DATE_FORMAT(a.created_at, '%d/%m/%Y %H:%i') as created_at
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE DATE(a.created_at) BETWEEN ? AND ?
            ORDER BY a.created_at DESC
            LIMIT 500";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$date_from, $date_to]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $report_columns = ['ID', 'User', 'Action', 'Type', 'Details', 'IP Address', 'Date/Time'];
        break;
        
    case 'expiry':
        $query = "SELECT 
            m.id,
            m.unique_id,
            m.name,
            m.member_type,
            m.email,
            m.emergency_contact,
            DATE_FORMAT(m.joined_date, '%d/%m/%Y') as joined_date,
            DATE_FORMAT(m.expiry_date, '%d/%m/%Y') as expiry_date,
            DATEDIFF(m.expiry_date, CURDATE()) as days_left,
            o.organization_name,
            CASE 
                WHEN m.expiry_date < CURDATE() THEN 'Expired'
                WHEN m.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Critical'
                WHEN m.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Warning'
                ELSE 'Good'
            END as urgency
            FROM id_members m
            LEFT JOIN organizations o ON m.organization_id = o.id
            WHERE m.expiry_date BETWEEN ? AND ? $reportOrgWhere
            ORDER BY m.expiry_date ASC";
        $stmt = $pdo->prepare($query);
        $params = array_merge([$date_from, $date_to], $reportOrgParams);
        $stmt->execute($params);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $report_columns = ['ID', 'Unique ID', 'Name', 'Type', 'Email', 'Contact', 'Organization', 'Joined', 'Expiry', 'Days Left', 'Urgency'];
        break;
        
    case 'templates':
        $query = "SELECT 
            t.id,
            t.name,
            t.orientation,
            t.downloads,
            t.rating,
            t.is_default,
            u.username as created_by,
            DATE_FORMAT(t.created_at, '%d/%m/%Y') as created_date,
            COUNT(m.id) as member_count
            FROM card_templates t
            LEFT JOIN users u ON t.created_by = u.id
            LEFT JOIN id_members m ON t.id = m.template_id
            WHERE t.status = 1
            GROUP BY t.id
            ORDER BY t.downloads DESC";
        $stmt = $pdo->query($query);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $report_columns = ['ID', 'Name', 'Orientation', 'Members', 'Downloads', 'Rating', 'Default', 'Created By', 'Created Date'];
        break;
        
    case 'users':
        $query = "SELECT 
            u.id,
            u.username,
            u.full_name,
            u.email,
            u.role,
            DATE_FORMAT(u.last_login, '%d/%m/%Y %H:%i') as last_login,
            DATE_FORMAT(u.created_at, '%d/%m/%Y') as created_at,
            COUNT(DISTINCT a.id) as action_count
            FROM users u
            LEFT JOIN audit_log a ON u.id = a.user_id
            WHERE u.deleted_at IS NULL
            GROUP BY u.id
            ORDER BY u.created_at DESC";
        $stmt = $pdo->query($query);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $report_columns = ['ID', 'Username', 'Full Name', 'Email', 'Role', 'Last Login', 'Created', 'Actions'];
        break;
        
    case 'organizations':
        $query = "SELECT 
            o.id,
            o.organization_name,
            o.organization_code,
            o.project_type,
            o.organization_type,
            CASE WHEN o.status = 1 THEN 'Active' ELSE 'Inactive' END as status,
            COUNT(m.id) as member_count,
            SUM(CASE WHEN m.expiry_date >= CURDATE() THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN m.expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired_count
            FROM organizations o
            LEFT JOIN id_members m ON o.id = m.organization_id
            WHERE o.deleted_at IS NULL
            GROUP BY o.id
            ORDER BY member_count DESC";
        $stmt = $pdo->query($query);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $report_columns = ['ID', 'Name', 'Code', 'Project Type', 'Org Type', 'Status', 'Members', 'Active', 'Expired'];
        break;
        
    case 'cards':
        $query = "SELECT 
            g.id,
            m.name,
            m.unique_id,
            t.name as template_name,
            DATE_FORMAT(g.created_at, '%d/%m/%Y %H:%i') as generated_at,
            g.print_count,
            DATE_FORMAT(g.last_printed_at, '%d/%m/%Y %H:%i') as last_printed_at
            FROM generated_cards g
            LEFT JOIN id_members m ON g.member_id = m.id
            LEFT JOIN card_templates t ON g.template_id = t.id
            WHERE DATE(g.created_at) BETWEEN ? AND ?
            ORDER BY g.created_at DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$date_from, $date_to]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $report_columns = ['ID', 'Member', 'Unique ID', 'Template', 'Generated At', 'Print Count', 'Last Printed'];
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Reports & Analytics · ID Card Generator</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <!-- Flatpickr for date picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

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
            
            --sidebar-width: 280px;
            --header-height: 70px;
            --mobile-breakpoint: 1024px;
            --tablet-breakpoint: 768px;
            --phone-breakpoint: 480px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--neutral-50);
            color: var(--neutral-800);
            line-height: 1.5;
            overflow-x: hidden;
            width: 100%;
        }

        /* Layout */
        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            background: var(--neutral-50);
            transition: margin-left 0.3s ease;
            width: calc(100% - var(--sidebar-width));
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }
        }

        .content-area {
            padding: 1.5rem;
            max-width: 1600px;
            margin: 0 auto;
        }

        @media (max-width: 768px) {
            .content-area {
                padding: 1rem;
            }
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-xl);
            padding: 1.25rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.125rem;
            margin-bottom: 0.5rem;
        }

        .stat-icon.primary { background: var(--primary-soft); color: var(--primary); }
        .stat-icon.success { background: var(--success-soft); color: var(--success); }
        .stat-icon.warning { background: var(--warning-soft); color: var(--warning); }
        .stat-icon.danger { background: var(--danger-soft); color: var(--danger); }
        .stat-icon.info { background: var(--info-soft); color: var(--info); }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--neutral-800);
            line-height: 1.2;
        }

        @media (max-width: 480px) {
            .stat-value {
                font-size: 1.5rem;
            }
        }

        .stat-label {
            font-size: 0.813rem;
            color: var(--neutral-500);
        }

        .stat-trend {
            font-size: 0.75rem;
            color: var(--neutral-500);
            margin-top: 0.25rem;
        }

        .trend-up { color: var(--success); }
        .trend-down { color: var(--danger); }

        /* Charts Row */
        .charts-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 768px) {
            .charts-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }

        .chart-card {
            background: white;
            border-radius: var(--radius-xl);
            padding: 1.25rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
        }

        .chart-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .chart-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--neutral-700);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }

        @media (max-width: 480px) {
            .chart-container {
                height: 200px;
            }
        }

        /* Report Controls */
        .report-controls {
            background: white;
            border-radius: var(--radius-xl);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
        }

        .controls-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1rem;
            align-items: flex-end;
        }

        @media (max-width: 768px) {
            .controls-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
        }

        .control-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .control-label {
            font-weight: 500;
            font-size: 0.813rem;
            color: var(--neutral-600);
        }

        .control-input, .control-select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--neutral-300);
            border-radius: var(--radius-lg);
            font-size: 0.875rem;
            width: 100%;
            background: white;
        }

        .control-input:focus, .control-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 26, 47, 0.1);
        }

        .control-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-position: right 0.75rem center;
            background-repeat: no-repeat;
            background-size: 1.25rem;
            padding-right: 2rem;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-lg);
            font-weight: 500;
            font-size: 0.875rem;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }

        @media (max-width: 768px) {
            .btn {
                width: 100%;
                padding: 0.625rem 1rem;
            }
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--neutral-300);
            color: var(--neutral-600);
        }
        .btn-outline:hover { background: var(--neutral-100); }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            color: white;
        }
        .btn-success:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }

        .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.813rem; }

        .btn-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .btn-group { flex-direction: column; }
            .btn-group .btn { width: 100%; }
        }

        /* Export Options */
        .export-options {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            justify-content: flex-end;
            flex-wrap: wrap;
            align-items: center;
        }

        @media (max-width: 768px) {
            .export-options {
                flex-direction: column;
                align-items: stretch;
            }
            .export-options span:first-child {
                text-align: center;
            }
        }

        .export-btn {
            padding: 0.375rem 0.75rem;
            border: 1px solid var(--neutral-300);
            border-radius: var(--radius-lg);
            background: white;
            color: var(--neutral-600);
            font-size: 0.813rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        @media (max-width: 768px) {
            .export-btn { width: 100%; justify-content: center; }
        }

        .export-btn:hover { background: var(--neutral-100); transform: translateY(-2px); }
        .export-btn.csv:hover { background: var(--success); color: white; border-color: var(--success); }
        .export-btn.excel:hover { background: #1e6f42; color: white; border-color: #1e6f42; }
        .export-btn.pdf:hover { background: var(--danger); color: white; border-color: var(--danger); }

        /* Report Table */
        .report-table-container {
            background: white;
            border-radius: var(--radius-xl);
            padding: 1.25rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        @media (max-width: 768px) {
            .report-table-container { padding: 0.75rem; }
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
            font-size: 0.875rem;
        }

        @media (max-width: 768px) {
            .report-table { min-width: 500px; font-size: 0.813rem; }
        }

        .report-table th {
            text-align: left;
            padding: 0.75rem 0.75rem;
            font-weight: 600;
            color: var(--neutral-600);
            background: var(--neutral-100);
            border-bottom: 2px solid var(--neutral-200);
            white-space: nowrap;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .report-table td {
            padding: 0.75rem 0.75rem;
            border-bottom: 1px solid var(--neutral-100);
            color: var(--neutral-700);
        }

        .report-table tr:hover td { background: var(--neutral-50); }
        .report-table tr:last-child td { border-bottom: none; }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            white-space: nowrap;
        }

        .status-active { background: var(--success-soft); color: var(--success); }
        .status-expired { background: var(--danger-soft); color: var(--danger); }
        .status-warning { background: var(--warning-soft); color: var(--warning); }
        .status-critical { background: var(--danger-soft); color: var(--danger); font-weight: 600; }
        .status-good { background: var(--success-soft); color: var(--success); }

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
        .alert-error { background: var(--danger-soft); color: var(--danger); }

        .alert-close {
            cursor: pointer;
            opacity: 0.5;
            margin-left: auto;
            padding: 0 0.25rem;
        }
        .alert-close:hover { opacity: 1; }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Breadcrumb */
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

        /* Responsive helpers */
        .desktop-only { display: inline; }
        .mobile-only { display: none; }

        @media (max-width: 768px) {
            .desktop-only { display: none; }
            .mobile-only { display: inline; }
            .text-truncate-mobile {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 120px;
                display: inline-block;
            }
        }

        /* Touch-friendly */
        @media (max-width: 1024px) {
            .btn, .export-btn, .control-select, .control-input {
                min-height: 44px;
            }
        }

        /* Print */
        @media print {
            .sidebar, .top-header, .report-controls, .export-options, .btn-group, .alert-close {
                display: none !important;
            }
            .main-content { margin-left: 0; padding: 0; }
            .content-area { padding: 0.5rem; }
            .report-table-container { box-shadow: none; border: 1px solid #ddd; }
            .report-table th { background: #f5f5f5; }
            .stats-grid, .charts-row { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="dashboard-wrapper">
        <main class="main-content">
            <?php include 'includes/header.php'; ?>

            <div class="content-area">
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Reports</li>
                    </ol>
                </nav>

                <!-- Alerts -->
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div class="flex-1"><?= htmlspecialchars($message) ?></div>
                        <i class="fas fa-times alert-close" onclick="this.parentElement.remove()"></i>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <div class="flex-1"><?= htmlspecialchars($error) ?></div>
                        <i class="fas fa-times alert-close" onclick="this.parentElement.remove()"></i>
                    </div>
                <?php endif; ?>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon primary"><i class="fas fa-users"></i></div>
                        <div class="stat-value"><?= number_format(array_sum(array_column($members_by_type, 'count'))) ?></div>
                        <div class="stat-label">Total Members</div>
                        <div class="stat-trend"><span class="trend-up"><i class="fas fa-arrow-up"></i> +<?= end($monthly_trends)['count'] ?? 0 ?> this month</span></div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-value"><?= number_format((int)$members_by_status['active']) ?></div>
                        <div class="stat-label">Active Members</div>
                        <div class="stat-trend"><?= round(($members_by_status['active'] / max(1, array_sum(array_column($members_by_type, 'count')))) * 100) ?>% of total</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon warning"><i class="fas fa-clock"></i></div>
                        <div class="stat-value"><?= number_format((int)$members_by_status['expiring_soon']) ?></div>
                        <div class="stat-label">Expiring Soon</div>
                        <div class="stat-trend"><span class="trend-down"><i class="fas fa-exclamation-triangle"></i> Need attention</span></div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon danger"><i class="fas fa-exclamation-circle"></i></div>
                        <div class="stat-value"><?= number_format((int)$members_by_status['expired']) ?></div>
                        <div class="stat-label">Expired Cards</div>
                        <div class="stat-trend">Requires renewal</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon info"><i class="fas fa-paint-brush"></i></div>
                        <div class="stat-value"><?= number_format($template_count) ?></div>
                        <div class="stat-label">Templates</div>
                        <div class="stat-trend"><i class="fas fa-download"></i> Available</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon primary"><i class="fas fa-building"></i></div>
                        <div class="stat-value"><?= number_format($org_count) ?></div>
                        <div class="stat-label">Organizations</div>
                        <div class="stat-trend">Active organizations</div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="charts-row">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3><i class="fas fa-chart-pie"></i> <span class="desktop-only">Members by Type</span><span class="mobile-only">By Type</span></h3>
                            <span class="badge" style="background:var(--neutral-100);padding:0.2rem 0.6rem;border-radius:9999px;font-size:0.688rem;"><?= count($members_by_type) ?> types</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="typeChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-card">
                        <div class="chart-header">
                            <h3><i class="fas fa-chart-line"></i> <span class="desktop-only">Monthly Registrations</span><span class="mobile-only">Monthly</span></h3>
                            <span class="badge" style="background:var(--neutral-100);padding:0.2rem 0.6rem;border-radius:9999px;font-size:0.688rem;">Last 12 months</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Report Controls -->
                <div class="report-controls">
                    <form method="GET" action="" id="reportForm">
                        <div class="controls-grid">
                            <div class="control-group">
                                <label class="control-label">Report Type</label>
                                <select name="report_type" class="control-select">
                                    <option value="members" <?= $report_type == 'members' ? 'selected' : '' ?>>Member List</option>
                                    <option value="activity" <?= $report_type == 'activity' ? 'selected' : '' ?>>Activity Log</option>
                                    <option value="expiry" <?= $report_type == 'expiry' ? 'selected' : '' ?>>Expiry Report</option>
                                    <option value="templates" <?= $report_type == 'templates' ? 'selected' : '' ?>>Template Usage</option>
                                    <option value="users" <?= $report_type == 'users' ? 'selected' : '' ?>>User Activity</option>
                                    <option value="organizations" <?= $report_type == 'organizations' ? 'selected' : '' ?>>Organizations</option>
                                    <option value="cards" <?= $report_type == 'cards' ? 'selected' : '' ?>>Generated Cards</option>
                                </select>
                            </div>

                            <?php if ($isSuperAdmin): ?>
                            <div class="control-group">
                                <label class="control-label">Organization</label>
                                <select name="org_id" class="control-select">
                                    <option value="0">All Organizations</option>
                                    <?php foreach ($organizations as $org): ?>
                                        <option value="<?= (int)$org['id'] ?>" <?= $orgFilter == $org['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($org['organization_name']) ?>
                                            <?php if ($org['project_type']): ?>
                                                (<?= ucfirst($org['project_type']) ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="control-group">
                                <label class="control-label">Date From</label>
                                <input type="text" name="date_from" class="control-input datepicker" 
                                       value="<?= htmlspecialchars($date_from) ?>" placeholder="YYYY-MM-DD">
                            </div>

                            <div class="control-group">
                                <label class="control-label">Date To</label>
                                <input type="text" name="date_to" class="control-input datepicker" 
                                       value="<?= htmlspecialchars($date_to) ?>" placeholder="YYYY-MM-DD">
                            </div>

                            <div class="control-group">
                                <label class="control-label">&nbsp;</label>
                                <div class="btn-group">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i> <span class="desktop-only">Generate</span>
                                    </button>
                                    <a href="reports.php" class="btn btn-outline">
                                        <i class="fas fa-undo"></i> <span class="desktop-only">Reset</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>

                    <!-- Export Options -->
                    <?php if (!empty($report_data)): ?>
                    <div class="export-options">
                        <span style="color:var(--neutral-500);font-size:0.813rem;">
                            <i class="fas fa-database"></i> <span class="desktop-only"><?= count($report_data) ?> records found</span>
                            <span class="mobile-only"><?= count($report_data) ?> items</span>
                        </span>
                        <a href="?report_type=<?= $report_type ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&org_id=<?= $orgFilter ?>&export=csv" class="export-btn csv">
                            <i class="fas fa-file-csv"></i> CSV
                        </a>
                        <a href="?report_type=<?= $report_type ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&org_id=<?= $orgFilter ?>&export=excel" class="export-btn excel">
                            <i class="fas fa-file-excel"></i> Excel
                        </a>
                        <?php if ($dompdfAvailable): ?>
                        <a href="?report_type=<?= $report_type ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&org_id=<?= $orgFilter ?>&export=pdf" class="export-btn pdf">
                            <i class="fas fa-file-pdf"></i> PDF
                        </a>
                        <?php endif; ?>
                        <button type="button" class="export-btn" onclick="window.print()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Report Table -->
                <div class="report-table-container">
                    <?php if (empty($report_data)): ?>
                        <div style="text-align:center;padding:2rem 1rem;color:var(--neutral-500);">
                            <i class="fas fa-chart-bar" style="font-size:3rem;margin-bottom:1rem;opacity:0.5;"></i>
                            <h3 style="margin-bottom:0.5rem;font-size:1.125rem;">No Data Found</h3>
                            <p style="font-size:0.875rem;">No records available for the selected criteria.</p>
                            <a href="reports.php" class="btn btn-outline btn-sm" style="margin-top:1rem;">
                                <i class="fas fa-undo"></i> Clear Filters
                            </a>
                        </div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <?php foreach ($report_columns as $column): ?>
                                        <th><?= htmlspecialchars($column) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $key => $value): ?>
                                            <td>
                                                <?php if ($key == 'status'): ?>
                                                    <span class="status-badge <?= getStatusClass($value) ?>">
                                                        <?= htmlspecialchars($value) ?>
                                                    </span>
                                                <?php elseif ($key == 'urgency'): ?>
                                                    <span class="status-badge <?= getStatusClass($value) ?>">
                                                        <?= htmlspecialchars($value) ?>
                                                    </span>
                                                <?php elseif ($key == 'days_left'): ?>
                                                    <?php if ($value < 0): ?>
                                                        <span style="color:var(--danger);font-weight:500;">Expired</span>
                                                    <?php elseif ($value <= 7): ?>
                                                        <span style="color:var(--danger);font-weight:600;"><?= $value ?> days</span>
                                                    <?php elseif ($value <= 30): ?>
                                                        <span style="color:var(--warning);"><?= $value ?> days</span>
                                                    <?php else: ?>
                                                        <span style="color:var(--success);"><?= $value ?> days</span>
                                                    <?php endif; ?>
                                                <?php elseif ($key == 'rating'): ?>
                                                    <span style="color:var(--warning);">
                                                        <?= str_repeat('★', (int)$value) ?><?= str_repeat('☆', 5 - (int)$value) ?>
                                                    </span>
                                                <?php elseif ($key == 'is_default'): ?>
                                                    <?= $value ? '⭐ Yes' : '—' ?>
                                                <?php elseif ($key == 'orientation'): ?>
                                                    <span class="badge <?= $value === 'landscape' ? 'bg-info' : 'bg-secondary' ?>">
                                                        <?= ucfirst($value) ?>
                                                    </span>
                                                <?php elseif (is_numeric($value) && $key == 'id'): ?>
                                                    #<?= $value ?>
                                                <?php elseif (empty($value) && $value !== '0'): ?>
                                                    —
                                                <?php else: ?>
                                                    <span class="text-truncate-mobile"><?= htmlspecialchars($value) ?></span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });

        document.addEventListener('click', function(event) {
            const sidebar = document.querySelector('.sidebar');
            const menuToggle = document.getElementById('menuToggle');
            if (window.innerWidth <= 1024) {
                if (sidebar && menuToggle && !sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Initialize date pickers
        flatpickr(".datepicker", {
            dateFormat: "Y-m-d",
            maxDate: "today",
            allowInput: true,
            disableMobile: false
        });

        // Auto-submit on filter change
        document.querySelectorAll('.control-select').forEach(select => {
            select.addEventListener('change', function() {
                this.closest('form').submit();
            });
        });

        // Form validation
        document.getElementById('reportForm')?.addEventListener('submit', function(e) {
            const dateFrom = document.querySelector('input[name="date_from"]').value.trim();
            const dateTo = document.querySelector('input[name="date_to"]').value.trim();
            
            if (dateFrom === '' || dateTo === '') {
                e.preventDefault();
                alert('Please select both "Date From" and "Date To" before generating the report.');
                return false;
            }
            
            const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
            if (!dateRegex.test(dateFrom) || !dateRegex.test(dateTo)) {
                e.preventDefault();
                alert('Please use the correct date format (YYYY-MM-DD).');
                return false;
            }
            
            if (new Date(dateFrom) > new Date(dateTo)) {
                e.preventDefault();
                alert('"Date From" cannot be after "Date To".');
                return false;
            }
            
            return true;
        });

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Member Type Chart
            const typeCtx = document.getElementById('typeChart').getContext('2d');
            new Chart(typeCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_map('getMemberTypeLabel', array_column($members_by_type, 'member_type'))) ?>,
                    datasets: [{
                        data: <?= json_encode(array_column($members_by_type, 'count')) ?>,
                        backgroundColor: ['#2563eb', '#0e9f6e', '#f4b740', '#dc2626', '#8b5cf6', '#ec4899'],
                        borderWidth: 0,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: window.innerWidth < 768 ? 'bottom' : 'right',
                            labels: {
                                boxWidth: 12,
                                padding: window.innerWidth < 768 ? 10 : 15,
                                font: { size: window.innerWidth < 768 ? 10 : 11 }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'white',
                            titleColor: '#1f2937',
                            bodyColor: '#4b5563',
                            borderColor: '#e5e7eb',
                            borderWidth: 1,
                            padding: 10
                        }
                    },
                    cutout: window.innerWidth < 768 ? '60%' : '65%'
                }
            });

            // Trend Chart
            const trendCtx = document.getElementById('trendChart').getContext('2d');
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode(array_column($monthly_trends, 'month')) ?>,
                    datasets: [{
                        label: 'New Members',
                        data: <?= json_encode(array_column($monthly_trends, 'count')) ?>,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        borderWidth: window.innerWidth < 768 ? 2 : 3,
                        pointBackgroundColor: '#2563eb',
                        pointBorderColor: 'white',
                        pointBorderWidth: 2,
                        pointRadius: window.innerWidth < 768 ? 2 : 4,
                        pointHoverRadius: window.innerWidth < 768 ? 4 : 6,
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'white',
                            titleColor: '#1f2937',
                            bodyColor: '#4b5563',
                            borderColor: '#e5e7eb',
                            borderWidth: 1,
                            padding: 10
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#f3f4f6' },
                            ticks: { stepSize: 1 }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { maxRotation: window.innerWidth < 768 ? 45 : 0 }
                        }
                    }
                }
            });
        });

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
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
            if (e.key === 'Escape' && window.innerWidth <= 1024) {
                document.querySelector('.sidebar')?.classList.remove('active');
            }
        });

        // Touch-friendly
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .export-btn, .control-select').forEach(el => {
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
