<?php
/**
 * U EPMS - Friendly Error Handling
 * Registered from config.php. Unexpected exceptions/fatals render a calm
 * page for users; the technical details go to logs/error.log (private).
 *
 * APP_DEBUG=true (local development) still shows the real message inline.
 */

function uepms_log_exception(Throwable $e): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $line = sprintf(
        "[%s] %s in %s:%d | url=%s | user=%s\n%s\n\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $_SERVER['REQUEST_URI'] ?? '-',
        $_SESSION['user_name'] ?? '-',
        $e->getTraceAsString()
    );
    @file_put_contents($dir . '/error.log', $line, FILE_APPEND | LOCK_EX);
}

function uepms_render_error_page(Throwable $e): void
{
    uepms_log_exception($e);
    if (ob_get_length()) {
        @ob_end_clean();
    }
    http_response_code(500);
    $debug = defined('APP_DEBUG') && APP_DEBUG;
    $detail = $debug ? htmlspecialchars($e->getMessage()) : 'The technical details were recorded and the developer can review them.';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Something went wrong</title></head>'
        . '<body style="font-family:Segoe UI,Arial,sans-serif;background:#f1f5f9;margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;">'
        . '<div style="background:#fff;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.08);padding:36px 40px;max-width:520px;text-align:center;">'
        . '<div style="font-size:40px;">&#9888;&#65039;</div>'
        . '<h2 style="margin:12px 0 6px;color:#0f172a;">Something went wrong</h2>'
        . '<p style="color:#475569;font-size:14px;line-height:1.6;margin:0 0 20px;">The page could not be completed. '
        . htmlspecialchars($detail) . '<br>Your work up to this point is safe - please try again.</p>'
        . '<a href="/dashboard.php" style="background:#1d4ed8;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-size:14px;">Back to Dashboard</a>'
        . '</div></body></html>';
    exit;
}

set_exception_handler(function (Throwable $e) {
    uepms_render_error_page($e);
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        uepms_render_error_page(new Error($err['message']));
    }
});
