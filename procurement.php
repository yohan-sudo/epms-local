<?php
/**
 * U EPMS - Requisition Portal (Procurement Officer only)
 * The Procurement Officer can VIEW requisitions and APPROVE or REJECT
 * them. Nothing else - drafting and all other write operations are
 * reserved for other roles.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole(['Procurement Officer']);

$pageTitle = 'Requisition Portal';
$activeNav = 'procurement';

$currentUserRole = $_SESSION['user_role'];
$currentUserId = $_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

// Handle POST: Approve / Reject a requisition
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'officer_decision') {
        $poId    = (int)$_POST['po_id'];
        $decision = $_POST['decision'] ?? 'approve';
        $notes   = trim($_POST['notes'] ?? '');

        $poStmt = $db->prepare("SELECT * FROM procurement_entries WHERE id = :id");
        $poStmt->execute([':id' => $poId]);
        $po = $poStmt->fetch();

        if (!$po) {
            setFlash('error', 'Requisition not found.');
            header('Location: /procurement.php');
            exit;
        }

        if (in_array($po['status'], ['Finalized', 'Rejected'], true)) {
            setFlash('warning', "Requisition {$po['reference_no']} has already been closed ({$po['status']}) and can no longer be decided on.");
            header('Location: /procurement.php?view_id=' . $poId);
            exit;
        }

        if ($decision === 'approve') {
            $stmt = $db->prepare("
                UPDATE procurement_entries
                SET status = 'Finalized',
                    admin_approved_by = :uid,
                    admin_approved_at = CURRENT_TIMESTAMP,
                    admin_notes = :notes,
                    rejection_reason = NULL
                WHERE id = :id
            ");
            $stmt->execute([
                ':uid'   => $currentUserId,
                ':notes' => $notes !== '' ? $notes : 'Approved by Procurement Officer ' . $currentUserName,
                ':id'    => $poId,
            ]);
            logAudit($db, 'REQUISITION_APPROVED', 'PROCUREMENT', $po['reference_no'], "Procurement Officer {$currentUserName} approved requisition {$po['reference_no']} (" . formatMoney((float)$po['total_cost']) . ")");
            setFlash('success', "Requisition {$po['reference_no']} approved and finalized for purchase.");
        } else {
            $reason = $notes !== '' ? $notes : 'Rejected by Procurement Officer.';
            $stmt = $db->prepare("
                UPDATE procurement_entries
                SET status = 'Rejected',
                    admin_approved_by = :uid,
                    admin_approved_at = CURRENT_TIMESTAMP,
                    rejection_reason = :reason
                WHERE id = :id
            ");
            $stmt->execute([':uid' => $currentUserId, ':reason' => $reason, ':id' => $poId]);
            logAudit($db, 'REQUISITION_REJECTED', 'PROCUREMENT', $po['reference_no'], "Procurement Officer {$currentUserName} rejected requisition {$po['reference_no']}: {$reason}");
            setFlash('warning', "Requisition {$po['reference_no']} was rejected.");
        }

        header('Location: /procurement.php?view_id=' . $poId);
        exit;
    }

    // Any other POST action is not permitted for this role
    setFlash('error', 'Your role can only view and approve requisitions. Drafting and editing are not permitted.');
    header('Location: /procurement.php');
    exit;
}

// List requisitions
$statusFilter = $_GET['filter'] ?? 'all';
$query = "
    SELECT p.*, u.name AS submitter_name
    FROM procurement_entries p
    LEFT JOIN users u ON p.submitted_by = u.id
";

if ($statusFilter === 'pending') {
    $query .= " WHERE p.status NOT IN ('Finalized', 'Rejected')";
} elseif ($statusFilter === 'finalized') {
    $query .= " WHERE p.status = 'Finalized'";
} elseif ($statusFilter === 'rejected') {
    $query .= " WHERE p.status = 'Rejected'";
}

$query .= " ORDER BY p.id DESC";
$requisitions = $db->query($query)->fetchAll();

// Detail view
$viewId = isset($_GET['view_id']) ? (int)$_GET['view_id'] : null;
$viewItem = null;
if ($viewId) {
    $vStmt = $db->prepare("
        SELECT p.*, u.name AS submitter_name
        FROM procurement_entries p
        LEFT JOIN users u ON p.submitted_by = u.id
        WHERE p.id = :id
    ");
    $vStmt->execute([':id' => $viewId]);
    $viewItem = $vStmt->fetch();
}

// Summary metrics
$totalReqCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries")->fetchColumn();
$pendingReqCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status NOT IN ('Finalized', 'Rejected')")->fetchColumn();
$finalizedReqCount = (int)$db->query("SELECT COUNT(*) FROM procurement_entries WHERE status = 'Finalized'")->fetchColumn();
$totalFinalizedValue = (float)$db->query("SELECT COALESCE(SUM(total_cost), 0) FROM procurement_entries WHERE status = 'Finalized'")->fetchColumn();

include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 class="page-title">Purchase Requisitions</h2>
            <p class="page-subtitle">Review supplier requisitions, then approve or reject them (view &amp; approve authority)</p>
        </div>
        <span class="badge badge-info" style="padding:6px 12px; font-size:12px;">&#128065; View &amp; Approve Only</span>
    </div>

    <?php displayFlash(); ?>

    <!-- Requisition KPI Metrics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Total Requisitions</span>
                <span style="font-size:18px;">&#128196;</span>
            </div>
            <div class="stat-value"><?= $totalReqCount ?></div>
            <div class="stat-desc">All-time requisition records</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Awaiting Decision</span>
                <span style="font-size:18px;">&#9203;</span>
            </div>
            <div class="stat-value" style="color:var(--warning);"><?= $pendingReqCount ?></div>
            <div class="stat-desc">Open requisitions pending review</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Approved &amp; Finalized</span>
                <span style="font-size:18px;">&#10003;</span>
            </div>
            <div class="stat-value" style="color:var(--success);"><?= $finalizedReqCount ?></div>
            <div class="stat-desc">Authorized purchase requisitions</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Approved Value</span>
                <span style="font-size:18px;">&#128178;</span>
            </div>
            <div class="stat-value" style="color:var(--primary);"><?= formatMoney($totalFinalizedValue) ?></div>
            <div class="stat-desc">Total approved spend (TZS)</div>
        </div>
    </div>

    <!-- Requisition Detail View -->
    <?php if ($viewItem): ?>
        <?php
            $canDecide = !in_array($viewItem['status'], ['Finalized', 'Rejected'], true);
        ?>
        <div class="card" style="border: 2px solid #3b82f6; background-color: #ffffff;">
            <div class="card-header">
                <div>
                    <h3 class="card-title">
                        Requisition: <span class="mono"><?= htmlspecialchars($viewItem['reference_no']) ?></span>
                        <?= getProcurementStatusBadge($viewItem['status']) ?>
                    </h3>
                    <p class="card-subtitle">Submitted on <?= formatDate($viewItem['date']) ?> by <?= htmlspecialchars($viewItem['submitter_name'] ?? 'Unknown user') ?></p>
                </div>
                <a href="/procurement.php" class="btn btn-secondary btn-sm">&times; Close Detail</a>
            </div>

            <!-- Item Details Breakdown -->
            <div class="details-list">
                <div class="detail-item">
                    <span class="detail-term">Supplier</span>
                    <span class="detail-desc"><?= htmlspecialchars($viewItem['supplier']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-term">Item Specification</span>
                    <span class="detail-desc"><?= htmlspecialchars($viewItem['item_name']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-term">Category</span>
                    <span class="detail-desc"><?= htmlspecialchars($viewItem['category']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-term">Order Quantity</span>
                    <span class="detail-desc"><?= formatNumber((float)$viewItem['quantity']) ?> <?= htmlspecialchars($viewItem['unit']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-term">Unit Price</span>
                    <span class="detail-desc"><?= formatMoney((float)$viewItem['unit_cost']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-term">Total Value</span>
                    <span class="detail-desc" style="color:var(--primary); font-size:16px;"><?= formatMoney((float)$viewItem['total_cost']) ?></span>
                </div>
            </div>

            <!-- Decision Notes History -->
            <div style="margin: 18px 0; display:flex; flex-direction:column; gap:10px;">
                <?php if ($viewItem['manager_notes']): ?>
                    <div style="padding:10px 14px; background:#eff6ff; border-left:4px solid #3b82f6; border-radius:4px;">
                        <strong>Manager Notes:</strong>
                        <p style="margin-top:2px; font-size:13px;"><?= htmlspecialchars($viewItem['manager_notes']) ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($viewItem['accountant_notes']): ?>
                    <div style="padding:10px 14px; background:#ecfdf5; border-left:4px solid #10b981; border-radius:4px;">
                        <strong>Accountant Notes:</strong>
                        <p style="margin-top:2px; font-size:13px;"><?= htmlspecialchars($viewItem['accountant_notes']) ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($viewItem['admin_notes']): ?>
                    <div style="padding:10px 14px; background:#f5f3ff; border-left:4px solid #8b5cf6; border-radius:4px;">
                        <strong>Approval Notes:</strong>
                        <p style="margin-top:2px; font-size:13px;"><?= htmlspecialchars($viewItem['admin_notes']) ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($viewItem['rejection_reason']): ?>
                    <div class="alert alert-danger" style="margin-bottom:0;">
                        <div>
                            <strong>Rejection Notice:</strong>
                            <p style="margin-top:2px;"><?= htmlspecialchars($viewItem['rejection_reason']) ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Approve / Reject Actions -->
            <?php if ($canDecide): ?>
                <div style="padding:16px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:var(--radius-md); margin-top:16px;">
                    <h4 style="font-size:14px; font-weight:700; margin-bottom:8px;">Requisition Decision (Procurement Officer Authority)</h4>
                    <form method="POST" action="/procurement.php">
                        <input type="hidden" name="action" value="officer_decision">
                        <input type="hidden" name="po_id" value="<?= (int)$viewItem['id'] ?>">
                        <div class="form-group">
                            <label for="officer_notes">Decision Notes / Remarks</label>
                            <input type="text" id="officer_notes" name="notes" placeholder="Optional remarks attached to your decision" class="form-control">
                        </div>
                        <div style="display:flex; gap:10px;">
                            <button type="submit" name="decision" value="approve" class="btn btn-success">&#10003; Approve Requisition</button>
                            <button type="submit" name="decision" value="reject" class="btn btn-danger">&#10007; Reject Requisition</button>
                        </div>
                    </form>
                </div>
            <?php elseif ($viewItem['status'] === 'Finalized'): ?>
                <div class="alert alert-success" style="margin-top:16px; margin-bottom:0;">
                    <span>&#10003; This requisition is approved and finalized. It is locked against further modification.</span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="card" style="padding:14px 20px; margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div class="btn-group">
                <a href="/procurement.php?filter=all" class="btn btn-sm <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">All (<?= count($requisitions) ?>)</a>
                <a href="/procurement.php?filter=pending" class="btn btn-sm <?= $statusFilter === 'pending' ? 'btn-primary' : 'btn-secondary' ?>">Awaiting Decision</a>
                <a href="/procurement.php?filter=finalized" class="btn btn-sm <?= $statusFilter === 'finalized' ? 'btn-primary' : 'btn-secondary' ?>">Approved</a>
                <a href="/procurement.php?filter=rejected" class="btn btn-sm <?= $statusFilter === 'rejected' ? 'btn-primary' : 'btn-secondary' ?>">Rejected</a>
            </div>
            <div style="font-size:12px; color:var(--text-subtle);">
                Displaying <strong><?= count($requisitions) ?></strong> requisitions
            </div>
        </div>
    </div>

    <!-- Requisitions Table -->
    <div class="card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Ref No.</th>
                        <th>Date</th>
                        <th>Supplier / Vendor</th>
                        <th>Item Details</th>
                        <th>Qty &amp; Unit</th>
                        <th>Total Cost (TZS)</th>
                        <th>Status</th>
                        <th>Submitted By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requisitions)): ?>
                        <tr><td colspan="9" class="empty-state">No requisitions match the selected filter.</td></tr>
                    <?php else: ?>
                        <?php foreach ($requisitions as $po): ?>
                            <tr>
                                <td class="mono"><strong><?= htmlspecialchars($po['reference_no']) ?></strong></td>
                                <td><?= formatDate($po['date']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($po['supplier']) ?></strong>
                                    <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($po['category']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($po['item_name']) ?></td>
                                <td><?= formatNumber((float)$po['quantity']) ?> <?= htmlspecialchars($po['unit']) ?></td>
                                <td><strong style="color:var(--text-main);"><?= formatMoney((float)$po['total_cost']) ?></strong></td>
                                <td><?= getProcurementStatusBadge($po['status']) ?></td>
                                <td><?= htmlspecialchars($po['submitter_name'] ?? '&mdash;') ?></td>
                                <td>
                                    <a href="/procurement.php?view_id=<?= (int)$po['id'] ?>" class="btn btn-secondary btn-sm">
                                        Inspect &rarr;
                                    </a>
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
