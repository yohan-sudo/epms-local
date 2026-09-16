<?php
/**
 * U EPMS - Petty Cash Float & Expense Ledger
 * - CEO (owner): issues new petty cash floats (the ONLY role that can).
 * - Accountant: records expenses against existing floats (cannot issue).
 * - Manager: records expenses.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/components/filter_bar.php';

requireRole(['CEO', 'Manager', 'Accountant']);

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

    // 2. Record Expense Against a Voucher (Accountant & Manager)
    if ($action === 'record_expense') {
        if (!in_array($currentUserRole, ['Accountant', 'Manager'], true)) {
            setFlash('error', 'Unauthorized: Only the Accountant or Manager can record expenses against petty cash vouchers.');
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
    $voucherSql .= " AND (i.voucher_no LIKE :vq1 OR i.purpose LIKE :vq2 OR ut.name LIKE :vq3 OR ub.name LIKE :vq4 OR CAST(i.amount AS CHAR) LIKE :vq5)";
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
           u.name AS approver_name
    FROM petty_cash_expenses e
    JOIN petty_cash_issuances i ON e.issuance_id = i.id
    LEFT JOIN users u ON e.approved_by = u.id
    WHERE 1=1 " . $expenseSql . "
    ORDER BY e.expense_date DESC, e.id DESC
");
foreach ($expenseArgs as $k => $v) { $stmtExpenses->bindValue($k, $v); }
$stmtExpenses->execute();
$expenses = $stmtExpenses->fetchAll();

// Eligible float holders: active Accountants only
$custodians = $db->query("SELECT * FROM users WHERE role = 'Accountant' AND status = 'Active' ORDER BY name ASC")->fetchAll();

// Financial Totals
$totalIssued = 0;
$totalExpensed = 0;
foreach ($vouchers as $v) {
    if ($v['status'] === 'Active') {
        $totalIssued += (float)$v['amount'];
        $totalExpensed += (float)$v['total_spent'];
    }
}
$netFloat = max(0, $totalIssued - $totalExpensed);

$showIssueForm = isset($_GET['action']) && $_GET['action'] === 'issue' && $currentUserRole === 'CEO';
$showExpenseForm = isset($_GET['action']) && $_GET['action'] === 'expense' && in_array($currentUserRole, ['Accountant', 'Manager'], true);

include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 class="page-title">Petty Cash Floats &amp; Expense Ledger</h2>
            <p class="page-subtitle">Floor disbursement accounting, custodian voucher reconciliation, and receipt verification (all amounts in TZS)</p>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <?php if ($currentUserRole === 'CEO'): ?>
                <a href="/petty_cash.php?action=issue" class="btn btn-primary">+ Issue New Float</a>
            <?php endif; ?>
            <?php if (in_array($currentUserRole, ['Accountant', 'Manager'], true)): ?>
                <a href="/petty_cash.php?action=expense" class="btn btn-secondary">+ Record Expense</a>
            <?php else: ?>
                <span class="badge badge-info" style="padding:6px 12px; font-size:12px;">&#128065; Read-Only Inspection</span>
            <?php endif; ?>
        </div>
    </div>

    <?php displayFlash(); ?>

    <?php render_filter_bar([
        'action'       => '/petty_cash.php',
        'q'            => $filter['q'],
        'from'         => $filter['from'],
        'to'           => $filter['to'],
        'placeholder'  => 'Search voucher, purpose, custodian, category, receipt...',
        'reportsKey'   => 'petty_cash_floats',
    ]); ?>

    <!-- KPI Balance Summary -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Active Float Reserve</span>
                <span class="badge badge-success">Available</span>
            </div>
            <div class="stat-value" style="color:var(--success);"><?= formatMoney($netFloat) ?></div>
            <div class="stat-desc">Current unspent floor cash available</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Total Floats Issued</span>
                <span class="badge badge-primary"><?= count($vouchers) ?> Vouchers<?= ($filter['from'] !== '' || $filter['to'] !== '' || $filter['q'] !== '') ? ' (filtered)' : '' ?></span>
            </div>
            <div class="stat-value"><?= formatMoney($totalIssued) ?></div>
            <div class="stat-desc">CEO disbursements to plant custodians</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Reconciled Expenses</span>
                <span class="badge badge-info"><?= count($expenses) ?> Receipts<?= ($filter['from'] !== '' || $filter['to'] !== '' || $filter['q'] !== '') ? ' (filtered)' : '' ?></span>
            </div>
            <div class="stat-value"><?= formatMoney($totalExpensed) ?></div>
            <div class="stat-desc">Documented with formal vendor receipts</div>
        </div>
    </div>

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

    <!-- Record Expense Form (Accountant / Manager) -->
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
                        <th>Original Float</th>
                        <th>Expensed</th>
                        <th>Remaining Float</th>
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
                                <td style="color:var(--text-muted);"><?= formatMoney((float)$v['total_spent']) ?> (<?= $pctUsed ?>%)</td>
                                <td>
                                    <strong style="color: <?= $rem > 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                                        <?= formatMoney($rem) ?>
                                    </strong>
                                </td>
                                <td>
                                    <span class="badge badge-success"><?= htmlspecialchars($v['status']) ?></span>
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
