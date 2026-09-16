<?php
/**
 * Factory Management System - Production Shift Reporting & Defect Logs
 * Pure PHP 8.2 & Plain HTML5/CSS3 (Zero Frameworks)
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/components/filter_bar.php';

requireRole(['CEO', 'Manager', 'Accountant']);

$pageTitle = 'Production Shift Reports';
$activeNav = 'production';

$currentUserRole = $_SESSION['user_role'];
$currentUserId = $_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

// Handle POST: Submit Shift Production Report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_shift_report') {
    if (!in_array($currentUserRole, ['Manager', 'CEO'], true)) {
        setFlash('error', 'Only the Manager or CEO can file daily shift production logs.');
        header('Location: /production.php');
        exit;
    }

    $errors = [];
    $reportDate     = field_date($errors, 'report_date', 'Report date', true, true) ?? date('Y-m-d');
    $shift          = field_choice($errors, 'shift', 'Shift period', [
        'Morning (06:00 - 14:00)', 'Afternoon (14:00 - 22:00)', 'Night (22:00 - 06:00)'
    ]) ?? 'Morning (06:00 - 14:00)';
    $machineId      = field_int($errors, 'machine_id', 'Machine', 1) ?? 0;
    $processId      = field_int($errors, 'process_id', 'Manufacturing process', 1) ?? 0;
    $unitsProcessed = field_int($errors, 'units_processed', 'Units processed', 1) ?? 0;
    $partialRejects = field_int($errors, 'partial_reject_count', 'Partial rejects (reworkable)', 0) ?? 0;
    $totalRejects   = field_int($errors, 'total_reject_count', 'Total rejects (scrapped)', 0) ?? 0;
    $rejectReason   = field_text($errors, 'reject_reason', 'Reject reason', false, 0, 255) ?? '';
    $rootCause      = field_text($errors, 'root_cause', 'Root cause', false, 0, 255) ?? '';
    $supervisorNotes= field_text($errors, 'supervisor_notes', 'Supervisor notes', false, 0, 1000) ?? '';

    // Validation
    if ($errors) {
        redirectWithErrors('/production.php?action=new', $errors);
    }

    /* Unit status classification: Cups (process 5) is where finished broom
     * sticks are counted, so units logged there are COMPLETED GOODS. Units
     * logged at any earlier stage are IN-PROCESS (still moving down the line). */
    $isCompletedGoods = ($processId === 5);

    if ($partialRejects + $totalRejects > $unitsProcessed) {
        setFlash('error', 'Reconciliation error: The sum of partial rejects and total scrap cannot exceed the units processed.');
        header('Location: /production.php?action=new');
        exit;
    }

    // Accepted units = what came through minus rejects
    $goodUnits = max(0, $unitsProcessed - $partialRejects - $totalRejects);

    try {
        $db->beginTransaction();

        // 1. Insert Daily Report
        $stmt = $db->prepare("
            INSERT INTO daily_reports (report_date, shift, supervisor_id, machine_id, units_produced, good_units, supervisor_notes, created_at)
            VALUES (:rdate, :shift, :sup_id, :mach_id, :produced, :good, :notes, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':rdate'   => $reportDate,
            ':shift'   => $shift,
            ':sup_id'  => $currentUserId,
            ':mach_id' => $machineId,
            ':produced'=> $unitsProcessed,
            ':good'    => $goodUnits,
            ':notes'   => $supervisorNotes
        ]);
        $reportId = (int)$db->lastInsertId();

        // 2. Insert Process Reject Breakdown
        $stmtReject = $db->prepare("
            INSERT INTO process_reject_logs (report_id, process_id, partial_reject_count, total_reject_count, reject_reason, root_cause)
            VALUES (:rep_id, :proc_id, :partial, :total, :reason, :cause)
        ");
        $stmtReject->execute([
            ':rep_id'  => $reportId,
            ':proc_id' => $processId,
            ':partial' => $partialRejects,
            ':total'   => $totalRejects,
            ':reason'  => $rejectReason,
            ':cause'   => $rootCause
        ]);

        // 3. Log Audit
        $unitStatus = $isCompletedGoods ? 'Completed goods (counted at Cups)' : 'In-process';
        logAudit(
            $db,
            'PRODUCTION_REPORT_SUBMITTED',
            'PRODUCTION_REPORT',
            $reportId,
            "Shift report #{$reportId} filed by {$currentUserName}. Units processed: {$unitsProcessed} ({$unitStatus}), Accepted: {$goodUnits}, Scrap: {$totalRejects}, Reworkable: {$partialRejects}."
        );

        $db->commit();
        setFlash('success', "Shift report #{$reportId} recorded: " . formatNumber($unitsProcessed) . " units processed ({$unitStatus}).");
        header('Location: /production.php');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Error recording production shift log: ' . $e->getMessage());
        header('Location: /production.php?action=new');
        exit;
    }
}

