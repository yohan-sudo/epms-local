<?php
/**
 * U EPMS - Database Initializer & Seeder
 * Works with MySQL (localhost / XAMPP) and SQLite (fallback).
 * All money columns are stored as Tanzanian Shillings (TZS).
 */

function initializeDatabase(PDO $db): void {
    $isMysql = (defined('DB_DRIVER') && DB_DRIVER === 'mysql');

    // ------------------------------------------------------------------
    // Schema
    // ------------------------------------------------------------------
    if ($isMysql) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                username VARCHAR(60) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                password_encrypted VARBINARY(512) NULL,
                role VARCHAR(40) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS processes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                description TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Active'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS machines (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(30) NOT NULL UNIQUE,
                name VARCHAR(150) NOT NULL,
                process_id INT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Operational',
                CONSTRAINT fk_machines_process FOREIGN KEY (process_id) REFERENCES processes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS procurement_entries (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                reference_no VARCHAR(30) NOT NULL UNIQUE,
                submitted_by INT UNSIGNED NULL,
                supplier VARCHAR(150) NOT NULL,
                item_name VARCHAR(255) NOT NULL,
                category VARCHAR(60) NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
                unit VARCHAR(30) NOT NULL,
                unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
                total_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
                status VARCHAR(40) NOT NULL DEFAULT 'Pending Approval',
                manager_approved_by INT UNSIGNED NULL,
                manager_approved_at DATETIME NULL,
                manager_notes TEXT NULL,
                accountant_approved_by INT UNSIGNED NULL,
                accountant_approved_at DATETIME NULL,
                accountant_notes TEXT NULL,
                admin_approved_by INT UNSIGNED NULL,
                admin_approved_at DATETIME NULL,
                admin_notes TEXT NULL,
                rejection_reason TEXT NULL,
                date DATE NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_proc_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_proc_manager FOREIGN KEY (manager_approved_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_proc_accountant FOREIGN KEY (accountant_approved_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_proc_admin FOREIGN KEY (admin_approved_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS daily_reports (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                report_date DATE NOT NULL,
                shift VARCHAR(60) NOT NULL,
                supervisor_id INT UNSIGNED NULL,
                machine_id INT UNSIGNED NULL,
                units_produced INT UNSIGNED NOT NULL DEFAULT 0,
                good_units INT UNSIGNED NOT NULL DEFAULT 0,
                supervisor_notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_reports_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_reports_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS process_reject_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                report_id INT UNSIGNED NOT NULL,
                process_id INT UNSIGNED NULL,
                partial_reject_count INT UNSIGNED NOT NULL DEFAULT 0,
                total_reject_count INT UNSIGNED NOT NULL DEFAULT 0,
                reject_reason VARCHAR(255) NOT NULL,
                root_cause VARCHAR(255) NULL,
                CONSTRAINT fk_rejects_report FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE CASCADE,
                CONSTRAINT fk_rejects_process FOREIGN KEY (process_id) REFERENCES processes(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS petty_cash_issuances (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                voucher_no VARCHAR(30) NOT NULL UNIQUE,
                issued_to INT UNSIGNED NULL,
                issued_by INT UNSIGNED NULL,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                purpose VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Active',
                issued_date DATE NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_issuance_to FOREIGN KEY (issued_to) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_issuance_by FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS petty_cash_expenses (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                issuance_id INT UNSIGNED NOT NULL,
                expense_date DATE NOT NULL,
                category VARCHAR(60) NOT NULL,
                description VARCHAR(255) NOT NULL,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                receipt_no VARCHAR(60) NOT NULL,
                approved_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_expense_issuance FOREIGN KEY (issuance_id) REFERENCES petty_cash_issuances(id) ON DELETE CASCADE,
                CONSTRAINT fk_expense_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS audit_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                actor_id INT UNSIGNED NULL,
                action VARCHAR(60) NOT NULL,
                entity_type VARCHAR(40) NOT NULL,
                entity_id VARCHAR(60) NOT NULL,
                details TEXT NOT NULL,
                timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        $db->exec("PRAGMA foreign_keys = ON;");
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                password_encrypted TEXT NULL,
                role TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'Active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS processes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                status TEXT NOT NULL DEFAULT 'Active'
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS machines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                process_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'Operational',
                FOREIGN KEY (process_id) REFERENCES processes(id) ON DELETE CASCADE
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS procurement_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reference_no TEXT UNIQUE NOT NULL,
                submitted_by INTEGER,
                supplier TEXT NOT NULL,
                item_name TEXT NOT NULL,
                category TEXT NOT NULL,
                quantity REAL NOT NULL DEFAULT 0,
                unit TEXT NOT NULL,
                unit_cost REAL NOT NULL DEFAULT 0,
                total_cost REAL NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'Pending Approval',
                manager_approved_by INTEGER,
                manager_approved_at DATETIME,
                manager_notes TEXT,
                accountant_approved_by INTEGER,
                accountant_approved_at DATETIME,
                accountant_notes TEXT,
                admin_approved_by INTEGER,
                admin_approved_at DATETIME,
                admin_notes TEXT,
                rejection_reason TEXT,
                date DATE NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (manager_approved_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (accountant_approved_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (admin_approved_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS daily_reports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                report_date DATE NOT NULL,
                shift TEXT NOT NULL,
                supervisor_id INTEGER,
                machine_id INTEGER,
                units_produced INTEGER NOT NULL DEFAULT 0,
                good_units INTEGER NOT NULL DEFAULT 0,
                supervisor_notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE SET NULL
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS process_reject_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                report_id INTEGER NOT NULL,
                process_id INTEGER,
                partial_reject_count INTEGER NOT NULL DEFAULT 0,
                total_reject_count INTEGER NOT NULL DEFAULT 0,
                reject_reason TEXT NOT NULL,
                root_cause TEXT,
                FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE CASCADE,
                FOREIGN KEY (process_id) REFERENCES processes(id) ON DELETE SET NULL
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS petty_cash_issuances (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                voucher_no TEXT UNIQUE NOT NULL,
                issued_to INTEGER,
                issued_by INTEGER,
                amount REAL NOT NULL DEFAULT 0,
                purpose TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'Active',
                issued_date DATE NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (issued_to) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS petty_cash_expenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                issuance_id INTEGER NOT NULL,
                expense_date DATE NOT NULL,
                category TEXT NOT NULL,
                description TEXT NOT NULL,
                amount REAL NOT NULL DEFAULT 0,
                receipt_no TEXT NOT NULL,
                approved_by INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (issuance_id) REFERENCES petty_cash_issuances(id) ON DELETE CASCADE,
                FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_id INTEGER,
                action TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id TEXT NOT NULL,
                details TEXT NOT NULL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    // ------------------------------------------------------------------
    // Seed data (only when empty)
    // ------------------------------------------------------------------
    $userCount = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount > 0) {
        return;
    }

    $passwordHash = password_hash('factory123', PASSWORD_BCRYPT);
    $seedBlob = function_exists('encryptPassword') ? encryptPassword('factory123') : null;

    // Roles: CEO (owner), Manager, Accountant, Procurement Officer.
    // Procurement Officer SUBMITS procurement records; Manager approves them
    // (first approval); Accountant gives the FINAL approval.
    $userStmt = $db->prepare('INSERT INTO users (name, username, password_hash, password_encrypted, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $userStmt->execute(['BRIGHTON MMARI', 'brighton_mmari', $passwordHash, $seedBlob, 'CEO', 'Active', '2026-01-10 09:00:00']);
    $userStmt->execute(['GLORY GEORGE', 'glory_george', $passwordHash, $seedBlob, 'Manager', 'Active', '2026-01-15 10:15:00']);
    $userStmt->execute(['SWAUMU MKOMWA', 'swaumu_mkomwa', $passwordHash, $seedBlob, 'Accountant', 'Active', '2026-01-18 11:45:00']);
    $userStmt->execute(['GLORIA MGASSA', 'gloria_mgassa', $passwordHash, $seedBlob, 'Procurement Officer', 'Active', '2026-02-01 14:20:00']);
    $userStmt->execute(['VICTOR DIAZ', 'victor_diaz', $passwordHash, $seedBlob, 'Procurement Officer', 'Banned', '2026-03-01 16:00:00']);

    // Production pipeline, first stage to last (broom stick plant):
    //   1. Rounding (machines R1, R2, ...)
    //   2. Sanding (machines S1, S2, ...)
    //   3. P.V.C - K line (machines K1, ...)
    //   4. P.V.C - O line (machines O1, ...)
    //   5. Cups (done by hand - finished broom sticks are counted here)
    //   6. Packaging / Sewing (machine or hand - products bundled here)
    $procStmt = $db->prepare('INSERT INTO processes (id, name, description, status) VALUES (?, ?, ?, ?)');
    $procStmt->execute([1, 'Rounding', 'First stage: sticks are rounded on R-series rounding machines (R1, R2, ...)', 'Active']);
    $procStmt->execute([2, 'Sanding', 'Second stage: surface smoothing on S-series sanding machines (S1, S2, ...)', 'Active']);
    $procStmt->execute([3, 'P.V.C (K Line)', 'P.V.C stage run on K-series machines (K1, ...)', 'Active']);
    $procStmt->execute([4, 'P.V.C (O Line)', 'P.V.C stage run on O-series machines (O1, ...)', 'Active']);
    $procStmt->execute([5, 'Cups', 'Done by hand. This is where the finished products (broom sticks) are counted.', 'Active']);
    $procStmt->execute([6, 'Packaging / Sewing', 'Done by machine or hand - products are packaged into bundles at this point.', 'Active']);

    $machStmt = $db->prepare('INSERT INTO machines (id, code, name, process_id, status) VALUES (?, ?, ?, ?, ?)');
    $machStmt->execute([1, 'R1', 'Rounding Machine R1', 1, 'Operational']);
    $machStmt->execute([2, 'R2', 'Rounding Machine R2', 1, 'Operational']);
    $machStmt->execute([3, 'S1', 'Sanding Machine S1', 2, 'Operational']);
    $machStmt->execute([4, 'S2', 'Sanding Machine S2', 2, 'Maintenance']);
    $machStmt->execute([5, 'K1', 'P.V.C K-Line Machine K1', 3, 'Operational']);
    $machStmt->execute([6, 'O1', 'P.V.C O-Line Machine O1', 4, 'Operational']);
    $machStmt->execute([7, 'HAND-01', 'Cups Station (Manual Hand Work)', 5, 'Operational']);
    $machStmt->execute([8, 'SEW-01', 'Packaging & Sewing Machine', 6, 'Operational']);

    // Procurement records in TZS (Tanzanian Shillings).
    // Lifecycle: Officer submits -> Manager approves -> Accountant finalizes.
    $poStmt = $db->prepare("
        INSERT INTO procurement_entries (
            reference_no, submitted_by, supplier, item_name, category,
            quantity, unit, unit_cost, total_cost, status,
            manager_approved_by, manager_approved_at, manager_notes,
            accountant_approved_by, accountant_approved_at, accountant_notes,
            admin_approved_by, admin_approved_at, admin_notes,
            rejection_reason, date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $poStmt->execute([
        'PRC-2026-001', 4, 'Apex Bristle & Plastic Ltd.', 'P.V.C Bristle Granules (Grade A)', 'Raw Material',
        15.5, 'Tons', 3150000.00, 48825000.00, 'Finalized',
        2, '2026-03-02 11:15:00', 'Material specifications verified against Q2 production plan.',
        3, '2026-03-03 09:30:00', 'Final approval: budget confirmed under Capital Expenditures.',
        null, null, null,
        null, '2026-03-01',
    ]);
    $poStmt->execute([
        'PRC-2026-002', 4, 'Vanguard Packaging Corp', 'Sewing Twine & Bundle Wire (Pack of 50)', 'Tooling',
        8, 'Sets', 1060000.00, 8480000.00, 'Pending Accountant Review',
        2, '2026-03-08 14:00:00', 'Essential for the packaging/sewing stage continuity.',
        null, null, null,
        null, null, null,
        null, '2026-03-07',
    ]);
    $poStmt->execute([
        'PRC-2026-003', 4, 'Total Lubricants & Hydraulics', 'ISO VG 46 Hydraulic Oil (200L Drum)', 'Consumables',
        6, 'Drums', 960000.00, 5760000.00, 'Pending Manager Review',
        null, null, null,
        null, null, null,
        null, null, null,
        null, '2026-03-12',
    ]);
    $poStmt->execute([
        'PRC-2026-004', 4, 'ElectroCoat Systems', 'P.V.C Coating Compound - Safety Yellow', 'Raw Material',
        500, 'kg', 21500.00, 10750000.00, 'Pending Manager Review',
        null, null, null,
        null, null, null,
        null, null, null,
        null, '2026-03-14',
    ]);

    $repStmt = $db->prepare('
        INSERT INTO daily_reports (id, report_date, shift, supervisor_id, machine_id, units_produced, good_units, supervisor_notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $repStmt->execute([1, '2026-03-13', 'Morning (06:00 - 14:00)', 2, 1, 1450, 1390, 'Rounding stage run on R1. Stick ends rounded cleanly; die wear monitored.', '2026-03-13 14:15:00']);
    $repStmt->execute([2, '2026-03-13', 'Afternoon (14:00 - 22:00)', 2, 3, 1380, 1341, 'Sanding stage on S1 finished ahead of schedule. Abrasive belt replaced at mid-shift.', '2026-03-13 22:10:00']);
    $repStmt->execute([3, '2026-03-14', 'Morning (06:00 - 14:00)', 2, 7, 520, 508, 'Cups stage (by hand): finished broom sticks counted. Minor rework on 8 sticks.', '2026-03-14 14:05:00']);

    $rejStmt = $db->prepare('INSERT INTO process_reject_logs (report_id, process_id, partial_reject_count, total_reject_count, reject_reason, root_cause) VALUES (?, ?, ?, ?, ?, ?)');
    $rejStmt->execute([1, 1, 45, 15, 'Uneven rounding & end splitting', 'Worn rounding cutter on R1 feed head']);
    $rejStmt->execute([2, 2, 12, 6, 'Rough surface patches after sanding', 'Glazed abrasive belt; replaced mid-shift']);
    $rejStmt->execute([3, 5, 8, 4, 'Broom stick count mismatch at cups stage', 'Hand-counting slip between cups stations']);

    // Petty cash in TZS
    $issStmt = $db->prepare('INSERT INTO petty_cash_issuances (id, voucher_no, issued_to, issued_by, amount, purpose, status, issued_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $issStmt->execute([1, 'PCV-2026-001', 2, 1, 1500000.00, 'Shift operations emergency parts & local maintenance float', 'Active', '2026-03-01']);
    $issStmt->execute([2, 'PCV-2026-002', 4, 1, 800000.00, 'Courier logistics, urgent supplies, and sample freight', 'Active', '2026-03-05']);

    $expStmt = $db->prepare('INSERT INTO petty_cash_expenses (issuance_id, expense_date, category, description, amount, receipt_no, approved_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $expStmt->execute([1, '2026-03-03', 'Hardware & Fasteners', 'Emergency M8 Grade 8.8 Hex Bolts & Spring Washers', 355000.00, 'REC-44912', 4]);
    $expStmt->execute([1, '2026-03-07', 'Shop Consumables', 'Industrial Degreaser Solvent & Heavy Duty Shop Towels', 215500.00, 'REC-45019', 4]);
    $expStmt->execute([1, '2026-03-11', 'Equipment Maintenance', 'Replacement hydraulic solenoid fuse and terminal blocks', 287500.00, 'REC-45188', 4]);
    $expStmt->execute([2, '2026-03-06', 'Logistics & Freight', 'Same-day courier dispatch for metallurgical sample testing', 360000.00, 'DHL-88910', 4]);

    $auditStmt = $db->prepare('INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details, timestamp) VALUES (?, ?, ?, ?, ?, ?)');
    $auditStmt->execute([1, 'SYSTEM_BOOTSTRAP', 'DATABASE', 'SCHEMA', 'Database schema initialized and baseline broom stick plant records seeded (currency: TZS).', '2026-03-01 08:00:00']);
    $auditStmt->execute([3, 'PROCUREMENT_FINALIZED', 'PROCUREMENT', 'PRC-2026-001', 'Accountant SWAUMU MKOMWA gave final approval to procurement record PRC-2026-001 (TZS 48,825,000.00).', '2026-03-03 09:30:00']);
    $auditStmt->execute([1, 'PETTY_CASH_ISSUED', 'PETTY_CASH', 'PCV-2026-001', 'CEO BRIGHTON MMARI issued TZS 1,500,000.00 petty cash float to GLORY GEORGE.', '2026-03-01 09:15:00']);
}

// CLI entry point: `php init_db.php`
if (php_sapi_name() === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/includes/db.php';
    echo 'Database initialized and seeded successfully (driver: ' . DB_DRIVER . ")\n";
}
