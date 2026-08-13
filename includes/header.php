<?php
$headerTitleMap = [
    'dashboard.php' => 'Dashboard',
    'generate_id_card.php' => 'Generate ID Card',
    'templates.php' => 'Template Design Studio',
 
    'view_members.php' => 'Members',
    'view_member.php' => 'Member Details',
    'settings.php' => 'Settings',
    'profile.php' => 'Profile',
    'reports.php' => 'Reports',
    'edit_member.php' => 'Edit Member',
    'add_member.php' => 'Add Member',
    'bulk_upload.php' => 'Bulk Upload',
    'audit_log.php' => 'Audit Log',
    'index.php' => 'Organizations',
    'add.php' => 'Add Organization',
    'edit.php' => 'Edit Organization',
    'view.php' => 'Organization Details',
    'bulk_print.php' => 'Bulk Print',
    'print_id_card.php' => 'Print ID Card',
    'contact.php' => 'Contact & Support',
    'design_template.php' => 'Template Designer',
    'view_template.php' => 'View Template',
    'edit_template.php' => 'Edit Template',
    'add_template.php' => 'Add Template',
    'view_card.php' => 'View ID Card'
];

$currentScript = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
$headerPageTitle = $page_title ?? ($headerTitleMap[$currentScript] ?? 'ID Card System');
$headerRole = $_SESSION['role_name'] ?? $user_role ?? ucwords(str_replace('_', ' ', $_SESSION['role'] ?? 'Administrator'));
$headerUsername = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
$headerDisplayInitials = strtoupper(substr($headerUsername, 0, 1));
$headerAvatar = $_SESSION['avatar'] ?? null;
$headerAvatarFile = basename((string) $headerAvatar);
$headerHasAvatar = $headerAvatarFile !== '' && file_exists(__DIR__ . '/../admin/users/assets/uploads/avatars/' . $headerAvatarFile);
$urgentExpiringCount = 0;
$currentPath = $_SERVER['PHP_SELF'] ?? '';
$basePath = (strpos($currentPath, '/admin/') !== false)
    ? '../../'
    : ((strpos($currentPath, '/organizations/') !== false || strpos($currentPath, '/Members/') !== false || strpos($currentPath, '/template/') !== false || strpos($currentPath, '/card/') !== false) ? '../' : '');

if (isset($pdo)) {
    try {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM id_members WHERE expiry_date BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)");
        $stmt->execute([$today, $today]);
        $urgentExpiringCount = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $urgentExpiringCount = 0;
    }
}
?>
<style>
    .top-header {
        background: white;
        padding: 0.75rem 2rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid var(--neutral-200);
        position: sticky;
        top: 0;
        z-index: 1040;
        box-shadow: var(--shadow-sm);
        min-height: 64px;
    }

    .menu-toggle {
        display: none;
        font-size: 1.25rem;
        color: var(--neutral-600);
        cursor: pointer;
        padding: 0.5rem;
    }

    .page-title h1 {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--neutral-800);
        margin: 0;
    }

    .header-actions {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .notification-btn {
        position: relative;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: var(--neutral-100);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--neutral-600);
        text-decoration: none;
        transition: all 0.2s;
        border: none;
        cursor: pointer;
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
        font-size: 0.65rem;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
    }

    .user-menu {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.25rem 0.75rem 0.25rem 0.25rem;
        background: var(--neutral-100);
        border-radius: var(--radius-lg);
        cursor: pointer;
        transition: all 0.2s;
        position: relative;
        border: none;
    }

    .user-menu:hover {
        background: var(--neutral-200);
    }

    .user-avatar {
        width: 32px;
        height: 32px;
        background: var(--primary);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 0.875rem;
        flex-shrink: 0;
        overflow: hidden;
    }

    .user-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .user-info {
        line-height: 1.3;
        min-width: 0;
    }

    .user-name {
        font-weight: 600;
        font-size: 0.813rem;
        color: var(--neutral-800);
        white-space: nowrap;
    }

    .user-role {
        font-size: 0.688rem;
        color: var(--neutral-500);
        white-space: nowrap;
    }

    .user-dropdown {
        position: absolute;
        top: 100%;
        right: 0;
        background: white;
        border-radius: 10px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        display: none;
        min-width: 180px;
        padding: 0.5rem 0;
        z-index: 1050;
        border: 1px solid var(--neutral-200);
        margin-top: 0.5rem;
    }

    .user-dropdown.active {
        display: block;
    }

    .user-dropdown a {
        display: block;
        padding: 0.5rem 1rem;
        text-decoration: none;
        color: var(--neutral-700);
        font-size: 0.875rem;
        transition: all 0.2s;
    }

    .user-dropdown a:hover {
        background: var(--neutral-100);
        color: var(--primary);
    }

    .user-dropdown .dropdown-divider {
        height: 1px;
        background: var(--neutral-200);
        margin: 0.25rem 0;
    }

    .user-dropdown .dropdown-danger {
        color: var(--danger);
    }

    .user-dropdown .dropdown-danger:hover {
        background: var(--danger-soft);
    }

    /* Contact Button in Header */
    .contact-btn {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: var(--primary-soft);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--primary);
        text-decoration: none;
        transition: all 0.2s;
        border: none;
        cursor: pointer;
    }

    .contact-btn:hover {
        background: var(--primary);
        color: white;
        transform: scale(1.05);
    }

    @media (max-width: 1024px) {
        .top-header {
            padding: 0.75rem 1rem;
        }

        .menu-toggle {
            display: block;
        }

        .user-info {
            display: none;
        }

        .user-menu {
            padding: 0.25rem;
            background: transparent;
        }

        .user-menu:hover {
            background: transparent;
        }
    }

    @media (max-width: 480px) {
        .page-title h1 {
            font-size: 1rem;
        }

        .header-actions {
            gap: 0.5rem;
        }

        .notification-btn {
            width: 32px;
            height: 32px;
        }

        .user-avatar {
            width: 28px;
            height: 28px;
            font-size: 0.75rem;
        }

        .contact-btn {
            width: 32px;
            height: 32px;
        }
    }
