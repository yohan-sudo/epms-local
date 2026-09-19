<?php
/**
 * U EPMS - Correction Workflow (v2.3)
 * Human errors happen. When a record was logged by mistake:
 *   1. The user SELECTS what is wrong (entity + record + edit/delete + reason)
 *   2. The C.E.O reviews and approves (or rejects) the correction
 *   3. Only then can the user change/delete EXACTLY what was approved - nothing else
 * Every step is audited; applied corrections re-audit with before/after values.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole(['CEO', 'Manager', 'Accountant', 'Procurement Officer', 'Supervisor', 'Assistant Manager']);

$pageTitle = 'Correction Requests';
$activeNav = 'corrections';

$currentUserRole = $_SESSION['user_role'];
$currentUserId   = (int)$_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];
$isCEO = $currentUserRole === 'CEO';

/**
 * Corrigible entities registry: table, label, and the ONLY fields an
 * approved "edit" may touch. Deletes remove the whole row.
 */
$ENTITIES = [
    'daily_report' => ['table' => 'daily_reports', 'label' => 'Production shift report',
        'fields' => ['units_produced' => 'Units processed', 'good_units' => 'Accepted (good) units', 'supervisor_notes' => 'Notes']],
    'electricity_reading' => ['table' => 'electricity_readings', 'label' => 'Electricity reading',
        'fields' => ['meter_kwh' => 'Meter (kWh)', 'units_produced' => 'Units produced', 'notes' => 'Notes']],
    'machine_downtime' => ['table' => 'machine_downtime', 'label' => 'Machine downtime entry',
        'fields' => ['minutes' => 'Downtime minutes', 'reason' => 'Reason']],
    'procurement_entry' => ['table' => 'procurement_entries', 'label' => 'Procurement record',
        'fields' => ['quantity' => 'Quantity', 'unit_cost' => 'Unit price', 'supplier' => 'Supplier']],
    'petty_cash_expense' => ['table' => 'petty_cash_expenses', 'label' => 'Petty cash expense',
        'fields' => ['amount' => 'Amount', 'description' => 'Description']],
];

$entityLabel = function (string $type, string $id) use ($db): string {
    return match ($type) {
        'daily_report' => 'Shift report #' . $id,
        'electricity_reading' => 'Electricity reading #' . $id,
        'machine_downtime' => 'Downtime entry #' . $id,
        'procurement_entry' => 'Procurement #' . $id,
        'petty_cash_expense' => 'Expense #' . $id,
        default => $type . ' #' . $id,
    };
};

