<?php
/**
 * U EPMS - Attachments (item 17)
 * Upload evidence (receipts, invoices, delivery notes, defect photos) onto
 * any record, and download them with role checks. Files live outside the
 * web root's executable path in uploads/ with random stored names.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();

const ALLOWED_MIME = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB

function attachmentsDir(): string
{
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

/** All attachments for one entity. */
function attachmentsFor(PDO $db, string $entityType, string|int $entityId): array
{
    try {
        $stmt = $db->prepare("
            SELECT a.*, u.name AS uploader_name
            FROM attachments a LEFT JOIN users u ON a.uploaded_by = u.id
            WHERE a.entity_type = :t AND a.entity_id = :id
            ORDER BY a.id DESC
        ");
        $stmt->execute([':t' => $entityType, ':id' => (string)$entityId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/** Render the attach form + list for an entity (drop into any page). */
function renderAttachments(PDO $db, string $entityType, string|int $entityId, bool $canUpload): void
{
    $files = attachmentsFor($db, $entityType, $entityId);
    ?>
    <div style="margin-top:10px; border-top:1px dashed var(--border-color); padding-top:10px;">
        <strong style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Evidence / attachments (<?= count($files) ?>)</strong>
        <?php foreach ($files as $f): ?>
            <div style="display:flex; gap:8px; align-items:center; margin-top:6px; font-size:12.5px;">
                <span>&#128206;</span>
                <a href="/upload.php?dl=<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['file_name']) ?></a>
                <span style="color:var(--text-subtle);">(<?= number_format((float)$f['size_bytes'] / 1024, 0) ?> KB by <?= htmlspecialchars($f['uploader_name'] ?? '?') ?>)</span>
            </div>
        <?php endforeach; ?>
        <?php if ($canUpload): ?>
            <form method="POST" action="/upload.php" enctype="multipart/form-data" style="display:flex; gap:8px; margin-top:8px; align-items:center;">
                <input type="hidden" name="upload_action" value="attach">
                <input type="hidden" name="entity_type" value="<?= htmlspecialchars($entityType) ?>">
                <input type="hidden" name="entity_id" value="<?= htmlspecialchars((string)$entityId) ?>">
                <input type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required class="form-control" style="width:auto; padding:4px 8px; font-size:12px;">
                <button type="submit" class="btn btn-secondary btn-sm">Attach</button>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

// ---- Upload ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['upload_action'] ?? '') === 'attach') {
    $entityType = preg_replace('/[^A-Z_]/i', '', (string)($_POST['entity_type'] ?? ''));
    $entityId   = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_POST['entity_id'] ?? ''));
    $backTo     = (string)($_POST['back_to'] ?? '/petty_cash.php');
    if ($entityType === '' || $entityId === '' || empty($_FILES['file']['tmp_name'])) {
        setFlash('error', 'Choose a file to attach.');
        commitSessionAndRedirect($backTo);
    }
    $info = @getimagesize($_FILES['file']['tmp_name']);
    $mime = '';
    if ($info !== false && isset($info['mime'])) {
        $mime = $info['mime'];
    } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($_FILES['file']['tmp_name']);
    }
    if (!isset(ALLOWED_MIME[$mime])) {
        setFlash('error', 'Only JPG, PNG, WebP images or PDF files are allowed.');
        commitSessionAndRedirect($backTo);
    }
    if ((int)$_FILES['file']['size'] > MAX_UPLOAD_BYTES) {
        setFlash('error', 'File too large - the limit is 5 MB.');
        commitSessionAndRedirect($backTo);
    }
    $ext = ALLOWED_MIME[$mime];
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], attachmentsDir() . '/' . $stored)) {
        setFlash('error', 'Could not store the file.');
        commitSessionAndRedirect($backTo);
    }
    $db->prepare("INSERT INTO attachments (entity_type, entity_id, file_name, stored_name, mime_type, size_bytes, uploaded_by) VALUES (:t, :i, :fn, :sn, :m, :sz, :by)")
       ->execute([
           ':t' => $entityType, ':i' => $entityId,
           ':fn' => substr((string)$_FILES['file']['name'], 0, 200),
           ':sn' => $stored, ':m' => $mime, ':sz' => (int)$_FILES['file']['size'],
           ':by' => (int)$_SESSION['user_id'],
       ]);
    logAudit($db, 'ATTACHMENT_UPLOADED', strtoupper($entityType), $entityId, $_SESSION['user_name'] . ' attached file ' . $_FILES['file']['name']);
    setFlash('success', 'File attached.');
    commitSessionAndRedirect($backTo);
}

// ---- Download (any authenticated user; files are business records) ----
if (isset($_GET['dl'])) {
    $id = (int)$_GET['dl'];
    $stmt = $db->prepare("SELECT * FROM attachments WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $att = $stmt->fetch();
    $path = $att ? attachmentsDir() . '/' . $att['stored_name'] : '';
    if (!$att || !is_file($path)) {
        http_response_code(404);
        echo 'File not found.';
        exit;
    }
    $isImage = str_starts_with((string)$att['mime_type'], 'image/');
    header('Content-Type: ' . $att['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($isImage ? 'inline' : 'attachment') . '; filename="' . addslashes($att['file_name']) . '"');
    readfile($path);
    exit;
}

commitSessionAndRedirect('/dashboard.php');
