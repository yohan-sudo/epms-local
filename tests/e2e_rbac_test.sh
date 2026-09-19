#!/bin/bash
# =============================================================
# U EPMS - End-to-End RBAC Verification
# Boots the PHP built-in server and tests each role's
# permissions over real HTTP requests.
# Usage:  bash tests/e2e_rbac_test.sh
# =============================================================
# Use a dedicated test port so the suite never clashes with the
# Apache vhost that serves the app on 3000.
PORT="${TEST_PORT:-3100}"
BASE="http://127.0.0.1:$PORT"
PHP_BIN="${PHP_BIN:-php}"
PASS=0
FAIL=0
SERVER_PID=""

cleanup() {
  if [ -n "$SERVER_PID" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null
    wait "$SERVER_PID" 2>/dev/null
  fi
  rm -f /tmp/uepms_cookies_*.txt
}
trap cleanup EXIT

check() { # $1 description, $2 result (0 = pass)
  if [ "$2" == "0" ]; then PASS=$((PASS+1)); echo "PASS: $1";
  else FAIL=$((FAIL+1)); echo "FAIL: $1"; fi
}

login_as() { # $1 username -> cookie jar at /tmp/uepms_cookies_$1.txt
  JAR="/tmp/uepms_cookies_$1.txt"
  rm -f "$JAR"
  curl -s -c "$JAR" -d "username=$1&password=factory123" "$BASE/index.php" -o /dev/null -L
  echo "$JAR"
}

get_page() { # $1 jar, $2 url -> body
  curl -s -b "$1" "$BASE$2"
}

post() { # $1 jar, $2 url, $3 data -> body
  curl -s -b "$1" -d "$3" "$BASE$2" -o /dev/null
}

MYSQL="/c/xampp/mysql/bin/mysql.exe"

echo "=== 1. Booting PHP built-in server on :$PORT ==="
PHP_CLI_SERVER_WORKERS=4 "$PHP_BIN" -S "127.0.0.1:$PORT" router.php >/tmp/uepms_server.log 2>&1 &
SERVER_PID=$!
for i in $(seq 1 30); do
  if curl -s -o /dev/null "$BASE/index.php"; then break; fi
  sleep 0.5
done
curl -s -o /dev/null "$BASE/index.php" && echo "Server is up." || { echo "Server failed to start"; exit 1; }

echo ""
echo "=== 2. Procurement Officer (GLORIA) submits procurement records ==="
JAR_PROC=$(login_as gloria_mgassa)
BODY=$(get_page "$JAR_PROC" "/procurement.php")
echo "$BODY" | grep -q "Procurement Records" && check "PO: procurement records portal loads" 0 || check "PO: procurement records portal loads" 1
echo "$BODY" | grep -q "Submit Procurement Record" && check "PO: has submit button" 0 || check "PO: has submit button" 1
# Officer CAN create a procurement record - it lands as 'Pending Manager Review'
post "$JAR_PROC" "/procurement.php" "action=create_request&supplier=E2E+Supply+Co&item_name=E2E+Bristles&category=Raw+Material&quantity=10&unit=packet&unit_cost=5000&req_date=$(date +%Y-%m-%d)"
E2E_REF=$($MYSQL -u root -N -e "SELECT reference_no FROM factory_db.procurement_entries WHERE supplier='E2E Supply Co' ORDER BY id DESC LIMIT 1;" 2>/dev/null | tr -d '\r')
case "$E2E_REF" in
  PRC-*) check "PO: record created with PRC reference ($E2E_REF)" 0 ;;
  REQ-*) check "PO: record created with PRC reference" 1 ;;
  *) check "PO: record created with PRC reference (none found)" 1 ;;
