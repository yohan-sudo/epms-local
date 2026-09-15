<?php
/**
 * Immutable Audit Trail Log Viewer
 * Pure PHP 8.2 & Plain HTML5/CSS3 (Zero Frameworks)
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole(['System Operator', 'Admin']);

$pageTitle = 'Immutable Audit Trail';
$activeNav = 'audit_logs';

$currentUserRole = $_SESSION['user_role'];
$currentUserId = $_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=factoryos_audit_trail_' . date('Y-m-d_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Timestamp', 'Actor Name', 'Actor Role', 'Action', 'Entity Type', 'Entity ID', 'Details']);

    $exportLogs = $db->query("
        SELECT a.id, a.timestamp, COALESCE(u.name, 'System') as actor_name, COALESCE(u.role, 'System') as actor_role,
               a.action, a.entity_type, a.entity_id, a.details
        FROM audit_logs a
        LEFT JOIN users u ON a.actor_id = u.id
        ORDER BY a.id DESC
    ");
    while ($row = $exportLogs->fetch()) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// Filter
$entityFilter = trim($_GET['entity'] ?? 'all');
$query = "
    SELECT a.*, 
           COALESCE(u.name, 'System') AS actor_name, 
           COALESCE(u.role, 'System') AS actor_role
    FROM audit_logs a
    LEFT JOIN users u ON a.actor_id = u.id
";

if ($entityFilter !== 'all' && !empty($entityFilter)) {
    $query .= " WHERE a.entity_type = " . $db->quote($entityFilter);
}

$query .= " ORDER BY a.id DESC";
$logs = $db->query($query)->fetchAll();

// Distinct entities for filter
$entities = $db->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type ASC")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 class="page-title">Immutable System Audit Trail</h2>
            <p class="page-subtitle">Chronological forensic ledger of all transactions, approvals, shift logs, and security events</p>
        </div>
        <div>
            <a href="/audit_logs.php?export=csv" class="btn btn-secondary btn-sm">&#128190; Export Audit CSV</a>
        </div>
    </div>

    <?php displayFlash(); ?>

    <!-- Filter Bar -->
    <div class="card" style="padding:14px 20px; margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div class="btn-group">
                <a href="/audit_logs.php?entity=all" class="btn btn-sm <?= $entityFilter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">
                    All Entities (<?= count($logs) ?>)
                </a>
                <?php foreach ($entities as $e): ?>
                    <a href="/audit_logs.php?entity=<?= urlencode($e) ?>" class="btn btn-sm <?= $entityFilter === $e ? 'btn-primary' : 'btn-secondary' ?>">
                        <?= htmlspecialchars($e) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div style="font-size:12px; color:var(--text-subtle);">
                Total <strong><?= count($logs) ?></strong> events recorded in database
            </div>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Log ID</th>
                        <th>Timestamp</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Transaction Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" class="empty-state">No audit logs match current filter criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="mono" style="color:var(--text-muted);">#<?= $log['id'] ?></td>
                                <td class="mono" style="white-space:nowrap; font-size:12px;"><?= formatDateTime($log['timestamp']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($log['actor_name']) ?></strong>
                                    <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($log['actor_role']) ?></div>
                                </td>
                                <td><span class="badge badge-secondary mono"><?= htmlspecialchars($log['action']) ?></span></td>
                                <td class="mono" style="font-size:12px; white-space:nowrap;">
                                    <span class="badge badge-info"><?= htmlspecialchars($log['entity_type']) ?> #<?= htmlspecialchars($log['entity_id']) ?></span>
                                </td>
                                <td style="font-size:13px; max-width:400px;"><?= htmlspecialchars($log['details']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
