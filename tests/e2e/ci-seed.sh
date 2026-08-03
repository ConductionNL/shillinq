#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Shillinq Contributors
# SPDX-License-Identifier: EUPL-1.2
#
# Provision Shillinq's OpenRegister register + schemas on a freshly installed
# Nextcloud, for the shared `E2E Tests (Playwright)` CI job.
#
# Wired up as the workflow's `playwright-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud server root, so this is
# invoked as:
#
#     playwright-seed-command: 'bash apps/shillinq/tests/e2e/ci-seed.sh'
#
# WHY THIS IS NEEDED
# ------------------
# `occ app:enable shillinq` runs the post-migration repair step, which is
# supposed to import `lib/Settings/shillinq_register.json` (plus the 152
# `lib/Settings/register.d/*.json` fragments it merges) into OpenRegister. That
# is not a reliable fresh-install path, and it fails SILENTLY:
#
#   1. An IRepairStep runs with NO user session. OpenRegister's RBAC evaluates
#      the acting user, so the import is denied outright with
#      "User 'Anonymous' does not have permission to 'create' objects in schema
#      '…'". `InitializeSettings::run()` catches `\Throwable` and downgrades it
#      to a warning, so `occ app:enable shillinq` still exits 0. (The shared
#      workflow additionally downgrades an `occ app:enable` failure to
#      `::warning::`, so there are two layers of silence over the same event.)
#   2. The repair step calls the NON-forced `loadConfiguration()`. That path is
#      version-guarded and can advance the recorded configuration version
#      WITHOUT applying the register, after which a second run sees "already
#      current" and does nothing either.
#
# Either way the app enables cleanly, the SPA boots, and the register simply is
# not there. The e2e suite's failure mode in that state is not a clear message:
# `tests/e2e/workflows/_fixtures.ts::missingSchema()` reports the register as
# absent and the finance specs skip themselves, while every UI spec times out on
# a selector — accusing the selectors, not the missing import.
#
# So this script does the import EXPLICITLY over the admin HTTP API (which has a
# real session and passes RBAC), forced, and then VERIFIES the register and a
# representative set of schemas actually exist. A failed provision becomes ONE
# loud step failure here instead of dozens of misleading spec failures later.
#
# It is idempotent: the import is idempotent server-side and re-running only
# re-verifies.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"

# ── Target resolution ────────────────────────────────────────────────────────
# The shared workflow's "Seed test data" step exports BASE_URL / NEXTCLOUD_URL /
# NC_BASE_URL / ADMIN_USER / ADMIN_PASSWORD / NC_ADMIN_USER / NC_ADMIN_PASS.
# Accept all of them, and fall back to the CI runner's own
# `php -S 0.0.0.0:8080` only when actually running on CI.
#
# On a developer box `localhost:8080` is the SHARED dev container, and this
# script performs ADMIN WRITES — it must never silently import a 493-schema
# register into somebody else's environment. Off CI, an unset target is a hard
# error. (Same rule, same reasoning, as tests/e2e/base-url.ts.)
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"
if [ -z "$BASE" ]; then
	if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export PLAYWRIGHT_BASE_URL or BASE_URL." >&2
		echo "       Refusing to default to http://localhost:8080 outside CI —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

USER_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
USER_PASS="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"

echo "[ci-seed] target:  ${BASE}"
echo "[ci-seed] app dir: ${APP_DIR}"

# ── 1. Import the Shillinq configuration ─────────────────────────────────────
# Shillinq's `appinfo/routes.php` returns
# `\OCA\OpenRegister\AppHost\Routes::standard()`, whose canonical table ships
# `settings#load` at POST /api/settings/load. On shillinq that name resolves to
# OCA\Shillinq\Controller\SettingsController::load(), which calls
# `SettingsService::loadConfigurationForced()` — precisely the forced import the
# repair step cannot perform, and the only path that also merges every
# `lib/Settings/register.d/*.json` fragment. It carries
# `#[AuthorizedAdminSetting(Application::APP_ID)]`, so HTTP Basic as admin is
# required.
#
# `OCS-APIRequest: true` is load-bearing, not decoration: the method carries no
# #[NoCSRFRequired], and Nextcloud's Request::passesCSRFCheck() short-circuits
# to true on that header (the strict-cookie precondition is satisfied because a
# Basic-auth request carries no session cookie at all). Without the header this
# POST is rejected as a CSRF failure.
IMPORT_URL="${BASE}/index.php/apps/shillinq/api/settings/load"
echo "[ci-seed] POST ${IMPORT_URL} (forced import)"

