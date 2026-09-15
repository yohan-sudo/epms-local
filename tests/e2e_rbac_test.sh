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

echo "=== 1. Booting PHP built-in server on :$PORT ==="
PHP_CLI_SERVER_WORKERS=4 "$PHP_BIN" -S "127.0.0.1:$PORT" router.php >/tmp/uepms_server.log 2>&1 &
SERVER_PID=$!
for i in $(seq 1 30); do
  if curl -s -o /dev/null "$BASE/index.php"; then break; fi
  sleep 0.5
done
curl -s -o /dev/null "$BASE/index.php" && echo "Server is up." || { echo "Server failed to start"; exit 1; }

echo ""
echo "=== 2. Procurement Officer (GLORIA) ==="
JAR_PROC=$(login_as gloria_mgassa)
BODY=$(get_page "$JAR_PROC" "/procurement.php")
echo "$BODY" | grep -q "Purchase Requisitions" && check "PO: requisition portal loads" 0 || check "PO: requisition portal loads" 1
echo "$BODY" | grep -q "Approve Requisition\|Inspect" && check "PO: can view/approve requisitions" 0 || check "PO: can view/approve requisitions" 1
# Try to draft a requisition - must be rejected
post "$JAR_PROC" "/procurement.php" "action=create_request&supplier=X&item_name=Y&quantity=1&unit_cost=1"
BODY=$(get_page "$JAR_PROC" "/procurement.php")
echo "$BODY" | grep -q "not permitted" && check "PO: drafting blocked (view & approve only)" 0 || check "PO: drafting blocked (view & approve only)" 1
# Dashboard access must redirect to procurement
CODE=$(curl -s -b "$JAR_PROC" -o /dev/null -w "%{http_code}" "$BASE/dashboard.php")
curl -s -b "$JAR_PROC" -o /dev/null -w "%{redirect_url}" "$BASE/dashboard.php" | grep -q "procurement" && check "PO: dashboard redirects to portal" 0 || check "PO: dashboard redirects to portal (code=$CODE)" 1
# Users page must be forbidden
curl -s -b "$JAR_PROC" "$BASE/users.php" | grep -q "403 Forbidden" && check "PO: users module forbidden" 0 || check "PO: users module forbidden" 1
# Audit trail must be forbidden
curl -s -b "$JAR_PROC" "$BASE/audit_logs.php" | grep -q "403 Forbidden" && check "PO: audit trail forbidden" 0 || check "PO: audit trail forbidden" 1
# Petty cash must be forbidden (no expense/float authority)
curl -s -b "$JAR_PROC" "$BASE/petty_cash.php" | grep -q "403 Forbidden" && check "PO: petty cash forbidden" 0 || check "PO: petty cash forbidden" 1

echo ""
echo "=== 3. Accountant (SWAUMU) ==="
JAR_ACC=$(login_as swaumu_mkomwa)
BODY=$(get_page "$JAR_ACC" "/petty_cash.php")
echo "$BODY" | grep -q "Petty Cash" && check "ACC: petty cash module loads" 0 || check "ACC: petty cash module loads" 1
echo "$BODY" | grep -q "Record Expense" && check "ACC: can record expenses" 0 || check "ACC: can record expenses" 1
echo "$BODY" | grep -q "+ Issue New Float" && check "ACC: NO issue-float button (should be absent)" 1 || check "ACC: NO issue-float button (should be absent)" 0
echo "$BODY" | grep -q 'value="issue_float"' && check "ACC: NO issue-float form (should be absent)" 1 || check "ACC: NO issue-float form (should be absent)" 0
# Try issuing a float anyway - must be denied server-side
post "$JAR_ACC" "/petty_cash.php" "action=issue_float&issued_to=3&amount=1000&purpose=test"
BODY=$(get_page "$JAR_ACC" "/petty_cash.php")
echo "$BODY" | grep -q "cannot issue new floats" && check "ACC: server blocks float issuance" 0 || check "ACC: server blocks float issuance" 1
# Audit trail forbidden
curl -s -b "$JAR_ACC" "$BASE/audit_logs.php" | grep -q "403 Forbidden" && check "ACC: audit trail forbidden" 0 || check "ACC: audit trail forbidden" 1
# Users forbidden
curl -s -b "$JAR_ACC" "$BASE/users.php" | grep -q "403 Forbidden" && check "ACC: users module forbidden" 0 || check "ACC: users module forbidden" 1
# Dashboard must NOT contain audit table
curl -s -b "$JAR_ACC" "$BASE/dashboard.php" | grep -q "System Activity" && check "ACC: dashboard hides audit trail" 1 || check "ACC: dashboard hides audit trail" 0
# Procurement portal forbidden
curl -s -b "$JAR_ACC" "$BASE/procurement.php" | grep -q "403 Forbidden" && check "ACC: procurement portal forbidden" 0 || check "ACC: procurement portal forbidden" 1
# Record a real expense (within float balance)
post "$JAR_ACC" "/petty_cash.php" "action=record_expense&issuance_id=1&expense_date=2026-03-15&category=Shop+Consumables&description=Test+expense&amount=1000&receipt_no=REC-TEST1"
BODY=$(get_page "$JAR_ACC" "/petty_cash.php")
echo "$BODY" | grep -q "Test expense" && check "ACC: expense recorded successfully" 0 || check "ACC: expense recorded successfully" 1