</style>

<header class="top-header">
    <div class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </div>

    <div class="page-title">
        <h1><?= htmlspecialchars($headerPageTitle) ?></h1>
    </div>

    <div class="header-actions">
        <!-- Contact Support Button -->
        <a href="<?= $basePath ?>contact.php" class="contact-btn" title="Contact Support">
            <i class="fas fa-headset"></i>
        </a>

        <a href="<?= $basePath ?>Members/view_members.php?status=expiring" class="notification-btn" title="Expiring Members">
            <i class="far fa-bell"></i>
            <?php if ($urgentExpiringCount > 0): ?>
                <span class="notification-badge"><?= $urgentExpiringCount ?></span>
            <?php endif; ?>
        </a>

        <div class="user-menu" id="userMenu">
            <div class="user-avatar">
                <?php if ($headerHasAvatar): ?>
                    <img src="<?= $basePath ?>admin/users/assets/uploads/avatars/<?= htmlspecialchars($headerAvatarFile) ?>" alt="Avatar">
                <?php else: ?>
                    <?= htmlspecialchars($headerDisplayInitials) ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($headerUsername) ?></div>
                <div class="user-role"><?= htmlspecialchars($headerRole) ?></div>
            </div>
            <i class="fas fa-chevron-down" style="font-size: 0.75rem; color: var(--neutral-500);"></i>

            <div class="user-dropdown" id="userDropdown">
                <a href="<?= $basePath ?>profile.php"><i class="fas fa-user me-2"></i>Profile</a>
                <a href="<?= $basePath ?>settings.php"><i class="fas fa-cog me-2"></i>Settings</a>
                <a href="<?= $basePath ?>contact.php"><i class="fas fa-headset me-2"></i>Contact Support</a>
                <div class="dropdown-divider"></div>
                <a href="<?= $basePath ?>admin/auth/logout.php" class="dropdown-danger"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
            </div>
        </div>
    </div>
</header>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // User dropdown toggle
    const userMenu = document.getElementById('userMenu');
    const userDropdown = document.getElementById('userDropdown');

    if (userMenu && userDropdown) {
        userMenu.addEventListener('click', function(e) {
            e.stopPropagation();
            userDropdown.classList.toggle('active');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            if (!userMenu.contains(event.target)) {
                userDropdown.classList.remove('active');
            }
        });
    }

    // Mobile menu toggle - delegated to sidebar
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');

    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('active');
        });
    }
});
</script>
