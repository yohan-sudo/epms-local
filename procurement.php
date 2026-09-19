<?php
/**
 * U EPMS - Procurement Records Pipeline (v2.3)
 * Complete lifecycle:
 *   1. Procurement Officer submits what has been procured -> 'Pending Manager Review'
 *      AND a procurement requisition goes to the C.E.O for authority approval.
 *   2. C.E.O approves the requisition -> the Officer is cleared to procure.
 *   3. Manager REVIEWS the record -> FINAL approval ('Finalized') or 'Rejected'
 *      (v2.3.2: the Accountant never approves procurement - they pay for it
 *       through the Cash Requests workflow after the Manager's approval)
 *   4. Accountant gives FINAL approval -> 'Finalized' (locked) or 'Rejected'
 *   5. Procurement Officer receives the goods INTO INVENTORY (once).
 * Closed records are immutable.
 * Everyone (all roles incl. Supervisor) can VIEW the pipeline; writes are role-enforced.
 * Pure PHP 8.2 & Plain HTML5/CSS3 (Zero Frameworks)
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/components/filter_bar.php';

requireRole(['CEO', 'Manager', 'Accountant', 'Procurement Officer', 'Supervisor']);

$pageTitle = 'Procurement Records';
$activeNav = 'procurement';

$currentUserRole = $_SESSION['user_role'];
$currentUserId = $_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

$isOpenStatus = static fn (string $s): bool => is_open_requisition($s);

/**
 * Generates the next unique reference number for the current year.
 * Driver-agnostic (no MySQL-only string functions).
 */
function next_reference_no(PDO $db): string
{
    $year = date('Y');
    $stmt = $db->prepare("SELECT reference_no FROM procurement_entries WHERE reference_no LIKE :p");
    $stmt->execute([':p' => "PRC-{$year}-%"]);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ref) {
        $parts = explode('-', (string)$ref);
        $max = max($max, (int)end($parts));
    }
    return sprintf('PRC-%s-%03d', $year, $max + 1);
}

const PROCUREMENT_CATEGORIES = [
    'Raw Material', 'Tooling', 'Consumables', 'Spare Parts', 'Safety & PPE', 'Logistics & Freight', 'Other',
];

// v2.2 (item 35): fixed unit vocabulary so records are comparable across
// periods and officers - no more free-text units like "kgs" vs "KG" vs "kilos".
const PROCUREMENT_UNITS = [
    'kg', 'tonne', 'litre', 'piece', 'bundle', 'packet', 'roll', 'drum', 'service',
];

// ==================================================================
 // POST actions
