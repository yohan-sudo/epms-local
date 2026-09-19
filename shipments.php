<?php
/**
 * U EPMS - Shipments (v2.3)
 * Finished-products flow, per client workflow:
 *   1. C.E.O requests a shipment of finished products from the factory
 *   2. Procurement Officer prepares it (bundles/units + destination + product)
 *   3. Manager approves the shipment info -> dispatched
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole(['CEO', 'Manager', 'Procurement Officer']);

$pageTitle = 'Product Shipments';
$activeNav = 'shipments';

$currentUserRole = $_SESSION['user_role'];
$currentUserId   = (int)$_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

$isCEO = $currentUserRole === 'CEO';
$isPO  = $currentUserRole === 'Procurement Officer';
$isMgr = $currentUserRole === 'Manager';

/* ==================================================================
 * POST actions
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. CEO requests a shipment
    if ($action === 'request_shipment') {
        if (!$isCEO) {
            setFlash('error', 'Only the C.E.O can request a product shipment.');
            header('Location: /shipments.php');
            exit;
        }
        $errors = [];
        $destination = field_text($errors, 'destination', 'Destination', true, 2, 150) ?? '';
        $note        = field_text($errors, 'note', 'Note', false, 0, 255) ?? '';
        if ($errors) {
            redirectWithErrors('/shipments.php', $errors);
        }
        $no = 'SHP-' . date('Y') . '-' . str_pad((string)(((int)$db->query('SELECT COUNT(*) FROM shipment_orders')->fetchColumn()) + 1), 4, '0', STR_PAD_LEFT);
        $stmt = $db->prepare("INSERT INTO shipment_orders (shipment_no, destination, status, requested_by_ceo) VALUES (:no, :d, 'Requested by CEO', :by)");
        $stmt->execute([':no' => $no, ':d' => $destination, ':by' => $currentUserId]);
        logAudit($db, 'SHIPMENT_REQUESTED', 'SHIPMENT', $no, "C.E.O {$currentUserName} requested a product shipment to {$destination}" . ($note !== '' ? " ({$note})" : '') . '.');
        notifyRoles($db, ['Procurement Officer'], 'Shipment requested by C.E.O', "{$no}: prepare finished products heading to {$destination}.", '/shipments.php');
        setFlash('success', "Shipment {$no} requested - the Procurement Officer will prepare it.");
        header('Location: /shipments.php');
        exit;
    }

    // 2. PO prepares the shipment
    if ($action === 'prepare_shipment') {
        if (!$isPO) {
            setFlash('error', 'Only the Procurement Officer prepares shipments.');
            header('Location: /shipments.php');
            exit;
        }
        $errors = [];
        $id     = field_int($errors, 'shipment_id', 'Shipment', 1) ?? 0;
        $bundles= field_int($errors, 'bundles', 'Bundles', 0) ?? 0;
        $units  = field_int($errors, 'units', 'Units', 1) ?? 0;
        $itemId = field_int($errors, 'product_item_id', 'Product', 0) ?? 0;
        $note   = field_text($errors, 'po_notes', 'Notes', false, 0, 255) ?? '';
        if ($errors || $id <= 0) {
            redirectWithErrors('/shipments.php', $errors ?: ['• Choose a valid shipment.']);
        }
        $sh = $db->query('SELECT * FROM shipment_orders WHERE id = ' . $id)->fetch();
        if (!$sh || $sh['status'] !== 'Requested by CEO') {
            setFlash('error', 'That shipment is not awaiting preparation.');
            header('Location: /shipments.php');
            exit;
        }
        $db->prepare("UPDATE shipment_orders SET bundles = :b, units = :u, product_item_id = :pi, status = 'Prepared - Awaiting Manager Approval', prepared_by_po = :by, prepared_at = CURRENT_TIMESTAMP, po_notes = :n WHERE id = :id")
           ->execute([':b' => $bundles, ':u' => $units, ':pi' => $itemId > 0 ? $itemId : null, ':by' => $currentUserId, ':n' => $note, ':id' => $id]);
        logAudit($db, 'SHIPMENT_PREPARED', 'SHIPMENT', $sh['shipment_no'], "PO {$currentUserName} prepared shipment {$sh['shipment_no']}: {$bundles} bundles / {$units} units to {$sh['destination']} - awaiting Manager approval.");
        notifyRoles($db, ['Manager'], 'Shipment awaiting your approval', "{$sh['shipment_no']}: {$bundles} bundles / {$units} units heading to {$sh['destination']} - approve the shipment info.", '/shipments.php');
        setFlash('success', "Shipment {$sh['shipment_no']} prepared - sent to the Manager for approval.");
        header('Location: /shipments.php');
        exit;
    }

    // 3. Manager approves -> dispatched
    if ($action === 'approve_shipment') {
        if (!$isMgr) {
            setFlash('error', 'Only the Manager approves shipment info.');
            header('Location: /shipments.php');
            exit;
        }
        $id = (int)($_POST['shipment_id'] ?? 0);
        $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approve' : 'reject';
        $notes = mb_substr(trim((string)($_POST['manager_notes'] ?? '')), 0, 255);
        $sh = $db->query('SELECT * FROM shipment_orders WHERE id = ' . $id)->fetch();
        if (!$sh || $sh['status'] !== 'Prepared - Awaiting Manager Approval') {
            setFlash('error', 'That shipment is not awaiting your approval.');
            header('Location: /shipments.php');
            exit;
        }
        if ($decision === 'approve') {
            try {
                $db->beginTransaction();
                // Deduct finished goods from inventory when a product was linked
                if (!empty($sh['product_item_id'])) {
                    $item = $db->query('SELECT * FROM inventory_items WHERE id = ' . (int)$sh['product_item_id'])->fetch();
                    if ($item && (float)$item['is_finished_goods'] === 1.0) {
                        if ((float)$item['quantity'] < (float)$sh['units']) {
                            throw new RuntimeException("Not enough finished stock: {$item['item_code']} has only " . rtrim(rtrim((string)$item['quantity'], '0'), '.') . " {$item['unit']} on hand.");
                        }
                        $db->prepare('UPDATE inventory_items SET quantity = quantity - :q WHERE id = :i')
                           ->execute([':q' => $sh['units'], ':i' => (int)$sh['product_item_id']]);
                        $db->prepare("INSERT INTO inventory_transactions (item_id, txn_type, quantity, reference, note, performed_by, txn_date)
                                      VALUES (:i, 'stock_out', :q, :ref, 'Dispatched on approved shipment', :by, CURRENT_DATE)")
                           ->execute([':i' => (int)$sh['product_item_id'], ':q' => $sh['units'], ':ref' => $sh['shipment_no'], ':by' => $currentUserId]);
                    }
                }
                $db->prepare("UPDATE shipment_orders SET status = 'Dispatched', approved_by_manager = :by, approved_at = CURRENT_TIMESTAMP, manager_notes = :n, dispatched_at = CURRENT_TIMESTAMP WHERE id = :id")
                   ->execute([':by' => $currentUserId, ':n' => $notes !== '' ? $notes : 'Approved by Manager ' . $currentUserName, ':id' => $id]);
                logAudit($db, 'SHIPMENT_DISPATCHED', 'SHIPMENT', $sh['shipment_no'], "Manager {$currentUserName} approved and dispatched shipment {$sh['shipment_no']} ({$sh['bundles']} bundles / {$sh['units']} units) to {$sh['destination']}.");
                $db->commit();
                notifyUser($db, (int)$sh['requested_by_ceo'], 'Shipment dispatched', "{$sh['shipment_no']} to {$sh['destination']} was approved by the Manager and has left the factory.", '/shipments.php');
                setFlash('success', "Shipment {$sh['shipment_no']} approved and dispatched.");
            } catch (RuntimeException $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                setFlash('error', $e->getMessage());
            } catch (Exception $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                error_log('[EPMS shipments] ' . $e->getMessage());
                setFlash('error', 'Approval failed. Please try again.');
            }
        } else {
            $db->prepare("UPDATE shipment_orders SET status = 'Rejected by Manager', approved_by_manager = :by, approved_at = CURRENT_TIMESTAMP, manager_notes = :n WHERE id = :id")
               ->execute([':by' => $currentUserId, ':n' => $notes !== '' ? $notes : 'Rejected by Manager', ':id' => $id]);
            logAudit($db, 'SHIPMENT_REJECTED', 'SHIPMENT', $sh['shipment_no'], "Manager {$currentUserName} rejected shipment {$sh['shipment_no']}: {$notes}");
            notifyUser($db, (int)$sh['requested_by_ceo'], 'Shipment rejected', "{$sh['shipment_no']} was rejected by the Manager" . ($notes !== '' ? ": {$notes}" : '.'), '/shipments.php');
            setFlash('warning', "Shipment {$sh['shipment_no']} rejected.");
        }
        header('Location: /shipments.php');
        exit;
    }

    setFlash('error', 'Unknown shipment action.');
    header('Location: /shipments.php');
    exit;
}

/* ==================================================================
 * Data
 * ================================================================== */
