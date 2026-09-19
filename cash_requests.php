<?php
/**
 * U EPMS - Cash Requests (v2.3)
 * Per client workflow:
 *   1. Procurement Officer / Manager sends a cash request to the C.E.O
 *   2. C.E.O approves - the request is automatically sent to the Accountant
 *   3. Accountant provides the money to the requester
 *   4. Requester confirms receipt (closing the loop)
 * The Accountant NEVER creates or approves cash requests - they only disburse
 * what the C.E.O approved, and they cannot see or log production info.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole(['CEO', 'Manager', 'Accountant', 'Procurement Officer']);

$pageTitle = 'Cash Requests';
$activeNav = 'cash_requests';

$currentUserRole = $_SESSION['user_role'];
$currentUserId   = (int)$_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

$isCEO = $currentUserRole === 'CEO';
$isAcc = $currentUserRole === 'Accountant';
$canRequest = in_array($currentUserRole, ['Procurement Officer', 'Manager'], true);

/* ==================================================================
 * POST actions
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. PO/Manager requests cash
    if ($action === 'request_cash') {
        if (!$canRequest) {
            setFlash('error', 'Only the Procurement Officer or the Manager can send a cash request to the C.E.O.');
            header('Location: /cash_requests.php');
            exit;
        }
        $errors = [];
        $amount  = field_float($errors, 'amount', 'Amount', 100) ?? 0;
        $purpose = field_text($errors, 'purpose', 'Purpose', true, 3, 255) ?? '';
        if ($errors) {
            redirectWithErrors('/cash_requests.php', $errors);
        }
        $no = 'CSH-' . date('Y') . '-' . str_pad((string)(((int)$db->query('SELECT COUNT(*) FROM cash_requests')->fetchColumn()) + 1), 4, '0', STR_PAD_LEFT);
        $stmt = $db->prepare("INSERT INTO cash_requests (request_no, requested_by, requester_role, amount, purpose, status) VALUES (:no, :by, :role, :amt, :p, 'Pending CEO Approval')");
        $stmt->execute([':no' => $no, ':by' => $currentUserId, ':role' => $currentUserRole, ':amt' => $amount, ':p' => $purpose]);
        logAudit($db, 'CASH_REQUEST_CREATED', 'CASH_REQUEST', $no, "{$currentUserName} ({$currentUserRole}) requested " . formatMoney($amount) . " - {$purpose}");
        notifyRoles($db, ['CEO'], 'Cash request awaiting your approval', "{$no}: {$currentUserName} ({$currentUserRole}) requests " . formatMoney($amount) . " for {$purpose}.", '/cash_requests.php');
        setFlash('success', "Cash request {$no} sent to the C.E.O.");
        header('Location: /cash_requests.php');
        exit;
    }

    // 2. CEO decision - approval automatically forwards to the Accountant
    if ($action === 'ceo_decision') {
        if (!$isCEO) {
            setFlash('error', 'Only the C.E.O approves cash requests.');
            header('Location: /cash_requests.php');
            exit;
        }
        $id = (int)($_POST['request_id'] ?? 0);
        $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approve' : 'reject';
        $notes = mb_substr(trim((string)($_POST['ceo_notes'] ?? '')), 0, 255);
        $req = $db->query('SELECT * FROM cash_requests WHERE id = ' . $id)->fetch();
        if (!$req || $req['status'] !== 'Pending CEO Approval') {
            setFlash('error', 'That request is not awaiting your approval.');
            header('Location: /cash_requests.php');
            exit;
        }
        if ($decision === 'approve') {
            $db->prepare("UPDATE cash_requests SET status = 'Approved - Sent to Accountant', ceo_decision_by = :by, ceo_decision_at = CURRENT_TIMESTAMP, ceo_notes = :n WHERE id = :id")
               ->execute([':by' => $currentUserId, ':n' => $notes !== '' ? $notes : 'Approved by C.E.O ' . $currentUserName, ':id' => $id]);
            logAudit($db, 'CASH_REQUEST_APPROVED', 'CASH_REQUEST', $req['request_no'], "C.E.O {$currentUserName} approved {$req['request_no']} (" . formatMoney((float)$req['amount']) . ") - automatically sent to the Accountant for disbursement.");
            notifyRoles($db, ['Accountant'], 'Cash request to disburse', "{$req['request_no']}: provide " . formatMoney((float)$req['amount']) . " to {$req['requester_role']} (approved by the C.E.O).", '/cash_requests.php');
            notifyUser($db, (int)$req['requested_by'], 'Cash request approved', "{$req['request_no']} (" . formatMoney((float)$req['amount']) . ") approved - collect the money from the Accountant.", '/cash_requests.php');
            setFlash('success', "{$req['request_no']} approved and automatically sent to the Accountant.");
        } else {
            $db->prepare("UPDATE cash_requests SET status = 'Rejected by CEO', ceo_decision_by = :by, ceo_decision_at = CURRENT_TIMESTAMP, ceo_notes = :n WHERE id = :id")
               ->execute([':by' => $currentUserId, ':n' => $notes !== '' ? $notes : 'Rejected by C.E.O', ':id' => $id]);
            logAudit($db, 'CASH_REQUEST_REJECTED', 'CASH_REQUEST', $req['request_no'], "C.E.O {$currentUserName} rejected {$req['request_no']}.");
            notifyUser($db, (int)$req['requested_by'], 'Cash request rejected', "{$req['request_no']} was rejected by the C.E.O" . ($notes !== '' ? ": {$notes}" : '.'), '/cash_requests.php');
            setFlash('warning', "{$req['request_no']} rejected.");
        }
        header('Location: /cash_requests.php');
        exit;
    }

    // 3. Accountant disburses
    if ($action === 'disburse') {
        if (!$isAcc) {
            setFlash('error', 'Only the Accountant provides money on approved requests.');
            header('Location: /cash_requests.php');
            exit;
        }
        $id = (int)($_POST['request_id'] ?? 0);
        $req = $db->query('SELECT * FROM cash_requests WHERE id = ' . $id)->fetch();
        if (!$req || $req['status'] !== 'Approved - Sent to Accountant') {
            setFlash('error', 'That request is not ready for disbursement.');
            header('Location: /cash_requests.php');
            exit;
        }
        $db->prepare("UPDATE cash_requests SET status = 'Disbursed - Awaiting Confirmation', disbursed_by = :by, disbursed_at = CURRENT_TIMESTAMP WHERE id = :id")
           ->execute([':by' => $currentUserId, ':id' => $id]);
        logAudit($db, 'CASH_REQUEST_DISBURSED', 'CASH_REQUEST', $req['request_no'], "Accountant {$currentUserName} disbursed " . formatMoney((float)$req['amount']) . " on {$req['request_no']}.");
        notifyUser($db, (int)$req['requested_by'], 'Money ready', "{$req['request_no']}: " . formatMoney((float)$req['amount']) . " has been provided by the Accountant. Confirm when you receive it.", '/cash_requests.php');
        setFlash('success', "{$req['request_no']} disbursed - awaiting the receiver's confirmation.");
        header('Location: /cash_requests.php');
        exit;
    }

    // 4. Requester confirms receipt
    if ($action === 'confirm_receipt') {
        $id = (int)($_POST['request_id'] ?? 0);
        $req = $db->query('SELECT * FROM cash_requests WHERE id = ' . $id)->fetch();
        if (!$req || (int)$req['requested_by'] !== $currentUserId || $req['status'] !== 'Disbursed - Awaiting Confirmation') {
            setFlash('error', 'That request is not awaiting your confirmation.');
            header('Location: /cash_requests.php');
            exit;
        }
        $db->prepare("UPDATE cash_requests SET status = 'Completed', confirmed_received_by = :by, confirmed_received_at = CURRENT_TIMESTAMP WHERE id = :id")
           ->execute([':by' => $currentUserId, ':id' => $id]);
        logAudit($db, 'CASH_REQUEST_CONFIRMED', 'CASH_REQUEST', $req['request_no'], "{$currentUserName} confirmed receipt of " . formatMoney((float)$req['amount']) . " on {$req['request_no']}.");
        notifyUser($db, (int)$req['disbursed_by'], 'Receipt confirmed', "{$req['request_no']} ({$req['amount']}) receipt confirmed by {$currentUserName}.", '/cash_requests.php');
        setFlash('success', "{$req['request_no']} confirmed. Loop closed.");
        header('Location: /cash_requests.php');
        exit;
    }

    setFlash('error', 'Unknown cash request action.');
    header('Location: /cash_requests.php');
    exit;
}

/* ==================================================================
 * Data
 * ================================================================== */
