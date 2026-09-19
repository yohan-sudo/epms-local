<?php
/**
 * U EPMS - Inventory Control (v2.3)
 *
 * Access:
 *   Procurement Officer -> FULL CONTROL: create items, stock-in, stock-out, adjust
 *   CEO                 -> VIEW ONLY (read tables and stats, no write buttons)
 *   Manager             -> approves Supervisor material requests (no stock control)
 *   Supervisor          -> requests materials, confirms receipt; no stock control
 *   Accountant          -> view stock levels only
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/components/filter_bar.php';

requireRole(['CEO', 'Manager', 'Accountant', 'Procurement Officer', 'Supervisor']);

$pageTitle = 'Inventory Control';
$activeNav = 'inventory';

$currentUserRole = $_SESSION['user_role'];
$currentUserId   = (int)$_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

$isPO  = $currentUserRole === 'Procurement Officer';
$isSup = $currentUserRole === 'Supervisor';
$isMgr = $currentUserRole === 'Manager';
$canControlStock = $isPO;          // full inventory authority
$canViewOnly   = in_array($currentUserRole, ['CEO', 'Accountant'], true);

$UNITS = ['kg', 'tonne', 'litre', 'piece', 'bundle', 'packet', 'roll', 'drum', 'bag'];

/* ==================================================================
 * POST actions
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Guard: stock control is PO-only. Approvals follow role gates below.
    $stockGuard = function (string $msg) use ($isPO): void {
        if (!$isPO) {
            setFlash('error', $msg);
            header('Location: /inventory.php');
            exit;
        }
    };

    // 1. Create item (PO only)
    if ($action === 'create_item') {
        $stockGuard('Only the Procurement Officer can add or edit inventory items.');
        $errors = [];
        $name   = field_text($errors, 'item_name', 'Item name', true, 2, 150) ?? '';
        $unit   = field_choice($errors, 'unit', 'Unit of measure', $UNITS) ?? 'piece';
        $qty    = field_float($errors, 'quantity', 'Opening quantity', 0) ?? 0;
        $reorder = field_float($errors, 'reorder_level', 'Reorder level', 0) ?? 0;
        $ucost  = field_float($errors, 'unit_cost', 'Unit cost', 0) ?? 0;
        $isFG   = isset($_POST['is_finished_goods']) ? 1 : 0;
        if ($errors) {
            redirectWithErrors('/inventory.php', $errors);
        }
        $code = 'INV-' . strtoupper(bin2hex(random_bytes(3)));
        try {
            $stmt = $db->prepare("INSERT INTO inventory_items (item_code, item_name, unit, quantity, reorder_level, unit_cost, is_finished_goods, created_by)
                                  VALUES (:c, :n, :u, :q, :r, :uc, :fg, :by)");
            $stmt->execute([':c' => $code, ':n' => $name, ':u' => $unit, ':q' => $qty, ':r' => $reorder, ':uc' => $ucost, ':fg' => $isFG, ':by' => $currentUserId]);
            if ($qty > 0) {
                $itemId = (int)$db->lastInsertId();
                $t = $db->prepare("INSERT INTO inventory_transactions (item_id, txn_type, quantity, reference, note, performed_by, txn_date)
                                   VALUES (:i, 'stock_in', :q, 'Opening balance', NULL, :by, CURRENT_DATE)");
                $t->execute([':i' => $itemId, ':q' => $qty, ':by' => $currentUserId]);
            }
            logAudit($db, 'INVENTORY_ITEM_CREATED', 'INVENTORY_ITEM', (int)$db->lastInsertId(), "Item {$code} ({$name}) added to inventory by {$currentUserName} - opening qty {$qty} {$unit}.");
            setFlash('success', "Inventory item {$code} created.");
        } catch (Exception $e) {
            error_log('[EPMS inventory] ' . $e->getMessage());
            setFlash('error', 'Could not create the item. Please try again.');
        }
        header('Location: /inventory.php');
        exit;
    }

    // 2. Stock movement (PO only): in / out / adjust
    if (in_array($action, ['stock_in', 'stock_out', 'stock_adjust'], true)) {
        $stockGuard('Only the Procurement Officer can move stock in the inventory.');
        $errors = [];
        $itemId = field_int($errors, 'item_id', 'Item', 1) ?? 0;
        $qty    = field_float($errors, 'quantity', 'Quantity', 0.01) ?? 0;
        $note   = field_text($errors, 'note', 'Note', false, 0, 255) ?? '';
        if ($errors || $itemId <= 0) {
            redirectWithErrors('/inventory.php', $errors ?: ['• Choose a valid item.']);
        }
        $item = $db->query('SELECT * FROM inventory_items WHERE id = ' . (int)$itemId)->fetch();
        if (!$item) {
            setFlash('error', 'Item not found.');
            header('Location: /inventory.php');
            exit;
        }
        $newQty = (float)$item['quantity'];
        if ($action === 'stock_in') {
            $newQty += $qty;
        } elseif ($action === 'stock_out') {
            if ($qty > (float)$item['quantity']) {
                setFlash('error', 'Cannot issue more than the stock on hand (' . rtrim(rtrim((string)$item['quantity'], '0'), '.') . ' ' . $item['unit'] . ' available).');
                header('Location: /inventory.php');
                exit;
            }
            $newQty -= $qty;
        } else {
            $newQty = $qty; // absolute adjustment
        }
        try {
            $db->beginTransaction();
            $upd = $db->prepare('UPDATE inventory_items SET quantity = :q WHERE id = :i');
            $upd->execute([':q' => $newQty, ':i' => $itemId]);
            $t = $db->prepare("INSERT INTO inventory_transactions (item_id, txn_type, quantity, reference, note, performed_by, txn_date)
                               VALUES (:i, :t, :q, :ref, :note, :by, CURRENT_DATE)");
            $t->execute([
                ':i' => $itemId, ':t' => $action === 'stock_adjust' ? 'adjustment' : ($action === 'stock_in' ? 'stock_in' : 'stock_out'),
                ':q' => $qty, ':ref' => (string)($_POST['reference'] ?? ''), ':note' => $note, ':by' => $currentUserId,
            ]);
            logAudit($db, 'INVENTORY_MOVEMENT', 'INVENTORY_ITEM', $itemId,
                ucfirst(str_replace('_', ' ', $action)) . " of {$qty} {$item['unit']} on {$item['item_code']} ({$item['item_name']}) by {$currentUserName}. New balance: {$newQty}.");
            $db->commit();

            // Low-stock alert (reorder level breached)
            $reorderLevel = (float)$item['reorder_level'];
            if ($newQty <= $reorderLevel) {
                notifyRoles($db, ['Procurement Officer', 'CEO'], 'Low stock alert',
                    "{$item['item_code']} ({$item['item_name']}) is at {$newQty} {$item['unit']} - at or below the reorder level of {$reorder}.", '/inventory.php');
            }
            setFlash('success', 'Stock updated: ' . $item['item_code'] . ' is now ' . rtrim(rtrim((string)$newQty, '0'), '.') . ' ' . $item['unit'] . '.');
        } catch (Exception $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('[EPMS inventory] ' . $e->getMessage());
            setFlash('error', 'Stock update failed. Please try again.');
        }
        header('Location: /inventory.php');
        exit;
    }

    // 3. Supervisor requests materials
    if ($action === 'request_material') {
        if (!$isSup) {
            setFlash('error', 'Only the Supervisor can request materials from inventory.');
            header('Location: /inventory.php');
            exit;
        }
        $errors = [];
        $itemId  = field_int($errors, 'item_id', 'Item', 1) ?? 0;
        $qty     = field_float($errors, 'quantity', 'Quantity', 0.01) ?? 0;
        $purpose = field_text($errors, 'purpose', 'Purpose', false, 0, 255) ?? '';
        if ($errors || $itemId <= 0) {
            redirectWithErrors('/inventory.php', $errors ?: ['• Choose a valid item.']);
        }
        $item = $db->query('SELECT * FROM inventory_items WHERE id = ' . (int)$itemId)->fetch();
        if (!$item) {
            setFlash('error', 'Item not found.');
            header('Location: /inventory.php');
            exit;
        }
        $reqNo = 'MTR-' . date('Y') . '-' . str_pad((string)(((int)$db->query('SELECT COUNT(*) FROM inventory_requests')->fetchColumn()) + 1), 4, '0', STR_PAD_LEFT);
        try {
            $stmt = $db->prepare("INSERT INTO inventory_requests (request_no, item_id, quantity, purpose, requested_by, status)
                                  VALUES (:no, :i, :q, :p, :by, 'Pending Manager Approval')");
            $stmt->execute([':no' => $reqNo, ':i' => $itemId, ':q' => $qty, ':p' => $purpose, ':by' => $currentUserId]);

            // Shortage alert: requested amount exceeds what is available
            if ($qty > (float)$item['quantity']) {
                notifyRoles($db, ['Manager', 'Procurement Officer'], 'Inventory shortage',
                    "{$reqNo}: Supervisor {$currentUserName} requested {$qty} {$item['unit']} of {$item['item_code']} but only " . rtrim(rtrim((string)$item['quantity'], '0'), '.') . ' ' . $item['unit'] . " is in stock. Restock before release.", '/inventory.php');
            }
            notifyRoles($db, ['Manager'], 'Material request awaiting approval',
                "{$reqNo}: {$currentUserName} requests {$qty} {$item['unit']} of {$item['item_name']}" . ($purpose !== '' ? " ({$purpose})" : '') . '.', '/inventory.php');
            logAudit($db, 'INVENTORY_REQUEST_CREATED', 'INVENTORY_REQUEST', (int)$db->lastInsertId(), "{$reqNo} raised by {$currentUserName}: {$qty} {$item['unit']} of {$item['item_code']}.");
            setFlash('success', "Request {$reqNo} sent to the Manager for approval.");
        } catch (Exception $e) {
            error_log('[EPMS inventory] ' . $e->getMessage());
            setFlash('error', 'Could not save the request. Please try again.');
        }
        header('Location: /inventory.php');
        exit;
    }

    // 4. Manager approves/rejects material request
    if ($action === 'manager_request_decision') {
        if (!$isMgr) {
            setFlash('error', 'Only the Manager can approve material requests.');
            header('Location: /inventory.php');
            exit;
        }
        $reqId = (int)($_POST['request_id'] ?? 0);
        $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approve' : 'reject';
        $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255);
        $req = $db->query('SELECT r.*, i.item_code, i.item_name, i.unit, i.quantity AS stock FROM inventory_requests r JOIN inventory_items i ON i.id = r.item_id WHERE r.id = ' . $reqId)->fetch();
        if (!$req || $req['status'] !== 'Pending Manager Approval') {
            setFlash('error', 'That request is not awaiting your approval.');
            header('Location: /inventory.php');
            exit;
        }
        if ($decision === 'approve') {
            $db->prepare("UPDATE inventory_requests SET status = 'Approved - Awaiting Release', manager_decision_by = :by, manager_decision_at = CURRENT_TIMESTAMP, manager_notes = :n WHERE id = :id")
               ->execute([':by' => $currentUserId, ':n' => $notes, ':id' => $reqId]);
            notifyRoles($db, ['Procurement Officer'], 'Material request approved',
                "{$req['request_no']} approved by the Manager - release {$req['quantity']} {$req['unit']} of {$req['item_code']} to the Supervisor.", '/inventory.php');
            logAudit($db, 'INVENTORY_REQUEST_APPROVED', 'INVENTORY_REQUEST', $reqId, "{$req['request_no']} approved by {$currentUserName}.");
            setFlash('success', "{$req['request_no']} approved - the Procurement Officer can now release the materials.");
        } else {
            $db->prepare("UPDATE inventory_requests SET status = 'Rejected', manager_decision_by = :by, manager_decision_at = CURRENT_TIMESTAMP, manager_notes = :n WHERE id = :id")
               ->execute([':by' => $currentUserId, ':n' => $notes, ':id' => $reqId]);
            notifyUser($db, (int)$req['requested_by'], 'Material request rejected', "{$req['request_no']} was rejected by the Manager" . ($notes !== '' ? ": {$notes}" : '.'), '/inventory.php');
            logAudit($db, 'INVENTORY_REQUEST_REJECTED', 'INVENTORY_REQUEST', $reqId, "{$req['request_no']} rejected by {$currentUserName}.");
            setFlash('info', "{$req['request_no']} rejected.");
        }
        header('Location: /inventory.php');
        exit;
    }

    // 5. PO releases approved materials (deducts stock)
    if ($action === 'release_material') {
        $stockGuard('Only the Procurement Officer can release materials from inventory.');
        $reqId = (int)($_POST['request_id'] ?? 0);
        $req = $db->query('SELECT r.*, i.item_code, i.item_name, i.unit, i.quantity AS stock FROM inventory_requests r JOIN inventory_items i ON i.id = r.item_id WHERE r.id = ' . $reqId)->fetch();
        if (!$req || $req['status'] !== 'Approved - Awaiting Release') {
            setFlash('error', 'That request is not ready for release.');
            header('Location: /inventory.php');
            exit;
        }
        if ((float)$req['quantity'] > (float)$req['stock']) {
            setFlash('error', "Not enough stock: {$req['item_code']} has only " . rtrim(rtrim((string)$req['stock'], '0'), '.') . " {$req['unit']} on hand. Restock first.");
            header('Location: /inventory.php');
            exit;
        }
        try {
            $db->beginTransaction();
            $db->prepare('UPDATE inventory_items SET quantity = quantity - :q WHERE id = :i')->execute([':q' => $req['quantity'], ':i' => (int)$req['item_id']]);
            $db->prepare("INSERT INTO inventory_transactions (item_id, txn_type, quantity, reference, note, performed_by, txn_date)
                          VALUES (:i, 'stock_out', :q, :ref, :note, :by, CURRENT_DATE)")
               ->execute([':i' => (int)$req['item_id'], ':q' => $req['quantity'], ':ref' => $req['request_no'],
                          ':note' => 'Issued to Supervisor on approved request', ':by' => $currentUserId]);
            $db->prepare("UPDATE inventory_requests SET status = 'Released - Awaiting Confirmation', released_by = :by, released_at = CURRENT_TIMESTAMP WHERE id = :id")
               ->execute([':by' => $currentUserId, ':id' => $reqId]);
            logAudit($db, 'INVENTORY_REQUEST_RELEASED', 'INVENTORY_REQUEST', $reqId, "{$req['request_no']} released: {$req['quantity']} {$req['unit']} of {$req['item_code']} issued by {$currentUserName}.");
            $db->commit();
            notifyUser($db, (int)$req['requested_by'], 'Materials released', "{$req['request_no']}: collect {$req['quantity']} {$req['unit']} of {$req['item_code']} from the store, then confirm receipt.", '/inventory.php');
            if ((float)$req['stock'] - (float)$req['quantity'] <= (float)($req['reorder_level'] ?? 0)) {
                // reorder check handled on next movement; simple heads-up now
            }
            setFlash('success', "{$req['request_no']} released to the Supervisor.");
        } catch (Exception $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('[EPMS inventory] ' . $e->getMessage());
            setFlash('error', 'Release failed. Please try again.');
        }
        header('Location: /inventory.php');
        exit;
    }

    // 6. Supervisor confirms receipt
    if ($action === 'confirm_receipt') {
        if (!$isSup) {
            setFlash('error', 'Only the Supervisor can confirm receipt of materials.');
            header('Location: /inventory.php');
            exit;
        }
        $reqId = (int)($_POST['request_id'] ?? 0);
        $req = $db->query('SELECT * FROM inventory_requests WHERE id = ' . $reqId)->fetch();
        if (!$req || $req['status'] !== 'Released - Awaiting Confirmation') {
            setFlash('error', 'That request is not awaiting your confirmation.');
            header('Location: /inventory.php');
            exit;
        }
        $db->prepare("UPDATE inventory_requests SET status = 'Completed', received_confirmed_by = :by, received_confirmed_at = CURRENT_TIMESTAMP WHERE id = :id")
           ->execute([':by' => $currentUserId, ':id' => $reqId]);
        logAudit($db, 'INVENTORY_REQUEST_RECEIVED', 'INVENTORY_REQUEST', $reqId, "{$req['request_no']} receipt confirmed by {$currentUserName}.");
        setFlash('success', "{$req['request_no']} marked as received. Thank you.");
        header('Location: /inventory.php');
        exit;
    }

    setFlash('error', 'Unknown inventory action.');
    header('Location: /inventory.php');
    exit;
}

/* ==================================================================
 * Data
 * ================================================================== */
