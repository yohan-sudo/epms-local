<?php
/**
 * U EPMS - Reports & Documents Module
 * - Report templates (production, petty cash, procurement records, audit)
 * - Filtered by date range (from/to), shared with the list pages
 * - Preview in the browser (HTML render of exactly what downloads)
 * - Download as PDF (pure-PHP engine) or Excel XLSX (pure-PHP engine)
 * - Combined reports: pick multiple templates -> one multi-section document
 *
 * Access: every role, but each template enforces its own role list
 * (the Audit Trail report remains CEO-only).
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/reports.php';

requireAuth();

$pageTitle = 'Reports & Documents';
$activeNav = 'reports';

$currentUserRole = $_SESSION['user_role'];
$currentUserName = $_SESSION['user_name'];

$registry = report_registry();
$available = reports_for_role($currentUserRole);

/* ------------------------------------------------------------------ *
 * Shared helpers for rendering
 * ------------------------------------------------------------------ */

/** Formats a cell value by its declared type. */
function render_report_value(array $col, $value): string
{
    switch ($col['type']) {
        case 'money':
            return formatMoney((float)$value);
        case 'date':
            return $value !== null && $value !== '' ? formatDate((string)$value) : '—';
        case 'int':
            return formatNumber((int)$value);
        case 'pct':
            return number_format((float)$value, 1) . '%';
        default:
            return (string)($value ?? '—');
    }
}

/** Raw value for spreadsheet cells (numeric stays numeric). $band = banded row styling. */
function xlsx_report_value(array $col, $value, bool $band = false)
{
    switch ($col['type']) {
        case 'money':
        case 'pct':
            return ['v' => round((float)$value, 2), 's' => $band ? 10 : 8];
        case 'int':
            return ['v' => (int)$value, 's' => $band ? 12 : 11];
        case 'date':
            return ['v' => (string)$value, 's' => $band ? 9 : 7];
        default:
            return ['v' => (string)($value ?? ''), 's' => $band ? 9 : 7];
    }
}

/* ------------------------------------------------------------------ *
 * Document generators (used by preview? No — preview is HTML. Used by downloads.)
 * ------------------------------------------------------------------ */

/**
 * Renders one report section into the PDF document.
 */
