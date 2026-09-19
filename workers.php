<?php
/**
 * U EPMS - Workers & Attendance (v2.3, LAST item on the client list)
 *
 * Biometric-ready framework:
 *   - Manager registers workers (name, staff no, department) and enrols
 *     fingerprint / face placeholders. Templates are stored as opaque
 *     columns (fingerprint_template / face_template) so a physical scanner
 *     or camera device can POST its captured template through
 *     api_biometric.php without any schema change.
 *   - Check-in/out is designed for device events: a biometric device posts
 *     {staff_no, method, device_info} to api_biometric.php; until hardware
 *     arrives the Manager can log attendance manually (method='manual').
 *   - C.E.O sees who has attended; Manager sees everything; others: no access.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole(['CEO', 'Manager']);

$pageTitle = 'Workers & Attendance';
$activeNav = 'workers';

$currentUserRole = $_SESSION['user_role'];
$currentUserId   = (int)$_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];
$isCEO = $currentUserRole === 'CEO';
$isMgr = $currentUserRole === 'Manager';
// v2.3.2: the C.E.O (owner) has the same worker-management authority as the
// Manager - including biometric enrollment - so enrollment is never blocked
// when the Manager is away.
$canManageWorkers = in_array($currentUserRole, ['Manager', 'CEO'], true);

/* ==================================================================
 * POST actions (Manager only)
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageWorkers) {
    $action = $_POST['action'] ?? '';

    if ($action === 'register_worker') {
        $errors = [];
        $name = normalizePersonName(field_text($errors, 'full_name', 'Full name', true, 2, 120) ?? '');
        if ($name !== '' && preg_match("/^[\p{Lu} .'\\-]+$/u", $name) !== 1) {
            $errors[] = "• Full name must be in CAPITAL LETTERS only - letters, spaces, apostrophes, hyphens and dots.";
        }
        $staffNo = strtoupper(trim((string)($_POST['staff_no'] ?? '')));
        if ($staffNo === '' || !preg_match('/^[A-Z0-9\-]{2,30}$/', $staffNo)) {
            $errors[] = '• Staff number is required (letters, numbers, hyphens).';
        }
        $dept = field_text($errors, 'department', 'Department', false, 0, 60) ?? '';
        if ($errors) {
            redirectWithErrors('/workers.php', $errors);
        }
        $dup = $db->prepare('SELECT COUNT(*) FROM workers WHERE staff_no = :s');
        $dup->execute([':s' => $staffNo]);
        if ((int)$dup->fetchColumn() > 0) {
            setFlash('error', "Staff number {$staffNo} is already registered.");
            header('Location: /workers.php');
            exit;
        }
        $db->prepare('INSERT INTO workers (full_name, staff_no, department, enrolled_by) VALUES (:n, :s, :d, :by)')
           ->execute([':n' => $name, ':s' => $staffNo, ':d' => $dept !== '' ? $dept : null, ':by' => $currentUserId]);
        logAudit($db, 'WORKER_REGISTERED', 'WORKER', $staffNo, "Manager {$currentUserName} registered worker {$name} ({$staffNo})" . ($dept !== '' ? " in {$dept}" : '') . '.');
        setFlash('success', "Worker {$name} registered. Enrol their fingerprint/face when the scanner is available.");
        header('Location: /workers.php');
        exit;
    }

    if ($action === 'enroll_biometric') {
        $wid = (int)($_POST['worker_id'] ?? 0);
        $finger = trim((string)($_POST['fingerprint_template'] ?? ''));
        $face = trim((string)($_POST['face_template'] ?? ''));
        $w = $db->query('SELECT * FROM workers WHERE id = ' . $wid)->fetch();
        if (!$w) {
            setFlash('error', 'Worker not found.');
            header('Location: /workers.php');
            exit;
        }
        $db->prepare('UPDATE workers SET fingerprint_template = :f, face_template = :fc, biometric_enrolled = 1, enrolled_by = :by, enrolled_at = CURRENT_TIMESTAMP WHERE id = :id')
           ->execute([':f' => $finger !== '' ? $finger : null, ':fc' => $face !== '' ? $face : null, ':by' => $currentUserId, ':id' => $wid]);
        logAudit($db, 'WORKER_BIOMETRIC_ENROLLED', 'WORKER', $w['staff_no'],
            "Manager {$currentUserName} enrolled biometrics for {$w['full_name']} ({$w['staff_no']})" . ($finger !== '' ? ' [fingerprint]' : '') . ($face !== '' ? ' [face]' : '') . '.');
        setFlash('success', "Biometric enrolment saved for {$w['full_name']}.");
        header('Location: /workers.php');
        exit;
    }

    if ($action === 'manual_attendance') {
        $wid = (int)($_POST['worker_id'] ?? 0);
        $w = $db->query('SELECT * FROM workers WHERE id = ' . $wid)->fetch();
        if (!$w) {
            setFlash('error', 'Worker not found.');
            header('Location: /workers.php');
            exit;
        }
        $today = date('Y-m-d');
        $existing = $db->prepare('SELECT * FROM worker_attendance WHERE worker_id = :w AND attend_date = :d');
        $existing->execute([':w' => $wid, ':d' => $today]);
        $att = $existing->fetch();
        if (!$att) {
            $db->prepare("INSERT INTO worker_attendance (worker_id, attend_date, check_in, method, device_info) VALUES (:w, :d, CURRENT_TIMESTAMP, 'manual', :dev)")
               ->execute([':w' => $wid, ':d' => $today, ':dev' => 'Manual entry by ' . $currentUserName]);
            setFlash('success', "{$w['full_name']} checked in.");
        } elseif ($att['check_out'] === null) {
            $db->prepare("UPDATE worker_attendance SET check_out = CURRENT_TIMESTAMP WHERE id = :id")->execute([':id' => $att['id']]);
            setFlash('success', "{$w['full_name']} checked out.");
        } else {
            setFlash('info', "{$w['full_name']} already has a full day recorded.");
        }
        logAudit($db, 'WORKER_ATTENDANCE', 'WORKER', $w['staff_no'], "Attendance event for {$w['full_name']} recorded by {$currentUserName} (manual).");
        header('Location: /workers.php');
        exit;
    }

    if ($action === 'toggle_worker_status') {
        $wid = (int)($_POST['worker_id'] ?? 0);
        $w = $db->query('SELECT * FROM workers WHERE id = ' . $wid)->fetch();
        if ($w) {
            $new = $w['status'] === 'Active' ? 'Inactive' : 'Active';
            $db->prepare('UPDATE workers SET status = :s WHERE id = :id')->execute([':s' => $new, ':id' => $wid]);
            logAudit($db, 'WORKER_STATUS_CHANGED', 'WORKER', $w['staff_no'], "{$currentUserName} set {$w['full_name']} to {$new}.");
            setFlash('success', "{$w['full_name']} is now {$new}.");
        }
        header('Location: /workers.php');
        exit;
    }

    setFlash('error', 'Unknown worker action.');
    header('Location: /workers.php');
    exit;
}

/* ==================================================================
 * Data
 * ================================================================== */
