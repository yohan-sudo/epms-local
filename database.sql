-- =====================================================================
-- U EPMS - Factory Management System
-- MySQL schema + seed data (XAMPP / phpMyAdmin on localhost)
--
-- How to use:
--   1. Start MySQL in XAMPP, open http://localhost/phpmyadmin
--   2. Import this file (it creates the `factory_db` database itself)
--      OR run:  mysql -u root < database.sql
--
-- The app (config.php + .env) expects: host 127.0.0.1:3306,
-- database factory_db, user root, empty password.
--
-- All money amounts are Tanzanian Shillings (TZS).
-- Default password for every seeded account: factory123
-- (bcrypt hash for login + AES-256-GCM encrypted copy for Admin/Operator password recovery)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `factory_db`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `factory_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Users & RBAC
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `petty_cash_expenses`;
DROP TABLE IF EXISTS `petty_cash_issuances`;
DROP TABLE IF EXISTS `process_reject_logs`;
DROP TABLE IF EXISTS `daily_reports`;
DROP TABLE IF EXISTS `procurement_entries`;
DROP TABLE IF EXISTS `machines`;
DROP TABLE IF EXISTS `processes`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `username` VARCHAR(60) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `password_encrypted` VARBINARY(512) NULL,
  `role` VARCHAR(40) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'Active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `processes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `machines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(30) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `process_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'Operational',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_machines_code` (`code`),
  CONSTRAINT `fk_machines_process` FOREIGN KEY (`process_id`) REFERENCES `processes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Procurement requisitions
