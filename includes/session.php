<?php
/**
 * U EPMS - Centralized Session Manager
 * Secure cookies on HTTPS, plain cookies on localhost (http) so the
 * app works out of the box with `php -S localhost:3000` / XAMPP.
 *
 * v2.2 hardening:
 *   - Session identifier is accepted ONLY from the cookie (no ?sid= URLs,
 *     no Authorization-header sessions - those leak through logs/history)
 *   - Cookie lifetime shortened to one working day
 */

function initAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Detect HTTPS (direct or via proxy)
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.use_only_cookies', '1');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 86400,            // one working day
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
 * Commits the session to disk and redirects.
 * (The old sid-in-URL fallback is gone: cookies only.)
 */
function commitSessionAndRedirect(string $url): void
{
    session_write_close();
    header('Location: ' . $url);
    exit;
}