function pdf_render_section(UePdf $pdf, string $title, string $subtitle, array $columns, array $rows): void
{
    // Section title
    $pdf->needSpace(30);
    $pdf->setFont(13, 'B');
    $pdf->setX(14);
    $pdf->cell(0, 8, $title);
    $pdf->setX(14);
    $pdf->ln(8);
    $pdf->setFont(9);
    $pdf->setX(14);
    $pdf->cell(0, 5, $subtitle, 'L', [90, 105, 130]);
    $pdf->setX(14);
    $pdf->ln(10);

    if (empty($rows)) {
        $pdf->setFont(9, 'I');
        $pdf->setX(14);
        $pdf->cell(0, 8, 'No records found for the selected filters.');
        $pdf->setX(14);
        $pdf->ln(14);
        return;
    }

    // Column widths scaled to content width
    $totalW = array_sum(array_column($columns, 'width'));
    $scale = $pdf->contentWidth() / $totalW;
    $widths = array_map(static fn ($c) => $c['width'] * $scale, $columns);

    $drawHeader = static function () use ($pdf, $columns, $widths): void {
        $pdf->rect(14, $pdf->getY(), $pdf->contentWidth(), 8, [29, 78, 216]);
        $pdf->setFont(8.5, 'B');
        $pdf->setX(14);
        foreach ($columns as $i => $col) {
            $pdf->cell($widths[$i], 8, $col['label'], $col['align'] === 'R' ? 'R' : 'L', [255, 255, 255]);
        }
        $pdf->setX(14);
        $pdf->ln(8);
    };
    $drawHeader();

    $pdf->setFont(8);
    $sumCols = [];
    foreach ($rows as $idx => $row) {
        $sumCols = $row['_sum_cols'] ?? $sumCols;

        // Measure the tallest wrapped cell for this row
        $rowH = 7.0;
        $cellLines = [];
        foreach ($columns as $i => $col) {
            $text = (string)($row[$col['key']] ?? '');
            if ($col['type'] !== 'text') {
                $text = render_report_value($col, $row[$col['key']] ?? '');
            }
            $lines = $pdf->wrapText($text, $widths[$i] - 2);
            $cellLines[$i] = $lines;
            $rowH = max($rowH, count($lines) * 3.6 + 2.4);
        }

        if ($pdf->getY() + $rowH > $pdf->bottomLimit()) {
            $pdf->addPage();
            $drawHeader();
            $pdf->setFont(8);
        }

        if ($idx % 2 === 1) {
            $pdf->rect(14, $pdf->getY(), $pdf->contentWidth(), $rowH, [241, 245, 249]);
        }

        // Hairline rule under every row keeps the grid readable when printed
        $pdf->rect(14, $pdf->getY() + $rowH, $pdf->contentWidth(), 0.25, [203, 213, 225]);

        // Render EVERY wrapped line of each cell (previously only the first
        // line was drawn, silently truncating long text like purposes and
        // defect reasons). The text block is vertically centered in the row.
        $startY = $pdf->getY();
        $lineH = 3.6;
        foreach ($columns as $i => $col) {
            $lines = $cellLines[$i];
            $blockH = count($lines) * $lineH + 2.4;
            $pdf->setY($startY + max(0.0, ($rowH - $blockH) / 2.0));
            $colX = 14 + (float)array_sum(array_slice($widths, 0, $i));
            foreach ($lines as $line) {
                $pdf->setX($colX);
                $pdf->cell($widths[$i], $lineH, $line, $col['align'] === 'R' ? 'R' : 'L');
            }
        }
        $pdf->setX(14);
        $pdf->setY($startY + $rowH);
    }

    // Totals row
    if ($sumCols) {
        if ($pdf->getY() + 9 > $pdf->bottomLimit()) {
            $pdf->addPage();
            $drawHeader();
            $pdf->setFont(8);
        }
        $pdf->rect(14, $pdf->getY(), $pdf->contentWidth(), 8, [226, 232, 240]);
        $pdf->setFont(8.5, 'B');
        $pdf->setX(14);
        foreach ($columns as $i => $col) {
            if (in_array($col['key'], $sumCols, true)) {
                $sum = 0;
                foreach ($rows as $row) {
                    $sum += is_numeric($row[$col['key']] ?? null) ? (float)$row[$col['key']] : 0;
                }
                $text = $col['type'] === 'money'
                    ? formatMoney($sum)
                    : formatNumber((int)$sum);
                $pdf->cell($widths[$i], 8, $text, 'R');
            } elseif ($i === 0) {
                $pdf->cell($widths[$i], 8, 'TOTAL (' . count($rows) . ' rows)', 'L');
            } else {
                $pdf->cell($widths[$i], 8, '', 'L');
            }
        }
        $pdf->setX(14);
        $pdf->ln(8);
    }

    $pdf->ln(6);
}

/**
 * Builds the full PDF document for one or many report sections.
 * @param array<int, array{key:string, title:string, columns:array, rows:array, from:string, to:string}> $sections
 */
function build_pdf_document(array $sections, string $generatedBy): string
{
    $pdf = new UePdf();

    $pdf->setFooterCallback(static function (UePdf $p) use ($generatedBy): void {
        $p->setFont(7.5);
        $p->setY(285);
        $p->setX(14);
        $p->cell(0, 6, APP_NAME . '  •  Generated ' . date('M d, Y H:i') . ' by ' . $generatedBy . '  •  Amounts in ' . APP_CURRENCY, 'L', [120, 130, 150]);
        $p->setX(180);
        $p->setFont(7.5, 'B');
        $p->cell(16, 6, 'Page ' . $p->pageNo(), 'R');
    });

    // Cover header on first page
    $pdf->setFont(17, 'B');
    $pdf->setX(14);
    $pdf->cell(0, 10, APP_NAME);
    $pdf->setX(14);
    $pdf->ln(10);
    $pdf->setFont(10);
    $pdf->setX(14);
    $rangeDesc = [];
    $firstFrom = $sections[0]['from'] ?? '';
    $firstTo = $sections[0]['to'] ?? '';
    if ($firstFrom !== '') { $rangeDesc[] = 'From ' . formatDate($firstFrom); }
    if ($firstTo !== '') { $rangeDesc[] = 'to ' . formatDate($firstTo); }
    $pdf->cell(0, 6, implode(' • ', array_merge(
        count($sections) > 1 ? ['Combined Report (' . count($sections) . ' sections)'] : [$sections[0]['title']],
        $rangeDesc
    )), 'L', [90, 105, 130]);
    $pdf->setX(14);
    $pdf->ln(8);
    // divider
    $pdf->rect(14, $pdf->getY(), $pdf->contentWidth(), 0.8, [29, 78, 216]);
    $pdf->ln(6);

    foreach ($sections as $section) {
        pdf_render_section($pdf, $section['title'], $section['subtitle'], $section['columns'], $section['rows']);
    }

    return $pdf->output('');
}

