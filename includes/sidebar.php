<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Load permission middleware if not already loaded
if (!function_exists('has_permission')) {
    $sidebarRoot = dirname(__DIR__);
    if (file_exists($sidebarRoot . '/middleware/permission.php') && file_exists($sidebarRoot . '/middleware/auth.php') && file_exists($sidebarRoot . '/config.php')) {
        require_once $sidebarRoot . '/config.php';
        require_once $sidebarRoot . '/middleware/auth.php';
        require_once $sidebarRoot . '/middleware/permission.php';
    }
}

// Helper: check permission gracefully (returns true if middleware not loaded)
if (!function_exists('sidebar_can')) {
    function sidebar_can(string $module, string $action): bool
    {
        if (!isset($GLOBALS['pdo']) || !function_exists('has_permission')) {
            return true;
        }
        return has_permission($GLOBALS['pdo'], $module, $action);
    }
}

// Detect current page automatically
$current_page = basename($_SERVER['PHP_SELF']);
$currentPath = $_SERVER['PHP_SELF'] ?? '';
$basePath = (strpos($currentPath, '/admin/') !== false)
    ? '../../'
    : ((strpos($currentPath, '/organizations/') !== false || strpos($currentPath, '/Members/') !== false || strpos($currentPath, '/template/') !== false || strpos($currentPath, '/card/') !== false) ? '../' : '');
?>

<style>
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
        --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
        --radius-md: 0.5rem;
    }

    /* ----- Sidebar ----- */
    .sidebar {
        width: 280px;
        background: linear-gradient(180deg, var(--primary) 0%, var(--primary-light) 100%);
        color: white;
        position: fixed;
        height: 100vh;
        overflow-y: auto;
        transition: transform 0.3s ease;
        z-index: 50;
        box-shadow: var(--shadow-xl);
    }

    .menu-btn {
        position: fixed;
        top: 15px;
        left: 15px;
        font-size: 22px;
        background: white;
        border: none;
        padding: 8px 12px;
        border-radius: 6px;
        cursor: pointer;
        z-index: 100;
        display: none;
    }

    /* Mobile */
    @media (max-width: 1024px) {
        .menu-btn {
            display: block;
        }

        .sidebar {
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }
    }

    .sidebar-header {
        padding: 1.5rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .sidebar-header i {
        font-size: 2rem;
        color: var(--accent);
        background: rgba(255, 255, 255, 0.1);
        padding: 0.5rem;
        border-radius: var(--radius-md);
    }

    .sidebar-header h2 {
        font-family: 'Poppins', sans-serif;
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0;
    }

    .sidebar-header span {
        color: var(--accent);
    }

    .sidebar-nav {
        padding: 1.5rem 0;
    }

    .nav-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 15px;
        color: #cbd5e0;
        text-decoration: none;
        border-left: 3px solid transparent;
        transition: 0.3s ease;
    }

    .nav-item:hover,
    .nav-item.active {
        background: rgba(255, 255, 255, 0.1);
        color: #fff;
        border-left-color: var(--accent);
    }

    .nav-item.active {
        background: rgba(229, 62, 62, 0.15);
    }

    .nav-item i {
        width: 20px;
        text-align: center;
    }

    .nav-divider {
        height: 1px;
        background: rgba(255, 255, 255, 0.1);
        margin: 1rem 1.5rem;
    }

    .nav-label {
        padding: 0.5rem 1.5rem;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: rgba(255, 255, 255, 0.4);
    }

    .sidebar-footer {
        padding: 1.5rem;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        margin-top: 1rem;
    }

    .sidebar-footer .nav-item {
        padding: 8px 15px;
    }

    /* Scrollbar */
    .sidebar::-webkit-scrollbar {
        width: 5px;
    }

    .sidebar::-webkit-scrollbar-track {
        background: var(--primary);
    }

    .sidebar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 10px;
    }

    /* Active link indicator */
    .nav-item .badge-count {
        margin-left: auto;
        background: var(--accent);
        color: white;
        font-size: 0.625rem;
        padding: 0.15rem 0.5rem;
        border-radius: 9999px;
        min-width: 20px;
        text-align: center;
    }

    .nav-item .badge-count.info {
        background: var(--info);
    }

    .nav-item .badge-count.success {
        background: var(--success);
    }

    .nav-item .badge-count.warning {
        background: var(--warning);
    }
