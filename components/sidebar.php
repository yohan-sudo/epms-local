<?php
/**
 * U EPMS - Application Sidebar Component
 * Plain HTML5 & PHP (Zero Frameworks)
 *
 * Animated glass sidebar with an icon-rail collapse mode (like the
 * "Aceagency" reference design):
 *   - Floating collapse/expand button, animated panel width
 *   - Rounded pill nav items, blue active pill, live count badges
 *   - Blue hover tooltips when collapsed, dividers, glass user card
 *   - Collapsed state persists across pages via localStorage
 */

require_once __DIR__ . '/../includes/session.php';
initAppSession();

$currentUserRole  = $_SESSION['user_role'] ?? 'Guest';
$currentUserStatus = $_SESSION['user_status'] ?? 'Active';
$currentUserName  = $_SESSION['user_name'] ?? 'Guest User';
$currentUserInitial = strtoupper(mb_substr($currentUserName, 0, 1));
$activeNav = $activeNav ?? 'dashboard';
$isPo = $currentUserRole === 'Procurement Officer';

// Live badge counts (fail silently if the DB hiccups)
// The procurement badge shows what is awaiting THE CURRENT USER's stage:
//   Procurement Officer -> their open submissions, Manager -> awaiting their
//   review, Accountant -> awaiting their final approval, others -> all open.
$pendingPoCount = 0;
$openFloatCount = 0;
if (isset($db) && $db) {
    try {
        if ($isPo) {
            $pendingPoCount = (int)$db->query(
                "SELECT COUNT(*) FROM procurement_entries WHERE status NOT IN ('Finalized', 'Rejected')"
            )->fetchColumn();
        } elseif ($currentUserRole === 'Manager') {
            $pendingPoCount = (int)$db->query(
                "SELECT COUNT(*) FROM procurement_entries WHERE status IN ('Pending Manager Review', 'Pending Approval')"
            )->fetchColumn();
        } elseif ($currentUserRole === 'Accountant') {
            $pendingPoCount = (int)$db->query(
                "SELECT COUNT(*) FROM procurement_entries WHERE status = 'Pending Accountant Review'"
            )->fetchColumn();
        } else {
            $pendingPoCount = (int)$db->query(
                "SELECT COUNT(*) FROM procurement_entries WHERE status NOT IN ('Finalized', 'Rejected')"
            )->fetchColumn();
        }
        if (!$isPo) {
            $openFloatCount = (int)$db->query(
                "SELECT COUNT(*) FROM petty_cash_issuances WHERE status = 'Active'"
            )->fetchColumn();
        }
    } catch (Exception $e) {
        // badges are cosmetic - never break the sidebar
    }
}

/**
 * Renders one desktop sidebar nav item.
 * $tooltip drives the collapsed-mode blue hover tooltip.
 */
function nav_item(string $href, string $icon, string $label, string $key, string $activeNav, int $badge = 0): void
{
    $active = $activeNav === $key;
    ?>
    <li class="nav-item <?= $active ? 'active' : '' ?>">
        <a href="<?= htmlspecialchars($href) ?>" data-tooltip="<?= htmlspecialchars($label) ?>">
            <span class="nav-icon"><?= $icon ?></span>
            <span class="nav-text"><?= htmlspecialchars($label) ?></span>
            <?php if ($badge > 0): ?>
                <span class="nav-badge" aria-label="<?= (int)$badge ?> pending"><?= $badge > 99 ? '99+' : $badge ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php
}

/** Renders one floating bottom-bar tab. */
function tab_item(string $href, string $icon, string $label, string $key, string $activeNav, int $badge = 0): void
{
    $active = $activeNav === $key;
    ?>
    <a class="tab-item<?= $active ? ' active' : '' ?>" href="<?= htmlspecialchars($href) ?>">
        <?php if ($active): ?>
            <span class="tab-bubble"><?= $icon ?></span>
            <?php if ($badge > 0): ?><span class="tab-badge"><?= $badge > 99 ? '99+' : $badge ?></span><?php endif; ?>
        <?php else: ?>
            <span class="tab-ico"><?= $icon ?>
                <?php if ($badge > 0): ?><span class="tab-badge"><?= $badge > 99 ? '99+' : $badge ?></span><?php endif; ?>
            </span>
        <?php endif; ?>
        <span class="tab-txt"><?= htmlspecialchars($label) ?></span>
    </a>
    <?php
}

