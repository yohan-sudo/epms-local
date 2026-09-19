<?php
/**
 * Factory Management System - Session & RBAC Auth Middleware
 * Native PHP 8.x session verification
 */

require_once __DIR__ . '/session.php';
initAppSession();

/**
 * Ensures user is authenticated. Redirects to index.php if not.
 */
function requireAuth(): void {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['flash_message'] = [
            'type' => 'warning',
            'text' => 'Session expired or not authenticated. Please log in.'
        ];
        commitSessionAndRedirect('/index.php');
    }

    if (isset($_SESSION['user_status']) && $_SESSION['user_status'] === 'Banned') {
        session_destroy();
        commitSessionAndRedirect('/index.php?error=account_banned');
    }

    // Forced password change: accounts on a temporary password may visit
    // only the change page until they set their own.
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $allowedWhilePending = ['change_password.php', 'logout.php'];
    if (!empty($_SESSION['pending_password_change']) && !in_array($script, $allowedWhilePending, true)) {
        commitSessionAndRedirect('/change_password.php');
    }
}

/**
 * Role-based access control helpers.
 *
 * Role set (v2.3):
 *   'CEO'                 - owner; sees everything, issues petty cash, approves cash requests,
 *                           inventory view-only, user management, audit trail, attendance
 *   'Manager'             - operations oversight; approves production + shipments + inventory
 *                           requests, cash request workflow, targets; NO inventory access
 *   'Accountant'          - payments and petty cash ONLY; disburses approved cash requests;
 *                           no production, no procurement records
 *   'Procurement Officer' - procures, full inventory control, shipment prep, cash requests
 *   'Supervisor'          - logs production + electricity + machine failures; requests
 *                           inventory materials; no money, no inventory stock control
 *   'Assistant Manager'   - reports viewer, no authority
 */
function requireRole(array $allowedRoles): void {
    requireAuth();
    
    global $db; // the 403 page includes header/sidebar which query the DB
    $currentUserRole = $_SESSION['user_role'] ?? '';
    
    if (!in_array($currentUserRole, $allowedRoles, true)) {
        http_response_code(403);
        $pageTitle = 'Access Denied';
        $activeNav = '';
        include __DIR__ . '/../components/header.php';
        echo '<div class="page-container">';
        echo '<div class="card alert-danger" style="margin-top: 24px;">';
        echo '<h2 style="font-size: 20px; font-weight: 800; margin-bottom: 8px;">403 Forbidden - Insufficient Permissions</h2>';
        echo '<p style="margin-bottom: 16px;">Your current role (<strong>' . htmlspecialchars($currentUserRole) . '</strong>) does not have access permissions for this module.</p>';
        echo '<div style="display:flex; gap:10px;">';
        if ($currentUserRole === 'Procurement Officer') {
            echo '<a href="/procurement.php" class="btn btn-secondary">&larr; Return to Procurement</a>';
        } elseif ($currentUserRole === 'Supervisor') {
            echo '<a href="/production.php" class="btn btn-secondary">&larr; Return to Production</a>';
        } else {
            echo '<a href="/dashboard.php" class="btn btn-secondary">&larr; Return to Dashboard</a>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';
        include __DIR__ . '/../components/footer.php';
        exit;
    }
}

/**
 * Checks if current user possesses a specific role
 */
function hasRole(string $role): bool {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
}

/**
 * Checks if current user possesses any of the given roles
 */
function hasAnyRole(array $roles): bool {
    return isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], $roles, true);
}
