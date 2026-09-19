<?php
/**
 * U EPMS - Floor Shift Entry (items 27, 36)
 * One big-button screen for the shop floor: pick process + machine, punch
 * the numbers, see live target progress. Works on any phone/tablet on the
 * office network. This is a simplified front for the same shift-report
 * pipeline as production.php (same validations, same classification).
 * Also serves as the wall display (read-only mode) with ?display=1.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole(['CEO', 'Manager', 'Supervisor']); // v2.3.2: floor is production-side; Accountant/PO have no floor duties

$wallMode = isset($_GET['display']);
$currentUserId = (int)$_SESSION['user_id'];
$currentUserName = (string)$_SESSION['user_name'];
$today = date('Y-m-d');

// Wall display is read-only; entry actions need Manager/CEO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$wallMode) {
    if (!in_array($_SESSION['user_role'] ?? '', ['Manager', 'CEO'], true)) {
        setFlash('error', 'Only the Manager or CEO can file shift entries.');
        commitSessionAndRedirect('/floor.php');
    }
    $errors = [];
    $processId = field_int($errors, 'process_id', 'Process', 1) ?? 0;
    $machineId = field_int($errors, 'machine_id', 'Machine', 1) ?? 0;
    $units     = field_int($errors, 'units_processed', 'Units processed', 1) ?? 0;
    $partial   = field_int($errors, 'partial_reject_count', 'Partial rejects', 0) ?? 0;
    $scrap     = field_int($errors, 'total_reject_count', 'Scrap', 0) ?? 0;
    $shift     = date('H') < 14 ? 'Morning (06:00 - 14:00)' : (date('H') < 22 ? 'Afternoon (14:00 - 22:00)' : 'Night (22:00 - 06:00)');
    if ($partial + $scrap > $units) {
        $errors[] = '• Rejects cannot exceed units processed.';
    }
    if ($errors) {
        setFlash('error', implode(' ', $errors));
        commitSessionAndRedirect('/floor.php');
    }
    $good = max(0, $units - $partial - $scrap);
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO daily_reports (report_date, shift, supervisor_id, machine_id, units_produced, good_units, supervisor_notes, created_at) VALUES (:d, :s, :sup, :m, :u, :g, :n, CURRENT_TIMESTAMP)")
           ->execute([':d' => $today, ':s' => $shift, ':sup' => $currentUserId, ':m' => $machineId, ':u' => $units, ':g' => $good, ':n' => 'Floor entry']);
        $reportId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO process_reject_logs (report_id, process_id, partial_reject_count, total_reject_count, reject_reason, root_cause) VALUES (:r, :p, :pr, :sc, :rr, '')")
           ->execute([':r' => $reportId, ':p' => $processId, ':pr' => $partial, ':sc' => $scrap, ':rr' => $scrap + $partial > 0 ? 'Other (see notes)' : 'No rejects']);
        $unitStatus = $processId === 5 ? 'Completed goods (counted at Cups)' : 'In-process';
        logAudit($db, 'PRODUCTION_REPORT_SUBMITTED', 'PRODUCTION_REPORT', $reportId, "Floor entry by {$currentUserName}: {$units} units ({$unitStatus}), accepted {$good}, scrap {$scrap}, rework {$partial}.");
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Could not save the entry.');
        commitSessionAndRedirect('/floor.php');
    }
    setFlash('success', "Saved: {$units} units on record.");
    commitSessionAndRedirect('/floor.php');
}

// Today's board: per process - target, produced, accepted, rejects
$boardStmt = $db->prepare("
    SELECT p.id AS process_id, p.name AS process_name,
           COALESCE(t.target_per_shift, 0) AS target,
           COALESCE(SUM(r.units_produced), 0) AS produced,
           COALESCE(SUM(r.good_units), 0) AS accepted
    FROM processes p
    LEFT JOIN shift_targets t ON t.process_id = p.id
        AND t.effective_from <= :today
        AND t.id = (SELECT MAX(t2.id) FROM shift_targets t2 WHERE t2.process_id = p.id AND t2.effective_from <= :today2)
    LEFT JOIN daily_reports r ON r.report_date = :today3
    WHERE p.status = 'Active'
    GROUP BY p.id, p.name, t.target_per_shift
    ORDER BY p.id ASC
");
$boardStmt->execute([':today' => $today, ':today2' => $today, ':today3' => $today]);
$board = $boardStmt->fetchAll();

// Per-process reject split
$rejectsToday = [];
foreach ($db->query("SELECT l.process_id, COALESCE(SUM(l.partial_reject_count),0) AS pr, COALESCE(SUM(l.total_reject_count),0) AS sc
                     FROM process_reject_logs l JOIN daily_reports r ON l.report_id = r.id
                     WHERE r.report_date = '{$today}' GROUP BY l.process_id")->fetchAll() as $rr) {
    $rejectsToday[(int)$rr['process_id']] = [(int)$rr['pr'], (int)$rr['sc']];
}

$processes = $db->query("SELECT id, name FROM processes WHERE status='Active' ORDER BY id ASC")->fetchAll();
$machines = $db->query("SELECT m.id, m.code, m.name, m.process_id FROM machines m ORDER BY m.code ASC")->fetchAll();
$rejectCostByProcess = [];
foreach ($db->query('SELECT id, reject_cost_per_unit FROM processes')->fetchAll() as $pc) {
    $rejectCostByProcess[(int)$pc['id']] = (float)$pc['reject_cost_per_unit'];
}

$pageTitle = $wallMode ? 'Factory Floor - Live' : 'Floor Entry';
$activeNav = '';
if ($wallMode) {
    // Minimal chrome for a wall monitor
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta http-equiv="refresh" content="60">';
    echo '<title>Factory Floor - Live</title><link rel="stylesheet" href="/assets/css/style.css"></head><body style="background:#0f172a; color:#e2e8f0; padding:24px;">';
    echo '<h1 style="font-size:32px; margin:0 0 6px;">&#127981; Today on the Floor &mdash; ' . date('D d M Y') . '</h1>';
    echo '<p style="color:#94a3b8; margin:0 0 20px;">Auto-refreshes every minute</p>';
    echo '<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:16px;">';
    foreach ($board as $b) {
        $pr = (int)$b['produced'];
        $tg = (int)$b['target'];
        $pct = $tg > 0 ? min(100, round($pr / $tg * 100)) : 0;
        [$prj, $scj] = $rejectsToday[(int)$b['process_id']] ?? [0, 0];
        $color = $tg === 0 ? '#38bdf8' : ($pct >= 100 ? '#22c55e' : ($pct >= 70 ? '#f59e0b' : '#ef4444'));
        echo '<div style="background:#1e293b; border-radius:14px; padding:18px;">';
        echo '<div style="font-size:18px; font-weight:800; margin-bottom:4px;">' . htmlspecialchars($b['process_name']) . '</div>';
        echo '<div style="font-size:44px; font-weight:900; color:' . $color . ';">' . number_format($pr) . '</div>';
        if ($tg > 0) {
            echo '<div style="color:#94a3b8; font-size:14px; margin-bottom:8px;">of ' . number_format($tg) . ' target (' . $pct . '%)</div>';
            echo '<div style="height:10px; background:#334155; border-radius:5px; overflow:hidden;"><div style="height:100%; width:' . $pct . '%; background:' . $color . ';"></div></div>';
        } else {
            echo '<div style="color:#94a3b8; font-size:14px; margin-bottom:8px;">no target set</div>';
        }
        echo '<div style="margin-top:8px; font-size:13px; color:#94a3b8;">&#10003; ' . number_format((int)$b['accepted']) . ' accepted &bull; &#10007; ' . number_format($scj) . ' scrap &bull; &#8635; ' . number_format($prj) . ' rework</div>';
        $cost = $rejectCostByProcess[(int)$b['process_id']] ?? 0;
        if ($cost > 0 && ($scj + $prj) > 0) {
            echo '<div style="margin-top:4px; font-size:13px; color:#fca5a5;">Reject cost: ' . APP_CURRENCY . ' ' . number_format($cost * ($scj + $prj)) . '</div>';
        }
        echo '</div>';
    }
    echo '</div></body></html>';
    exit;
}

include __DIR__ . '/components/header.php';
?>

<main class="page-container" style="max-width: 900px;">
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h2 class="page-title">Floor Shift Entry</h2>
            <p class="page-subtitle">Big buttons for the shop floor &mdash; pick a machine, punch the numbers, done in under a minute</p>
        </div>
        <a href="/floor.php?display=1" target="_blank" class="btn btn-secondary btn-sm">&#128250; Open Wall Display</a>
    </div>

    <?php displayFlash(); ?>

    <?php if (in_array($currentUserRole, ['Manager', 'CEO'], true)): ?>
    <div class="card" style="margin-bottom:16px;">
        <form method="POST" action="/floor.php">
            <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                <div class="form-group">
                    <label style="font-size:14px;">Process *</label>
                    <select id="f-process" name="process_id" required class="form-control" style="font-size:22px; padding:12px; height:auto;">
                        <?php foreach ($processes as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-size:14px;">Machine *</label>
                    <select id="f-machine" name="machine_id" required class="form-control" style="font-size:22px; padding:12px; height:auto;">
                        <?php foreach ($machines as $m): ?><option value="<?= (int)$m['id'] ?>" data-proc="<?= (int)$m['process_id'] ?>"><?= htmlspecialchars($m['code']) ?> &mdash; <?= htmlspecialchars($m['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-size:14px;">Units processed *</label>
                    <input type="number" name="units_processed" id="f-units" inputmode="numeric" min="1" required class="form-control" style="font-size:28px; padding:12px; height:auto; font-weight:800;" placeholder="0">
                </div>
                <div class="form-group" style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div>
                        <label style="font-size:14px;">Rework</label>
                        <input type="number" name="partial_reject_count" inputmode="numeric" min="0" value="0" class="form-control" style="font-size:22px; padding:12px; height:auto;">
                    </div>
                    <div>
                        <label style="font-size:14px;">Scrap</label>
                        <input type="number" name="total_reject_count" inputmode="numeric" min="0" value="0" class="form-control" style="font-size:22px; padding:12px; height:auto;">
                    </div>
                </div>
                <div class="form-group" style="grid-column:1 / -1;">
                    <button type="submit" class="btn btn-primary" style="width:100%; font-size:20px; padding:14px; font-weight:800;">&#10003; SAVE SHIFT ENTRY</button>
                </div>
            </div>
        </form>
    </div>
    <script>
    (function(){
        var machine = document.getElementById('f-machine');
        var process = document.getElementById('f-process');
        function sync(){ if(!machine||!process) return; var opt = machine.selectedOptions[0]; if(opt&&opt.dataset.proc){ process.value = opt.dataset.proc; } }
        machine.addEventListener('change', sync); sync();
    })();
    </script>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><div><h3 class="card-title">Today's Board</h3><p class="card-subtitle">Live progress against the shift targets</p></div></div>
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:14px; padding: 0 0 14px;">
            <?php foreach ($board as $b):
                $pr = (int)$b['produced']; $tg = (int)$b['target'];
                $pct = $tg > 0 ? min(100, round($pr / $tg * 100)) : 0;
                [$prj, $scj] = $rejectsToday[(int)$b['process_id']] ?? [0, 0];
                $color = $tg === 0 ? 'var(--primary)' : ($pct >= 100 ? 'var(--success)' : ($pct >= 70 ? 'var(--warning, #d97706)' : 'var(--danger)'));
            ?>
                <div style="border:1px solid var(--border-color); border-radius:12px; padding:14px;">
                    <div style="font-weight:800; margin-bottom:2px;"><?= htmlspecialchars($b['process_name']) ?></div>
                    <div style="font-size:32px; font-weight:900; color:<?= $color ?>;"><?= number_format($pr) ?></div>
                    <?php if ($tg > 0): ?>
                        <div style="font-size:12px; color:var(--text-muted); margin-bottom:6px;">of <?= number_format($tg) ?> target (<?= $pct ?>%)</div>
                        <div style="height:8px; background:var(--border-color); border-radius:4px; overflow:hidden;"><div style="height:100%; width:<?= $pct ?>%; background:<?= $color ?>;"></div></div>
                    <?php else: ?>
                        <div style="font-size:12px; color:var(--text-muted);">no target set yet</div>
                    <?php endif; ?>
                    <div style="margin-top:6px; font-size:12px; color:var(--text-muted);">&#10003; <?= number_format((int)$b['accepted']) ?> accepted &bull; &#10007; <?= $scj ?> scrap &bull; &#8635; <?= $prj ?> rework</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