/** Renders one row inside the mobile "More" panel. */
function more_row(string $href, string $icon, string $label, string $key, string $activeNav, int $badge = 0): void
{
    $active = $activeNav === $key;
    ?>
    <a class="tab-more-row<?= $active ? ' active' : '' ?>" href="<?= htmlspecialchars($href) ?>">
        <span class="tab-more-ico"><?= $icon ?></span>
        <span><?= htmlspecialchars($label) ?></span>
        <?php if ($badge > 0): ?>
            <span class="nav-badge"><?= $badge > 99 ? '99+' : $badge ?></span>
        <?php endif; ?>
    </a>
    <?php
}
?>
<aside class="app-sidebar" id="app-sidebar" data-collapsed="false">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-expanded="true"
            aria-controls="app-sidebar" aria-label="Collapse sidebar">
        <span class="sidebar-toggle-arrow">&#10094;</span>
    </button>

    <div class="sidebar-brand">
        <div class="brand-icon">E</div>
        <div class="brand-text">
            <h1>EPMS</h1>
            <span>Enterprise Plant Monitoring</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <?php if ($isPo): ?>
        <!-- Procurement Officer: submission portal + reports -->
        <div class="nav-section-title"><span class="nav-text">Procurement</span></div>
        <ul class="nav-list">
            <?php nav_item('/procurement.php', '&#128722;', 'Procurement Records', 'procurement', $activeNav, $pendingPoCount); ?>
            <?php nav_item('/reports.php', '&#128202;', 'Reports', 'reports', $activeNav); ?>
        </ul>
        <?php else: ?>

        <div class="nav-section-title"><span class="nav-text">Overview</span></div>
        <ul class="nav-list">
            <?php nav_item('/dashboard.php', '&#9638;', 'Dashboard', 'dashboard', $activeNav); ?>
        </ul>

        <div class="nav-section-title"><span class="nav-text">Operations</span></div>
        <ul class="nav-list">
            <?php nav_item('/procurement.php', '&#128722;', 'Procurement Records', 'procurement', $activeNav, $pendingPoCount); ?>
            <?php nav_item('/production.php', '&#9881;', 'Production Shifts', 'production', $activeNav); ?>
            <?php nav_item('/petty_cash.php', '&#128181;', 'Petty Cash', 'petty_cash', $activeNav, $openFloatCount); ?>
        </ul>

        <div class="nav-section-title"><span class="nav-text">Insights</span></div>
        <ul class="nav-list">
            <?php nav_item('/reports.php', '&#128202;', 'Reports & Documents', 'reports', $activeNav); ?>
        </ul>

        <?php if ($currentUserRole === 'CEO'): ?>
        <div class="nav-section-title"><span class="nav-text">Administration</span></div>
        <ul class="nav-list">
            <?php nav_item('/users.php', '&#128101;', 'User Management', 'users', $activeNav); ?>
            <?php nav_item('/settings.php', '&#9874;', 'Machinery Config', 'settings', $activeNav); ?>
            <?php nav_item('/audit_logs.php', '&#128220;', 'Audit Trail', 'audit_logs', $activeNav); ?>
        </ul>
        <?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user-card" data-tooltip="<?= htmlspecialchars($currentUserName) ?>">
            <div class="user-avatar-sm"><?= htmlspecialchars($currentUserInitial) ?></div>
            <div class="user-meta-sm">
                <div class="user-meta-name"><?= htmlspecialchars($currentUserName) ?></div>
                <div class="user-meta-role">
                    <?= htmlspecialchars($currentUserRole) ?>
                    <?php if ($currentUserStatus === 'Banned'): ?>
                        <span style="color:#ef4444;">&bull; BANNED</span>
                    <?php endif; ?>
                </div>
            </div>
            <span class="badge <?= $currentUserStatus === 'Active' ? 'badge-success' : 'badge-danger' ?>"
                  style="margin-left:auto; font-size:10px; padding:2px 7px;">
                <?= htmlspecialchars($currentUserStatus) ?>
            </span>
        </div>
        <a href="/logout.php" class="sidebar-signout" data-tooltip="Sign Out">
            <span class="nav-icon">&#10140;</span>
            <span class="nav-text">Sign Out</span>
        </a>
        <div class="sidebar-copyright">EPMS &bull; v2.1</div>
    </div>
</aside>

