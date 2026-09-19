<?php
/**
 * U EPMS - Factory Management System
 * Core Configuration & Environment Loader
 * Loads .env from the project root and exposes DB + app constants.
 */

// ---------------------------------------------------------------------
// 1. Simple .env parser (no external dependencies)
// ---------------------------------------------------------------------
$envFile = __DIR__ . '/.env';
if (is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key   = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        // Strip surrounding quotes if present
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && !array_key_exists($key, $GLOBALS['__ENV_CACHE'] ?? [])) {
            $GLOBALS['__ENV_CACHE'][$key] = $value;
        }
    }
}

/**
 * Fetch an environment variable with a default fallback.
 */
function env(string $key, ?string $default = null): ?string {
    $value = $GLOBALS['__ENV_CACHE'][$key]
        ?? getenv($key)
        ?: $default;
    return $value;
}

// ---------------------------------------------------------------------
// 2. Application Constants
// ---------------------------------------------------------------------
define('APP_NAME', env('APP_NAME', 'Enterprise Plant Monitoring System'));
define('APP_VERSION', '2.0.0');
define('APP_ENV', env('APP_ENV', 'local'));
define('APP_DEBUG', in_array(strtolower((string)env('APP_DEBUG', 'true')), ['1', 'true', 'on', 'yes'], true));
define('APP_URL', env('APP_URL', 'http://localhost:3000'));
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Africa/Dar_es_Salaam'));

date_default_timezone_set(APP_TIMEZONE);

// Reporting currency - Tanzanian Shillings (TZS) across the whole system
define('APP_CURRENCY', 'TZS');

// Friendly error handling: unexpected errors show a calm page and log
// privately to logs/error.log (registered before anything can fail).
require_once __DIR__ . '/includes/error_handler.php';

// ---------------------------------------------------------------------
// 3. Database Connection Parameters (localhost by default)
// ---------------------------------------------------------------------
define('DB_DRIVER', strtolower(env('DB_DRIVER', 'mysql')));   // 'mysql' or 'sqlite'
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'factory_db'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', (string)env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));
define('DB_SQLITE_FILE', __DIR__ . '/' . ltrim((string)env('DB_SQLITE_PATH', 'data/factory.db'), '/\\'));
