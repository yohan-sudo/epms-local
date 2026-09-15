<?php
/**
 * U EPMS - Machinery & Plant Process Configuration
 * Admin: full write authority. System Operator: read-only inspection
 * (write authority retained ONLY over Administration data - users).
 * Pure PHP 8.2 & Plain HTML5/CSS3 (Zero Frameworks)
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole(['System Operator', 'Admin']);

$pageTitle = 'Machinery & Process Configuration';
$activeNav = 'settings';

$currentUserRole = $_SESSION['user_role'];
$currentUserId = $_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'];

// Handle POST: Add Machine or Process (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($currentUserRole !== 'Admin') {
        setFlash('error', 'System Operator has read-only access to machinery configuration. Operational modifications are restricted to Admin.');
        header('Location: /settings.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // 1. Add Machine
    if ($action === 'add_machine') {
        $code      = strtoupper(trim($_POST['code'] ?? ''));
        $name      = trim($_POST['name'] ?? '');
        $processId = (int)($_POST['process_id'] ?? 1);
        $status    = $_POST['status'] ?? 'Operational';

        if (empty($code) || empty($name)) {
            setFlash('error', 'Machine code and descriptive name are required.');
            header('Location: /settings.php');
            exit;
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
        $machineId = (int)$_POST['machine_id'];
        $newStatus = $_POST['status'];

        $stmt = $db->prepare("UPDATE machines SET status = :s WHERE id = :id");
        $stmt->execute([':s' => $newStatus, ':id' => $machineId]);

        logAudit($db, 'MACHINE_STATUS_UPDATED', 'MACHINE', $machineId, "Machine #{$machineId} status updated to {$newStatus} by {$currentUserName}");
        setFlash('success', "Machine status updated to {$newStatus}.");
        header('Location: /settings.php');
        exit;
    }

    // 3. Add Process
    if ($action === 'add_process') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if (empty($name)) {
            setFlash('error', 'Process name is required.');
            header('Location: /settings.php');
            exit;
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
                                    <?php if ($currentUserRole === 'Admin'): ?>
                                        <form method="POST" action="/settings.php" style="display:flex; gap:6px;">
                                            <input type="hidden" name="action" value="update_machine_status">
                                            <input type="hidden" name="machine_id" value="<?= (int)$m['id'] ?>">
                                            <select name="status" onchange="this.form.submit();" class="role-select" style="font-size:11px; padding:2px 6px;">
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

        <!-- Right: Register Machine Form / Operator Notice -->
        <div class="card" style="margin-bottom:0;">
            <?php if ($currentUserRole === 'Admin'): ?>
                <div class="card-header">
                    <h3 class="card-title">+ Add Equipment</h3>
                </div>

                <form method="POST" action="/settings.php">
                    <input type="hidden" name="action" value="add_machine">
                    <div class="form-group">
                        <label for="code">Asset Code *</label>
                        <input type="text" id="code" name="code" placeholder="e.g. MILL-03" required class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="name">Machine Description *</label>
                        <input type="text" id="name" name="name" placeholder="e.g. Mazak Quick Turn CNC Lathe" required class="form-control">
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
            <?php else: ?>
                <div class="card-header">
                    <h3 class="card-title">Operator Inspection Mode</h3>
                </div>
                <div class="alert alert-info" style="margin-bottom:0;">
                    <p style="font-size:13px; line-height:1.5;">
                        <strong>Operator view:</strong> you can inspect machinery and plant processes. Modifications are restricted to Admin. Your write access is limited to user accounts.
                    </p>
                </div>
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
            <?php if ($currentUserRole === 'Admin'): ?>
                <div class="card-header">
                    <h3 class="card-title">+ New Process</h3>
                </div>

                <form method="POST" action="/settings.php">
                    <input type="hidden" name="action" value="add_process">
                    <div class="form-group">
                        <label for="p_name">Process Name *</label>
                        <input type="text" id="p_name" name="name" placeholder="e.g. Ultrasonic Cleaning" required class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="p_desc">Description &amp; Specifications</label>
                        <textarea id="p_desc" name="description" rows="3" placeholder="Technical process parameters..." class="form-control"></textarea>
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
                    Production flow stages and machine tolerances are standardized by plant engineering. Process alterations are controlled under Admin approval.
                </p>
            <?php endif; ?>
        </div>
    </div></main>

<?php include __DIR__ . '/components/footer.php'; ?>