</style>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-id-card"></i>
        <h2>ID<span>HUB</span></h2>
    </div>

    <div class="sidebar-nav">
        <!-- MAIN -->
        <div class="nav-label">MAIN</div>

        <a href="<?= $basePath ?>dashboard.php" class="nav-item <?= ($current_page == 'dashboard.php') ? 'active' : '' ?>">
            <i class="fas fa-chart-pie"></i>
            <span>Dashboard</span>
        </a>

        <!-- MEMBERS -->
        <div class="nav-divider"></div>
        <div class="nav-label">MEMBERS</div>

        <a href="<?= $basePath ?>Members/view_members.php" class="nav-item <?= ($current_page == 'view_members.php') ? 'active' : '' ?>">
            <i class="fas fa-users"></i>
            <span>All Members</span>
        </a>

        <a href="<?= $basePath ?>Members/add_member.php" class="nav-item <?= ($current_page == 'add_member.php') ? 'active' : '' ?>">
            <i class="fas fa-user-plus"></i>
            <span>Add Member</span>
        </a>

        <a href="<?= $basePath ?>Members/bulk_upload.php" class="nav-item <?= ($current_page == 'bulk_upload.php') ? 'active' : '' ?>">
            <i class="fas fa-upload"></i>
            <span>Bulk Upload</span>
        </a>

        <!-- ID CARDS -->
        <div class="nav-divider"></div>
        <div class="nav-label">ID CARDS</div>


        <a href="<?= $basePath ?>generate_id_card.php" class="nav-item <?= ($current_page == 'generate_id_card.php') ? 'active' : '' ?>">
            <i class="fas fa-magic"></i>
            <span>Generate Card</span>
        </a>

        <a href="<?= $basePath ?>card/bulk_print.php" class="nav-item <?= ($current_page == 'bulk_print.php') ? 'active' : '' ?>">
            <i class="fas fa-print"></i>
            <span>Bulk Print</span>
        </a>

        <!-- TEMPLATES -->
        <div class="nav-divider"></div>
        <div class="nav-label">TEMPLATES</div>

        <a href="<?= $basePath ?>template/templates.php" class="nav-item <?= (strpos($_SERVER['REQUEST_URI'] ?? '', '/template/') !== false && $current_page != 'design_template.php') ? 'active' : '' ?>">
            <i class="fas fa-paint-brush"></i>
            <span>All Templates</span>
        </a>

        <a href="<?= $basePath ?>template/add_template.php" class="nav-item <?= ($current_page == 'add_template.php') ? 'active' : '' ?>">
            <i class="fas fa-plus"></i>
            <span>Add Template</span>
        </a>

        <!-- ORGANIZATIONS -->
        <?php if (sidebar_can('organizations', 'view')): ?>
        <div class="nav-divider"></div>
        <div class="nav-label">ORGANIZATIONS</div>

        <a href="<?= $basePath ?>organizations/index.php" class="nav-item <?= (strpos($_SERVER['REQUEST_URI'] ?? '', '/organizations/') !== false) ? 'active' : '' ?>">
            <i class="fas fa-building"></i>
            <span>Organizations</span>
        </a>
        <?php endif; ?>

        <!-- REPORTS -->
        <div class="nav-divider"></div>
        <div class="nav-label">REPORTS</div>

        <a href="<?= $basePath ?>reports.php" class="nav-item <?= ($current_page == 'reports.php') ? 'active' : '' ?>">
            <i class="fas fa-file-alt"></i>
            <span>Reports</span>
        </a>

        <?php if (sidebar_can('audit', 'view')): ?>
        <a href="<?= $basePath ?>audit_log.php" class="nav-item <?= ($current_page == 'audit_log.php') ? 'active' : '' ?>">
            <i class="fas fa-history"></i>
            <span>Audit Log</span>
        </a>
        <?php endif; ?>

        <!-- ADMINISTRATION -->
        <div class="nav-divider"></div>
        <div class="nav-label">ADMINISTRATION</div>

        <?php if (sidebar_can('users', 'view')): ?>
        <a href="<?= $basePath ?>admin/users/index.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'] ?? '', '/admin/users/') !== false) ? 'active' : '' ?>">
            <i class="fas fa-users-cog"></i>
            <span>User Management</span>
        </a>
        <?php endif; ?>

        <?php if (sidebar_can('roles', 'view')): ?>
        <a href="<?= $basePath ?>admin/roles/index.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'] ?? '', '/admin/roles/') !== false) ? 'active' : '' ?>">
            <i class="fas fa-user-shield"></i>
            <span>Roles & Permissions</span>
        </a>
        <?php endif; ?>

        <!-- SYSTEM -->
        <div class="nav-divider"></div>
        <div class="nav-label">SYSTEM</div>

        <a href="<?= $basePath ?>settings.php" class="nav-item <?= ($current_page == 'settings.php') ? 'active' : '' ?>">
            <i class="fas fa-cog"></i>
            <span>Settings</span>
        </a>

        <a href="<?= $basePath ?>profile.php" class="nav-item <?= ($current_page == 'profile.php') ? 'active' : '' ?>">
            <i class="fas fa-user-cog"></i>
            <span>Profile</span>
        </a>

        <a href="<?= $basePath ?>contact.php" class="nav-item <?= ($current_page == 'contact.php') ? 'active' : '' ?>">
            <i class="fas fa-headset"></i>
            <span>Contact Support</span>
        </a>
    </div>

    <div class="sidebar-footer">
        <a href="<?= $basePath ?>admin/auth/logout.php" class="nav-item">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>

<button id="menuToggle" class="menu-btn">
    <i class="fas fa-bars"></i>
</button>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const menuToggle = document.getElementById("menuToggle");
    const sidebar = document.getElementById("sidebar");

    if (menuToggle && sidebar) {
        menuToggle.addEventListener("click", function(e) {
            e.stopPropagation();
            sidebar.classList.toggle("active");
        });

        document.addEventListener("click", function(event) {
            if (window.innerWidth <= 1024) {
                if (!sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                    sidebar.classList.remove("active");
                }
            }
        });

        // Close sidebar on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && window.innerWidth <= 1024) {
                sidebar.classList.remove('active');
            }
        });
    }
});
</script>