/**
 * Builds the XLSX workbook for one or many report sections (one sheet each).
 * Layout: merged title banner -> meta line -> blank spacer -> frozen, filterable
 * header row -> banded data rows -> bold totals row. Column widths are sized
 * from the real cell contents so nothing renders clipped.
 */
function build_xlsx_document(array $sections, string $generatedBy): string
{
    $xlsx = new UeXlsx();
    $used = [];

    foreach ($sections as $idx => $section) {
        $name = mb_substr($section['title'], 0, 28);
        $base = $name;
        $n = 2;
        while (in_array($name, $used, true)) {
            $name = mb_substr($base, 0, 26) . ' ' . $n++;
        }
        $used[] = $name;
        $xlsx->addSheet($name);

        $s = $idx;
        $colCount = max(1, count($section['columns']));
        $lastColLetter = strtoupper(chr(64 + min($colCount, 26)));

        // Row 1: merged title banner across the full table width
        $xlsx->writeRow($s, [['v' => APP_NAME . '  —  ' . $section['title'], 's' => 3]]);
        $xlsx->mergeCells($s, 'A1:' . $lastColLetter . '1');

        // Row 2: period / generation meta
        $meta = 'Period: '
            . ($section['from'] !== '' ? $section['from'] : 'beginning') . ' to '
            . ($section['to'] !== '' ? $section['to'] : date('Y-m-d'))
            . '   •   Generated: ' . date('Y-m-d H:i') . ' by ' . $generatedBy
            . '   •   ' . count($section['rows']) . ' record(s)'
            . '   •   Currency: ' . APP_CURRENCY;
        $xlsx->writeRow($s, [['v' => $meta, 's' => 6]]);
        $xlsx->mergeCells($s, 'A2:' . $lastColLetter . '2');

        // Row 3: spacer
        $xlsx->writeRow($s, array_fill(0, $colCount, ''));

        // Row 4: frozen, filterable header
        $header = [];
        foreach ($section['columns'] as $col) {
            $header[] = ['v' => $col['label'], 's' => 2];
        }
        $xlsx->writeRow($s, $header, ['ht' => 20]);
        $headerRow = (int)$xlsx->lastRow($s);
        $xlsx->setFreezeHeaderRow($s, $headerRow);

        // Data rows with zebra banding
        $sumCols = [];
        $band = false;
        foreach ($section['rows'] as $row) {
            $sumCols = $row['_sum_cols'] ?? $sumCols;
            $cells = [];
            foreach ($section['columns'] as $col) {
                $cells[] = xlsx_report_value($col, $row[$col['key']] ?? '', $band);
            }
            $xlsx->writeRow($s, $cells);
            $band = !$band;
        }
        $lastDataRow = (int)$xlsx->lastRow($s);

        // Totals
        if ($sumCols && $section['rows']) {
            $totalRow = [];
            foreach ($section['columns'] as $ci => $col) {
                if (in_array($col['key'], $sumCols, true)) {
                    $sum = 0;
                    foreach ($section['rows'] as $row) {
                        $sum += is_numeric($row[$col['key']] ?? null) ? (float)$row[$col['key']] : 0;
                    }
                    $totalRow[] = ['v' => round($sum, 2), 's' => 4];
                } elseif ($ci === 0) {
                    $totalRow[] = ['v' => 'TOTAL (' . count($section['rows']) . ' rows)', 's' => 4];
                } else {
                    $totalRow[] = ['v' => '', 's' => 4];
                }
            }
            $xlsx->writeRow($s, $totalRow, ['ht' => 18]);
        }

        // Auto-filter across header + data (not the totals row)
        $xlsx->setAutoFilter($s, $headerRow, max($lastDataRow, $headerRow), $colCount);

        // Column widths: generous header-based sizing, capped to keep sheets printable
        $widths = [];
        $ci = 0;
        foreach ($section['columns'] as $col) {
            $w = max(mb_strlen((string)$col['label']) + 4, min((float)$col['width'] * 0.85, 46));
            if (($col['type'] ?? '') === 'money') {
                $w = max($w, 14);
            }
            $widths[$ci++] = round($w, 1);
        }
        $xlsx->setColumnWidths($s, $widths);
    }

    return $xlsx->output('');
}

