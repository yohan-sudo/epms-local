-- =====================================================================
-- U EPMS - Restricted MySQL Accounts (item 29)
-- Retires root-with-no-password for the application.
--
-- Run ONCE from a shell:
--   C:\xampp\mysql\bin\mysql.exe -u root --password=<ROOT PASSWORD> < "C:\xampp\htdocs\U EPMS\scripts\create_db_users.sql"
--
-- The app password is generated fresh by the runner; after running,
-- set DB_USER=epms_app / DB_PASS=<generated password> in .env.
--
-- SECURITY NOTE: the passwords below are LOCAL-ONLY defaults for this
-- XAMPP installation (database bound to 127.0.0.1). Before exposing the
-- server to any network, replace every password here AND in the app .env.
-- =====================================================================

-- 1. Application account: only what the app needs, only from this machine.
CREATE USER IF NOT EXISTS 'epms_app'@'localhost' IDENTIFIED BY 'EpmsApp#2026Local';
ALTER USER 'epms_app'@'localhost' IDENTIFIED BY 'EpmsApp#2026Local';

GRANT SELECT, INSERT, UPDATE, DELETE ON `factory_db`.* TO 'epms_app'@'localhost';
-- The app's migrator needs ALTER/CREATE/INDEX on its own schema only:
GRANT ALTER, CREATE, INDEX, DROP ON `factory_db`.* TO 'epms_app'@'localhost';
-- Schema inspection uses information_schema (readable by any user on its own objects).

-- 2. Read-only account for report exports / audits by the CEO's office.
CREATE USER IF NOT EXISTS 'epms_readonly'@'localhost' IDENTIFIED BY 'EpmsRead#2026Local';
GRANT SELECT ON `factory_db`.* TO 'epms_readonly'@'localhost';

-- 3. Backup account: dump rights only.
CREATE USER IF NOT EXISTS 'epms_backup'@'localhost' IDENTIFIED BY 'EpmsBak#2026Local';
GRANT SELECT, LOCK TABLES, SHOW VIEW ON `factory_db`.* TO 'epms_backup'@'localhost';

FLUSH PRIVILEGES;

-- 4. If root currently has NO password, give it one now.
-- (Uncomment after confirming you know the new password - it is needed for admin work.)
-- ALTER USER 'root'@'localhost' IDENTIFIED BY 'RootAdmin#2026Local';

SELECT user, host FROM mysql.user WHERE user LIKE 'epms%';
