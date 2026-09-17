<?php
/**
 * U EPMS - Executive & Operational Dashboard
 * Server-Side Rendered PHP with Plain HTML5 & CSS3
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();

// Procurement Officers are restricted exclusively to the procurement records portal
if (($_SESSION['user_role'] ?? '') === 'Procurement Officer') {
    commitSessionAndRedirect('/procurement.php');
}

$pageTitle = 'Operational Dashboard';
$activeNav = 'dashboard';

$currentUserRole = $_SESSION['user_role'];
$currentUserId = $_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

// Audit trail is restricted to the CEO (owner)
$canViewAudit = $currentUserRole === 'CEO';

// Operational KPIs
// 1. Production Stats
$prodStats = $db->query("
    SELECT
        COALESCE(SUM(units_produced), 0) AS total_produced,
        COALESCE(SUM(good_units), 0) AS total_good
    FROM daily_reports
")->fetch();

$totalProduced = (int)$prodStats['total_produced'];
$totalGood = (int)$prodStats['total_good'];
$yieldRate = $totalProduced > 0 ? round(($totalGood / $totalProduced) * 100, 1) : 0;

// Rejects breakdown
$rejectStats = $db->query("
    SELECT
        COALESCE(SUM(partial_reject_count), 0) AS total_partial,
        COALESCE(SUM(total_reject_count), 0) AS total_scrap
    FROM process_reject_logs
")->fetch();
$totalPartialRejects = (int)$rejectStats['total_partial'];
$totalScrap = (int)$rejectStats['total_scrap'];
$scrapRate = $totalProduced > 0 ? round(($totalScrap / $totalProduced) * 100, 2) : 0;

// 2. Petty Cash Stats
$issuanceSum = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM petty_cash_issuances WHERE status = 'Active'")->fetchColumn();
$expenseSum = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM petty_cash_expenses")->fetchColumn();
$pettyCashBalance = max(0, $issuanceSum - $expenseSum);

// 3. Procurement Records Pipeline
$pendingManagerCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status IN ('Pending Manager Review', 'Pending Approval')")->fetchColumn();
$pendingAccountantCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status = 'Pending Accountant Review'")->fetchColumn();
$pendingPoCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status NOT IN ('Finalized', 'Rejected')")->fetchColumn();
$finalizedPoTotal = (float)$db->query("SELECT COALESCE(SUM(total_cost), 0) FROM procurement_entries WHERE status = 'Finalized'")->fetchColumn();
$rejectedPoCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status = 'Rejected'")->fetchColumn();

// Procurement records awaiting MY stage of approval (role-specific worklist)
$myApprovalStage = null;
if ($currentUserRole === 'Manager') {
    $myApprovalStage = "p.status IN ('Pending Manager Review', 'Pending Approval')";
} elseif ($currentUserRole === 'Accountant') {
    $myApprovalStage = "p.status = 'Pending Accountant Review'";
}
$myApprovals = [];
if ($myApprovalStage !== null) {
    $stmtMy = $db->prepare("
        SELECT p.*, u.name as submitter_name
        FROM procurement_entries p
        LEFT JOIN users u ON p.submitted_by = u.id
        WHERE {$myApprovalStage}
        ORDER BY p.date ASC, p.id ASC
        LIMIT 6
    ");
    $stmtMy->execute();
    $myApprovals = $stmtMy->fetchAll();
}

// Recent procurement records (visible to every non-procurement role)
$recentPos = $db->query("
    SELECT p.*, u.name as submitter_name
    FROM procurement_entries p
    LEFT JOIN users u ON p.submitted_by = u.id
    ORDER BY p.id DESC
    LIMIT 5
")->fetchAll();

// Recent Shift Reports
$recentReports = $db->query("
    SELECT r.*, m.code AS machine_code, m.name AS machine_name, u.name AS supervisor_name,
           (SELECT COALESCE(SUM(partial_reject_count), 0) FROM process_reject_logs WHERE report_id = r.id) as partial_rejects,
           (SELECT COALESCE(SUM(total_reject_count), 0) FROM process_reject_logs WHERE report_id = r.id) as scrap_rejects
    FROM daily_reports r
    LEFT JOIN machines m ON r.machine_id = m.id
    LEFT JOIN users u ON r.supervisor_id = u.id
    ORDER BY r.id DESC
    LIMIT 4
")->fetchAll();

// Recent Audit Logs (CEO only)
$recentAudits = [];
if ($canViewAudit) {
    $recentAudits = $db->query("
        SELECT a.*, COALESCE(u.name, 'System') as actor_name, COALESCE(u.role, 'System') as actor_role
        FROM audit_logs a
        LEFT JOIN users u ON a.actor_id = u.id
        ORDER BY a.id DESC
        LIMIT 5
    ")->fetchAll();
}

include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 class="page-title">Welcome back, <?= htmlspecialchars($currentUserName) ?></h2>
            <p class="page-subtitle">Plant Operations &bull; Real-time status for active shift cycle</p>
        </div>
        <div style="display:flex; gap:10px;">
            <?php if ($currentUserRole === 'Manager'): ?>
                <a href="/production.php?action=new" class="btn btn-primary">+ Submit Shift Report</a>
                <a href="/petty_cash.php?action=expense" class="btn btn-secondary">+ Record Expense</a>
            <?php elseif ($currentUserRole === 'Accountant'): ?>
                <a href="/petty_cash.php?action=expense" class="btn btn-primary">+ Record Expense</a>
            <?php elseif ($currentUserRole === 'CEO'): ?>
                <a href="/petty_cash.php?action=issue" class="btn btn-primary">+ Issue Petty Cash Float</a>
                <a href="/users.php" class="btn btn-secondary">Manage Users</a>
            <?php endif; ?>
        </div>
    </div>

    <?php displayFlash(); ?>

    <!-- High-Impact KPI Overview -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Gross Production</span>
                <span class="badge badge-primary">Output</span>
            </div>
            <div class="stat-value"><?= formatNumber($totalProduced) ?> <span style="font-size:14px; font-weight:normal; color:var(--text-muted);">units</span></div>
            <div class="stat-desc">
                <strong style="color:var(--success);"><?= $yieldRate ?>%</strong> QA yield passing rate
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Scrap &amp; Rejects</span>
                <span class="badge badge-warning"><?= $scrapRate ?>% Scrap</span>
            </div>
            <div class="stat-value" style="color:var(--danger);"><?= formatNumber($totalScrap) ?> <span style="font-size:14px; font-weight:normal; color:var(--text-muted);">scrap</span></div>
            <div class="stat-desc">
                +<?= formatNumber($totalPartialRejects) ?> reworkable units on floor
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Active Petty Cash</span>
                <span class="badge badge-success">Ledger</span>
            </div>
            <div class="stat-value"><?= formatMoney($pettyCashBalance) ?></div>
            <div class="stat-desc">
                <?= formatMoney($expenseSum) ?> disbursed from <?= formatMoney($issuanceSum) ?> floats
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Procurement Queue</span>
                <span class="badge badge-info"><?= $pendingPoCount ?> Open Records</span>
            </div>
            <div class="stat-value"><?= formatMoney($finalizedPoTotal) ?></div>
            <div class="stat-desc">
                Finalized spend (TZS) &bull; <?= $pendingManagerCount ?> awaiting Manager &bull; <?= $pendingAccountantCount ?> awaiting Accountant
            </div>
        </div>
    </div>

    <!-- My Procurement Approvals (Manager & Accountant only) -->
    <?php if ($myApprovalStage !== null): ?>
    <div class="card" style="<?= empty($myApprovals) ? 'margin-bottom:24px;' : 'border:2px solid var(--warning); margin-bottom:24px;' ?>">
        <div class="card-header">
            <div>
                <h3 class="card-title">
                    <?php if ($currentUserRole === 'Manager'): ?>
                        Procurement Awaiting Your Approval
                    <?php else: ?>
                        Procurement Awaiting Your Final Approval
                    <?php endif; ?>
                </h3>
                <p class="card-subtitle">
                    <?php if ($currentUserRole === 'Manager'): ?>
                        Submitted by the Procurement Officer &mdash; your approval forwards each record to the Accountant
                    <?php else: ?>
                        Manager-approved records &mdash; you are the final approver; approval locks the record
                    <?php endif; ?>
                </p>
            </div>
            <a href="/procurement.php" class="btn btn-secondary btn-sm">Open Procurement Records &rarr;</a>
        </div>

        <?php if (empty($myApprovals)): ?>
            <div class="empty-state" style="padding:18px;">
                <p style="margin:0; color:var(--text-muted);">&#10003; Nothing is waiting for your decision right now.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Ref #</th>
                        <th>Supplier / Item</th>
                        <th>Total Cost</th>
                        <th>Status</th>
                        <th>Submitted By</th>
                        <th>Decision</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($myApprovals as $po): ?>
                        <tr>
                            <td class="mono"><strong><?= htmlspecialchars($po['reference_no']) ?></strong></td>
                            <td>
                                <div style="font-weight:600;"><?= htmlspecialchars($po['supplier']) ?></div>
                                <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($po['item_name']) ?></div>
                            </td>
                            <td><strong><?= formatMoney((float)$po['total_cost']) ?></strong></td>
                            <td><?= getProcurementStatusBadge($po['status']) ?></td>
                            <td><?= htmlspecialchars($po['submitter_name'] ?? '&mdash;') ?></td>
                            <td>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <form method="POST" action="/procurement.php" style="display:inline;">
                                        <input type="hidden" name="action" value="<?= $currentUserRole === 'Manager' ? 'manager_decision' : 'accountant_decision' ?>">
                                        <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>">
                                        <input type="hidden" name="notes" value="">
                                        <button type="submit" name="decision" value="approve" class="btn btn-success btn-sm"
                                                onclick="return confirm('Approve <?= htmlspecialchars($po['reference_no']) ?> for <?= formatMoney((float)$po['total_cost']) ?>?');">&#10003; Approve</button>
                                        <button type="submit" name="decision" value="reject" class="btn btn-danger btn-sm"
                                                onclick="return confirm('Reject <?= htmlspecialchars($po['reference_no']) ?>? A rejection reason will be recorded.');">&#10007;</button>
                                    </form>
                                    <a href="/procurement.php?view_id=<?= (int)$po['id'] ?>" class="btn btn-secondary btn-sm">Inspect</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; margin-bottom: 24px;">
        <!-- Left: Recent Procurement Records -->
        <div class="card" style="margin-bottom:0;">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Recent Procurement Records</h3>
                    <p class="card-subtitle">Officer submits &rarr; Manager approves &rarr; Accountant finalizes &rarr; locked</p>
                </div>
                <a href="/procurement.php" class="btn btn-secondary btn-sm">Open Records &rarr;</a>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ref #</th>
                            <th>Supplier / Item</th>
                            <th>Total Cost</th>
                            <th>Status</th>
                            <th>Submitted By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentPos)): ?>
                            <tr><td colspan="5" class="empty-state">No procurement records submitted yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentPos as $po): ?>
                                <tr>
                                    <td class="mono"><strong><?= htmlspecialchars($po['reference_no']) ?></strong></td>
                                    <td>
                                        <div style="font-weight:600;"><?= htmlspecialchars($po['supplier']) ?></div>
                                        <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($po['item_name']) ?></div>
                                    </td>
                                    <td><strong><?= formatMoney((float)$po['total_cost']) ?></strong></td>
                                    <td><?= getProcurementStatusBadge($po['status']) ?></td>
                                    <td><?= htmlspecialchars($po['submitter_name'] ?? '&mdash;') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: Role Capabilities & Access Matrix Reminder -->
        <div class="card" style="margin-bottom:0;">
            <div class="card-header">
                <h3 class="card-title">Role &amp; Access Matrix</h3>
            </div>
            <div style="font-size:13px; color:var(--text-muted); line-height:1.6;">
                <p style="margin-bottom:12px;">You are currently operating with the permissions of: <strong style="color:var(--text-main);"><?= htmlspecialchars($currentUserRole) ?></strong>.</p>

                <div style="padding:12px; background:var(--bg-surface-subtle); border-radius:var(--radius-md); border:1px solid var(--border-color); margin-bottom:12px;">
                    <div style="font-weight:700; color:var(--text-main); margin-bottom:6px;">Your Current Authority:</div>
                    <?php if ($currentUserRole === 'Manager'): ?>
                        <ul style="padding-left:18px; margin:0;">
                            <li>Log daily shift production reports</li>
                            <li>Record partial &amp; total reject defects</li>
                            <li>Record expenses against petty cash floats</li>
                        </ul>
                    <?php elseif ($currentUserRole === 'Accountant'): ?>
                        <ul style="padding-left:18px; margin:0;">
                            <li>Record expenses against petty cash floats</li>
                            <li>Review and reconcile expense receipts</li>
                            <li>Cannot issue new floats (CEO authority)</li>
                        </ul>
                    <?php elseif ($currentUserRole === 'CEO'): ?>
                        <ul style="padding-left:18px; margin:0;">
                            <li>Issue new petty cash floats</li>
                            <li>Manage machinery, processes &amp; plant settings</li>
                            <li>Manage users (ban &amp; delete) and view audit trail</li>
                        </ul>
                    <?php elseif ($currentUserRole === 'Procurement Officer'): ?>
                        <ul style="padding-left:18px; margin:0;">
                            <li>Submit procurement records of what has been procured</li>
                            <li>Records route to the Manager, then the Accountant (final approver)</li>
                        </ul>
                    <?php endif; ?>
                </div>

                <p style="font-size:12px; color:var(--text-subtle);">
                    Tip: Your role's permissions are fixed for the session — sign in with your own account each time to act under the right authority.
                </p>
            </div>
        </div>
    </div>

    <!-- Production Shift Reports & Reject Breakdown -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Recent Production Shift Reports</h3>
                <p class="card-subtitle">Real-time machine output, yield metrics, and reject analysis</p>
            </div>
            <a href="/production.php" class="btn btn-secondary btn-sm">All Production Logs &rarr;</a>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date &amp; Shift</th>
                        <th>Machine</th>
                        <th>Gross Units</th>
                        <th>Good Output</th>
                        <th>Defects (Partial / Scrap)</th>
                        <th>Supervisor</th>
                        <th>Yield</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentReports)): ?>
                        <tr><td colspan="7" class="empty-state">No shift logs found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentReports as $rep):
                            $repYield = $rep['units_produced'] > 0 ? round(($rep['good_units'] / $rep['units_produced']) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td>
                                    <strong><?= formatDate($rep['report_date']) ?></strong>
                                    <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($rep['shift']) ?></div>
                                </td>
                                <td>
                                    <span class="mono" style="font-weight:700; color:var(--primary);"><?= htmlspecialchars($rep['machine_code'] ?? '&mdash;') ?></span>
                                    <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($rep['machine_name'] ?? 'Machine removed') ?></div>
                                </td>
                                <td><strong><?= formatNumber((int)$rep['units_produced']) ?></strong></td>
                                <td><span style="color:var(--success); font-weight:700;"><?= formatNumber((int)$rep['good_units']) ?></span></td>
                                <td>
                                    <span class="badge badge-warning"><?= (int)$rep['partial_rejects'] ?> reworkable</span>
                                    <span class="badge badge-danger"><?= (int)$rep['scrap_rejects'] ?> scrap</span>
                                </td>
                                <td><?= htmlspecialchars($rep['supervisor_name'] ?? 'Unknown') ?></td>
                                <td>
                                    <strong style="color: <?= $repYield >= 95 ? 'var(--success)' : 'var(--warning)' ?>;"><?= $repYield ?>%</strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($canViewAudit): ?>
    <!-- Live Audit Trail Log (CEO only) -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">System Activity &amp; Audit Trail</h3>
                <p class="card-subtitle">Immutable chronological operational event logs (restricted to the CEO)</p>
            </div>
            <a href="/audit_logs.php" class="btn btn-secondary btn-sm">Full Audit Trail &rarr;</a>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentAudits as $log): ?>
                        <tr>
                            <td class="mono" style="font-size:12px; white-space:nowrap;"><?= formatDateTime($log['timestamp']) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($log['actor_name']) ?></strong>
                                <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($log['actor_role']) ?></div>
                            </td>
                            <td><span class="badge badge-secondary mono"><?= htmlspecialchars($log['action']) ?></span></td>
                            <td class="mono" style="font-size:12px;"><?= htmlspecialchars($log['entity_type']) ?> #<?= htmlspecialchars($log['entity_id']) ?></td>
                            <td style="font-size:13px;"><?= htmlspecialchars($log['details']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