$workers = $db->query('SELECT * FROM workers ORDER BY full_name ASC')->fetchAll();
$today = date('Y-m-d');
$attendanceToday = $db->query(
    "SELECT a.*, w.full_name, w.staff_no FROM worker_attendance a JOIN workers w ON w.id = a.worker_id WHERE a.attend_date = '{$today}' ORDER BY a.check_in DESC"
)->fetchAll();
$recentDays = $db->query(
    "SELECT attend_date, COUNT(DISTINCT worker_id) AS present FROM worker_attendance GROUP BY attend_date ORDER BY attend_date DESC LIMIT 10"
)->fetchAll();
$enrolledCount = count(array_filter($workers, fn ($w) => (int)$w['biometric_enrolled'] === 1));
?>
<?php include __DIR__ . '/components/header.php'; ?>
<main class="page-container">
    <?php displayFlash(); ?>

    <div style="margin-bottom:18px;">
        <h1 style="font-size:24px; font-weight:800;">Workers &amp; Attendance</h1>
        <p style="color:var(--text-secondary, #64748b); font-size:13px;">
            <?php if ($canManageWorkers): ?>Register workers, enrol their fingerprint/face (Windows Hello, Touch ID, Android) and take biometric check-ins. A physical scanner can still POST events through api_biometric.php.
            <?php else: ?>Who came to work today - recorded by fingerprint/face at the gate.<?php endif; ?>
        </p>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; margin-bottom:18px;">
        <div class="card" style="padding:14px 16px;">
            <div style="font-size:12px; color:var(--text-secondary, #64748b);">Registered Workers</div>
            <div style="font-size:22px; font-weight:800;"><?= count($workers) ?></div>
        </div>
        <div class="card" style="padding:14px 16px;">
            <div style="font-size:12px; color:var(--text-secondary, #64748b);">Biometric-Enrolled</div>
            <div style="font-size:22px; font-weight:800;"><?= $enrolledCount ?></div>
        </div>
        <div class="card" style="padding:14px 16px;">
            <div style="font-size:12px; color:var(--text-secondary, #64748b);">Present Today</div>
            <div style="font-size:22px; font-weight:800; color:#16a34a;"><?= count($attendanceToday) ?></div>
        </div>
    </div>

    <?php if ($canManageWorkers): ?>
    <details class="card" style="padding:16px; margin-bottom:18px;">
        <summary style="cursor:pointer; font-weight:700; font-size:15px;">+ Register Worker</summary>
        <form method="post" action="/workers.php" style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
            <input type="hidden" name="action" value="register_worker">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div style="flex:1; min-width:200px;"><label style="font-size:11px;">Full name (CAPITALS) *</label>
                <input class="form-control" name="full_name" required minlength="2" maxlength="120" placeholder="e.g. ASHA MKUMBU" style="text-transform:uppercase;"></div>
            <div><label style="font-size:11px;">Staff number *</label>
                <input class="form-control" name="staff_no" required maxlength="30" placeholder="e.g. WK-014" style="text-transform:uppercase; width:130px;"></div>
            <div><label style="font-size:11px;">Department</label>
                <input class="form-control" name="department" maxlength="60" placeholder="e.g. Rounding line" style="width:170px;"></div>
            <button class="btn btn-primary" type="submit">Register</button>
        </form>
    </details>
    <?php endif; ?>

    <div class="card" style="padding:16px; margin-bottom:18px;">
        <h3 class="card-title" style="font-size:15px; font-weight:700; margin-bottom:10px;">Workers</h3>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead><tr>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Staff No</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Name</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Department</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Biometrics</th>
                    <th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Status</th>
                    <?php if ($canManageWorkers): ?><th style="text-align:left; padding:8px; border-bottom:2px solid var(--border, #e2e8f0);">Actions</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php if (!$workers): ?>
                    <tr><td colspan="6" style="padding:14px; color:var(--text-secondary, #64748b);">No workers registered yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($workers as $w): ?>
                    <tr>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0); font-weight:600;"><?= htmlspecialchars($w['staff_no']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars($w['full_name']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= htmlspecialchars((string)$w['department']) ?: '&mdash;' ?></td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);">
                            <?= (int)$w['biometric_enrolled'] === 1 ? '<span class="badge badge-success" style="font-size:10px;">Enrolled</span>' : '<span class="badge badge-secondary" style="font-size:10px;">Not enrolled</span>' ?>
                        </td>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);"><?= $w['status'] === 'Active' ? '<span class="badge badge-success" style="font-size:10px;">Active</span>' : '<span class="badge badge-danger" style="font-size:10px;">' . htmlspecialchars($w['status']) . '</span>' ?></td>
                        <?php if ($canManageWorkers): ?>
                        <td style="padding:8px; border-bottom:1px solid var(--border, #e2e8f0);">
                            <?php if ($w['status'] === 'Active'): ?>
                                <form method="post" action="/workers.php" style="display:inline-flex; gap:4px; flex-wrap:wrap;">
                                    <input type="hidden" name="action" value="manual_attendance">
                                    <input type="hidden" name="worker_id" value="<?= (int)$w['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <button class="btn btn-secondary" style="font-size:11px; padding:4px 10px;">Check in/out</button>
                                </form>
                                <details style="display:inline-block; margin-left:4px;">
                                    <summary class="btn btn-secondary" style="cursor:pointer; display:inline-block; font-size:11px; padding:4px 10px;">Enrol biometrics</summary>
                                    <div style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                        <button type="button" class="btn btn-primary" style="font-size:11px; padding:5px 10px;"
                                                onclick="uepmsEnrolBiometric(<?= (int)$w['id'] ?>, '<?= htmlspecialchars(addslashes($w['full_name'])) ?>')">
                                            Use this device (fingerprint/face)
                                        </button>
                                        <span style="font-size:10px; color:var(--text-secondary,#64748b);">The worker presses their fingerprint/face on this device; the key never leaves it.</span>
                                    </div>
                                </details>
                                <form method="post" action="/workers.php" style="display:inline; margin-left:4px;" onsubmit="return uepmsBiometricCheckIn(this, <?= (int)$w['id'] ?>);">
                                    <input type="hidden" name="action" value="webauthn_checkin">
                                    <input type="hidden" name="worker_id" value="<?= (int)$w['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <button class="btn btn-secondary" style="font-size:11px; padding:4px 10px;">Biometric check-in</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/workers.php" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_worker_status">
                                    <input type="hidden" name="worker_id" value="<?= (int)$w['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <button class="btn btn-secondary" style="font-size:11px; padding:4px 10px;">Reactivate</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
        <div class="card" style="padding:16px;">
            <h3 class="card-title" style="font-size:15px; font-weight:700; margin-bottom:10px;">Present Today (<?= date('M d') ?>)</h3>
            <?php if (!$attendanceToday): ?>
                <p style="font-size:13px; color:var(--text-secondary, #64748b);">Nobody has checked in yet today.</p>
            <?php else: ?>
                <?php foreach ($attendanceToday as $a): ?>
                    <div style="display:flex; justify-content:space-between; padding:7px 0; border-bottom:1px solid var(--border, #f1f5f9); font-size:13px;">
                        <div><strong><?= htmlspecialchars($a['full_name']) ?></strong> <span style="color:var(--text-secondary,#94a3b8); font-size:11px;"><?= htmlspecialchars($a['staff_no']) ?></span></div>
                        <div style="font-size:11px; color:var(--text-secondary, #64748b);">
                            In: <?= $a['check_in'] ? formatDateTime((string)$a['check_in']) : '-' ?>
                            <?= $a['check_out'] ? ' &bull; Out: ' . formatDateTime((string)$a['check_out']) : '' ?>
                            <span class="badge badge-secondary" style="font-size:9px; margin-left:4px;"><?= htmlspecialchars($a['method']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="card" style="padding:16px;">
            <h3 class="card-title" style="font-size:15px; font-weight:700; margin-bottom:10px;">Last 10 Days</h3>
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead><tr>
                    <th style="text-align:left; padding:6px 8px; border-bottom:2px solid var(--border, #e2e8f0);">Date</th>
                    <th style="text-align:right; padding:6px 8px; border-bottom:2px solid var(--border, #e2e8f0);">Present</th>
                </tr></thead>
                <tbody>
                <?php foreach ($recentDays as $rd): ?>
                    <tr>
                        <td style="padding:6px 8px; border-bottom:1px solid var(--border, #f1f5f9);"><?= formatDate((string)$rd['attend_date']) ?></td>
                        <td style="padding:6px 8px; border-bottom:1px solid var(--border, #f1f5f9); text-align:right; font-weight:700;"><?= (int)$rd['present'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<script>
/* ===== WebAuthn biometric enrolment & check-in (workers) ===== */
function uepmsBioToast(msg, ok) {
    var t = document.createElement('div');
    t.style.cssText = 'position:fixed;top:18px;right:18px;z-index:3000;max-width:340px;padding:12px 16px;border-radius:10px;';
    t.style.background = ok ? '#16a34a' : '#dc2626';
    t.style.color = '#fff'; t.style.fontSize = '13px'; t.style.boxShadow = '0 8px 24px rgba(0,0,0,.25)';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 5000);
}

function uepmsB64ToBuf(b64) {
    var bin = atob(b64.replace(/-/g, '+').replace(/_/g, '/'));
    var buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
}
function uepmsBufToB64(buf) {
    var bytes = new Uint8Array(buf), s = '';
    for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s);
}
/* The server library encodes binary values as =?BINARY?B?<base64>?= strings;
   this walks the options object and turns each one into an ArrayBuffer. */