/* ==================================================================
 * POST actions
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Any user requests a correction
    if ($action === 'request_correction') {
        $errors = [];
        $entityType = (string)($_POST['entity_type'] ?? '');
        $entityId   = (int)($_POST['entity_id'] ?? 0);
        $corrType   = ($_POST['correction_type'] ?? '') === 'delete' ? 'delete' : 'edit';
        $reason     = field_text($errors, 'reason', 'Reason', true, 10, 500) ?? '';
        if (!isset($ENTITIES[$entityType])) {
            $errors[] = '• Choose what you want to correct.';
        }
        if ($entityId <= 0) {
            $errors[] = '• Enter the record number you want to correct.';
        }
        if ($errors) {
            redirectWithErrors('/corrections.php?action=new', $errors);
        }
        $meta = $ENTITIES[$entityType];
        $row = $db->query("SELECT * FROM {$meta['table']} WHERE id = " . $entityId)->fetch();
        if (!$row) {
            redirectWithErrors('/corrections.php?action=new', ['• That record was not found.']);
        }
        $stmt = $db->prepare("INSERT INTO correction_requests (entity_type, entity_id, correction_type, reason, requested_by, status) VALUES (:t, :i, :c, :r, :by, 'Pending CEO Approval')");
        $stmt->execute([':t' => $entityType, ':i' => $entityId, ':c' => $corrType, ':r' => $reason, ':by' => $currentUserId]);
        $reqId = (int)$db->lastInsertId();
        logAudit($db, 'CORRECTION_REQUESTED', 'CORRECTION', $reqId, "{$currentUserName} requested to " . strtoupper($corrType) . " {$entityLabel($entityType, (string)$entityId)}: {$reason}");
        notifyRoles($db, ['CEO'], 'Correction awaiting your approval', "{$currentUserName} wants to " . ($corrType === 'delete' ? 'DELETE' : 'EDIT') . " {$entityLabel($entityType, (string)$entityId)}. Reason: {$reason}", '/corrections.php');
        setFlash('success', 'Correction request sent to the C.E.O. You will be able to apply it once approved.');
        header('Location: /corrections.php');
        exit;
    }

    // 2. CEO decision
    if ($action === 'ceo_decision') {
        if (!$isCEO) {
            setFlash('error', 'Only the C.E.O approves corrections.');
            header('Location: /corrections.php');
            exit;
        }
        $id = (int)($_POST['request_id'] ?? 0);
        $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approve' : 'reject';
        $notes = mb_substr(trim((string)($_POST['ceo_notes'] ?? '')), 0, 255);
        $req = $db->query('SELECT * FROM correction_requests WHERE id = ' . $id)->fetch();
        if (!$req || $req['status'] !== 'Pending CEO Approval') {
            setFlash('error', 'That correction is not awaiting your approval.');
            header('Location: /corrections.php');
            exit;
        }
        $newStatus = $decision === 'approve' ? 'Approved - Awaiting Application' : 'Rejected';
        $db->prepare("UPDATE correction_requests SET status = :s, decided_by = :by, decided_at = CURRENT_TIMESTAMP, ceo_notes = :n WHERE id = :id")
           ->execute([':s' => $newStatus, ':by' => $currentUserId, ':n' => $notes, ':id' => $id]);
        logAudit($db, $decision === 'approve' ? 'CORRECTION_APPROVED' : 'CORRECTION_REJECTED', 'CORRECTION', $id,
            "C.E.O {$currentUserName} " . ($decision === 'approve' ? 'APPROVED' : 'REJECTED') . " the request to " . strtoupper($req['correction_type']) . " {$entityLabel($req['entity_type'], $req['entity_id'])} by user #{$req['requested_by']}." . ($notes !== '' ? " Note: {$notes}" : ''));
        notifyUser($db, (int)$req['requested_by'], 'Correction ' . ($decision === 'approve' ? 'approved' : 'rejected'),
            $decision === 'approve'
                ? "Your correction to {$entityLabel($req['entity_type'], $req['entity_id'])} was approved. Go to Corrections to apply it - you may only change what you selected."
                : "Your correction to {$entityLabel($req['entity_type'], $req['entity_id'])} was rejected" . ($notes !== '' ? ": {$notes}" : '.'),
            '/corrections.php');
        setFlash('success', 'Decision recorded.');
        header('Location: /corrections.php');
        exit;
    }

    // 3. Requester applies the approved correction (scoped)
    if ($action === 'apply_correction') {
        $id = (int)($_POST['request_id'] ?? 0);
        $req = $db->query('SELECT * FROM correction_requests WHERE id = ' . $id)->fetch();
        if (!$req || $req['status'] !== 'Approved - Awaiting Application' || (int)$req['requested_by'] !== $currentUserId) {
            setFlash('error', 'That correction is not yours to apply, or it is not approved yet.');
            header('Location: /corrections.php');
            exit;
        }
        $meta = $ENTITIES[$req['entity_type']] ?? null;
        if (!$meta) {
            setFlash('error', 'Unknown entity type.');
            header('Location: /corrections.php');
            exit;
        }
        $table = $meta['table'];
        $row = $db->query("SELECT * FROM {$table} WHERE id = " . (int)$req['entity_id'])->fetch();
        if (!$row) {
            $db->prepare("UPDATE correction_requests SET status = 'Record No Longer Exists', applied_by = :by, applied_at = CURRENT_TIMESTAMP WHERE id = :id")
               ->execute([':by' => $currentUserId, ':id' => $id]);
            setFlash('warning', 'The record no longer exists; the request was closed.');
            header('Location: /corrections.php');
            exit;
        }

        if ($req['correction_type'] === 'delete') {
            $snapshot = json_encode($row, JSON_UNESCAPED_UNICODE);
            $db->prepare("DELETE FROM {$table} WHERE id = :id")->execute([':id' => (int)$req['entity_id']]);
            $db->prepare("UPDATE correction_requests SET status = 'Applied', applied_by = :by, applied_at = CURRENT_TIMESTAMP WHERE id = :id")
               ->execute([':by' => $currentUserId, ':id' => $id]);
            logAudit($db, 'CORRECTION_APPLIED_DELETE', 'CORRECTION', $id, "{$currentUserName} DELETED {$entityLabel($req['entity_type'], $req['entity_id'])} under approved correction #{$id}. Snapshot: " . substr((string)$snapshot, 0, 380));
            setFlash('success', 'The record was deleted as approved.');
        } else {
            // Edit: ONLY the whitelisted fields, ONLY those with a submitted value
            $sets = [];
            $args = [':id' => (int)$req['entity_id']];
            $changes = [];
            foreach ($meta['fields'] as $col => $label) {
                $key = 'field_' . $col;
                if (isset($_POST[$key]) && (string)$_POST[$key] !== '' && (string)$_POST[$key] !== (string)$row[$col]) {
                    $sets[] = "`{$col}` = :f_{$col}";
                    $args[":f_{$col}"] = $_POST[$key];
                    $changes[] = "{$label}: '" . $row[$col] . "' -> '" . $_POST[$key] . "'";
                }
            }
            if (!$changes) {
                setFlash('info', 'No changes were submitted.');
                header('Location: /corrections.php');
                exit;
            }
            $db->prepare("UPDATE {$table} SET " . implode(', ', $sets) . " WHERE id = :id")->execute($args);
            $db->prepare("UPDATE correction_requests SET status = 'Applied', applied_by = :by, applied_at = CURRENT_TIMESTAMP WHERE id = :id")
               ->execute([':by' => $currentUserId, ':id' => $id]);
            logAudit($db, 'CORRECTION_APPLIED_EDIT', 'CORRECTION', $id, "{$currentUserName} EDITED {$entityLabel($req['entity_type'], $req['entity_id'])} under approved correction #{$id}. " . implode('; ', $changes));
            setFlash('success', 'Correction applied: ' . implode('; ', $changes));
        }
        header('Location: /corrections.php');
        exit;
    }

    setFlash('error', 'Unknown correction action.');
    header('Location: /corrections.php');
    exit;
}

/* ==================================================================
 * Data
 * ================================================================== */
