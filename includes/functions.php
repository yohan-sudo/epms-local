<?php
/**
 * U EPMS - Core Helper Functions
 * Pure PHP 8.x - currency (TZS), flash messages, CSRF, audit logger.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

/**
 * Reversible password vault (AES-256-GCM).
 *
 * Login still verifies against the bcrypt hash; this encrypted copy exists ONLY
 * so the CEO can recover a password through the Users page.
 * Key comes from APP_KEY in .env. Blobs are base64(IV 12B | TAG 16B | ciphertext).
 */
function encryptPassword(string $plain): string {
    $key = hex2bin((string)env('APP_KEY', ''));
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('APP_KEY missing or invalid in .env - cannot store password.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipherText = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipherText === false) {
        throw new RuntimeException('Password encryption failed.');
    }
    return base64_encode($iv . $tag . $cipherText);
}

function decryptPassword(string $blob): ?string {
    $key = hex2bin((string)env('APP_KEY', ''));
    if ($key === false || strlen($key) !== 32 || $blob === '') {
        return null;
    }
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipherText = substr($raw, 28);
    $plain = openssl_decrypt($cipherText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

/**
 * Appends an immutable record into the audit_logs table.
 */
function logAudit(PDO $db, string $action, string $entityType, string|int $entityId, string $details): void {
    $actorId = $_SESSION['user_id'] ?? null;

    $stmt = $db->prepare("
        INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details, timestamp)
        VALUES (:actor_id, :action, :entity_type, :entity_id, :details, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([
        ':actor_id'    => $actorId,
        ':action'      => $action,
        ':entity_type' => $entityType,
        ':entity_id'   => (string)$entityId,
        ':details'     => $details,
    ]);
}

/**
 * Currency formatter - Tanzanian Shillings (TZS).
 * Example: TZS 1,500,000.00
 */
function formatMoney(float $amount): string {
    return APP_CURRENCY . ' ' . number_format($amount, 2);
}

/**
 * Number formatter
 */
function formatNumber(int|float $num): string {
    return number_format($num);
}

/**
 * Date/time formatter
 */
function formatDateTime(string $datetime): string {
    return date('M d, Y H:i', strtotime($datetime));
}

function formatDate(string $date): string {
    return date('M d, Y', strtotime($date));
}

/**
 * Sets a flash message in the session
 */
function setFlash(string $type, string $message): void {
    initAppSession();
    $_SESSION['flash_message'] = [
        'type' => $type, // 'success', 'error', 'warning', 'info'
        'text' => $message,
    ];
}

/**
 * Renders and clears flash messages from the session
 */
function displayFlash(): void {
    initAppSession();
    if (!empty($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        $type = $flash['type'];
        $alertClass = 'alert-info';
        if ($type === 'success') $alertClass = 'alert-success';
        if ($type === 'error' || $type === 'danger') $alertClass = 'alert-danger';
        if ($type === 'warning') $alertClass = 'alert-warning';

        echo '<div class="alert ' . $alertClass . '">';
        echo '<span>' . htmlspecialchars($flash['text']) . '</span>';
        echo '<button type="button" onclick="this.parentElement.style.display=\'none\';" style="background:none;border:none;cursor:pointer;font-weight:700;padding:0 4px;">&times;</button>';
        echo '</div>';
        unset($_SESSION['flash_message']);
    }
}

/**
 * CSRF Protection
 */
function csrf_token(): string {
    initAppSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool {
    initAppSession();
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/* =========================================================
 * Form Validation Toolkit
 * Shared by every POST handler. Each field_* helper reads the
 * field from $_POST, type-casts it, validates it, and appends
 * a human-readable message into $errors when it is invalid.
 * If $errors is non-empty the handler flashes it and bounces
 * the user back to the form via redirectWithErrors().
 * ========================================================= */

/**
 * Flashes a joined list of validation errors and redirects back to the form.
 */
function redirectWithErrors(string $backUrl, array $errors): void {
    setFlash('error', 'Please fix the following and try again: ' . implode(' ', $errors));
    header('Location: ' . $backUrl);
    exit;
}

/**
 * Trimmed text field with optional length + pattern constraints.
 * Returns the trimmed value, '' when optional-and-empty, or null when invalid.
 */
function field_text(array &$errors, string $key, string $label, bool $required = false, int $min = 0, int $max = 255, ?string $pattern = null, string $patternHint = ''): ?string {
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        if ($required) {
            $errors[] = "• {$label} is required.";
            return null;
        }
        return '';
    }
    $len = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($len < $min) {
        $errors[] = "• {$label} must be at least {$min} characters.";
        return null;
    }
    if ($len > $max) {
        $errors[] = "• {$label} must not exceed {$max} characters.";
        return null;
    }
    if ($pattern !== null && preg_match($pattern, $value) !== 1) {
        $errors[] = "• {$label}: invalid format" . ($patternHint !== '' ? " — {$patternHint}." : '.');
        return null;
    }
    /* Defense-in-depth: these business-text fields never legitimately
     * contain angle brackets or backticks. Output is also escaped everywhere. */
    if (preg_match('/[<>`]/', $value)) {
        $errors[] = "• {$label} must not contain <, > or backtick characters.";
        return null;
    }
    return $value;
}

/**
 * Whole-number field.
 */
function field_int(array &$errors, string $key, string $label, ?int $min = null, ?int $max = null): ?int {
    $raw = trim((string)($_POST[$key] ?? ''));
    if ($raw === '') {
        $errors[] = "• {$label} is required.";
        return null;
    }
    if (!preg_match('/^-?\d+$/', $raw)) {
        $errors[] = "• {$label} must be a whole number.";
        return null;
    }
    $value = (int)$raw;
    if ($min !== null && $value < $min) {
        $errors[] = "• {$label} must be at least {$min}.";
        return null;
    }
    if ($max !== null && $value > $max) {
        $errors[] = "• {$label} must not exceed {$max}.";
        return null;
    }
    return $value;
}

/**
 * Decimal / monetary field (TZS).
 */
function field_float(array &$errors, string $key, string $label, ?float $min = null, ?float $max = null): ?float {
    $raw = trim((string)($_POST[$key] ?? ''));
    if ($raw === '') {
        $errors[] = "• {$label} is required.";
        return null;
    }
    if (!is_numeric($raw)) {
        $errors[] = "• {$label} must be a number.";
        return null;
    }
    $value = (float)$raw;
    if ($min !== null && $value < $min) {
        $errors[] = '• ' . $label . ' must be at least ' . APP_CURRENCY . ' ' . number_format($min) . '.';
        return null;
    }
    if ($max !== null && $value > $max) {
        $errors[] = '• ' . $label . ' must not exceed ' . APP_CURRENCY . ' ' . number_format($max) . '.';
        return null;
    }
    return $value;
}

/**
 * Date field (normalized to Y-m-d). Optionally rejects future dates.
 */
function field_date(array &$errors, string $key, string $label, bool $required = true, bool $disallowFuture = false): ?string {
    $raw = trim((string)($_POST[$key] ?? ''));
    if ($raw === '') {
        if ($required) {
            $errors[] = "• {$label} is required.";
            return null;
        }
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        $errors[] = "• {$label} is not a valid date.";
        return null;
    }
    $value = date('Y-m-d', $ts);
    if ($disallowFuture && $value > date('Y-m-d')) {
        $errors[] = "• {$label} cannot be in the future.";
        return null;
    }
    return $value;
}

/**
 * Whitelisted select/radio value.
 */
function field_choice(array &$errors, string $key, string $label, array $allowed): ?string {
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        $errors[] = "• {$label} is required.";
        return null;
    }
    if (!in_array($value, $allowed, true)) {
        $errors[] = "• {$label} has an invalid selection.";
        return null;
    }
    return $value;
}

/**
 * Username: 3-32 chars, letters/numbers/dots/underscores (also enforced in the HTML pattern).
 */
function field_username(array &$errors, string $key, string $label = 'Username'): ?string {
    return field_text($errors, $key, $label, true, 3, 32, '/^[A-Za-z0-9_.]+$/', 'use only letters, numbers, dots or underscores (3-32 characters)');
}

/**
 * Password: 6-64 chars, no whitespace (also enforced in the HTML pattern).
 */
function field_password(array &$errors, string $key, string $label = 'Password', int $min = 6, int $max = 64): ?string {
    $value = (string)($_POST[$key] ?? '');
    if ($value === '') {
        $errors[] = "• {$label} is required.";
        return null;
    }
    if (strlen($value) < $min) {
        $errors[] = "• {$label} must be at least {$min} characters.";
        return null;
    }
    if (strlen($value) > $max) {
        $errors[] = "• {$label} must not exceed {$max} characters.";
        return null;
    }
    if (preg_match('/\s/', $value)) {
        $errors[] = "• {$label} must not contain spaces.";
        return null;
    }
    return $value;
}

/**
 * Procurement record lifecycle statuses:
 *   'Pending Manager Review'    (Procurement Officer submitted; awaiting Manager approval)
 *   'Pending Accountant Review' (Manager approved; awaiting Accountant final approval)
 *   'Finalized'                 (Accountant approved - closed & locked)
 *   'Rejected'                  (rejected by Manager or Accountant - closed)
 * 'Pending Approval' and 'Approved - Pending Admin Decision' are legacy
 * statuses kept readable so historical rows never disappear.
 */
const PROCUREMENT_OPEN_STATUSES = [
    'Pending Approval',
    'Pending Manager Review',
    'Pending Accountant Review',
    'Approved - Pending Admin Decision',
];

function is_open_requisition(string $status): bool {
    return in_array($status, PROCUREMENT_OPEN_STATUSES, true);
}

/**
 * Get CSS badge class for procurement statuses
 */
function getProcurementStatusBadge(string $status): string {
    return match ($status) {
        'Finalized' => '<span class="badge badge-success">Finalized</span>',
        'Rejected' => '<span class="badge badge-danger">Rejected</span>',
        'Pending Manager Review' => '<span class="badge badge-warning">Awaiting Manager</span>',
        'Pending Accountant Review' => '<span class="badge badge-info">Awaiting Accountant</span>',
        'Pending Approval' => '<span class="badge badge-warning">Awaiting Review</span>',
        'Approved - Pending Admin Decision' => '<span class="badge badge-info">Pending Final Approval (legacy)</span>',
        default => '<span class="badge badge-secondary">' . htmlspecialchars($status) . '</span>',
    };
}