function uepmsRecursiveB64ToBuf(obj) {
    var prefix = '=?BINARY?B?';
    var suffix = '?=';
    if (typeof obj === 'object' && obj !== null) {
        for (var key in obj) {
            if (typeof obj[key] === 'string') {
                var str = obj[key];
                if (str.substring(0, prefix.length) === prefix && str.substring(str.length - suffix.length) === suffix) {
                    str = str.substring(prefix.length, str.length - suffix.length);
                    var bin = window.atob(str);
                    var bytes = new Uint8Array(bin.length);
                    for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
                    obj[key] = bytes.buffer;
                }
            } else {
                uepmsRecursiveB64ToBuf(obj[key]);
            }
        }
    }
    return obj;
}
function uepmsCsrf() {
    var m = document.querySelector('input[name="csrf_token"]');
    return m ? m.value : '';
}

async function uepmsEnrolBiometric(workerId, workerName) {
    try {
        if (!window.fetch || !navigator.credentials || !navigator.credentials.create) {
            throw new Error('This browser does not support biometric enrolment (WebAuthn).');
        }
        var rep = await fetch('api_webauthn.php?fn=enroll_args&worker_id=' + workerId, { method: 'GET', cache: 'no-cache' });
        var args = await rep.json();
        if (args.success === false) throw new Error(args.msg || 'Could not start enrolment.');
        uepmsRecursiveB64ToBuf(args);
        var cred = await navigator.credentials.create(args);
        rep = await fetch('api_webauthn.php?fn=enroll_verify', {
            method: 'POST', cache: 'no-cache',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': uepmsCsrf() },
            body: JSON.stringify({
                clientDataJSON: cred.response.clientDataJSON ? uepmsBufToB64(cred.response.clientDataJSON) : null,
                attestationObject: cred.response.attestationObject ? uepmsBufToB64(cred.response.attestationObject) : null
            })
        });
        var done = await rep.json();
        if (!done.success) throw new Error(done.msg || 'Enrolment failed.');
        uepmsBioToast(done.msg, true);
        setTimeout(function () { window.location.reload(); }, 1200);
    } catch (err) {
        uepmsBioToast(err.message || 'Enrolment failed.', false);
    }
}