esac
E2E_ID=$($MYSQL -u root -N -e "SELECT id FROM factory_db.procurement_entries WHERE supplier='E2E Supply Co' ORDER BY id DESC LIMIT 1;" 2>/dev/null | tr -d '\r')
STATUS=$($MYSQL -u root -N -e "SELECT status FROM factory_db.procurement_entries WHERE id=$E2E_ID;" 2>/dev/null | tr -d '\r')
[ "$STATUS" == "Pending Manager Review" ] && check "PO: record enters 'Pending Manager Review'" 0 || check "PO: record enters 'Pending Manager Review' (got $STATUS)" 1
# Dashboard access must redirect to the procurement portal
curl -s -b "$JAR_PROC" -o /dev/null -w "%{redirect_url}" "$BASE/dashboard.php" | grep -q "procurement" && check "PO: dashboard redirects to portal" 0 || check "PO: dashboard redirects to portal" 1
# Users page must be forbidden
curl -s -b "$JAR_PROC" "$BASE/users.php" | grep -q "403 Forbidden" && check "PO: users module forbidden" 0 || check "PO: users module forbidden" 1
# Audit trail must be forbidden
curl -s -b "$JAR_PROC" "$BASE/audit_logs.php" | grep -q "403 Forbidden" && check "PO: audit trail forbidden" 0 || check "PO: audit trail forbidden" 1
# Petty cash: the Officer may REQUEST funds but must never see issue/record/confirm powers
PC_BODY=$(curl -s -b "$JAR_PROC" "$BASE/petty_cash.php")
echo "$PC_BODY" | grep -q "Request Petty Cash" && check "PO: petty cash request portal accessible" 0 || check "PO: petty cash request portal accessible" 1
echo "$PC_BODY" | grep -q "value=\"issue_float\"\|value=\"record_expense\"" && check "PO: petty cash money actions hidden" 1 || check "PO: petty cash money actions hidden" 0

echo ""
echo "=== 3. Manager (GLORY) first approval ==="
JAR_MGR=$(login_as daniel_gimeno)
BODY=$(get_page "$JAR_MGR" "/dashboard.php")
echo "$BODY" | grep -q "Procurement Awaiting Your Approval" && check "MGR: dashboard shows approval worklist" 0 || check "MGR: dashboard shows approval worklist" 1
# Manager tries to create a record - must be blocked
post "$JAR_MGR" "/procurement.php" "action=create_request&supplier=Mgr+Co&item_name=Nope&category=Tooling&quantity=1&unit=piece&unit_cost=10&req_date=$(date +%Y-%m-%d)"
BODY=$(get_page "$JAR_MGR" "/procurement.php")
echo "$BODY" | grep -q "reserved for the Procurement Officer" && check "MGR: submitting blocked (Officer-only)" 0 || check "MGR: submitting blocked (Officer-only)" 1
# Manager approves the E2E record -> moves to 'Pending Accountant Review'
post "$JAR_MGR" "/procurement.php" "action=manager_decision&po_id=$E2E_ID&decision=approve&notes=E2E+manager+approval"
STATUS=$($MYSQL -u root -N -e "SELECT status FROM factory_db.procurement_entries WHERE id=$E2E_ID;" 2>/dev/null | tr -d '\r')
[ "$STATUS" == "Finalized" ] && check "MGR: approval is final (Finalized, v2.3.2)" 0 || check "MGR: approval is final (Finalized, v2.3.2) (got $STATUS)" 1

echo ""
echo "=== 4. Accountant (SWAUMU) final approval ==="
JAR_ACC=$(login_as swaumu_mkomwa)
BODY=$(get_page "$JAR_ACC" "/dashboard.php")
echo "$BODY" | grep -q "Cash Requests to Pay" && check "ACC: dashboard shows payments worklist" 0 || check "ACC: dashboard shows payments worklist" 1
echo "$BODY" | grep -q "Procurement Awaiting" && check "ACC: dashboard hides procurement approvals" 1 || check "ACC: dashboard hides procurement approvals" 0
# Accountant petty cash module loads; cannot issue floats
BODY=$(get_page "$JAR_ACC" "/petty_cash.php")
echo "$BODY" | grep -q "Petty Cash" && check "ACC: petty cash module loads" 0 || check "ACC: petty cash module loads" 1
echo "$BODY" | grep -q "+ Issue New Float" && check "ACC: NO issue-float button (should be absent)" 1 || check "ACC: NO issue-float button (should be absent)" 0
# v2.3.2: the Manager's approval is FINAL - the record is already Finalized here
STATUS=$($MYSQL -u root -N -e "SELECT status FROM factory_db.procurement_entries WHERE id=$E2E_ID;" 2>/dev/null | tr -d '\r')
[ "$STATUS" == "Finalized" ] && check "MGR: approval finalized the record (no Accountant stage)" 0 || check "MGR: approval finalized the record (no Accountant stage) (got $STATUS)" 1
# The Accountant's decision endpoint is retired: posting it changes nothing
post "$JAR_ACC" "/procurement.php" "action=accountant_decision&po_id=$E2E_ID&decision=reject&notes=try+again"
STATUS=$($MYSQL -u root -N -e "SELECT status FROM factory_db.procurement_entries WHERE id=$E2E_ID;" 2>/dev/null | tr -d '\r')
[ "$STATUS" == "Finalized" ] && check "ACC: accountant_decision retired (record untouched)" 0 || check "ACC: accountant_decision retired (record untouched) (got $STATUS)" 1
# Audit trail forbidden
curl -s -b "$JAR_ACC" "$BASE/audit_logs.php" | grep -q "403 Forbidden" && check "ACC: audit trail forbidden" 0 || check "ACC: audit trail forbidden" 1
# Users forbidden
curl -s -b "$JAR_ACC" "$BASE/users.php" | grep -q "403 Forbidden" && check "ACC: users module forbidden" 0 || check "ACC: users module forbidden" 1
# Dashboard must NOT contain audit table
curl -s -b "$JAR_ACC" "$BASE/dashboard.php" | grep -q "System Activity" && check "ACC: dashboard hides audit trail" 1 || check "ACC: dashboard hides audit trail" 0
# Record a real expense (within float balance)
post "$JAR_ACC" "/petty_cash.php" "action=record_expense&issuance_id=1&expense_date=2026-03-15&category=Shop+Consumables&description=Test+expense&amount=1000&receipt_no=REC-TEST1"
BODY=$(get_page "$JAR_ACC" "/petty_cash.php")
echo "$BODY" | grep -q "Test expense" && check "ACC: expense recorded successfully" 0 || check "ACC: expense recorded successfully" 1