$requests = $db->query(
    'SELECT c.*, ru.name AS requester_name, du.name AS decider_name, au.name AS applier_name
     FROM correction_requests c
     JOIN users ru ON ru.id = c.requested_by
     LEFT JOIN users du ON du.id = c.decided_by
     LEFT JOIN users au ON au.id = c.applied_by
     ORDER BY c.id DESC LIMIT 40'
)->fetchAll();

$myApproved = $db->query(
    "SELECT * FROM correction_requests WHERE requested_by = {$currentUserId} AND status = 'Approved - Awaiting Application' ORDER BY id DESC"
)->fetchAll();

$badge = function (string $s): string {
    return match ($s) {
        'Applied' => '<span class="badge badge-success">Applied</span>',
        'Rejected' => '<span class="badge badge-danger">Rejected</span>',
        'Approved - Awaiting Application' => '<span class="badge badge-info">Approved - apply now</span>',
        'Record No Longer Exists' => '<span class="badge badge-secondary">Closed</span>',
        default => '<span class="badge badge-warning">Awaiting C.E.O</span>',
    };
};

$prefillType = $_GET['entity'] ?? '';
$prefillId   = $_GET['id'] ?? '';
?>
<?php include __DIR__ . '/components/header.php'; ?>
<main class="page-container">
    <?php displayFlash(); ?>

    <div style="margin-bottom:18px;">
        <h1 style="font-size:24px; font-weight:800;">Correction Requests</h1>
        <p style="color:var(--text-secondary, #64748b); font-size:13px;">
            Logged something by mistake? Select it, explain why, and the C.E.O will approve. You may then change <em>only</em> what you selected.
        </p>
    </div>

    <?php foreach ($myApproved as $ma): $meta = $ENTITIES[$ma['entity_type']] ?? null; ?>
        <?php if ($meta): $row = $db->query("SELECT * FROM {$meta['table']} WHERE id = " . (int)$ma['entity_id'])->fetch(); ?>
        <div class="card" style="border:2px solid #3b82f6; padding:16px; margin-bottom:14px;">
            <h3 style="font-size:14px; font-weight:800; margin-bottom:4px;">
                &#9989; Approved: <?= $ma['correction_type'] === 'delete' ? 'DELETE' : 'EDIT' ?> <?= htmlspecialchars($meta['label']) ?> #<?= (int)$ma['entity_id'] ?>
            </h3>
            <p style="font-size:12px; color:var(--text-secondary, #64748b); margin-bottom:10px;">You may only change what you selected. Everything else stays locked.</p>
            <?php if ($ma['correction_type'] === 'delete'): ?>
                <form method="post" action="/corrections.php" onsubmit="return confirm('Delete this record permanently? This cannot be undone.');">
                    <input type="hidden" name="action" value="apply_correction">
                    <input type="hidden" name="request_id" value="<?= (int)$ma['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <button class="btn btn-danger" type="submit">&#128465; Delete <?= htmlspecialchars($meta['label']) ?> #<?= (int)$ma['entity_id'] ?></button>
                </form>
            <?php elseif ($row): ?>
                <form method="post" action="/corrections.php">
                    <input type="hidden" name="action" value="apply_correction">
                    <input type="hidden" name="request_id" value="<?= (int)$ma['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px;">
                        <?php foreach ($meta['fields'] as $col => $label): ?>
                            <div><label style="font-size:11px;"><?= htmlspecialchars($label) ?></label>
                                <input class="form-control" name="field_<?= htmlspecialchars($col) ?>" value="<?= htmlspecialchars((string)$row[$col]) ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-primary" type="submit" style="margin-top:10px;">Apply approved changes</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <details class="card" style="padding:16px; margin-bottom:18px;" <?= ($prefillType !== '' || !$requests) ? 'open' : '' ?>>
        <summary style="cursor:pointer; font-weight:700; font-size:15px;">+ Request a Correction</summary>
        <form method="post" action="/corrections.php" style="margin-top:12px;">
            <input type="hidden" name="action" value="request_correction">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px;">
                <div><label style="font-size:11px;">What is wrong? *</label>
                    <select class="form-control" name="entity_type" required>
                        <option value="">- select -</option>
                        <?php foreach ($ENTITIES as $key => $meta): ?>
                            <option value="<?= $key ?>" <?= $prefillType === $key ? 'selected' : '' ?>><?= htmlspecialchars($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div><label style="font-size:11px;">Record number *</label>
                    <input class="form-control" type="number" min="1" name="entity_id" required value="<?= htmlspecialchars((string)$prefillId) ?>"></div>
                <div><label style="font-size:11px;">Correction *</label>
                    <select class="form-control" name="correction_type" required>
                        <option value="edit">Edit the record</option>
                        <option value="delete">Delete the record</option>
                    </select></div>
            </div>
            <div style="margin-top:10px;"><label style="font-size:11px;">Why is this needed? *</label>
                <textarea class="form-control" name="reason" required minlength="10" maxlength="500" rows="2" placeholder="Explain the mistake - the C.E.O reads this before approving."></textarea></div>
            <button class="btn btn-primary" type="submit" style="margin-top:10px;">Send to C.E.O &rarr;</button>
        </form>
    </details>

    <div class="card" style="padding:16px;">
        <h3 class="card-title" style="font-size:15px; font-weight:700; margin-bottom:10px;">All Correction Requests</h3>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead><tr>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">#</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Target</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Type</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Reason</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">By</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Status</th>
                    <?php if ($isCEO): ?><th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Decision</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php if (!$requests): ?>
                    <tr><td colspan="7" style="padding:14px; color:var(--text-secondary, #64748b);">No correction requests yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0); font-weight:600;"><?= (int)$r['id'] ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars($entityLabel($r['entity_type'], $r['entity_id'])) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= $r['correction_type'] === 'delete' ? '🗑 Delete' : '✏️ Edit' ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0); max-width:260px;"><?= htmlspecialchars($r['reason']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars($r['requester_name']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= $badge($r['status']) ?></td>
                        <?php if ($isCEO): ?>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);">
                            <?php if ($r['status'] === 'Pending CEO Approval'): ?>
                                <form method="post" action="/corrections.php" style="display:inline-flex; gap:4px;">
                                    <input type="hidden" name="action" value="ceo_decision">
                                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <button class="btn btn-success" name="decision" value="approve" style="font-size:11px; padding:4px 10px;">Approve</button>
                                    <button class="btn btn-danger" name="decision" value="reject" style="font-size:11px; padding:4px 10px;">Reject</button>
                                </form>
                            <?php else: ?>
                                <span style="color:var(--text-secondary, #94a3b8);">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php include __DIR__ . '/components/footer.php'; ?>