async function uepmsBiometricCheckIn(form, workerId) {
    try {
        if (!window.fetch || !navigator.credentials || !navigator.credentials.get) {
            throw new Error('This browser does not support biometric check-in (WebAuthn).');
        }
        var rep = await fetch('api_webauthn.php?fn=attend_args', {
            method: 'POST', cache: 'no-cache',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': uepmsCsrf() },
            body: JSON.stringify({ worker_id: workerId })
        });
        var args = await rep.json();
        if (args.success === false) throw new Error(args.msg || 'Check-in could not start.');
        uepmsRecursiveB64ToBuf(args);
        var cred = await navigator.credentials.get(args);
        rep = await fetch('api_webauthn.php?fn=attend_verify', {
            method: 'POST', cache: 'no-cache',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': uepmsCsrf() },
            body: JSON.stringify({
                id: cred.rawId ? uepmsBufToB64(cred.rawId) : null,
                clientDataJSON: cred.response.clientDataJSON ? uepmsBufToB64(cred.response.clientDataJSON) : null,
                authenticatorData: cred.response.authenticatorData ? uepmsBufToB64(cred.response.authenticatorData) : null,
                signature: cred.response.signature ? uepmsBufToB64(cred.response.signature) : null,
                userHandle: cred.response.userHandle ? uepmsBufToB64(cred.response.userHandle) : null
            })
        });
        var done = await rep.json();
        if (!done.success) throw new Error(done.msg || 'Verification failed.');
        uepmsBioToast(done.msg, true);
        setTimeout(function () { window.location.reload(); }, 1200);
    } catch (err) {
        uepmsBioToast(err.message || 'Check-in failed.', false);
    }
    return false; // the form is only the CSRF carrier; JS handled everything
}
</script>
<?php include __DIR__ . '/components/footer.php'; ?>
