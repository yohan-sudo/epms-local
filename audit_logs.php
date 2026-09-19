<?php
/**
 * Immutable Audit Trail Log Viewer
 * Pure PHP 8.2 & Plain HTML5/CSS3 (Zero Frameworks)
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/components/filter_bar.php';

requireRole(['CEO']);

$pageTitle = 'Immutable Audit Trail';
$activeNav = 'audit_logs';

$currentUserRole = $_SESSION['user_role'];
$currentUserId = $_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

// ---- Search & date-range filter (shared contract: q, from, to) ----
$filter = read_filter_params();

// Filter conditions shared by the table and the CSV export
$auditConditions = [];
$auditArgs = [];
$entityFilter = trim((string)($_GET['entity'] ?? 'all'));
if ($entityFilter !== 'all' && $entityFilter !== '') {
    $auditConditions[] = "a.entity_type = :entity";
    $auditArgs[':entity'] = $entityFilter;
}
if ($filter['from'] !== '') { $auditConditions[] = "DATE(a.timestamp) >= :date_from"; $auditArgs[':date_from'] = $filter['from']; }
if ($filter['to'] !== '') { $auditConditions[] = "DATE(a.timestamp) <= :date_to"; $auditArgs[':date_to'] = $filter['to']; }
if ($filter['q'] !== '') {
    $like = '%' . $filter['q'] . '%';
    $auditConditions[] = "(a.action LIKE :q1 OR a.details LIKE :q2 OR a.entity_type LIKE :q3 OR a.entity_id LIKE :q4 OR u.name LIKE :q5 OR u.role LIKE :q6)";
    for ($i = 1; $i <= 6; $i++) { $auditArgs[":q{$i}"] = $like; }
}
$auditWhere = $auditConditions ? 'WHERE ' . implode(' AND ', $auditConditions) : '';

// Handle CSV Export (respects current filters)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=epms_audit_trail_' . date('Y-m-d_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Timestamp', 'Actor Name', 'Actor Role', 'Action', 'Entity Type', 'Entity ID', 'Details']);

    $stmtExport = $db->prepare("
        SELECT a.id, a.timestamp, COALESCE(u.name, 'System') as actor_name, COALESCE(u.role, 'System') as actor_role,
               a.action, a.entity_type, a.entity_id, a.details
        FROM audit_logs a
        LEFT JOIN users u ON a.actor_id = u.id
        {$auditWhere}
        ORDER BY a.id DESC
    ");
    foreach ($auditArgs as $k => $v) { $stmtExport->bindValue($k, $v); }
    $stmtExport->execute();
    while ($row = $stmtExport->fetch()) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$stmtLogs = $db->prepare("
    SELECT a.*, 
           COALESCE(u.name, 'System') AS actor_name, 
           COALESCE(u.role, 'System') AS actor_role
    FROM audit_logs a
    LEFT JOIN users u ON a.actor_id = u.id
    {$auditWhere}
    ORDER BY a.timestamp DESC, a.id DESC
");
foreach ($auditArgs as $k => $v) { $stmtLogs->bindValue($k, $v); }
$stmtLogs->execute();
$logs = $stmtLogs->fetchAll();

// Distinct entities for filter
$entities = $db->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type ASC")->fetchAll(PDO::FETCH_COLUMN);

// v2.2: tamper-evidence check - re-walk the hash chain
[$chainOk, $chainBrokenAt, $chainChecked, $chainSealed] = verifyAuditChain($db);

include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 class="page-title">Immutable System Audit Trail</h2>
            <p class="page-subtitle">Chronological forensic ledger of all transactions, approvals, shift logs, and security events</p>
        </div>
        <div style="display:flex; gap:8px;">
            <a href="/reports.php?report=audit&amp;from=<?= urlencode($filter['from']) ?>&amp;to=<?= urlencode($filter['to']) ?>" class="btn btn-secondary btn-sm">&#128202; Audit Report (PDF/Excel)</a>
            <a href="/audit_logs.php?export=csv&amp;entity=<?= urlencode($entityFilter) ?>&amp;q=<?= urlencode($filter['q']) ?>&amp;from=<?= urlencode($filter['from']) ?>&amp;to=<?= urlencode($filter['to']) ?>" class="btn btn-secondary btn-sm">&#128190; Export Filtered CSV</a>
        </div>
    </div>

    <?php displayFlash(); ?>

    <?php if ($chainOk): ?>
        <div class="card alert-success" style="padding:10px 16px; margin-bottom:16px; font-size:13px;">
            &#128274; <strong>Chain verified:</strong> <?= $chainSealed ?> sealed entries intact (plus <?= ($chainChecked - $chainSealed) ?> legacy entries from before sealing) - no tampering detected.
        </div>
    <?php else: ?>
        <div class="card alert-danger" style="padding:10px 16px; margin-bottom:16px; font-size:13px;">
            &#9888;&#65039; <strong>TAMPERING DETECTED:</strong> the audit chain breaks at entry #<?= $chainBrokenAt ?> (of <?= $chainChecked ?> checked). Entries from that point on have been altered or removed. Investigate immediately.
        </div>
    <?php endif; ?>

    <?php render_filter_bar([
        'action'       => '/audit_logs.php',
        'q'            => $filter['q'],
        'from'         => $filter['from'],
        'to'           => $filter['to'],
        'placeholder'  => 'Search action, actor, details, entity...',
        'extraHidden'  => $entityFilter !== 'all' ? ['entity' => $entityFilter] : [],
    ]); ?>

    <!-- Entity Filter Bar -->
    <div class="card" style="padding:14px 20px; margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div class="btn-group">
                <a href="/audit_logs.php?entity=all" class="btn btn-sm <?= $entityFilter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">
                    All Entities (<?= count($logs) ?>)
                </a>
                <?php foreach ($entities as $e): ?>
                    <a href="/audit_logs.php?entity=<?= urlencode($e) ?>&amp;q=<?= urlencode($filter['q']) ?>&amp;from=<?= urlencode($filter['from']) ?>&amp;to=<?= urlencode($filter['to']) ?>" class="btn btn-sm <?= $entityFilter === $e ? 'btn-primary' : 'btn-secondary' ?>">
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
                                <td style="font-size:13px; max-width:400px;">
                                    <?= htmlspecialchars($log['details']) ?>
                                    <div style="font-size:11px; color:var(--text-subtle); margin-top:2px;">
                                        From <?= htmlspecialchars($log['ip_address'] ?? 'unknown') ?>
                                        &bull; seal <span class="mono" title="SHA-256 chain hash"><?= htmlspecialchars(substr((string)($log['row_hash'] ?? ''), 0, 10)) ?></span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