$shipments = $db->query(
    'SELECT s.*, i.item_name AS product_name, i.item_code AS product_code,
            c.name AS ceo_name, p.name AS po_name, m.name AS mgr_name
     FROM shipment_orders s
     LEFT JOIN inventory_items i ON i.id = s.product_item_id
     LEFT JOIN users c ON c.id = s.requested_by_ceo
     LEFT JOIN users p ON p.id = s.prepared_by_po
     LEFT JOIN users m ON m.id = s.approved_by_manager
     ORDER BY s.id DESC LIMIT 30'
)->fetchAll();

$finishedGoods = $db->query('SELECT * FROM inventory_items WHERE is_finished_goods = 1 AND status = "Active" ORDER BY item_name')->fetchAll();

$badge = function (string $s): string {
    return match ($s) {
        'Dispatched' => '<span class="badge badge-success">Dispatched</span>',
        'Rejected by Manager' => '<span class="badge badge-danger">Rejected</span>',
        'Prepared - Awaiting Manager Approval' => '<span class="badge badge-info">Awaiting Manager</span>',
        default => '<span class="badge badge-warning">Awaiting preparation</span>',
    };
};
?>
<?php include __DIR__ . '/components/header.php'; ?>
<main class="page-container">
    <?php displayFlash(); ?>

    <div style="margin-bottom:18px;">
        <h1 style="font-size:24px; font-weight:800;">Product Shipments</h1>
        <p style="color:var(--text-secondary, #64748b); font-size:13px;">
            C.E.O requests &rarr; Procurement Officer prepares &rarr; Manager approves and dispatches.
        </p>
    </div>

    <?php if ($isCEO): ?>
    <div class="card" style="padding:16px; margin-bottom:18px;">
        <h3 class="card-title" style="font-size:15px; font-weight:700; margin-bottom:10px;">Request a Product Shipment</h3>
        <form method="post" action="/shipments.php" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
            <input type="hidden" name="action" value="request_shipment">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div style="flex:1; min-width:220px;"><label style="font-size:11px;">Destination *</label>
                <input class="form-control" name="destination" required minlength="2" maxlength="150" placeholder="e.g. Kariakoo - Mwl. Baruti Wholesalers"></div>
            <div style="flex:1; min-width:180px;"><label style="font-size:11px;">Note</label>
                <input class="form-control" name="note" maxlength="255" placeholder="Optional instructions"></div>
            <button class="btn btn-primary" type="submit">Request shipment &rarr;</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card" style="padding:16px;">
        <h3 class="card-title" style="font-size:15px; font-weight:700; margin-bottom:10px;">Shipment Orders</h3>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead><tr>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Shipment</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Destination</th>
                    <th style="text-align:right; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Bundles</th>
                    <th style="text-align:right; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Units</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Status</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Action</th>
                </tr></thead>
                <tbody>
                <?php if (!$shipments): ?>
                    <tr><td colspan="6" style="padding:14px; color:var(--text-secondary, #64748b);">No shipments yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($shipments as $s): ?>
                    <tr>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0); font-weight:600;"><?= htmlspecialchars($s['shipment_no']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars($s['destination']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0); text-align:right;"><?= (int)$s['bundles'] ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0); text-align:right;"><?= (int)$s['units'] ?><?= $s['product_code'] ? ' <span style="color:var(--text-secondary,#94a3b8); font-size:11px;">(' . htmlspecialchars($s['product_code']) . ')</span>' : '' ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= $badge($s['status']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);">
                            <?php if ($isPO && $s['status'] === 'Requested by CEO'): ?>
                                <details>
                                    <summary class="btn btn-secondary" style="cursor:pointer; display:inline-block; font-size:11px; padding:4px 10px;">Prepare</summary>
                                    <form method="post" action="/shipments.php" style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap; align-items:end;">
                                        <input type="hidden" name="action" value="prepare_shipment">
                                        <input type="hidden" name="shipment_id" value="<?= (int)$s['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <div><label style="font-size:11px;">Product</label>
                                            <select class="form-control" name="product_item_id" style="min-width:160px;">
                                                <option value="0">- none -</option>
                                                <?php foreach ($finishedGoods as $fg): ?>
                                                    <option value="<?= (int)$fg['id'] ?>"><?= htmlspecialchars($fg['item_code']) ?> (stock: <?= rtrim(rtrim((string)$fg['quantity'], '0'), '.') ?>)</option>
                                                <?php endforeach; ?>
                                            </select></div>
                                        <div><label style="font-size:11px;">Bundles</label><input class="form-control" type="number" min="0" name="bundles" value="0" style="width:80px;"></div>
                                        <div><label style="font-size:11px;">Units *</label><input class="form-control" type="number" min="1" name="units" required style="width:90px;"></div>
                                        <button class="btn btn-primary" style="font-size:11px; padding:5px 12px;">Send to Manager</button>
                                    </form>
                                </details>
                            <?php elseif ($isMgr && $s['status'] === 'Prepared - Awaiting Manager Approval'): ?>
                                <form method="post" action="/shipments.php" style="display:inline-flex; gap:4px;">
                                    <input type="hidden" name="action" value="approve_shipment">
                                    <input type="hidden" name="shipment_id" value="<?= (int)$s['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <button class="btn btn-success" name="decision" value="approve" style="font-size:11px; padding:4px 10px;">Approve &amp; dispatch</button>
                                    <button class="btn btn-danger" name="decision" value="reject" style="font-size:11px; padding:4px 10px;">Reject</button>
                                </form>
                            <?php else: ?>
                                <span style="color:var(--text-secondary, #94a3b8);">&mdash;</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php include __DIR__ . '/components/footer.php'; ?>