<?php
/* ---------------------------------------------------------------------
 * Mobile floating bottom navigation bar (pure CSS tab bar, <=768px only)
 * Active tab rises in a circular bubble with a notched cut-out, like a
 * native mobile app. Secondary pages live in the "More" panel.
 */
$isAdminSide = $currentUserRole === 'CEO';
$moreKeys    = $isAdminSide ? ['reports', 'users', 'settings', 'audit_logs'] : ($isPo ? [] : ['reports']);
$moreActive  = in_array($activeNav, $moreKeys, true);
?>
<nav class="mobile-tabbar" id="mobile-tabbar" aria-label="Primary mobile navigation">        <?php if ($isPo): ?>
            <?php tab_item('/procurement.php', '&#128722;', 'Records', 'procurement', $activeNav, $pendingPoCount); ?>
            <?php tab_item('/reports.php', '&#128202;', 'Reports', 'reports', $activeNav); ?>
        <?php else: ?>
            <?php tab_item('/dashboard.php', '&#127968;', 'Home', 'dashboard', $activeNav); ?>
            <?php tab_item('/procurement.php', '&#128722;', 'Records', 'procurement', $activeNav, $pendingPoCount); ?>
            <?php tab_item('/production.php', '&#9881;', 'Production', 'production', $activeNav); ?>
            <?php tab_item('/petty_cash.php', '&#128181;', 'Petty Cash', 'petty_cash', $activeNav, $openFloatCount); ?>
        <?php endif; ?>

    <button type="button" class="tab-item tab-more-btn<?= $moreActive ? ' active' : '' ?>" id="tab-more-btn" aria-haspopup="true" aria-expanded="false">
        <span class="tab-ico">&#128100;</span>
        <span class="tab-txt">More</span>
    </button>

    <div class="tabbar-more" id="tabbar-more" role="menu" aria-label="More pages">
        <div class="tab-more-head">
            <div class="user-avatar-sm"><?= htmlspecialchars($currentUserInitial) ?></div>
            <div class="tab-more-id">
                <div class="tab-more-name"><?= htmlspecialchars($currentUserName) ?></div>
                <div class="tab-more-role"><?= htmlspecialchars($currentUserRole) ?></div>
            </div>
        </div>
        <?php if ($isAdminSide): ?>
            <?php more_row('/users.php', '&#128101;', 'User Management', 'users', $activeNav); ?>
            <?php more_row('/settings.php', '&#9874;', 'Machinery Config', 'settings', $activeNav); ?>
            <?php more_row('/audit_logs.php', '&#128220;', 'Audit Trail', 'audit_logs', $activeNav); ?>
        <?php endif; ?>
        <?php if (!$isPo): ?>
            <?php more_row('/reports.php', '&#128202;', 'Reports & Documents', 'reports', $activeNav); ?>
        <?php endif; ?>
        <a class="tab-more-row tab-more-signout" href="/logout.php">
            <span class="tab-more-ico">&#10140;</span>
            <span>Sign Out</span>
        </a>
    </div>
</nav>

<script>
(function () {
    var btn = document.getElementById('tab-more-btn');
    var panel = document.getElementById('tabbar-more');
    if (!btn || !panel) return;
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = panel.classList.toggle('open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) {
        if (panel.classList.contains('open') && !panel.contains(e.target)) {
            panel.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
        }
    });
    panel.addEventListener('click', function (e) {
        if (e.target.closest('a')) {
            panel.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
        }
    });
})();

/* Sidebar collapse / expand, persisted across pages */
(function () {
    var sidebar = document.getElementById('app-sidebar');
    var toggle = document.getElementById('sidebar-toggle');
    if (!sidebar || !toggle) return;

    var initializing = true;

    function apply(collapsed, persist) {
        if (initializing) {
            /* suppress the width animation while restoring saved state
               so there is no visible "re-collapse" flash on page load */
            sidebar.style.transition = 'none';
        }
        sidebar.setAttribute('data-collapsed', collapsed ? 'true' : 'false');
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        if (persist) {
            try { localStorage.setItem('epms_sidebar_collapsed', collapsed ? '1' : '0'); } catch (e) {}
        }
    }

    var saved = null;
    try { saved = localStorage.getItem('epms_sidebar_collapsed'); } catch (e) {}
    if (saved === '1') {
        apply(true, false);
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { sidebar.style.transition = ''; });
        });
    }
    initializing = false;

    toggle.addEventListener('click', function () {
        apply(sidebar.getAttribute('data-collapsed') !== 'true', true);
    });
})();
</script>