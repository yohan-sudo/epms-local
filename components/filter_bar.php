<?php
/**
 * U EPMS - Shared Search & Date-Range Filter Bar
 * Renders a GET form (q, from, to) + quick-range chips + "Send to Reports"
 * link. Every list page includes this and applies the same filter contract:
 *   q    = free-text search term (LIKE across the page's key columns)
 *   from = start date (Y-m-d, inclusive)
 *   to   = end date (Y-m-d, inclusive)
 */

/**
 * Renders the filter bar.
 * @param array{
 *   action:string, q:string, from:string, to:string,
 *   placeholder?:string, reportsKey?:string, extraHidden?:array<string,string>
 * } $config
 */
function render_filter_bar(array $config): void
{
    $action      = $config['action'];
    $q           = $config['q'] ?? '';
    $from        = $config['from'] ?? '';
    $to          = $config['to'] ?? '';
    $placeholder = $config['placeholder'] ?? 'Search...';
    $reportsKey  = $config['reportsKey'] ?? '';
    $extraHidden = $config['extraHidden'] ?? [];
    $today       = date('Y-m-d');

    $hasRange = ($from !== '' || $to !== '');
    $qs = static function (array $overrides = []) use ($action, $q, $from, $to): string {
        $params = array_filter(['q' => $q, 'from' => $from, 'to' => $to], static fn ($v) => $v !== '');
        $params = array_merge($params, $overrides);
        return htmlspecialchars($action . '?' . http_build_query($params), ENT_QUOTES);
    };
    ?>
    <div class="card" style="padding:14px 20px; margin-bottom:16px;">
        <form method="GET" action="<?= htmlspecialchars($action) ?>"
              style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
            <?php foreach ($extraHidden as $hk => $hv): ?>
                <input type="hidden" name="<?= htmlspecialchars($hk) ?>" value="<?= htmlspecialchars($hv) ?>">
            <?php endforeach; ?>

            <div style="flex:2; min-width:220px;">
                <label style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; display:block; margin-bottom:4px;">Search</label>
                <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= htmlspecialchars($placeholder) ?>"
                       class="form-control" maxlength="100" style="padding:6px 10px; font-size:13px;">
            </div>

            <div>
                <label style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; display:block; margin-bottom:4px;">From</label>
                <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" max="<?= $today ?>"
                       class="form-control" style="padding:6px 10px; font-size:13px; width:150px;">
            </div>

            <div>
                <label style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; display:block; margin-bottom:4px;">To</label>
                <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" max="<?= $today ?>"
                       class="form-control" style="padding:6px 10px; font-size:13px; width:150px;">
            </div>

            <button type="submit" class="btn btn-primary btn-sm" style="padding:7px 16px;">&#128269; Apply</button>
            <a href="<?= htmlspecialchars($action) ?>" class="btn btn-secondary btn-sm" style="padding:7px 12px;">Reset</a>

            <?php if ($reportsKey !== ''): ?>
                <a href="/reports.php?report=<?= urlencode($reportsKey) ?>&amp;from=<?= urlencode($from) ?>&amp;to=<?= urlencode($to) ?>"
                   class="btn btn-secondary btn-sm" style="padding:7px 12px; margin-left:auto;" title="Open this filtered view in the Reports module for preview and download">
                    &#128202; Send to Reports
                </a>
            <?php endif; ?>
        </form>

        <div style="display:flex; gap:6px; margin-top:10px; flex-wrap:wrap; align-items:center;">
            <span style="font-size:11px; color:var(--text-subtle); font-weight:700; text-transform:uppercase; margin-right:2px;">Quick range:</span>
            <a href="<?= $qs(['from' => $today, 'to' => $today]) ?>" class="btn btn-sm <?= ($from === $today && $to === $today) ? 'btn-primary' : 'btn-secondary' ?>">Today</a>
            <a href="<?= $qs(['from' => date('Y-m-d', strtotime('-6 days')), 'to' => $today]) ?>" class="btn btn-sm <?= ($from === date('Y-m-d', strtotime('-6 days')) && $to === $today) ? 'btn-primary' : 'btn-secondary' ?>">Last 7 days</a>
            <a href="<?= $qs(['from' => date('Y-m-d', strtotime('-29 days')), 'to' => $today]) ?>" class="btn btn-sm <?= ($from === date('Y-m-d', strtotime('-29 days')) && $to === $today) ? 'btn-primary' : 'btn-secondary' ?>">Last 30 days</a>
            <a href="<?= $qs(['from' => date('Y-m-01'), 'to' => $today]) ?>" class="btn btn-sm <?= ($from === date('Y-m-01') && $to === $today) ? 'btn-primary' : 'btn-secondary' ?>">This month</a>
            <?php if ($hasRange): ?>
                <span class="badge badge-info" style="margin-left:6px;">
                    Filtered: <?= htmlspecialchars($from !== '' ? $from : 'beginning') ?> &rarr; <?= htmlspecialchars($to !== '' ? $to : 'today') ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Normalizes and validates shared q/from/to GET params.
 * @return array{q:string, from:string, to:string, errors:string[]}
 */
function read_filter_params(): array
{
    $q     = trim((string)($_GET['q'] ?? ''));
    $from  = trim((string)($_GET['from'] ?? ''));
    $to    = trim((string)($_GET['to'] ?? ''));
    $errors = [];

    if (mb_strlen($q) > 100) {
        $q = mb_substr($q, 0, 100);
    }

    foreach (['from' => $from, 'to' => $to] as $label => $value) {
        if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $errors[] = "Invalid {$label} date ignored.";
            if ($label === 'from') {
                $from = '';
            } else {
                $to = '';
            }
        }
    }
    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from]; // silently swap instead of empty result
    }

    return ['q' => $q, 'from' => $from, 'to' => $to, 'errors' => $errors];
}
