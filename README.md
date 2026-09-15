<div align="center">

# U EPMS — Enterprise Plant Monitoring System

Pure PHP 8.x • Plain HTML5/CSS3 (zero frameworks) • MySQL (localhost) or SQLite • Currency: **TZS**

</div>

## Role & Permission Model

| Capability | Procurement Officer | Manager | Accountant | Admin | System Operator |
|---|---|---|---|---|---|
| View requisitions | ✅ | ✅ (dashboard) | ✅ (dashboard) | ✅ | ✅ |
| **Approve / reject requisitions** | ✅ (only authority) | — | — | — | — |
| Draft / edit requisitions | ❌ view & approve only | — | — | ✅ | — |
| Log production shift reports | — | ✅ | — | ✅ | — |
| **Record petty cash expenses** | — | ✅ | ✅ | — | — |
| **Issue new petty cash floats** | — | — | ❌ expenses only | ✅ (only authority) | — |
| Machinery & process config | — | — | — | ✅ | read-only |
| **Audit trail** | ❌ | ❌ | ❌ | ✅ | ✅ |
| Ban / delete users | — | — | — | ✅ | ✅ |
| View data | requisitions only | yes | yes | yes | **everything, read-only** (writes only on administration data) |

Deleting a user removes the account completely while preserving historical business records
(requisitions, floats, expenses, reports) — their user references become NULL via
`ON DELETE SET NULL` foreign keys.

## Run Locally (XAMPP / localhost)

**Prerequisites:** PHP 8.x with `pdo_mysql` (XAMPP includes it), MySQL/MariaDB running.

1. Start **Apache/MySQL** in the XAMPP control panel (MySQL is required for the default config).
2. Configure the environment in `.env` (already set up for localhost XAMPP):

   ```env
   DB_DRIVER=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=factory_db
   DB_USER=root
   DB_PASS=
   ```

3. Create the database + seed data — either:
   - open **phpMyAdmin** → Import → choose `database.sql`, **or**
   - run `mysql -u root < database.sql`, **or**
   - just start the app: it auto-creates the schema and seeds demo data on first run.

4. Open the app — no terminal needed:
   - **XAMPP Apache (recommended):** if Apache isn't running yet, open the XAMPP Control
     Panel and click **Start** next to Apache. Then browse to <http://localhost:3000>.
     (The folder ships with `apache-conf/httpd-uepms.conf` — if you ever reinstall XAMPP,
     copy it to `C:\xampp\apache\conf\extra\` and add
     `Include "conf/extra/httpd-uepms.conf"` to `httpd.conf`.)
   - Built-in server alternative: `php -S 127.0.0.1:3000 router.php` → <http://127.0.0.1:3000>

### SQLite fallback (zero configuration)

Set `DB_DRIVER=sqlite` in `.env` — the schema is created and seeded automatically at
`data/factory.db`. No MySQL needed.

## Demo Accounts

Password for every seeded account: `factory123`

| User | Username | Role |
|---|---|---|
| GIMENO | `gimeno` | System Operator |
| BRIGHTON MMARI | `brighton_mmari` | Admin |
| GLORY GEORGE | `glory_george` | Manager |
| SWAUMU MKOMWA | `swaumu_mkomwa` | Accountant |
| GLORIA MGASSA | `gloria_mgassa` | Procurement Officer |
| Victor Diaz | `victor_diaz` | Procurement Officer (**Banned** — login rejected) |

The login page also offers 1-click role login, and the top bar has a role switcher for
testing the RBAC matrix.

## Password Vault (Admin & System Operator)

On the **Users** page, Admin and System Operator can:

- **View Password** — reveals the account's current password (from the encrypted vault).
  Every reveal is written to the Audit Trail as `PASSWORD_VIEWED`.
- **Change Password** — sets a new password (min 6 characters) for any account.
  Logged in the Audit Trail as `PASSWORD_CHANGED`.

How it works: login verification always uses the one-way **bcrypt** hash (`password_hash`).
A second, **AES-256-GCM encrypted** copy of the password is stored in the
`users.password_encrypted` column so it can be recovered by Admin/Operator. The key lives
in `APP_KEY` (`.env`) — generate one with `php -r "echo bin2hex(random_bytes(32));"`.
Changing `APP_KEY` makes all stored vault passwords unreadable (logins still work).
Accounts whose password was changed before the vault existed (or without the app) have no
recoverable copy — use **Change Password** to set one.

> **Security note:** storing reversible passwords weakens the security model (anyone with
> DB + `APP_KEY` access can read every password). This is intentional for this internal
> deployment; remove `view_password`/`change_password` and the `password_encrypted` column
> to revert to hash-only storage.

## Currency

All monetary amounts are stored and displayed in **Tanzanian Shillings (TZS)** — see
`formatMoney()` in `includes/functions.php` and the `APP_CURRENCY` constant in `config.php`.

## Database Schema

`database.sql` contains the complete MySQL schema (utf8mb4, InnoDB, foreign keys) plus
seed data:

- `users` — RBAC directory (role, status: Active/Banned)
- `processes`, `machines` — plant configuration
- `procurement_entries` — requisitions with manager/accountant/admin approval columns
- `daily_reports`, `process_reject_logs` — production & defects
- `petty_cash_issuances`, `petty_cash_expenses` — floats & expenses (TZS)
- `audit_logs` — immutable audit trail (Admin/Operator visibility)

## Tests

An end-to-end RBAC suite boots the PHP server and verifies every role boundary over HTTP:

```bash
php -l $(git ls-files '*.php')        # lint
bash tests/e2e_rbac_test.sh           # 34 RBAC assertions (needs MySQL running)
```

## Notes

- Sessions use plain cookies on `http://localhost` and secure `SameSite=None` cookies on
  HTTPS automatically (see `includes/session.php`).
- An immutable audit record is written for every security-relevant action (logins, bans,
  deletions, approvals, float issuance, expenses, machine changes).
