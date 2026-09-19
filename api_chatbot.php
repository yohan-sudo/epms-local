<?php
/**
 * U EPMS - Chatbot (v2.3)
 * Rule-based assistant, fully local (no external services).
 *
 *   C.E.O  -> a full overview of the whole system: money in/out, production,
 *             inventory, shipments, workflow status. Ask anything.
 *   Others -> ONLY reminders of their own pending approvals/requests and
 *             unread notifications. It never reveals data outside the role.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireAuth();
$role = $_SESSION['user_role'] ?? 'Guest';
$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['reply' => 'Internal error.']);
    exit;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
$message = mb_strtolower(mb_substr(trim((string)($in['message'] ?? '')), 0, 200));

function jout(string $reply, array $data = []): void {
    echo json_encode(['reply' => $reply, 'data' => $data]);
    exit;
}



/* The CEO: answer with live aggregates over the whole system. */
if ($role === 'CEO') {
    $stats = [];
    try {
        $stats['finalized_spend'] = (float)$db->query("SELECT COALESCE(SUM(total_cost),0) FROM procurement_entries WHERE status='Finalized'")->fetchColumn();
        $stats['pending_po'] = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status NOT IN ('Finalized','Rejected')")->fetchColumn();
        $stats['open_float'] = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM petty_cash_issuances WHERE status='Active'")->fetchColumn();
        $stats['pending_cash_requests'] = (int)$db->query("SELECT COUNT(*) FROM cash_requests WHERE status='Pending CEO Approval'")->fetchColumn();
        $stats['approved_not_disbursed'] = (int)$db->query("SELECT COUNT(*) FROM cash_requests WHERE status='Approved - Sent to Accountant'")->fetchColumn();
        $stats['revenue'] = (float)$db->query('SELECT COALESCE(SUM(total_amount),0) FROM dispatches')->fetchColumn();
        $stats['receivables'] = (float)$db->query('SELECT COALESCE(SUM(total_amount - amount_paid),0) FROM dispatches')->fetchColumn();
        $stats['units_produced'] = (int)$db->query('SELECT COALESCE(SUM(units_produced),0) FROM daily_reports')->fetchColumn();
        $stats['rejects'] = (int)$db->query('SELECT COALESCE(SUM(total_reject_count),0) FROM process_reject_logs')->fetchColumn();
        $stats['pending_prod_verify'] = (int)$db->query("SELECT COUNT(*) FROM daily_reports WHERE approval_status='Pending Manager Approval'")->fetchColumn();
        $stats['low_stock'] = (int)$db->query('SELECT COUNT(*) FROM inventory_items WHERE quantity <= reorder_level')->fetchColumn();
        $stats['pending_inventory_reqs'] = (int)$db->query("SELECT COUNT(*) FROM inventory_requests WHERE status='Pending Manager Approval'")->fetchColumn();
        $stats['shipments_awaiting'] = (int)$db->query("SELECT COUNT(*) FROM shipment_orders WHERE status IN ('Requested by CEO','Prepared - Awaiting Manager Approval')")->fetchColumn();
        $stats['corrections_pending'] = (int)$db->query("SELECT COUNT(*) FROM correction_requests WHERE status='Pending CEO Approval'")->fetchColumn();
        $stats['failures_open'] = (int)$db->query("SELECT COUNT(*) FROM machine_failures WHERE status IN ('Pending Verification','Verified - Repair Needed')")->fetchColumn();
        $stats['users_active'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE status='Active'")->fetchColumn();
        $stats['workers'] = (int)$db->query('SELECT COUNT(*) FROM workers WHERE status="Active"')->fetchColumn();
        $stats['present_today'] = (int)$db->query('SELECT COUNT(DISTINCT worker_id) FROM worker_attendance WHERE attend_date = CURRENT_DATE')->fetchColumn();
    } catch (Exception $e) {
        jout('I could not read the system data just now. Please try again.');
    }

    $m = fn (float $v): string => formatMoney($v);

    // Money questions
    if (preg_match('/spend|spent|procurement|purchase/', $message)) {
        jout("Total finalized procurement spend is {$m($stats['finalized_spend'])}. There are {$stats['pending_po']} procurement record(s) still in the pipeline.", $stats);
    }
    if (preg_match('/petty|float/', $message)) {
        jout("Active petty cash floats total {$m($stats['open_float'])} with the Accountant.", $stats);
    }
    if (preg_match('/revenue|sales|income/', $message)) {
        jout("Recorded dispatch revenue is {$m($stats['revenue'])}. Customers still owe {$m($stats['receivables'])}.", $stats);
    }
    if (preg_match('/receivab|debt|owe/', $message)) {
        jout("Customer receivables stand at {$m($stats['receivables'])} across recorded dispatches.", $stats);
    }
    if (preg_match('/cash request/', $message)) {
        jout("{$stats['pending_cash_requests']} cash request(s) await your approval and {$stats['approved_not_disbursed']} approved request(s) wait for the Accountant to disburse.", $stats);
    }
    if (preg_match('/production|produced|units|output/', $message)) {
        jout("Total units processed across all shifts: " . number_format($stats['units_produced']) . ", with " . number_format($stats['rejects']) . " scrapped. {$stats['pending_prod_verify']} shift log(s) still await the Manager's verification.", $stats);
    }
    if (preg_match('/reject|scrap|waste/', $message)) {
        jout("Total scrapped units: " . number_format($stats['rejects']) . " out of " . number_format($stats['units_produced']) . " processed.", $stats);
    }
    if (preg_match('/inventory|stock|materials?/', $message)) {
        jout("Inventory: {$stats['low_stock']} item(s) are at or below their reorder level. {$stats['pending_inventory_reqs']} material request(s) await the Manager's approval.", $stats);
    }
    if (preg_match('/shipment|dispatch|market/', $message)) {
        jout("{$stats['shipments_awaiting']} shipment(s) are in progress. Recorded dispatch revenue: {$m($stats['revenue'])}.", $stats);
    }
    if (preg_match('/correction|mistake|fix/', $message)) {
        jout("{$stats['corrections_pending']} correction request(s) await your approval.", $stats);
    }
    if (preg_match('/machine|failure|breakdown|repair/', $message)) {
        jout("{$stats['failures_open']} machine failure report(s) need attention.", $stats);
    }
    if (preg_match('/worker|attendance|present|who came|staff/', $message)) {
        jout("{$stats['workers']} workers registered; {$stats['present_today']} marked present today.", $stats);
    }
    if (preg_match('/user|account|staff count|team/', $message)) {
        jout("{$stats['users_active']} active system accounts, {$stats['workers']} workers registered for attendance.", $stats);
    }
    if (preg_match('/overview|summary|status|everything|how (is|are)|report/', $message)) {
        $reply = "Here is the whole system at a glance:\n"
            . "• Money: finalized procurement {$m($stats['finalized_spend'])}, active floats {$m($stats['open_float'])}, revenue {$m($stats['revenue'])}, receivables {$m($stats['receivables'])}\n"
            . "• Production: " . number_format($stats['units_produced']) . " units processed, " . number_format($stats['rejects']) . " scrapped, {$stats['pending_prod_verify']} log(s) awaiting verification\n"
            . "• Inventory: {$stats['low_stock']} item(s) low on stock, {$stats['pending_inventory_reqs']} request(s) awaiting approval\n"
            . "• Workflow: {$stats['pending_po']} procurement record(s) open, {$stats['pending_cash_requests']} cash request(s) for you, {$stats['shipments_awaiting']} shipment(s) in progress, {$stats['corrections_pending']} correction(s) for you\n"
            . "• People: {$stats['users_active']} accounts, {$stats['workers']} workers ({$stats['present_today']} present today)";
        jout($reply, $stats);
    }
    // Default: greet + guide
    jout("I can give you the full picture. Try asking: \"overview\", \"spend\", \"petty cash\", \"revenue\", \"production\", \"inventory\", \"shipments\", \"cash requests\", \"corrections\", \"machine failures\" or \"attendance\".", $stats);
}

/* ---------- Everyone else: reminders ONLY, strictly role-scoped ---------- */
try {
    $unreadStmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0');
    $unreadStmt->execute([':u' => $userId]);
    $unread = (int)$unreadStmt->fetchColumn();

    $recent = $db->prepare('SELECT title, body, link, created_at FROM notifications WHERE user_id = :u AND is_read = 0 ORDER BY id DESC LIMIT 3');
    $recent->execute([':u' => $userId]);
    $recentItems = $recent->fetchAll();

    $mine = [];
    if ($role === 'Manager') {
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status IN ('Pending Manager Review','Pending Approval')")->fetchColumn() . ' procurement record(s) awaiting your review';
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM daily_reports WHERE approval_status='Pending Manager Approval'")->fetchColumn() . ' production log(s) awaiting your verification';
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM inventory_requests WHERE status='Pending Manager Approval'")->fetchColumn() . ' material request(s) awaiting your approval';
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM shipment_orders WHERE status='Prepared - Awaiting Manager Approval'")->fetchColumn() . ' shipment(s) awaiting your approval';
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM cash_requests WHERE status='Disbursed - Awaiting Confirmation' AND requested_by = {$userId}")->fetchColumn() . ' cash disbursement(s) you have not confirmed';
    } elseif ($role === 'Accountant') {
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM cash_requests WHERE status='Approved - Sent to Accountant'")->fetchColumn() . ' approved cash request(s) to disburse';
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM cash_requests WHERE status='Disbursed - Awaiting Confirmation' AND requested_by = {$userId}")->fetchColumn() . ' disbursement(s) you have not confirmed';
    } elseif ($role === 'Procurement Officer') {
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE submitted_by = {$userId} AND status NOT IN ('Finalized','Rejected')")->fetchColumn() . ' of your procurement record(s) still in the pipeline';
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE requisition_status='Pending CEO Approval' AND submitted_by = {$userId}")->fetchColumn() . ' requisition(s) awaiting the C.E.O';
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM inventory_requests WHERE status='Approved - Awaiting Release'")->fetchColumn() . ' material request(s) ready for you to release';
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM shipment_orders WHERE status='Requested by CEO'")->fetchColumn() . ' shipment(s) awaiting your preparation';
    } elseif ($role === 'Supervisor') {
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM daily_reports WHERE supervisor_id = {$userId} AND approval_status='Pending Manager Approval'")->fetchColumn() . ' of your log(s) awaiting the Manager\'s verification';
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM inventory_requests WHERE requested_by = {$userId} AND status='Released - Awaiting Confirmation'")->fetchColumn() . ' material release(s) awaiting your receipt confirmation';
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM machine_failures WHERE reported_by = {$userId} AND status='Pending Verification'")->fetchColumn() . ' failure report(s) awaiting verification';
    } elseif ($role === 'Assistant Manager') {
        $mine[] = (int)$db->query("SELECT COUNT(*) FROM daily_reports WHERE approval_status='Pending Manager Approval'")->fetchColumn() . ' production log(s) pending verification (informational)';
    }

    $mine = array_values(array_filter($mine, fn ($x) => !str_starts_with($x, '0 ')));
    $lines = [];
    foreach ($mine as $mm) { $lines[] = '• ' . $mm; }
    foreach ($recentItems as $ri) {
        $lines[] = '🔔 [' . formatDateTime((string)$ri['created_at']) . '] ' . $ri['title'];
    }
    if (!$lines) {
        jout("Nothing needs your attention right now - you're all caught up. &#127881;");
    }
    jout(($unread > 0 ? "You have {$unread} unread notification(s). " : '') . "Here is what needs your attention:\n" . implode("\n", $lines));
} catch (Exception $e) {
    jout('I could not read your reminders just now. Please try again.');
}
