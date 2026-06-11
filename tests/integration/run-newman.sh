#!/usr/bin/env bash
#
# Shillinq API-contract test runner (Newman / Postman).
#
# Runs every Postman collection under tests/integration/ against a live
# Nextcloud instance serving the shillinq app:
#   - shillinq.postman_collection.json            (core bookkeeping contract)
#   - bookings.postman_collection.json            (resource-calendar bookings)
#   - VAT_Filings.postman_collection.json         (BTW-aangifte / VAT filings)
#   - TenderNedIntegratie.postman_collection.json (TenderNed integration; this
#                                                  collection already resolves its
#                                                  OpenRegister register from the
#                                                  stable slug 'shillinq', so it is
#                                                  CI-durable across a re-import)
#
# Each collection is self-contained and idempotent: it seeds what it needs and
# tears it down. Collection variables are parameterised (base_url / admin_user /
# admin_password) so the suite is portable across environments.
#
# Usage:
#   ./run-newman.sh                                  # defaults to localhost:8080, admin:admin
#   BASE_URL=http://localhost:8080 ./run-newman.sh
#   ADMIN_USER=admin ADMIN_PASS=admin ./run-newman.sh
#
# Uses a globally-installed `newman` if present, otherwise falls back to
# `npx newman`. Runs are serialised via flock (when available) so concurrent
# CI agents do not trip the Nextcloud brute-force protection.
#
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

set -euo pipefail

# Re-exec under an exclusive flock so parallel agents serialise.
LOCK_FILE="/tmp/uiaudit-shillinq.lock"
if [ "${SHILLINQ_NEWMAN_LOCKED:-}" != "1" ] && command -v flock >/dev/null 2>&1; then
  export SHILLINQ_NEWMAN_LOCKED=1
  exec flock "${LOCK_FILE}" "$0" "$@"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

BASE_URL="${BASE_URL:-http://localhost:8080}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin}"

if command -v newman >/dev/null 2>&1; then
  NEWMAN=(newman)
else
  NEWMAN=(npx --yes newman)
fi

COLLECTIONS=(
  "shillinq.postman_collection.json"
  "bookings.postman_collection.json"
  "VAT_Filings.postman_collection.json"
  "TenderNedIntegratie.postman_collection.json"
)

rc=0
for collection in "${COLLECTIONS[@]}"; do
  path="${SCRIPT_DIR}/${collection}"
  if [ ! -f "${path}" ]; then
    echo "WARN: collection not found, skipping: ${collection}" >&2
    continue
  fi
  echo "==> newman run ${collection}"
  if ! "${NEWMAN[@]}" run "${path}" \
      --env-var "base_url=${BASE_URL}" \
      --env-var "admin_user=${ADMIN_USER}" \
      --env-var "admin_password=${ADMIN_PASS}" \
      --reporters cli \
      --color on \
      "$@"; then
    rc=1
  fi
done

exit "${rc}"
