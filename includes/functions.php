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
 * so Admin/System Operator can recover a password through the Users page.
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

/**
 * Get CSS badge class for procurement statuses
 */
function getProcurementStatusBadge(string $status): string {
    return match ($status) {
        'Finalized' => '<span class="badge badge-success">Finalized</span>',
        'Approved - Pending Admin Decision' => '<span class="badge badge-info">Pending Admin Decision</span>',
        'Pending Accountant Review' => '<span class="badge badge-warning">Pending Accountant</span>',
        'Pending Manager Review' => '<span class="badge badge-primary">Pending Manager</span>',
        'Rejected' => '<span class="badge badge-danger">Rejected</span>',
        default => '<span class="badge badge-secondary">' . htmlspecialchars($status) . '</span>',
    };
}
