<?php
/**
 * U EPMS - Petty Cash Floats, Requests & Expense Ledger
 *
 * v2.2 client flow (2026-09):
 *   1. A requester (Procurement Officer, Manager or Accountant) SUBMITS a
 *      petty cash request (purpose, amount, payee, cash or supplier payment).
 *   2. The CEO VERIFIES and approves or rejects the request.
 *   3. On approval the CEO issues the float (or authorizes the supplier
 *      payment) and the Accountant - still the only cash handler - draws
 *      against it and records expenses.
 *   4. Expenses recorded by the Accountant are CONFIRMED by the CEO or
 *      Manager (two signatures).
 *   5. Finished floats are CLOSED with a reconciliation statement and the
 *      CEO countersigns. One open float per holder.
 *   6. A monthly budget warns at 80 percent (app_settings).
 *
 * Legacy direct issuance (CEO -> float) still works for genuine walk-ups.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/components/filter_bar.php';

requireRole(['CEO', 'Manager', 'Accountant', 'Procurement Officer']);

$pageTitle = 'Petty Cash Management';
$activeNav = 'petty_cash';

$currentUserRole = $_SESSION['user_role'];
$currentUserId = $_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Issue New Petty Cash Voucher (CEO ONLY - Accountants may NOT issue float)
    if ($action === 'issue_float') {
        if ($currentUserRole !== 'CEO') {
            setFlash('error', 'Unauthorized: Only the CEO can issue new petty cash floats. Recording expenses is the Accountant and Manager authority.');
            header('Location: /petty_cash.php');
            exit;
        }

        $errors = [];
        $issuedTo   = field_int($errors, 'issued_to', 'Designated custodian', 1) ?? 0;
        $amount     = field_float($errors, 'amount', 'Float amount', 800000, 7000000) ?? 0;
        $purpose    = field_text($errors, 'purpose', 'Operational purpose', true, 5, 255) ?? '';
        $issuedDate = field_date($errors, 'issued_date', 'Date of issuance', true, true) ?? date('Y-m-d');
        $voucherNo  = 'PCV-' . date('Y') . '-' . str_pad((string)rand(100, 999), 3, '0', STR_PAD_LEFT);

        if ($errors) {
            redirectWithErrors('/petty_cash.php?action=issue', $errors);
        }

        // The ONLY permitted float holder is an active Accountant
        $holderStmt = $db->prepare("SELECT role, status, name FROM users WHERE id = :id");
        $holderStmt->execute([':id' => $issuedTo]);
        $holder = $holderStmt->fetch();
        if (!$holder || $holder['role'] !== 'Accountant' || $holder['status'] !== 'Active') {
            setFlash('error', 'Only an active Accountant can be designated as a petty cash float holder.');
            header('Location: /petty_cash.php?action=issue');
            exit;
        }

        $stmt = $db->prepare("
            INSERT INTO petty_cash_issuances (voucher_no, issued_to, issued_by, amount, purpose, status, issued_date, created_at)
            VALUES (:vno, :to, :by, :amt, :purpose, 'Active', :idate, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':vno'     => $voucherNo,
            ':to'      => $issuedTo,
            ':by'      => $currentUserId,
            ':amt'     => $amount,
            ':purpose' => $purpose,
            ':idate'   => $issuedDate,
        ]);

        logAudit($db, 'PETTY_CASH_ISSUED', 'PETTY_CASH', $voucherNo, "CEO {$currentUserName} issued float of " . formatMoney($amount) . " under voucher {$voucherNo}");
        setFlash('success', "Petty cash voucher {$voucherNo} for " . formatMoney($amount) . " issued successfully.");
        header('Location: /petty_cash.php');
        exit;
    }

    // 2. Record Expense Against a Voucher (Accountant only, v2.3.2)
    if ($action === 'record_expense') {
        if ($currentUserRole !== 'Accountant') {
            setFlash('error', 'Unauthorized: Only the Accountant records expenses against petty cash vouchers.');
            header('Location: /petty_cash.php');
            exit;
        }

        $errors = [];
        $issuanceId  = field_int($errors, 'issuance_id', 'Float voucher', 1) ?? 0;
        $expenseDate = field_date($errors, 'expense_date', 'Expense date', true, true) ?? date('Y-m-d');
        $category    = field_choice($errors, 'category', 'Expense category', [
            'Hardware & Fasteners', 'Shop Consumables', 'Equipment Maintenance',
            'Logistics & Freight', 'Safety & PPE',
        ]) ?? '';
        $description = field_text($errors, 'description', 'Expense description', true, 3, 255) ?? '';
        $amount      = field_float($errors, 'amount', 'Expense amount', 100) ?? 0;
        $receiptNo   = field_text($errors, 'receipt_no', 'Receipt / tax invoice number', true, 3, 50) ?? '';

        if ($errors) {
            redirectWithErrors('/petty_cash.php?action=expense', $errors);
        }

        // Duplicate receipt guard: one receipt number can never be claimed twice
        $dupStmt = $db->prepare("SELECT COUNT(*) FROM petty_cash_expenses WHERE receipt_no = :rc");
        $dupStmt->execute([':rc' => $receiptNo]);
        if ((int)$dupStmt->fetchColumn() > 0) {
            setFlash('error', "Receipt number '{$receiptNo}' has already been recorded - one receipt cannot be claimed twice.");
            header('Location: /petty_cash.php?action=expense');
            exit;
        }

        // Check voucher balance limit
        $vStmt = $db->prepare("
            SELECT i.*,
                   COALESCE(SUM(e.amount), 0) AS total_expensed
            FROM petty_cash_issuances i
            LEFT JOIN petty_cash_expenses e ON e.issuance_id = i.id
            WHERE i.id = :id
            GROUP BY i.id
        ");
        $vStmt->execute([':id' => $issuanceId]);
        $vInfo = $vStmt->fetch();

        if (!$vInfo) {
            setFlash('error', 'Voucher not found.');
            header('Location: /petty_cash.php');
            exit;
        }

        $availableBalance = (float)$vInfo['amount'] - (float)$vInfo['total_expensed'];
        if ($amount > $availableBalance) {
            setFlash('error', "Expense exceeds remaining voucher float balance (" . formatMoney($availableBalance) . " remaining).");
            header('Location: /petty_cash.php?action=expense');
            exit;
        }

        $stmt = $db->prepare("
            INSERT INTO petty_cash_expenses (issuance_id, expense_date, category, description, amount, receipt_no, approved_by, created_at)
            VALUES (:iid, :edate, :cat, :desc, :amt, :rec, :approver, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':iid'      => $issuanceId,
            ':edate'    => $expenseDate,
            ':cat'      => $category,
            ':desc'     => $description,
            ':amt'      => $amount,
            ':rec'      => $receiptNo,
            ':approver' => $currentUserId,
        ]);

        logAudit($db, 'PETTY_CASH_EXPENSE', 'PETTY_CASH', $vInfo['voucher_no'], "{$currentUserName} expensed " . formatMoney($amount) . " for '{$description}' [Receipt: {$receiptNo}]");
        setFlash('success', "Expense of " . formatMoney($amount) . " recorded against voucher {$vInfo['voucher_no']}.");
        header('Location: /petty_cash.php');
        exit;
    }

    // 3. Submit a petty cash request (Officer, Manager, Accountant) - client flow step 1
    if ($action === 'request_float') {
        if (!in_array($currentUserRole, ['Procurement Officer', 'Manager', 'Accountant'], true)) {
            setFlash('error', 'Only the Procurement Officer, Manager or Accountant can request petty cash.');
            header('Location: /petty_cash.php');
            exit;
        }
        $errors = [];
        $amount  = field_float($errors, 'amount', 'Requested amount', 800000, 7000000) ?? 0;
        $purpose = field_text($errors, 'purpose', 'Purpose', true, 5, 255) ?? '';
        $payee   = field_text($errors, 'payee', 'Payee / supplier', false, 0, 120) ?? '';
        $mode    = field_choice($errors, 'payment_mode', 'Payment mode', ['Cash', 'Supplier Payment']) ?? 'Cash';

        if ($errors) {
            redirectWithErrors('/petty_cash.php?action=request', $errors);
        }

        $requestNo = 'PCR-' . date('Y') . '-' . str_pad((string)(int)$db->query('SELECT COUNT(*) FROM petty_cash_requests')->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);
        $stmt = $db->prepare("
            INSERT INTO petty_cash_requests (request_no, requested_by, amount, purpose, payee, payment_mode)
            VALUES (:rno, :by, :amt, :purpose, :payee, :mode)
        ");
        $stmt->execute([':rno' => $requestNo, ':by' => $currentUserId, ':amt' => $amount, ':purpose' => $purpose, ':payee' => $payee, ':mode' => $mode]);

        logAudit($db, 'PETTY_CASH_REQUESTED', 'PETTY_CASH', $requestNo, "{$currentUserName} ({$currentUserRole}) requested " . formatMoney($amount) . " ({$mode}) for '{$purpose}' under {$requestNo}");
        notifyRoles($db, ['CEO'], 'New petty cash request', "{$currentUserName} requested " . formatMoney($amount) . " ({$mode}) - {$requestNo}: {$purpose}", '/petty_cash.php#requests');
        setFlash('success', "Request {$requestNo} submitted. The CEO has been alerted and will verify it.");
        header('Location: /petty_cash.php');
        exit;
    }

    // 4. CEO verifies / rejects a request - client flow step 2
    if ($action === 'verify_request') {
        if ($currentUserRole !== 'CEO') {
            setFlash('error', 'Only the CEO can verify petty cash requests.');
            header('Location: /petty_cash.php');
            exit;
        }
        $rid   = (int)($_POST['request_id'] ?? 0);
        $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approve' : 'reject';
        $dummyErrors = [];
        $note  = field_text($dummyErrors, 'verification_note', 'Note', false, 0, 255) ?? '';

        $rStmt = $db->prepare("SELECT r.*, u.name AS requester_name FROM petty_cash_requests r JOIN users u ON r.requested_by = u.id WHERE r.id = :id");
        $rStmt->execute([':id' => $rid]);
        $req = $rStmt->fetch();

        if (!$req || $req['status'] !== 'Pending CEO Verification') {
            setFlash('error', 'Request not found or already decided.');
            header('Location: /petty_cash.php');
            exit;
        }

        if ($decision === 'reject') {
            $db->prepare("UPDATE petty_cash_requests SET status = 'Rejected', rejection_reason = :rr, verified_by = :vb, verified_at = CURRENT_TIMESTAMP WHERE id = :id")
               ->execute([':rr' => $note !== '' ? $note : 'Rejected by CEO', ':vb' => $currentUserId, ':id' => $rid]);
            logAudit($db, 'PETTY_CASH_REQUEST_REJECTED', 'PETTY_CASH', $req['request_no'], "CEO {$currentUserName} rejected {$req['request_no']} (" . formatMoney((float)$req['amount']) . "): " . ($note !== '' ? $note : 'no reason given'));
            notifyUser($db, (int)$req['requested_by'], 'Petty cash request rejected', "Your request {$req['request_no']} (" . formatMoney((float)$req['amount']) . ') was rejected by the CEO.', '/petty_cash.php#requests');
            setFlash('warning', "Request {$req['request_no']} rejected.");
            header('Location: /petty_cash.php#requests');
            exit;
        }

        // Approve: mark verified; issuance happens in the next action
        $db->prepare("UPDATE petty_cash_requests SET status = 'Verified - Awaiting Float', verified_by = :vb, verified_at = CURRENT_TIMESTAMP, verification_note = :vn WHERE id = :id")
           ->execute([':vb' => $currentUserId, ':vn' => $note, ':id' => $rid]);
        logAudit($db, 'PETTY_CASH_REQUEST_VERIFIED', 'PETTY_CASH', $req['request_no'], "CEO {$currentUserName} verified {$req['request_no']} (" . formatMoney((float)$req['amount']) . ") for {$req['requester_name']}");
        notifyRoles($db, ['Accountant'], 'Petty cash request verified', "{$req['request_no']} (" . formatMoney((float)$req['amount']) . ") was verified by the CEO - issue the float when ready.", '/petty_cash.php#requests');
        setFlash('success', "Request {$req['request_no']} verified. The Accountant can now draw the float against it.");
        header('Location: /petty_cash.php#requests');
        exit;
    }

    // 5. Issue a float AGAINST a verified request - client flow step 3
    if ($action === 'issue_from_request') {
        if ($currentUserRole !== 'CEO') {
            setFlash('error', 'Only the CEO can issue floats against verified requests.');
            header('Location: /petty_cash.php');
            exit;
        }
        $rid = (int)($_POST['request_id'] ?? 0);
        $rStmt = $db->prepare("SELECT r.*, u.name AS requester_name FROM petty_cash_requests r JOIN users u ON r.requested_by = u.id WHERE r.id = :id");
        $rStmt->execute([':id' => $rid]);
        $req = $rStmt->fetch();

        if (!$req || $req['status'] !== 'Verified - Awaiting Float') {
            setFlash('error', 'Request not found or not in Verified state.');
            header('Location: /petty_cash.php');
            exit;
        }

        // Cash goes to the Accountant (sole cash handler); supplier payments
        // are recorded as floats drawn by the Accountant marked as paid out.
        $acctStmt = $db->prepare("SELECT id FROM users WHERE role = 'Accountant' AND status = 'Active' ORDER BY id ASC LIMIT 1");
        $acctStmt->execute();
        $accountantId = (int)$acctStmt->fetchColumn();
        if (!$accountantId) {
            setFlash('error', 'No active Accountant exists - cannot issue the float.');
            header('Location: /petty_cash.php#requests');
            exit;
        }

        $voucherNo = 'PCV-' . date('Y') . '-' . str_pad((string)(int)$db->query('SELECT COUNT(*) FROM petty_cash_issuances')->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);
        $db->beginTransaction();
        try {
            $db->prepare("
                INSERT INTO petty_cash_issuances (voucher_no, issued_to, issued_by, amount, purpose, status, issued_date, created_at)
                VALUES (:vno, :to, :by, :amt, :purpose, 'Active', :idate, CURRENT_TIMESTAMP)
            ")->execute([
                ':vno' => $voucherNo, ':to' => $accountantId, ':by' => $currentUserId,
                ':amt' => $req['amount'],
                ':purpose' => '[' . $req['payment_mode'] . ' for ' . ($req['payee'] ?: $req['requester_name']) . '] ' . $req['purpose'],
                ':idate' => date('Y-m-d'),
            ]);
            $newFloatId = (int)$db->lastInsertId();
            $db->prepare("UPDATE petty_cash_requests SET status = 'Issued', issuance_id = :iid WHERE id = :id")
               ->execute([':iid' => $newFloatId, ':id' => $rid]);

            logAudit($db, 'PETTY_CASH_ISSUED_FROM_REQUEST', 'PETTY_CASH', $voucherNo, "CEO {$currentUserName} issued " . formatMoney((float)$req['amount']) . " as {$voucherNo} against verified {$req['request_no']} ({$req['payment_mode']})");
            notifyUser($db, (int)$req['requested_by'], 'Petty cash ready', "Your request {$req['request_no']} was verified and the float {$voucherNo} issued - see the Accountant.", '/petty_cash.php');
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Could not issue the float: ' . $e->getMessage());
            header('Location: /petty_cash.php#requests');
            exit;
        }
        setFlash('success', "Float {$voucherNo} (" . formatMoney((float)$req['amount']) . ') issued against ' . $req['request_no'] . '.');
        header('Location: /petty_cash.php');
        exit;
    }

    // 6. Confirm an expense (CEO/Manager two-signature over the Accountant's entry)
    if ($action === 'confirm_expense') {
        if (!in_array($currentUserRole, ['CEO', 'Manager'], true)) {
            setFlash('error', 'Only the CEO or Manager can confirm expenses.');
            header('Location: /petty_cash.php');
            exit;
        }
        $eid = (int)($_POST['expense_id'] ?? 0);
        $eStmt = $db->prepare("SELECT e.*, i.voucher_no, u.name AS recorder_name FROM petty_cash_expenses e JOIN petty_cash_issuances i ON e.issuance_id = i.id JOIN users u ON e.approved_by = u.id WHERE e.id = :id AND e.confirmed_by IS NULL");
        $eStmt->execute([':id' => $eid]);
        $exp = $eStmt->fetch();
        if (!$exp) {
            setFlash('error', 'Expense not found or already confirmed.');
            header('Location: /petty_cash.php');
            exit;
        }
        if ((int)$exp['approved_by'] === $currentUserId) {
            setFlash('error', 'You cannot confirm an expense you recorded yourself - two different people are required.');
            header('Location: /petty_cash.php');
            exit;
        }
        $db->prepare("UPDATE petty_cash_expenses SET confirmed_by = :cb, confirmed_at = CURRENT_TIMESTAMP WHERE id = :id")
           ->execute([':cb' => $currentUserId, ':id' => $eid]);
        logAudit($db, 'PETTY_CASH_EXPENSE_CONFIRMED', 'PETTY_CASH', $exp['voucher_no'], "{$currentUserName} confirmed the " . formatMoney((float)$exp['amount']) . " expense recorded by {$exp['recorder_name']} (two-signature rule)");
        setFlash('success', 'Expense confirmed - two signatures complete.');
        header('Location: /petty_cash.php');
        exit;
    }

    // 7. Close a float (Accountant reconciles, CEO countersigns)
    if ($action === 'close_float') {
        $fid = (int)($_POST['float_id'] ?? 0);
        $dummyErrors2 = [];
        $note = field_text($dummyErrors2, 'close_note', 'Closing note', false, 0, 255) ?? '';
        $fStmt = $db->prepare("SELECT i.*, COALESCE((SELECT SUM(amount) FROM petty_cash_expenses WHERE issuance_id = i.id), 0) AS spent FROM petty_cash_issuances i WHERE i.id = :id");
        $fStmt->execute([':id' => $fid]);
        $float = $fStmt->fetch();
        if (!$float || !in_array($float['status'], ['Active', 'Pending Countersign'], true)) {
            setFlash('error', 'Float not found or already closed.');
            header('Location: /petty_cash.php');
            exit;
        }

        if ($currentUserRole === 'Accountant') {
            if ((float)$float['issued_to'] !== (float)$currentUserId && (int)$float['issued_to'] !== $currentUserId) {
                setFlash('error', 'Only the float holder can close their own float.');
                header('Location: /petty_cash.php');
                exit;
            }
            $db->prepare("UPDATE petty_cash_issuances SET status = 'Pending Countersign', closed_by = :cb, closed_at = CURRENT_TIMESTAMP, close_note = :cn WHERE id = :id")
               ->execute([':cb' => $currentUserId, ':cn' => $note, ':id' => $fid]);
            logAudit($db, 'PETTY_CASH_FLOAT_CLOSED', 'PETTY_CASH', $float['voucher_no'], "Holder {$currentUserName} closed {$float['voucher_no']} (spent " . formatMoney((float)$float['spent']) . " of " . formatMoney((float)$float['amount']) . "); awaiting CEO countersignature");
            notifyRoles($db, ['CEO'], 'Float awaiting countersignature', "{$float['voucher_no']} was closed by {$currentUserName} - " . formatMoney((float)$float['spent']) . ' spent, ' . formatMoney((float)$float['amount'] - (float)$float['spent']) . ' returned.', '/petty_cash.php');
            setFlash('success', "Float {$float['voucher_no']} closed. The CEO has been asked to countersign.");
        } elseif ($currentUserRole === 'CEO') {
            if ($float['status'] !== 'Pending Countersign') {
                setFlash('error', 'The holder (Accountant) must close the float first; then you countersign.');
                header('Location: /petty_cash.php');
                exit;
            }
            $db->prepare("UPDATE petty_cash_issuances SET status = 'Closed', countersigned_by = :csb, countersigned_at = CURRENT_TIMESTAMP WHERE id = :id")
               ->execute([':csb' => $currentUserId, ':id' => $fid]);
            logAudit($db, 'PETTY_CASH_FLOAT_COUNTERSIGNED', 'PETTY_CASH', $float['voucher_no'], "CEO {$currentUserName} countersigned the close-out of {$float['voucher_no']}");
            setFlash('success', "Float {$float['voucher_no']} countersigned and fully closed.");
        } else {
            setFlash('error', 'Only the Accountant (holder) or CEO can take close-out actions.');
        }
        header('Location: /petty_cash.php');
        exit;
    }

    setFlash('error', 'Unknown petty cash action.');
    header('Location: /petty_cash.php');
    exit;
}

// ---- Search & date-range filter (shared contract: q, from, to) ----
$filter = read_filter_params();
foreach ($filter['errors'] as $fe) {
    setFlash('warning', $fe);
}

$voucherSql = "";
$voucherArgs = [];
if ($filter['from'] !== '') { $voucherSql .= " AND i.issued_date >= :v_from"; $voucherArgs[':v_from'] = $filter['from']; }
if ($filter['to'] !== '') { $voucherSql .= " AND i.issued_date <= :v_to"; $voucherArgs[':v_to'] = $filter['to']; }
if ($filter['q'] !== '') {
    $voucherSql .= " AND (i.voucher_no LIKE :vq1 OR i.purpose LIKE :vq2 OR u_to.name LIKE :vq3 OR u_by.name LIKE :vq4 OR CAST(i.amount AS CHAR) LIKE :vq5)";
    $like = '%' . $filter['q'] . '%';
    for ($i = 1; $i <= 5; $i++) { $voucherArgs[":vq{$i}"] = $like; }
}

// Fetch vouchers with filters
$stmtVouchers = $db->prepare("
    SELECT i.*,
           u_to.name AS custodian_name,
           u_by.name AS issuer_name,
           COALESCE(SUM(e.amount), 0) AS total_spent
    FROM petty_cash_issuances i
    LEFT JOIN users u_to ON i.issued_to = u_to.id
    LEFT JOIN users u_by ON i.issued_by = u_by.id
    LEFT JOIN petty_cash_expenses e ON e.issuance_id = i.id
    WHERE 1=1 " . $voucherSql . "
    GROUP BY i.id
    ORDER BY i.issued_date DESC, i.id DESC
");
foreach ($voucherArgs as $k => $v) { $stmtVouchers->bindValue($k, $v); }
$stmtVouchers->execute();
$vouchers = $stmtVouchers->fetchAll();

$expenseSql = "";
$expenseArgs = [];
if ($filter['from'] !== '') { $expenseSql .= " AND e.expense_date >= :e_from"; $expenseArgs[':e_from'] = $filter['from']; }
if ($filter['to'] !== '') { $expenseSql .= " AND e.expense_date <= :e_to"; $expenseArgs[':e_to'] = $filter['to']; }
if ($filter['q'] !== '') {
    $expenseSql .= " AND (e.description LIKE :eq1 OR e.category LIKE :eq2 OR e.receipt_no LIKE :eq3 OR i.voucher_no LIKE :eq4 OR u.name LIKE :eq5 OR CAST(e.amount AS CHAR) LIKE :eq6)";
    $like = '%' . $filter['q'] . '%';
    for ($i = 1; $i <= 6; $i++) { $expenseArgs[":eq{$i}"] = $like; }
}

// Fetch expense transactions with filters
$stmtExpenses = $db->prepare("
    SELECT e.*,
           i.voucher_no,
           u.name AS approver_name,
           uc.name AS confirmed_by_name
    FROM petty_cash_expenses e
    JOIN petty_cash_issuances i ON e.issuance_id = i.id
    LEFT JOIN users u ON e.approved_by = u.id
    LEFT JOIN users uc ON e.confirmed_by = uc.id
    WHERE 1=1 " . $expenseSql . "
    ORDER BY e.expense_date DESC, e.id DESC
");
foreach ($expenseArgs as $k => $v) { $stmtExpenses->bindValue($k, $v); }
$stmtExpenses->execute();
$expenses = $stmtExpenses->fetchAll();

// Petty cash requests (client flow) - visible to all, acted on by CEO
$stmtRequests = $db->prepare("
    SELECT r.*, u.name AS requester_name, u.role AS requester_role,
           uv.name AS verifier_name, i.voucher_no
    FROM petty_cash_requests r
    JOIN users u ON r.requested_by = u.id
    LEFT JOIN users uv ON r.verified_by = uv.id
    LEFT JOIN petty_cash_issuances i ON r.issuance_id = i.id
    ORDER BY (r.status = 'Pending CEO Verification') DESC, r.id DESC
    LIMIT 50
");
$stmtRequests->execute();
$pcRequests = $stmtRequests->fetchAll();

// Monthly budget (item 11): warning at 80 percent
$monthlyBudget = (float)getSetting($db, 'petty_cash_monthly_budget', '5000000');
$monthIssued = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM petty_cash_issuances WHERE status IN ('Active','Pending Countersign') AND issued_date >= '" . date('Y-m-01') . "'")->fetchColumn();
$budgetPct = $monthlyBudget > 0 ? round(($monthIssued / $monthlyBudget) * 100) : 0;

// Eligible float holders: active Accountants only
$custodians = $db->query("SELECT * FROM users WHERE role = 'Accountant' AND status = 'Active' ORDER BY name ASC")->fetchAll();

// Financial Totals
$totalIssued = 0;
$totalExpensed = 0;
foreach ($vouchers as $v) {
    if (in_array($v['status'], ['Active', 'Pending Countersign'], true)) {
        $totalIssued += (float)$v['amount'];
        $totalExpensed += (float)$v['total_spent'];
    }
}
$netFloat = max(0, $totalIssued - $totalExpensed);

$showIssueForm = isset($_GET['action']) && $_GET['action'] === 'issue' && $currentUserRole === 'CEO';
// v2.3.2: recording expenses is the ACCOUNTANT's authority (the Manager no
// longer handles money); requesting cash stays open to PO/Manager/Accountant.
$showExpenseForm = isset($_GET['action']) && $_GET['action'] === 'expense' && $currentUserRole === 'Accountant';
$showRequestForm = isset($_GET['action']) && $_GET['action'] === 'request' && in_array($currentUserRole, ['Procurement Officer', 'Manager', 'Accountant'], true);

include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 class="page-title">Petty Cash Floats, Requests &amp; Expense Ledger</h2>
            <p class="page-subtitle">Request &rarr; CEO verification &rarr; float &rarr; expenses with two signatures &rarr; close-out &amp; countersign (all amounts in TZS)</p>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <?php if ($currentUserRole === 'CEO'): ?>
                <a href="/petty_cash.php?action=issue" class="btn btn-primary">+ Issue New Float</a>
            <?php elseif (in_array($currentUserRole, ['Procurement Officer', 'Manager', 'Accountant'], true)): ?>
                <a href="/petty_cash.php?action=request" class="btn btn-primary">+ Request Petty Cash</a>
            <?php endif; ?>
            <?php if ($currentUserRole === 'Accountant'): ?>
                <a href="/petty_cash.php?action=expense" class="btn btn-secondary">+ Record Expense</a>
            <?php endif; ?>
        </div>
    </div>

    <?php displayFlash(); ?>

    <?php if ($monthlyBudget > 0): ?>
        <?php if ($budgetPct >= 80): ?>
            <div class="card alert-<?= $budgetPct >= 100 ? 'danger' : 'warning' ?>" style="padding:10px 16px; margin-bottom:16px; font-size:13px;">
                &#9888;&#65039; <strong>Budget alert:</strong> <?= formatMoney($monthIssued) ?> of the <?= formatMoney($monthlyBudget) ?> monthly budget already issued this month (<?= $budgetPct ?>%).
                <?= $budgetPct >= 100 ? 'The budget is exhausted - hold further floats unless the CEO approves an override.' : 'Approaching the limit - issue only essential floats.' ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php render_filter_bar([
        'action'       => '/petty_cash.php',
        'q'            => $filter['q'],
        'from'         => $filter['from'],
        'to'           => $filter['to'],
        'placeholder'  => 'Search voucher, purpose, custodian, category, receipt...',
        'reportsKey'   => 'petty_cash_floats',
    ]); ?>

    <!-- KPI Balance Summary: Issued -> Used -> Remaining -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Issued</span>
                <span class="badge badge-primary"><?= count($vouchers) ?> Vouchers<?= ($filter['from'] !== '' || $filter['to'] !== '' || $filter['q'] !== '') ? ' (filtered)' : '' ?></span>
            </div>
            <div class="stat-value"><?= formatMoney($totalIssued) ?></div>
            <div class="stat-desc">Total float cash disbursed to the Accountant (custodian)</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Used</span>
                <span class="badge badge-info"><?= count($expenses) ?> Receipts<?= ($filter['from'] !== '' || $filter['to'] !== '' || $filter['q'] !== '') ? ' (filtered)' : '' ?></span>
            </div>
            <div class="stat-value"><?= formatMoney($totalExpensed) ?></div>
            <div class="stat-desc">Spent and reconciled with vendor receipts</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Remaining</span>
                <span class="badge badge-success">On Hand</span>
            </div>
            <div class="stat-value" style="color:var(--success);"><?= formatMoney($netFloat) ?></div>
            <div class="stat-desc">Unspent floor cash still available (Issued &minus; Used)</div>
        </div>
    </div>

    <!-- Petty cash request form (client flow step 1) -->
    <?php if ($showRequestForm): ?>
        <div class="card" style="border: 2px solid var(--primary-border);">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Request Petty Cash</h3>
                    <p class="card-subtitle">Submit a request for the CEO to verify - the Accountant remains the only cash handler</p>
                </div>
                <a href="/petty_cash.php" class="btn btn-secondary btn-sm">&times; Cancel</a>
            </div>
            <form method="POST" action="/petty_cash.php">
                <input type="hidden" name="action" value="request_float">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="amount">Amount (TZS) *</label>
                        <input type="number" id="amount" name="amount" min="800000" max="7000000" step="1" value="1000000" class="form-control" required
                               data-required-error="Amount is required." data-min-error="Minimum is TZS 800,000." data-max-error="Maximum single request is TZS 7,000,000.">
                        <span class="form-help">Allowed range: TZS 800,000 &ndash; TZS 7,000,000.</span>
                    </div>
                    <div class="form-group">
                        <label for="payment_mode">Payment mode *</label>
                        <select id="payment_mode" name="payment_mode" class="form-control">
                            <option value="Cash">Cash from the float</option>
                            <option value="Supplier Payment">Pay a supplier directly</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="payee">Payee / supplier (for supplier payments)</label>
                        <input type="text" id="payee" name="payee" class="form-control" maxlength="120" placeholder="e.g. Kariakoo Hardware Ltd">
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="purpose">Purpose &amp; scope *</label>
                        <input type="text" id="purpose" name="purpose" class="form-control" required maxlength="255" minlength="5"
                               placeholder="e.g. Emergency bearing replacement for sanding machine S2" data-required-error="Purpose is required." data-plaintext>
                    </div>
                </div>
                <div style="margin-top:16px; display:flex; justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary">Submit Request to CEO &rarr;</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Requests pipeline (client flow steps 2-3: CEO verifies, then float issues) -->
    <?php if (!empty($pcRequests)): ?>
    <div class="card" id="requests">
        <div class="card-header">
            <div>
                <h3 class="card-title">Petty Cash Requests</h3>
                <p class="card-subtitle">Officer / Manager / Accountant requests &rarr; CEO verifies &rarr; float issued to the Accountant</p>
            </div>
            <span class="badge badge-primary"><?= count($pcRequests) ?> total</span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Request #</th>
                        <th>Requested By</th>
                        <th>Amount</th>
                        <th>Mode / Payee</th>
                        <th>Purpose</th>
                        <th>Status</th>
                        <?php if ($currentUserRole === 'CEO'): ?><th>Decision</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pcRequests as $r):
                        $badge = match ($r['status']) {
                            'Pending CEO Verification' => 'badge-warning',
                            'Verified - Awaiting Float' => 'badge-info',
                            'Issued' => 'badge-success',
                            'Rejected' => 'badge-danger',
                            default => 'badge-secondary',
                        };
                    ?>
                        <tr>
                            <td class="mono"><strong><?= htmlspecialchars($r['request_no']) ?></strong></td>
                            <td><strong><?= htmlspecialchars($r['requester_name']) ?></strong>
                                <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($r['requester_role']) ?></div>
                            </td>
                            <td><strong><?= formatMoney((float)$r['amount']) ?></strong></td>
                            <td><?= htmlspecialchars($r['payment_mode']) ?><?= $r['payee'] ? ' &mdash; ' . htmlspecialchars($r['payee']) : '' ?></td>
                            <td style="max-width:260px; font-size:12.5px;"><?= htmlspecialchars($r['purpose']) ?>
                                <?php if ($r['voucher_no']): ?><div style="font-size:11px; color:var(--success);">Float: <span class="mono"><?= htmlspecialchars($r['voucher_no']) ?></span></div><?php endif; ?>
                            </td>
                            <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($r['status']) ?></span>
                                <?php if ($r['verified_name'] ?? false): ?><div style="font-size:11px; color:var(--text-muted);">by <?= htmlspecialchars($r['verifier_name']) ?></div><?php endif; ?>
                            </td>
                            <?php if ($currentUserRole === 'CEO'): ?>
                                <td>
                                    <?php if ($r['status'] === 'Pending CEO Verification'): ?>
                                        <form method="POST" action="/petty_cash.php" style="display:flex; gap:6px; align-items:center;">
                                            <input type="hidden" name="action" value="verify_request">
                                            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                            <input type="hidden" name="decision" value="approve">
                                            <input type="text" name="verification_note" class="form-control" placeholder="Note (optional)" style="width:130px; padding:4px 8px; font-size:12px;" maxlength="255">
                                            <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Verify <?= htmlspecialchars($r['request_no']) ?> for <?= formatMoney((float)$r['amount']) ?>?');">Verify</button>
                                        </form>
                                        <form method="POST" action="/petty_cash.php" style="margin-top:4px;">
                                            <input type="hidden" name="action" value="verify_request">
                                            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                            <input type="hidden" name="decision" value="reject">
                                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Reject <?= htmlspecialchars($r['request_no']) ?>?');">Reject</button>
                                        </form>
                                    <?php elseif ($r['status'] === 'Verified - Awaiting Float'): ?>
                                        <form method="POST" action="/petty_cash.php">
                                            <input type="hidden" name="action" value="issue_from_request">
                                            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                            <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Issue <?= formatMoney((float)$r['amount']) ?> to the Accountant against <?= htmlspecialchars($r['request_no']) ?>?');">Issue Float</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:var(--text-subtle);">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Issue New Float Form (CEO only) -->
    <?php if ($showIssueForm): ?>
        <div class="card" style="border: 2px solid var(--primary-border);">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Issue New Petty Cash Float Voucher</h3>
                    <p class="card-subtitle">CEO authority: disburse TZS 800,000 &ndash; 7,000,000 to the Accountant (sole float holder)</p>
                </div>
                <a href="/petty_cash.php" class="btn btn-secondary btn-sm">&times; Cancel</a>
            </div>

            <form method="POST" action="/petty_cash.php">
                <input type="hidden" name="action" value="issue_float">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="issued_to">Float Holder (Accountant only) *</label>
                        <select id="issued_to" name="issued_to" class="form-control" required>
                            <?php foreach ($custodians as $c): ?>
                                <option value="<?= (int)$c['id'] ?>">
                                    <?= htmlspecialchars($c['name']) ?> (Accountant)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="form-help">Petty cash floats are held exclusively by the Accountant.</span>
                    </div>

                    <div class="form-group">
                        <label for="amount">Float Amount (TZS) *</label>
                        <input type="number" id="amount" name="amount" min="800000" max="7000000" step="1" value="1000000" class="form-control" required
                               data-required-error="Float amount is required." data-min-error="Minimum float is TZS 800,000." data-max-error="Maximum single float is TZS 7,000,000.">
                        <span class="form-help">Allowed range: TZS 800,000 &ndash; TZS 7,000,000.</span>
                    </div>

                    <div class="form-group">
                        <label for="issued_date">Date of Issuance *</label>
                        <input type="date" id="issued_date" name="issued_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" class="form-control" required
                               data-required-error="Date of issuance is required." data-max-error="Issuance date cannot be in the future.">
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="purpose">Operational Purpose &amp; Scope *</label>
                        <input type="text" id="purpose" name="purpose" value="Shift operations emergency parts & local maintenance float" class="form-control" required
                               maxlength="255" minlength="5" data-required-error="Operational purpose is required." data-plaintext>
                    </div>
                </div>

                <div style="margin-top:16px; display:flex; justify-content:flex-end; gap:10px;">
                    <a href="/petty_cash.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Authorize &amp; Issue Float &rarr;</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Record Expense Form (Accountant only) -->
    <?php if ($showExpenseForm): ?>
        <div class="card" style="border: 2px solid var(--primary-border);">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Record Petty Cash Expense</h3>
                    <p class="card-subtitle">Deduct spent cash against an active custodian voucher with receipt proof</p>
                </div>
                <a href="/petty_cash.php" class="btn btn-secondary btn-sm">&times; Cancel</a>
            </div>

            <form method="POST" action="/petty_cash.php">
                <input type="hidden" name="action" value="record_expense">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="issuance_id">Associated Float Voucher *</label>
                        <select id="issuance_id" name="issuance_id" class="form-control" required>
                            <?php foreach ($vouchers as $v):
                                $rem = (float)$v['amount'] - (float)$v['total_spent'];
                            ?>
                                <?php if ($v['status'] === 'Active' && $rem > 0): ?>
                                    <option value="<?= (int)$v['id'] ?>">
                                        <?= htmlspecialchars($v['voucher_no']) ?> &mdash; <?= htmlspecialchars($v['custodian_name'] ?? 'Unassigned') ?> (<?= formatMoney($rem) ?> remaining)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="expense_date">Expense Date *</label>
                        <input type="date" id="expense_date" name="expense_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" class="form-control" required
                               data-required-error="Expense date is required." data-max-error="Expense date cannot be in the future.">
                    </div>

                    <div class="form-group">
                        <label for="category">Expense Category *</label>
                        <select id="category" name="category" class="form-control">
                            <option value="Hardware &amp; Fasteners">Hardware &amp; Fasteners</option>
                            <option value="Shop Consumables">Shop Consumables &amp; Solvents</option>
                            <option value="Equipment Maintenance">Emergency Equipment Maintenance</option>
                            <option value="Logistics &amp; Freight">Urgent Courier / Freight</option>
                            <option value="Safety &amp; PPE">Safety Equipment &amp; First Aid</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="amount">Expense Amount (TZS) *</label>
                        <input type="number" id="amount" name="amount" min="100" max="50000000" step="1" value="50000" class="form-control" required
                               data-required-error="Expense amount is required." data-min-error="Minimum expense is TZS 100." data-max-error="Maximum single expense is TZS 50,000,000.">
                    </div>

                    <div class="form-group">
                        <label for="receipt_no">Receipt / Tax Invoice Number *</label>
                        <input type="text" id="receipt_no" name="receipt_no" placeholder="e.g. REC-99201" value="REC-<?= rand(10000, 99999) ?>" class="form-control" required
                               minlength="3" maxlength="50" data-required-error="Receipt number is required." data-plaintext>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="description">Expense Item Description *</label>
                        <input type="text" id="description" name="description" placeholder="e.g. Replacement hydraulic solenoid fuse and terminal blocks" class="form-control" required
                               minlength="3" maxlength="255" data-required-error="Expense description is required." data-plaintext>
                    </div>
                </div>

                <div style="margin-top:16px; display:flex; justify-content:flex-end; gap:10px;">
                    <a href="/petty_cash.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Log Expense &amp; Reconcile &rarr;</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Vouchers Grid -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Petty Cash Float Vouchers</h3>
                <p class="card-subtitle">Active custodian allocations and remaining balance levels</p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Voucher #</th>
                        <th>Issued Date</th>
                        <th>Custodian (Holder)</th>
                        <th>Purpose</th>
                        <th>Issued</th>
                        <th>Used</th>
                        <th>Remaining</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vouchers)): ?>
                        <tr><td colspan="8" class="empty-state">No vouchers match the current search / date filter.</td></tr>
                    <?php else: ?>
                        <?php foreach ($vouchers as $v):
                            $rem = (float)$v['amount'] - (float)$v['total_spent'];
                            $pctUsed = $v['amount'] > 0 ? round(((float)$v['total_spent'] / (float)$v['amount']) * 100) : 0;
                        ?>
                            <tr>
                                <td class="mono"><strong><?= htmlspecialchars($v['voucher_no']) ?></strong></td>
                                <td><?= formatDate($v['issued_date']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($v['custodian_name'] ?? 'Unassigned') ?></strong>
                                    <div style="font-size:11px; color:var(--text-muted);">Issued by <?= htmlspecialchars($v['issuer_name'] ?? 'Unknown') ?></div>
                                </td>
                                <td style="max-width:280px;"><?= htmlspecialchars($v['purpose']) ?></td>
                                <td><strong><?= formatMoney((float)$v['amount']) ?></strong></td>
                                <td>
                                    <?= formatMoney((float)$v['total_spent']) ?>
                                    <div style="height:5px; width:90px; background:var(--border-color); border-radius:3px; margin-top:4px; overflow:hidden;">
                                        <div style="height:100%; width:<?= min(100, $pctUsed) ?>%; background:<?= $pctUsed >= 90 ? 'var(--danger)' : ($pctUsed >= 70 ? 'var(--warning, #d97706)' : 'var(--primary)') ?>;"></div>
                                    </div>
                                    <div style="font-size:11px; color:var(--text-muted); margin-top:2px;"><?= $pctUsed ?>% used</div>
                                </td>
                                <td>
                                    <strong style="color: <?= $rem > 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                                        <?= formatMoney($rem) ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php
                                        $statusBadge = match ($v['status']) {
                                            'Active' => 'badge-success',
                                            'Pending Countersign' => 'badge-warning',
                                            'Closed' => 'badge-secondary',
                                            default => 'badge-secondary',
                                        };
                                    ?>
                                    <span class="badge <?= $statusBadge ?>"><?= htmlspecialchars($v['status']) ?></span>
                                    <?php if ($v['status'] === 'Closed'): ?>
                                        <div style="font-size:11px; color:var(--text-muted);">Countersigned by <?= htmlspecialchars($v['countersigned_by'] ? ($v['countersigned_by'] == $currentUserId ? 'CEO (you)' : 'CEO') : '-') ?></div>
                                    <?php endif; ?>
                                    <?php if ($v['status'] === 'Active' && $currentUserRole === 'Accountant' && (int)$v['issued_to'] === $currentUserId): ?>
                                        <form method="POST" action="/petty_cash.php" style="margin-top:6px;" onsubmit="return confirm('Close this float? State what was done with the remaining cash in the note.');">
                                            <input type="hidden" name="action" value="close_float">
                                            <input type="hidden" name="float_id" value="<?= (int)$v['id'] ?>">
                                            <input type="text" name="close_note" class="form-control" placeholder="Closing note" style="width:140px; padding:4px 8px; font-size:11px; margin-bottom:4px;" maxlength="255">
                                            <button type="submit" class="btn btn-warning btn-sm" style="font-size:11px; padding:3px 10px;">Close &amp; Reconcile</button>
                                        </form>
                                    <?php elseif ($v['status'] === 'Pending Countersign' && $currentUserRole === 'CEO'): ?>
                                        <form method="POST" action="/petty_cash.php" style="margin-top:6px;" onsubmit="return confirm('Countersign the close-out of <?= htmlspecialchars($v['voucher_no']) ?>?');">
                                            <input type="hidden" name="action" value="close_float">
                                            <input type="hidden" name="float_id" value="<?= (int)$v['id'] ?>">
                                            <button type="submit" class="btn btn-primary btn-sm" style="font-size:11px; padding:3px 10px;">&#10003; Countersign</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Expense Ledger -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Expense Ledger</h3>
                <p class="card-subtitle">Detailed itemized transaction history with receipt references</p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Voucher #</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Receipt #</th>
                        <th>Amount (TZS)</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expenses)): ?>
                        <tr><td colspan="7" class="empty-state">No expenses match the current search / date filter.</td></tr>
                    <?php else: ?>
                        <?php foreach ($expenses as $e): ?>
                            <tr>
                                <td><?= formatDate($e['expense_date']) ?></td>
                                <td class="mono"><?= htmlspecialchars($e['voucher_no']) ?></td>
                                <td><span class="badge badge-secondary"><?= htmlspecialchars($e['category']) ?></span></td>
                                <td style="font-weight:600;"><?= htmlspecialchars($e['description']) ?></td>
                                <td class="mono" style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($e['receipt_no']) ?></td>
                                <td><strong style="color:var(--text-main);"><?= formatMoney((float)$e['amount']) ?></strong></td>
                                <td><?= htmlspecialchars($e['approver_name'] ?? 'Unknown') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