echo ""
echo "=== 5. CEO (BRIGHTON) full administration ==="
JAR_CEO=$(login_as brighton_mmari)
BODY=$(get_page "$JAR_CEO" "/users.php")
echo "$BODY" | grep -q "Personnel Registry" && check "CEO: users module loads" 0 || check "CEO: users module loads" 1
echo "$BODY" | grep -q "Delete" && check "CEO: has Delete button" 0 || check "CEO: has Delete button" 1
curl -s -b "$JAR_CEO" "$BASE/audit_logs.php" | grep -q "Immutable System Audit Trail" && check "CEO: audit trail loads" 0 || check "CEO: audit trail loads" 1
# Issue a float - CEO can issue, but ONLY to an Accountant (sole float holder)
post "$JAR_CEO" "/petty_cash.php" "action=issue_float&issued_to=4&amount=1500000&purpose=CEO+test+float&issued_date=$(date +%Y-%m-%d)"
BODY=$(get_page "$JAR_CEO" "/petty_cash.php")
echo "$BODY" | grep -q "CEO test float" && check "CEO: float issued successfully" 0 || check "CEO: float issued successfully" 1
# Create + delete a scratch user (12-char minimum; full name MUST already be CAPITALS)
post "$JAR_CEO" "/users.php" "action=create_user&name=SCRATCH+USER&username=scratch_user&role=Manager&password=TempPass!2026x"
SCRATCH_ROW=$(get_page "$JAR_CEO" "/users.php" | grep -B8 "scratch_user" | grep -o '#[0-9]*' | head -1 | tr -d '#')
BODY=$(get_page "$JAR_CEO" "/users.php")
echo "$BODY" | grep -q "SCRATCH USER" && check "CEO: CAPITALS full name accepted" 0 || check "CEO: CAPITALS full name accepted" 1
# lowercase names are AUTO-UPPERCASED to CAPITALS on save
post "$JAR_CEO" "/users.php" "action=create_user&name=lower+case+name&username=lower_case_user&role=Manager&password=TempPass!2026x"
get_page "$JAR_CEO" "/users.php" | grep -q "LOWER CASE NAME" && check "PO-name lowercase auto-uppercased to CAPITALS" 0 || check "PO-name lowercase auto-uppercased to CAPITALS" 1
LC_ROW=$(get_page "$JAR_CEO" "/users.php" | grep -B8 "lower_case_user" | grep -o '#[0-9]*' | head -1 | tr -d '#')
post "$JAR_CEO" "/users.php" "action=delete_user&target_user_id=$LC_ROW"
post "$JAR_CEO" "/users.php" "action=delete_user&target_user_id=$SCRATCH_ROW"
BODY=$(get_page "$JAR_CEO" "/users.php")
echo "$BODY" | grep -q "scratch_user" && check "CEO: user hard-deleted" 1 || check "CEO: user hard-deleted" 0
# Full names: digits must be rejected outright (CAPITAL LETTERS only)
post "$JAR_CEO" "/users.php" "action=create_user&name=Digits+123&username=digits_user&role=Manager&password=TempPass!2026x"
BODY=$(get_page "$JAR_CEO" "/users.php")
echo "$BODY" | grep -q "No numbers or other characters" && check "PO-name digit rejection message shown" 0 || check "PO-name digit rejection message shown" 1
echo "$BODY" | grep -q "digits_user" && check "PO-name with digits rejected" 1 || check "PO-name with digits rejected" 0
# Create-user form must NOT offer System Operator or Admin
BODY=$(get_page "$JAR_CEO" "/users.php?action=new")
echo "$BODY" | grep -q "System Operator" && check "CEO: no System Operator role anywhere" 1 || check "CEO: no System Operator role anywhere" 0
echo "$BODY" | grep -q '"Admin"' && check "CEO: no Admin role in create form" 1 || check "CEO: no Admin role in create form" 0
echo "$BODY" | grep -q "CEO (Owner" && check "CEO: CEO role offered in create form" 0 || check "CEO: CEO role offered in create form" 1
# Role switcher removed: no endpoint, no header dropdown
curl -s -o /dev/null -w "%{http_code}" "$BASE/switch_role.php" | grep -q "404" && check "switch_role.php endpoint removed (404)" 0 || check "switch_role.php endpoint removed (404)" 1
get_page "$JAR_CEO" "/dashboard.php" | grep -q "Active Role:" && check "header shows no Active Role switcher" 1 || check "header shows no Active Role switcher" 0
get_page "$JAR_CEO" "/dashboard.php" | grep -q "header-user-chip" && check "header shows signed-in identity chip" 0 || check "header shows signed-in identity chip" 1

