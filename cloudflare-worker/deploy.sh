#!/usr/bin/env bash
# Deploy cha-marketing-pages Worker + Routes to clarensheritage.org
# Run this from your local machine (needs curl + jq).
# Usage: bash deploy.sh

set -euo pipefail

# Set these before running:
#   export CF_ACCOUNT_ID="091c57c5e8f696117761bb903a16b4bb"
#   export CF_API_TOKEN="<your-cloudflare-api-token>"
ACCOUNT_ID="${CF_ACCOUNT_ID:?CF_ACCOUNT_ID env var required}"
API_TOKEN="${CF_API_TOKEN:?CF_API_TOKEN env var required}"
WORKER_NAME="cha-marketing-pages"
SCRIPT_FILE="$(dirname "$0")/worker.js"
CF="https://api.cloudflare.com/client/v4"

echo "=== Step 1: Upload Worker script ==="
UPLOAD=$(curl -s -X PUT "$CF/accounts/$ACCOUNT_ID/workers/scripts/$WORKER_NAME" \
  -H "Authorization: Bearer $API_TOKEN" \
  -F "script=@$SCRIPT_FILE;type=application/javascript" \
  -F 'metadata={"main_module":false,"body_part":"script"};type=application/json')
echo "$UPLOAD" | python3 -m json.tool 2>/dev/null || echo "$UPLOAD"

SUCCESS=$(echo "$UPLOAD" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('success',''))" 2>/dev/null)
if [ "$SUCCESS" != "True" ] && [ "$SUCCESS" != "true" ]; then
  echo "ERROR: Worker upload failed. Aborting."
  exit 1
fi
echo "Worker uploaded successfully."

echo ""
echo "=== Step 2: Get Zone ID for clarensheritage.org ==="
ZONE_RESP=$(curl -s -X GET "$CF/zones?name=clarensheritage.org" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json")
ZONE_ID=$(echo "$ZONE_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['result'][0]['id'])" 2>/dev/null)
echo "Zone ID: $ZONE_ID"

if [ -z "$ZONE_ID" ]; then
  echo "ERROR: Could not retrieve zone ID. Check that the token has Zone.Workers Routes permission."
  exit 1
fi

echo ""
echo "=== Step 3: Add Workers Route — /trail/* ==="
ROUTE1=$(curl -s -X POST "$CF/zones/$ZONE_ID/workers/routes" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  --data "{\"pattern\":\"clarensheritage.org/trail/*\",\"script\":\"$WORKER_NAME\"}")
echo "$ROUTE1" | python3 -m json.tool 2>/dev/null || echo "$ROUTE1"

echo ""
echo "=== Step 4: Add Workers Route — /partners/* ==="
ROUTE2=$(curl -s -X POST "$CF/zones/$ZONE_ID/workers/routes" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  --data "{\"pattern\":\"clarensheritage.org/partners/*\",\"script\":\"$WORKER_NAME\"}")
echo "$ROUTE2" | python3 -m json.tool 2>/dev/null || echo "$ROUTE2"

echo ""
echo "=== Deployment complete ==="
echo "  Trail page   → https://clarensheritage.org/trail/"
echo "  Partner page → https://clarensheritage.org/partners/"
echo ""
echo "Test with:"
echo "  curl -I https://clarensheritage.org/trail/"
echo "  curl -I https://clarensheritage.org/partners/"
