<?php
/**
 * U EPMS - Sales & Dispatch (item 24)
 * Records outbound dispatches: customer, bundles/units, unit price,
 * amount paid. Drives the revenue and receivables cards on the dashboard
 * and feeds the Profit & Loss report.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole(['CEO', 'Manager', 'Accountant']);

$pageTitle = 'Sales & Dispatch';
$activeNav = 'dispatch';

$currentUserRole = $_SESSION['user_role'];
$currentUserId = (int)$_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['dispatch_action'] ?? '');

    if ($act === 'add_customer') {
        $errors = [];
        $cname = field_text($errors, 'name', 'Customer name', true, 2, 120) ?? '';
        $cphone = field_text($errors, 'phone', 'Phone', false, 0, 30) ?? '';
        $caddr = field_text($errors, 'address', 'Address', false, 0, 200) ?? '';
        if ($errors) {
            redirectWithErrors('/dispatch.php', $errors);
        }
        $db->prepare("INSERT INTO customers (name, phone, address) VALUES (:n, :p, :a)")
           ->execute([':n' => $cname, ':p' => $cphone, ':a' => $caddr]);
        logAudit($db, 'CUSTOMER_ADDED', 'CUSTOMER', (int)$db->lastInsertId(), "{$currentUserName} registered customer {$cname}");
        setFlash('success', "Customer {$cname} registered.");
        commitSessionAndRedirect('/dispatch.php');
    }

    if ($act === 'dispatch') {
        $errors = [];
        $customerId = field_int($errors, 'customer_id', 'Customer', 1) ?? 0;
        $bundles    = field_int($errors, 'bundles', 'Bundles', 0) ?? 0;
        $unitsPer   = field_int($errors, 'units_per_bundle', 'Units per bundle', 1) ?? 60;
        $unitPrice  = field_float($errors, 'unit_price', 'Price per bundle', 0) ?? 0;
        $paid       = field_float($errors, 'amount_paid', 'Amount paid', 0) ?? 0;
        $dDate      = field_date($errors, 'dispatch_date', 'Dispatch date', true, true) ?? date('Y-m-d');
        if ($bundles < 1) {
            $errors[] = '• At least one bundle is required.';
        }
        if ($errors) {
            redirectWithErrors('/dispatch.php', $errors);
        }
        $units = $bundles * $unitsPer;
        $total = $bundles * $unitPrice;

        $db->beginTransaction();
        try {
            $no = 'DSP-' . date('Y') . '-' . str_pad((string)((int)$db->query('SELECT COUNT(*) FROM dispatches')->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
            $db->prepare("INSERT INTO dispatches (dispatch_no, customer_id, bundles, units, unit_price, total_amount, amount_paid, dispatch_date, recorded_by) VALUES (:no, :c, :b, :u, :p, :t, :a, :d, :by)")
               ->execute([':no' => $no, ':c' => $customerId, ':b' => $bundles, ':u' => $units, ':p' => $unitPrice, ':t' => $total, ':a' => $paid, ':d' => $dDate, ':by' => $currentUserId]);
            logAudit($db, 'DISPATCH_RECORDED', 'DISPATCH', $no, "{$currentUserName} dispatched {$bundles} bundles ({$units} units) at " . formatMoney($unitPrice) . " each = " . formatMoney($total) . " (paid " . formatMoney($paid) . ") under {$no}");
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Could not record the dispatch: ' . $e->getMessage());
            commitSessionAndRedirect('/dispatch.php');
        }
        setFlash('success', "Dispatch {$no} recorded: " . formatMoney($total) . ($paid < $total ? ' - balance ' . formatMoney($total - $paid) : ' fully paid.'));
        commitSessionAndRedirect('/dispatch.php');
    }

    if ($act === 'record_payment') {
        $did = (int)($_POST['dispatch_id'] ?? 0);
        $pay = (float)($_POST['payment'] ?? 0);
        $dStmt = $db->prepare("SELECT * FROM dispatches WHERE id = :id");
        $dStmt->execute([':id' => $did]);
        $d = $dStmt->fetch();
        if ($d && $pay > 0) {
            $db->prepare("UPDATE dispatches SET amount_paid = amount_paid + :p WHERE id = :id")->execute([':p' => $pay, ':id' => $did]);
            logAudit($db, 'PAYMENT_RECEIVED', 'DISPATCH', $d['dispatch_no'], "{$currentUserName} recorded payment of " . formatMoney($pay) . " on {$d['dispatch_no']}");
            setFlash('success', 'Payment recorded.');
        }
        commitSessionAndRedirect('/dispatch.php');
    }

    commitSessionAndRedirect('/dispatch.php');
}

$customers = $db->query("SELECT * FROM customers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$dispatches = $db->query("
    SELECT d.*, c.name AS customer_name, u.name AS recorded_by_name
    FROM dispatches d
    JOIN customers c ON d.customer_id = c.id
    LEFT JOIN users u ON d.recorded_by = u.id
    ORDER BY d.dispatch_date DESC, d.id DESC
    LIMIT 100
")->fetchAll();

$totalRevenue = 0.0;
$totalPaid = 0.0;
$totalUnits = 0;
foreach ($dispatches as $d) {
    $totalRevenue += (float)$d['total_amount'];
    $totalPaid += (float)$d['amount_paid'];
    $totalUnits += (int)$d['units'];
}
$outstanding = max(0, $totalRevenue - $totalPaid);

include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header">
        <h2 class="page-title">Sales &amp; Dispatch</h2>
        <p class="page-subtitle">Bundles out to customers, prices, payments received and outstanding balances</p>
    </div>

    <?php displayFlash(); ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header"><span class="stat-title">Revenue</span><span class="badge badge-primary"><?= count($dispatches) ?> dispatches</span></div>
            <div class="stat-value"><?= formatMoney($totalRevenue) ?></div>
            <div class="stat-desc"><?= formatNumber($totalUnits) ?> broom sticks dispatched</div>
        </div>
        <div class="stat-card">
            <div class="stat-header"><span class="stat-title">Collected</span><span class="badge badge-success">Paid</span></div>
            <div class="stat-value" style="color:var(--success);"><?= formatMoney($totalPaid) ?></div>
            <div class="stat-desc">Cash actually received</div>
        </div>
        <div class="stat-card">
            <div class="stat-header"><span class="stat-title">Owed to Factory</span><span class="badge <?= $outstanding > 0 ? 'badge-danger' : 'badge-secondary' ?>">Receivables</span></div>
            <div class="stat-value" style="color:<?= $outstanding > 0 ? 'var(--danger)' : 'inherit' ?>;"><?= formatMoney($outstanding) ?></div>
            <div class="stat-desc">Outstanding customer balances</div>
        </div>
    </div>

    <div class="form-grid" style="margin-bottom:16px;">
        <div class="card" style="padding:14px 18px; margin:0;">
            <h4 style="font-size:13px; font-weight:800; margin:0 0 8px;">&#128666; Record Dispatch</h4>
            <form method="POST" action="/dispatch.php">
                <input type="hidden" name="dispatch_action" value="dispatch">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Customer *</label>
                        <select name="customer_id" required class="form-control">
                            <?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Bundles *</label>
                        <input type="number" name="bundles" min="1" required class="form-control" placeholder="e.g. 40">
                    </div>
                    <div class="form-group">
                        <label>Units per bundle</label>
                        <input type="number" name="units_per_bundle" min="1" value="60" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Price per bundle (TZS) *</label>
                        <input type="number" name="unit_price" min="0" step="1" required class="form-control" placeholder="e.g. 8500">
                    </div>
                    <div class="form-group">
                        <label>Amount paid now (TZS)</label>
                        <input type="number" name="amount_paid" min="0" step="1" value="0" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Date *</label>
                        <input type="date" name="dispatch_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required class="form-control">
                    </div>
                    <div class="form-group" style="display:flex; align-items:flex-end; grid-column: 1 / -1;">
                        <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">Record Dispatch &rarr;</button>
                    </div>
                </div>
            </form>
        </div>
        <div class="card" style="padding:14px 18px; margin:0;">
            <h4 style="font-size:13px; font-weight:800; margin:0 0 8px;">&#128100; Register Customer</h4>
            <form method="POST" action="/dispatch.php">
                <input type="hidden" name="dispatch_action" value="add_customer">
                <div class="form-group">
                    <label>Name *</label>
                    <input type="text" name="name" required class="form-control" maxlength="120" placeholder="e.g. Mwanza Traders Ltd">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" class="form-control" maxlength="30" placeholder="e.g. 0712 345 678">
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" name="address" class="form-control" maxlength="200">
                </div>
                <button type="submit" class="btn btn-secondary btn-sm">Register &rarr;</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Dispatch Ledger</h3>
                <p class="card-subtitle">All outbound dispatches with payment status</p>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Dispatch #</th><th>Date</th><th>Customer</th><th>Bundles</th><th>Units</th><th>Total</th><th>Paid</th><th>Balance</th><th></th></tr></thead>
                <tbody>
                    <?php if (empty($dispatches)): ?>
                        <tr><td colspan="9" class="empty-state">No dispatches yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($dispatches as $d):
                            $bal = (float)$d['total_amount'] - (float)$d['amount_paid'];
                        ?>
                            <tr>
                                <td class="mono"><strong><?= htmlspecialchars($d['dispatch_no']) ?></strong></td>
                                <td><?= formatDate($d['dispatch_date']) ?></td>
                                <td><strong><?= htmlspecialchars($d['customer_name']) ?></strong></td>
                                <td><?= (int)$d['bundles'] ?></td>
                                <td><?= formatNumber((int)$d['units']) ?></td>
                                <td><?= formatMoney((float)$d['total_amount']) ?></td>
                                <td style="color:var(--success);"><?= formatMoney((float)$d['amount_paid']) ?></td>
                                <td style="color: <?= $bal > 0 ? 'var(--danger)' : 'var(--success)' ?>; font-weight:700;"><?= formatMoney($bal) ?></td>
                                <td>
                                    <?php if ($bal > 0): ?>
                                        <form method="POST" action="/dispatch.php" style="display:flex; gap:4px;">
                                            <input type="hidden" name="dispatch_action" value="record_payment">
                                            <input type="hidden" name="dispatch_id" value="<?= (int)$d['id'] ?>">
                                            <input type="number" name="payment" min="1" step="1" placeholder="TZS" required class="form-control" style="width:90px; padding:4px 8px; font-size:12px;">
                                            <button type="submit" class="btn btn-success btn-sm">+Pay</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge badge-success">Settled</span>
                                    <?php endif; ?>
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