echo ""
echo "=== 4. Admin (BRIGHTON) ==="
JAR_ADM=$(login_as brighton_mmari)
BODY=$(get_page "$JAR_ADM" "/users.php")
echo "$BODY" | grep -q "Personnel Registry" && check "ADM: users module loads" 0 || check "ADM: users module loads" 1
echo "$BODY" | grep -q "Delete" && check "ADM: has Delete button" 0 || check "ADM: has Delete button" 1
curl -s -b "$JAR_ADM" "$BASE/audit_logs.php" | grep -q "Immutable System Audit Trail" && check "ADM: audit trail loads" 0 || check "ADM: audit trail loads" 1
# Issue a float - allowed for Admin
post "$JAR_ADM" "/petty_cash.php" "action=issue_float&issued_to=3&amount=250000&purpose=Admin+test+float&issued_date=2026-03-15"
BODY=$(get_page "$JAR_ADM" "/petty_cash.php")
echo "$BODY" | grep -q "Admin test float" && check "ADM: float issued successfully" 0 || check "ADM: float issued successfully" 1
# Create + delete a scratch user
post "$JAR_ADM" "/users.php" "action=create_user&name=Scratch+User&username=scratch_user&role=Manager&password=factory123"
SCRATCH_ROW=$(get_page "$JAR_ADM" "/users.php" | grep -B8 "scratch_user" | grep -o '#[0-9]*' | head -1 | tr -d '#')
post "$JAR_ADM" "/users.php" "action=delete_user&target_user_id=$SCRATCH_ROW"
BODY=$(get_page "$JAR_ADM" "/users.php")
echo "$BODY" | grep -q "scratch_user" && check "ADM: user hard-deleted" 1 || check "ADM: user hard-deleted" 0

echo ""
echo "=== 5. System Operator (GIMENO) ==="
JAR_OPS=$(login_as gimeno)
BODY=$(get_page "$JAR_OPS" "/dashboard.php")
echo "$BODY" | grep -q "System Activity" && check "OPS: dashboard shows audit trail" 0 || check "OPS: dashboard shows audit trail" 1
curl -s -b "$JAR_OPS" "$BASE/audit_logs.php" | grep -q "Immutable System Audit Trail" && check "OPS: audit trail loads" 0 || check "OPS: audit trail loads" 1
BODY=$(get_page "$JAR_OPS" "/users.php")
echo "$BODY" | grep -q "Personnel Registry" && check "OPS: users module loads" 0 || check "OPS: users module loads" 1
echo "$BODY" | grep -q "Delete" && check "OPS: has Delete button" 0 || check "OPS: has Delete button" 1
echo "$BODY" | grep -q "Ban" && check "OPS: has Ban button" 0 || check "OPS: has Ban button" 1
# Settings must be read-only (no add-equipment form)
curl -s -b "$JAR_OPS" "$BASE/settings.php" | grep -q "Operator Inspection Mode" && check "OPS: settings is read-only" 0 || check "OPS: settings is read-only" 1
# Production posting blocked
post "$JAR_OPS" "/production.php" "action=save_shift_report&machine_id=101&units_produced=10&good_units=9"
BODY=$(get_page "$JAR_OPS" "/production.php")
echo "$BODY" | grep -q "read-only" && check "OPS: production writes blocked" 0 || check "OPS: production writes blocked" 1
# Petty cash write blocked
post "$JAR_OPS" "/petty_cash.php" "action=record_expense&issuance_id=1&amount=100&description=x"
BODY=$(get_page "$JAR_OPS" "/petty_cash.php")
echo "$BODY" | grep -q "read-only" && check "OPS: petty cash writes blocked" 0 || check "OPS: petty cash writes blocked" 1
# Operator CAN create users (administration data)
post "$JAR_OPS" "/users.php" "action=create_user&name=Operator+Made&username=op_made&role=Manager&password=factory123"
BODY=$(get_page "$JAR_OPS" "/users.php")
echo "$BODY" | grep -q "op_made" && check "OPS: can create users (administration data)" 0 || check "OPS: can create users (administration data)" 1
# Clean up scratch operator user
OP_ROW=$(get_page "$JAR_OPS" "/users.php" | grep -B8 "op_made" | grep -o '#[0-9]*' | head -1 | tr -d '#')
post "$JAR_OPS" "/users.php" "action=delete_user&target_user_id=$OP_ROW"
BODY=$(get_page "$JAR_OPS" "/users.php")
echo "$BODY" | grep -q "op_made" && check "OPS: user hard-deleted" 1 || check "OPS: user hard-deleted" 0

echo ""
echo "=== 6. Banned user rejected ==="
JAR_BAN="/tmp/uepms_cookies_victor_diaz.txt"
rm -f "$JAR_BAN"
curl -s -c "$JAR_BAN" -d "username=victor_diaz&password=factory123" "$BASE/index.php" -o /dev/null
BODY=$(get_page "$JAR_BAN" "/index.php")
echo "$BODY" | grep -q "Account Suspended\|suspended/banned\|Access denied" && check "Banned login rejected" 0 || check "Banned login rejected" 1

echo ""
echo "=== 7. TZS currency ==="
JAR2=$(login_as brighton_mmari)
get_page "$JAR2" "/petty_cash.php" | grep -q "TZS" && check "Currency shows TZS" 0 || check "Currency shows TZS" 1

echo ""
echo "=============================="
echo "RESULTS: $PASS passed, $FAIL failed"
echo "=============================="
[ "$FAIL" == "0" ]
