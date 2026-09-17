<?php
/**
 * U EPMS - Machinery & Plant Process Configuration
 * CEO (owner): full write authority.
 * Pure PHP 8.2 & Plain HTML5/CSS3 (Zero Frameworks)
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole(['CEO']);

$pageTitle = 'Machinery & Process Configuration';
$activeNav = 'settings';

$currentUserRole = $_SESSION['user_role'];
$currentUserId = $_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

// Handle POST: Add Machine or Process (CEO only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($currentUserRole !== 'CEO') {
        setFlash('error', 'Operational modifications are restricted to the CEO.');
        header('Location: /settings.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // 1. Add Machine
    if ($action === 'add_machine') {
        $allowedStatuses = ['Operational', 'Maintenance', 'Offline'];

        $errors = [];
        $code      = field_text($errors, 'code', 'Asset code', true, 2, 20) ?? '';
        $code      = strtoupper($code);
        $name      = field_text($errors, 'name', 'Machine description', true, 3, 100) ?? '';
        $processId = field_int($errors, 'process_id', 'Associated process', 1) ?? 0;
        $status    = field_choice($errors, 'status', 'Operational status', $allowedStatuses) ?? 'Operational';

        if ($errors) {
            redirectWithErrors('/settings.php', $errors);
        }

        // Check duplicate code
        $check = $db->prepare("SELECT COUNT(*) FROM machines WHERE code = :c");
        $check->execute([':c' => $code]);
        if ($check->fetchColumn() > 0) {
            setFlash('error', "Machine code '{$code}' already exists.");
            header('Location: /settings.php');
            exit;
        }

        $stmt = $db->prepare("
            INSERT INTO machines (code, name, process_id, status)
            VALUES (:c, :n, :p, :s)
        ");
        $stmt->execute([':c' => $code, ':n' => $name, ':p' => $processId, ':s' => $status]);

        logAudit($db, 'MACHINE_ADDED', 'MACHINE', $code, "Registered machine {$code} ({$name}) by {$currentUserName}");
        setFlash('success', "Machine {$code} ({$name}) successfully registered in operational registry.");
        header('Location: /settings.php');
        exit;
    }

    // 2. Update Machine Status
    if ($action === 'update_machine_status') {
        $machineId = (int)($_POST['machine_id'] ?? 0);

        $errors = [];
        $newStatus = field_choice($errors, 'status', 'Machine status', ['Operational', 'Maintenance', 'Offline']) ?? '';
        if ($machineId <= 0) {
            $errors[] = '• A valid machine must be selected.';
        }
        if ($errors) {
            redirectWithErrors('/settings.php', $errors);
        }

        $stmt = $db->prepare("UPDATE machines SET status = :s WHERE id = :id");
        $stmt->execute([':s' => $newStatus, ':id' => $machineId]);

        logAudit($db, 'MACHINE_STATUS_UPDATED', 'MACHINE', $machineId, "Machine #{$machineId} status updated to {$newStatus} by {$currentUserName}");
        setFlash('success', "Machine status updated to {$newStatus}.");
        header('Location: /settings.php');
        exit;
    }

    // 3. Add Process
    if ($action === 'add_process') {
        $errors = [];
        $name = field_text($errors, 'name', 'Process name', true, 3, 100) ?? '';
        $desc = field_text($errors, 'description', 'Process description', false, 0, 500) ?? '';

        if ($errors) {
            redirectWithErrors('/settings.php', $errors);
        }

        $stmt = $db->prepare("INSERT INTO processes (name, description, status) VALUES (:n, :d, 'Active')");
        $stmt->execute([':n' => $name, ':d' => $desc]);
        $newPid = $db->lastInsertId();

        logAudit($db, 'PROCESS_ADDED', 'PROCESS', $newPid, "Added manufacturing process '{$name}'");
        setFlash('success', "Manufacturing process '{$name}' registered.");
        header('Location: /settings.php');
        exit;
    }

    setFlash('error', 'Unknown configuration action.');
    header('Location: /settings.php');
    exit;
}

// Fetch machines & processes
$machines = $db->query("
    SELECT m.*, p.name AS process_name
    FROM machines m
    JOIN processes p ON m.process_id = p.id
    ORDER BY m.code ASC
")->fetchAll();

$processes = $db->query("SELECT * FROM processes ORDER BY id ASC")->fetchAll();

// System Diagnostics
include __DIR__ . '/components/header.php';
?>

<main class="page-container">
    <div class="page-header">
        <h2 class="page-title">Machinery &amp; Process Configuration</h2>
        <p class="page-subtitle">Equipment telemetry registry and manufacturing stage configuration</p>
    </div>

    <?php displayFlash(); ?>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; margin-bottom:24px;">
        <!-- Left: Machines Table -->
        <div class="card" style="margin-bottom:0;">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Plant Equipment &amp; Machinery</h3>
                    <p class="card-subtitle">Active floor units with real-time operational status</p>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Machine Name</th>
                            <th>Process Line</th>
                            <th>Status</th>
                            <th>Update Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($machines as $m):
                            $statusClass = match($m['status']) {
                                'Operational' => 'badge-success',
                                'Maintenance' => 'badge-warning',
                                'Offline' => 'badge-danger',
                                default => 'badge-secondary',
                            };
                        ?>
                            <tr>
                                <td class="mono"><strong><?= htmlspecialchars($m['code']) ?></strong></td>
                                <td><strong><?= htmlspecialchars($m['name']) ?></strong></td>
                                <td><?= htmlspecialchars($m['process_name']) ?></td>
                                <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($m['status']) ?></span></td>
                                <td>
                                    <?php if ($currentUserRole === 'CEO'): ?>
                                        <form method="POST" action="/settings.php" style="display:flex; gap:6px;">
                                            <input type="hidden" name="action" value="update_machine_status">
                                            <input type="hidden" name="machine_id" value="<?= (int)$m['id'] ?>">
                                            <select name="status" onchange="this.form.submit();" class="form-control" style="font-size:11px; padding:2px 6px;">
                                                <option value="Operational" <?= $m['status'] === 'Operational' ? 'selected' : '' ?>>Operational</option>
                                                <option value="Maintenance" <?= $m['status'] === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                                <option value="Offline" <?= $m['status'] === 'Offline' ? 'selected' : '' ?>>Offline</option>
                                            </select>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:var(--text-subtle);">Inspection Only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: Register Machine Form (CEO only) -->
        <div class="card" style="margin-bottom:0;">
            <?php if ($currentUserRole === 'CEO'): ?>
                <div class="card-header">
                    <h3 class="card-title">+ Add Equipment</h3>
                </div>

                <form method="POST" action="/settings.php">
                    <input type="hidden" name="action" value="add_machine">
                    <div class="form-group">
                        <label for="code">Asset Code *</label>
                        <input type="text" id="code" name="code" placeholder="e.g. MILL-03" required class="form-control"
                               minlength="2" maxlength="20" pattern="[A-Za-z0-9-]{2,20}" data-required-error="Asset code is required." data-plaintext data-pattern-error="Asset code allows only letters, numbers and hyphens (2-20 characters).">
                    </div>

                    <div class="form-group">
                        <label for="name">Machine Description *</label>
                        <input type="text" id="name" name="name" placeholder="e.g. Mazak Quick Turn CNC Lathe" required class="form-control"
                               minlength="3" maxlength="100" data-required-error="Machine description is required." data-plaintext>
                    </div>

                    <div class="form-group">
                        <label for="process_id">Associated Process *</label>
                        <select id="process_id" name="process_id" class="form-control" required>
                            <?php foreach ($processes as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status">Initial Operational Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="Operational">Operational</option>
                            <option value="Maintenance">Maintenance</option>
                            <option value="Offline">Offline</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%; margin-top:8px;">
                        Register Equipment
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Processes Grid -->
    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; margin-bottom:24px;">
        <div class="card" style="margin-bottom:0;">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Manufacturing Processes</h3>
                    <p class="card-subtitle">Active manufacturing workflow stages across the plant</p>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Process Stage</th>
                            <th>Description</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($processes as $p): ?>
                            <tr>
                                <td class="mono">#<?= $p['id'] ?></td>
                                <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                                <td style="color:var(--text-muted); font-size:13px;"><?= htmlspecialchars($p['description']) ?></td>
                                <td><span class="badge badge-success"><?= htmlspecialchars($p['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" style="margin-bottom:0;">
            <?php if ($currentUserRole === 'CEO'): ?>
                <div class="card-header">
                    <h3 class="card-title">+ New Process</h3>
                </div>

                <form method="POST" action="/settings.php">
                    <input type="hidden" name="action" value="add_process">
                    <div class="form-group">
                        <label for="p_name">Process Name *</label>
                        <input type="text" id="p_name" name="name" placeholder="e.g. Ultrasonic Cleaning" required class="form-control"
                               minlength="3" maxlength="100" data-required-error="Process name is required." data-plaintext>
                    </div>

                    <div class="form-group">
                        <label for="p_desc">Description &amp; Specifications</label>
                        <textarea id="p_desc" name="description" rows="3" placeholder="Technical process parameters..." class="form-control" maxlength="500"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%; margin-top:8px;">
                        Add Manufacturing Process
                    </button>
                </form>
            <?php else: ?>
                <div class="card-header">
                    <h3 class="card-title">Process Specifications</h3>
                </div>
                <p style="font-size:13px; color:var(--text-muted); line-height:1.5;">
                    Production flow stages and machine tolerances are standardized by plant engineering. Process alterations are controlled under CEO approval.
                </p>
            <?php endif; ?>
        </div>
    </div></main>

<?php include __DIR__ . '/components/footer.php'; ?>
