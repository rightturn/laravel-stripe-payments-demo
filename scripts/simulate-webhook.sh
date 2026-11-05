#!/usr/bin/env bash
# Plays Stripe's part: sends a correctly SIGNED webhook to the app.
#
#   scripts/simulate-webhook.sh <order-id> [event-id] [amount-cents]
#
# Environment: BASE_URL (default http://localhost:8000), STRIPE_WEBHOOK_SECRET (default whsec_demo)
set -euo pipefail

ORDER_ID="${1:?usage: $0 <order-id> [event-id] [amount-cents]}"
EVENT_ID="${2:-evt_$(date +%s%N)}"
AMOUNT="${3:-4900}"
BASE_URL="${BASE_URL:-http://localhost:8000}"
SECRET="${STRIPE_WEBHOOK_SECRET:-whsec_demo}"

PAYLOAD=$(printf '{"id":"%s","object":"event","type":"checkout.session.completed","data":{"object":{"id":"cs_local_%s","object":"checkout.session","payment_status":"paid","amount_total":%s,"currency":"usd","payment_intent":"pi_%s","client_reference_id":"%s","metadata":{"order_id":"%s"}}}}' \
  "$EVENT_ID" "$ORDER_ID" "$AMOUNT" "$ORDER_ID" "$ORDER_ID" "$ORDER_ID")
TS=$(date +%s)
SIG=$(printf '%s.%s' "$TS" "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" -hex | sed 's/^.* //')

curl -sS -X POST "$BASE_URL/api/stripe/webhook" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H "Stripe-Signature: t=$TS,v1=$SIG" \
  --data "$PAYLOAD"
echo
