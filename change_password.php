<?php
/**
 * U EPMS - Own Password Change (forced at first login + self-service)
 * Reachable only by an authenticated user changing THEIR OWN password.
 * When the account is flagged must_change_password, every other page
 * redirects here until the change is completed.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();

$userId   = (int)$_SESSION['user_id'];
$userName = (string)$_SESSION['user_name'];
$isForced = !empty($_SESSION['pending_password_change']);

// Handle the change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_own_password') {
    $errors = [];
    $newPassword = field_password($errors, 'new_password', 'New password', 12, 64);
    $confirm     = (string)($_POST['confirm_password'] ?? '');

    if ($newPassword !== null && $newPassword !== $confirm) {
        $errors[] = '• New password and confirmation do not match.';
    }
    if ($newPassword !== null && in_array(strtolower($newPassword), ['password', 'factory123', '12345678', '123456789', 'qwerty123'], true)) {
        $errors[] = '• That password is too common. Choose something unique.';
    }

    if ($errors) {
        setFlash('error', implode(' ', $errors));
        commitSessionAndRedirect('/change_password.php');
    }

    try {
        $stmt = $db->prepare("
            UPDATE users
            SET password_hash = :h, must_change_password = 0, password_changed_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':h' => password_hash($newPassword, PASSWORD_BCRYPT), ':id' => $userId]);
    } catch (Exception $e) {
        setFlash('error', 'Could not update the password. Please try again.');
        commitSessionAndRedirect('/change_password.php');
    }

    unset($_SESSION['pending_password_change']);
    logAudit($db, 'PASSWORD_CHANGED_SELF', 'USER', $userId, "{$userName} set a new password for their own account");
    setFlash('success', 'Password updated successfully. Welcome aboard!');
    commitSessionAndRedirect('/dashboard.php');
}

$uStmt = $db->prepare("SELECT must_change_password, password_changed_at FROM users WHERE id = :id");
$uStmt->execute([':id' => $userId]);
$uRow = $uStmt->fetch();
$isForced = $isForced || ($uRow && !empty($uRow['must_change_password']));

$pageTitle = 'Change Password';
$activeNav = '';
include __DIR__ . '/components/header.php';
?>

<main class="page-container" style="max-width: 560px;">
    <div class="page-header">
        <h2 class="page-title">Change Your Password</h2>
        <p class="page-subtitle">Passwords are never visible to anyone - not even the CEO. Choose a strong one only you know.</p>
    </div>

    <?php displayFlash(); ?>

    <?php if ($isForced): ?>
        <div class="card alert-warning" style="margin-bottom:16px;">
            <strong>Security requirement:</strong> your account has a temporary password.
            You must set your own password before you can use the system.
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="/change_password.php">
            <input type="hidden" name="action" value="change_own_password">
            <div class="form-group">
                <label for="new_password">New Password * <span style="font-weight:500; color:var(--text-muted); font-size:11px;">(minimum 12 characters)</span></label>
                <input type="password" id="new_password" name="new_password" required minlength="12" maxlength="64"
                       class="form-control" autocomplete="new-password"
                       placeholder="Use a memorable passphrase, e.g. blue-harvest-42-mkoma">
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm New Password *</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="12" maxlength="64"
                       class="form-control" autocomplete="new-password" placeholder="Type it once more">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:8px;">
                <?php if (!$isForced): ?>
                    <a href="/dashboard.php" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">Set My New Password &rarr;</button>
            </div>
        </form>
    </div>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