echo ""
echo "=== 6. Search & Reports retrieve data ==="
JAR2=$(login_as brighton_mmari)
# Search on production returns rows (machine + notes text)
BODY=$(get_page "$JAR2" "/production.php?q=Rounding")
echo "$BODY" | grep -q "R1" && check "SEARCH: production q=Rounding finds machine R1" 0 || check "SEARCH: production q=Rounding finds machine R1" 1
# Date-filtered production search returns the seeded rows
BODY=$(get_page "$JAR2" "/production.php?from=2026-03-01&to=$(date +%Y-%m-%d)")
echo "$BODY" | grep -q "Sanding Machine S1" && check "SEARCH: production date filter returns rows" 0 || check "SEARCH: production date filter returns rows" 1
# Procurement search by supplier (matches either fresh-seed or legacy seed data)
BODY=$(get_page "$JAR2" "/procurement.php?q=Apex")
echo "$BODY" | grep -q "Apex Industrial\|Apex Bristle" && check "SEARCH: procurement q=Apex finds supplier" 0 || check "SEARCH: procurement q=Apex finds supplier" 1
# Audit CSV export returns data
CSV=$(get_page "$JAR2" "/audit_logs.php?export=csv")
echo "$CSV" | grep -q "SYSTEM_BOOTSTRAP" && check "SEARCH: audit CSV export returns rows" 0 || check "SEARCH: audit CSV export returns rows" 1
# Reports preview renders the production report with rows
BODY=$(get_page "$JAR2" "/reports.php?report=production&from=2026-03-01&to=$(date +%Y-%m-%d)&preview=1")
echo "$BODY" | grep -q "Production Shift Report" && check "REPORT: production preview renders" 0 || check "REPORT: production preview renders" 1
echo "$BODY" | grep -q "Rounding Machine R1" && check "REPORT: production preview has data rows" 0 || check "REPORT: production preview has data rows" 1
# Procurement records report renders (officer can see it too)
BODY=$(get_page "$JAR_PROC" "/reports.php?report=procurement_records&from=2026-01-01&to=$(date +%Y-%m-%d)&preview=1")
echo "$BODY" | grep -q "Procurement Records Report" && check "REPORT: procurement preview renders for PO" 0 || check "REPORT: procurement preview renders for PO" 1
# Audit report forbidden to non-CEO
curl -s -b "$JAR_ACC" -o /dev/null -w "%{http_code}" "$BASE/reports.php?report=audit&from=2026-01-01&to=$(date +%Y-%m-%d)&preview=1" | grep -q "403" && check "REPORT: audit report forbidden to Accountant" 0 || check "REPORT: audit report forbidden to Accountant" 1