$requests = $db->query(
    'SELECT c.*, ru.name AS requester_name, cu.name AS ceo_name, du.name AS disburser_name
     FROM cash_requests c
     JOIN users ru ON ru.id = c.requested_by
     LEFT JOIN users cu ON cu.id = c.ceo_decision_by
     LEFT JOIN users du ON du.id = c.disbursed_by
     ORDER BY c.id DESC LIMIT 30'
)->fetchAll();

$badge = function (string $s): string {
    return match ($s) {
        'Completed' => '<span class="badge badge-success">Completed</span>',
        'Rejected by CEO' => '<span class="badge badge-danger">Rejected</span>',
        'Approved - Sent to Accountant' => '<span class="badge badge-info">With Accountant</span>',
        'Disbursed - Awaiting Confirmation' => '<span class="badge badge-warning">Awaiting receipt</span>',
        default => '<span class="badge badge-warning">Awaiting C.E.O</span>',
    };
};
?>
<?php include __DIR__ . '/components/header.php'; ?>
<main class="page-container">
    <?php displayFlash(); ?>

    <div style="margin-bottom:18px;">
        <h1 style="font-size:24px; font-weight:800;">Cash Requests</h1>
        <p style="color:var(--text-secondary, #64748b); font-size:13px;">
            <?php if ($canRequest): ?>Send a request to the C.E.O; once approved it goes straight to the Accountant, who provides the money.
            <?php elseif ($isCEO): ?>Approve or reject. Approvals go to the Accountant automatically.
            <?php else: ?>Disburse what the C.E.O approved. You never create or approve requests.<?php endif; ?>
        </p>
    </div>

    <?php if ($canRequest): ?>
    <div class="card" style="padding:16px; margin-bottom:18px;">
        <h3 class="card-title" style="font-size:15px; font-weight:700; margin-bottom:10px;">Request Cash from the C.E.O</h3>
        <form method="post" action="/cash_requests.php" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
            <input type="hidden" name="action" value="request_cash">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div style="width:180px;"><label style="font-size:11px;">Amount (TZS) *</label>
                <input class="form-control" type="number" name="amount" min="100" step="0.01" required></div>
            <div style="flex:1; min-width:220px;"><label style="font-size:11px;">Purpose *</label>
                <input class="form-control" name="purpose" required minlength="3" maxlength="255" placeholder="e.g. Payment for bristle granules delivery"></div>
            <button class="btn btn-primary" type="submit">Send to C.E.O &rarr;</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card" style="padding:16px;">
        <h3 class="card-title" style="font-size:15px; font-weight:700; margin-bottom:10px;">Requests</h3>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead><tr>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Request</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">From</th>
                    <th style="text-align:right; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Amount</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Purpose</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Status</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Action</th>
                </tr></thead>
                <tbody>
                <?php if (!$requests): ?>
                    <tr><td colspan="6" style="padding:14px; color:var(--text-secondary, #64748b);">No cash requests yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0); font-weight:600;"><?= htmlspecialchars($r['request_no']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars($r['requester_name']) ?> <span style="font-size:11px; color:var(--text-secondary,#94a3b8);">(<?= htmlspecialchars($r['requester_role']) ?>)</span></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0); text-align:right; font-weight:700;"><?= formatMoney((float)$r['amount']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars($r['purpose']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= $badge($r['status']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);">
                            <?php if ($isCEO && $r['status'] === 'Pending CEO Approval'): ?>
                                <form method="post" action="/cash_requests.php" style="display:inline-flex; gap:4px;">
                                    <input type="hidden" name="action" value="ceo_decision">
                                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <button class="btn btn-success" name="decision" value="approve" style="font-size:11px; padding:4px 10px;">Approve</button>
                                    <button class="btn btn-danger" name="decision" value="reject" style="font-size:11px; padding:4px 10px;">Reject</button>
                                </form>
                            <?php elseif ($isAcc && $r['status'] === 'Approved - Sent to Accountant'): ?>
                                <form method="post" action="/cash_requests.php" style="display:inline;">
                                    <input type="hidden" name="action" value="disburse">
                                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <button class="btn btn-primary" style="font-size:11px; padding:4px 10px;"><?= formatMoney((float)$r['amount']) ?> &rarr; Provide money</button>
                                </form>
                            <?php elseif ((int)$r['requested_by'] === $currentUserId && $r['status'] === 'Disbursed - Awaiting Confirmation'): ?>
                                <form method="post" action="/cash_requests.php" style="display:inline;">
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
</main>
<?php include __DIR__ . '/components/footer.php'; ?>