/* ------------------------------------------------------------------ *
 * Request routing: download | preview | page
 * ------------------------------------------------------------------ */

$format   = strtolower(trim((string)($_GET['format'] ?? '')));   // pdf | xlsx | (empty = page)
$single   = trim((string)($_GET['report'] ?? ''));               // single report key
$combined = isset($_GET['combined']) && is_array($_GET['combined']) ? $_GET['combined'] : [];
$from     = trim((string)($_GET['from'] ?? ''));
$to       = trim((string)($_GET['to'] ?? ''));
$preview  = isset($_GET['preview']);

// Validate date range
foreach (['from' => &$from, 'to' => &$to] as $lbl => &$val) {
    if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        $val = '';
    }
}
unset($lbl, $val);
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}
// Default range: current month
if ($from === '' && $to === '') {
    $from = date('Y-m-01');
    $to = date('Y-m-d');
}

/**
 * Resolves the requested selection into normalized sections (role-checked).
 * @return array<int, array{key:string, title:string, subtitle:string, columns:array, rows:array, from:string, to:string}>
 */
$resolveSections = static function (array $keys, string $from, string $to) use ($registry, $available, $currentUserName): array {
    $sections = [];
    foreach ($keys as $key) {
        if (!isset($registry[$key]) || !isset($available[$key])) {
            continue; // unknown or not permitted for this role - skip silently on combined, 403 below on single
        }
        $def = $registry[$key];
        $rows = ($def['build'])($from, $to);
        $sections[] = [
            'key'      => $key,
            'title'    => $def['label'],
            'subtitle' => $def['description'] . '  |  ' . count($rows) . ' record(s)  |  Prepared by ' . $currentUserName,
            'columns'  => $def['columns'],
            'rows'     => $rows,
            'from'     => $from,
            'to'       => $to,
        ];
    }
    return $sections;
};

$isDownload = in_array($format, ['pdf', 'xlsx'], true);
$keys = $single !== '' ? [$single] : (array_values(array_filter($combined, static fn ($k) => is_string($k))) ?: []);

