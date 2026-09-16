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
post "$JAR_PROC" "/procurement.php" "action=create_request&supplier=E2E+Supply+Co&item_name=E2E+Bristles&category=Raw+Material&quantity=10&unit=Bags&unit_cost=5000&req_date=$(date +%Y-%m-%d)"
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
# Petty cash must be forbidden
curl -s -b "$JAR_PROC" "$BASE/petty_cash.php" | grep -q "403 Forbidden" && check "PO: petty cash forbidden" 0 || check "PO: petty cash forbidden" 1

echo ""
echo "=== 3. Manager (GLORY) first approval ==="
JAR_MGR=$(login_as glory_george)
BODY=$(get_page "$JAR_MGR" "/dashboard.php")
echo "$BODY" | grep -q "Procurement Awaiting Your Approval" && check "MGR: dashboard shows approval worklist" 0 || check "MGR: dashboard shows approval worklist" 1
# Manager tries to create a record - must be blocked
post "$JAR_MGR" "/procurement.php" "action=create_request&supplier=Mgr+Co&item_name=Nope&category=Tooling&quantity=1&unit=Sets&unit_cost=10&req_date=$(date +%Y-%m-%d)"
BODY=$(get_page "$JAR_MGR" "/procurement.php")
echo "$BODY" | grep -q "reserved for the Procurement Officer" && check "MGR: submitting blocked (Officer-only)" 0 || check "MGR: submitting blocked (Officer-only)" 1
# Manager approves the E2E record -> moves to 'Pending Accountant Review'
post "$JAR_MGR" "/procurement.php" "action=manager_decision&po_id=$E2E_ID&decision=approve&notes=E2E+manager+approval"
STATUS=$($MYSQL -u root -N -e "SELECT status FROM factory_db.procurement_entries WHERE id=$E2E_ID;" 2>/dev/null | tr -d '\r')
[ "$STATUS" == "Pending Accountant Review" ] && check "MGR: approval forwards to Accountant" 0 || check "MGR: approval forwards to Accountant (got $STATUS)" 1

echo ""
echo "=== 4. Accountant (SWAUMU) final approval ==="
JAR_ACC=$(login_as swaumu_mkomwa)
BODY=$(get_page "$JAR_ACC" "/dashboard.php")
echo "$BODY" | grep -q "Procurement Awaiting Your Final Approval" && check "ACC: dashboard shows final-approval worklist" 0 || check "ACC: dashboard shows final-approval worklist" 1
# Accountant petty cash module loads; cannot issue floats
BODY=$(get_page "$JAR_ACC" "/petty_cash.php")
echo "$BODY" | grep -q "Petty Cash" && check "ACC: petty cash module loads" 0 || check "ACC: petty cash module loads" 1
echo "$BODY" | grep -q "+ Issue New Float" && check "ACC: NO issue-float button (should be absent)" 1 || check "ACC: NO issue-float button (should be absent)" 0
# Accountant approves -> Finalized & locked
post "$JAR_ACC" "/procurement.php" "action=accountant_decision&po_id=$E2E_ID&decision=approve&notes=E2E+final+approval"
STATUS=$($MYSQL -u root -N -e "SELECT status FROM factory_db.procurement_entries WHERE id=$E2E_ID;" 2>/dev/null | tr -d '\r')
[ "$STATUS" == "Finalized" ] && check "ACC: final approval -> Finalized" 0 || check "ACC: final approval -> Finalized (got $STATUS)" 1
# A second decision on the locked record must be refused
post "$JAR_ACC" "/procurement.php" "action=accountant_decision&po_id=$E2E_ID&decision=reject&notes=try+again"
STATUS=$($MYSQL -u root -N -e "SELECT status FROM factory_db.procurement_entries WHERE id=$E2E_ID;" 2>/dev/null | tr -d '\r')
[ "$STATUS" == "Finalized" ] && check "ACC: closed record is locked (immutable)" 0 || check "ACC: closed record is locked (got $STATUS)" 1
# Accountant cannot finalize a record that has not reached their stage
SKIP_ID=$($MYSQL -u root -N -e "SELECT id FROM factory_db.procurement_entries WHERE status='Pending Manager Review' LIMIT 1;" 2>/dev/null | tr -d '\r')
if [ -n "$SKIP_ID" ]; then
  post "$JAR_ACC" "/procurement.php" "action=accountant_decision&po_id=$SKIP_ID&decision=approve&notes=skip+manager"
  STATUS=$($MYSQL -u root -N -e "SELECT status FROM factory_db.procurement_entries WHERE id=$SKIP_ID;" 2>/dev/null | tr -d '\r')
  [ "$STATUS" == "Pending Manager Review" ] && check "ACC: cannot skip the Manager stage" 0 || check "ACC: cannot skip the Manager stage (got $STATUS)" 1
fi
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
# Create + delete a scratch user
post "$JAR_CEO" "/users.php" "action=create_user&name=Scratch+User&username=scratch_user&role=Manager&password=factory123"
SCRATCH_ROW=$(get_page "$JAR_CEO" "/users.php" | grep -B8 "scratch_user" | grep -o '#[0-9]*' | head -1 | tr -d '#')
post "$JAR_CEO" "/users.php" "action=delete_user&target_user_id=$SCRATCH_ROW"
BODY=$(get_page "$JAR_CEO" "/users.php")
echo "$BODY" | grep -q "scratch_user" && check "CEO: user hard-deleted" 1 || check "CEO: user hard-deleted" 0
# Create-user form must NOT offer System Operator or Admin
BODY=$(get_page "$JAR_CEO" "/users.php?action=new")
echo "$BODY" | grep -q "System Operator" && check "CEO: no System Operator role anywhere" 1 || check "CEO: no System Operator role anywhere" 0
echo "$BODY" | grep -q '"Admin"' && check "CEO: no Admin role in create form" 1 || check "CEO: no Admin role in create form" 0
echo "$BODY" | grep -q "CEO (Owner" && check "CEO: CEO role offered in create form" 0 || check "CEO: CEO role offered in create form" 1

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
echo "=============================="
echo "RESULTS: $PASS passed, $FAIL failed"
echo "=============================="
[ "$FAIL" == "0" ]
