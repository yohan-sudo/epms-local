<?php
/**
 * U EPMS - Authentication & Role Access Gateway
 * Plain PHP, Plain HTML5, Plain CSS3 (Zero Frameworks)
 */
require_once __DIR__ . '/includes/session.php';
initAppSession();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Handle Direct Quick-Login / Role selector from login screen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_login_id'])) {
    $userId = (int)$_POST['quick_login_id'];
    $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if ($user) {
        if ($user['status'] === 'Banned') {
            setFlash('error', "Authentication Failed: Account '{$user['name']}' is suspended/banned by the System Operator.");
            commitSessionAndRedirect('/index.php');
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_username'] = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_status'] = $user['status'];

        logAudit($db, 'USER_LOGIN', 'AUTH', $user['id'], "User {$user['name']} logged in via direct role authentication");
        $destination = ($user['role'] === 'Procurement Officer') ? '/procurement.php' : '/dashboard.php';
        commitSessionAndRedirect($destination);
    }
}

// Handle Traditional Username/Password Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'] ?? '';

    $stmt = $db->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(:u) OR LOWER(name) = LOWER(:u)");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if ($user && ($user['status'] === 'Banned')) {
        setFlash('error', "Account Suspended: Access denied for {$user['name']}. Contact System Operator.");
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
        setFlash('error', "Invalid username or password. You may use 1-click role logins below.");
        commitSessionAndRedirect('/index.php');
    }
}

// Fetch all users for the 1-click switcher
$allUsers = $db->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
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
                <input type="text" id="username" name="username" class="form-control" value="gimeno" required autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" value="factory123" required autocomplete="current-password">
                <span class="form-help">Default demo password for all accounts: <code>factory123</code></span>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 10px; margin-top: 8px;">
                Sign In with Credentials
            </button>
        </form>

        <div style="margin: 24px 0 16px; display: flex; align-items: center; text-align: center; color: var(--text-subtle); font-size: 12px;">
            <div style="flex: 1; height: 1px; background: var(--border-color);"></div>
            <span style="padding: 0 12px; font-weight: 600; text-transform: uppercase;">Or Instant 1-Click Role Login</span>
            <div style="flex: 1; height: 1px; background: var(--border-color);"></div>
        </div>

        <!-- 1-Click Role Access Grid -->
        <div class="demo-account-grid">
            <?php foreach ($allUsers as $u): ?>
                <form method="POST" action="/index.php" style="margin:0;">
                    <input type="hidden" name="quick_login_id" value="<?= (int)$u['id'] ?>">
                    <button type="submit" class="demo-user-btn" <?= $u['status'] === 'Banned' ? 'style="border-color:#fca5a5;background:#fef2f2;"' : '' ?>>
                        <div class="demo-user-name">
                            <?= htmlspecialchars($u['name']) ?>
                            <?php if ($u['status'] === 'Banned'): ?>
                                <span style="color:#dc2626;font-size:10px;">(BANNED)</span>
                            <?php endif; ?>
                        </div>
                        <div class="demo-user-role"><?= htmlspecialchars($u['role']) ?></div>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>

        <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border-color); font-size: 11.5px; color: var(--text-subtle); line-height: 1.6;">
            <strong>Enterprise Plant Monitoring System</strong><br>
            Manage production, procurement requisitions, and petty cash. All monetary values are recorded in Tanzanian Shillings (<?= APP_CURRENCY ?>).
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
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.style.opacity = '0.7';
                btn.style.pointerEvents = 'none';
                if (!btn.classList.contains('demo-user-btn')) {
                    btn.innerText = 'Authenticating...';
                }
            }
        });
    });
});
</script>
</body>
</html>
