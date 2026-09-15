<?php
/**
 * User Logout & Session Cleanup
 */
require_once __DIR__ . '/includes/session.php';
initAppSession();

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None',
    ]);
    header("Set-Cookie: PHPSESSID=; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Secure; HttpOnly; SameSite=None; Partitioned", false);
}
@session_destroy();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Signing out...</title>
    <script>
    localStorage.removeItem('factory_sid');
    window.location.href = '/index.php?logged_out=1';
    </script>
</head>
<body style="background:#0f172a;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;">
    <p>Signing out... <a href="/index.php?logged_out=1" style="color:#38bdf8;">Click here if not redirected</a></p>
</body>
</html>
