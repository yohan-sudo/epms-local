<?php
/**
 * U EPMS - Store Bridge (item 23)
 * Materials received into the store (from procurement or direct) and
 * issued to the floor. Makes "bought minus used minus in store" checkable.
 * Creating a receipt also registers a material batch that production
 * entries can reference.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/components/filter_bar.php';

requireRole(['CEO', 'Manager', 'Procurement Officer', 'Supervisor']); // v2.3.2: store operations are production-side; Accountant pays via cash requests only

$pageTitle = 'Materials Store';
$activeNav = 'stores';

$currentUserRole = $_SESSION['user_role'];
$currentUserId = (int)$_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];
$canWrite = in_array($currentUserRole, ['CEO', 'Manager', 'Procurement Officer'], true);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canWrite) {
    $act = (string)($_POST['store_action'] ?? '');

    if ($act === 'receive') {
        $errors = [];
        $material = field_text($errors, 'material_name', 'Material name', true, 2, 120) ?? '';
        $qty      = (float)($_POST['quantity'] ?? 0);
        $unit     = field_choice($errors, 'unit', 'Unit', ['Bags', 'Kg', 'Pieces', 'Rolls', 'Litres']) ?? 'Bags';
        $rDate    = field_date($errors, 'receipt_date', 'Receipt date', true, true) ?? date('Y-m-d');
        $procId   = field_int($errors, 'procurement_id', 'Linked purchase', 0) ?? 0;
        $note     = field_text($errors, 'note', 'Note', false, 0, 255) ?? '';
        if ($qty <= 0) {
            $errors[] = '• Quantity must be greater than zero.';
        }
        if ($errors) {
            redirectWithErrors('/stores.php', $errors);
        }

        $db->beginTransaction();
        try {
            $batchCode = 'MB-' . date('Ym') . '-' . str_pad((string)((int)$db->query('SELECT COUNT(*) FROM material_batches')->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
            $db->prepare("INSERT INTO material_batches (batch_code, procurement_id, material_name, quantity, unit, received_date, received_by) VALUES (:bc, :pid, :mn, :q, :u, :d, :by)")
               ->execute([':bc' => $batchCode, ':pid' => $procId ?: null, ':mn' => $material, ':q' => $qty, ':u' => $unit, ':d' => $rDate, ':by' => $currentUserId]);
            $batchId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO stock_receipts (batch_id, procurement_id, quantity, unit, receipt_date, received_by, note) VALUES (:b, :pid, :q, :u, :d, :by, :n)")
               ->execute([':b' => $batchId, ':pid' => $procId ?: null, ':q' => $qty, ':u' => $unit, ':d' => $rDate, ':by' => $currentUserId, ':n' => $note]);
            logAudit($db, 'STOCK_RECEIVED', 'STOCK', $batchCode, "{$currentUserName} received {$qty} {$unit} of {$material} into the store (batch {$batchCode})");
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Could not record the receipt: ' . $e->getMessage());
            commitSessionAndRedirect('/stores.php');
        }
        setFlash('success', "Batch {$batchCode} ({$qty} {$unit} of {$material}) received into the store.");
        commitSessionAndRedirect('/stores.php');
    }

    if ($act === 'issue') {
        $errors = [];
        $batchId = field_int($errors, 'batch_id', 'Material batch', 1) ?? 0;
        $qty     = (float)($_POST['quantity'] ?? 0);
        $iDate   = field_date($errors, 'issue_date', 'Issue date', true, true) ?? date('Y-m-d');
        $toProc  = field_int($errors, 'issued_to_process', 'Issued to process', 0) ?? 0;
        $note    = field_text($errors, 'note', 'Note', false, 0, 255) ?? '';
        if ($qty <= 0) {
            $errors[] = '• Quantity must be greater than zero.';
        }
        if ($errors) {
            redirectWithErrors('/stores.php', $errors);
        }

        $db->beginTransaction();
        try {
            // Lock the batch row so two simultaneous issues cannot overdraw
            $lockClause = (defined('DB_DRIVER') && DB_DRIVER === 'mysql') ? ' FOR UPDATE' : '';
            $bStmt = $db->prepare("SELECT * FROM material_batches WHERE id = :id{$lockClause}");
            $bStmt->execute([':id' => $batchId]);
            $batch = $bStmt->fetch();
            if (!$batch) {
                throw new RuntimeException('Batch not found.');
            }
            $st = $db->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock_issues WHERE batch_id = :b");
            $st->execute([':b' => $batchId]);
            $issued = (float)$st->fetchColumn();
            $remaining = (float)$batch['quantity'] - $issued;
            if ($qty > $remaining + 0.0001) {
                throw new RuntimeException("Only {$remaining} {$batch['unit']} remaining in batch {$batch['batch_code']}.");
            }
            $db->prepare("INSERT INTO stock_issues (batch_id, quantity, unit, issue_date, issued_to_process, issued_by, note) VALUES (:b, :q, :u, :d, :p, :by, :n)")
               ->execute([':b' => $batchId, ':q' => $qty, ':u' => $batch['unit'], ':d' => $iDate, ':p' => $toProc ?: null, ':by' => $currentUserId, ':n' => $note]);
            logAudit($db, 'STOCK_ISSUED', 'STOCK', $batch['batch_code'], "{$currentUserName} issued {$qty} {$batch['unit']} from batch {$batch['batch_code']} to process #{$toProc}");
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Could not issue material: ' . $e->getMessage());
            commitSessionAndRedirect('/stores.php');
        }
        setFlash('success', "Material issued to the floor.");
        commitSessionAndRedirect('/stores.php');
    }

    commitSessionAndRedirect('/stores.php');
}

// Overview: per batch received / issued / remaining
$stockRows = $db->query("
    SELECT mb.*,
           COALESCE((SELECT SUM(quantity) FROM stock_issues si WHERE si.batch_id = mb.id), 0) AS issued_qty,
           u.name AS received_by_name
    FROM material_batches mb
    LEFT JOIN users u ON mb.received_by = u.id
    ORDER BY mb.id DESC
    LIMIT 100
")->fetchAll();

$recentIssues = $db->query("
    SELECT si.*, mb.batch_code, mb.material_name, p.name AS process_name, u.name AS issued_by_name
    FROM stock_issues si
    JOIN material_batches mb ON si.batch_id = mb.id
    LEFT JOIN processes p ON si.issued_to_process = p.id
    LEFT JOIN users u ON si.issued_by = u.id
    ORDER BY si.id DESC
    LIMIT 25
")->fetchAll();

$openProcurements = $db->query("SELECT id, reference_no, item_name FROM procurement_entries WHERE status = 'Finalized' ORDER BY id DESC LIMIT 30")->fetchAll();
$allProcesses = $db->query("SELECT id, name FROM processes ORDER BY id ASC")->fetchAll();

$totalReceived = 0.0;
$totalIssued = 0.0;
foreach ($stockRows as $s) {
    $totalReceived += (float)$s['quantity'];
    $totalIssued += (float)$s['issued_qty'];
}

include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header">
        <h2 class="page-title">Materials Store</h2>
        <p class="page-subtitle">Bridges buying and production: what arrived, what was issued to the floor, what remains &mdash; gaps become visible</p>
    </div>

    <?php displayFlash(); ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header"><span class="stat-title">Received</span><span class="badge badge-primary"><?= count($stockRows) ?> batches</span></div>
            <div class="stat-value"><?= number_format($totalReceived, 1) ?></div>
            <div class="stat-desc">Total units received into the store</div>
        </div>
        <div class="stat-card">
            <div class="stat-header"><span class="stat-title">Issued to Floor</span><span class="badge badge-info"><?= count($recentIssues) ?> recent</span></div>
            <div class="stat-value"><?= number_format($totalIssued, 1) ?></div>
            <div class="stat-desc">Total units issued to production</div>
        </div>
        <div class="stat-card">
            <div class="stat-header"><span class="stat-title">In Store</span><span class="badge badge-success">On Hand</span></div>
            <div class="stat-value" style="color:var(--success);"><?= number_format(max(0, $totalReceived - $totalIssued), 1) ?></div>
            <div class="stat-desc">Received &minus; issued across all batches</div>
        </div>
    </div>

    <?php if ($canWrite): ?>
    <div class="form-grid" style="margin-bottom:16px;">
        <div class="card" style="padding:14px 18px; margin:0;">
            <h4 style="font-size:13px; font-weight:800; margin:0 0 8px;">&#128230; Receive Materials</h4>
            <form method="POST" action="/stores.php">
                <input type="hidden" name="store_action" value="receive">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Material *</label>
                        <input type="text" name="material_name" required class="form-control" placeholder="e.g. Sisal fibre, PVC granules" maxlength="120">
                    </div>
                    <div class="form-group">
                        <label>Quantity *</label>
                        <input type="number" name="quantity" step="0.01" min="0.01" required class="form-control" placeholder="e.g. 25">
                    </div>
                    <div class="form-group">
                        <label>Unit *</label>
                        <select name="unit" class="form-control"><option>Bags</option><option>Kg</option><option>Pieces</option><option>Rolls</option><option>Litres</option></select>
                    </div>
                    <div class="form-group">
                        <label>Linked purchase (optional)</label>
                        <select name="procurement_id" class="form-control">
                            <option value="0">None</option>
                            <?php foreach ($openProcurements as $op): ?><option value="<?= (int)$op['id'] ?>"><?= htmlspecialchars($op['reference_no']) ?> &mdash; <?= htmlspecialchars($op['item_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date *</label>
                        <input type="date" name="receipt_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required class="form-control">
                    </div>
                    <div class="form-group" style="display:flex; align-items:flex-end;">
                        <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">Receive &rarr;</button>
                    </div>
                </div>
            </form>
        </div>
        <div class="card" style="padding:14px 18px; margin:0;">
            <h4 style="font-size:13px; font-weight:800; margin:0 0 8px;">&#128666; Issue to Floor</h4>
            <form method="POST" action="/stores.php">
                <input type="hidden" name="store_action" value="issue">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Batch *</label>
                        <select name="batch_id" required class="form-control">
                            <?php foreach ($stockRows as $s): $rem = (float)$s['quantity'] - (float)$s['issued_qty']; if ($rem > 0): ?>
                                <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['batch_code']) ?> &mdash; <?= htmlspecialchars($s['material_name']) ?> (<?= number_format($rem, 1) ?> left)</option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Quantity *</label>
                        <input type="number" name="quantity" step="0.01" min="0.01" required class="form-control">
                    </div>
                    <div class="form-group">
                        <label>To process</label>
                        <select name="issued_to_process" class="form-control">
                            <option value="0">General</option>
                            <?php foreach ($allProcesses as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date *</label>
                        <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required class="form-control">
                    </div>
                    <div class="form-group" style="display:flex; align-items:flex-end;">
                        <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">Issue &rarr;</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Batch Ledger</h3>
                <p class="card-subtitle">Received vs issued vs remaining per material batch</p>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Batch</th><th>Material</th><th>Received</th><th>Issued</th><th>Remaining</th><th>Use %</th><th>Received By</th><th>Date</th></tr></thead>
                <tbody>
                    <?php if (empty($stockRows)): ?>
                        <tr><td colspan="8" class="empty-state">No material batches yet - record the first receipt above.</td></tr>
                    <?php else: ?>
                        <?php foreach ($stockRows as $s):
                            $rem = (float)$s['quantity'] - (float)$s['issued_qty'];
                            $pct = (float)$s['quantity'] > 0 ? round(((float)$s['issued_qty'] / (float)$s['quantity']) * 100) : 0;
                        ?>
                            <tr>
                                <td class="mono"><strong><?= htmlspecialchars($s['batch_code']) ?></strong></td>
                                <td><?= htmlspecialchars($s['material_name']) ?></td>
                                <td><?= number_format((float)$s['quantity'], 1) ?> <?= htmlspecialchars($s['unit']) ?></td>
                                <td><?= number_format((float)$s['issued_qty'], 1) ?> <?= htmlspecialchars($s['unit']) ?></td>
                                <td><strong style="color: <?= $rem > 0 ? 'var(--success)' : 'var(--danger)' ?>;"><?= number_format($rem, 1) ?> <?= htmlspecialchars($s['unit']) ?></strong></td>
                                <td>
                                    <div style="height:5px; width:70px; background:var(--border-color); border-radius:3px; overflow:hidden;">
                                        <div style="height:100%; width:<?= min(100, $pct) ?>%; background:<?= $pct >= 100 ? 'var(--danger)' : 'var(--primary)' ?>;"></div>
                                    </div>
                                    <span style="font-size:11px; color:var(--text-muted);"><?= $pct ?>%</span>
                                </td>
                                <td><?= htmlspecialchars($s['received_by_name'] ?? 'Unknown') ?></td>
                                <td><?= formatDate($s['received_date']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Recent Issues to the Floor</h3>
                <p class="card-subtitle">Latest material movements into production</p>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Date</th><th>Batch</th><th>Material</th><th>Quantity</th><th>To Process</th><th>Issued By</th></tr></thead>
                <tbody>
                    <?php if (empty($recentIssues)): ?>
                        <tr><td colspan="6" class="empty-state">Nothing issued yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentIssues as $i): ?>
                            <tr>
                                <td><?= formatDate($i['issue_date']) ?></td>
                                <td class="mono"><?= htmlspecialchars($i['batch_code']) ?></td>
                                <td><?= htmlspecialchars($i['material_name']) ?></td>
                                <td><strong><?= number_format((float)$i['quantity'], 1) ?> <?= htmlspecialchars($i['unit']) ?></strong></td>
                                <td><?= htmlspecialchars($i['process_name'] ?? 'General') ?></td>
                                <td><?= htmlspecialchars($i['issued_by_name'] ?? 'Unknown') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
