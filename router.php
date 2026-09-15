<?php
/**
 * Built-In PHP Server Router
 * Directs traffic to the appropriate PHP controllers and serves static assets
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Serve static assets directly (CSS, images, icons, fonts)
$filePath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    // Set appropriate MIME types for assets
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    if ($ext === 'css') {
        header('Content-Type: text/css; charset=utf-8');
    } elseif ($ext === 'js') {
        header('Content-Type: application/javascript; charset=utf-8');
    } elseif ($ext === 'svg') {
        header('Content-Type: image/svg+xml');
    } elseif ($ext === 'png') {
        header('Content-Type: image/png');
    } elseif ($ext === 'jpg' || $ext === 'jpeg') {
        header('Content-Type: image/jpeg');
    }
    return false; // Tells PHP built-in web server to serve this file directly
}

// Fast favicon return to avoid 404 logs
if ($uri === '/favicon.ico') {
    http_response_code(204);
    return true;
}

// Route root to dashboard or procurement portal if session exists, else index
if ($uri === '/' || $uri === '') {
    require_once __DIR__ . '/includes/session.php';
    initAppSession();
    if (!empty($_SESSION['user_id'])) {
        if (($_SESSION['user_role'] ?? '') === 'Procurement Officer') {
            include __DIR__ . '/procurement.php';
        } else {
            include __DIR__ . '/dashboard.php';
        }
    } else {
        include __DIR__ . '/index.php';
    }
    return true;
}

// Route direct .php requests
if (file_exists(__DIR__ . $uri)) {
    include __DIR__ . $uri;
    return true;
}

// Route extensionless requests (e.g. /dashboard -> /dashboard.php)
if (file_exists(__DIR__ . $uri . '.php')) {
    include __DIR__ . $uri . '.php';
    return true;
}

// 404 Fallback
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>404 Not Found - EPMS</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body style="background-color: var(--bg-app); display:flex; align-items:center; justify-content:center; height:100vh;">
    <div class="card" style="max-width:480px; text-align:center; padding:36px;">
        <h2 style="font-size:24px; font-weight:800; color:var(--text-main); margin-bottom:8px;">404 - Page Not Found</h2>
        <p style="color:var(--text-muted); margin-bottom:20px;">The requested factory module could not be found or has moved.</p>
        <a href="/dashboard.php" class="btn btn-primary">&larr; Return to Dashboard</a>
    </div>
</body>
</html>
