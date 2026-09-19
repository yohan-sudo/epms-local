<?php
/**
 * U EPMS - Delegations (acting approvers)
 * CEO appoints a deputy for Manager/Accountant duties over a date range.
 * The single-holder rule: exactly one active Accountant-scope delegation
 * may exist at a time, and only while the Accountant is still Active.
 * A delegation never outlives its grantor's Active status (checked live
 * in effectiveRole()).
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/components/filter_bar.php';

requireRole(['CEO']);

$pageTitle = 'Delegations';
$activeNav = 'delegations';
$currentUserId = (int)$_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['delegation_action'] ?? '');

    if ($act === 'create') {
        $errors = [];
        $scope  = field_choice($errors, 'role_scope', 'Duties to delegate', ['Manager', 'Accountant']) ?? '';
        $toId   = field_int($errors, 'to_user_id', 'Delegate (acting user)', 1) ?? 0;
        $dFrom  = field_date($errors, 'date_from', 'From date') ?? date('Y-m-d');
        $dTo    = field_date($errors, 'date_to', 'To date') ?? date('Y-m-d');

        if ($dFrom > $dTo) {
            $errors[] = '• The From date must be on or before the To date.';
        }

        // Delegate must be active and must NOT already hold the delegated role
        $toStmt = $db->prepare("SELECT name, role, status FROM users WHERE id = :id");
        $toStmt->execute([':id' => $toId]);
        $toUser = $toStmt->fetch();
        if (!$toUser) {
            $errors[] = '• Delegate user not found.';
        } else {
            if ($toUser['status'] !== 'Active') {
                $errors[] = '• The delegate must be an Active user.';
            }
            if ($toUser['role'] === $scope) {
                $errors[] = "• {$toUser['name']} already holds the {$scope} role - no delegation needed.";
            }
        }

        // Single-holder rule: no overlapping delegation of the same scope
        if (!$errors) {
            $overlap = $db->prepare("
                SELECT COUNT(*) FROM delegations
                WHERE role_scope = :scope AND date_to >= :from AND date_from <= :to
            ");
            $overlap->execute([':scope' => $scope, ':from' => $dFrom, ':to' => $dTo]);
            if ((int)$overlap->fetchColumn() > 0) {
                $errors[] = "• A {$scope} delegation already covers part of that period - only one acting holder is allowed at a time.";
            }
        }

        if ($errors) {
            setFlash('error', implode(' ', $errors));
            commitSessionAndRedirect('/delegations.php');
        }

        $stmt = $db->prepare("
            INSERT INTO delegations (from_user_id, to_user_id, role_scope, date_from, date_to, created_by)
            VALUES (:from_uid, :to_uid, :scope, :dfrom, :dto, :by)
        ");
        // The grantor is the Active user currently holding that role
        $grantorStmt = $db->prepare("SELECT id FROM users WHERE role = :r AND status = 'Active' ORDER BY id ASC LIMIT 1");
        $grantorStmt->execute([':r' => $scope]);
        $grantorId = (int)$grantorStmt->fetchColumn();

        $stmt->execute([
            ':from_uid' => $grantorId,
            ':to_uid'   => $toId,
            ':scope'    => $scope,
            ':dfrom'    => $dFrom,
            ':dto'      => $dTo,
            ':by'       => $currentUserId,
        ]);

        logAudit($db, 'DELEGATION_CREATED', 'DELEGATION', (int)$db->lastInsertId(), "CEO {$currentUserName} delegated {$scope} duties to {$toUser['name']} from {$dFrom} to {$dTo} (single-holder rule enforced)");
        notifyUser($db, $toId, 'You are now acting ' . $scope, "You have been appointed acting {$scope} from {$dFrom} to {$dTo} by {$currentUserName}.", '/delegations.php');
        setFlash('success', "Delegation created: {$toUser['name']} acts as {$scope} from {$dFrom} to {$dTo}.");
        commitSessionAndRedirect('/delegations.php');
    }

    if ($act === 'revoke') {
        $did = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT d.*, u.name AS delegate_name FROM delegations d JOIN users u ON d.to_user_id = u.id WHERE d.id = :id");
        $stmt->execute([':id' => $did]);
        $del = $stmt->fetch();
        if ($del) {
            $db->prepare("DELETE FROM delegations WHERE id = :id")->execute([':id' => $did]);
            logAudit($db, 'DELEGATION_REVOKED', 'DELEGATION', $did, "CEO {$currentUserName} revoked the {$del['role_scope']} delegation for {$del['delegate_name']}");
            setFlash('success', 'Delegation revoked.');
        }
        commitSessionAndRedirect('/delegations.php');
    }

    commitSessionAndRedirect('/delegations.php');
}

$delegations = $db->query("
    SELECT d.*,
           ug.name AS grantor_name,
           ud.name AS delegate_name, ud.role AS delegate_own_role
    FROM delegations d
    LEFT JOIN users ug ON d.from_user_id = ug.id
    LEFT JOIN users ud ON d.to_user_id = ud.id
    ORDER BY d.date_from DESC, d.id DESC
")->fetchAll();

$eligible = $db->query("SELECT id, name, role FROM users WHERE status = 'Active' AND role IN ('Manager', 'Procurement Officer') ORDER BY name ASC")->fetchAll();

include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header">
        <h2 class="page-title">Delegations &amp; Acting Approvers</h2>
        <p class="page-subtitle">Appoint a deputy when the Manager or Accountant is away &mdash; only one acting holder per role is ever allowed, and delegations stop working the moment the real holder returns or is suspended</p>
    </div>

    <?php displayFlash(); ?>

    <div class="card" style="border:2px solid var(--primary-border);">
        <div class="card-header">
            <div>
                <h3 class="card-title">Appoint an Acting Approver</h3>
                <p class="card-subtitle">The delegate gains the chosen role's authority only within the date range &mdash; and only while the real holder is Active</p>
            </div>
        </div>
        <form method="POST" action="/delegations.php">
            <input type="hidden" name="delegation_action" value="create">
            <div class="form-grid">
                <div class="form-group">
                    <label for="role_scope">Duties to delegate *</label>
                    <select id="role_scope" name="role_scope" class="form-control" required>
                        <option value="Manager">Manager (procurement first-line approval)</option>
                        <option value="Accountant">Accountant (final approval + petty cash)</option>
                    </select>
                    <span class="form-help">Only one acting holder per role at any time - the system blocks overlaps.</span>
                </div>
                <div class="form-group">
                    <label for="to_user_id">Delegate (acting user) *</label>
                    <select id="to_user_id" name="to_user_id" class="form-control" required>
                        <?php foreach ($eligible as $e): ?>
                            <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['name']) ?> (<?= htmlspecialchars($e['role']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="date_from">From *</label>
                    <input type="date" id="date_from" name="date_from" value="<?= date('Y-m-d') ?>" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="date_to">To *</label>
                    <input type="date" id="date_to" name="date_to" value="<?= date('Y-m-d') ?>" class="form-control" required>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:12px;">
                <button type="submit" class="btn btn-primary">Create Delegation &rarr;</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">All Delegations</h3>
                <p class="card-subtitle">Historic and current appointments</p>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Real Holder</th>
                        <th>Delegate</th>
                        <th>Acting As</th>
                        <th>Period</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($delegations)): ?>
                        <tr><td colspan="6" class="empty-state">No delegations have been created.</td></tr>
                    <?php else: ?>
                        <?php foreach ($delegations as $d):
                            $isNow = $d['date_from'] <= date('Y-m-d') && $d['date_to'] >= date('Y-m-d') && $d['grantor_name'] !== null;
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($d['grantor_name'] ?? 'Unknown') ?></td>
                                <td><strong><?= htmlspecialchars($d['delegate_name'] ?? 'Unknown') ?></strong>
                                    <div style="font-size:11px; color:var(--text-muted);">own role: <?= htmlspecialchars($d['delegate_own_role'] ?? '') ?></div>
                                </td>
                                <td><span class="badge badge-info"><?= htmlspecialchars($d['role_scope']) ?></span></td>
                                <td class="mono" style="font-size:12px;"><?= htmlspecialchars($d['date_from']) ?> &rarr; <?= htmlspecialchars($d['date_to']) ?></td>
                                <td>
                                    <?php if ($isNow): ?>
                                        <span class="badge badge-warning">ACTING NOW</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Ended / future</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" action="/delegations.php" onsubmit="return confirm('Revoke this delegation? The delegate loses the acting role immediately.');">
                                        <input type="hidden" name="delegation_action" value="revoke">
                                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include __DIR__ . '/components/footer.php'; ?>
