<?php
/**
 * U EPMS - Application Header Component
 * Plain HTML5 & PHP
 */

require_once __DIR__ . '/../includes/session.php';
initAppSession();

$pageTitle = $pageTitle ?? 'Factory Operations';
$currentUserId = $_SESSION['user_id'] ?? 0;
$currentUserRole = $_SESSION['user_role'] ?? 'Guest';
$currentUserName = $_SESSION['user_name'] ?? 'Guest';

$roleBadgeClass = match($currentUserRole) {
    'CEO' => 'badge-danger',
    'Manager' => 'badge-warning',
    'Accountant' => 'badge-success',
    'Procurement Officer' => 'badge-info',
    default => 'badge-secondary',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="/assets/js/validate.js" defer></script>
    <script>
    (function() {
        const urlParams = new URLSearchParams(window.location.search);
        const sid = urlParams.get('sid') || localStorage.getItem('factory_sid');
        if (sid) {
            localStorage.setItem('factory_sid', sid);
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('a[href^="/"], a[href$=".php"]').forEach(function(a) {
                    const href = a.getAttribute('href');
                    if (href && !href.includes('sid=') && !href.startsWith('#') && !href.startsWith('javascript:') && !href.startsWith('mailto:')) {
                        const separator = href.includes('?') ? '&' : '?';
                        a.setAttribute('href', href + separator + 'sid=' + encodeURIComponent(sid));
                    }
                });
                document.querySelectorAll('form').forEach(function(f) {
                    if (!f.querySelector('input[name="sid"]')) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'sid';
                        input.value = sid;
                        f.appendChild(input);
                    }
                });
            });
        }
    })();
    </script>
</head>
<body>
<div class="app-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="app-main">
        <header class="app-header">
            <div class="header-title-wrap">
                <h1 class="header-page-title"><?= htmlspecialchars($pageTitle) ?></h1>
            </div>

            <div class="header-actions">
                <div class="header-user-chip" title="Signed-in account">
                    <span class="header-user-name"><?= htmlspecialchars($currentUserName) ?></span>
                    <span class="badge <?= $roleBadgeClass ?>"><?= htmlspecialchars($currentUserRole) ?></span>
                </div>
            </div>
        </header>