-- Statuses used by the app:
--   'Pending Manager Review' | 'Pending Accountant Review'
--   'Approved - Pending Admin Decision' | 'Finalized' | 'Rejected'
-- ---------------------------------------------------------------------
CREATE TABLE `procurement_entries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference_no` VARCHAR(30) NOT NULL,
  `submitted_by` INT UNSIGNED NULL,
  `supplier` VARCHAR(150) NOT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `category` VARCHAR(60) NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `unit` VARCHAR(30) NOT NULL,
  `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `status` VARCHAR(40) NOT NULL DEFAULT 'Pending Manager Review',
  `manager_approved_by` INT UNSIGNED NULL,
  `manager_approved_at` DATETIME NULL,
  `manager_notes` TEXT NULL,
  `accountant_approved_by` INT UNSIGNED NULL,
  `accountant_approved_at` DATETIME NULL,
  `accountant_notes` TEXT NULL,
  `admin_approved_by` INT UNSIGNED NULL,
  `admin_approved_at` DATETIME NULL,
  `admin_notes` TEXT NULL,
  `rejection_reason` TEXT NULL,
  `date` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proc_reference` (`reference_no`),
  CONSTRAINT `fk_proc_submitter` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_proc_manager` FOREIGN KEY (`manager_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_proc_accountant` FOREIGN KEY (`accountant_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_proc_admin` FOREIGN KEY (`admin_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `daily_reports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_date` DATE NOT NULL,
  `shift` VARCHAR(60) NOT NULL,
  `supervisor_id` INT UNSIGNED NULL,
  `machine_id` INT UNSIGNED NULL,
  `units_produced` INT UNSIGNED NOT NULL DEFAULT 0,
  `good_units` INT UNSIGNED NOT NULL DEFAULT 0,
  `supervisor_notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_reports_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reports_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `process_reject_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id` INT UNSIGNED NOT NULL,
  `process_id` INT UNSIGNED NULL,
  `partial_reject_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_reject_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `reject_reason` VARCHAR(255) NOT NULL,
  `root_cause` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_rejects_report` FOREIGN KEY (`report_id`) REFERENCES `daily_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rejects_process` FOREIGN KEY (`process_id`) REFERENCES `processes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `petty_cash_issuances` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_no` VARCHAR(30) NOT NULL,
  `issued_to` INT UNSIGNED NULL,
  `issued_by` INT UNSIGNED NULL,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `purpose` VARCHAR(255) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'Active',
  `issued_date` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_issuance_voucher` (`voucher_no`),
  CONSTRAINT `fk_issuance_to` FOREIGN KEY (`issued_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_issuance_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `petty_cash_expenses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issuance_id` INT UNSIGNED NOT NULL,
  `expense_date` DATE NOT NULL,
  `category` VARCHAR(60) NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `receipt_no` VARCHAR(60) NOT NULL,
  `approved_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_expense_issuance` FOREIGN KEY (`issuance_id`) REFERENCES `petty_cash_issuances` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_expense_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id` INT UNSIGNED NULL,
  `action` VARCHAR(60) NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL,
  `entity_id` VARCHAR(60) NOT NULL,
  `details` TEXT NOT NULL,
  `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Seed data
-- bcrypt hash of 'factory123'
-- password_encrypted: AES-256-GCM vault copy of 'factory123' (decrypted via APP_KEY in .env)
-- ---------------------------------------------------------------------
INSERT INTO `users` (`id`, `name`, `username`, `password_hash`, `password_encrypted`, `role`, `status`, `created_at`) VALUES
(1, 'GIMENO', 'gimeno', '$2y$10$YaDzh643sbUJcZKz1ItPIuD3emVlWfJnd3B.bkckpjNwUrhN8idt6', '/VwB3BQygjLv2k3AU3j1XNm2Aecs9SFmyzkSaVgE/ZRQS+PVyHE=', 'System Operator', 'Active', '2026-01-10 08:30:00'),
(2, 'BRIGHTON MMARI', 'brighton_mmari', '$2y$10$YaDzh643sbUJcZKz1ItPIuD3emVlWfJnd3B.bkckpjNwUrhN8idt6', '/VwB3BQygjLv2k3AU3j1XNm2Aecs9SFmyzkSaVgE/ZRQS+PVyHE=', 'Admin', 'Active', '2026-01-10 09:00:00'),
(3, 'GLORY GEORGE', 'glory_george', '$2y$10$YaDzh643sbUJcZKz1ItPIuD3emVlWfJnd3B.bkckpjNwUrhN8idt6', '/VwB3BQygjLv2k3AU3j1XNm2Aecs9SFmyzkSaVgE/ZRQS+PVyHE=', 'Manager', 'Active', '2026-01-15 10:15:00'),
(4, 'SWAUMU MKOMWA', 'swaumu_mkomwa', '$2y$10$YaDzh643sbUJcZKz1ItPIuD3emVlWfJnd3B.bkckpjNwUrhN8idt6', '/VwB3BQygjLv2k3AU3j1XNm2Aecs9SFmyzkSaVgE/ZRQS+PVyHE=', 'Accountant', 'Active', '2026-01-18 11:45:00'),
(5, 'GLORIA MGASSA', 'gloria_mgassa', '$2y$10$YaDzh643sbUJcZKz1ItPIuD3emVlWfJnd3B.bkckpjNwUrhN8idt6', '/VwB3BQygjLv2k3AU3j1XNm2Aecs9SFmyzkSaVgE/ZRQS+PVyHE=', 'Procurement Officer', 'Active', '2026-02-01 14:20:00'),
(6, 'Victor Diaz', 'victor_diaz', '$2y$10$YaDzh643sbUJcZKz1ItPIuD3emVlWfJnd3B.bkckpjNwUrhN8idt6', '/VwB3BQygjLv2k3AU3j1XNm2Aecs9SFmyzkSaVgE/ZRQS+PVyHE=', 'Procurement Officer', 'Banned', '2026-03-01 16:00:00');

INSERT INTO `processes` (`id`, `name`, `description`, `status`) VALUES
(1, 'Stamping & Heavy Pressing', 'Cold rolled steel blanking and hydraulic forming', 'Active'),
(2, 'Precision CNC Machining', 'Multi-axis milling, drilling, and high-tolerance turning', 'Active'),
(3, 'Surface Treatment & Powder Coating', 'Degreasing, electrostatic spray, and infrared thermal curing', 'Active'),
(4, 'Final Assembly & QA Testing', 'Modular component integration and pneumatic pressure checks', 'Active');

INSERT INTO `machines` (`id`, `code`, `name`, `process_id`, `status`) VALUES
(101, 'HYD-01', 'Komatsu 250T Hydraulic Press', 1, 'Operational'),
(102, 'CNC-04', 'Haas VF-4SS 4-Axis Vertical Mill', 2, 'Operational'),
(103, 'COAT-02', 'Nordson Automatic Powder Line', 3, 'Maintenance'),
(104, 'ASSY-01', 'Pneumatic Robotic Assembly Cell 1', 4, 'Operational'),
(105, 'PRESS-02', 'AIDA 160T Mechanical Stamping Press', 1, 'Operational');

INSERT INTO `procurement_entries`
(`reference_no`, `submitted_by`, `supplier`, `item_name`, `category`, `quantity`, `unit`, `unit_cost`, `total_cost`, `status`, `manager_approved_by`, `manager_approved_at`, `manager_notes`, `accountant_approved_by`, `accountant_approved_at`, `accountant_notes`, `admin_approved_by`, `admin_approved_at`, `admin_notes`, `rejection_reason`, `date`) VALUES
('REQ-2026-001', 5, 'Apex Industrial Steel Ltd.', 'Cold Rolled Steel Coils (Grade 1018)', 'Raw Material', 15.50, 'Tons', 3150000.00, 48825000.00, 'Finalized', 3, '2026-03-02 11:15:00', 'Material specifications verified against Q2 production roadmap.', 4, '2026-03-03 09:30:00', 'Budget allocation confirmed under Capital Expenditures.', 2, '2026-03-03 16:45:00', 'Purchase authorized. Expedited delivery approved.', NULL, '2026-03-01'),
('REQ-2026-002', 5, 'Vanguard Cutting Tools Corp', 'Carbide End Mills & Drill Inserts (Pack of 50)', 'Tooling', 8.00, 'Sets', 1060000.00, 8480000.00, 'Approved - Pending Admin Decision', 3, '2026-03-08 14:00:00', 'Essential for CNC machine line continuity.', 4, '2026-03-09 10:20:00', 'Operating account budget verified.', NULL, NULL, NULL, NULL, '2026-03-07'),
('REQ-2026-003', 5, 'Total Lubricants & Hydraulics', 'ISO VG 46 Hydraulic Oil (200L Drum)', 'Consumables', 6.00, 'Drums', 960000.00, 5760000.00, 'Pending Accountant Review', 3, '2026-03-12 16:10:00', 'Approved for scheduled 500-hour hydraulic maintenance.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-12'),
('REQ-2026-004', 5, 'ElectroCoat Systems', 'Polyester Powder Paint - Gloss Safety Yellow', 'Raw Material', 500.00, 'kg', 21500.00, 10750000.00, 'Pending Manager Review', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-14');

INSERT INTO `daily_reports` (`id`, `report_date`, `shift`, `supervisor_id`, `machine_id`, `units_produced`, `good_units`, `supervisor_notes`, `created_at`) VALUES
(1, '2026-03-13', 'Morning (06:00 - 14:00)', 3, 101, 1450, 1390, 'High yield run on outer chassis bracket forming. Die wear monitored on flange radius.', '2026-03-13 14:15:00'),
(2, '2026-03-13', 'Afternoon (14:00 - 22:00)', 3, 102, 380, 362, 'CNC batch finished ahead of schedule. Tool 3 replaced at mid-shift after tool wear alarm.', '2026-03-13 22:10:00'),
(3, '2026-03-14', 'Morning (06:00 - 14:00)', 3, 104, 520, 508, 'Final assembly cycle stable. Minor pneumatic connector seal rework performed on 8 units.', '2026-03-14 14:05:00');

INSERT INTO `process_reject_logs` (`report_id`, `process_id`, `partial_reject_count`, `total_reject_count`, `reject_reason`, `root_cause`) VALUES
(1, 1, 45, 15, 'Burr formation & Edge thinning', 'Upper punch edge rounding during sheet draw stroke'),
(2, 2, 12, 6, 'Dimensional tolerance drift (+0.04mm)', 'Thermal expansion on spindle cooling jacket'),
(3, 4, 8, 4, 'Fastener torque anomaly', 'Air pressure drop on pneumatic torque wrench line');

INSERT INTO `petty_cash_issuances` (`id`, `voucher_no`, `issued_to`, `issued_by`, `amount`, `purpose`, `status`, `issued_date`) VALUES
(1, 'PCV-2026-001', 3, 2, 1500000.00, 'Shift operations emergency parts & local maintenance float', 'Active', '2026-03-01'),
(2, 'PCV-2026-002', 5, 2, 800000.00, 'Courier logistics, urgent customs clearances, and sample freight', 'Active', '2026-03-05');

INSERT INTO `petty_cash_expenses` (`issuance_id`, `expense_date`, `category`, `description`, `amount`, `receipt_no`, `approved_by`) VALUES
(1, '2026-03-03', 'Hardware & Fasteners', 'Emergency M8 Grade 8.8 Hex Bolts & Spring Washers', 355000.00, 'REC-44912', 4),
(1, '2026-03-07', 'Shop Consumables', 'Industrial Degreaser Solvent & Heavy Duty Shop Towels', 215500.00, 'REC-45019', 4),
(1, '2026-03-11', 'Equipment Maintenance', 'Replacement hydraulic solenoid fuse and terminal blocks', 287500.00, 'REC-45188', 4),
(2, '2026-03-06', 'Logistics & Freight', 'Same-day courier dispatch for metallurgical sample testing', 360000.00, 'DHL-88910', 4);

INSERT INTO `audit_logs` (`actor_id`, `action`, `entity_type`, `entity_id`, `details`, `timestamp`) VALUES
(1, 'SYSTEM_BOOTSTRAP', 'DATABASE', 'SCHEMA', 'Database schema initialized and baseline manufacturing records seeded (currency: TZS).', '2026-03-01 08:00:00'),
(2, 'PROCUREMENT_FINALIZED', 'PROCUREMENT', 'REQ-2026-001', 'Admin BRIGHTON MMARI authorized final procurement order REQ-2026-001 (TZS 48,825,000.00).', '2026-03-03 16:45:00'),
(2, 'PETTY_CASH_ISSUED', 'PETTY_CASH', 'PCV-2026-001', 'Admin BRIGHTON MMARI issued TZS 1,500,000.00 petty cash float to GLORY GEORGE.', '2026-03-01 09:15:00');