$items = $db->query('SELECT * FROM inventory_items WHERE status = "Active" ORDER BY item_name ASC')->fetchAll();
$lowStock = array_filter($items, fn ($i) => (float)$i['quantity'] <= (float)$i['reorder_level']);

$recentTxns = $db->query(
    'SELECT t.*, i.item_code, i.item_name, i.unit, u.name AS actor
     FROM inventory_transactions t
     JOIN inventory_items i ON i.id = t.item_id
     JOIN users u ON u.id = t.performed_by
     ORDER BY t.id DESC LIMIT 12'
)->fetchAll();

$requests = $db->query(
    'SELECT r.*, i.item_code, i.item_name, i.unit, i.quantity AS stock,
            ru.name AS requester, mu.name AS manager_name, pu.name AS releaser
     FROM inventory_requests r
     JOIN inventory_items i ON i.id = r.item_id
     JOIN users ru ON ru.id = r.requested_by
     LEFT JOIN users mu ON mu.id = r.manager_decision_by
     LEFT JOIN users pu ON pu.id = r.released_by
     ORDER BY r.id DESC LIMIT 25'
)->fetchAll();

$myOpenRequests = $isSup
    ? (int)$db->query("SELECT COUNT(*) FROM inventory_requests WHERE requested_by = {$currentUserId} AND status IN ('Released - Awaiting Confirmation')")->fetchColumn()
    : 0;
