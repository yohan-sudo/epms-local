<?php
/**
 * U EPMS - WebAuthn biometric enrollment & check-in (v2.3.4)
 *
 * Real, standards-based biometrics using the browser platform authenticator
 * (Windows Hello fingerprint/face, Touch ID, Android fingerprint) via the
 * bundled lbuchs/WebAuthn library (lib/WebAuthn, MIT).
 *
 * Enrolment (CEO/Manager on Workers & Attendance):
 *   1. GET  api_webauthn.php?fn=enroll_args&worker_id=N  -> PKC creation options
 *   2. JS   navigator.credentials.create(...)            -> user's fingerprint/face
 *   3. POST api_webauthn.php?fn=enroll_verify            -> verified + stored
 *
 * Check-in (same worker on the gate device):
 *   4. POST api_webauthn.php?fn=attend_args  {worker_id} -> PKC request options
 *   5. JS   navigator.credentials.get(...)               -> fingerprint scan
 *   6. POST api_webauthn.php?fn=attend_verify            -> attendance written
 *
 * The private key never leaves the worker's device; the server stores only the
 * credential id + public key, and verifies a signature at every check-in.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/lib/WebAuthn/WebAuthn.php';

requireAuth();

header('Content-Type: application/json');

/**
 * Relying-Party ID: the host the app is served from (WebAuthn requires the
 * RP ID to match the origin; 127.0.0.1/localhost are valid secure contexts).
 */
function uepms_rp_id(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
    $host = preg_replace('/:\d+$/', '', $host);
    return $host !== '' ? $host : '127.0.0.1';
}

function uepms_webauthn(): lbuchs\WebAuthn\WebAuthn
{
    return new lbuchs\WebAuthn\WebAuthn('U EPMS Worker Biometrics', uepms_rp_id(), ['none']);
}

function uepms_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'msg' => $msg]);
    exit;
}

/** JSON body of a fetch() POST. */
function uepms_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

/** Enrolment/attendance endpoints mutate state: require the CSRF header. */
function uepms_require_csrf_header(): void
{
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        uepms_fail('Session expired - reload the page and try again.', 419);
    }
}

function uepms_load_worker(int $wid): array
{
    global $db;
    $stmt = $db->prepare('SELECT * FROM workers WHERE id = :id');
    $stmt->execute([':id' => $wid]);
    $w = $stmt->fetch();
    if (!$w) {
        uepms_fail('Worker not found.', 404);
    }
    if ($w['status'] !== 'Active') {
        uepms_fail('This worker is not active.', 409);
    }
    return $w;
}

$fn    = (string)($_GET['fn'] ?? $_POST['fn'] ?? '');
$isMgr = in_array($_SESSION['user_role'] ?? '', ['CEO', 'Manager'], true);
$currentUserName = (string)$_SESSION['user_name'];

/* ================================================================
 * 1. Enrolment: build the credential-creation options
 * ================================================================ */
if ($fn === 'enroll_args') {
    if (!$isMgr) {
        uepms_fail('Only the C.E.O or Manager can enrol worker biometrics.', 403);
    }
    $worker = uepms_load_worker((int)($_GET['worker_id'] ?? 0));

    $webauthn = uepms_webauthn();
    // userHandle = worker id as 4-byte binary; internal + cross-platform allowed;
    // user verification REQUIRED (a PIN/fingerpress is the whole point here).
    $args = $webauthn->getCreateArgs(
        pack('N', (int)$worker['id']),
        'worker-' . (int)$worker['id'],
        $worker['full_name'],
        60 * 4,
        false,
        'required'
    );

    $_SESSION['webauthn_challenge'] = $webauthn->getChallenge();
    $_SESSION['webauthn_worker_id'] = (int)$worker['id'];

    echo json_encode($args);
    exit;
}

/* ================================================================
 * 2. Enrolment: verify the attestation and store the credential
 * ================================================================ */