IMPORT_BODY="$(mktemp)"
IMPORT_CODE="$(
	curl -sS -o "$IMPORT_BODY" -w '%{http_code}' \
		--max-time 900 \
		-u "${USER_NAME}:${USER_PASS}" \
		-X POST \
		-H 'Content-Type: application/json' \
		-H 'OCS-APIRequest: true' \
		--data '{}' \
		"$IMPORT_URL" || echo 000
)"

echo "[ci-seed] settings#load HTTP ${IMPORT_CODE}"
head -c 3000 "$IMPORT_BODY"; echo

# HTTP 200 is necessary but NOT sufficient: SettingsController::load() returns
# `{"success": false, "message": "..."}` with a 200 when the import itself
# failed. Treat anything that is not an explicit success as a reason to try the
# generic importer below, and let the verification step decide the outcome.
IMPORT_OK=0
if [ "$IMPORT_CODE" = "200" ] && grep -q '"success":[[:space:]]*true' "$IMPORT_BODY"; then
	IMPORT_OK=1
	echo "[ci-seed] shillinq settings#load reported success."
else
	echo "[ci-seed] shillinq settings#load did not report success; falling back to the OpenRegister importer."
fi

# ── 1b. Fallback: OpenRegister's generic configuration importer ──────────────
# Independent of shillinq's own controller wiring, so it still provisions the
# register if `settings#load` is unavailable (e.g. an OpenRegister build whose
# AppHost route table predates `settings#load`). Admin-only. It reads the upload
# under the literal form key `file`; a raw JSON body is NOT one of its accepted
# shapes.
#
# ⚠️ This fallback posts the MONOLITH ONLY. It cannot merge the 152
# `register.d/*.json` fragments — that merge lives in
# SettingsService::loadRegisterConfigData(), server-side. So a run that lands
# here provisions ~101 of the 493 schemas and the verification below will name
# whichever required slug is missing. That is the intended outcome: a partial
# provision must be visibly partial, not quietly accepted.
if [ "$IMPORT_OK" != "1" ]; then
	REGISTER_JSON="${APP_DIR}/lib/Settings/shillinq_register.json"
	if [ ! -f "$REGISTER_JSON" ]; then
		echo "::error::shillinq_register.json not found at ${REGISTER_JSON}."
		exit 1
	fi

	OR_URL="${BASE}/index.php/apps/openregister/api/configurations/import"
	echo "[ci-seed] POST ${OR_URL} (file=shillinq_register.json, force=true)"
	OR_BODY="$(mktemp)"
	OR_CODE="$(
		curl -sS -o "$OR_BODY" -w '%{http_code}' \
			--max-time 900 \
			-u "${USER_NAME}:${USER_PASS}" \
			-X POST \
			-H 'OCS-APIRequest: true' \
			-F "file=@${REGISTER_JSON}" \
			-F 'force=true' \
			-F 'appId=shillinq' \
			"$OR_URL" || echo 000
	)"
	echo "[ci-seed] configurations/import HTTP ${OR_CODE}"
	head -c 3000 "$OR_BODY"; echo
fi