if ($isDownload || $preview) {
    $sections = $resolveSections($keys, $from, $to);

    if (!$sections) {
        http_response_code(403);
        $pageTitle = 'Access Denied';
        include __DIR__ . '/components/header.php';
        echo '<div class="page-container"><div class="card alert-danger">';
        echo '<h2 style="font-size:20px; font-weight:800; margin-bottom:8px;">403 Forbidden</h2>';
        echo '<p>The requested report does not exist or your role does not have access to it.</p>';
        echo '<a href="/reports.php" class="btn btn-secondary">&larr; Back to Reports</a></div></div>';
        include __DIR__ . '/components/footer.php';
        exit;
    }

    // Audit-log the generation attempt (preview or download, success path)
    try {
        $what = count($sections) > 1
            ? 'Combined report (' . count($sections) . ' sections)'
            : $sections[0]['title'];
        logAudit($db, 'REPORT_GENERATED', 'REPORT', $sections[0]['key'], "User {$currentUserName} ({$currentUserRole}) generated {$what} for {$from} to {$to}");
    } catch (Exception $e) {
        // never block on audit failure
    }

    // ---- PREVIEW (HTML mirror of the download) ----
    if ($preview) {
        include __DIR__ . '/components/header.php';
        echo '<main class="page-container">';
        echo '<div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">';
        echo '<div><h2 class="page-title">Report Preview</h2>';
        echo '<p class="page-subtitle">' . htmlspecialchars(count($sections) > 1 ? 'Combined report — ' . count($sections) . ' sections' : $sections[0]['title'])
            . ' • ' . htmlspecialchars(($from !== '' ? $from : 'beginning') . ' → ' . ($to !== '' ? $to : 'today'))
            . ' • ' . count($sections[0]['rows']) . '+ records</p></div>';
        echo '<div style="display:flex; gap:8px;">';
        $qs = http_build_query(array_filter([
            'report' => $single ?: null,
            'combined' => $combined ?: null,
            'from' => $from, 'to' => $to,
        ]));
        echo '<a href="/reports.php?' . htmlspecialchars($qs) . '&amp;format=pdf" class="btn btn-primary btn-sm">&#128196; Download PDF</a>';
        echo '<a href="/reports.php?' . htmlspecialchars($qs) . '&amp;format=xlsx" class="btn btn-success btn-sm">&#128202; Download Excel</a>';
        echo '<a href="/reports.php" class="btn btn-secondary btn-sm">&larr; Back</a>';
        echo '</div></div>';

        foreach ($sections as $section) {
            echo '<div class="card"><div class="card-header"><div>';
            echo '<h3 class="card-title">' . htmlspecialchars($section['title']) . '</h3>';
            echo '<p class="card-subtitle">' . htmlspecialchars($section['subtitle']) . '</p></div></div>';
            if (!$section['rows']) {
                echo '<div class="empty-state">No records found for the selected filters.</div></div>';
                continue;
            }
            echo '<div class="table-responsive"><table class="table"><thead><tr>';
            foreach ($section['columns'] as $col) {
                echo '<th' . ($col['align'] === 'R' ? ' style="text-align:right;"' : '') . '>' . htmlspecialchars($col['label']) . '</th>';
            }
            echo '</tr></thead><tbody>';
            $sumCols = [];
            foreach ($section['rows'] as $row) {
                $sumCols = $row['_sum_cols'] ?? $sumCols;
                echo '<tr>';
                foreach ($section['columns'] as $col) {
                    $align = $col['align'] === 'R' ? ' style="text-align:right;"' : '';
                    echo '<td' . $align . '>' . htmlspecialchars(render_report_value($col, $row[$col['key']] ?? '')) . '</td>';
                }
                echo '</tr>';
            }
            if ($sumCols) {
                echo '<tr style="background:var(--bg-surface-subtle); font-weight:700;">';
                foreach ($section['columns'] as $ci => $col) {
                    $align = $col['align'] === 'R' ? ' style="text-align:right;"' : '';
                    if (in_array($col['key'], $sumCols, true)) {
                        $sum = 0;
                        foreach ($section['rows'] as $row) {
                            $sum += is_numeric($row[$col['key']] ?? null) ? (float)$row[$col['key']] : 0;
                        }
                        echo '<td' . $align . '>' . htmlspecialchars(render_report_value($col, $sum)) . '</td>';
                    } elseif ($ci === 0) {
                        echo '<td>TOTAL (' . count($section['rows']) . ' rows)</td>';
                    } else {
                        echo '<td></td>';
                    }
                }
                echo '</tr>';
            }
            echo '</tbody></table></div></div>';
        }
        echo '</main>';
        include __DIR__ . '/components/footer.php';
        exit;
    }

    // ---- DOWNLOADS ----
    if ($format === 'pdf') {
        $binary = build_pdf_document($sections, $currentUserName);
        header('Content-Type: application/pdf');
        $name = count($sections) > 1 ? 'EPMS_Combined_Report' : preg_replace('/[^A-Za-z0-9]+/', '_', $sections[0]['title']);
        header('Content-Disposition: attachment; filename=' . $name . '_' . date('Ymd_His') . '.pdf');
        header('Content-Length: ' . strlen($binary));
        echo $binary;
        exit;
    }

    // xlsx
    $binary = build_xlsx_document($sections, $currentUserName);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $name = count($sections) > 1 ? 'EPMS_Combined_Report' : preg_replace('/[^A-Za-z0-9]+/', '_', $sections[0]['title']);
    header('Content-Disposition: attachment; filename=' . $name . '_' . date('Ymd_His') . '.xlsx');
    header('Content-Length: ' . strlen($binary));
    echo $binary;
    exit;
}

