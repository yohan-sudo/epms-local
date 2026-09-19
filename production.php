<?php
/**
 * Factory Management System - Production Shift Reporting & Defect Logs
 * Pure PHP 8.2 & Plain HTML5/CSS3 (Zero Frameworks)
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/components/filter_bar.php';

requireRole(['CEO', 'Manager', 'Supervisor', 'Assistant Manager']); // v2.3: Accountant removed - payments role only

$pageTitle = 'Production Shift Reports';
$activeNav = 'production';

$currentUserRole = $_SESSION['user_role'];
$currentUserId = $_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

// Handle POST: Submit Shift Production Report (v2.3: Supervisor logs, Manager verifies)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_shift_report') {
    if (!in_array($currentUserRole, ['Supervisor', 'CEO'], true)) {
        setFlash('error', 'Logging production is the Supervisor\'s job. The Manager verifies entries but does not log them.');
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
    $rejectReason   = field_choice($errors, 'reject_reason', 'Reject reason', [
        'No rejects', 'Burr formation', 'Dimensional out-of-spec', 'Surface oxidation',
        'Cracked / split material', 'Machine misalignment', 'Wrong feedstock (bad batch)',
        'Operator error', 'Power interruption', 'Other (see notes)',
    ]) ?? 'No rejects';
    $rootCause      = field_text($errors, 'root_cause', 'Root cause', false, 0, 255) ?? '';
    // Optional: only validate when the user actually picked a batch
    $batchId = 0;
    if (trim((string)($_POST['batch_id'] ?? '')) !== '') {
        $batchId = field_int($errors, 'batch_id', 'Material batch', 1) ?? 0;
    }
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

        // 2. Insert Process Reject Breakdown (with material batch link)
        $stmtReject = $db->prepare("
            INSERT INTO process_reject_logs (report_id, process_id, partial_reject_count, total_reject_count, reject_reason, root_cause, batch_id)
            VALUES (:rep_id, :proc_id, :partial, :total, :reason, :cause, :batch)
        ");
        $stmtReject->execute([
            ':rep_id'  => $reportId,
            ':proc_id' => $processId,
            ':partial' => $partialRejects,
            ':total'   => $totalRejects,
            ':reason'  => $rejectReason,
            ':cause'   => $rootCause,
            ':batch'   => $batchId > 0 ? $batchId : null,
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

        // v2.3 approval gate: ALL logs (Supervisor or CEO) await the Manager's
        // verification - the Manager never logs, only verifies.
        $approvalStatus = 'Pending Manager Approval';
        $db->prepare('UPDATE daily_reports SET approval_status = :st, approved_by = NULL WHERE id = :id')
           ->execute([
               ':st' => $approvalStatus,
               ':id' => $reportId,
           ]);

        $db->commit();

        // v2.2: alert the Manager+CEO when rejects are heavy or a target was missed
        $target = $targetsByProcess[(int)$processId] ?? 0;
        if ($target > 0 && $goodUnits < $target) {
            notifyRoles($db, ['Manager', 'CEO'], 'Shift below target', "Shift #{$reportId} on {$processId}: {$goodUnits} accepted vs target {$target} (" . date('M d') . ').', '/production.php');
        }
        if ($approvalStatus === 'Pending Manager Approval') {
            notifyRoles($db, ['Manager'], 'Production log awaiting your verification', "Shift #{$reportId} ({$unitsProcessed} units) logged by {$currentUserName} - verify its authenticity.", '/production.php');
        }

        setFlash('success', "Shift report #{$reportId} recorded: " . formatNumber($unitsProcessed) . " units processed ({$unitStatus})." . ($approvalStatus === 'Pending Manager Approval' ? ' Awaiting the Manager\'s verification.' : ''));
        header('Location: /production.php');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Error recording production shift log: ' . $e->getMessage());
        header('Location: /production.php?action=new');
        exit;
    }
}

// Handle POST: Manager verifies a Supervisor's production log (v2.3)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_report') {
    if ($currentUserRole !== 'Manager') {
        setFlash('error', 'Only the Manager verifies production logs.');
        header('Location: /production.php');
        exit;
    }
    $repId = (int)($_POST['report_id'] ?? 0);
    $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approve' : 'reject';
    $notes = mb_substr(trim((string)($_POST['approval_notes'] ?? '')), 0, 255);
    $rep = $db->query('SELECT * FROM daily_reports WHERE id = ' . $repId)->fetch();
    if (!$rep || $rep['approval_status'] !== 'Pending Manager Approval') {
        setFlash('error', 'That report is not awaiting verification.');
        header('Location: /production.php');
        exit;
    }
    if ($decision === 'approve') {
        $db->prepare("UPDATE daily_reports SET approval_status = 'Manager Verified', approved_by = :by, approved_at = CURRENT_TIMESTAMP, approval_notes = :n WHERE id = :id")
           ->execute([':by' => $currentUserId, ':n' => $notes, ':id' => $repId]);
        logAudit($db, 'PRODUCTION_REPORT_VERIFIED', 'PRODUCTION_REPORT', $repId, "Manager {$currentUserName} verified shift report #{$repId} ({$rep['units_produced']} units) as authentic.");
        notifyUser($db, (int)$rep['supervisor_id'], 'Production log verified', "Shift #{$repId} was verified by the Manager." . ($notes !== '' ? " Note: {$notes}" : ''), '/production.php');
        setFlash('success', "Report #{$repId} verified.");
    } else {
        $db->prepare("UPDATE daily_reports SET approval_status = 'Rejected by Manager', approved_by = :by, approved_at = CURRENT_TIMESTAMP, approval_notes = :n WHERE id = :id")
           ->execute([':by' => $currentUserId, ':n' => $notes !== '' ? $notes : 'Rejected', ':id' => $repId]);
        logAudit($db, 'PRODUCTION_REPORT_REJECTED', 'PRODUCTION_REPORT', $repId, "Manager {$currentUserName} REJECTED shift report #{$repId}: {$notes}");
        notifyUser($db, (int)$rep['supervisor_id'], 'Production log rejected', "Shift #{$repId} was rejected by the Manager" . ($notes !== '' ? ": {$notes}" : '.') . ' Request a correction if needed.', '/corrections.php');
        setFlash('warning', "Report #{$repId} rejected.");
    }
    header('Location: /production.php');
    exit;
}

// Handle POST: Supervisor reports machine failure -> Manager verifies (v2.3)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report_failure') {
    if (!in_array($currentUserRole, ['Supervisor', 'Manager', 'CEO'], true)) {
        setFlash('error', 'Only the Supervisor can report machine failures.');
        header('Location: /production.php');
        exit;
    }
    $errors = [];
    $fMachine = field_int($errors, 'machine_id', 'Machine', 1) ?? 0;
    $fReason  = field_text($errors, 'failure_reason', 'Failure reason', true, 3, 255) ?? '';
    if ($errors) {
        redirectWithErrors('/production.php', $errors);
    }
    $db->prepare("INSERT INTO machine_failures (machine_id, reported_by, failure_reason) VALUES (:m, :by, :r)")
       ->execute([':m' => $fMachine, ':by' => $currentUserId, ':r' => $fReason]);
    logAudit($db, 'MACHINE_FAILURE_REPORTED', 'MACHINE', $fMachine, "{$currentUserName} reported a machine failure: {$fReason} - awaiting the Manager's verification.");
    notifyRoles($db, ['Manager'], 'Machine failure reported', "Machine #{$fMachine}: {$fReason} (reported by {$currentUserName}) - verify and arrange repair.", '/production.php#failures');
    setFlash('success', 'Failure report sent to the Manager.');
    header('Location: /production.php');
    exit;
}

// Handle POST: Manager verifies a failure report (v2.3)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_failure') {
    if ($currentUserRole !== 'Manager') {
        setFlash('error', 'Only the Manager verifies failure reports.');
        header('Location: /production.php');
        exit;
    }
    $fId = (int)($_POST['failure_id'] ?? 0);
    $decision = ($_POST['decision'] ?? '') === 'confirm' ? 'confirm' : 'dismiss';
    $notes = mb_substr(trim((string)($_POST['manager_notes'] ?? '')), 0, 255);
    $f = $db->query('SELECT * FROM machine_failures WHERE id = ' . $fId)->fetch();
    if (!$f || $f['status'] !== 'Pending Verification') {
        setFlash('error', 'That report is not awaiting verification.');
        header('Location: /production.php');
        exit;
    }
    if ($decision === 'confirm') {
        $db->prepare("UPDATE machine_failures SET status = 'Verified - Repair Needed', verified_by = :by, verified_at = CURRENT_TIMESTAMP, manager_notes = :n WHERE id = :id")
           ->execute([':by' => $currentUserId, ':n' => $notes, ':id' => $fId]);
        logAudit($db, 'MACHINE_FAILURE_VERIFIED', 'MACHINE', (int)$f['machine_id'], "Manager {$currentUserName} verified failure report #{$fId}: {$f['failure_reason']}");
        setFlash('success', 'Failure verified - arrange repair.');
    } else {
        $db->prepare("UPDATE machine_failures SET status = 'Dismissed', verified_by = :by, verified_at = CURRENT_TIMESTAMP, manager_notes = :n WHERE id = :id")
           ->execute([':by' => $currentUserId, ':n' => $notes, ':id' => $fId]);
        logAudit($db, 'MACHINE_FAILURE_DISMISSED', 'MACHINE', (int)$f['machine_id'], "Manager {$currentUserName} dismissed failure report #{$fId}: {$notes}");
        setFlash('info', 'Report dismissed.');
    }
    notifyUser($db, (int)$f['reported_by'], 'Failure report decision', "Your failure report #{$fId} was " . ($decision === 'confirm' ? 'verified' : 'dismissed') . ' by the Manager.', '/production.php#failures');
    header('Location: /production.php');
    exit;
}

// Handle POST: Supervisor logs electricity usage (v2.3)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_electricity') {
    if (!in_array($currentUserRole, ['Supervisor', 'Manager', 'CEO'], true)) {
        setFlash('error', 'Only the Supervisor logs electricity usage.');
        header('Location: /production.php');
        exit;
    }
    $errors = [];
    $eDate = field_date($errors, 'reading_date', 'Date', true, true) ?? date('Y-m-d');
    $eShift = field_text($errors, 'shift', 'Shift', false, 0, 40) ?? 'Morning';
    $eKwh  = field_float($errors, 'meter_kwh', 'Meter reading (kWh)', 0.01) ?? 0;
    $eUnits= field_float($errors, 'units_produced', 'Units produced', 0) ?? 0;
    $eNote = field_text($errors, 'notes', 'Notes', false, 0, 255) ?? '';
    if ($errors) {
        redirectWithErrors('/production.php', $errors);
    }
    $db->prepare('INSERT INTO electricity_readings (reading_date, shift, meter_kwh, units_produced, notes, logged_by) VALUES (:d, :s, :k, :u, :n, :by)')
       ->execute([':d' => $eDate, ':s' => $eShift, ':k' => $eKwh, ':u' => $eUnits, ':n' => $eNote, ':by' => $currentUserId]);
    logAudit($db, 'ELECTRICITY_LOGGED', 'ELECTRICITY', $eDate, "{$currentUserName} logged {$eKwh} kWh for {$eShift} ({$eDate}) - {$eUnits} units produced.");
    setFlash('success', 'Electricity usage recorded.');
    header('Location: /production.php');
    exit;
}

// Handle POST: log machine downtime (item 20)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_downtime') {
    if (!in_array($currentUserRole, ['Manager', 'CEO'], true)) {
        setFlash('error', 'Only the Manager or CEO can log machine downtime.');
        header('Location: /production.php');
        exit;
    }
    $errors = [];
    $dtMachine = field_int($errors, 'machine_id', 'Machine', 1) ?? 0;
    $dtDate    = field_date($errors, 'report_date', 'Date', true, true) ?? date('Y-m-d');
    $dtShift   = field_text($errors, 'shift', 'Shift', false, 0, 40) ?? '';
    $dtStart   = (string)($_POST['started_at'] ?? '');
    $dtEnd     = (string)($_POST['ended_at'] ?? '');
    $dtReason  = field_text($errors, 'reason', 'Reason', true, 3, 255) ?? '';
    if (!preg_match('/^\d{2}:\d{2}$/', $dtStart)) {
        $errors[] = '• Start time is required (HH:MM).';
    }
    if ($errors) {
        redirectWithErrors('/production.php', $errors);
    }
    $minutes = 0;
    if (preg_match('/^\d{2}:\d{2}$/', $dtEnd)) {
        $minutes = (int)round((strtotime($dtEnd) - strtotime($dtStart)) / 60);
        if ($minutes < 0) {
            $minutes += 24 * 60; // overnight
        }
    }
    $db->prepare("INSERT INTO machine_downtime (machine_id, report_date, shift, started_at, ended_at, minutes, reason, recorded_by) VALUES (:m, :d, :s, :st, :en, :min, :r, :by)")
       ->execute([':m' => $dtMachine, ':d' => $dtDate, ':s' => $dtShift, ':st' => $dtStart, ':en' => $dtEnd !== '' ? $dtEnd : null, ':min' => $minutes, ':r' => $dtReason, ':by' => $currentUserId]);
    logAudit($db, 'MACHINE_DOWNTIME_LOGGED', 'MACHINE', $dtMachine, "{$currentUserName} logged downtime of {$minutes} min ({$dtStart}" . ($dtEnd ? "-{$dtEnd}" : '-ongoing') . "): {$dtReason}");
    setFlash('success', "Downtime logged ({$minutes} minutes).");
    header('Location: /production.php');
    exit;
}

// Handle POST: set a shift target for a process (item 21, CEO/Manager)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_target') {
    if (!in_array($currentUserRole, ['Manager', 'CEO'], true)) {
        setFlash('error', 'Only the Manager or CEO can set shift targets.');
        header('Location: /production.php');
        exit;
    }
    $errors = [];
    $tgProcess = field_int($errors, 'process_id', 'Process', 1) ?? 0;
    $tgFrom    = field_date($errors, 'effective_from', 'Effective from') ?? date('Y-m-d');
    $tgAmount  = field_int($errors, 'target_per_shift', 'Target per shift', 1) ?? 0;
    if ($errors) {
        redirectWithErrors('/production.php', $errors);
    }
    $db->prepare("INSERT INTO shift_targets (process_id, effective_from, target_per_shift, created_by) VALUES (:p, :f, :t, :by)")
       ->execute([':p' => $tgProcess, ':f' => $tgFrom, ':t' => $tgAmount, ':by' => $currentUserId]);
    logAudit($db, 'SHIFT_TARGET_SET', 'PRODUCTION', $tgProcess, "{$currentUserName} set target of {$tgAmount} units/shift for process #{$tgProcess} effective {$tgFrom}");
    setFlash('success', "Target set: {$tgAmount} units per shift.");
    header('Location: /production.php');
    exit;
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
           ap.name AS approver_name,
           COALESCE(l.partial_reject_count, 0) AS partial_rejects,
           COALESCE(l.total_reject_count, 0) AS scrap_rejects,
           l.reject_reason,
           l.root_cause,
           mb.batch_code
    FROM daily_reports r
    JOIN machines m ON r.machine_id = m.id
    JOIN users u ON r.supervisor_id = u.id
    LEFT JOIN users ap ON ap.id = r.approved_by
    LEFT JOIN process_reject_logs l ON l.report_id = r.id
    LEFT JOIN processes p ON l.process_id = p.id
    LEFT JOIN material_batches mb ON l.batch_id = mb.id
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

// v2.3: pending verifications (Manager) + failure reports + electricity log
$pendingReports = $currentUserRole === 'Manager'
    ? $db->query("SELECT r.id, r.report_date, r.shift, r.units_produced, r.good_units, u.name AS supervisor_name
                  FROM daily_reports r JOIN users u ON u.id = r.supervisor_id
                  WHERE r.approval_status = 'Pending Manager Approval' ORDER BY r.id DESC LIMIT 20")->fetchAll()
    : [];
$pendingFailures = in_array($currentUserRole, ['Manager', 'CEO'], true)
    ? $db->query("SELECT f.*, m.code AS machine_code, u.name AS reporter_name
                  FROM machine_failures f JOIN machines m ON m.id = f.machine_id JOIN users u ON u.id = f.reported_by
                  WHERE f.status = 'Pending Verification' ORDER BY f.id DESC LIMIT 20")->fetchAll()
    : [];
$failureHistory = $db->query("SELECT f.*, m.code AS machine_code, u.name AS reporter_name
                  FROM machine_failures f JOIN machines m ON m.id = f.machine_id JOIN users u ON u.id = f.reported_by
                  ORDER BY f.id DESC LIMIT 10")->fetchAll();
$electricity = $db->query("SELECT e.*, u.name AS logger_name FROM electricity_readings e JOIN users u ON u.id = e.logged_by ORDER BY e.id DESC LIMIT 10")->fetchAll();
$processes = $db->query("SELECT * FROM processes WHERE status = 'Active' ORDER BY id ASC")->fetchAll();

// Material batches for the reject-linkage dropdown
$activeBatches = $db->query("SELECT id, batch_code, material_name FROM material_batches ORDER BY id DESC LIMIT 30")->fetchAll();

// Shift targets: latest effective target per process (item 21)
$targetsByProcess = [];
try {
    $tRows = $db->query("
        SELECT st.process_id, st.target_per_shift
        FROM shift_targets st
        WHERE st.effective_from <= CURRENT_DATE
          AND st.id = (SELECT MAX(st2.id) FROM shift_targets st2 WHERE st2.process_id = st.process_id AND st2.effective_from <= CURRENT_DATE)
    ")->fetchAll();
    foreach ($tRows as $t) {
        $targetsByProcess[(int)$t['process_id']] = (int)$t['target_per_shift'];
    }
} catch (Exception $e) {
    $targetsByProcess = [];
}

// Reject cost per unit per process (item 25) for waste-in-shillings
$rejectCostByProcess = [];
foreach ($db->query('SELECT id, reject_cost_per_unit FROM processes')->fetchAll() as $pc) {
    $rejectCostByProcess[(int)$pc['id']] = (float)$pc['reject_cost_per_unit'];
}
$rejectMoney = 0.0;
foreach ($reports as $r) {
    $cost = $rejectCostByProcess[(int)($r['process_id'] ?? 0)] ?? 0.0;
    $rejectMoney += $cost * ((int)$r['partial_rejects'] + (int)$r['scrap_rejects']);
}

// Downtime entries in the current filter window
$downtimeWhere = "1=1";
$downtimeArgs = [];
if ($filter['from'] !== '') { $downtimeWhere .= " AND d.report_date >= :df"; $downtimeArgs[':df'] = $filter['from']; }
if ($filter['to'] !== '') { $downtimeWhere .= " AND d.report_date <= :dt"; $downtimeArgs[':dt'] = $filter['to']; }
$stmtDown = $db->prepare("
    SELECT d.*, m.code AS machine_code, u.name AS recorder_name
    FROM machine_downtime d
    JOIN machines m ON d.machine_id = m.id
    LEFT JOIN users u ON d.recorded_by = u.id
    WHERE {$downtimeWhere}
    ORDER BY d.report_date DESC, d.id DESC
    LIMIT 200
");
foreach ($downtimeArgs as $k => $v) { $stmtDown->bindValue($k, $v); }
$stmtDown->execute();
$downtime = $stmtDown->fetchAll();

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

$showNew = isset($_GET['action']) && $_GET['action'] === 'new' && in_array($currentUserRole, ['Supervisor', 'CEO'], true); // v2.3.2: Manager verifies, never logs

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
            <?php elseif ($currentUserRole === 'Assistant Manager'): ?>
                <span class="badge badge-info" style="padding:6px 12px; font-size:12px;">&#128065; Assistant Manager: View Only (reports and monitoring)</span>
            <?php elseif ($currentUserRole === 'Supervisor' && !$showNew): ?>
                <a href="/production.php?action=new" class="btn btn-primary">+ Log Shift Report</a>
                <a href="/production.php#electricity" class="btn btn-secondary">&#9889; Electricity</a>
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
    <?php if ($pendingReports): ?>
    <!-- v2.3: Manager verification worklist -->
    <div class="card" style="border-left:4px solid #f59e0b; padding:14px 16px; margin-bottom:16px;" id="verifications">
        <h3 style="font-size:15px; font-weight:800; margin-bottom:8px;">&#128269; Production Logs Awaiting Your Verification (<?= count($pendingReports) ?>)</h3>
        <p style="font-size:12px; color:var(--text-secondary, #64748b); margin-bottom:10px;">Confirm each entry is authentic before it counts in official records.</p>
        <?php foreach ($pendingReports as $pr): ?>
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid var(--border, #e2e8f0); flex-wrap:wrap;">
                <div style="font-size:13px;">
                    <strong>#<?= (int)$pr['id'] ?></strong> &bull; <?= formatDate($pr['report_date']) ?> &bull; <?= htmlspecialchars($pr['shift']) ?> &bull;
                    <?= formatNumber((int)$pr['units_produced']) ?> processed / <?= formatNumber((int)$pr['good_units']) ?> good &bull; by <strong><?= htmlspecialchars($pr['supervisor_name']) ?></strong>
                </div>
                <form method="post" action="/production.php" style="display:inline-flex; gap:6px;">
                    <input type="hidden" name="action" value="verify_report">
                    <input type="hidden" name="report_id" value="<?= (int)$pr['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input class="form-control" name="approval_notes" placeholder="Optional note" maxlength="255" style="width:150px; padding:4px 8px; font-size:12px;">
                    <button class="btn btn-success" name="decision" value="approve" style="font-size:11px; padding:4px 10px;">Verify</button>
                    <button class="btn btn-danger" name="decision" value="reject" style="font-size:11px; padding:4px 10px;">Reject</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($pendingFailures): ?>
    <div class="card" style="border-left:4px solid #dc2626; padding:14px 16px; margin-bottom:16px;" id="failures">
        <h3 style="font-size:15px; font-weight:800; margin-bottom:8px;">&#9888;&#65039; Machine Failures Awaiting Verification (<?= count($pendingFailures) ?>)</h3>
        <?php foreach ($pendingFailures as $pf): ?>
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid var(--border, #e2e8f0); flex-wrap:wrap;">
                <div style="font-size:13px;">
                    <strong><?= htmlspecialchars($pf['machine_code']) ?></strong> &bull; <?= htmlspecialchars($pf['failure_reason']) ?> &bull; reported by <?= htmlspecialchars($pf['reporter_name']) ?> (<?= formatDateTime($pf['reported_at']) ?>)
                </div>
                <form method="post" action="/production.php" style="display:inline-flex; gap:6px;">
                    <input type="hidden" name="action" value="verify_failure">
                    <input type="hidden" name="failure_id" value="<?= (int)$pf['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input class="form-control" name="manager_notes" placeholder="Optional note" maxlength="255" style="width:150px; padding:4px 8px; font-size:12px;">
                    <button class="btn btn-success" name="decision" value="confirm" style="font-size:11px; padding:4px 10px;">Confirm</button>
                    <button class="btn btn-danger" name="decision" value="dismiss" style="font-size:11px; padding:4px 10px;">Dismiss</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($isSup ?? false): ?>
    <?php endif; ?>

    <?php if ($currentUserRole === 'Supervisor'): ?>
    <!-- v2.3: Supervisor quick actions -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
        <details class="card" style="padding:14px 16px;">
            <summary style="cursor:pointer; font-weight:700; font-size:14px;">&#128295; Report Machine Failure</summary>
            <form method="post" action="/production.php" style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
                <input type="hidden" name="action" value="report_failure">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div><label style="font-size:11px;">Machine *</label>
                    <select class="form-control" name="machine_id" required>
                        <?php foreach ($machines as $m): ?><option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['code']) ?> - <?= htmlspecialchars($m['name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div style="flex:1; min-width:180px;"><label style="font-size:11px;">Reason *</label><input class="form-control" name="failure_reason" required minlength="3" maxlength="255"></div>
                <button class="btn btn-danger" type="submit">Send to Manager</button>
            </form>
        </details>
        <details class="card" style="padding:14px 16px;" id="electricity">
            <summary style="cursor:pointer; font-weight:700; font-size:14px;">&#9889; Log Electricity Usage</summary>
            <form method="post" action="/production.php" style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
                <input type="hidden" name="action" value="log_electricity">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div><label style="font-size:11px;">Date *</label><input class="form-control" type="date" name="reading_date" required value="<?= date('Y-m-d') ?>"></div>
                <div><label style="font-size:11px;">Shift</label>
                    <select class="form-control" name="shift">
                        <option>Morning (06:00 - 14:00)</option><option>Afternoon (14:00 - 22:00)</option><option>Night (22:00 - 06:00)</option>
                    </select></div>
                <div><label style="font-size:11px;">kWh *</label><input class="form-control" type="number" step="0.01" min="0.01" name="meter_kwh" required style="width:100px;"></div>
                <div><label style="font-size:11px;">Units produced</label><input class="form-control" type="number" step="1" min="0" name="units_produced" value="0" style="width:110px;"></div>
                <button class="btn btn-primary" type="submit">Save</button>
            </form>
        </details>
    </div>
    <?php endif; ?>

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
                +<?= formatNumber($aggPartial) ?> reworkable &bull; cost of rejects: <strong><?= formatMoney($rejectMoney) ?></strong>
            </div>
        </div>
    </div>

    <!-- Manager tools: downtime log + shift targets (items 20, 21) -->
    <?php if (in_array($currentUserRole, ['Manager', 'CEO'], true) && !$showNew): ?>
    <div class="form-grid" style="margin-bottom:16px;">
        <div class="card" style="padding:14px 18px; margin:0;">
            <h4 style="font-size:13px; font-weight:800; margin:0 0 8px;">&#9203; Log Machine Downtime</h4>
            <form method="POST" action="/production.php" style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end;">
                <input type="hidden" name="action" value="log_downtime">
                <select name="machine_id" class="form-control" required style="width:130px; padding:5px 8px; font-size:12px;">
                    <?php foreach ($machines as $m): ?><option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['code']) ?></option><?php endforeach; ?>
                </select>
                <input type="date" name="report_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required class="form-control" style="width:135px; padding:5px 8px; font-size:12px;">
                <input type="time" name="started_at" required class="form-control" style="width:105px; padding:5px 8px; font-size:12px;">
                <input type="time" name="ended_at" class="form-control" style="width:105px; padding:5px 8px; font-size:12px;" title="Leave empty if still down">
                <input type="text" name="reason" placeholder="Reason (e.g. belt snapped)" required class="form-control" style="flex:1; min-width:160px; padding:5px 8px; font-size:12px;" maxlength="255">
                <button type="submit" class="btn btn-primary btn-sm">Log</button>
            </form>
        </div>
        <div class="card" style="padding:14px 18px; margin:0;">
            <h4 style="font-size:13px; font-weight:800; margin:0 0 8px;">&#127919; Set Shift Target</h4>
            <form method="POST" action="/production.php" style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end;">
                <input type="hidden" name="action" value="set_target">
                <select name="process_id" class="form-control" required style="width:170px; padding:5px 8px; font-size:12px;">
                    <?php foreach ($processes as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?><?= isset($targetsByProcess[(int)$p['id']]) ? ' (now ' . $targetsByProcess[(int)$p['id']] . ')' : '' ?></option><?php endforeach; ?>
                </select>
                <input type="number" name="target_per_shift" min="1" placeholder="Units per shift" required class="form-control" style="width:130px; padding:5px 8px; font-size:12px;">
                <input type="date" name="effective_from" value="<?= date('Y-m-d') ?>" class="form-control" style="width:135px; padding:5px 8px; font-size:12px;">
                <button type="submit" class="btn btn-primary btn-sm">Set</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

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

                    <div class="form-group">
                        <label for="batch_id">Material Batch in Use</label>
                        <select id="batch_id" name="batch_id" class="form-control">
                            <option value="0">Not tracked</option>
                            <?php foreach ($activeBatches as $b): ?>
                                <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['batch_code']) ?> &mdash; <?= htmlspecialchars($b['material_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="form-help">Links rejects back to the delivery they came from</span>
                    </div>

                    <div class="form-group">
                        <label for="reject_reason">Primary Defect / Reject Reason</label>
                        <select id="reject_reason" name="reject_reason" class="form-control">
                            <?php foreach (['No rejects', 'Burr formation', 'Dimensional out-of-spec', 'Surface oxidation', 'Cracked / split material', 'Machine misalignment', 'Wrong feedstock (bad batch)', 'Operator error', 'Power interruption', 'Other (see notes)'] as $rrOpt): ?>
                                <option value="<?= $rrOpt ?>" <?= $rrOpt === 'No rejects' ? 'selected' : '' ?>><?= $rrOpt ?></option>
                            <?php endforeach; ?>
                        </select>
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
                        <th>Material Batch</th>
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
                                </td>
                                <td>
                                    <?php if (!empty($r['batch_code'])): ?>
                                        <span class="mono badge badge-info"><?= htmlspecialchars($r['batch_code']) ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--text-subtle);">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($r['supervisor_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Machine downtime log table -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Machine Downtime Log</h3>
                <p class="card-subtitle">Stoppages recorded per machine &mdash; explains low output the same day</p>
            </div>
            <span class="badge badge-danger"><?= count($downtime) ?> entries</span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Date</th><th>Machine</th><th>Shift</th><th>From</th><th>To</th><th>Minutes</th><th>Reason</th><th>Recorded By</th></tr></thead>
                <tbody>
                    <?php if (empty($downtime)): ?>
                        <tr><td colspan="8" class="empty-state">No downtime recorded in this period.</td></tr>
                    <?php else: ?>
                        <?php $totalDown = 0; foreach ($downtime as $d): $totalDown += (int)$d['minutes']; ?>
                            <tr>
                                <td><?= formatDate($d['report_date']) ?></td>
                                <td><span class="mono" style="font-weight:700;"><?= htmlspecialchars($d['machine_code']) ?></span></td>
                                <td><?= htmlspecialchars((string)$d['shift']) ?></td>
                                <td class="mono"><?= htmlspecialchars($d['started_at']) ?></td>
                                <td class="mono"><?= htmlspecialchars((string)($d['ended_at'] ?? 'ongoing')) ?></td>
                                <td><strong><?= (int)$d['minutes'] ?></strong></td>
                                <td style="max-width:260px; font-size:12.5px;"><?= htmlspecialchars($d['reason']) ?></td>
                                <td><?= htmlspecialchars($d['recorded_by'] ? '' : '') ?><?= htmlspecialchars((string)($d['recorder_name'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background:var(--bg-surface-subtle); font-weight:700;"><td colspan="5">TOTAL DOWNTIME</td><td><?= $totalDown ?> min</td><td colspan="2"></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
