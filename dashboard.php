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
// v2.3.2: ONLY the Manager approves procurement (their approval is final).
// The Accountant pays for approved purchases via Cash Requests instead.
$myApprovalStage = null;
if ($currentUserRole === 'Manager') {
    $myApprovalStage = "p.status IN ('Pending Manager Review', 'Pending Approval')";
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

// ---- v2.2 dashboard additions ----
// Revenue & receivables (dispatches)
$revenueTotal = (float)$db->query('SELECT COALESCE(SUM(total_amount),0) FROM dispatches')->fetchColumn();
$revenuePaid  = (float)$db->query('SELECT COALESCE(SUM(amount_paid),0) FROM dispatches')->fetchColumn();
$receivables  = max(0, $revenueTotal - $revenuePaid);

// Money out: finalized purchases + petty cash expenses
$purchaseSpend = $finalizedPoTotal;
$pettySpend = (float)$db->query('SELECT COALESCE(SUM(amount),0) FROM petty_cash_expenses')->fetchColumn();
$rejectCostTotal = (float)$db->query(
    'SELECT COALESCE(SUM(pc.reject_cost_per_unit * (l.partial_reject_count + l.total_reject_count)),0)
     FROM process_reject_logs l JOIN processes pc ON l.process_id = pc.id'
)->fetchColumn();
$netPosition = $revenuePaid - $purchaseSpend - $pettySpend;

// Monthly petty cash budget usage
$pcBudget = (float)getSetting($db, 'petty_cash_monthly_budget', '10000000');
$pcMonthIssued = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM petty_cash_issuances WHERE status IN ('Active','Pending Countersign') AND issued_date >= '" . date('Y-m-01') . "'")->fetchColumn();
$pcBudgetPct = $pcBudget > 0 ? round($pcMonthIssued / $pcBudget * 100) : 0;

// My unread notifications
$myNotifications = [];
try {
    $stmtN = $db->prepare('SELECT * FROM notifications WHERE user_id = :u ORDER BY id DESC LIMIT 5');
    $stmtN->execute([':u' => $currentUserId]);
    $myNotifications = $stmtN->fetchAll();
} catch (Exception $e) {
    $myNotifications = [];
}

// Pending petty cash requests awaiting CEO verification
$pendingPcRequests = (int)$db->query("SELECT COUNT(*) FROM petty_cash_requests WHERE status = 'Pending CEO Verification'")->fetchColumn();
$floatsToCountersign = (int)$db->query("SELECT COUNT(*) FROM petty_cash_issuances WHERE status = 'Pending Countersign'")->fetchColumn();

// Machine downtime today
$downtimeToday = (int)$db->query("SELECT COALESCE(SUM(minutes),0) FROM machine_downtime WHERE report_date = '" . date('Y-m-d') . "'")->fetchColumn();

// Approval aging: oldest open procurement request (item 9)
$oldestPending = $db->query("SELECT DATEDIFF(CURRENT_DATE, MIN(date)) AS days FROM procurement_entries WHERE status NOT IN ('Finalized','Rejected')")->fetchColumn();

// ---- v2.3 dashboard additions: new workflow queues ----
// CEO: requisitions + corrections + cash requests awaiting them
$ceoRequisitions = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE requisition_status = 'Pending CEO Approval'")->fetchColumn();
$ceoCorrections = (int)$db->query("SELECT COUNT(*) FROM correction_requests WHERE status = 'Pending CEO Approval'")->fetchColumn();
$ceoCashRequests = (int)$db->query("SELECT COUNT(*) FROM cash_requests WHERE status = 'Pending CEO Approval'")->fetchColumn();
// Manager: production verifications + inventory requests + shipments
$mgrVerifyReports = (int)$db->query("SELECT COUNT(*) FROM daily_reports WHERE approval_status = 'Pending Manager Approval'")->fetchColumn();
$mgrInvRequests = (int)$db->query("SELECT COUNT(*) FROM inventory_requests WHERE status = 'Pending Manager Approval'")->fetchColumn();
$mgrShipments = (int)$db->query("SELECT COUNT(*) FROM shipment_orders WHERE status = 'Prepared - Awaiting Manager Approval'")->fetchColumn();
$mgrFailures = (int)$db->query("SELECT COUNT(*) FROM machine_failures WHERE status = 'Pending Verification'")->fetchColumn();
// Accountant: cash to disburse (their ONLY new duty)
$accToDisburse = (int)$db->query("SELECT COUNT(*) FROM cash_requests WHERE status = 'Approved - Sent to Accountant'")->fetchColumn();
// PO: requisitions awaiting CEO + shipments to prepare + releases
$poAwaitingCeo = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE submitted_by = " . (int)$currentUserId . " AND requisition_status = 'Pending CEO Approval'")->fetchColumn();
$poShipments = (int)$db->query("SELECT COUNT(*) FROM shipment_orders WHERE status = 'Requested by CEO'")->fetchColumn();
$poReleases = (int)$db->query("SELECT COUNT(*) FROM inventory_requests WHERE status = 'Approved - Awaiting Release'")->fetchColumn();
// Supervisor: my pending verifications + releases to confirm
$supPendingVerify = (int)$db->query("SELECT COUNT(*) FROM daily_reports WHERE supervisor_id = " . (int)$currentUserId . " AND approval_status = 'Pending Manager Approval'")->fetchColumn();
$supAwaitReceipt = (int)$db->query("SELECT COUNT(*) FROM inventory_requests WHERE requested_by = " . (int)$currentUserId . " AND status = 'Released - Awaiting Confirmation'")->fetchColumn();
// Everyone: workers present today (CEO + Manager see attendance)
$presentToday = (int)$db->query('SELECT COUNT(DISTINCT worker_id) FROM worker_attendance WHERE attend_date = CURRENT_DATE')->fetchColumn();

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
                <a href="/production.php" class="btn btn-primary">&#128269; Verify Production Logs</a>
            <?php elseif ($currentUserRole === 'Accountant'): ?>
                <a href="/cash_requests.php" class="btn btn-primary">&#128176; Cash Requests to Pay</a>
            <?php elseif ($currentUserRole === 'CEO'): ?>
                <a href="/petty_cash.php?action=issue" class="btn btn-primary">+ Issue Petty Cash Float</a>
                <a href="/users.php" class="btn btn-secondary">Manage Users</a>
            <?php elseif ($currentUserRole === 'Supervisor'): ?>
                <a href="/production.php?action=new" class="btn btn-primary">+ Log Shift Report</a>
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

    <!-- v2.2: Finance & Attention Worklist -->
    <?php if (in_array($currentUserRole, ['CEO', 'Manager', 'Accountant'], true)): ?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Revenue Collected</span>
                <span class="badge badge-success">Dispatches</span>
            </div>
            <div class="stat-value"><?= formatMoney($revenuePaid) ?></div>
            <div class="stat-desc">
                of <?= formatMoney($revenueTotal) ?> billed
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Customer Debts</span>
                <span class="badge badge-warning">Receivables</span>
            </div>
            <div class="stat-value" style="color:<?= $receivables > 0 ? 'var(--warning)' : 'var(--success)' ?>;">
                <?= formatMoney($receivables) ?>
            </div>
            <div class="stat-desc">
                unpaid balances on dispatched goods
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Money Out</span>
                <span class="badge badge-info">Spend</span>
            </div>
            <div class="stat-value"><?= formatMoney($purchaseSpend + $pettySpend) ?></div>
            <div class="stat-desc">
                <?= formatMoney($purchaseSpend) ?> purchases &bull; <?= formatMoney($pettySpend) ?> petty cash
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Petty Cash Budget</span>
                <span class="badge <?= $pcBudgetPct >= 100 ? 'badge-danger' : ($pcBudgetPct >= 80 ? 'badge-warning' : 'badge-success') ?>">
                    <?= $pcBudgetPct ?>% used
                </span>
            </div>
            <div class="stat-value"><?= formatMoney($pcMonthIssued) ?></div>
            <div class="stat-desc">
                of <?= formatMoney($pcBudget) ?> this month
                <?php if ($pcBudgetPct >= 80): ?>
                    <strong style="color:var(--danger);">&bull; near or over limit</strong>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($pcBudgetPct >= 80): ?>
    <div class="card alert-warning" style="padding:10px 16px; margin-bottom:24px; font-size:13px;">
        &#9888;&#65039; <strong>Budget alert:</strong> petty cash issued this month is at <?= $pcBudgetPct ?>% of the monthly budget (<?= formatMoney($pcMonthIssued) ?> of <?= formatMoney($pcBudget) ?>).
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($currentUserRole === 'CEO' && ($pendingPcRequests > 0 || $floatsToCountersign > 0)): ?>
    <div class="card" style="border:2px solid var(--warning); padding:12px 16px; margin-bottom:24px; font-size:13px;">
        &#128276; <strong>Petty cash needs your action:</strong>
        <?php if ($pendingPcRequests > 0): ?><?= $pendingPcRequests ?> request<?= $pendingPcRequests > 1 ? 's' : '' ?> awaiting verification<?php endif; ?>
        <?php if ($pendingPcRequests > 0 && $floatsToCountersign > 0): ?>&bull;<?php endif; ?>
        <?php if ($floatsToCountersign > 0): ?><?= $floatsToCountersign ?> float<?= $floatsToCountersign > 1 ? 's' : '' ?> awaiting countersignature<?php endif; ?>
        &mdash; <a href="/petty_cash.php">open Petty Cash</a>
    </div>
    <?php endif; ?>

    <?php if ($currentUserRole === 'CEO' && ($ceoRequisitions > 0 || $ceoCorrections > 0 || $ceoCashRequests > 0)): ?>
    <div class="card" style="border:2px solid var(--warning); padding:12px 16px; margin-bottom:24px; font-size:13px;">
        &#128276; <strong>Awaiting your approval:</strong>
        <?php if ($ceoRequisitions > 0): ?><?= $ceoRequisitions ?> procurement requisition(s) - <a href="/procurement.php">decide</a><?php endif; ?>
        <?php if ($ceoRequisitions > 0 && ($ceoCorrections > 0 || $ceoCashRequests > 0)): ?> &bull; <?php endif; ?>
        <?php if ($ceoCashRequests > 0): ?><?= $ceoCashRequests ?> cash request(s) - <a href="/cash_requests.php">decide</a><?php endif; ?>
        <?php if ($ceoCashRequests > 0 && $ceoCorrections > 0): ?> &bull; <?php endif; ?>
        <?php if ($ceoCorrections > 0): ?><?= $ceoCorrections ?> correction(s) - <a href="/corrections.php">decide</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($currentUserRole === 'Manager' && ($mgrVerifyReports > 0 || $mgrInvRequests > 0 || $mgrShipments > 0 || $mgrFailures > 0)): ?>
    <div class="card" style="border:2px solid var(--warning); padding:12px 16px; margin-bottom:24px; font-size:13px;">
        &#128276; <strong>Awaiting your approval / verification:</strong>
        <?php if ($mgrVerifyReports > 0): ?><?= $mgrVerifyReports ?> production log(s) - <a href="/production.php#verifications">verify</a><?php endif; ?>
        <?php if ($mgrVerifyReports > 0 && ($mgrInvRequests > 0 || $mgrShipments > 0 || $mgrFailures > 0)): ?> &bull; <?php endif; ?>
        <?php if ($mgrInvRequests > 0): ?><?= $mgrInvRequests ?> material request(s) - <a href="/inventory.php">decide</a><?php endif; ?>
        <?php if ($mgrInvRequests > 0 && ($mgrShipments > 0 || $mgrFailures > 0)): ?> &bull; <?php endif; ?>
        <?php if ($mgrShipments > 0): ?><?= $mgrShipments ?> shipment(s) - <a href="/shipments.php">decide</a><?php endif; ?>
        <?php if ($mgrShipments > 0 && $mgrFailures > 0): ?> &bull; <?php endif; ?>
        <?php if ($mgrFailures > 0): ?><?= $mgrFailures ?> machine failure report(s) - <a href="/production.php#failures">verify</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($currentUserRole === 'Accountant' && $accToDisburse > 0): ?>
    <div class="card" style="border:2px solid var(--warning); padding:12px 16px; margin-bottom:24px; font-size:13px;">
        &#128276; <strong><?= $accToDisburse ?> approved cash request(s) to disburse</strong> &mdash; <a href="/cash_requests.php">open Cash Requests</a>
    </div>
    <?php endif; ?>

    <?php if ($currentUserRole === 'Procurement Officer' && ($poAwaitingCeo > 0 || $poShipments > 0 || $poReleases > 0)): ?>
    <div class="card" style="border:2px solid var(--info, #3b82f6); padding:12px 16px; margin-bottom:24px; font-size:13px;">
        &#128276; <strong>Your queue:</strong>
        <?php if ($poAwaitingCeo > 0): ?><?= $poAwaitingCeo ?> requisition(s) with the C.E.O<?php endif; ?>
        <?php if ($poAwaitingCeo > 0 && ($poShipments > 0 || $poReleases > 0)): ?> &bull; <?php endif; ?>
        <?php if ($poShipments > 0): ?><?= $poShipments ?> shipment(s) to prepare - <a href="/shipments.php">prepare</a><?php endif; ?>
        <?php if ($poShipments > 0 && $poReleases > 0): ?> &bull; <?php endif; ?>
        <?php if ($poReleases > 0): ?><?= $poReleases ?> material release(s) - <a href="/inventory.php">release</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($currentUserRole === 'Supervisor' && ($supPendingVerify > 0 || $supAwaitReceipt > 0)): ?>
    <div class="card" style="border:2px solid var(--info, #3b82f6); padding:12px 16px; margin-bottom:24px; font-size:13px;">
        &#128276; <strong>Your queue:</strong>
        <?php if ($supPendingVerify > 0): ?><?= $supPendingVerify ?> log(s) awaiting the Manager's verification<?php endif; ?>
        <?php if ($supPendingVerify > 0 && $supAwaitReceipt > 0): ?> &bull; <?php endif; ?>
        <?php if ($supAwaitReceipt > 0): ?><?= $supAwaitReceipt ?> material release(s) to confirm - <a href="/inventory.php">confirm receipt</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- My Procurement Approvals (Manager only - final approver) -->
    <?php if ($myApprovalStage !== null): ?>
    <div class="card" style="<?= empty($myApprovals) ? 'margin-bottom:24px;' : 'border:2px solid var(--warning); margin-bottom:24px;' ?>">
        <div class="card-header">
            <div>
                <h3 class="card-title">
                    Procurement Awaiting Your Approval
                </h3>
                <p class="card-subtitle">
                    Submitted by the Procurement Officer &mdash; your approval is FINAL and locks the record (the Accountant arranges payment separately via Cash Requests)
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
                                        <input type="hidden" name="action" value="manager_decision">
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

    <!-- Notifications (all roles) -->
    <?php if (!empty($myNotifications)): ?>
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">&#128276; My Notifications</h3>
                <p class="card-subtitle">Things that need your attention</p>
            </div>
            <a href="/notifications.php" class="btn btn-secondary btn-sm">View all &rarr;</a>
        </div>
        <?php foreach (array_slice($myNotifications, 0, 5) as $notif): ?>
            <div style="padding:10px 16px; border-bottom:1px solid var(--border); font-size:13px; <?= !$notif['is_read'] ? 'background:var(--bg-hover);' : '' ?>">
                <strong><?= htmlspecialchars($notif['title']) ?></strong><br>
                <span style="color:var(--text-muted);"> <?= htmlspecialchars($notif['body']) ?></span>
                <span style="float:right; color:var(--text-muted); font-size:11px;"><?= htmlspecialchars(substr((string)$notif['created_at'], 0, 16)) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($canViewAudit): ?>
    <div class="card" style="font-size:13px; padding:10px 16px; margin-bottom:24px;">
        &#9201;&#65039; <strong>Approval aging:</strong> oldest open procurement record has waited <strong><?= $oldestPending !== null && $oldestPending !== false ? (int)$oldestPending : 0 ?> day(s)</strong>
        &bull; Machine downtime today: <strong><?= (int)$downtimeToday ?> min</strong>
        &bull; Reject cost to date: <strong><?= formatMoney($rejectCostTotal) ?></strong>
    </div>
    <?php endif; ?>

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