?>
<?php include __DIR__ . '/components/header.php'; ?>
<main class="page-container">
    <?php displayFlash(); ?>

    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
        <div>
            <h1 style="font-size:24px; font-weight:800;">Inventory Control</h1>
            <p style="color:var(--text-secondary, #64748b); font-size:13px;">
                <?php if ($isPO): ?>You have full control: add items, receive stock, issue materials and adjust balances.
                <?php elseif ($currentUserRole === 'CEO'): ?>View only - the inventory is managed by the Procurement Officer.
                <?php elseif ($isMgr): ?>Approve or reject the Supervisor's material requests. Stock control stays with the Procurement Officer.
                <?php elseif ($isSup): ?>Request materials for the floor and confirm what you receive.
                <?php else: ?>Stock levels for reference.<?php endif; ?>
            </p>
        </div>
        <?php if ($myOpenRequests > 0): ?>
            <span class="badge badge-warning" style="font-size:12px; padding:6px 12px;"><?= $myOpenRequests ?> material(s) awaiting your receipt confirmation</span>
        <?php endif; ?>
    </div>

    <?php if ($lowStock): ?>
        <div class="card alert-warning" style="padding:12px 16px; margin-bottom:16px; font-size:13px;">
            &#9888;&#65039; <strong>Low stock (<?= count($lowStock) ?>):</strong>
            <?php foreach (array_slice($lowStock, 0, 4, true) as $i): ?>
                <?= htmlspecialchars($i['item_code']) ?> (<?= rtrim(rtrim((string)$i['quantity'], '0'), '.') ?> <?= htmlspecialchars($i['unit']) ?>)<?php echo next($lowStock) ? '; ' : '' ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:18px;">
        <div class="card" style="padding:14px 16px;">
            <div style="font-size:12px; color:var(--text-secondary, #64748b);">Tracked Items</div>
            <div style="font-size:22px; font-weight:800;"><?= count($items) ?></div>
        </div>
        <div class="card" style="padding:14px 16px;">
            <div style="font-size:12px; color:var(--text-secondary, #64748b);">Below Reorder Level</div>
            <div style="font-size:22px; font-weight:800; color:<?= count($lowStock) ? '#dc2626' : 'inherit' ?>;"><?= count($lowStock) ?></div>
        </div>
        <?php if ($isSup): ?>
        <div class="card" style="padding:14px 16px;">
            <div style="font-size:12px; color:var(--text-secondary, #64748b);">My Awaiting Confirmations</div>
            <div style="font-size:22px; font-weight:800;"><?= $myOpenRequests ?></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($isPO): ?>
    <!-- PO: add item -->
    <details style="margin-bottom:16px;">
        <summary class="btn btn-secondary" style="cursor:pointer; display:inline-block; padding:9px 16px; border-radius:8px;">+ Add Inventory Item</summary>
        <form method="post" action="/inventory.php" class="card" style="padding:16px; margin-top:10px;">
            <input type="hidden" name="action" value="create_item">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
                <div class="form-group"><label>Item name *</label><input class="form-control" name="item_name" required minlength="2" maxlength="150"></div>
                <div class="form-group"><label>Unit *</label>
                    <select class="form-control" name="unit" required><?php foreach ($UNITS as $u): ?><option><?= $u ?></option><?php endforeach; ?></select>
                </div>
                <div class="form-group"><label>Opening quantity</label><input class="form-control" type="number" step="0.01" min="0" name="quantity" value="0"></div>
                <div class="form-group"><label>Reorder level</label><input class="form-control" type="number" step="0.01" min="0" name="reorder_level" value="0"></div>
                <div class="form-group"><label>Unit cost (TZS)</label><input class="form-control" type="number" step="0.01" min="0" name="unit_cost" value="0"></div>
            </div>
            <label style="font-size:13px; margin-top:8px; display:inline-flex; gap:6px; align-items:center;">
                <input type="checkbox" name="is_finished_goods" value="1"> Finished goods (broom sticks) rather than raw material
            </label>
            <div style="margin-top:12px;"><button class="btn btn-primary" type="submit">Save Item</button></div>
        </form>
    </details>
    <?php endif; ?>

    <!-- Items table -->
    <div class="card" style="padding:16px; margin-bottom:18px;">
        <h3 class="card-title" style="font-size:15px; font-weight:700; margin-bottom:10px;">Stock on Hand</h3>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead><tr>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Code</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Item</th>
                    <th style="text-align:right; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Quantity</th>
                    <th style="text-align:right; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Reorder</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Status</th>
                    <?php if ($isPO): ?><th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Actions</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php if (!$items): ?>
                    <tr><td colspan="6" style="padding:14px; color:var(--text-secondary, #64748b);">No items yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($items as $i): $low = (float)$i['quantity'] <= (float)$i['reorder_level']; ?>
                    <tr>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0); font-weight:600;"><?= htmlspecialchars($i['item_code']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars($i['item_name']) ?><?= $i['is_finished_goods'] ? ' <span class="badge badge-success" style="font-size:10px;">finished</span>' : '' ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0); text-align:right; font-weight:700; color:<?= $low ? '#dc2626' : 'inherit' ?>;"><?= rtrim(rtrim((string)$i['quantity'], '0'), '.') ?> <?= htmlspecialchars($i['unit']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0); text-align:right;"><?= rtrim(rtrim((string)$i['reorder_level'], '0'), '.') ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= $low ? '<span class="badge badge-danger" style="font-size:10px;">LOW</span>' : '<span class="badge badge-success" style="font-size:10px;">OK</span>' ?></td>
                        <?php if ($isPO): ?>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);">
                            <details>
                                <summary class="btn btn-secondary" style="cursor:pointer; display:inline-block; font-size:11px; padding:4px 10px;">Move</summary>
                                <form method="post" action="/inventory.php" style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                    <input type="hidden" name="action" value="stock_in">
                                    <input type="hidden" name="item_id" value="<?= (int)$i['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input class="form-control" type="number" step="0.01" min="0.01" name="quantity" placeholder="Qty" required style="width:90px; padding:4px 8px;">
                                    <select class="form-control" name="direction" style="width:auto; padding:4px 8px;" onchange="this.form.action.value=this.value==='out'?'stock_out':(this.value==='adjust'?'stock_adjust':'stock_in')">
                                        <option value="in">In +</option>
                                        <option value="out">Out &minus;</option>
                                        <option value="adjust">Set =</option>
                                    </select>
                                    <input class="form-control" name="note" placeholder="Note" maxlength="255" style="width:130px; padding:4px 8px;">
                                    <button class="btn btn-primary" style="font-size:11px; padding:5px 12px;">Apply</button>
                                </form>
                            </details>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($isSup || $isMgr || $isPO): ?>
    <!-- Material requests -->
    <div class="card" style="padding:16px; margin-bottom:18px;">
        <h3 class="card-title" style="font-size:15px; font-weight:700; margin-bottom:10px;">Material Requests (Supervisor &rarr; Manager &rarr; Store)</h3>

        <?php if ($isSup): ?>
        <form method="post" action="/inventory.php" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end; margin-bottom:14px; padding:12px; background:var(--bg-surface-subtle, #f8fafc); border-radius:8px;">
            <input type="hidden" name="action" value="request_material">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div><label style="font-size:11px;">Item *</label>
                <select class="form-control" name="item_id" required style="min-width:200px;">
                    <?php foreach ($items as $i): ?>
                        <option value="<?= (int)$i['id'] ?>"><?= htmlspecialchars($i['item_code']) ?> - <?= htmlspecialchars($i['item_name']) ?> (stock: <?= rtrim(rtrim((string)$i['quantity'], '0'), '.') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label style="font-size:11px;">Quantity *</label><input class="form-control" type="number" step="0.01" min="0.01" name="quantity" required style="width:110px;"></div>
            <div><label style="font-size:11px;">Purpose</label><input class="form-control" name="purpose" maxlength="255" style="width:200px;"></div>
            <button class="btn btn-primary" type="submit">Request &rarr; Manager</button>
        </form>
        <?php endif; ?>

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead><tr>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Request</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Item</th>
                    <th style="text-align:right; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Qty</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Status</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">By</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Action</th>
                </tr></thead>
                <tbody>
                <?php if (!$requests): ?>
                    <tr><td colspan="6" style="padding:14px; color:var(--text-secondary, #64748b);">No material requests yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($requests as $r): $short = (float)$r['quantity'] > (float)$r['stock']; ?>
                    <tr>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0); font-weight:600;"><?= htmlspecialchars($r['request_no']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars($r['item_code']) ?> <?= $short ? '<span class="badge badge-danger" style="font-size:10px;">shortage</span>' : '' ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0); text-align:right;"><?= rtrim(rtrim((string)$r['quantity'], '0'), '.') ?> <?= htmlspecialchars($r['unit']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars($r['status']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars($r['requester']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);">
                            <?php if ($isMgr && $r['status'] === 'Pending Manager Approval'): ?>
                                <form method="post" action="/inventory.php" style="display:inline-flex; gap:4px; align-items:center;">
                                    <input type="hidden" name="action" value="manager_request_decision">
                                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <button class="btn btn-primary" name="decision" value="approve" style="font-size:11px; padding:4px 10px;">Approve</button>
                                    <button class="btn btn-secondary" name="decision" value="reject" style="font-size:11px; padding:4px 10px;">Reject</button>
                                </form>
                            <?php elseif ($isPO && $r['status'] === 'Approved - Awaiting Release'): ?>
                                <form method="post" action="/inventory.php" style="display:inline;">
                                    <input type="hidden" name="action" value="release_material">
                                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <button class="btn btn-primary" style="font-size:11px; padding:4px 10px;">Release from store</button>
                                </form>
                            <?php elseif ($isSup && (int)$r['requested_by'] === $currentUserId && $r['status'] === 'Released - Awaiting Confirmation'): ?>
                                <form method="post" action="/inventory.php" style="display:inline;">
                                    <input type="hidden" name="action" value="confirm_receipt">
                                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <button class="btn btn-primary" style="font-size:11px; padding:4px 10px;">I received it</button>
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
    <?php endif; ?>

    <!-- Recent movements -->
    <div class="card" style="padding:16px;">
        <h3 class="card-title" style="font-size:15px; font-weight:700; margin-bottom:10px;">Recent Movements</h3>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead><tr>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">When</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Item</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Type</th>
                    <th style="text-align:right; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Qty</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">By</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Note</th>
                </tr></thead>
                <tbody>
                <?php if (!$recentTxns): ?>
                    <tr><td colspan="6" style="padding:14px; color:var(--text-secondary, #64748b);">No movements recorded yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentTxns as $t): ?>
                    <tr>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= formatDate($t['txn_date']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars($t['item_code']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars(str_replace('_', ' ', $t['txn_type'])) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0); text-align:right; font-weight:600;"><?= rtrim(rtrim((string)$t['quantity'], '0'), '.') ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars($t['actor']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars((string)($t['note'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php include __DIR__ . '/components/footer.php'; ?>