if ($fn === 'enroll_verify') {
    if (!$isMgr) {
        uepms_fail('Only the C.E.O or Manager can enrol worker biometrics.', 403);
    }
    uepms_require_csrf_header();
    $body   = uepms_json_body();
    $wid    = (int)($_SESSION['webauthn_worker_id'] ?? 0);
    $worker = uepms_load_worker($wid);
    $challenge = $_SESSION['webauthn_challenge'] ?? null;
    if (!$challenge) {
        uepms_fail('Enrolment session expired - start again.', 419);
    }

    try {
        $webauthn = uepms_webauthn();
        $data = $webauthn->processCreate(
            base64_decode((string)($body['clientDataJSON'] ?? ''), true),
            base64_decode((string)($body['attestationObject'] ?? ''), true),
            $challenge,
            true,    // user verification required
            true,    // user presence required
            false,   // no root-CA pinning (plain 'none' attestation)
            false    // no CTS profile match requirement
        );
    } catch (Throwable $e) {
        uepms_fail('Enrolment verification failed: ' . $e->getMessage(), 400);
    }

    $data->signatureCounter ??= 0;
    global $db;
    $stmt = $db->prepare(
        'INSERT INTO webauthn_credentials (worker_id, credential_id, public_key, format, aaguid, sign_count, enrolled_by)
         VALUES (:w, :cid, :pk, :fmt, :aaguid, :cnt, :by)'
    );
    $stmt->bindValue(':w', $wid, PDO::PARAM_INT);
    $stmt->bindValue(':cid', $data->credentialId, PDO::PARAM_LOB);
    $stmt->bindValue(':pk', (string)$data->credentialPublicKey);
    $stmt->bindValue(':fmt', (string)($data->attestationFormat ?? 'none'));
    $stmt->bindValue(':aaguid', isset($data->AAGUID) ? (string)$data->AAGUID : null);
    $stmt->bindValue(':cnt', (int)$data->signatureCounter, PDO::PARAM_INT);
    $stmt->bindValue(':by', (int)$_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();

    $db->prepare('UPDATE workers SET biometric_enrolled = 1, enrolled_by = :by, enrolled_at = CURRENT_TIMESTAMP WHERE id = :id')
       ->execute([':by' => (int)$_SESSION['user_id'], ':id' => $wid]);

    logAudit($db, 'WORKER_BIOMETRIC_ENROLLED', 'WORKER', (string)$worker['staff_no'],
        "WebAuthn enrolment (platform fingerprint/face) for {$worker['full_name']} ({$worker['staff_no']}) by {$currentUserName}.");

    unset($_SESSION['webauthn_challenge'], $_SESSION['webauthn_worker_id']);
    echo json_encode(['success' => true, 'msg' => "Biometric enrolment saved for {$worker['full_name']}."]);
    exit;
}

/* ================================================================
 * 3. Check-in: build the credential-request options
 * ================================================================ */
if ($fn === 'attend_args') {
    uepms_require_csrf_header();
    $wid   = (int)(uepms_json_body()['worker_id'] ?? $_POST['worker_id'] ?? 0);
    $worker = uepms_load_worker($wid);

    global $db;
    $stmt = $db->prepare('SELECT credential_id FROM webauthn_credentials WHERE worker_id = :w');
    $stmt->execute([':w' => $wid]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        uepms_fail('This worker has no enrolled biometrics yet.', 409);
    }

    $webauthn = uepms_webauthn();
    $args = $webauthn->getGetArgs($ids, 60 * 4, false, false, false, false, true, 'required');

    $_SESSION['webauthn_challenge'] = $webauthn->getChallenge();
    $_SESSION['webauthn_worker_id'] = $wid;

    echo json_encode($args);
    exit;
}

/* ================================================================
 * 4. Check-in: verify the assertion and write attendance
 * ================================================================ */
if ($fn === 'attend_verify') {
    uepms_require_csrf_header();
    $body    = uepms_json_body();
    $wid     = (int)($_SESSION['webauthn_worker_id'] ?? 0);
    $worker  = uepms_load_worker($wid);
    $challenge = $_SESSION['webauthn_challenge'] ?? '';
    if ($challenge === '') {
        uepms_fail('Check-in session expired - try again.', 419);
    }

    global $db;
    $stmt = $db->prepare('SELECT * FROM webauthn_credentials WHERE worker_id = :w');
    $stmt->execute([':w' => $wid]);
    $rows = $stmt->fetchAll();

    $credId = base64_decode((string)($body['id'] ?? ''), true);
    $credential = null;
    foreach ($rows as $row) {
        if (hash_equals((string)$row['credential_id'], (string)$credId)) {
            $credential = $row;
            break;
        }
    }
    if (!$credential) {
        uepms_fail('Unknown credential for this worker.', 401);
    }

    try {
        $webauthn = uepms_webauthn();
        $webauthn->processGet(
            base64_decode((string)($body['clientDataJSON'] ?? ''), true),
            base64_decode((string)($body['authenticatorData'] ?? ''), true),
            base64_decode((string)($body['signature'] ?? ''), true),
            (string)$credential['public_key'], // PEM string as stored at enrolment
            $challenge,
            (int)$credential['sign_count'] > 0 ? (int)$credential['sign_count'] : null,
            true // user verification required
        );
    } catch (Throwable $e) {
        uepms_fail('Biometric verification failed: ' . $e->getMessage(), 401);
    }

    // Counter guards against cloned authenticators.
    $authData = base64_decode((string)($body['authenticatorData'] ?? ''), true);
    $newCount = 0;
    if (strlen($authData) >= 33) {
        $newCount = unpack('N', substr($authData, 29, 4))[1];
    }
    $db->prepare('UPDATE webauthn_credentials SET sign_count = :c WHERE id = :id')
       ->execute([':c' => max($newCount, (int)$credential['sign_count'] + 1), ':id' => $credential['id']]);

    // Write the attendance event (same policy as manual check-in/out).
    $today = date('Y-m-d');
    $existing = $db->prepare('SELECT * FROM worker_attendance WHERE worker_id = :w AND attend_date = :d');
    $existing->execute([':w' => $wid, ':d' => $today]);
    $att = $existing->fetch();
    if (!$att) {
        $db->prepare("INSERT INTO worker_attendance (worker_id, attend_date, check_in, method, device_info) VALUES (:w, :d, CURRENT_TIMESTAMP, 'biometric', :dev)")
           ->execute([':w' => $wid, ':d' => $today, ':dev' => 'WebAuthn on ' . uepms_rp_id()]);
        $msg = "{$worker['full_name']} checked in (biometric verified).";
    } elseif ($att['check_out'] === null) {
        $db->prepare('UPDATE worker_attendance SET check_out = CURRENT_TIMESTAMP WHERE id = :id')->execute([':id' => $att['id']]);
        $msg = "{$worker['full_name']} checked out (biometric verified).";
    } else {
        $msg = "{$worker['full_name']} already has a full day recorded.";
    }

    logAudit($db, 'WORKER_ATTENDANCE', 'WORKER', (string)$worker['staff_no'],
        "Biometric attendance event for {$worker['full_name']} ({$worker['staff_no']}) - signature verified.");

    unset($_SESSION['webauthn_challenge'], $_SESSION['webauthn_worker_id']);
    echo json_encode(['success' => true, 'msg' => $msg]);
    exit;
}

uepms_fail('Unknown WebAuthn function.', 404);