# ── 2. Verify the register and schemas are actually there ────────────────────
# An import reporting success is not the same as the register existing.
#
# ⚠️ THE SLUGS BELOW ARE PascalCase ON PURPOSE.
# OpenRegister resolves a URL segment via LOWER(slug), so casing is not what
# breaks a lookup — STRUCTURE is (a hyphen where the slug has none, a prefix, a
# kebab-cased rewrite). Shillinq declares 493 schemas and every one of their
# `slug` values is PascalCase, byte-for-byte the schema name: `LeaseContract`,
# `EuVatRate`, `AdministrationMembership`, `GLLine`. They are NOT kebab-cased
# anywhere in `lib/Settings/**`, and rewriting them here would manufacture a
# false "missing schema" failure. Each name below was read out of the repo's own
# register JSON, not guessed:
#
#   Account, GLTransaction, GLLine, BankStatement  lib/Settings/shillinq_register.json
#   Administration, AdministrationMembership       register.d/bookkeeping-multi-administratie.json
#   LeaseContract                                  register.d/bookkeeping-ifrs-16-lease.json
#   EuVatRate                                      register.d/bookkeeping-btw-oss-eu.json
#   Booking                                        register.d/bookings-resource-calendar.json
#
# They are also exactly the set `tests/e2e/workflows/*.spec.ts` passes to
# `OrFixtures.missingSchema()` (plus two spot-checks from the monolith and one
# from the bookings module), so this gate proves precisely what the deep
# finance/bookings specs need in order to run for real rather than skip.
#
# The HTTP status is captured and checked SEPARATELY from the payload on
# purpose: an endpoint that 404s or redirects to the login form yields an empty
# slug set, which is indistinguishable from "the import produced nothing" if you
# only look at the parsed list. A wrong lookup manufactures an absence for free,
# so the two are reported as different errors.
verify() {
	python3 - "$1" "$2" "$3" <<'PY'
import json, sys
path, kind, code = sys.argv[1], sys.argv[2], sys.argv[3]
required = {
    'registers': ['shillinq'],
    'schemas': [
        'Account', 'GLTransaction', 'GLLine', 'BankStatement',
        'Administration', 'AdministrationMembership',
        'LeaseContract', 'EuVatRate', 'Booking',
    ],
}[kind]
with open(path) as fh:
    raw = fh.read()
if code != '200':
    print(f'::error::OpenRegister {kind} endpoint returned HTTP {code}, so the '
          f'slug list below proves nothing about the import. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print(f'::error::{kind} endpoint did not return JSON (HTTP 200). First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
items = body if isinstance(body, list) else body.get('results', [])
slugs = {i.get('slug') for i in items if isinstance(i, dict)}
missing = [s for s in required if s not in slugs]
print(f'[ci-seed] {kind} present: {len(slugs)}')
if kind == 'registers':
    print(f'[ci-seed] register slugs: {sorted(s for s in slugs if s)}')
if missing:
    print(f'::error::Shillinq {kind} missing after import: {missing}')
    sample = sorted(s for s in slugs if s)[:40]
    print(f'::error::A sample of what IS present: {sample}')
    print('::error::The e2e finance/bookings workflows cannot seed anything without them.')
    sys.exit(1)
print(f'[ci-seed] {kind} OK ({len(required)} required slugs present)')
PY
}

REG_BODY="$(mktemp)"
REG_CODE="$(curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	-o "$REG_BODY" -w '%{http_code}' --max-time 300 \
	"${BASE}/index.php/apps/openregister/api/registers?_limit=300" || echo 000)"
verify "$REG_BODY" registers "$REG_CODE"

SCH_BODY="$(mktemp)"
SCH_CODE="$(curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	-o "$SCH_BODY" -w '%{http_code}' --max-time 300 \
	"${BASE}/index.php/apps/openregister/api/schemas?_limit=1000" || echo 000)"
verify "$SCH_BODY" schemas "$SCH_CODE"

# The register existing is still not the same as it being READABLE by the admin
# session the specs use. `workflows/_fixtures.ts` probes this collection shape
# and, on a 4xx, reports the register as absent and lets the finance specs skip
# — a green run that proved nothing. Probe it here so that failure mode has a
# name and a status code.
OBJ_CODE="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 300 \
	-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/objects/shillinq/Account?_limit=1" || echo 000)"
echo "[ci-seed] objects/shillinq/Account probe -> ${OBJ_CODE}"
if [ "$OBJ_CODE" -ge 400 ] 2>/dev/null; then
	echo "::error::The shillinq Account collection is not readable (HTTP ${OBJ_CODE})."
	echo "::error::tests/e2e/workflows/_fixtures.ts treats this as 'register absent' and skips."
	exit 1
fi

echo "[ci-seed] Shillinq register + schemas provisioned."

