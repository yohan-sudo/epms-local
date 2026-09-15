<?php
/**
 * U EPMS - Database Connector (PDO)
 * Connects to MySQL on localhost (XAMPP/phpMyAdmin) or falls back to SQLite.
 * The schema is auto-initialized (and seeded) on first run for both drivers.
 */

require_once __DIR__ . '/../config.php';

function uepms_connect(): PDO {
    if (DB_DRIVER === 'mysql') {
        // Step 1: connect to the MySQL server and make sure the schema exists
        try {
            $serverDsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET;
            $server = new PDO($serverDsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $server = null;
        } catch (PDOException $e) {
            http_response_code(500);
            die(
                '<!DOCTYPE html><html><head><title>Database Connection Error</title></head>'
                . '<body style="font-family:sans-serif;max-width:640px;margin:60px auto;">'
                . '<h2 style="color:#dc2626;">MySQL Connection Failed</h2>'
                . '<p>Could not connect to MySQL at <code>' . htmlspecialchars(DB_HOST . ':' . DB_PORT) . '</code>.</p>'
                . '<p style="color:#64748b;">' . htmlspecialchars($e->getMessage()) . '</p>'
                . '<p>Make sure MySQL is running (XAMPP &rarr; Start MySQL) or switch <code>DB_DRIVER=sqlite</code> in <code>.env</code>.</p>'
                . '</body></html>'
            );
        }

        // Step 2: connect to the schema
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $db = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    } else {
        // SQLite fallback (zero configuration)
        $dataDir = dirname(DB_SQLITE_FILE);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }
        $db = new PDO('sqlite:' . DB_SQLITE_FILE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA foreign_keys = ON;');
        $db->exec('PRAGMA journal_mode = WAL;');
    }

    return $db;
}

function uepms_schema_is_initialized(PDO $db): bool {
    if (DB_DRIVER === 'mysql') {
        $stmt = $db->query('SHOW TABLES LIKE ' . $db->quote('users'));
        return $stmt && $stmt->fetch() !== false;
    }
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
    return $stmt && $stmt->fetch() !== false;
}

/**
 * Lightweight in-place migration: ensures the reversible password vault
 * column exists on databases created before it was introduced.
 */
function uepms_migrate(PDO $db): void {
    try {
        if (DB_DRIVER === 'mysql') {
            $cols = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('password_encrypted', $cols, true)) {
                $db->exec('ALTER TABLE users ADD COLUMN password_encrypted VARBINARY(512) NULL AFTER password_hash');
            }
        } else {
            $cols = $db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('password_encrypted', $cols, true)) {
                $db->exec('ALTER TABLE users ADD COLUMN password_encrypted TEXT NULL');
            }
        }
    } catch (Exception $e) {
        // Never block the app over a migration issue.
    }
}

/**
 * One-time convenience backfill: accounts still on the demo default password
 * ('factory123') get their vault copy populated so Admin/Operator can view it.
 * Accounts with a changed password are left NULL (not recoverable) by design.
 * Self-contained: runs before functions.php defines encryptPassword().
 */
function uepms_backfill_default_vault(PDO $db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $rows = $db->query("SELECT id, password_hash FROM users WHERE password_encrypted IS NULL")->fetchAll();
        if (!$rows) {
            return;
        }
        $key = hex2bin((string)env('APP_KEY', ''));
        if ($key === false || strlen($key) !== 32) {
            return;
        }
        $upd = $db->prepare("UPDATE users SET password_encrypted = :e WHERE id = :id");
        foreach ($rows as $row) {
            if (password_verify('factory123', $row['password_hash'])) {
                $iv = random_bytes(12);
                $tag = '';
                $ct = openssl_encrypt('factory123', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
                if ($ct !== false) {
                    $upd->execute([':e' => base64_encode($iv . $tag . $ct), ':id' => $row['id']]);
                }
            }
        }
    } catch (Exception $e) {
        // Best effort only.
    }
}

/**
 * Shared connection used by every page.
 */
$db = uepms_connect();

require_once __DIR__ . '/../init_db.php';
if (!uepms_schema_is_initialized($db)) {
    initializeDatabase($db);
}
uepms_migrate($db);
uepms_backfill_default_vault($db);
