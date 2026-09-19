<?php
/**
 * U EPMS - Biometric Device Endpoint (v2.3)
 * Face/fingerprint hardware posts events here:
 *
 *   POST { "device_key": "...", "staff_no": "WK-014", "action": "clock" }
 *   POST { "device_key": "...", "staff_no": "WK-014", "action": "enroll",
 *          "fingerprint_template": "...", "face_template": "..." }
 *
 * Authenticated by BIOMETRIC_DEVICE_KEY in .env (generate once, flash to device).
 * clock: first event of the day = check-in, second = check-out.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$expectedKey = (string)env('BIOMETRIC_DEVICE_KEY', '');
$raw = json_decode(file_get_contents('php://input'), true);
$deviceKey = (string)($raw['device_key'] ?? '');
if ($expectedKey === '' || !hash_equals($expectedKey, $deviceKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid device key']);
    exit;
}

$staffNo = strtoupper(trim((string)($raw['staff_no'] ?? '')));
$action = (string)($raw['action'] ?? 'clock');
$method = in_array(($raw['method'] ?? 'biometric'), ['biometric', 'manual'], true) ? ($raw['method'] ?? 'biometric') : 'biometric';
$deviceInfo = mb_substr((string)($raw['device_info'] ?? 'unknown device'), 0, 120);

$stmt = $db->prepare('SELECT * FROM workers WHERE staff_no = :s AND status = "Active"');
$stmt->execute([':s' => $staffNo]);
$worker = $stmt->fetch();
if (!$worker) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Unknown or inactive staff number']);
    exit;
}

if ($action === 'enroll') {
    $finger = trim((string)($raw['fingerprint_template'] ?? ''));
    $face = trim((string)($raw['face_template'] ?? ''));
    if ($finger === '' && $face === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'No template provided']);
        exit;
    }
    $db->prepare('UPDATE workers SET fingerprint_template = :f, face_template = :fc, biometric_enrolled = 1, enrolled_at = CURRENT_TIMESTAMP WHERE id = :id')
       ->execute([':f' => $finger !== '' ? $finger : null, ':fc' => $face !== '' ? $face : null, ':id' => (int)$worker['id']]);
    logAudit($db, 'WORKER_BIOMETRIC_ENROLLED', 'WORKER', $worker['staff_no'], "Biometric enrolment captured by device for {$worker['full_name']} ({$worker['staff_no']}).");
    echo json_encode(['ok' => true, 'result' => 'enrolled', 'worker' => $worker['full_name']]);
    exit;
}

// clock event
$today = date('Y-m-d');
$existing = $db->prepare('SELECT * FROM worker_attendance WHERE worker_id = :w AND attend_date = :d');
$existing->execute([':w' => (int)$worker['id'], ':d' => $today]);
$att = $existing->fetch();

if (!$att) {
    $db->prepare('INSERT INTO worker_attendance (worker_id, attend_date, check_in, method, device_info) VALUES (:w, :d, CURRENT_TIMESTAMP, :m, :dev)')
       ->execute([':w' => (int)$worker['id'], ':d' => $today, ':m' => $method, ':dev' => $deviceInfo]);
    logAudit($db, 'WORKER_CHECKIN', 'WORKER', $worker['staff_no'], "{$worker['full_name']} checked in via {$method} ({$deviceInfo}).");
    echo json_encode(['ok' => true, 'result' => 'check_in', 'worker' => $worker['full_name'], 'time' => date('H:i:s')]);
} elseif ($att['check_out'] === null) {
    $db->prepare('UPDATE worker_attendance SET check_out = CURRENT_TIMESTAMP WHERE id = :id')->execute([':id' => $att['id']]);
    logAudit($db, 'WORKER_CHECKOUT', 'WORKER', $worker['staff_no'], "{$worker['full_name']} checked out via {$method} ({$deviceInfo}).");
    echo json_encode(['ok' => true, 'result' => 'check_out', 'worker' => $worker['full_name'], 'time' => date('H:i:s')]);
} else {
    echo json_encode(['ok' => true, 'result' => 'already_complete', 'worker' => $worker['full_name']]);
}
