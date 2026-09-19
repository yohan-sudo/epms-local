<?php
/**
 * U EPMS - Authentication & Role Access Gateway
 * Plain PHP, Plain HTML5, Plain CSS3 (Zero Frameworks)
 *
 * v2.2 hardening:
 *   - Lockout: 5 failed attempts pauses the account for 15 minutes
 *   - Session ID regenerated at every login (fixation defense)
 *   - Accounts flagged must_change_password are routed to the change page
 *   - Every failed attempt is logged with its source address
 */
require_once __DIR__ . '/includes/session.php';
initAppSession();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

const MAX_LOGIN_ATTEMPTS = 5;
const LOCKOUT_MINUTES    = 15;

// Handle Username/Password Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

    $loginErrors = [];
    if ($username === '' || $password === '') {
        $loginErrors[] = 'Both username and password are required.';
    } elseif (!preg_match('/^[A-Za-z0-9_.]{3,32}$/', $username)) {
        $loginErrors[] = 'Username format is invalid (3-32 letters, numbers, dots or underscores).';
    } elseif (strlen($password) < 6 || strlen($password) > 64 || preg_match('/\s/', $password)) {
        $loginErrors[] = 'Password length is invalid (6-64 characters, no spaces).';
    }
    if ($loginErrors) {
        setFlash('error', implode(' ', $loginErrors));
        commitSessionAndRedirect('/index.php');
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(:u) OR LOWER(name) = LOWER(:u)");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    // ---- Lockout gate: no password check while the account is paused ----
    if ($user && !empty($user['locked_until']) && strtotime((string)$user['locked_until']) > time()) {
        $mins = (int)ceil((strtotime((string)$user['locked_until']) - time()) / 60);
        setFlash('error', "Account locked for {$mins} more minute(s) after repeated failed sign-ins. Try again shortly.");
        commitSessionAndRedirect('/index.php');
    }

    if ($user && ($user['status'] === 'Banned')) {
        logAudit($db, 'LOGIN_BLOCKED_BANNED', 'AUTH', $user['id'], "Blocked sign-in for banned account '{$user['name']}' from {$clientIp}");
        setFlash('error', "Account Suspended: Access denied for {$user['name']}. Contact the CEO.");
        commitSessionAndRedirect('/index.php');
    }

    if ($user && password_verify($password, $user['password_hash'])) {
        // Success: reset failure counter, clear any lockout
        try {
            $db->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = :id")
               ->execute([':id' => $user['id']]);
        } catch (Exception $e) {
            // non-fatal
        }

        // Fixation defense: brand-new session ID for an authenticated session
        session_regenerate_id(true);

        $_SESSION['user_id']     = $user['id'];
        $_SESSION['user_name']   = $user['name'];
        $_SESSION['user_username'] = $user['username'];
        $_SESSION['user_role']   = $user['role'];
        $_SESSION['user_status'] = $user['status'];

        logAudit($db, 'USER_LOGIN', 'AUTH', $user['id'], "User {$user['name']} authenticated via credentials");

        // Forced password change: temporary / first-login passwords
        if (!empty($user['must_change_password'])) {
            $_SESSION['pending_password_change'] = true;
            commitSessionAndRedirect('/change_password.php');
        }

        $destination = ($user['role'] === 'Procurement Officer') ? '/procurement.php' : '/dashboard.php';
        commitSessionAndRedirect($destination);
    } else {
        // Failure: count it, lock at the threshold, log it
        if ($user) {
            $attempts = (int)$user['failed_attempts'] + 1;
            $lockUntil = $attempts >= MAX_LOGIN_ATTEMPTS
                ? date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60)
                : null;
            try {
                $upd = $db->prepare("UPDATE users SET failed_attempts = :a, locked_until = :lu WHERE id = :id");
                $upd->execute([':a' => $attempts, ':lu' => $lockUntil, ':id' => $user['id']]);
            } catch (Exception $e) {
                // non-fatal
            }
            if ($lockUntil) {
                logAudit($db, 'LOGIN_LOCKED', 'AUTH', $user['id'], "Account '{$user['name']}' locked for " . LOCKOUT_MINUTES . " minutes after {$attempts} failed attempts (from {$clientIp})");
                setFlash('error', 'Too many failed attempts. The account is locked for ' . LOCKOUT_MINUTES . ' minutes.');
                commitSessionAndRedirect('/index.php');
            }
        }
        setFlash('error', "Invalid username or password.");
        commitSessionAndRedirect('/index.php');
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
<script src="/assets/js/validate.js" defer></script>
</head>
<body style="background-color: #0f172a;">

<div class="login-screen">
    <div class="login-card">
        <div class="login-header">
            <div class="brand-icon" style="margin: 0 auto; width: 48px; height: 48px; font-size: 24px;">E</div>
            <h2><?= htmlspecialchars(APP_NAME) ?></h2>
            <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                Production &bull; Procurement &bull; Petty Cash &bull; <?= APP_CURRENCY ?>
            </p>
        </div>

        <?php displayFlash(); ?>

        <?php if (isset($_GET['error']) && $_GET['error'] === 'account_banned'): ?>
            <div class="alert alert-danger">
                <span>Access Denied: That account has been <strong>Banned</strong> by an administrator.</span>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['logged_out'])): ?>
            <div class="alert alert-info">
                <span>You have been safely signed out.</span>
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['user_id'])): ?>
            <div class="alert alert-success" style="margin-bottom: 20px;">
                <div>
                    Currently signed in as: <strong><?= htmlspecialchars($_SESSION['user_name']) ?></strong> (<?= htmlspecialchars($_SESSION['user_role']) ?>)
                </div>
                <a href="/dashboard.php" class="btn btn-primary btn-sm" style="margin-top: 8px; display:inline-block;">Go to Dashboard &rarr;</a>
            </div>
        <?php endif; ?>

        <!-- Standard Credentials Form -->
        <form method="POST" action="/index.php">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" required autocomplete="username" placeholder="Enter your username">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password" placeholder="Enter your password">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 10px; margin-top: 8px;">
                Sign In with Credentials
            </button>
        </form>

    </div>
</div>

</body>
</html>