echo ""
echo "=== 7. Banned user rejected ==="
JAR_BAN="/tmp/uepms_cookies_victor_diaz.txt"
rm -f "$JAR_BAN"
curl -s -c "$JAR_BAN" -d "username=victor_diaz&password=factory123" "$BASE/index.php" -o /dev/null
BODY=$(get_page "$JAR_BAN" "/index.php")
echo "$BODY" | grep -q "Account Suspended\|suspended/banned\|Access denied" && check "Banned login rejected" 0 || check "Banned login rejected" 1

echo ""
echo "=== 8. TZS currency & nav labels ==="
JAR3=$(login_as brighton_mmari)
get_page "$JAR3" "/petty_cash.php" | grep -q "TZS" && check "Currency shows TZS" 0 || check "Currency shows TZS" 1
# The nav label must render as 'Reports & Documents' (not the escaped &amp; bug)
BODY=$(get_page "$JAR3" "/dashboard.php")
echo "$BODY" | grep -q "Reports &amp; Documents" && check "NAV: Reports & Documents label correct" 0 || check "NAV: Reports & Documents label correct" 1
echo "$BODY" | grep -q "Reports &amp;amp; Documents" && check "NAV: no double-escaped &amp;amp; bug" 1 || check "NAV: no double-escaped &amp;amp; bug" 0

echo ""
echo "=== 9. v2.3: Supervisor role & workflow chains ==="
# Supervisor logs in and files a shift report that lands PENDING manager approval
rm -f /tmp/uepms_sup.txt
curl -s -c /tmp/uepms_sup.txt -d "username=glory_george&password=factory123" "$BASE/index.php" -o /dev/null -L
SUP_HOME=$(get_page /tmp/uepms_sup.txt "/production.php")
echo "$SUP_HOME" | grep -q "Log Shift Report" && check "SUP: supervisor can log shift reports" 0 || check "SUP: supervisor can log shift reports" 1
post /tmp/uepms_sup.txt "/production.php" "action=save_shift_report&report_date=$(date +%Y-%m-%d)&shift=Morning+(06:00+-+14:00)&machine_id=1&process_id=1&units_processed=500&partial_reject_count=2&total_reject_count=1&reject_reason=No+rejects&supervisor_notes=E2Ev23"
SUP_PENDING=$($MYSQL -u root -N -e "SELECT approval_status FROM factory_db.daily_reports WHERE supervisor_notes='E2Ev23' ORDER BY id DESC LIMIT 1;" 2>/dev/null | tr -d '\r')
[ "$SUP_PENDING" == "Pending Manager Approval" ] && check "SUP: production log enters 'Pending Manager Approval'" 0 || check "SUP: production log enters 'Pending Manager Approval' (got $SUP_PENDING)" 1
# Manager verifies it
JAR_MGR2=$(login_as daniel_gimeno)
SUP_ID=$($MYSQL -u root -N -e "SELECT id FROM factory_db.daily_reports WHERE supervisor_notes='E2Ev23' ORDER BY id DESC LIMIT 1;" 2>/dev/null | tr -d '\r')
post "$JAR_MGR2" "/production.php" "action=verify_report&report_id=$SUP_ID&decision=approve"
SUP_AFTER=$($MYSQL -u root -N -e "SELECT approval_status FROM factory_db.daily_reports WHERE id=$SUP_ID;" 2>/dev/null | tr -d '\r')
[ "$SUP_AFTER" == "Manager Verified" ] && check "MGR: production log verified" 0 || check "MGR: production log verified (got $SUP_AFTER)" 1
# Supervisor is locked out of money pages
get_page /tmp/uepms_sup.txt "/petty_cash.php" | grep -q "403 Forbidden" && check "SUP: petty cash forbidden" 0 || check "SUP: petty cash forbidden" 1
curl -s -b /tmp/uepms_sup.txt "$BASE/cash_requests.php" | grep -q "403 Forbidden" && check "SUP: cash requests forbidden" 0 || check "SUP: cash requests forbidden" 1

