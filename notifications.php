<?php
/**
 * U EPMS - My Notifications (the alert bell)
 * Every authenticated role sees their own queue; mark-as-read supported.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();

$pageTitle = 'My Notifications';
$activeNav = 'notifications';
$userId = (int)$_SESSION['user_id'];

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['notif_action'] ?? '');
    if ($act === 'mark_read' || $act === 'mark_unread') {
        $nid = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('UPDATE notifications SET is_read = :r WHERE id = :id AND user_id = :u');
        $stmt->execute([':r' => $act === 'mark_read' ? 1 : 0, ':id' => $nid, ':u' => $userId]);
    } elseif ($act === 'mark_all_read') {
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);
    }
    if (($_POST['panel'] ?? '') === '1') {
        commitSessionAndRedirect('/dashboard.php'); // back to where the panel was clicked
    }
    commitSessionAndRedirect('/notifications.php');
}

// v2.3.2: OPEN = read. Opening a notification marks it as read (cleared from
// the unread list) and forwards to its target page.
if (isset($_GET['open'])) {
    $oid = (int)$_GET['open'];
    $stmt = $db->prepare('SELECT link FROM notifications WHERE id = :id AND user_id = :u');
    $stmt->execute([':id' => $oid, ':u' => $userId]);
    $link = (string)$stmt->fetchColumn();
    $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :u')->execute([':id' => $oid, ':u' => $userId]);
    commitSessionAndRedirect($link !== '' ? $link : '/notifications.php');
}

$stmt = $db->prepare('SELECT * FROM notifications WHERE user_id = :u ORDER BY id DESC LIMIT 100');
$stmt->execute([':u' => $userId]);
$notifications = $stmt->fetchAll();

$unread = 0;
foreach ($notifications as $n) {
    if (!$n['is_read']) {
        $unread++;
    }
}

include __DIR__ . '/components/header.php';
?>

<main class="page-container" style="max-width: 860px;">
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 class="page-title">My Notifications</h2>
            <p class="page-subtitle"><?= $unread > 0 ? $unread . ' unread item(s)' : 'All caught up - nothing unread' ?></p>
        </div>
        <?php if ($unread > 0): ?>
            <form method="POST" action="/notifications.php">
                <input type="hidden" name="notif_action" value="mark_all_read">
                <button type="submit" class="btn btn-secondary btn-sm">&#10003; Mark all as read</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">&#128276;</div>
                <h3>No notifications yet</h3>
                <p>You will be alerted here when something needs your action: requests to approve, approvals received, floats issued.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>When</th><th>Notification</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($notifications as $n): ?>
                        <tr style="<?= $n['is_read'] ? '' : 'background:var(--bg-surface-subtle);' ?>">
                            <td class="mono" style="white-space:nowrap; font-size:12px;"><?= formatDateTime($n['created_at']) ?></td>
                            <td>
                                <strong style="<?= $n['is_read'] ? 'font-weight:500;' : '' ?>"><?= htmlspecialchars($n['title']) ?></strong>
                                <div style="font-size:12.5px; color:var(--text-muted);"><?= htmlspecialchars($n['body']) ?></div>
                            </td>
                            <td style="white-space:nowrap; text-align:right;">
                                <?php if (!empty($n['link'])): ?>
                                    <a href="/notifications.php?open=<?= (int)$n['id'] ?>" class="btn btn-primary btn-sm">Open</a>
                                <?php endif; ?>
                                <form method="POST" action="/notifications.php" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                    <input type="hidden" name="notif_action" value="<?= $n['is_read'] ? 'mark_unread' : 'mark_read' ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm"><?= $n['is_read'] ? 'Unread' : '&#10003;' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
