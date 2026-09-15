<?php
/**
 * U EPMS - Centralized Session Manager
 * Secure cookies on HTTPS, plain cookies on localhost (http) so the
 * app works out of the box with `php -S localhost:3000` / XAMPP.
 */

function initAppSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Detect HTTPS (direct or via proxy)
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    // Accept session ID from query param, POST, or Authorization header
    // (fallback for embedded/iframe contexts where cookies are blocked)
    $sid = $_GET['sid'] ?? $_POST['sid'] ?? null;
    if (!$sid && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+([a-zA-Z0-9,-]{1,128})/', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            $sid = $matches[1];
        }
    }
    if ($sid && is_string($sid) && preg_match('/^[a-zA-Z0-9,-]{1,128}$/', $sid)) {
        session_id($sid);
    }

    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 86400 * 7,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,           // plain cookie on http://localhost
            'httponly' => true,
            'samesite' => $https ? 'None' : 'Lax',
        ]);
    } else {
        ini_set('session.cookie_secure', $https ? '1' : '0');
    }

    @session_start();
}

/**
 * Commits the session to disk and redirects, preserving the sid for
 * embedded contexts that rely on URL-based session fallback.
 */
function commitSessionAndRedirect(string $url): void {
    $sid = session_id();
    if ($sid && !str_contains($url, 'sid=') && !empty($_GET['sid'])) {
        $separator = str_contains($url, '?') ? '&' : '?';
        $url .= $separator . 'sid=' . urlencode($sid);
    }
    session_write_close();
    header('Location: ' . $url);
    exit;
}