# --- procurement requisition: PO -> CEO approval -> Finalized -> inventory ---
JAR_PROC2=$(login_as gloria_mgassa)
post "$JAR_PROC2" "/procurement.php" "action=create_request&supplier=V23+Chain+Co&item_name=V23+Wire&category=Raw+Material&quantity=4&unit=kg&unit_cost=9000&req_date=$(date +%Y-%m-%d)"
V23_ID=$($MYSQL -u root -N -e "SELECT id FROM factory_db.procurement_entries WHERE supplier='V23 Chain Co' ORDER BY id DESC LIMIT 1;" 2>/dev/null | tr -d '\r')
V23_REQ=$($MYSQL -u root -N -e "SELECT requisition_status FROM factory_db.procurement_entries WHERE id=$V23_ID;" 2>/dev/null | tr -d '\r')
[ "$V23_REQ" == "Pending CEO Approval" ] && check "PO: new record starts with requisition 'Pending CEO Approval'" 0 || check "PO: new record starts with requisition 'Pending CEO Approval' (got $V23_REQ)" 1
JAR_CEO2=$(login_as brighton_mmari)
post "$JAR_CEO2" "/procurement.php" "action=requisition_decision&po_id=$V23_ID&decision=approve&notes=E2E"
V23_REQ2=$($MYSQL -u root -N -e "SELECT requisition_status FROM factory_db.procurement_entries WHERE id=$V23_ID;" 2>/dev/null | tr -d '\r')
[ "$V23_REQ2" == "CEO Approved" ] && check "CEO: requisition approved" 0 || check "CEO: requisition approved (got $V23_REQ2)" 1
# Manager approves = FINAL (v2.3.2: Accountant never approves procurement)
post "$JAR_MGR2" "/procurement.php" "action=manager_decision&po_id=$V23_ID&decision=approve&notes=E2E"
JAR_ACC2=$(login_as swaumu_mkomwa)
V23_STATUS=$($MYSQL -u root -N -e "SELECT status FROM factory_db.procurement_entries WHERE id=$V23_ID;" 2>/dev/null | tr -d '\r')
[ "$V23_STATUS" == "Finalized" ] && check "MGR: approval is FINAL (Finalized)" 0 || check "MGR: approval is FINAL (Finalized) (got $V23_STATUS)" 1
# Accountant cannot approve procurement anymore
post "$JAR_ACC2" "/procurement.php" "action=accountant_decision&po_id=$V23_ID&decision=approve&notes=E2E"
V23_STILL=$($MYSQL -u root -N -e "SELECT status FROM factory_db.procurement_entries WHERE id=$V23_ID;" 2>/dev/null | tr -d '\r')
[ "$V23_STILL" == "Finalized" ] && check "ACC: accountant_decision is a no-op (role removed)" 0 || check "ACC: accountant_decision is a no-op (role removed) (got $V23_STILL)" 1
# Accountant cannot log production (payments-only role)
get_page "$JAR_ACC2" "/production.php" | grep -q "403 Forbidden" && check "ACC: production forbidden (v2.3.2)" 0 || check "ACC: production forbidden (v2.3.2)" 1
# PO receives into inventory
post "$JAR_PROC2" "/procurement.php" "action=receive_to_inventory&po_id=$V23_ID"
V23_INV=$($MYSQL -u root -N -e "SELECT inventory_received FROM factory_db.procurement_entries WHERE id=$V23_ID;" 2>/dev/null | tr -d '\r')
[ "$V23_INV" == "1" ] && check "PO: received into inventory" 0 || check "PO: received into inventory (got $V23_INV)" 1

# --- material request: Supervisor -> Manager approve -> PO release -> confirm ---
post /tmp/uepms_sup.txt "/inventory.php" "action=request_material&item_id=$( ($MYSQL -u root -N -e "SELECT id FROM factory_db.inventory_items WHERE is_finished_goods=0 ORDER BY id DESC LIMIT 1;" 2>/dev/null | tr -d '\r') )&quantity=1&purpose=E2E"
MTR_ID=$($MYSQL -u root -N -e "SELECT id FROM factory_db.inventory_requests WHERE purpose='E2E' ORDER BY id DESC LIMIT 1;" 2>/dev/null | tr -d '\r')
MTR_STATUS=$($MYSQL -u root -N -e "SELECT status FROM factory_db.inventory_requests WHERE id=$MTR_ID;" 2>/dev/null | tr -d '\r')
[ "$MTR_STATUS" == "Pending Manager Approval" ] && check "SUP: material request sent to Manager" 0 || check "SUP: material request sent to Manager (got $MTR_STATUS)" 1
post "$JAR_MGR2" "/inventory.php" "action=manager_request_decision&request_id=$MTR_ID&decision=approve"
post "$JAR_PROC2" "/inventory.php" "action=release_material&request_id=$MTR_ID"
post /tmp/uepms_sup.txt "/inventory.php" "action=confirm_receipt&request_id=$MTR_ID"
MTR_DONE=$($MYSQL -u root -N -e "SELECT status FROM factory_db.inventory_requests WHERE id=$MTR_ID;" 2>/dev/null | tr -d '\r')
[ "$MTR_DONE" == "Completed" ] && check "MTR: full request chain completes" 0 || check "MTR: full request chain completes (got $MTR_DONE)" 1

