<?php
/**
 * U EPMS - Application Footer Component
 * Plain HTML5 & PHP
 */
?>
        <footer style="padding: 20px 28px; border-top: 1px solid var(--border-color); background: var(--bg-surface); font-size: 12px; color: var(--text-subtle); display:flex; justify-content:space-between; align-items:center; margin-top:auto;">
            <div>
                <strong><?= htmlspecialchars(APP_NAME) ?></strong> &bull; Production, Procurement &amp; Cash Flow Management
            </div>
            <div>
                <span>Currency: <?= APP_CURRENCY ?> (Tanzanian Shillings) &bull; <?= htmlspecialchars(APP_TIMEZONE) ?></span>
            </div>
        </footer>
    </div> <!-- /.app-main -->
</div> <!-- /.app-layout -->
<?php
/* v2.3: floating assistant (role-scoped answers) */
if (!empty($currentUserId)) {
    include __DIR__ . '/chatbot.php';
}
?>
<script>
/* v2.3: notification panel dropdown in the header */
(function () {
    var btn = document.getElementById('notif-bell-btn');
    var panel = document.getElementById('notif-panel');
    if (!btn || !panel) return;
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = panel.style.display !== 'none';
        panel.style.display = open ? 'none' : 'block';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    });
    document.addEventListener('click', function (e) {
        if (panel.style.display !== 'none' && !panel.contains(e.target) && e.target !== btn) {
            panel.style.display = 'none';
            btn.setAttribute('aria-expanded', 'false');
        }
    });
})();
</script>
</body>
</html>
