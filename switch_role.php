<?php
/**
 * U EPMS - Quick Role Switcher Endpoint
 * Facilitates instantaneous testing of RBAC policies across roles.
 */
require_once __DIR__ . '/includes/session.php';
initAppSession();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$userId = (int)($_POST['user_id'] ?? $_GET['user_id'] ?? 1);

$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if ($user) {
    if ($user['status'] === 'Banned') {
        session_destroy();
        commitSessionAndRedirect('/index.php?error=account_banned');
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_username'] = $user['username'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_status'] = $user['status'];

    logAudit($db, 'USER_ROLE_SWITCH', 'SESSION', $user['id'], "Switched active test session to {$user['name']} ({$user['role']})");
    setFlash('success', "Switched active user to " . htmlspecialchars($user['name']) . " (" . htmlspecialchars($user['role']) . "). Permissions updated.");

    $referer = $_SERVER['HTTP_REFERER'] ?? '/dashboard.php';
    // Procurement Officer is strictly scoped to the procurement records portal
    if ($user['role'] === 'Procurement Officer') {
        $referer = '/procurement.php';
    } elseif (str_contains($referer, 'switch_role.php')) {
        $referer = '/dashboard.php';
    }

    commitSessionAndRedirect($referer);
}

commitSessionAndRedirect('/index.php');