// ---- Search & date-range filter (shared contract: q, from, to) ----
$filter = read_filter_params();
foreach ($filter['errors'] as $fe) {
    setFlash('warning', $fe);
}

$searchSql = "";
$searchArgs = [];
if ($filter['q'] !== '') {
    $like = '%' . $filter['q'] . '%';
    $searchSql = " AND (m.code LIKE :like1 OR m.name LIKE :like2 OR r.shift LIKE :like3
        OR r.supervisor_notes LIKE :like4 OR l.reject_reason LIKE :like5 OR l.root_cause LIKE :like6
        OR u.name LIKE :like7 OR CAST(r.units_produced AS CHAR) LIKE :like8)";
    for ($i = 1; $i <= 8; $i++) {
        $searchArgs[":like{$i}"] = $like;
    }
}

// Fetch reports with defect logs + filters (process joined for unit-status classification)
$stmtReports = $db->prepare("
    SELECT r.*, 
           m.code AS machine_code, 
           m.name AS machine_name, 
           COALESCE(p.id, 0) AS process_id,
           COALESCE(p.name, 'General') AS process_name,
           u.name AS supervisor_name,
           COALESCE(l.partial_reject_count, 0) AS partial_rejects,
           COALESCE(l.total_reject_count, 0) AS scrap_rejects,
           l.reject_reason,
           l.root_cause
    FROM daily_reports r
    JOIN machines m ON r.machine_id = m.id
    JOIN users u ON r.supervisor_id = u.id
    LEFT JOIN process_reject_logs l ON l.report_id = r.id
    LEFT JOIN processes p ON l.process_id = p.id
    WHERE 1=1
      " . ($filter['from'] !== '' ? " AND r.report_date >= :date_from" : "") . "
      " . ($filter['to'] !== '' ? " AND r.report_date <= :date_to" : "") . "
      " . $searchSql . "
    ORDER BY r.report_date DESC, r.id DESC
");
if ($filter['from'] !== '') { $stmtReports->bindValue(':date_from', $filter['from']); }
if ($filter['to'] !== '') { $stmtReports->bindValue(':date_to', $filter['to']); }
foreach ($searchArgs as $k => $v) { $stmtReports->bindValue($k, $v); }
$stmtReports->execute();
$reports = $stmtReports->fetchAll();

// Fetch active operational machines & processes for dropdowns
$machines = $db->query("SELECT m.*, p.name AS process_name FROM machines m JOIN processes p ON m.process_id = p.id ORDER BY m.code ASC")->fetchAll();
$processes = $db->query("SELECT * FROM processes WHERE status = 'Active' ORDER BY id ASC")->fetchAll();

// Aggregate KPIs (split by unit status: completed goods at Cups vs in-process)
$aggProduced = 0;
$aggGood = 0;
$aggScrap = 0;
$aggPartial = 0;
$aggCompletedUnits = 0;
$aggInProcessUnits = 0;
foreach ($reports as $r) {
    $aggProduced += (int)$r['units_produced'];
    $aggGood += (int)$r['good_units'];
    $aggScrap += (int)$r['scrap_rejects'];
    $aggPartial += (int)$r['partial_rejects'];
    if ((int)($r['process_id'] ?? 0) === 5) {
        $aggCompletedUnits += (int)$r['units_produced'];
    } else {
        $aggInProcessUnits += (int)$r['units_produced'];
    }
}
$aggYield = $aggProduced > 0 ? round(($aggGood / $aggProduced) * 100, 1) : 0;
$aggScrapRate = $aggProduced > 0 ? round(($aggScrap / $aggProduced) * 100, 2) : 0;

$showNew = isset($_GET['action']) && $_GET['action'] === 'new' && in_array($currentUserRole, ['Manager', 'CEO'], true);

include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 class="page-title">Production Shift Reports &amp; Reject Tracking</h2>
            <p class="page-subtitle">Units processed per stage, completed goods counted at Cups, and reject analysis</p>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <?php if ($currentUserRole === 'Accountant'): ?>
                <span class="badge badge-info" style="padding:6px 12px; font-size:12px;">&#128065; Accountant: View Only (expenses are managed in Petty Cash)</span>
            <?php elseif (!$showNew): ?>
                <a href="/production.php?action=new" class="btn btn-primary">+ Log Shift Report</a>
            <?php else: ?>
                <a href="/production.php" class="btn btn-secondary">&larr; Back to Shift Logs</a>
            <?php endif; ?>
        </div>
    </div>

    <?php displayFlash(); ?>

    <?php render_filter_bar([
        'action'       => '/production.php',
        'q'            => $filter['q'],
        'from'         => $filter['from'],
        'to'           => $filter['to'],
        'placeholder'  => 'Search machine, supervisor, notes, rejects, output...',
        'reportsKey'   => 'production',
    ]); ?>

    <!-- Summary Performance Cards -->
    <div class="stats-grid">        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Completed Goods (Cups)</span>
                <span class="badge badge-success">Finished Broom Sticks</span>
            </div>
            <div class="stat-value"><?= formatNumber($aggCompletedUnits) ?> <span style="font-size:14px; color:var(--text-muted);">units</span></div>
            <div class="stat-desc">Counted at the Cups stage &mdash; ready for packaging</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Units In Process</span>
                <span class="badge badge-primary">Earlier Stages</span>
            </div>
            <div class="stat-value"><?= formatNumber($aggInProcessUnits) ?> <span style="font-size:14px; color:var(--text-muted);">units</span></div>
            <div class="stat-desc">Logged at Rounding, Sanding &amp; P.V.C stages, not yet counted as finished</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Acceptance Rate</span>
                <span class="badge badge-info"><?= $aggYield ?>% Accepted</span>
            </div>
            <div class="stat-value" style="color:var(--success);">
                <?= formatNumber($aggGood) ?> <span style="font-size:14px; color:var(--text-muted);">accepted</span>
            </div>
            <div class="stat-desc">
                Of <?= formatNumber($aggProduced) ?> total units processed across <?= count($reports) ?> shift logs
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Scrap &amp; Defect Rate</span>
                <span class="badge badge-danger"><?= $aggScrapRate ?>% Scrap</span>
            </div>
            <div class="stat-value" style="color:var(--danger);"><?= formatNumber($aggScrap) ?> <span style="font-size:14px; color:var(--text-muted);">scrap</span></div>
            <div class="stat-desc">
                +<?= formatNumber($aggPartial) ?> reworkable units salvageable
            </div>
        </div>
    </div>

    <!-- New Shift Report Form -->
    <?php if ($showNew): ?>
        <div class="card" style="border: 2px solid var(--primary-border);">
            <div class="card-header">
                <div>
                    <h3 class="card-title">File Daily Shift Production Report</h3>
                    <p class="card-subtitle">Complete machine cycle telemetry, good output, and reject categorization</p>
                </div>
            </div>

            <form method="POST" action="/production.php">
                <input type="hidden" name="action" value="save_shift_report">
                
                <h4 style="font-size:14px; font-weight:700; color:var(--text-main); margin-bottom:12px; border-bottom:1px solid var(--border-color); padding-bottom:6px;">1. Shift &amp; Machinery Telemetry</h4>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="report_date">Report Date *</label>
                        <input type="date" id="report_date" name="report_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required class="form-control"
                               data-required-error="Report date is required." data-max-error="Report date cannot be in the future.">
                    </div>

                    <div class="form-group">
                        <label for="shift">Shift Period *</label>
                        <select id="shift" name="shift" class="form-control">
                            <option value="Morning (06:00 - 14:00)">Morning (06:00 - 14:00)</option>
                            <option value="Afternoon (14:00 - 22:00)">Afternoon (14:00 - 22:00)</option>
                            <option value="Night (22:00 - 06:00)">Night (22:00 - 06:00)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="machine_id">Assigned Machine *</label>
                        <select id="machine_id" name="machine_id" class="form-control" required>
                            <?php foreach ($machines as $m): ?>
                                <option value="<?= $m['id'] ?>">
                                    <?= htmlspecialchars($m['code']) ?> - <?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['status']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="process_id">Manufacturing Process *</label>
                        <select id="process_id" name="process_id" class="form-control" required>
                            <?php foreach ($processes as $p): ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <h4 style="font-size:14px; font-weight:700; color:var(--text-main); margin:18px 0 12px; border-bottom:1px solid var(--border-color); padding-bottom:6px;">2. Units Processed &amp; Defect Classification</h4>
                <div style="margin-bottom:14px; padding:10px 14px; background:var(--bg-surface-subtle); border-radius:var(--radius-md); font-size:13px; color:var(--text-muted);">
                    <strong id="unit-status-tag" style="color:var(--primary);">Units In Process</strong>
                    <span id="unit-status-help"> &mdash; units logged at any stage before Cups are in-process; they become Completed Goods only when counted at Cups.</span>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="units_processed">Units Processed *</label>
                        <input type="number" id="units_processed" name="units_processed" min="1" max="1000000" required value="1000" class="form-control" oninput="recalcDefects();"
                               data-required-error="Units processed is required." data-min-error="Must process at least 1 unit." data-max-error="Enter a realistic figure (max 1,000,000).">
                    </div>

                    <div class="form-group">
                        <label for="partial_reject_count">Partial Rejects (Reworkable)</label>
                        <input type="number" id="partial_reject_count" name="partial_reject_count" min="0" max="1000000" value="25" class="form-control" oninput="recalcDefects();"
                               data-min-error="Partial rejects cannot be negative." data-max-error="Too large — max 1,000,000.">
                        <span class="form-help">Items salvageable via rework at the same stage</span>
                    </div>

                    <div class="form-group">
                        <label for="total_reject_count">Total Rejects (Scrapped)</label>
                        <input type="number" id="total_reject_count" name="total_reject_count" min="0" max="1000000" value="15" class="form-control" oninput="recalcDefects();"
                               data-min-error="Scrapped units cannot be negative." data-max-error="Too large — max 1,000,000.">
                        <span class="form-help">Material scrapped; complete loss</span>
                    </div>

                    <div class="form-group">
                        <label>Accepted Units (auto)</label>
                        <div id="accepted-preview" class="form-control" style="background:var(--bg-surface-subtle); font-weight:700; color:var(--success);">960</div>
                        <span class="form-help">Units processed &minus; rejects &mdash; calculated automatically, no QA field needed</span>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="reject_reason">Primary Defect / Reject Reason</label>
                        <input type="text" id="reject_reason" name="reject_reason" placeholder="e.g. Burr formation, dimensional out-of-spec, surface oxidation" value="Dimensional tolerance variation (+0.03mm)" maxlength="255" class="form-control"
                               data-plaintext>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="root_cause">Identified Root Cause &amp; Corrective Action</label>
                        <input type="text" id="root_cause" name="root_cause" placeholder="e.g. Upper tool punch wear, hydraulic oil temperature spike" value="Thermal expansion on spindle cooling line; coolant refilled" maxlength="255" class="form-control"
                               data-plaintext>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="supervisor_notes">Supervisor Operational Notes</label>
                        <textarea id="supervisor_notes" name="supervisor_notes" rows="2" class="form-control" placeholder="General machine performance, operator shifts, preventive checks"></textarea>
                    </div>
                </div>

                <div style="margin-top:16px; display:flex; justify-content:flex-end; gap:10px;">
                    <a href="/production.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save &amp; Commit Shift Report &rarr;</button>
                </div>
            </form>
        </div>

        <script>
            var CUPS_PROCESS_ID = 5;
            function recalcDefects() {
                var processed = parseInt(document.getElementById('units_processed').value) || 0;
                var partial = parseInt(document.getElementById('partial_reject_count').value) || 0;
                var total = parseInt(document.getElementById('total_reject_count').value) || 0;
                var accepted = Math.max(0, processed - partial - total);
                document.getElementById('accepted-preview').textContent = accepted.toLocaleString();
                var processSel = document.getElementById('process_id');
                var isCups = processSel && parseInt(processSel.value, 10) === CUPS_PROCESS_ID;
                var tag = document.getElementById('unit-status-tag');
                var help = document.getElementById('unit-status-help');
                if (tag && help) {
                    tag.textContent = isCups ? 'Completed Goods' : 'Units In Process';
                    tag.style.color = isCups ? 'var(--success)' : 'var(--primary)';
                    help.textContent = isCups
                        ? ' \u2014 units counted at Cups are finished broom sticks (completed goods).'
                        : ' \u2014 units logged at any stage before Cups are in-process; they become Completed Goods only when counted at Cups.';
                }
            }
            document.getElementById('process_id').addEventListener('change', recalcDefects);
            recalcDefects();
        </script>
    <?php endif; ?>

    <!-- Shift Reports Ledger -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Production Shift Ledger</h3>
                <p class="card-subtitle">Detailed breakdown of gross output, QA yield, and defect causes</p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date &amp; Shift</th>
                        <th>Machine</th>
                        <th>Process</th>
                        <th>Unit Status</th>
                        <th>Units Processed</th>
                        <th>Accepted</th>
                        <th>Partial Rework</th>
                        <th>Total Scrap</th>
                        <th>Defect Reason &amp; Root Cause</th>
                        <th>Supervisor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                        <tr><td colspan="10" class="empty-state">No shift reports match the current search / date filter.</td></tr>
                    <?php else: ?>                        <?php foreach ($reports as $r):
                            $isCompleted = ((int)$r['process_id'] === 5);
                        ?>
                            <tr>
                                <td>
                                    <strong><?= formatDate($r['report_date']) ?></strong>
                                    <div style="font-size:11px; color:var(--text-muted);">
                                        <?= htmlspecialchars($r['shift']) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="mono" style="font-weight:700; color:var(--primary);">
                                        <?= htmlspecialchars($r['machine_code']) ?>
                                    </span>
                                    <div style="font-size:11px; color:var(--text-muted);">
                                        <?= htmlspecialchars($r['machine_name']) ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($r['process_name']) ?></td>
                                <td>
                                    <?php if ($isCompleted): ?>
                                        <span class="badge badge-success">Completed Goods</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">In Process</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= formatNumber($r['units_produced']) ?></strong></td>
                                <td><span style="color:var(--success); font-weight:700;">
                                    <?= formatNumber($r['good_units']) ?>
                                </span></td>
                                <td>
                                    <?php if ($r['partial_rejects'] > 0): ?>
                                        <span class="badge badge-warning"><?= formatNumber($r['partial_rejects']) ?> units</span>
                                    <?php else: ?>
                                        <span style="color:var(--text-subtle);">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['scrap_rejects'] > 0): ?>
                                        <span class="badge badge-danger"><?= formatNumber($r['scrap_rejects']) ?> scrap</span>
                                    <?php else: ?>
                                        <span style="color:var(--text-subtle);">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width:240px;">
                                    <?php if ($r['reject_reason']): ?>
                                        <div style="font-weight:600; font-size:12.5px;"><?= htmlspecialchars($r['reject_reason']) ?></div>
                                        <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($r['root_cause'] ?? '') ?></div>
                                    <?php else: ?>
                                        <span style="color:var(--text-subtle);">No defects recorded</span>
                                    <?php endif; ?>
                                </td>                                <td><?= htmlspecialchars($r['supervisor_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
