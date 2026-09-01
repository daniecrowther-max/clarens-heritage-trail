#!/usr/bin/env bash
# Regression suite for the 26 Aug 2026 security-audit remediation.
#
#   tests/test-checkout.php      item 2 (/checkout rate limiting)
#                                item 3 (no payment URL without a purchase row)
#   tests/test-redeem-stock.php  item 4 (concurrency-safe voucher stock)
#   tests/test-webhook-idempotency.php  webhook idempotency (open item, added 26 Aug 2026)
#   tests/test-app-browser.js    item 1 (server-confirmed redemption only)
#                                item 5 (stored-XSS escaping of feed data)
#
# Requires: php CLI, node, and a Chrome/Chromium binary. No WordPress, no
# database and no network access to the live site — the PHP tests stub WP and
# $wpdb, and the browser tests run against tests/mock-api-server.js.
set -u
cd "$(dirname "$0")/.."

fail=0
for t in tests/test-checkout.php tests/test-redeem-stock.php tests/test-webhook-idempotency.php; do
  echo "=== $t ==="
  php "$t" || fail=1
done

echo "=== tests/test-app-browser.js ==="
node tests/test-app-browser.js || fail=1

echo
if [ "$fail" -eq 0 ]; then
  echo "ALL SUITES PASSED"
else
  echo "SUITE FAILURES — see above"
fi
exit "$fail"