# --- cash request: Manager -> CEO -> Accountant -> confirm ---
post "$JAR_MGR2" "/cash_requests.php" "action=request_cash&amount=50000&purpose=E2E+cash+chain"
CSH_ID=$($MYSQL -u root -N -e "SELECT id FROM factory_db.cash_requests WHERE purpose='E2E cash chain' ORDER BY id DESC LIMIT 1;" 2>/dev/null | tr -d '\r')
CSH_STATUS=$($MYSQL -u root -N -e "SELECT status FROM factory_db.cash_requests WHERE id=$CSH_ID;" 2>/dev/null | tr -d '\r')
[ "$CSH_STATUS" == "Pending CEO Approval" ] && check "MGR: cash request to CEO" 0 || check "MGR: cash request to CEO (got $CSH_STATUS)" 1
post "$JAR_CEO2" "/cash_requests.php" "action=ceo_decision&request_id=$CSH_ID&decision=approve&notes=E2E"
CSH_AFTER=$($MYSQL -u root -N -e "SELECT status FROM factory_db.cash_requests WHERE id=$CSH_ID;" 2>/dev/null | tr -d '\r')
[ "$CSH_AFTER" == "Approved - Sent to Accountant" ] && check "CEO: approval auto-forwards to Accountant" 0 || check "CEO: approval auto-forwards to Accountant (got $CSH_AFTER)" 1
post "$JAR_ACC2" "/cash_requests.php" "action=disburse&request_id=$CSH_ID"
MGR_ID=$($MYSQL -u root -N -e "SELECT id FROM users WHERE username='daniel_gimeno';" 2>/dev/null | tr -d '\r')
post "$JAR_MGR2" "/cash_requests.php" "action=confirm_receipt&request_id=$CSH_ID"
CSH_DONE=$($MYSQL -u root -N -e "SELECT status FROM factory_db.cash_requests WHERE id=$CSH_ID;" 2>/dev/null | tr -d '\r')
[ "$CSH_DONE" == "Completed" ] && check "MGR: cash loop closed by receipt confirmation" 0 || check "MGR: cash loop closed by receipt confirmation (got $CSH_DONE)" 1

# --- shipments: CEO request -> PO prepare -> Manager approve ---
post "$JAR_CEO2" "/shipments.php" "action=request_shipment&destination=E2E+Market"
SHP_ID=$($MYSQL -u root -N -e "SELECT id FROM factory_db.shipment_orders WHERE destination='E2E Market' ORDER BY id DESC LIMIT 1;" 2>/dev/null | tr -d '\r')
post "$JAR_PROC2" "/shipments.php" "action=prepare_shipment&shipment_id=$SHP_ID&product_item_id=0&bundles=2&units=100&po_notes=E2E"
SHP_STATUS=$($MYSQL -u root -N -e "SELECT status FROM factory_db.shipment_orders WHERE id=$SHP_ID;" 2>/dev/null | tr -d '\r')
[ "$SHP_STATUS" == "Prepared - Awaiting Manager Approval" ] && check "PO: shipment prepared" 0 || check "PO: shipment prepared (got $SHP_STATUS)" 1
post "$JAR_MGR2" "/shipments.php" "action=approve_shipment&shipment_id=$SHP_ID&decision=approve"
SHP_DONE=$($MYSQL -u root -N -e "SELECT status FROM factory_db.shipment_orders WHERE id=$SHP_ID;" 2>/dev/null | tr -d '\r')
[ "$SHP_DONE" == "Dispatched" ] && check "MGR: shipment approved and dispatched" 0 || check "MGR: shipment approved and dispatched (got $SHP_DONE)" 1

