<?php
/**
 * Factory Management System - Production Shift Reporting & Defect Logs
 * Pure PHP 8.2 & Plain HTML5/CSS3 (Zero Frameworks)
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole(['System Operator', 'Admin', 'Manager', 'Accountant']);

$pageTitle = 'Production Shift Reports';
$activeNav = 'production';

$currentUserRole = $_SESSION['user_role'];
$currentUserId = $_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

// Handle POST: Submit Shift Production Report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_shift_report') {
    if ($currentUserRole === 'System Operator') {
        setFlash('error', 'System Operator has read-only inspection access. Production logs cannot be recorded.');
        header('Location: /production.php');
        exit;
    }

    if (!in_array($currentUserRole, ['Manager', 'Admin'], true)) {
        setFlash('error', 'Only Managers or Administrators can file daily shift production logs.');
        header('Location: /production.php');
        exit;
    }

    $reportDate     = $_POST['report_date'] ?? date('Y-m-d');
    $shift          = $_POST['shift'] ?? 'Morning (06:00 - 14:00)';
    $machineId      = (int)($_POST['machine_id'] ?? 0);
    $processId      = (int)($_POST['process_id'] ?? 1);
    $unitsProduced  = (int)($_POST['units_produced'] ?? 0);
    $goodUnits      = (int)($_POST['good_units'] ?? 0);
    $partialRejects = (int)($_POST['partial_reject_count'] ?? 0);
    $totalRejects   = (int)($_POST['total_reject_count'] ?? 0);
    $rejectReason   = trim($_POST['reject_reason'] ?? 'None recorded');
    $rootCause      = trim($_POST['root_cause'] ?? 'Unspecified');
    $supervisorNotes= trim($_POST['supervisor_notes'] ?? '');

    // Validation
    if ($machineId <= 0 || $unitsProduced <= 0 || $goodUnits < 0) {
        setFlash('error', 'Invalid numbers. Ensure machine is selected, gross output is positive, and good units are non-negative.');
        header('Location: /production.php?action=new');
        exit;
    }

    if ($goodUnits + $partialRejects + $totalRejects > $unitsProduced) {
        setFlash('error', 'Reconciliation error: The sum of good units, partial rejects, and total scrap cannot exceed gross units produced.');
        header('Location: /production.php?action=new');
        exit;
    }

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
            ':produced'=> $unitsProduced,
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
        logAudit(
            $db,
            'PRODUCTION_REPORT_SUBMITTED',
            'PRODUCTION_REPORT',
            $reportId,
            "Shift report #{$reportId} filed by {$currentUserName}. Output: {$unitsProduced} units, Good: {$goodUnits}, Scrap: {$totalRejects}, Reworkable: {$partialRejects}."
        );

        $db->commit();
        setFlash('success', "Shift production report #{$reportId} recorded successfully!");
        header('Location: /production.php');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Error recording production shift log: ' . $e->getMessage());
        header('Location: /production.php?action=new');
        exit;
    }
}

// Fetch active operational machines & processes for dropdowns
$machines = $db->query("SELECT m.*, p.name AS process_name FROM machines m JOIN processes p ON m.process_id = p.id ORDER BY m.code ASC")->fetchAll();
$processes = $db->query("SELECT * FROM processes WHERE status = 'Active' ORDER BY id ASC")->fetchAll();

// Fetch reports with defect logs
$reports = $db->query("
    SELECT r.*, 
           m.code AS machine_code, 
           m.name AS machine_name, 
           p.name AS process_name,
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
    ORDER BY r.id DESC
")->fetchAll();

// Aggregate KPIs
$aggProduced = 0;
$aggGood = 0;
$aggScrap = 0;
$aggPartial = 0;
foreach ($reports as $r) {
    $aggProduced += (int)$r['units_produced'];
    $aggGood += (int)$r['good_units'];
    $aggScrap += (int)$r['scrap_rejects'];
    $aggPartial += (int)$r['partial_rejects'];
}
$aggYield = $aggProduced > 0 ? round(($aggGood / $aggProduced) * 100, 1) : 0;
$aggScrapRate = $aggProduced > 0 ? round(($aggScrap / $aggProduced) * 100, 2) : 0;

$showNew = isset($_GET['action']) && $_GET['action'] === 'new' && in_array($currentUserRole, ['Manager', 'Admin'], true);

include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 class="page-title">Production Shift Reports &amp; Reject Tracking</h2>
            <p class="page-subtitle">Floor machinery output, partial reworkable tolerances vs. total scrapped scrap analysis</p>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <?php if ($currentUserRole === 'System Operator'): ?>
                <span class="badge badge-info" style="padding:6px 12px; font-size:12px;">&#128065; Operator: Read-Only Inspection</span>
            <?php elseif ($currentUserRole === 'Accountant'): ?>
                <span class="badge badge-info" style="padding:6px 12px; font-size:12px;">&#128065; Accountant: View Only (expenses are managed in Petty Cash)</span>
            <?php elseif (!$showNew): ?>
                <a href="/production.php?action=new" class="btn btn-primary">+ Log Shift Report</a>
            <?php else: ?>
                <a href="/production.php" class="btn btn-secondary">&larr; Back to Shift Logs</a>
            <?php endif; ?>
        </div>
    </div>

    <?php displayFlash(); ?>

    <!-- Summary Performance Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Aggregate Output</span>
                <span class="badge badge-primary">Shift Cumulative</span>
            </div>
            <div class="stat-value"><?= formatNumber($aggProduced) ?> <span style="font-size:14px; color:var(--text-muted);">units</span></div>
            <div class="stat-desc">
                Across <?= count($reports) ?> logged production shifts
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Yield Quality Rate</span>
                <span class="badge badge-success"><?= $aggYield ?>% Pass</span>
            </div>
            <div class="stat-value" style="color:var(--success);"><?= formatNumber($aggGood) ?> <span style="font-size:14px; color:var(--text-muted);">good</span></div>
            <div class="stat-desc">
                Passed pneumatic &amp; visual QA standards
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
                        <input type="date" id="report_date" name="report_date" value="<?= date('Y-m-d') ?>" required class="form-control">
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

                <h4 style="font-size:14px; font-weight:700; color:var(--text-main); margin:18px 0 12px; border-bottom:1px solid var(--border-color); padding-bottom:6px;">2. Production Volume &amp; Defect Classification</h4>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="units_produced">Gross Units Produced *</label>
                        <input type="number" id="units_produced" name="units_produced" min="1" required value="1000" class="form-control" oninput="recalcDefects();">
                    </div>

                    <div class="form-group">
                        <label for="good_units">Good Units Passed QA *</label>
                        <input type="number" id="good_units" name="good_units" min="0" required value="960" class="form-control" oninput="recalcDefects();">
                    </div>

                    <div class="form-group">
                        <label for="partial_reject_count">Partial Rejects (Reworkable)</label>
                        <input type="number" id="partial_reject_count" name="partial_reject_count" min="0" value="25" class="form-control" oninput="recalcDefects();">
                        <span class="form-help">Items salvageable via secondary machining or de-burring</span>
                    </div>

                    <div class="form-group">
                        <label for="total_reject_count">Total Rejects (Scrapped)</label>
                        <input type="number" id="total_reject_count" name="total_reject_count" min="0" value="15" class="form-control" oninput="recalcDefects();">
                        <span class="form-help">Material scrapped; complete loss</span>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="reject_reason">Primary Defect / Reject Reason</label>
                        <input type="text" id="reject_reason" name="reject_reason" placeholder="e.g. Burr formation, dimensional out-of-spec, surface oxidation" value="Dimensional tolerance variation (+0.03mm)" class="form-control">
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="root_cause">Identified Root Cause &amp; Corrective Action</label>
                        <input type="text" id="root_cause" name="root_cause" placeholder="e.g. Upper tool punch wear, hydraulic oil temperature spike" value="Thermal expansion on spindle cooling line; coolant refilled" class="form-control">
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
            function recalcDefects() {
                var produced = parseInt(document.getElementById('units_produced').value) || 0;
                var good = parseInt(document.getElementById('good_units').value) || 0;
                var partial = parseInt(document.getElementById('partial_reject_count').value) || 0;
                var total = parseInt(document.getElementById('total_reject_count').value) || 0;
                // auto-align if good units not manually adjusted
            }
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
                        <th>Output</th>
                        <th>Good</th>
                        <th>Partial Rework</th>
                        <th>Total Scrap</th>
                        <th>Defect Reason &amp; Root Cause</th>
                        <th>Supervisor</th>
                        <th>Yield</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                        <tr><td colspan="10" class="empty-state">No shift reports logged yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reports as $r): 
                            $yield = $r['units_produced'] > 0 ? round(($r['good_units'] / $r['units_produced']) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td>
                                    <strong><?= formatDate($r['report_date']) ?></strong>
                                    <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($r['shift']) ?></div>
                                </td>
                                <td>
                                    <span class="mono" style="font-weight:700; color:var(--primary);"><?= htmlspecialchars($r['machine_code']) ?></span>
                                    <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($r['machine_name']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($r['process_name'] ?? 'General') ?></td>
                                <td><strong><?= formatNumber($r['units_produced']) ?></strong></td>
                                <td><span style="color:var(--success); font-weight:700;"><?= formatNumber($r['good_units']) ?></span></td>
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
                                </td>
                                <td><?= htmlspecialchars($r['supervisor_name']) ?></td>
                                <td>
                                    <strong style="color: <?= $yield >= 95 ? 'var(--success)' : 'var(--warning)' ?>;"><?= $yield ?>%</strong>
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
