<?php
/**
 * U EPMS - Application Sidebar Component
 * Pure HTML5 & PHP (Zero Frameworks)
 */

require_once __DIR__ . '/../includes/session.php';
initAppSession();

$currentUserRole = $_SESSION['user_role'] ?? 'Guest';
$currentUserName = $_SESSION['user_name'] ?? 'Guest User';
$currentUserInitial = strtoupper(substr($currentUserName, 0, 1));
$activeNav = $activeNav ?? 'dashboard';

// Counts for badges
global $db;
$pendingPoCount = 0;
if ($db) {
    try {
        if ($currentUserRole === 'Procurement Officer') {
            $pendingPoCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status NOT IN ('Finalized', 'Rejected')")->fetchColumn();
        } else {
            $pendingPoCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status NOT IN ('Finalized', 'Rejected')")->fetchColumn();
        }
    } catch (Exception $e) {
        // fail silently for badge
    }
}
?>
<aside class="app-sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">E</div>
        <div class="brand-text">
            <h1>EPMS</h1>
            <span>Enterprise Plant Monitoring</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <?php if ($currentUserRole === 'Procurement Officer'): ?>
        <!-- Procurement Officer Dedicated Navigation (Exclusively Requisitions) -->
        <div class="nav-section-title">Requisition Portal</div>
        <ul class="nav-list">
            <li class="nav-item <?= $activeNav === 'procurement' ? 'active' : '' ?>">
                <a href="/procurement.php">
                    <span class="nav-label-group">
                        <span class="nav-icon">&#128722;</span>
                        <span>Requisitions</span>
                    </span>
                    <?php if ($pendingPoCount > 0): ?>
                        <span class="nav-badge"><?= $pendingPoCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
        <?php else: ?>
        <div class="nav-section-title">Core Operations</div>
        <ul class="nav-list">
            <li class="nav-item <?= $activeNav === 'dashboard' ? 'active' : '' ?>">
                <a href="/dashboard.php">
                    <span class="nav-label-group">
                        <span class="nav-icon">&#9638;</span>
                        <span>Dashboard</span>
                    </span>
                </a>
            </li>
            <li class="nav-item <?= $activeNav === 'procurement' ? 'active' : '' ?>">
                <a href="/procurement.php">
                    <span class="nav-label-group">
                        <span class="nav-icon">&#128722;</span>
                        <span>Requisitions</span>
                    </span>
                    <?php if ($pendingPoCount > 0): ?>
                        <span class="nav-badge"><?= $pendingPoCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item <?= $activeNav === 'production' ? 'active' : '' ?>">
                <a href="/production.php">
                    <span class="nav-label-group">
                        <span class="nav-icon">&#9881;</span>
                        <span>Production Shifts</span>
                    </span>
                </a>
            </li>
            <li class="nav-item <?= $activeNav === 'petty_cash' ? 'active' : '' ?>">
                <a href="/petty_cash.php">
                    <span class="nav-label-group">
                        <span class="nav-icon">&#128181;</span>
                        <span>Petty Cash</span>
                    </span>
                </a>
            </li>
        </ul>

        <?php if (in_array($currentUserRole, ['System Operator', 'Admin'], true)): ?>
        <div class="nav-section-title">Administration</div>
        <ul class="nav-list">
            <li class="nav-item <?= $activeNav === 'users' ? 'active' : '' ?>">
                <a href="/users.php">
                    <span class="nav-label-group">
                        <span class="nav-icon">&#128101;</span>
                        <span>User Management</span>
                    </span>
                </a>
            </li>
            <li class="nav-item <?= $activeNav === 'settings' ? 'active' : '' ?>">
                <a href="/settings.php">
                    <span class="nav-label-group">
                        <span class="nav-icon">&#9874;</span>
                        <span>Machinery Config</span>
                    </span>
                </a>
            </li>
            <li class="nav-item <?= $activeNav === 'audit_logs' ? 'active' : '' ?>">
                <a href="/audit_logs.php">
                    <span class="nav-label-group">
                        <span class="nav-icon">&#128220;</span>
                        <span>Audit Trail</span>
                    </span>
                </a>
            </li>
        </ul>
        <?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user-card">
            <div class="user-avatar-sm"><?= htmlspecialchars($currentUserInitial) ?></div>
            <div class="user-meta-sm">
                <div class="user-meta-name"><?= htmlspecialchars($currentUserName) ?></div>
                <div class="user-meta-role"><?= htmlspecialchars($currentUserRole) ?></div>
            </div>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px;">
            <span style="font-size:11px; color:#64748b;">PHP <?= PHP_VERSION ?> &bull; <?= strtoupper(DB_DRIVER) ?></span>
            <a href="/logout.php" style="color:#ef4444; font-size:12px; font-weight:600;">Sign Out &rarr;</a>
        </div>
    </div>
</aside>
