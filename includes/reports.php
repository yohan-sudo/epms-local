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
            'roles'       => ['CEO', 'Manager', 'Accountant'],
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
            'roles'       => ['CEO', 'Manager', 'Accountant'],
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
            'roles'       => ['CEO', 'Manager', 'Accountant'],
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
            'roles'       => ['CEO', 'Manager', 'Accountant', 'Procurement Officer'],
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
