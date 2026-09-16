<?php
/**
 * U EPMS - Authentication & Role Access Gateway
 * Plain PHP, Plain HTML5, Plain CSS3 (Zero Frameworks)
 */
require_once __DIR__ . '/includes/session.php';
initAppSession();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Handle Username/Password Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

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

    if ($user && ($user['status'] === 'Banned')) {
        setFlash('error', "Account Suspended: Access denied for {$user['name']}. Contact the CEO.");
        commitSessionAndRedirect('/index.php');
    }

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_username'] = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_status'] = $user['status'];

        logAudit($db, 'USER_LOGIN', 'AUTH', $user['id'], "User {$user['name']} authenticated via credentials");
        $destination = ($user['role'] === 'Procurement Officer') ? '/procurement.php' : '/dashboard.php';
        commitSessionAndRedirect($destination);
    } else {
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

        <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border-color); font-size: 11.5px; color: var(--text-subtle); line-height: 1.6;">
            <strong>Enterprise Plant Monitoring System</strong><br>
            Manage production, procurement records, and petty cash. All monetary values are recorded in Tanzanian Shillings (<?= APP_CURRENCY ?>).
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const sid = urlParams.get('sid') || localStorage.getItem('factory_sid');
    if (sid) {
        localStorage.setItem('factory_sid', sid);
        document.querySelectorAll('form').forEach(function(form) {
            if (!form.querySelector('input[name="sid"]')) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'sid';
                hidden.value = sid;
                form.appendChild(hidden);
            }
        });
    }

    // Interactive button loading state for instant visual feedback
    // (only when the shared validation engine passes the form)
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (e.defaultPrevented) return;
            if (typeof window.uepmsFormValid === 'function' && !window.uepmsFormValid(form)) {
                e.preventDefault();
                return;
            }
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.style.opacity = '0.7';
                btn.style.pointerEvents = 'none';
                btn.innerText = 'Signing in...';
            }
        });
    });
});
</script>
</body>
</html>