# ── 3. Warm the SPA so the first spec doesn't pay the cold start ─────────────
# The shared workflow serves Nextcloud with `php -S 0.0.0.0:8080`. It sets
# PHP_CLI_SERVER_WORKERS=8, but the first hit still pays a cold opcache and the
# first parse of a multi-megabyte webpack bundle, and that cost lands entirely on
# whichever spec happens to run first. Warming it here puts that cost in the
# environment-preparation step where it belongs, rather than inside an assertion
# timeout that would then have to keep drifting upward. This matters more here
# than elsewhere in the fleet: the config runs 5 workers, so five specs pay the
# cold start simultaneously.
#
# Failures are ignored on purpose: this is a warm-up, not a gate. The real
# checks are above and below.
for path in \
	"/index.php/apps/shillinq/" \
	"/index.php/apps/shillinq/api/settings" \
	"/index.php/settings/admin/shillinq" \
	"/index.php/apps/openregister/api/registers?_limit=1"
do
	code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 300 \
		-u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' "${BASE}${path}" || echo 000)"
	echo "[ci-seed] warm ${path} -> ${code}"
done

# Pull the main webpack bundle once so it is in the page cache.
#
# Do NOT hardcode the URL. Nextcloud serves an app's assets from whichever apps
# directory it was installed into — `/apps/shillinq/js/…` on the CI runner,
# `/custom_apps/shillinq/js/…` in the docker dev images — and asking for the
# wrong one does not 404. It returns **HTTP 200 with `text/html`**: the NC error
# page, served through index.php. A status-code check therefore reports success
# while fetching a 40 KB HTML page instead of a multi-MB bundle, so the warm-up
# silently warms nothing.
#
# Read the real src out of the rendered app page instead, and verify the
# response is actually JavaScript.
APP_HTML="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' --max-time 300 \
	"${BASE}/index.php/apps/shillinq/" -o "$APP_HTML" || true

# `|| true` is load-bearing: grep exits 1 when it matches nothing, and under
# `set -euo pipefail` that aborts the script right here — so the case the gate
# below exists to explain (no bundle) would die with a bare non-zero exit and
# none of the diagnosis. Let it fall through to the gate instead.
BUNDLE_SRC="$(grep -oE 'src="[^"]*shillinq-main[^"]*"' "$APP_HTML" \
	| head -1 | sed 's/^src="//; s/"$//' || true)"

if [ -n "$BUNDLE_SRC" ]; then
	BUNDLE_INFO="$(curl -sS -o /dev/null --max-time 300 \
		-w '%{http_code} %{content_type} %{size_download}' \
		-u "${USER_NAME}:${USER_PASS}" "${BASE}${BUNDLE_SRC}" || echo '000 - 0')"
	echo "[ci-seed] warm bundle ${BUNDLE_SRC} -> ${BUNDLE_INFO}"
else
	echo "[ci-seed] could not locate the bundle src in the rendered app page."
	BUNDLE_INFO=""
fi

# On CI this is a GATE, not a warm-up.
#
# The single most likely way this job "succeeds" dishonestly is by passing
# without ever loading the app — and the environment hides it well: when the
# bundle is absent, Nextcloud does not 404. It serves its HTML error page with
# **HTTP 200 and Content-Type text/html**, so a build producing nothing looks,
# to every status-code check in the pipeline, exactly like success.
#
# ⚠️ Note also that a DELETE-based control on this is defeated:
# `tests/e2e/global-setup.ts::ensureBundleBuilt()` does `fs.existsSync()` and
# runs `npm run build` when the file is gone. To prove this gate can fail,
# TRUNCATE the bundle (`: > js/shillinq-main.js`) — the file still exists, so
# nothing rebuilds it, and the served response is a 0-byte body.
#
# This gate reads the SERVED response, not the file on disk, and it sits at the
# very end so that a run which reaches the specs has provably been able to fetch
# real JavaScript for the SPA.
if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	case "$BUNDLE_INFO" in
		*javascript*)
			echo "[ci-seed] bundle verified as JavaScript."
			;;
		*)
			echo "::error::The Shillinq frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::Check the 'Build app frontend' step — a missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac
fi

echo "[ci-seed] done."
