<?php
/**
 * U EPMS - User Accounts & Access Management
 * CEO (owner): create users, ban/unban, hard-delete, and manage (view/change) passwords.
 * Pure PHP 8.2 & Plain HTML5/CSS3 (Zero Frameworks)
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole(['CEO']);

$pageTitle = 'User Accounts & Access Management';
$activeNav = 'users';

$currentUserRole = $_SESSION['user_role'];
$currentUserId = $_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Add New User
    if ($action === 'create_user') {
        $allowedRoles = ['Procurement Officer', 'Manager', 'Accountant', 'CEO'];

        $errors = [];
        $name     = normalizePersonName(field_text($errors, 'name', 'Full name', true, 2, 100) ?? '');
        if ($name !== '' && preg_match("/^[\p{Lu}0-9 .'\-]+$/u", $name) !== 1) {
            $errors[] = "• Full name must be in CAPITAL LETTERS (letters, spaces, apostrophes, hyphens and dots only).";
            $name = '';
        }
        $username = field_username($errors, 'username');
        $role     = field_choice($errors, 'role', 'Role', $allowedRoles);
        $password = field_password($errors, 'password');

        if ($errors) {
            redirectWithErrors('/users.php?action=new', $errors);
        }

        $check = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
        $check->execute([':u' => $username]);
        if ($check->fetchColumn() > 0) {
            setFlash('error', "Username '{$username}' is already taken. Choose another.");
            header('Location: /users.php?action=new');
            exit;
        }

        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $enc  = encryptPassword($password);
        } catch (RuntimeException $e) {
            setFlash('error', $e->getMessage());
            header('Location: /users.php?action=new');
            exit;
        }
        $stmt = $db->prepare("
            INSERT INTO users (name, username, password_hash, password_encrypted, role, status, created_at)
            VALUES (:name, :user, :pass, :enc, :role, 'Active', CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':name'   => $name,
            ':user'   => $username,
            ':pass'   => $hash,
            ':enc'    => $enc,
            ':role'   => $role,
        ]);
        $newUid = $db->lastInsertId();

        logAudit($db, 'USER_CREATED', 'USER', $newUid, "Registered user '{$name}' with role '{$role}'");
        setFlash('success', "User '{$name}' successfully created with role '{$role}'.");
        header('Location: /users.php');
        exit;
    }

    // 2. Toggle Status (Ban <-> Reactivate)
    if ($action === 'toggle_status') {
        $targetId = (int)$_POST['target_user_id'];

        if ($targetId === $currentUserId) {
            setFlash('error', 'Security rule: You cannot ban or reactivate your own active session account.');
            header('Location: /users.php');
            exit;
        }

        $uStmt = $db->prepare("SELECT * FROM users WHERE id = :id");
        $uStmt->execute([':id' => $targetId]);
        $targetUser = $uStmt->fetch();

        if ($targetUser) {
            $newStatus = $targetUser['status'] === 'Active' ? 'Banned' : 'Active';
            $upd = $db->prepare("UPDATE users SET status = :s WHERE id = :id");
            $upd->execute([':s' => $newStatus, ':id' => $targetId]);

            logAudit($db, 'USER_STATUS_TOGGLED', 'USER', $targetId, "User '{$targetUser['name']}' status changed to '{$newStatus}' by {$currentUserName}");
            setFlash('success', "User '{$targetUser['name']}' is now {$newStatus}.");
        }
        header('Location: /users.php');
        exit;
    }

    // 3. Delete User Completely (CEO only)
    if ($action === 'delete_user') {
        $targetId = (int)$_POST['target_user_id'];

        if ($targetId === $currentUserId) {
            setFlash('error', 'Security rule: You cannot delete your own active session account.');
            header('Location: /users.php');
            exit;
        }

        $uStmt = $db->prepare("SELECT * FROM users WHERE id = :id");
        $uStmt->execute([':id' => $targetId]);
        $targetUser = $uStmt->fetch();

        if ($targetUser) {
            try {
                $db->beginTransaction();

                // Historical business records are preserved: the schema's
                // ON DELETE SET NULL foreign keys detach them from the user,
                // then the account itself is removed completely.
                $del = $db->prepare("DELETE FROM users WHERE id = :id");
                $del->execute([':id' => $targetId]);

                logAudit($db, 'USER_DELETED', 'USER', $targetId, "User '{$targetUser['name']}' ({$targetUser['role']}) permanently deleted by {$currentUserName}");
                $db->commit();

                setFlash('success', "User '{$targetUser['name']}' has been permanently deleted from the system. Historical records were preserved.");
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                setFlash('error', 'Failed to delete user: ' . $e->getMessage());
            }
        }
        header('Location: /users.php');
        exit;
    }

    // 4. Change Password (CEO only)
    if ($action === 'change_password') {
        $targetId    = (int)($_POST['target_user_id'] ?? 0);

        $errors = [];
        $newPassword = field_password($errors, 'new_password', 'New password');
        if ($errors) {
            redirectWithErrors('/users.php', $errors);
        }

        $uStmt = $db->prepare("SELECT * FROM users WHERE id = :id");
        $uStmt->execute([':id' => $targetId]);
        $targetUser = $uStmt->fetch();

        if ($targetUser) {
            try {
                $upd = $db->prepare("UPDATE users SET password_hash = :h, password_encrypted = :e WHERE id = :id");
                $upd->execute([
                    ':h'  => password_hash($newPassword, PASSWORD_BCRYPT),
                    ':e'  => encryptPassword($newPassword),
                    ':id' => $targetId,
                ]);
                logAudit($db, 'PASSWORD_CHANGED', 'USER', $targetId, "Password of '{$targetUser['name']}' changed by {$currentUserName}");
                setFlash('success', "Password for '{$targetUser['name']}' has been updated. The new password applies at next sign-in.");
            } catch (RuntimeException $e) {
                setFlash('error', $e->getMessage());
            }
        }
        header('Location: /users.php');
        exit;
    }

    // 5. View (Reveal) Password (CEO only) - always audit logged
    if ($action === 'view_password') {
        $targetId = (int)$_POST['target_user_id'];

        $uStmt = $db->prepare("SELECT * FROM users WHERE id = :id");
        $uStmt->execute([':id' => $targetId]);
        $targetUser = $uStmt->fetch();

        if ($targetUser) {
            $plain = !empty($targetUser['password_encrypted']) ? decryptPassword($targetUser['password_encrypted']) : null;
            if ($plain !== null) {
                logAudit($db, 'PASSWORD_VIEWED', 'USER', $targetId, "Password of '{$targetUser['name']}' revealed by {$currentUserName}");
                setFlash('success', "Password for '{$targetUser['name']}' is: {$plain}");
            } else {
                setFlash('error', "No recoverable password is stored for '{$targetUser['name']}' yet. Use Change Password to set one.");
            }
        }
        header('Location: /users.php');
        exit;
    }

    setFlash('error', 'Unknown user management action.');
    header('Location: /users.php');
    exit;
}

// Fetch users
$users = $db->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();
$showNewForm = isset($_GET['action']) && $_GET['action'] === 'new';

// Change-password form state
$passwordTarget = null;
if (isset($_GET['action']) && $_GET['action'] === 'password') {
    $pid = (int)($_GET['user_id'] ?? 0);
    foreach ($users as $u) {
        if ((int)$u['id'] === $pid) {
            $passwordTarget = $u;
            break;
        }
    }
}

include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 class="page-title">User Accounts Directory</h2>
            <p class="page-subtitle">Security boundaries, identity registry, and operational privilege administration</p>
        </div>
        <div style="display:flex; gap:10px;">
            <?php if (!$showNewForm): ?>
                <a href="/users.php?action=new" class="btn btn-primary">+ Register New User</a>
            <?php else: ?>
                <a href="/users.php" class="btn btn-secondary">&larr; Back to Users</a>
            <?php endif; ?>
        </div>
    </div>

    <?php displayFlash(); ?>

    <!-- Register User Form -->
    <?php if ($showNewForm): ?>
        <div class="card" style="border: 2px solid var(--primary-border);">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Register New System Account</h3>
                    <p class="card-subtitle">Assign plant role and initialize credentials</p>
                </div>
                <a href="/users.php" class="btn btn-secondary btn-sm">&times; Cancel</a>
            </div>

            <form method="POST" action="/users.php">
                <input type="hidden" name="action" value="create_user">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="name">Full Name * <span style="font-weight:500; color:var(--text-muted); font-size:11px;">(in CAPITAL LETTERS)</span></label>
                        <input type="text" id="name" name="name" required placeholder="e.g. SARAH JENKINS" class="form-control" pattern="[A-Za-z0-9 .'\-]+"
                               maxlength="100" data-plaintext data-required-error="Full name is required."
                               data-pattern-error="Full name must use CAPITAL LETTERS (letters, spaces, apostrophes, hyphens and dots only)."
                               oninput="this.value = this.value.toUpperCase();"                 >
                    </div>

                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" id="username" name="username" required placeholder="e.g. jenkins_s" class="form-control"
                               data-pattern-error="Use only letters, numbers, dots or underscores (3-32 characters).">
                    </div>

                    <div class="form-group">
                        <label for="role">Assigned System Role *</label>
                        <select id="role" name="role" class="form-control" required>
                            <option value="Procurement Officer">Procurement Officer (Submit Procurement Records)</option>
                            <option value="Manager">Manager (Shift Reports, Expenses &amp; First Procurement Approval)</option>
                            <option value="Accountant">Accountant (Expenses &amp; Final Procurement Approval)</option>
                            <option value="CEO">CEO (Owner: Floats, Settings, Users)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="password">Temporary Password *</label>
                        <input type="password" id="password" name="password" required value="factory123" class="form-control"
                               minlength="6" maxlength="64" data-required-error="Temporary password is required.">
                        <span class="form-help">6-64 characters, no spaces. The user can sign in with it immediately.</span>
                    </div>
                </div>

                <div style="margin-top:16px; display:flex; justify-content:flex-end; gap:10px;">
                    <a href="/users.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Create User Account &rarr;</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Change Password Form -->
    <?php if ($passwordTarget): ?>
        <div class="card" style="border: 2px solid #f59e0b;">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Change Password &mdash; <?= htmlspecialchars($passwordTarget['name']) ?></h3>
                    <p class="card-subtitle">The new password applies at next sign-in and is stored both hashed (login) and encrypted (recovery)</p>
                </div>
                <a href="/users.php" class="btn btn-secondary btn-sm">&times; Cancel</a>
            </div>

            <form method="POST" action="/users.php">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="target_user_id" value="<?= (int)$passwordTarget['id'] ?>">

                <div class="form-group">
                    <label for="new_password">New Password *</label>
                    <input type="text" id="new_password" name="new_password" required minlength="6" maxlength="64" class="form-control" placeholder="Minimum 6 characters" autocomplete="new-password"
                           data-required-error="New password is required.">
                    <span class="form-help">Minimum 6 characters, no spaces. You can reveal it later with the &quot;View Password&quot; button.</span>
                </div>

                <div style="margin-top:16px; display:flex; justify-content:flex-end; gap:10px;">
                    <a href="/users.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Update Password &rarr;</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- User Table -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Personnel Registry</h3>
                <p class="card-subtitle">Ban, reactivate, view &amp; change passwords, or permanently delete system accounts</p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Account Status</th>
                        <th>Created At</th>
                        <th>Access Governance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u):
                        $roleClass = match($u['role']) {
                            'CEO' => 'badge-danger',
                            'Manager' => 'badge-warning',
                            'Accountant' => 'badge-success',
                            'Procurement Officer' => 'badge-info',
                            default => 'badge-secondary',
                        };
                        $isCurrent = $u['id'] == $currentUserId;
                    ?>
                        <tr>
                            <td class="mono">#<?= $u['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($u['name']) ?></strong>
                                <?php if ($isCurrent): ?>
                                    <span style="font-size:11px; color:var(--primary); font-weight:700;">(YOU)</span>
                                <?php endif; ?>
                            </td>
                            <td class="mono"><?= htmlspecialchars($u['username']) ?></td>
                            <td><span class="badge <?= $roleClass ?>"><?= htmlspecialchars($u['role']) ?></span></td>
                            <td>
                                <?php if ($u['status'] === 'Active'): ?>
                                    <span class="badge badge-success">&#9679; Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">&#9679; Banned / Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td><?= formatDate($u['created_at']) ?></td>
                            <td>
                                <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                                    <form method="POST" action="/users.php" style="display:inline;">
                                        <input type="hidden" name="action" value="view_password">
                                        <input type="hidden" name="target_user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Reveal the password of <?= htmlspecialchars($u['name']) ?>? This action is recorded in the Audit Trail.');">
                                            View Password
                                        </button>
                                    </form>
                                    <a href="/users.php?action=password&user_id=<?= (int)$u['id'] ?>" class="btn btn-secondary btn-sm">Change Password</a>
                                    <?php if (!$isCurrent): ?>
                                        <form method="POST" action="/users.php" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="target_user_id" value="<?= (int)$u['id'] ?>">
                                            <?php if ($u['status'] === 'Active'): ?>
                                                <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Suspend user <?= htmlspecialchars($u['name']) ?>? They will be unable to sign in.');">
                                                    Ban
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" class="btn btn-success btn-sm">
                                                    Reactivate
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                        <form method="POST" action="/users.php" style="display:inline;" onsubmit="return confirm('PERMANENT ACTION: Completely DELETE user <?= htmlspecialchars($u['name']) ?>? The account is removed for good; historical business records are preserved.');">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="target_user_id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" style="background:#dc2626;">
                                                Delete
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:var(--text-subtle);">(current user)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Role-Based Access Control Matrix Explainer Table -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Role Permissions Matrix</h3>
                <p class="card-subtitle">Institutional security segregation enforced at PHP script headers</p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Functional Domain</th>
                        <th>Procurement Officer</th>
                        <th>Manager</th>
                        <th>Accountant</th>
                        <th>CEO</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Submit Procurement Records</strong></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Sole authority</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                    </tr>
                    <tr>
                        <td><strong>First Procurement Approval</strong></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; On dashboard</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                    </tr>
                    <tr>
                        <td><strong>Final Procurement Approval</strong></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Final approver (locks record)</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                    </tr>
                    <tr>
                        <td><strong>Issue New Petty Cash Floats</strong></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--danger); font-weight:700;">&#10007; No (expenses only)</span></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Full</span></td>
                    </tr>
                    <tr>
                        <td><strong>Record Expenses Against Float</strong></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Full</span></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Full</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                    </tr>
                    <tr>
                        <td><strong>Log Daily Production Shift Reports</strong></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Full</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Full</span></td>
                    </tr>
                    <tr>
                        <td><strong>Machinery &amp; Process Config</strong></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Full</span></td>
                    </tr>
                    <tr>
                        <td><strong>Audit Trail Access</strong></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Full</span></td>
                    </tr>
                    <tr>
                        <td><strong>Ban / Delete User Accounts</strong></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Full</span></td>
                    </tr>
                    <tr>
                        <td><strong>View / Change User Passwords</strong></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--text-subtle);">&mdash;</span></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Full</span></td>
                    </tr>
                    <tr>
                        <td><strong>View Operational Data</strong></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Procurement records only</span></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Yes</span></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Yes</span></td>
                        <td><span style="color:var(--success); font-weight:700;">&#10003; Yes</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
