<?php
/**
 * U EPMS - Report Definitions
 * Every report template: label, description, columns, SQL builder and
 * post-processing. Used by reports.php for preview + PDF/XLSX download,
 * and for combined (multi-report) documents.
 */

require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/xlsx.php';

/**
 * @return array<string, array{label:string, description:string, group:string, roles:string[], columns:array, build:callable}>
 */
function report_registry(): array
{
    return [
        'production' => [
            'label'       => 'Production Shift Report',
            'description' => 'Daily shift output, QA yield and reject breakdown by machine and supervisor.',
            'group'       => 'Production',
            'roles'       => ['CEO', 'Manager', 'Supervisor', 'Assistant Manager'],
            'columns'     => [
                ['key' => 'report_date',   'label' => 'Date',        'width' => 20, 'align' => 'L', 'type' => 'date'],
                ['key' => 'shift',         'label' => 'Shift',       'width' => 30, 'align' => 'L', 'type' => 'text'],
                ['key' => 'machine',       'label' => 'Machine',     'width' => 24, 'align' => 'L', 'type' => 'text'],
                ['key' => 'process_name',  'label' => 'Process',     'width' => 26, 'align' => 'L', 'type' => 'text'],
                ['key' => 'units_produced','label' => 'Produced',    'width' => 18, 'align' => 'R', 'type' => 'int'],
                ['key' => 'good_units',    'label' => 'Good',        'width' => 16, 'align' => 'R', 'type' => 'int'],
                ['key' => 'scrap',         'label' => 'Scrap',       'width' => 14, 'align' => 'R', 'type' => 'int'],
                ['key' => 'partial',       'label' => 'Reworkable',  'width' => 18, 'align' => 'R', 'type' => 'int'],
                ['key' => 'yield_pct',     'label' => 'Yield %',     'width' => 16, 'align' => 'R', 'type' => 'pct'],
            ],
            'build' => function (string $from, string $to): array {
                $db = $GLOBALS['db'];
                $stmt = $db->prepare("
                    SELECT r.report_date, r.shift,
                           CONCAT(m.code, ' - ', m.name) AS machine,
                           COALESCE(p.name, 'General') AS process_name,
                           r.units_produced, r.good_units,
                           COALESCE(l.total_reject_count, 0) AS scrap,
                           COALESCE(l.partial_reject_count, 0) AS partial
                    FROM daily_reports r
                    JOIN machines m ON r.machine_id = m.id
                    LEFT JOIN process_reject_logs l ON l.report_id = r.id
                    LEFT JOIN processes p ON l.process_id = p.id
                    WHERE r.report_date BETWEEN :from AND :to
                    ORDER BY r.report_date DESC, r.id DESC
                ");
                $stmt->execute([':from' => $from, ':to' => $to]);
                $rows = $stmt->fetchAll();
                foreach ($rows as &$row) {
                    $produced = (int)$row['units_produced'];
                    $row['yield_pct'] = $produced > 0 ? round(((int)$row['good_units'] / $produced) * 100, 1) : 0;
                    $row['_summary'] = true; // numeric totals column
                    $row['_sum_cols'] = ['units_produced', 'good_units', 'scrap', 'partial'];
                }
                unset($row);
                return $rows;
            },
        ],

        'petty_cash_floats' => [
            'label'       => 'Petty Cash Floats Report',
            'description' => 'All float vouchers: custodian, purpose, issued vs expensed vs remaining balance.',
            'group'       => 'Petty Cash',
            'roles'       => ['CEO', 'Manager', 'Supervisor', 'Assistant Manager'],
            'columns'     => [
                ['key' => 'voucher_no',    'label' => 'Voucher #',   'width' => 24, 'align' => 'L', 'type' => 'text'],
                ['key' => 'issued_date',   'label' => 'Issued',      'width' => 20, 'align' => 'L', 'type' => 'date'],
                ['key' => 'custodian',     'label' => 'Custodian',   'width' => 30, 'align' => 'L', 'type' => 'text'],
                ['key' => 'issuer',        'label' => 'Issued By',   'width' => 26, 'align' => 'L', 'type' => 'text'],
                ['key' => 'purpose',       'label' => 'Purpose',     'width' => 40, 'align' => 'L', 'type' => 'text'],
                ['key' => 'amount',        'label' => 'Float (TZS)', 'width' => 24, 'align' => 'R', 'type' => 'money'],
                ['key' => 'spent',         'label' => 'Expensed',    'width' => 22, 'align' => 'R', 'type' => 'money'],
                ['key' => 'remaining',     'label' => 'Remaining',   'width' => 22, 'align' => 'R', 'type' => 'money'],
            ],
            'build' => function (string $from, string $to): array {
                $db = $GLOBALS['db'];
                $stmt = $db->prepare("
                    SELECT i.voucher_no, i.issued_date,
                           COALESCE(ut.name, 'Unassigned') AS custodian,
                           COALESCE(ub.name, 'Unknown') AS issuer,
                           i.purpose, i.amount,
                           COALESCE(SUM(e.amount), 0) AS spent
                    FROM petty_cash_issuances i
                    LEFT JOIN users ut ON i.issued_to = ut.id
                    LEFT JOIN users ub ON i.issued_by = ub.id
                    LEFT JOIN petty_cash_expenses e ON e.issuance_id = i.id
                    WHERE i.issued_date BETWEEN :from AND :to
                    GROUP BY i.id
                    ORDER BY i.issued_date DESC, i.id DESC
                ");
                $stmt->execute([':from' => $from, ':to' => $to]);
                $rows = $stmt->fetchAll();
                foreach ($rows as &$row) {
                    $row['remaining'] = (float)$row['amount'] - (float)$row['spent'];
                    $row['_sum_cols'] = ['amount', 'spent', 'remaining'];
                }
                unset($row);
                return $rows;
            },
        ],

        'petty_cash_expenses' => [
            'label'       => 'Petty Cash Expense Ledger',
            'description' => 'Itemized expenses with category, receipt number and recording officer.',
            'group'       => 'Petty Cash',
            'roles'       => ['CEO', 'Manager', 'Supervisor', 'Assistant Manager'],
            'columns'     => [
                ['key' => 'expense_date',  'label' => 'Date',        'width' => 20, 'align' => 'L', 'type' => 'date'],
                ['key' => 'voucher_no',    'label' => 'Voucher #',   'width' => 24, 'align' => 'L', 'type' => 'text'],
                ['key' => 'category',      'label' => 'Category',    'width' => 28, 'align' => 'L', 'type' => 'text'],
                ['key' => 'description',   'label' => 'Description', 'width' => 46, 'align' => 'L', 'type' => 'text'],
                ['key' => 'receipt_no',    'label' => 'Receipt #',   'width' => 22, 'align' => 'L', 'type' => 'text'],
                ['key' => 'amount',        'label' => 'Amount',      'width' => 20, 'align' => 'R', 'type' => 'money'],
                ['key' => 'recorded_by',   'label' => 'Recorded By', 'width' => 26, 'align' => 'L', 'type' => 'text'],
            ],
            'build' => function (string $from, string $to): array {
                $db = $GLOBALS['db'];
                $stmt = $db->prepare("
                    SELECT e.expense_date, i.voucher_no, e.category, e.description, e.receipt_no,
                           e.amount, COALESCE(u.name, 'Unknown') AS recorded_by
                    FROM petty_cash_expenses e
                    JOIN petty_cash_issuances i ON e.issuance_id = i.id
                    LEFT JOIN users u ON e.approved_by = u.id
                    WHERE e.expense_date BETWEEN :from AND :to
                    ORDER BY e.expense_date DESC, e.id DESC
                ");
                $stmt->execute([':from' => $from, ':to' => $to]);
                $rows = $stmt->fetchAll();
                foreach ($rows as &$row) {
                    $row['_sum_cols'] = ['amount'];
                }
                unset($row);
                return $rows;
            },
        ],

        'procurement_records' => [
            'label'       => 'Procurement Records Report',
            'description' => 'All procurement records with supplier, quantities, value and approval status.',
            'group'       => 'Procurement',
            'roles'       => ['CEO', 'Manager', 'Procurement Officer'],
            'columns'     => [
                ['key' => 'reference_no',  'label' => 'Ref No.',     'width' => 26, 'align' => 'L', 'type' => 'text'],
                ['key' => 'date',          'label' => 'Date',        'width' => 20, 'align' => 'L', 'type' => 'date'],
                ['key' => 'supplier',      'label' => 'Supplier',    'width' => 30, 'align' => 'L', 'type' => 'text'],
                ['key' => 'item_name',     'label' => 'Item',        'width' => 34, 'align' => 'L', 'type' => 'text'],
                ['key' => 'qty_unit',      'label' => 'Qty',         'width' => 16, 'align' => 'R', 'type' => 'int'],
                ['key' => 'total_cost',    'label' => 'Total (TZS)', 'width' => 24, 'align' => 'R', 'type' => 'money'],
                ['key' => 'status',        'label' => 'Status',      'width' => 30, 'align' => 'L', 'type' => 'text'],
                ['key' => 'submitter',     'label' => 'Submitted By','width' => 26, 'align' => 'L', 'type' => 'text'],
            ],
            'build' => function (string $from, string $to): array {
                $db = $GLOBALS['db'];
                $stmt = $db->prepare("
                    SELECT p.reference_no, p.date, p.supplier, p.item_name,
                           p.quantity, p.unit, p.total_cost, p.status,
                           COALESCE(u.name, 'Unknown') AS submitter
                    FROM procurement_entries p
                    LEFT JOIN users u ON p.submitted_by = u.id
                    WHERE p.date BETWEEN :from AND :to
                    ORDER BY p.date DESC, p.id DESC
                ");
                $stmt->execute([':from' => $from, ':to' => $to]);
                $rows = $stmt->fetchAll();
                foreach ($rows as &$row) {
                    $row['qty_unit'] = rtrim(rtrim(number_format((float)$row['quantity'], 2), '0'), '.') . ' ' . $row['unit'];
                    $row['_sum_cols'] = ['total_cost'];
                }
                unset($row);
                return $rows;
            },
        ],

        'procurement_activity' => [
            'label'       => 'Procurement Activity by Officer',
            'description' => 'Per-officer workload and turnaround: submissions, approvals, rejections, value and average days to finalize.',
            'group'       => 'Procurement',
            'roles'       => ['CEO', 'Manager'],
            'columns'     => [
                ['key' => 'officer',        'label' => 'Officer',            'width' => 30, 'align' => 'L', 'type' => 'text'],
                ['key' => 'submitted',      'label' => 'Submitted',          'width' => 14, 'align' => 'R', 'type' => 'int'],
                ['key' => 'finalized',      'label' => 'Approved & Locked',  'width' => 18, 'align' => 'R', 'type' => 'int'],
                ['key' => 'rejected',       'label' => 'Rejected',           'width' => 12, 'align' => 'R', 'type' => 'int'],
                ['key' => 'pending',        'label' => 'Still Open',         'width' => 14, 'align' => 'R', 'type' => 'int'],
                ['key' => 'total_value',    'label' => 'Value (TZS)',        'width' => 22, 'align' => 'R', 'type' => 'money'],
                ['key' => 'avg_days',       'label' => 'Avg Days to Final',  'width' => 18, 'align' => 'R', 'type' => 'pct'],
            ],
            'build' => function (string $from, string $to): array {
                $db = $GLOBALS['db'];
                $stmt = $db->prepare("
                    SELECT COALESCE(u.name, 'Unknown') AS officer,
                           COUNT(*) AS submitted,
                           SUM(CASE WHEN p.status = 'Finalized' THEN 1 ELSE 0 END) AS finalized,
                           SUM(CASE WHEN p.status = 'Rejected' THEN 1 ELSE 0 END) AS rejected,
                           SUM(CASE WHEN p.status NOT IN ('Finalized','Rejected') THEN 1 ELSE 0 END) AS pending,
                           COALESCE(SUM(p.total_cost), 0) AS total_value,
                           COALESCE(ROUND(AVG(CASE WHEN p.status = 'Finalized' AND p.accountant_approved_at IS NOT NULL
                                                   THEN DATEDIFF(p.accountant_approved_at, p.date) END), 1), 0) AS avg_days
                    FROM procurement_entries p
                    LEFT JOIN users u ON p.submitted_by = u.id
                    WHERE p.date BETWEEN :from AND :to
                    GROUP BY u.id, u.name
                    ORDER BY submitted DESC
                ");
                $stmt->execute([':from' => $from, ':to' => $to]);
                return $stmt->fetchAll();
            },
        ],

        'profit_and_loss' => [
            'label'       => 'Profit and Loss Summary',
            'description' => 'Revenue from dispatched goods against purchases, petty cash expenses and reject losses for the period.',
            'group'       => 'Finance',
            'roles'       => ['CEO', 'Manager', 'Supervisor', 'Assistant Manager'],
            'columns'     => [
                ['key' => 'section',  'label' => 'Section',        'width' => 22, 'align' => 'L', 'type' => 'text'],
                ['key' => 'item',     'label' => 'Item',           'width' => 44, 'align' => 'L', 'type' => 'text'],
                ['key' => 'amount',   'label' => 'Amount (TZS)',   'width' => 26, 'align' => 'R', 'type' => 'money'],
                ['key' => 'note',     'label' => 'Note',           'width' => 40, 'align' => 'L', 'type' => 'text'],
            ],
            'build' => function (string $from, string $to): array {
                $db = $GLOBALS['db'];
                $stmt = $db->prepare("SELECT
                        (SELECT COALESCE(SUM(total_amount),0) FROM dispatches WHERE dispatch_date BETWEEN :f1 AND :t1) AS rev_billed,
                        (SELECT COALESCE(SUM(amount_paid),0)  FROM dispatches WHERE dispatch_date BETWEEN :f2 AND :t2) AS rev_paid,
                        (SELECT COALESCE(SUM(total_cost),0) FROM procurement_entries WHERE status='Finalized' AND date BETWEEN :f3 AND :t3) AS purch,
                        (SELECT COALESCE(SUM(amount),0) FROM petty_cash_expenses WHERE expense_date BETWEEN :f4 AND :t4) AS petty,
                        (SELECT COALESCE(SUM(pc.reject_cost_per_unit * (l.partial_reject_count + l.total_reject_count)),0)
                            FROM process_reject_logs l
                            JOIN processes pc ON l.process_id = pc.id
                            JOIN daily_reports dr ON l.report_id = dr.id
                            WHERE dr.report_date BETWEEN :f5 AND :t5) AS rejects");
                $stmt->execute([':f1'=>$from,':t1'=>$to,':f2'=>$from,':t2'=>$to,':f3'=>$from,':t3'=>$to,':f4'=>$from,':t4'=>$to,':f5'=>$from,':t5'=>$to]);
                $m = $stmt->fetch() ?: [];
                $revBilled = (float)($m['rev_billed'] ?? 0);
                $revPaid   = (float)($m['rev_paid'] ?? 0);
                $purch     = (float)($m['purch'] ?? 0);
                $petty     = (float)($m['petty'] ?? 0);
                $rejects   = (float)($m['rejects'] ?? 0);
                $totalCost = $purch + $petty + $rejects;
                $net       = $revBilled - $totalCost;
                return [
                    ['section' => 'Revenue',   'item' => 'Goods dispatched (billed)',            'amount' => $revBilled,                    'note' => 'value of goods sent to customers'],
                    ['section' => 'Revenue',   'item' => 'Cash collected',                       'amount' => $revPaid,                      'note' => ($revBilled > 0 ? round($revPaid / $revBilled * 100) : 0) . '% of billed'],
                    ['section' => 'Costs',     'item' => 'Materials purchased (finalized)',      'amount' => $purch,                        'note' => 'approved and locked procurement'],
                    ['section' => 'Costs',     'item' => 'Petty cash expenses',                  'amount' => $petty,                        'note' => 'receipt-backed spending'],
                    ['section' => 'Costs',     'item' => 'Production rejects (est. value)',      'amount' => $rejects,                      'note' => 'units scrapped or reworked x unit cost'],
                    ['section' => 'Result',    'item' => 'NET POSITION (billed - all costs)',    'amount' => $net,                          'note' => $net >= 0 ? 'surplus' : 'deficit'],
                ];
            },
        ],

        'manager_summary' => [
            'label'       => 'Manager Summary (Plain-Language)',
            'description' => 'One row per key indicator with a plain-sentence reading of the trend - the automated summary report.',
            'group'       => 'Management',
            'roles'       => ['CEO', 'Manager', 'Supervisor', 'Assistant Manager'],
            'columns'     => [
                ['key' => 'area',    'label' => 'Area',            'width' => 22, 'align' => 'L', 'type' => 'text'],
                ['key' => 'metric',  'label' => 'Indicator',       'width' => 34, 'align' => 'L', 'type' => 'text'],
                ['key' => 'value',   'label' => 'Value',           'width' => 22, 'align' => 'L', 'type' => 'text'],
                ['key' => 'reading', 'label' => 'What It Means',   'width' => 70, 'align' => 'L', 'type' => 'text'],
            ],
            'build' => function (string $from, string $to): array {
                $db = $GLOBALS['db'];
                $q = function (string $sql) use ($db): float {
                    return (float)$db->query($sql)->fetchColumn();
                };
                $safe = fn (string $s): string => preg_replace('/[\'\\"]/', '', $s);
                $prevFrom = date('Y-m-d', strtotime($from . ' -1 month'));
                $prevTo   = date('Y-m-d', strtotime($to . ' -1 month'));

                $prod = $q("SELECT COALESCE(SUM(units_produced),0) FROM daily_reports WHERE report_date BETWEEN '" . $safe($from) . "' AND '" . $safe($to) . "'");
                $prodPrev = $q("SELECT COALESCE(SUM(units_produced),0) FROM daily_reports WHERE report_date BETWEEN '" . $safe($prevFrom) . "' AND '" . $safe($prevTo) . "'");
                $rejects = $q("SELECT COALESCE(SUM(l.partial_reject_count + l.total_reject_count),0) FROM process_reject_logs l JOIN daily_reports dr ON l.report_id = dr.id WHERE dr.report_date BETWEEN '" . $safe($from) . "' AND '" . $safe($to) . "'");
                $rejectCost = $q("SELECT COALESCE(SUM(pc.reject_cost_per_unit * (l.partial_reject_count + l.total_reject_count)),0) FROM process_reject_logs l JOIN processes pc ON l.process_id = pc.id JOIN daily_reports dr ON l.report_id = dr.id WHERE dr.report_date BETWEEN '" . $safe($from) . "' AND '" . $safe($to) . "'");
                $downMin = $q("SELECT COALESCE(SUM(minutes),0) FROM machine_downtime WHERE report_date BETWEEN '" . $safe($from) . "' AND '" . $safe($to) . "'");
                $pcIssued = $q("SELECT COALESCE(SUM(amount),0) FROM petty_cash_issuances WHERE status IN ('Active','Closed') AND issued_date BETWEEN '" . $safe($from) . "' AND '" . $safe($to) . "'");
                $pcExp = $q("SELECT COALESCE(SUM(amount),0) FROM petty_cash_expenses WHERE expense_date BETWEEN '" . $safe($from) . "' AND '" . $safe($to) . "'");
                $budget = (float)getSetting($db, 'petty_cash_monthly_budget', '10000000');
                $revBilled = $q("SELECT COALESCE(SUM(total_amount),0) FROM dispatches WHERE dispatch_date BETWEEN '" . $safe($from) . "' AND '" . $safe($to) . "'");
                $revPaid = $q("SELECT COALESCE(SUM(amount_paid),0) FROM dispatches WHERE dispatch_date BETWEEN '" . $safe($from) . "' AND '" . $safe($to) . "'");
                $poOpen = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status NOT IN ('Finalized','Rejected')")->fetchColumn();
                $poOld = $db->query("SELECT COALESCE(DATEDIFF(CURRENT_DATE, MIN(date)),0) FROM procurement_entries WHERE status NOT IN ('Finalized','Rejected')")->fetchColumn();

                $pct = function (float $now, float $prev): string {
                    if ($prev <= 0) { return $now > 0 ? 'new activity this period' : 'no activity'; }
                    $d = round(($now - $prev) / $prev * 100);
                    return ($d >= 0 ? 'up ' : 'down ') . abs($d) . '% vs previous month';
                };
                $budgetPct = $budget > 0 ? round($pcIssued / $budget * 100) : 0;

                return [
                    ['area' => 'Production',  'metric' => 'Units produced',            'value' => number_format($prod),
                     'reading' => $pct($prod, $prodPrev)],
                    ['area' => 'Quality',     'metric' => 'Rejects (rework + scrap)',  'value' => number_format($rejects) . ' units (' . formatMoney($rejectCost) . ')',
                     'reading' => $rejectCost > 0 ? 'waste worth ' . formatMoney($rejectCost) . ' this period' : 'no recorded reject value'],
                    ['area' => 'Machines',    'metric' => 'Downtime',                  'value' => number_format($downMin) . ' minutes',
                     'reading' => $downMin > 240 ? 'high downtime - investigate causes' : ($downMin > 0 ? 'within a normal band' : 'no downtime logged')],
                    ['area' => 'Petty cash',  'metric' => 'Issued vs budget',          'value' => formatMoney($pcIssued) . ' (' . $budgetPct . '%)',
                     'reading' => $budgetPct >= 100 ? 'OVER budget - stop non-urgent spending' : ($budgetPct >= 80 ? 'close to the monthly limit' : 'within budget')],
                    ['area' => 'Petty cash',  'metric' => 'Expenses recorded',         'value' => formatMoney($pcExp),
                     'reading' => 'receipts against floats issued this period'],
                    ['area' => 'Sales',       'metric' => 'Goods dispatched',          'value' => formatMoney($revBilled),
                     'reading' => $revPaid >= $revBilled && $revBilled > 0 ? 'fully collected' : formatMoney($revBilled - $revPaid) . ' still owed by customers'],
                    ['area' => 'Procurement', 'metric' => 'Records still open',        'value' => $poOpen . ' record(s)',
                     'reading' => $poOld > 3 ? 'oldest has waited ' . (int)$poOld . ' days - follow up' : 'turnaround is healthy'],
                ];
            },
        ],

        'audit' => [
            'label'       => 'System Audit Trail Report',
            'description' => 'Security-relevant events: logins, approvals, data changes with actor and timestamp.',
            'group'       => 'Administration',
            'roles'       => ['CEO'],
            'columns'     => [
                ['key' => 'timestamp',     'label' => 'Timestamp',   'width' => 30, 'align' => 'L', 'type' => 'text'],
                ['key' => 'actor',         'label' => 'Actor',       'width' => 26, 'align' => 'L', 'type' => 'text'],
                ['key' => 'role',          'label' => 'Role',        'width' => 24, 'align' => 'L', 'type' => 'text'],
                ['key' => 'action',        'label' => 'Action',      'width' => 30, 'align' => 'L', 'type' => 'text'],
                ['key' => 'entity',        'label' => 'Entity',      'width' => 20, 'align' => 'L', 'type' => 'text'],
                ['key' => 'details',       'label' => 'Details',     'width' => 56, 'align' => 'L', 'type' => 'text'],
            ],
            'build' => function (string $from, string $to): array {
                $db = $GLOBALS['db'];
                $stmt = $db->prepare("
                    SELECT a.timestamp,
                           COALESCE(u.name, 'System') AS actor,
                           COALESCE(u.role, 'System') AS role,
                           a.action,
                           CONCAT(a.entity_type, ' #', a.entity_id) AS entity,
                           a.details
                    FROM audit_logs a
                    LEFT JOIN users u ON a.actor_id = u.id
                    WHERE DATE(a.timestamp) BETWEEN :from AND :to
                    ORDER BY a.timestamp DESC
                ");
                $stmt->execute([':from' => $from, ':to' => $to]);
                return $stmt->fetchAll();
            },
        ],
    ];
}

/**
 * Reports a role may access.
 * @return array<string, array{label:string, description:string, group:string}>
 */
function reports_for_role(string $role): array
{
    $out = [];
    foreach (report_registry() as $key => $r) {
        if (in_array($role, $r['roles'], true)) {
            $out[$key] = ['label' => $r['label'], 'description' => $r['description'], 'group' => $r['group']];
        }
    }
    return $out;
}