// ==================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- 1. Submit procured-item record (Procurement Officer only) ----
    if ($action === 'create_request') {
        if ($currentUserRole !== 'Procurement Officer') {
            setFlash('error', 'Submitting procurement records is reserved for the Procurement Officer. Your role has view access on this module.');
            header('Location: /procurement.php');
            exit;
        }

        $errors = [];
        $supplier  = field_text($errors, 'supplier', 'Supplier', true, 2, 150) ?? '';
        $itemName  = field_text($errors, 'item_name', 'Item specification', true, 2, 255) ?? '';
        $category  = field_choice($errors, 'category', 'Category', PROCUREMENT_CATEGORIES) ?? '';
        $quantity  = field_float($errors, 'quantity', 'Quantity', 0.01) ?? 0;
        $unit      = field_choice($errors, 'unit', 'Unit of measure', PROCUREMENT_UNITS) ?? '';
        $unitCost  = field_float($errors, 'unit_cost', 'Unit price', 1) ?? 0;
        $reqDate   = field_date($errors, 'req_date', 'Procurement date', true, true) ?? date('Y-m-d');

        if ($errors) {
            redirectWithErrors('/procurement.php?action=new', $errors);
        }

        $totalCost = round($quantity * $unitCost, 2);
        if ($totalCost > 20000000000) {
            redirectWithErrors('/procurement.php?action=new', ['• Total value exceeds the system limit.']);
        }

        $referenceNo = next_reference_no($db);
        $stmt = $db->prepare("
            INSERT INTO procurement_entries
                (reference_no, submitted_by, supplier, item_name, category,
                 quantity, unit, unit_cost, total_cost, status, requisition_status, date, created_at)
            VALUES (:ref, :uid, :supplier, :item, :cat, :qty, :unit, :ucost, :tcost, 'Pending Manager Review', 'Pending CEO Approval', :rdate, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':ref'     => $referenceNo,
            ':uid'     => $currentUserId,
            ':supplier' => $supplier,
            ':item'    => $itemName,
            ':cat'     => $category,
            ':qty'     => $quantity,
            ':unit'    => $unit,
            ':ucost'   => $unitCost,
            ':tcost'   => $totalCost,
            ':rdate'   => $reqDate,
        ]);
        $newId = (int)$db->lastInsertId();

        logAudit($db, 'PROCUREMENT_SUBMITTED', 'PROCUREMENT', $referenceNo, "Procurement Officer {$currentUserName} submitted procurement record {$referenceNo}: {$quantity} {$unit} of '{$itemName}' from {$supplier} (" . formatMoney($totalCost) . ") - requisition sent to the C.E.O, record awaiting Manager approval");
        notifyRoles($db, ['CEO'], 'Procurement requisition awaiting your approval',
            "{$referenceNo}: {$quantity} {$unit} of {$itemName} from {$supplier} (" . formatMoney($totalCost) . ") - approve so the Procurement Officer can proceed.", '/procurement.php?view_id=' . $newId);
        setFlash('success', "Procurement record {$referenceNo} submitted. The requisition is with the C.E.O and the record awaits the Manager's review.");
        header('Location: /procurement.php?view_id=' . $newId);
        exit;
    }

    // ---- 1b. C.E.O requisition authority gate (CEO only) -----------
    if ($action === 'requisition_decision') {
        if ($currentUserRole !== 'CEO') {
            setFlash('error', 'Only the C.E.O can approve or reject procurement requisitions.');
            header('Location: /procurement.php');
            exit;
        }
        $poId = (int)($_POST['po_id'] ?? 0);
        $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approve' : 'reject';
        $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255);
        $po = $db->query('SELECT * FROM procurement_entries WHERE id = ' . $poId)->fetch();
        if (!$po) {
            setFlash('error', 'Procurement record not found.');
            header('Location: /procurement.php');
            exit;
        }
        if ($po['requisition_status'] !== 'Pending CEO Approval') {
            setFlash('warning', "Requisition for {$po['reference_no']} is not awaiting your approval (current state: {$po['requisition_status']}).");
            header('Location: /procurement.php?view_id=' . $poId);
            exit;
        }
        if ($decision === 'approve') {
            $db->prepare("UPDATE procurement_entries SET requisition_status = 'CEO Approved', requisition_ceo_id = :uid, requisition_ceo_at = CURRENT_TIMESTAMP, requisition_notes = :n WHERE id = :id")
               ->execute([':uid' => $currentUserId, ':n' => $notes !== '' ? $notes : 'Approved by C.E.O ' . $currentUserName, ':id' => $poId]);
            logAudit($db, 'REQUISITION_CEO_APPROVED', 'PROCUREMENT', $po['reference_no'], "C.E.O {$currentUserName} approved the procurement requisition for {$po['reference_no']} - the Officer is cleared to procure.");
            notifyUser($db, (int)$po['submitted_by'], 'Requisition approved - go procure', "Your requisition {$po['reference_no']} (" . formatMoney((float)$po['total_cost']) . ") was approved by the C.E.O. You may proceed to procure.", '/procurement.php?view_id=' . $poId);
            setFlash('success', "Requisition for {$po['reference_no']} approved - the Procurement Officer is cleared to procure.");
        } else {
            $db->prepare("UPDATE procurement_entries SET requisition_status = 'CEO Rejected', requisition_ceo_id = :uid, requisition_ceo_at = CURRENT_TIMESTAMP, requisition_notes = :n, status = 'Rejected', rejection_reason = :n2 WHERE id = :id")
               ->execute([':uid' => $currentUserId, ':n' => $notes !== '' ? $notes : 'Rejected by C.E.O', ':n2' => $notes !== '' ? $notes : 'Rejected by C.E.O ' . $currentUserName, ':id' => $poId]);
            logAudit($db, 'REQUISITION_CEO_REJECTED', 'PROCUREMENT', $po['reference_no'], "C.E.O {$currentUserName} rejected the procurement requisition for {$po['reference_no']}.");
            notifyUser($db, (int)$po['submitted_by'], 'Requisition rejected', "Your requisition {$po['reference_no']} was rejected by the C.E.O" . ($notes !== '' ? ": {$notes}" : '.'), '/procurement.php?view_id=' . $poId);
            setFlash('warning', "Requisition for {$po['reference_no']} rejected.");
        }
        header('Location: /procurement.php?view_id=' . $poId);
        exit;
    }

    // ---- 1c. Receive finalized goods into inventory (PO only) ------
    if ($action === 'receive_to_inventory') {
        if ($currentUserRole !== 'Procurement Officer') {
            setFlash('error', 'Only the Procurement Officer receives procured materials into inventory.');
            header('Location: /procurement.php');
            exit;
        }
        $poId = (int)($_POST['po_id'] ?? 0);
        $po = $db->query('SELECT * FROM procurement_entries WHERE id = ' . $poId)->fetch();
        if (!$po || $po['status'] !== 'Finalized' || (int)$po['inventory_received'] === 1) {
            setFlash('error', 'Only finalized, not-yet-received procurement records can be received into inventory.');
            header('Location: /procurement.php?view_id=' . $poId);
            exit;
        }
        try {
            $db->beginTransaction();
            $existing = $db->prepare('SELECT * FROM inventory_items WHERE item_name = :n AND is_finished_goods = 0 LIMIT 1');
            $existing->execute([':n' => $po['item_name']]);
            $item = $existing->fetch();
            if ($item) {
                $db->prepare('UPDATE inventory_items SET quantity = quantity + :q WHERE id = :i')->execute([':q' => $po['quantity'], ':i' => (int)$item['id']]);
                $itemId = (int)$item['id'];
            } else {
                $code = 'INV-' . strtoupper(bin2hex(random_bytes(3)));
                $ins = $db->prepare("INSERT INTO inventory_items (item_code, item_name, unit, quantity, reorder_level, unit_cost, is_finished_goods, created_by)
                                     VALUES (:c, :n, :u, :q, 0, :uc, 0, :by)");
                $ins->execute([':c' => $code, ':n' => $po['item_name'], ':u' => $po['unit'], ':q' => $po['quantity'], ':uc' => $po['unit_cost'], ':by' => $currentUserId]);
                $itemId = (int)$db->lastInsertId();
            }
            $db->prepare("INSERT INTO inventory_transactions (item_id, txn_type, quantity, reference, note, performed_by, txn_date)
                         VALUES (:i, 'stock_in', :q, :ref, 'Received from procurement', :by, CURRENT_DATE)")
               ->execute([':i' => $itemId, ':q' => $po['quantity'], ':ref' => $po['reference_no'], ':by' => $currentUserId]);
            $db->prepare('UPDATE procurement_entries SET inventory_received = 1 WHERE id = :id')->execute([':id' => $poId]);
            logAudit($db, 'PROCUREMENT_RECEIVED_TO_INVENTORY', 'PROCUREMENT', $po['reference_no'], "{$po['quantity']} {$po['unit']} of '{$po['item_name']}' from {$po['reference_no']} received into inventory by {$currentUserName}.");
            $db->commit();
            notifyRoles($db, ['CEO'], 'Materials received into inventory', "{$po['reference_no']}: {$po['quantity']} {$po['unit']} of {$po['item_name']} now in inventory.", '/inventory.php');
            setFlash('success', "{$po['reference_no']} received into inventory.");
        } catch (Exception $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('[EPMS procurement] receive: ' . $e->getMessage());
            setFlash('error', 'Could not receive into inventory. Please try again.');
        }
        header('Location: /procurement.php?view_id=' . $poId);
        exit;
    }

    // ---- 2. Manager decision (Manager only) ------------------------
    if ($action === 'manager_decision') {
        if ($currentUserRole !== 'Manager') {
            setFlash('error', 'Manager approval is reserved for the Manager role. Your role has view access only on this module.');
            header('Location: /procurement.php');
            exit;
        }

        $poId     = (int)($_POST['po_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $notes    = trim((string)($_POST['notes'] ?? ''));

        $errors = [];
        if ($decision !== 'approve' && $decision !== 'reject') {
            $errors[] = '• A valid decision (approve or reject) is required.';
        }
        $notes = field_text($errors, 'notes', 'Decision notes', false, 0, 500) ?? '';

        if ($errors) {
            redirectWithErrors('/procurement.php', $errors);
        }

        if ($decision === 'reject' && $notes === '') {
            $notes = 'Rejected by Manager.';
        }

        $poStmt = $db->prepare("SELECT * FROM procurement_entries WHERE id = :id");
        $poStmt->execute([':id' => $poId]);
        $po = $poStmt->fetch();

        if (!$po) {
            setFlash('error', 'Procurement record not found.');
            header('Location: /procurement.php');
            exit;
        }

        if (!$isOpenStatus($po['status'])) {
            setFlash('warning', "Procurement record {$po['reference_no']} has already been closed ({$po['status']}) and can no longer be decided on.");
            header('Location: /procurement.php?view_id=' . $poId);
            exit;
        }

        if ($po['status'] !== 'Pending Manager Review' && $po['status'] !== 'Pending Approval') {
            setFlash('warning', "Procurement record {$po['reference_no']} is not awaiting Manager review (current stage: {$po['status']}).");
            header('Location: /procurement.php?view_id=' . $poId);
            exit;
        }

        if ($decision === 'approve') {
            // v2.3.2: the Manager's approval is FINAL. The Accountant does not
            // approve procurement - they arrange payment via Cash Requests.
            $stmt = $db->prepare("
                UPDATE procurement_entries
                SET status = 'Finalized',
                    manager_approved_by = :uid,
                    manager_approved_at = CURRENT_TIMESTAMP,
                    manager_notes = :notes,
                    rejection_reason = NULL
                WHERE id = :id
            ");
            $stmt->execute([
                ':uid'   => $currentUserId,
                ':notes' => $notes !== '' ? $notes : 'Final approval by Manager ' . $currentUserName,
                ':id'    => $poId,
            ]);
            logAudit($db, 'PROCUREMENT_MANAGER_APPROVED', 'PROCUREMENT', $po['reference_no'], "Manager {$currentUserName} gave FINAL approval to procurement record {$po['reference_no']} (" . formatMoney((float)$po['total_cost']) . ") - closed, locked, and ready for payment via Cash Requests");
            notifyRoles($db, ['Procurement Officer'], 'Procurement approved - payment next', "{$po['reference_no']} (" . formatMoney((float)$po['total_cost']) . ") was finally approved by the Manager. Request cash from the C.E.O to pay the supplier.", '/cash_requests.php');
            setFlash('success', "Procurement record {$po['reference_no']} FINALIZED. The Procurement Officer can receive it into inventory and request payment via Cash Requests.");
        } else {
            $stmt = $db->prepare("
                UPDATE procurement_entries
                SET status = 'Rejected',
                    manager_approved_by = :uid,
                    manager_approved_at = CURRENT_TIMESTAMP,
                    manager_notes = :notes,
                    rejection_reason = :reason
                WHERE id = :id
            ");
            $stmt->execute([':uid' => $currentUserId, ':notes' => $notes, ':reason' => $notes, ':id' => $poId]);
            logAudit($db, 'PROCUREMENT_MANAGER_REJECTED', 'PROCUREMENT', $po['reference_no'], "Manager {$currentUserName} rejected procurement record {$po['reference_no']}: {$notes}");
            setFlash('warning', "Procurement record {$po['reference_no']} was rejected.");
        }

        header('Location: /procurement.php?view_id=' . $poId);
        exit;
    }

    // ---- 3. Accountant decision REMOVED (v2.3.2) -------------------
    // The Accountant never approves procurement: their role is to PAY for
    // approved purchases via the cash-request workflow. The Manager's
    // approval is final; the record then goes Finalized -> inventory.
    if ($action === 'accountant_decision') {
        setFlash('error', 'Procurement approval is not the Accountant\'s role. Payments are handled through Cash Requests after the Manager\'s approval.');
        header('Location: /procurement.php');
        exit;
    }
    // (retired Accountant approval block removed in v2.3.2)

    // ---- Unknown action -------------------------------------------
    setFlash('error', 'Unknown procurement action.');
    header('Location: /procurement.php');
    exit;
}

// ==================================================================
 // GET: list + detail + metrics
// ==================================================================
$statusFilter = $_GET['filter'] ?? 'all';

// ---- Search & date-range filter (shared contract: q, from, to) ----
$filter = read_filter_params();
foreach ($filter['errors'] as $fe) {
    setFlash('warning', $fe);
}

$conditions = [];
$args = [];
if ($statusFilter === 'pending') {
    $conditions[] = "p.status NOT IN ('Finalized', 'Rejected')";
} elseif ($statusFilter === 'manager') {
    $conditions[] = "p.status IN ('Pending Manager Review', 'Pending Approval')";
} elseif ($statusFilter === 'accountant') {
    $conditions[] = "p.status = 'Pending Accountant Review'";
} elseif ($statusFilter === 'finalized') {
    $conditions[] = "p.status = 'Finalized'";
} elseif ($statusFilter === 'rejected') {
    $conditions[] = "p.status = 'Rejected'";
}
if ($filter['from'] !== '') { $conditions[] = "p.date >= :date_from"; $args[':date_from'] = $filter['from']; }
if ($filter['to'] !== '') { $conditions[] = "p.date <= :date_to"; $args[':date_to'] = $filter['to']; }
if ($filter['q'] !== '') {
    $like = '%' . $filter['q'] . '%';
    $conditions[] = "(p.reference_no LIKE :q1 OR p.supplier LIKE :q2 OR p.item_name LIKE :q3 OR p.category LIKE :q4 OR p.status LIKE :q5 OR u.name LIKE :q6 OR CAST(p.total_cost AS CHAR) LIKE :q7)";
    for ($i = 1; $i <= 7; $i++) { $args[":q{$i}"] = $like; }
}

$query = "
    SELECT p.*, u.name AS submitter_name
    FROM procurement_entries p
    LEFT JOIN users u ON p.submitted_by = u.id
    " . ($conditions ? 'WHERE ' . implode(' AND ', $conditions) : '') . "
    ORDER BY p.date DESC, p.id DESC
";
$stmtReq = $db->prepare($query);
foreach ($args as $k => $v) { $stmtReq->bindValue($k, $v); }
$stmtReq->execute();
$requisitions = $stmtReq->fetchAll();

// Detail view
$viewId = isset($_GET['view_id']) ? (int)$_GET['view_id'] : null;
$viewItem = null;
if ($viewId) {
    $vStmt = $db->prepare("
        SELECT p.*, u.name AS submitter_name,
               manager.name AS manager_decider_name,
               accountant.name AS accountant_decider_name
        FROM procurement_entries p
        LEFT JOIN users u ON p.submitted_by = u.id
        LEFT JOIN users manager ON p.manager_approved_by = manager.id
        LEFT JOIN users accountant ON p.accountant_approved_by = accountant.id
        WHERE p.id = :id
    ");
    $vStmt->execute([':id' => $viewId]);
    $viewItem = $vStmt->fetch();
}

// Summary metrics
$totalReqCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries")->fetchColumn();
$pendingReqCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status NOT IN ('Finalized', 'Rejected')")->fetchColumn();
$pendingManagerCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status IN ('Pending Manager Review', 'Pending Approval')")->fetchColumn();
$pendingAccountantCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status = 'Pending Accountant Review'")->fetchColumn();
$finalizedReqCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status = 'Finalized'")->fetchColumn();
$rejectedReqCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status = 'Rejected'")->fetchColumn();
$totalFinalizedValue = (float)$db->query("SELECT COALESCE(SUM(total_cost), 0) FROM procurement_entries WHERE status = 'Finalized'")->fetchColumn();
$pendingValue = (float)$db->query("SELECT COALESCE(SUM(total_cost), 0) FROM procurement_entries WHERE status NOT IN ('Finalized', 'Rejected')")->fetchColumn();

$showNewForm = isset($_GET['action']) && $_GET['action'] === 'new' && $currentUserRole === 'Procurement Officer';

include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 class="page-title">Procurement Records</h2>
            <p class="page-subtitle">
                Procurement Officer submits &rarr; Manager approves &rarr; Accountant final-approves &rarr; closed records are locked
            </p>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <?php if ($currentUserRole === 'Procurement Officer'): ?>
                <?php if (!$showNewForm): ?>
                    <a href="/procurement.php?action=new" class="btn btn-primary">+ Submit Procurement Record</a>
                <?php else: ?>
                    <a href="/procurement.php" class="btn btn-secondary">&larr; Back to Records</a>
                <?php endif; ?>
            <?php elseif ($currentUserRole === 'Manager'): ?>
                <span class="badge badge-warning" style="padding:6px 12px; font-size:12px;">&#10003; Your authority: first approval</span>
            <?php elseif ($currentUserRole === 'Accountant'): ?>
                <span class="badge badge-success" style="padding:6px 12px; font-size:12px;">&#10003; Your authority: final approval</span>
            <?php else: ?>
                <span class="badge badge-secondary" style="padding:6px 12px; font-size:12px;">&#128065; Read-only view</span>
            <?php endif; ?>
        </div>
    </div>

    <?php displayFlash(); ?>

    <?php
    $extraHidden = $statusFilter !== 'all' ? ['filter' => $statusFilter] : [];
    render_filter_bar([
        'action'       => '/procurement.php',
        'q'            => $filter['q'],
        'from'         => $filter['from'],
        'to'           => $filter['to'],
        'placeholder'  => 'Search ref no, supplier, item, status, amount...',
        'reportsKey'   => 'procurement_records',
        'extraHidden'  => $extraHidden,
    ]);
    ?>

    <!-- Procurement KPI Metrics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Awaiting Manager</span>
                <span style="font-size:18px;">&#9203;</span>
            </div>
            <div class="stat-value" style="color:var(--warning);"><?= $pendingManagerCount ?></div>
            <div class="stat-desc">Submitted by the Procurement Officer, awaiting first approval</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Approved (payment pending)</span>
                <span style="font-size:18px;">&#128176;</span>
            </div>
            <div class="stat-value" style="color:var(--primary);"><?= $finalizedReqCount ?></div>
            <div class="stat-desc">Finally approved - the Accountant arranges payment via Cash Requests</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Approved &amp; Finalized</span>
                <span style="font-size:18px;">&#10003;</span>
            </div>
            <div class="stat-value" style="color:var(--success);"><?= $finalizedReqCount ?></div>
            <div class="stat-desc"><?= formatMoney($totalFinalizedValue) ?> authorized spend (TZS)</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Rejected</span>
                <span style="font-size:18px;">&#10007;</span>
            </div>
            <div class="stat-value" style="color:var(--danger);"><?= $rejectedReqCount ?></div>
            <div class="stat-desc">Returned with reasons &bull; <?= $totalReqCount ?> records all-time</div>
        </div>
    </div>

    <!-- Submit Procurement Record Form (Procurement Officer only) -->
    <?php if ($showNewForm): ?>
        <div class="card" style="border: 2px solid var(--primary-border);">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Submit Procurement Record</h3>
                    <p class="card-subtitle">Records what has been procured &mdash; it then appears on the Manager's dashboard for approval, and finally on the Accountant's</p>
                </div>
                <a href="/procurement.php" class="btn btn-secondary btn-sm">&times; Cancel</a>
            </div>

            <form method="POST" action="/procurement.php">
                <input type="hidden" name="action" value="create_request">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="supplier">Supplier / Vendor *</label>
                        <input type="text" id="supplier" name="supplier" required placeholder="e.g. Apex Industrial Steel Ltd." class="form-control"
                               minlength="2" maxlength="150" data-required-error="Supplier is required.">
                    </div>

                    <div class="form-group">
                        <label for="item_name">Item Specification *</label>
                        <input type="text" id="item_name" name="item_name" required placeholder="e.g. Broom Bristles (Grade A)" class="form-control"
                               minlength="2" maxlength="255" data-required-error="Item specification is required.">
                    </div>

                    <div class="form-group">
                        <label for="category">Category *</label>
                        <select id="category" name="category" class="form-control" required>
                            <?php foreach (PROCUREMENT_CATEGORIES as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="quantity">Quantity *</label>
                        <input type="number" id="quantity" name="quantity" required min="0.01" step="0.01" value="1" class="form-control"
                               data-required-error="Quantity is required." data-min-error="Quantity must be greater than zero.">
                    </div>

                    <div class="form-group">
                        <label for="unit">Unit of Measure *</label>
                        <select id="unit" name="unit" class="form-control" required data-required-error="Unit of measure is required.">
                            <?php foreach (PROCUREMENT_UNITS as $u): ?>
                                <option value="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($u) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="unit_cost">Unit Price (TZS) *</label>
                        <input type="number" id="unit_cost" name="unit_cost" required min="1" step="1" value="100000" class="form-control"
                               data-required-error="Unit price is required." data-min-error="Unit price must be at least TZS 1.">
                    </div>

                    <div class="form-group">
                        <label for="req_date">Procurement Date *</label>
                        <input type="date" id="req_date" name="req_date" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" class="form-control"
                               data-required-error="Procurement date is required." data-max-error="Date cannot be in the future.">
                    </div>
                </div>

                <div style="margin-top:10px; padding:10px 14px; background:var(--bg-surface-subtle); border-radius:var(--radius-md); font-size:13px; color:var(--text-muted);">
                    Estimated total: <strong id="req-total-preview" style="color:var(--primary);"><?= formatMoney(100000) ?></strong>
                    <span style="font-size:12px;">(quantity &times; unit price &mdash; finalized on the server)</span>
                </div>

                <div style="margin-top:16px; display:flex; justify-content:flex-end; gap:10px;">
                    <a href="/procurement.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Submit for Manager Approval &rarr;</button>
                </div>
            </form>
        </div>

        <script>
        (function () {
            var qty = document.getElementById('quantity');
            var cost = document.getElementById('unit_cost');
            var out = document.getElementById('req-total-preview');
            function fmt(n) { return 'TZS ' + Number(n).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
            function recalc() {
                var t = (parseFloat(qty.value) || 0) * (parseFloat(cost.value) || 0);
                out.textContent = fmt(t);
            }
            qty.addEventListener('input', recalc);
            cost.addEventListener('input', recalc);
        })();
        </script>
    <?php endif; ?>

    <!-- Record Detail View -->
    <?php if ($viewItem): ?>
        <?php
        $canManagerDecide = $isOpenStatus($viewItem['status'])
            && $currentUserRole === 'Manager'
            && in_array($viewItem['status'], ['Pending Manager Review', 'Pending Approval'], true);
        ?>
        <div class="card" style="border: 2px solid #3b82f6; background-color: #ffffff;">
            <div class="card-header">
                <div>
                    <h3 class="card-title">
                        Record: <span class="mono"><?= htmlspecialchars($viewItem['reference_no']) ?></span>
                        <?= getProcurementStatusBadge($viewItem['status']) ?>
                    </h3>
                    <p class="card-subtitle">
                        Submitted on <?= formatDate($viewItem['date']) ?> by <?= htmlspecialchars($viewItem['submitter_name'] ?? 'Unknown user') ?>
                        <?php if ($viewItem['manager_decider_name']): ?>
                            &bull; Manager: <?= htmlspecialchars($viewItem['manager_decider_name']) ?>
                        <?php endif; ?>
                        <?php if ($viewItem['accountant_decider_name']): ?>
                            &bull; Final approval: <?= htmlspecialchars($viewItem['accountant_decider_name']) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="/procurement.php" class="btn btn-secondary btn-sm">&times; Close Detail</a>
            </div>

            <!-- Item Details Breakdown -->
            <div class="details-list">
                <div class="detail-item">
                    <span class="detail-term">Supplier</span>
                    <span class="detail-desc"><?= htmlspecialchars($viewItem['supplier']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-term">Item Specification</span>
                    <span class="detail-desc"><?= htmlspecialchars($viewItem['item_name']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-term">Category</span>
                    <span class="detail-desc"><?= htmlspecialchars($viewItem['category']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-term">Order Quantity</span>
                    <span class="detail-desc"><?= formatNumber((float)$viewItem['quantity']) ?> <?= htmlspecialchars($viewItem['unit']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-term">Unit Price</span>
                    <span class="detail-desc"><?= formatMoney((float)$viewItem['unit_cost']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-term">Total Value</span>
                    <span class="detail-desc" style="color:var(--primary); font-size:16px;"><?= formatMoney((float)$viewItem['total_cost']) ?></span>
                </div>
            </div>

            <!-- Approval Notes History -->
            <div style="margin: 18px 0; display:flex; flex-direction:column; gap:10px;">
                <?php if (!empty($viewItem['requisition_status'])): ?>
                    <?php $reqBadge = $viewItem['requisition_status'] === 'CEO Approved' ? 'badge-success' : ($viewItem['requisition_status'] === 'CEO Rejected' ? 'badge-danger' : 'badge-warning'); ?>
                    <div style="padding:10px 14px; background:#fefce8; border-left:4px solid #eab308; border-radius:4px;">
                        <strong>Procurement Requisition (authority to procure):</strong>
                        <span class="badge <?= $reqBadge ?>" style="margin-left:6px; font-size:10px; vertical-align:middle;">
                            <?= $viewItem['requisition_status'] === 'CEO Approved' ? 'Approved by C.E.O' : ($viewItem['requisition_status'] === 'CEO Rejected' ? 'Rejected by C.E.O' : 'Awaiting C.E.O approval') ?>
                        </span>
                        <?php if ($viewItem['requisition_notes']): ?><p style="margin-top:2px; font-size:13px;"><?= htmlspecialchars($viewItem['requisition_notes']) ?></p><?php endif; ?>
                        <?php if ($currentUserRole === 'CEO' && $viewItem['requisition_status'] === 'Pending CEO Approval'): ?>
                            <form method="POST" action="/procurement.php" style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                                <input type="hidden" name="action" value="requisition_decision">
                                <input type="hidden" name="po_id" value="<?= (int)$viewItem['id'] ?>">
                                <input class="form-control" type="text" name="notes" placeholder="Optional remarks" maxlength="255" style="flex:1; min-width:180px;">
                                <button type="submit" name="decision" value="approve" class="btn btn-success">&#10003; Approve requisition</button>
                                <button type="submit" name="decision" value="reject" class="btn btn-danger">&#10007; Reject</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($viewItem['manager_notes']): ?>
                    <div style="padding:10px 14px; background:#eff6ff; border-left:4px solid #3b82f6; border-radius:4px;">
                        <strong>Manager Review:</strong>
                        <p style="margin-top:2px; font-size:13px;"><?= htmlspecialchars($viewItem['manager_notes']) ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($viewItem['accountant_notes']): ?>
                    <div style="padding:10px 14px; background:#ecfdf5; border-left:4px solid #10b981; border-radius:4px;">
                        <strong>Accountant Final Approval:</strong>
                        <p style="margin-top:2px; font-size:13px;"><?= htmlspecialchars($viewItem['accountant_notes']) ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($viewItem['rejection_reason']): ?>
                    <div class="alert alert-danger" style="margin-bottom:0;">
                        <div>
                            <strong>Rejection Notice:</strong>
                            <p style="margin-top:2px;"><?= htmlspecialchars($viewItem['rejection_reason']) ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Manager Approval Actions -->
            <?php if ($canManagerDecide): ?>
                <div style="padding:16px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:var(--radius-md); margin-top:16px;">
                    <h4 style="font-size:14px; font-weight:700; margin-bottom:8px;">Manager Review (first approval)</h4>
                    <form method="POST" action="/procurement.php">
                        <input type="hidden" name="action" value="manager_decision">
                        <input type="hidden" name="po_id" value="<?= (int)$viewItem['id'] ?>">
                        <div class="form-group">
                            <label for="manager_notes">Decision Notes / Remarks</label>
                            <input type="text" id="manager_notes" name="notes" placeholder="Optional remarks attached to your decision" class="form-control" maxlength="500"
                                   data-plaintext>
                        </div>
                        <div style="display:flex; gap:10px;">
                            <button type="submit" name="decision" value="approve" class="btn btn-success">&#10003; Approve &rarr; send to Accountant</button>
                            <button type="submit" name="decision" value="reject" class="btn btn-danger">&#10007; Reject Record</button>
                        </div>
                    </form>
                </div>
            <?php elseif ($currentUserRole === 'Procurement Officer' && $viewItem['status'] === 'Finalized' && (int)$viewItem['inventory_received'] === 0): ?>
                <div style="padding:16px; background:#f0fdf4; border:1px solid #86efac; border-radius:var(--radius-md); margin-top:16px;">
                    <h4 style="font-size:14px; font-weight:700; margin-bottom:8px;">Receive into Inventory</h4>
                    <p style="font-size:13px; margin-bottom:10px;">This record is finalized. Receive the goods into inventory so they become available to the floor.</p>
                    <form method="POST" action="/procurement.php">
                        <input type="hidden" name="action" value="receive_to_inventory">
                        <input type="hidden" name="po_id" value="<?= (int)$viewItem['id'] ?>">
                        <button type="submit" class="btn btn-success">&#128230; Receive <?= htmlspecialchars($viewItem['quantity'] . ' ' . $viewItem['unit']) ?> into inventory</button>
                    </form>
                </div>
            <?php elseif ($viewItem['status'] === 'Finalized' && (int)$viewItem['inventory_received'] === 1): ?>
                <div class="alert alert-success" style="margin-top:16px;">&#128230; Received into inventory.</div>
            <?php elseif ($canAccountantDecide): ?>
                <div style="padding:16px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:var(--radius-md); margin-top:16px;">
                    <h4 style="font-size:14px; font-weight:700; margin-bottom:8px;">Accountant Final Approval (closes &amp; locks the record)</h4>
                    <form method="POST" action="/procurement.php">
                        <input type="hidden" name="action" value="accountant_decision">
                        <input type="hidden" name="po_id" value="<?= (int)$viewItem['id'] ?>">
                        <div class="form-group">
                            <label for="accountant_notes">Decision Notes / Remarks</label>
                            <input type="text" id="accountant_notes" name="notes" placeholder="Optional remarks attached to your decision" class="form-control" maxlength="500"
                                   data-plaintext>
                        </div>
                        <div style="display:flex; gap:10px;">
                            <button type="submit" name="decision" value="approve" class="btn btn-success">&#10003; Final Approval</button>
                            <button type="submit" name="decision" value="reject" class="btn btn-danger">&#10007; Reject Record</button>
                        </div>
                    </form>
                </div>
            <?php elseif ($viewItem['status'] === 'Finalized'): ?>
                <div class="alert alert-success" style="margin-top:16px; margin-bottom:0;">
                    <span>&#10003; Finally approved by the Manager. This record is locked. Payment is arranged by the Accountant through <a href="/cash_requests.php">Cash Requests</a>.</span>
                </div>
            <?php elseif ($viewItem['status'] === 'Rejected'): ?>
                <div class="alert alert-warning" style="margin-top:16px; margin-bottom:0;">
                    <span>&#10007; This record was rejected and is closed.</span>
                </div>
            <?php elseif ($isOpenStatus($viewItem['status'])): ?>
                <div class="alert alert-info" style="margin-top:16px; margin-bottom:0;">
                    <span>&#9203; Awaiting the Manager's review on the dashboard.</span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Status Filter Bar -->
    <div class="card" style="padding:14px 20px; margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div class="btn-group">
                <a href="/procurement.php?filter=all" class="btn btn-sm <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">All (<?= count($requisitions) ?>)</a>
                <a href="/procurement.php?filter=pending" class="btn btn-sm <?= $statusFilter === 'pending' ? 'btn-primary' : 'btn-secondary' ?>">Open</a>
                <a href="/procurement.php?filter=manager" class="btn btn-sm <?= $statusFilter === 'manager' ? 'btn-primary' : 'btn-secondary' ?>">Awaiting Manager</a>
                <a href="/procurement.php?filter=finalized" class="btn btn-sm <?= $statusFilter === 'finalized' ? 'btn-primary' : 'btn-secondary' ?>">Approved</a>
                <a href="/procurement.php?filter=rejected" class="btn btn-sm <?= $statusFilter === 'rejected' ? 'btn-primary' : 'btn-secondary' ?>">Rejected</a>
            </div>
            <div style="font-size:12px; color:var(--text-subtle);">
                Displaying <strong><?= count($requisitions) ?></strong> records<?= ($filter['from'] !== '' || $filter['to'] !== '' || $filter['q'] !== '') ? ' (date/search filtered)' : '' ?>
            </div>
        </div>
    </div>

    <!-- Records Table -->
    <div class="card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Ref No.</th>
                        <th>Date</th>
                        <th>Supplier / Vendor</th>
                        <th>Item Details</th>
                        <th>Qty &amp; Unit</th>
                        <th>Total Cost (TZS)</th>
                        <th>Status</th>
                        <th>Submitted By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requisitions)): ?>
                        <tr><td colspan="9" class="empty-state">No procurement records match the selected search / date filter.</td></tr>
                    <?php else: ?>
                        <?php foreach ($requisitions as $po): ?>
                            <tr>
                                <td class="mono"><strong><?= htmlspecialchars($po['reference_no']) ?></strong></td>
                                <td><?= formatDate($po['date']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($po['supplier']) ?></strong>
                                    <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($po['category']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($po['item_name']) ?></td>
                                <td><?= formatNumber((float)$po['quantity']) ?> <?= htmlspecialchars($po['unit']) ?></td>
                                <td><strong style="color:var(--text-main);"><?= formatMoney((float)$po['total_cost']) ?></strong></td>
                                <td><?= getProcurementStatusBadge($po['status']) ?></td>
                                <td><?= htmlspecialchars($po['submitter_name'] ?? '&mdash;') ?></td>
                                <td>
                                    <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                                        <a href="/procurement.php?view_id=<?= (int)$po['id'] ?>" class="btn btn-secondary btn-sm">
                                            Inspect &rarr;
                                        </a>
                                        <?php if ($currentUserRole === 'Manager' && $isOpenStatus($po['status']) && in_array($po['status'], ['Pending Manager Review', 'Pending Approval'], true)): ?>
                                            <form method="POST" action="/procurement.php" style="display:inline;">
                                                <input type="hidden" name="action" value="manager_decision">
                                                <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>">
                                                <input type="hidden" name="notes" value="">
                                                <button type="submit" name="decision" value="approve" class="btn btn-success btn-sm"
                                                        onclick="return confirm('Give FINAL approval to procurement record <?= htmlspecialchars($po['reference_no']) ?>? This locks the record and clears it for payment.');">&#10003;</button>
                                                <button type="submit" name="decision" value="reject" class="btn btn-danger btn-sm"
                                                        onclick="return confirm('Reject procurement record <?= htmlspecialchars($po['reference_no']) ?>? A rejection reason will be recorded.');">&#10007;</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
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
