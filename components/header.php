<?php
/**
 * U EPMS - Application Header Component
 * Plain HTML5 & PHP
 */

require_once __DIR__ . '/../includes/session.php';
initAppSession();

$pageTitle = $pageTitle ?? 'Factory Operations';
$currentUserId = $_SESSION['user_id'] ?? 0;
$currentUserRole = $_SESSION['user_role'] ?? 'Guest';
$currentUserName = $_SESSION['user_name'] ?? 'Guest';

$roleBadgeClass = match($currentUserRole) {
    'CEO' => 'badge-danger',
    'Manager' => 'badge-warning',
    'Accountant' => 'badge-success',
    'Procurement Officer' => 'badge-info',
    'Supervisor' => 'badge-secondary',
    'Assistant Manager' => 'badge-secondary',
    default => 'badge-secondary',
};

// v2.3 notification panel: latest unread items for the dropdown
$bellCount = 0;
$bellItems = [];
if ($currentUserId) {
    try {
        $bellCount = unreadNotificationCount($db, (int)$currentUserId);
        $stmtBell = $db->prepare('SELECT id, title, body, link, created_at FROM notifications WHERE user_id = :u AND is_read = 0 ORDER BY id DESC LIMIT 6');
        $stmtBell->execute([':u' => (int)$currentUserId]);
        $bellItems = $stmtBell->fetchAll();
    } catch (Exception $e) {
        $bellCount = 0;
        $bellItems = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="/assets/js/validate.js" defer></script>
    <script>
    // v2.2: sessions travel only in the secure cookie - the old sid-in-URL
    // rewriting was removed so identifiers can never leak into links,
    // history, referrer headers or server logs.
    </script>
</head>
<body>
<div class="app-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="app-main">
        <header class="app-header">
            <div class="header-title-wrap">
                <h1 class="header-page-title"><?= htmlspecialchars($pageTitle) ?></h1>
            </div>

            <div class="header-actions">
                <!-- v2.3 Notification panel -->
                <div style="position:relative;">
                    <button type="button" id="notif-bell-btn" class="header-bell" title="My notifications" aria-haspopup="true" aria-expanded="false"
                            style="position:relative; display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; background:var(--bg-surface-subtle, #f1f5f9); font-size:17px; text-decoration:none; border:none; cursor:pointer;">
                        &#128276;
                        <?php if ($bellCount > 0): ?>
                            <span style="position:absolute; top:-4px; right:-6px; background:#dc2626; color:#fff; font-size:10px; font-weight:800; min-width:18px; height:18px; border-radius:9px; display:flex; align-items:center; justify-content:center; padding:0 4px;"><?= $bellCount > 99 ? '99+' : $bellCount ?></span>
                        <?php endif; ?>
                    </button>
                    <div id="notif-panel" style="display:none; position:absolute; right:0; top:44px; width:340px; max-height:420px; overflow-y:auto; background:#fff; border:1px solid var(--border, #e2e8f0); border-radius:12px; box-shadow:0 10px 40px rgba(0,0,0,.14); z-index:1200;">
                        <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-bottom:1px solid var(--border, #e2e8f0); position:sticky; top:0; background:#fff;">
                            <strong style="font-size:13px;">Notifications<?= $bellCount > 0 ? " ({$bellCount} unread)" : '' ?></strong>
                            <a href="/notifications.php" style="font-size:11px; text-decoration:none; font-weight:700;">View all &rarr;</a>
                        </div>
                        <?php if (!$bellItems): ?>
                            <div style="padding:18px 14px; font-size:12px; color:var(--text-secondary, #64748b); text-align:center;">You're all caught up. &#127881;</div>
                        <?php else: ?>
                            <?php foreach ($bellItems as $bi): ?>
                                <div style="padding:10px 14px; border-bottom:1px solid var(--border, #f1f5f9);">
                                    <div style="display:flex; justify-content:space-between; gap:6px;">
                                        <strong style="font-size:12px; line-height:1.35;"><?= htmlspecialchars($bi['title']) ?></strong>
                                        <form method="post" action="/notifications.php" style="display:inline;">
                                            <input type="hidden" name="notif_action" value="mark_read">
                                            <input type="hidden" name="notification_id" value="<?= (int)$bi['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="panel" value="1">
                                            <button type="submit" title="Mark as read" style="background:none; border:none; cursor:pointer; color:#94a3b8; font-size:13px;">&times;</button>
                                        </form>
                                    </div>
                                    <div style="font-size:11px; color:var(--text-secondary, #64748b); line-height:1.4; margin-top:2px;"><?= htmlspecialchars(mb_substr((string)$bi['body'], 0, 110)) ?></div>
                                    <div style="font-size:10px; color:#94a3b8; margin-top:4px; display:flex; justify-content:space-between;">
                                        <span><?= formatDateTime((string)$bi['created_at']) ?></span>
                                        <?php if (!empty($bi['link'])): ?><a href="/notifications.php?open=<?= (int)$bi['id'] ?>" style="font-size:10px; font-weight:700; text-decoration:none;">Open &rarr;</a><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="header-user-chip" title="Signed-in account">
                    <span class="header-user-name"><?= htmlspecialchars($currentUserName) ?></span>
                    <?php if (!empty($_SESSION['acting_as'])): ?>
                        <span class="badge badge-warning" title="Acting via an approved delegation">Acting <?= htmlspecialchars($_SESSION['acting_as']) ?></span>
                    <?php endif; ?>
                    <span class="badge <?= $roleBadgeClass ?>"><?= htmlspecialchars($currentUserRole) ?></span>
                </div>
            </div>
        </header>
