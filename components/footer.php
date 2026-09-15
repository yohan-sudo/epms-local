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
</body>
</html>
