<?php
/**
 * U EPMS - Idempotent Schema Migration (v2.2)
 * Creates every table/column needed by the 2026-09 update set:
 *   - login lockout + must-change-password columns on users
 *   - sealed audit log columns (ip, user agent, prev hash, own hash)
 *   - notifications (in-app alert bell)
 *   - delegations (acting approvers)
 *   - petty_cash_requests (request -> CEO verify -> issue/pay)
 *   - expense confirmation columns + float close-out columns
 *   - machine downtime log, shift targets, material batches
 *   - stock receipts/issues (store bridge), customers, dispatches
 *   - attachments (polymorphic)
 *   - reject unit cost on processes
 *
 * Safe to run on every request: each statement is guarded by an
 * existence check and only runs when missing.
 * Invoked from includes/db.php right after uepms_migrate().
 */

function uepms_migrate_v22(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $isMysql = defined('DB_DRIVER') ? DB_DRIVER === 'mysql' : true;

    try {
        $db->query('SELECT 1 FROM users LIMIT 1');
    } catch (Exception $e) {
        return; // schema not initialized yet; init_db creates fresh shapes
    }

    /** Does a table exist? */
    $tableExists = function (string $t) use ($db, $isMysql): bool {
        try {
            if ($isMysql) {
                return (bool)$db->query('SHOW TABLES LIKE ' . $db->quote($t))->fetch();
            }
            $st = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $st->execute([$t]);
            return (bool)$st->fetch();
        } catch (Exception $e) {
            return false;
        }
    };

    /** Existing column names of a table (lowercased). */
    $colsOf = function (string $t) use ($db, $isMysql): array {
        try {
            if ($isMysql) {
                return array_map('strtolower', $db->query("SHOW COLUMNS FROM `{$t}`")->fetchAll(PDO::FETCH_COLUMN));
            }
            return array_map('strtolower', $db->query("PRAGMA table_info({$t})")->fetchAll(PDO::FETCH_COLUMN));
        } catch (Exception $e) {
            return [];
        }
    };

    /** Add a column when missing. */
    $addCol = function (string $t, string $c, string $mysqlDdl, string $sqliteDdl) use ($db, $isMysql, $colsOf): void {
        try {
            if (!in_array(strtolower($c), $colsOf($t), true)) {
                $db->exec($isMysql ? "ALTER TABLE `{$t}` ADD COLUMN `{$c}` {$mysqlDdl}" : "ALTER TABLE {$t} ADD COLUMN {$c} {$sqliteDdl}");
            }
        } catch (Exception $e) {
            error_log('[EPMS migration] ' . $t . '.' . $c . ': ' . $e->getMessage());
        }
    };

    /** Create a table when missing (MySQL + SQLite DDL variants). */
    $createTable = function (string $t, string $ddlMysql, string $ddlSqlite) use ($db, $isMysql, $tableExists): void {
        try {
            if (!$tableExists($t)) {
                $db->exec($isMysql ? $ddlMysql : $ddlSqlite);
            }
        } catch (Exception $e) {
            // ignore
        }
    };

    /* ---------------- users: lockout + forced password change ---------------- */
    $addCol('users', 'failed_attempts', 'INT UNSIGNED NOT NULL DEFAULT 0', "INTEGER NOT NULL DEFAULT 0");
    $addCol('users', 'locked_until', 'DATETIME NULL', "TEXT NULL");
    $addCol('users', 'must_change_password', 'TINYINT UNSIGNED NOT NULL DEFAULT 0', "INTEGER NOT NULL DEFAULT 0");
    $addCol('users', 'password_changed_at', 'DATETIME NULL', "TEXT NULL");

    /* ---------------- audit_logs: origin + tamper-evident chain ---------------- */
    $addCol('audit_logs', 'ip_address', "VARCHAR(45) NULL", "TEXT NULL");
    $addCol('audit_logs', 'user_agent', "VARCHAR(255) NULL", "TEXT NULL");
    $addCol('audit_logs', 'prev_hash', "CHAR(64) NULL", "TEXT NULL");
    $addCol('audit_logs', 'row_hash', "CHAR(64) NULL", "TEXT NULL");

    // The audit log must never be silently rewritten. An FK with
    // ON DELETE SET NULL on actor_id lets deleting a user MUTATE sealed
    // history (breaking hash chains). Remove any such FK on every run.
    try {
        $badFk = $db->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND DELETE_RULE = 'SET NULL'"
        )->fetchColumn();
        if ($badFk) {
            $db->exec('ALTER TABLE `audit_logs` DROP FOREIGN KEY `' . $badFk . '`');
        }
    } catch (Exception $e) {
        // SQLite or older MySQL without information_schema - nothing to fix
    }

    /* ---------------- notifications (alert bell) ---------------- */
    $createTable('notifications', "
        CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(120) NOT NULL,
            body VARCHAR(400) NOT NULL,
            link VARCHAR(160) NULL,
            is_read TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            link TEXT NULL,
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ---------------- delegations (acting approvers) ---------------- */
    $createTable('delegations', "
        CREATE TABLE IF NOT EXISTS delegations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            from_user_id INT UNSIGNED NOT NULL,
            to_user_id INT UNSIGNED NOT NULL,
            role_scope VARCHAR(40) NOT NULL,
            date_from DATE NOT NULL,
            date_to DATE NOT NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS delegations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_user_id INTEGER NOT NULL,
            to_user_id INTEGER NOT NULL,
            role_scope TEXT NOT NULL,
            date_from TEXT NOT NULL,
            date_to TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ---------------- petty cash requests (client flow) ---------------- */
    $createTable('petty_cash_requests', "
        CREATE TABLE IF NOT EXISTS petty_cash_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_no VARCHAR(30) NOT NULL UNIQUE,
            requested_by INT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            purpose VARCHAR(255) NOT NULL,
            payee VARCHAR(120) NULL,
            payment_mode VARCHAR(20) NOT NULL DEFAULT 'Cash',
            status VARCHAR(40) NOT NULL DEFAULT 'Pending CEO Verification',
            verified_by INT UNSIGNED NULL,
            verified_at DATETIME NULL,
            verification_note VARCHAR(255) NULL,
            rejection_reason VARCHAR(255) NULL,
            issuance_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS petty_cash_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_no TEXT NOT NULL UNIQUE,
            requested_by INTEGER NOT NULL,
            amount REAL NOT NULL,
            purpose TEXT NOT NULL,
            payee TEXT NULL,
            payment_mode TEXT NOT NULL DEFAULT 'Cash',
            status TEXT NOT NULL DEFAULT 'Pending CEO Verification',
            verified_by INTEGER NULL,
            verified_at TEXT NULL,
            verification_note TEXT NULL,
            rejection_reason TEXT NULL,
            issuance_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ---------------- expense confirmation + float close-out ---------------- */
    $addCol('petty_cash_expenses', 'confirmed_by', 'INT UNSIGNED NULL', "INTEGER NULL");
    $addCol('petty_cash_expenses', 'confirmed_at', 'DATETIME NULL', "TEXT NULL");
    $addCol('petty_cash_issuances', 'closed_at', 'DATETIME NULL', "TEXT NULL");
    $addCol('petty_cash_issuances', 'closed_by', 'INT UNSIGNED NULL', "INTEGER NULL");
    $addCol('petty_cash_issuances', 'close_note', 'VARCHAR(255) NULL', "TEXT NULL");
    $addCol('petty_cash_issuances', 'countersigned_by', 'INT UNSIGNED NULL', "INTEGER NULL");
    $addCol('petty_cash_issuances', 'countersigned_at', 'DATETIME NULL', "TEXT NULL");
    $addCol('petty_cash_issuances', 'batch_id', 'INT UNSIGNED NULL', "INTEGER NULL");

    /* ---------------- settings key/value (budgets etc.) ---------------- */
    $createTable('app_settings', "
        CREATE TABLE IF NOT EXISTS app_settings (
            skey VARCHAR(60) PRIMARY KEY,
            svalue VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS app_settings (
            skey TEXT PRIMARY KEY,
            svalue TEXT NOT NULL
        )
    ");

    /* ---------------- machine downtime log ---------------- */
    $createTable('machine_downtime', "
        CREATE TABLE IF NOT EXISTS machine_downtime (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            machine_id INT UNSIGNED NOT NULL,
            report_date DATE NOT NULL,
            shift VARCHAR(40) NULL,
            started_at TIME NOT NULL,
            ended_at TIME NULL,
            minutes INT UNSIGNED NOT NULL DEFAULT 0,
            reason VARCHAR(255) NOT NULL,
            recorded_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS machine_downtime (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            machine_id INTEGER NOT NULL,
            report_date TEXT NOT NULL,
            shift TEXT NULL,
            started_at TEXT NOT NULL,
            ended_at TEXT NULL,
            minutes INTEGER NOT NULL DEFAULT 0,
            reason TEXT NOT NULL,
            recorded_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ---------------- shift targets ---------------- */
    $createTable('shift_targets', "
        CREATE TABLE IF NOT EXISTS shift_targets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            process_id INT UNSIGNED NOT NULL,
            effective_from DATE NOT NULL,
            target_per_shift INT UNSIGNED NOT NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS shift_targets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            process_id INTEGER NOT NULL,
            effective_from TEXT NOT NULL,
            target_per_shift INTEGER NOT NULL,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ---------------- material batches ---------------- */
    $createTable('material_batches', "
        CREATE TABLE IF NOT EXISTS material_batches (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            batch_code VARCHAR(30) NOT NULL UNIQUE,
            procurement_id INT UNSIGNED NULL,
            material_name VARCHAR(120) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
            unit VARCHAR(20) NOT NULL DEFAULT 'Bags',
            received_date DATE NOT NULL,
            received_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS material_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_code TEXT NOT NULL UNIQUE,
            procurement_id INTEGER NULL,
            material_name TEXT NOT NULL,
            quantity REAL NOT NULL DEFAULT 0,
            unit TEXT NOT NULL DEFAULT 'Bags',
            received_date TEXT NOT NULL,
            received_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $addCol('process_reject_logs', 'batch_id', 'INT UNSIGNED NULL', "INTEGER NULL");

    /* ---------------- store bridge ---------------- */
    $createTable('stock_receipts', "
        CREATE TABLE IF NOT EXISTS stock_receipts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            batch_id INT UNSIGNED NOT NULL,
            procurement_id INT UNSIGNED NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit VARCHAR(20) NOT NULL DEFAULT 'Bags',
            receipt_date DATE NOT NULL,
            received_by INT UNSIGNED NOT NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS stock_receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id INTEGER NOT NULL,
            procurement_id INTEGER NULL,
            quantity REAL NOT NULL,
            unit TEXT NOT NULL DEFAULT 'Bags',
            receipt_date TEXT NOT NULL,
            received_by INTEGER NOT NULL,
            note TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $createTable('stock_issues', "
        CREATE TABLE IF NOT EXISTS stock_issues (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            batch_id INT UNSIGNED NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit VARCHAR(20) NOT NULL DEFAULT 'Bags',
            issue_date DATE NOT NULL,
            issued_to_process INT UNSIGNED NULL,
            issued_by INT UNSIGNED NOT NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS stock_issues (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id INTEGER NOT NULL,
            quantity REAL NOT NULL,
            unit TEXT NOT NULL DEFAULT 'Bags',
            issue_date TEXT NOT NULL,
            issued_to_process INTEGER NULL,
            issued_by INTEGER NOT NULL,
            note TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ---------------- sales & dispatch ---------------- */
    $createTable('customers', "
        CREATE TABLE IF NOT EXISTS customers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            phone VARCHAR(30) NULL,
            address VARCHAR(200) NULL,
            is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT NULL,
            address TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $createTable('dispatches', "
        CREATE TABLE IF NOT EXISTS dispatches (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dispatch_no VARCHAR(30) NOT NULL UNIQUE,
            customer_id INT UNSIGNED NOT NULL,
            bundles INT UNSIGNED NOT NULL DEFAULT 0,
            units INT UNSIGNED NOT NULL DEFAULT 0,
            unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
            dispatch_date DATE NOT NULL,
            recorded_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS dispatches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dispatch_no TEXT NOT NULL UNIQUE,
            customer_id INTEGER NOT NULL,
            bundles INTEGER NOT NULL DEFAULT 0,
            units INTEGER NOT NULL DEFAULT 0,
            unit_price REAL NOT NULL DEFAULT 0,
            total_amount REAL NOT NULL DEFAULT 0,
            amount_paid REAL NOT NULL DEFAULT 0,
            dispatch_date TEXT NOT NULL,
            recorded_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ---------------- attachments (polymorphic) ---------------- */
    $createTable('attachments', "
        CREATE TABLE IF NOT EXISTS attachments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(40) NOT NULL,
            entity_id VARCHAR(60) NOT NULL,
            file_name VARCHAR(200) NOT NULL,
            stored_name VARCHAR(120) NOT NULL,
            mime_type VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
            size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity_type TEXT NOT NULL,
            entity_id TEXT NOT NULL,
            file_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
            size_bytes INTEGER NOT NULL DEFAULT 0,
            uploaded_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ---------------- reject cost per process ---------------- */
    $addCol('processes', 'reject_cost_per_unit', 'DECIMAL(12,2) NOT NULL DEFAULT 0', "REAL NOT NULL DEFAULT 0");

    uepms_migrate_v23($db, $createTable, $addCol);
}

/**
 * v2.3 migration set (2026-09, role/workflow restructure):
 *   - Supervisor role + dedicated seed account
 *   - inventory_items / inventory_transactions (PO full control, CEO view)
 *   - procurement requisitions to CEO + inventory intake link
 *   - inventory material requests (Supervisor -> Manager -> PO release/receive)
 *   - cash_requests (PO/Manager -> CEO -> Accountant disburse -> receiver confirm)
 *   - shipment_orders (CEO request -> PO prepare -> Manager approve -> dispatch)
 *   - machine failure reports (Supervisor -> Manager verifies)
 *   - electricity readings (Supervisor logs)
 *   - production_reports approval gate (Supervisor logs, Manager approves)
 *   - correction requests (log-a-mistake -> CEO approves scoped change)
 *   - workers + attendance (biometric-ready framework)
 */
function uepms_migrate_v23(PDO $db, callable $createTable, callable $addCol): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    /* ---------------- Supervisor seed account ---------------- */
    try {
        $has = $db->query("SELECT COUNT(*) FROM users WHERE username = 'supervisor_one'")->fetchColumn();
        if ((int)$has === 0) {
            $hash = password_hash('factory123', PASSWORD_BCRYPT);
            $blob = function_exists('encryptPassword') ? encryptPassword('factory123') : null;
            $stmt = $db->prepare("INSERT INTO users (name, username, password_hash, password_encrypted, role, status, must_change_password, created_at)
                                  VALUES ('SUPERVISOR ONE', 'supervisor_one', :h, :b, 'Supervisor', 'Active', 1, CURRENT_TIMESTAMP)");
            $stmt->execute([':h' => $hash, ':b' => $blob]);
        }
    } catch (Exception $e) {
        error_log('[EPMS v2.3] supervisor seed: ' . $e->getMessage());
    }

    /* ---------------- inventory (PO full control; CEO view-only) ---------------- */
    $createTable('inventory_items', "
        CREATE TABLE IF NOT EXISTS inventory_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_code VARCHAR(30) NOT NULL UNIQUE,
            item_name VARCHAR(150) NOT NULL,
            unit VARCHAR(20) NOT NULL DEFAULT 'piece',
            quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
            reorder_level DECIMAL(14,2) NOT NULL DEFAULT 0,
            unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            is_finished_goods TINYINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS inventory_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_code TEXT NOT NULL UNIQUE,
            item_name TEXT NOT NULL,
            unit TEXT NOT NULL DEFAULT 'piece',
            quantity REAL NOT NULL DEFAULT 0,
            reorder_level REAL NOT NULL DEFAULT 0,
            unit_cost REAL NOT NULL DEFAULT 0,
            is_finished_goods INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'Active',
            created_by INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $createTable('inventory_transactions', "
        CREATE TABLE IF NOT EXISTS inventory_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_id INT UNSIGNED NOT NULL,
            txn_type VARCHAR(20) NOT NULL,
            quantity DECIMAL(14,2) NOT NULL,
            reference VARCHAR(120) NULL,
            note VARCHAR(255) NULL,
            performed_by INT UNSIGNED NOT NULL,
            txn_date DATE NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS inventory_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            txn_type TEXT NOT NULL,
            quantity REAL NOT NULL,
            reference TEXT NULL,
            note TEXT NULL,
            performed_by INTEGER NOT NULL,
            txn_date TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ---------------- procurement -> CEO requisition + inventory intake ---------------- */
    $addCol('procurement_entries', 'requisition_status', "VARCHAR(30) NULL", "TEXT NULL");
    $addCol('procurement_entries', 'requisition_ceo_id', "INT UNSIGNED NULL", "INTEGER NULL");
    $addCol('procurement_entries', 'requisition_ceo_at', "DATETIME NULL", "TEXT NULL");
    $addCol('procurement_entries', 'requisition_notes', "VARCHAR(255) NULL", "TEXT NULL");
    $addCol('procurement_entries', 'inventory_received', "TINYINT UNSIGNED NOT NULL DEFAULT 0", "INTEGER NOT NULL DEFAULT 0");

    /* ---------------- Supervisor material requests from inventory ---------------- */
    $createTable('inventory_requests', "
        CREATE TABLE IF NOT EXISTS inventory_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_no VARCHAR(30) NOT NULL UNIQUE,
            item_id INT UNSIGNED NOT NULL,
            quantity DECIMAL(14,2) NOT NULL,
            purpose VARCHAR(255) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Pending Manager Approval',
            requested_by INT UNSIGNED NOT NULL,
            manager_decision_by INT UNSIGNED NULL,
            manager_decision_at DATETIME NULL,
            manager_notes VARCHAR(255) NULL,
            released_by INT UNSIGNED NULL,
            released_at DATETIME NULL,
            received_confirmed_by INT UNSIGNED NULL,
            received_confirmed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS inventory_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_no TEXT NOT NULL UNIQUE,
            item_id INTEGER NOT NULL,
            quantity REAL NOT NULL,
            purpose TEXT NULL,
            status TEXT NOT NULL DEFAULT 'Pending Manager Approval',
            requested_by INTEGER NOT NULL,
            manager_decision_by INTEGER NULL,
            manager_decision_at TEXT NULL,
            manager_notes TEXT NULL,
            released_by INTEGER NULL,
            released_at TEXT NULL,
            received_confirmed_by INTEGER NULL,
            received_confirmed_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ---------------- cash requests (PO/Manager -> CEO -> Accountant) ---------------- */
    $createTable('cash_requests', "
        CREATE TABLE IF NOT EXISTS cash_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_no VARCHAR(30) NOT NULL UNIQUE,
            requested_by INT UNSIGNED NOT NULL,
            requester_role VARCHAR(40) NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            purpose VARCHAR(255) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Pending CEO Approval',
            ceo_decision_by INT UNSIGNED NULL,
            ceo_decision_at DATETIME NULL,
            ceo_notes VARCHAR(255) NULL,
            disbursed_by INT UNSIGNED NULL,
            disbursed_at DATETIME NULL,
            confirmed_received_by INT UNSIGNED NULL,
            confirmed_received_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS cash_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_no TEXT NOT NULL UNIQUE,
            requested_by INTEGER NOT NULL,
            requester_role TEXT NOT NULL,
            amount REAL NOT NULL,
            purpose TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'Pending CEO Approval',
            ceo_decision_by INTEGER NULL,
            ceo_decision_at TEXT NULL,
            ceo_notes TEXT NULL,
            disbursed_by INTEGER NULL,
            disbursed_at TEXT NULL,
            confirmed_received_by INTEGER NULL,
            confirmed_received_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ---------------- shipment orders (CEO request -> PO prepare -> Manager approve) ---------------- */
    $createTable('shipment_orders', "
        CREATE TABLE IF NOT EXISTS shipment_orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shipment_no VARCHAR(30) NOT NULL UNIQUE,
            destination VARCHAR(150) NOT NULL,
            product_item_id INT UNSIGNED NULL,
            bundles INT UNSIGNED NOT NULL DEFAULT 0,
            units INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'Requested by CEO',
            requested_by_ceo INT UNSIGNED NOT NULL,
            prepared_by_po INT UNSIGNED NULL,
            prepared_at DATETIME NULL,
            po_notes VARCHAR(255) NULL,
            approved_by_manager INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            manager_notes VARCHAR(255) NULL,
            dispatched_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS shipment_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            shipment_no TEXT NOT NULL UNIQUE,
            destination TEXT NOT NULL,
            product_item_id INTEGER NULL,
            bundles INTEGER NOT NULL DEFAULT 0,
            units INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'Requested by CEO',
            requested_by_ceo INTEGER NOT NULL,
            prepared_by_po INTEGER NULL,
            prepared_at TEXT NULL,
            po_notes TEXT NULL,
            approved_by_manager INTEGER NULL,
            approved_at TEXT NULL,
            manager_notes TEXT NULL,
            dispatched_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ---------------- machine failure reports (Supervisor -> Manager verifies) ---------------- */
    $createTable('machine_failures', "
        CREATE TABLE IF NOT EXISTS machine_failures (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            machine_id INT UNSIGNED NOT NULL,
            reported_by INT UNSIGNED NOT NULL,
            failure_reason VARCHAR(255) NOT NULL,
            reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(30) NOT NULL DEFAULT 'Pending Verification',
            verified_by INT UNSIGNED NULL,
            verified_at DATETIME NULL,
            manager_notes VARCHAR(255) NULL,
            resolved_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS machine_failures (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            machine_id INTEGER NOT NULL,
            reported_by INTEGER NOT NULL,
            failure_reason TEXT NOT NULL,
            reported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status TEXT NOT NULL DEFAULT 'Pending Verification',
            verified_by INTEGER NULL,
            verified_at TEXT NULL,
            manager_notes TEXT NULL,
            resolved_at TEXT NULL
        )
    ");

    /* ---------------- electricity readings (Supervisor logs) ---------------- */
    $createTable('electricity_readings', "
        CREATE TABLE IF NOT EXISTS electricity_readings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reading_date DATE NOT NULL,
            shift VARCHAR(40) NOT NULL DEFAULT 'Morning (06:00 - 14:00)',
            meter_kwh DECIMAL(12,2) NOT NULL DEFAULT 0,
            units_produced DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes VARCHAR(255) NULL,
            logged_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS electricity_readings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reading_date TEXT NOT NULL,
            shift TEXT NOT NULL DEFAULT 'Morning (06:00 - 14:00)',
            meter_kwh REAL NOT NULL DEFAULT 0,
            units_produced REAL NOT NULL DEFAULT 0,
            notes TEXT NULL,
            logged_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ---------------- production approval gate (Supervisor logs, Manager verifies) ---------------- */
    $addCol('daily_reports', 'approval_status', "VARCHAR(30) NOT NULL DEFAULT 'Pending Manager Approval'", "TEXT NOT NULL DEFAULT 'Pending Manager Approval'");
    $addCol('daily_reports', 'approved_by', "INT UNSIGNED NULL", "INTEGER NULL");
    $addCol('daily_reports', 'approved_at', "DATETIME NULL", "TEXT NULL");
    $addCol('daily_reports', 'approval_notes', "VARCHAR(255) NULL", "TEXT NULL");

    /* ---------------- correction workflow (user asks, CEO approves scoped change) ---------------- */
    $createTable('correction_requests', "
        CREATE TABLE IF NOT EXISTS correction_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(60) NOT NULL,
            entity_id VARCHAR(60) NOT NULL,
            correction_type VARCHAR(20) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            requested_by INT UNSIGNED NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Pending CEO Approval',
            decided_by INT UNSIGNED NULL,
            decided_at DATETIME NULL,
            ceo_notes VARCHAR(255) NULL,
            applied_by INT UNSIGNED NULL,
            applied_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS correction_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity_type TEXT NOT NULL,
            entity_id TEXT NOT NULL,
            correction_type TEXT NOT NULL,
            reason TEXT NOT NULL,
            requested_by INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'Pending CEO Approval',
            decided_by INTEGER NULL,
            decided_at TEXT NULL,
            ceo_notes TEXT NULL,
            applied_by INTEGER NULL,
            applied_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ---------------- workers + attendance (biometric-ready, LAST on client list) ---------------- */
    $createTable('workers', "
        CREATE TABLE IF NOT EXISTS workers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(120) NOT NULL,
            staff_no VARCHAR(30) NOT NULL UNIQUE,
            department VARCHAR(60) NULL,
            fingerprint_template TEXT NULL,
            face_template TEXT NULL,
            biometric_enrolled TINYINT UNSIGNED NOT NULL DEFAULT 0,
            enrolled_by INT UNSIGNED NULL,
            enrolled_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS workers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            staff_no TEXT NOT NULL UNIQUE,
            department TEXT NULL,
            fingerprint_template TEXT NULL,
            face_template TEXT NULL,
            biometric_enrolled INTEGER NOT NULL DEFAULT 0,
            enrolled_by INTEGER NULL,
            enrolled_at TEXT NULL,
            status TEXT NOT NULL DEFAULT 'Active',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $createTable('worker_attendance', "
        CREATE TABLE IF NOT EXISTS worker_attendance (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            worker_id INT UNSIGNED NOT NULL,
            attend_date DATE NOT NULL,
            check_in DATETIME NULL,
            check_out DATETIME NULL,
            method VARCHAR(20) NOT NULL DEFAULT 'biometric',
            device_info VARCHAR(120) NULL,
            UNIQUE KEY uq_attend (worker_id, attend_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS worker_attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            attend_date TEXT NOT NULL,
            check_in TEXT NULL,
            check_out TEXT NULL,
            method TEXT NOT NULL DEFAULT 'biometric',
            device_info TEXT NULL,
            UNIQUE (worker_id, attend_date)
        )
    ");

    // MySQL: enforce the attendance uniqueness guard as a real index.
    try {
        if (defined('DB_DRIVER') && DB_DRIVER === 'mysql') {
            $idx = $db->query("SHOW INDEX FROM worker_attendance WHERE Key_name = 'uq_attend'")->fetch();
            if (!$idx) {
                $db->exec('ALTER TABLE worker_attendance ADD UNIQUE KEY uq_attend (worker_id, attend_date)');
            }
        }
    } catch (Exception $e) {
        error_log('[EPMS v2.3] attendance index: ' . $e->getMessage());
    }

    // v2.3.4: WebAuthn credentials for worker biometric enrolment.
    // One row per enrolled authenticator (platform fingerprint/face sensor or
    // security key). credential_id + public key are what the server verifies
    // at every check-in; sign_count detects cloned authenticators.
    $createTable('webauthn_credentials', "
        CREATE TABLE IF NOT EXISTS webauthn_credentials (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            worker_id INT UNSIGNED NOT NULL,
            credential_id VARBINARY(512) NOT NULL,
            public_key TEXT NOT NULL,
            format VARCHAR(30) NOT NULL DEFAULT 'none',
            aaguid VARCHAR(60) NULL,
            sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            enrolled_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_webauthn_cred (credential_id(255)),
            KEY idx_webauthn_worker (worker_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "
        CREATE TABLE IF NOT EXISTS webauthn_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            credential_id BLOB NOT NULL,
            public_key TEXT NOT NULL,
            format TEXT NOT NULL DEFAULT 'none',
            aaguid TEXT NULL,
            sign_count INTEGER NOT NULL DEFAULT 0,
            enrolled_by INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (credential_id)
        )
    ");

    // v2.3.4 repair: convert the public key column to TEXT if an earlier
    // run created it as VARBINARY (the library stores a PEM string).
    if (defined('DB_DRIVER') && DB_DRIVER === 'mysql') {
        try {
            $col = $db->query("SHOW COLUMNS FROM webauthn_credentials WHERE Field = 'public_key'")->fetch();
            if ($col && stripos((string)$col['Type'], 'varbinary') !== false) {
                $db->exec('ALTER TABLE webauthn_credentials MODIFY public_key TEXT NOT NULL');
            }
        } catch (Exception $e) {
            error_log('[EPMS v2.3.4] public_key type repair: ' . $e->getMessage());
        }
    }

    // v2.3.1: widen status columns that outgrew VARCHAR(30) - long lifecycle
    // labels like 'Approved - Awaiting Application' were silently truncated.
    if (defined('DB_DRIVER') && DB_DRIVER === 'mysql') {
        foreach ([
            ['correction_requests', "VARCHAR(60) NOT NULL DEFAULT 'Pending CEO Approval'"],
            ['inventory_requests', "VARCHAR(60) NOT NULL DEFAULT 'Pending Manager Approval'"],
        ] as [$st, $ddl]) {
            try {
                $db->exec("ALTER TABLE `{$st}` MODIFY `status` {$ddl}");
            } catch (Exception $e) {
                error_log('[EPMS v2.3.1] widen ' . $st . '.status: ' . $e->getMessage());
            }
        }
    }
}
