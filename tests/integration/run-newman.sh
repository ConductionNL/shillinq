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
# Target instance:
#   There is deliberately NO default base URL. This runner PERFORMS WRITES —
#   every collection seeds objects, posts filings and tears them down again.
#   The previous default was `http://localhost:8080`, which on a developer box
#   is the *shared* Nextcloud dev container, so an invocation with no
#   environment set silently created and deleted fixtures in an instance other
#   sessions were using. Failing loudly on an unset variable is strictly better
#   than writing into someone else's environment.
#
#   The URL is resolved from the first of these that is set and non-empty:
#     PLAYWRIGHT_BASE_URL, BASE_URL, NEXTCLOUD_URL, NC_BASE_URL
#   `BASE_URL` is the name the shared ConductionNL/.github quality workflow
#   exports, so it MUST stay accepted: a resolver that honours only
#   PLAYWRIGHT_BASE_URL hard-fails every CI run.
#
# Usage:
#   BASE_URL=http://localhost:8097 ./run-newman.sh   # your own isolated instance
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

# Resolve the instance under test. No default: see the "Target instance" note
# above. Order matters only in that the most specific name wins; every one of
# them is honoured because different callers export different names (CI exports
# BASE_URL, the Playwright suites export PLAYWRIGHT_BASE_URL, the docker-compose
# helpers export NEXTCLOUD_URL / NC_BASE_URL).
BASE_URL="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"

if [ -z "${BASE_URL}" ]; then
  cat >&2 <<'EOF'
ERROR: no target instance configured.

None of PLAYWRIGHT_BASE_URL, BASE_URL, NEXTCLOUD_URL or NC_BASE_URL is set.

This runner deliberately has no default: it used to fall back to
http://localhost:8080, which is the SHARED dev container, and the collections
it runs create and delete objects. Pointing them at the shared instance
corrupts an environment other sessions are using.

Point it at your own isolated instance, e.g.
  BASE_URL=http://localhost:8097 ./run-newman.sh

In CI the shared quality workflow exports BASE_URL, which is also accepted;
if you are seeing this in CI, that export is missing.
EOF
  exit 2
fi

# Normalise away any trailing slashes so `${base_url}/index.php/...` in the
# collections never produces a double slash.
BASE_URL="${BASE_URL%"${BASE_URL##*[!/]}"}"

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