// ------------------------------------------------------------------ *
// Reports landing page
// ------------------------------------------------------------------
$grouped = [];
foreach ($available as $key => $meta) {
    $grouped[$meta['group']][] = ['key' => $key] + $meta;
}

include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header">
        <h2 class="page-title">Reports &amp; Documents</h2>
        <p class="page-subtitle">Generate, preview, and download PDF or Excel reports — individually or combined into one document</p>
    </div>

    <?php displayFlash(); ?>

    <!-- Combined report builder -->
    <div class="card" style="border:2px solid var(--primary-border);">
        <div class="card-header">
            <div>
                <h3 class="card-title">&#128230; Combined Report Builder</h3>
                <p class="card-subtitle">Tick two or more report types and download them merged into a single PDF or Excel workbook</p>
            </div>
        </div>

        <form method="GET" action="/reports.php" id="combined-form">
            <div class="form-grid">
                <?php foreach ($grouped as $group => $items): ?>
                    <?php foreach ($items as $item): ?>
                        <label class="form-check" style="display:flex; gap:8px; align-items:flex-start; padding:10px 12px; border:1px solid var(--border-color); border-radius:8px; cursor:pointer;">
                            <input type="checkbox" name="combined[]" value="<?= htmlspecialchars($item['key']) ?>"
                                   style="margin-top:3px; accent-color:var(--primary);">
                            <span>
                                <strong style="font-size:13.5px; display:block;"><?= htmlspecialchars($item['label']) ?></strong>
                                <span style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($item['description']) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-top:16px;">
                <div>
                    <label style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; display:block; margin-bottom:4px;">From</label>
                    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control" style="width:160px;">
                </div>
                <div>
                    <label style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; display:block; margin-bottom:4px;">To</label>
                    <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control" style="width:160px;">
                </div>
                <button type="submit" formaction="/reports.php" name="preview" value="1" class="btn btn-secondary" formtarget="_blank">&#128065; Preview</button>
                <button type="submit" formaction="/reports.php?format=pdf" class="btn btn-primary">&#128196; Download Combined PDF</button>
                <button type="submit" formaction="/reports.php?format=xlsx" class="btn btn-success">&#128202; Download Combined Excel</button>
            </div>
        </form>
    </div>

    <!-- Individual templates -->
    <?php foreach ($grouped as $group => $items): ?>
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title"><?= htmlspecialchars($group) ?> Reports</h3>
                    <p class="card-subtitle">Pick a range, preview it, then download as PDF or Excel</p>
                </div>
            </div>
            <div class="form-grid">
                <?php foreach ($items as $item): ?>
                    <div style="border:1px solid var(--border-color); border-radius:8px; padding:14px;">
                        <strong style="display:block; margin-bottom:4px;"><?= htmlspecialchars($item['label']) ?></strong>
                        <p style="font-size:12.5px; color:var(--text-muted); margin-bottom:12px; min-height:34px;"><?= htmlspecialchars($item['description']) ?></p>
                        <form method="GET" action="/reports.php" style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                            <input type="hidden" name="report" value="<?= htmlspecialchars($item['key']) ?>">
                            <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control" style="width:135px; font-size:12px; padding:5px 8px;">
                            <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control" style="width:135px; font-size:12px; padding:5px 8px;">
                            <button type="submit" name="preview" value="1" class="btn btn-secondary btn-sm" formtarget="_blank">Preview</button>
                            <button type="submit" formaction="/reports.php" formmethod="get" name="format" value="pdf" class="btn btn-primary btn-sm">PDF</button>
                            <button type="submit" formaction="/reports.php" formmethod="get" name="format" value="xlsx" class="btn btn-success btn-sm">Excel</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!$available): ?>
        <div class="card">
            <div class="empty-state">
                <div class="empty-state-icon">&#128202;</div>
                <h3>No reports available for your role</h3>
                <p>Contact an administrator if you believe this is a mistake.</p>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