# --- corrections: request -> CEO approve -> scoped apply ---
ELEC_ID=$($MYSQL -u root -N -e "SELECT id FROM factory_db.electricity_readings ORDER BY id DESC LIMIT 1;" 2>/dev/null | tr -d '\r')
post /tmp/uepms_sup.txt "/corrections.php" "action=request_correction&entity_type=electricity_reading&entity_id=$ELEC_ID&correction_type=edit&reason=E2E+correction+test"
COR_ID=$($MYSQL -u root -N -e "SELECT id FROM factory_db.correction_requests WHERE reason='E2E correction test' ORDER BY id DESC LIMIT 1;" 2>/dev/null | tr -d '\r')
post "$JAR_CEO2" "/corrections.php" "action=ceo_decision&request_id=$COR_ID&decision=approve&notes=E2E"
post /tmp/uepms_sup.txt "/corrections.php" "action=apply_correction&request_id=$COR_ID&field_notes=E2E+corrected+$(date +%s)"
COR_DONE=$($MYSQL -u root -N -e "SELECT status FROM factory_db.correction_requests WHERE id=$COR_ID;" 2>/dev/null | tr -d '\r')
[ "$COR_DONE" == "Applied" ] && check "SUP: correction applied after CEO approval" 0 || check "SUP: correction applied after CEO approval (got $COR_DONE)" 1

# --- chatbot role scoping ---
CB_CEO=$(curl -s -b "$JAR_CEO2" -H "Content-Type: application/json" -d '{"message":"overview"}' "$BASE/api_chatbot.php")
echo "$CB_CEO" | grep -q "glance\|Money\|procurement" && check "CEO: chatbot gives full overview" 0 || check "CEO: chatbot gives full overview" 1
CB_SUP=$(curl -s -b /tmp/uepms_sup.txt -H "Content-Type: application/json" -d '{"message":"how much revenue did we make"}' "$BASE/api_chatbot.php")
echo "$CB_SUP" | grep -qi "attention\|notification\|caught up" && ! echo "$CB_SUP" | grep -qi "revenue is\|TZS" && check "SUP: chatbot refuses money data, gives reminders only" 0 || check "SUP: chatbot refuses money data, gives reminders only" 1

# --- biometric device endpoint ---
DEV_KEY=$(grep '^BIOMETRIC_DEVICE_KEY=' .env | cut -d= -f2)
BIO=$(curl -s -H "Content-Type: application/json" -d "{\"device_key\":\"$DEV_KEY\",\"staff_no\":\"WK-014\",\"action\":\"clock\"}" "$BASE/api_biometric.php")
echo "$BIO" | grep -q '"ok":true' && check "BIOMETRIC: device clock event accepted" 0 || check "BIOMETRIC: device clock event accepted" 1
BIO_BAD=$(curl -s -H "Content-Type: application/json" -d '{"device_key":"wrong","staff_no":"WK-014","action":"clock"}' "$BASE/api_biometric.php")
echo "$BIO_BAD" | grep -q 'Invalid device key' && check "BIOMETRIC: wrong device key rejected" 0 || check "BIOMETRIC: wrong device key rejected" 1

# --- v2.3 access matrix: Accountant blocked from production; CEO view-only inventory ---
get_page "$JAR_ACC2" "/production.php" | grep -q "403 Forbidden" && check "ACC: production module forbidden (v2.3)" 0 || check "ACC: production module forbidden (v2.3)" 1
curl -s -b "$JAR_CEO2" "$BASE/inventory.php" | grep -q "Add Inventory Item" && check "CEO: inventory is view-only" 1 || check "CEO: inventory is view-only" 0
curl -s -b "$JAR_MGR2" "$BASE/inventory.php" | grep -q "Approve" && check "MGR: can act on material requests in inventory" 0 || check "MGR: can act on material requests in inventory" 1

# cleanup v2.3 test rows
$MYSQL -u root -e "DELETE FROM factory_db.procurement_entries WHERE supplier='V23 Chain Co'; DELETE FROM factory_db.daily_reports WHERE supervisor_notes='E2Ev23' OR supervisor_notes='DEBUGV23'; DELETE FROM factory_db.inventory_requests WHERE purpose='E2E'; DELETE FROM factory_db.cash_requests WHERE purpose='E2E cash chain'; DELETE FROM factory_db.shipment_orders WHERE destination IN ('E2E Market','Debug Market'); DELETE FROM factory_db.correction_requests WHERE reason='E2E correction test';" 2>/dev/null

echo ""
echo "=============================="
echo "RESULTS: $PASS passed, $FAIL failed"
echo "=============================="
[ "$FAIL" == "0" ]
