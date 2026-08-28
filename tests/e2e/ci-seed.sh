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

# The Nextcloud server root, where `occ` lives. CI already runs with cwd set
# there, but derive it from the app's own location so the script also works
# when run from anywhere else — the same definition buildiq's ci-seed.sh uses.
SERVER_DIR="$(cd -- "${APP_DIR}/../.." && pwd)"

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

# ── 0. Make the CI instance emit PRETTY URLs ─────────────────────────────────
# The SPA builds its vue-router history base as
#
#     createWebHistory(generateUrl('/apps/shillinq'))        (src/main.js)
#
# and `generateUrl` from `@nextcloud/router` prefixes `/index.php` unless
# `OC.config.modRewriteWorking` is true. Nextcloud sets that flag from
# `htaccess.IgnoreFrontController` (lib/private/Template/JSConfigHelper.php —
# a STRICT `=== true`, so it must be stored as a real boolean).
#
# A default CI install leaves it false, so on the runner the router base is
# `/index.php/apps/shillinq` while every spec navigates to `/apps/shillinq/…`.
# The page still SERVES — the workflow's `php -S` front-controller router maps
# it — so nothing 404s and nothing errors. But vue-router sees a location that
# does not start with its base, falls through to main.js's
# `{ path: '/:pathMatch(.*)*', redirect: '/' }` catch-all, and lands on
# `/index.php/apps/shillinq/`. Every deep-linked spec then asserts against the
# DASHBOARD instead of its own page.
#
# That is not a hypothesis either. Run 30860327035 recorded it verbatim:
#
#     Expected substring: "/cashflow/dashboard"
#     Received string:    "http://localhost:8080/index.php/apps/shillinq/"
#
# — note the `/index.php` in a URL the spec never asked for. The truncated-
# bundle control run (30858387599) is the other half of the proof: with a
# 0-byte bundle the same assertion PASSES, because with no router there is no
# redirect. The redirect is the SPA's, and its base is the only thing that
# produces it.
#
# The docker dev containers this suite was authored against have mod_rewrite
# working, so the specs' `/apps/shillinq` literals are correct there. Aligning
# the CI instance is the same class of fix as pinning `additional-apps` to
# `development`: stop the CI instance behaving unlike every environment the app
# is actually developed against.
#
# Only attempted when `occ` is reachable, i.e. cwd is the Nextcloud server root
# (which is exactly how the workflow invokes this script).
if [ -f "./occ" ]; then
	echo "[ci-seed] enabling pretty URLs (htaccess.IgnoreFrontController=true)"
	php ./occ config:system:set htaccess.IgnoreFrontController --value=true --type=boolean || true
	# `tail -1` is load-bearing. On this instance `occ` prints an app-bootstrap
	# notice to STDOUT before the command's own output — run 30862592617
	# captured
	#
	#     Interface "OCP\ContextChat\IContentProvider" not found
	#     true
	#
	# as the value of this variable, and the gate below (correctly) rejected it
	# even though the preceding `config:system:set` had reported success. Take
	# the command's LAST line, which is the value; `2>/dev/null` does not help
	# because the notice is on stdout, not stderr.
	IFC="$(php ./occ config:system:get htaccess.IgnoreFrontController 2>/dev/null | tail -1 || echo '')"
	echo "[ci-seed] htaccess.IgnoreFrontController -> '${IFC}'"

	# GATE on the read-back. `config:system:set` can fail (read-only config,
	# wrong type coercion) and still exit 0 under `|| true`, and Nextcloud's
	# check is a STRICT `=== true` — a value stored as the STRING "true" reads
	# back identically here but leaves modRewriteWorking false. So require the
	# literal `true`, and say what breaks if it is not.
	if [ "$IFC" != "true" ]; then
		echo "::error::htaccess.IgnoreFrontController is '${IFC}', not 'true'."
		echo "::error::The SPA's vue-router base stays /index.php/apps/shillinq while the specs"
		echo "::error::navigate to /apps/shillinq/… — every deep link then falls through main.js's"
		echo "::error::'/:pathMatch(.*)*' catch-all and silently redirects to the dashboard, so the"
		echo "::error::specs assert against the wrong page while nothing 404s or errors."
		exit 1
	fi

	# Corroborate against a RENDERED page, because that is the value the SPA
	# actually consumes — `occ` only proves config.php was written. Non-fatal:
	# Nextcloud serves OC.config INLINE only when the client advertises CSP v3
	# support, and falls back to a separate `core.OCJS.getConfig` script for
	# everyone else. curl is in the "everyone else" bucket, so a miss here is
	# expected and must not abort the job; the gate above is the one that binds.
	CFG_HTML="$(mktemp)"
	curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' --max-time 120 \
		"${BASE}/index.php/apps/files/" -o "$CFG_HTML" || true
	if grep -q 'modRewriteWorking":true' "$CFG_HTML"; then
		echo "[ci-seed] modRewriteWorking is TRUE in the served page config."
	else
		echo "[ci-seed] (inline OC.config not present in the curl-rendered page; relying on the occ read-back)"
	fi
else
	echo "[ci-seed] no ./occ in $(pwd) — skipping the pretty-URL config step."
fi

# ── 0b. Disable Nextcloud's own first-run wizard ─────────────────────────────
# `firstrunwizard` is a STOCK Nextcloud app, enabled by default, and on a fresh
# profile it renders a full-viewport opaque modal over the SPA:
#
#     <div role="dialog" aria-modal="true" id="firstrunwizard"
#          class="first-run-wizard modal-mask modal-mask--opaque">
#
# It is the single largest cause of spec failure on a clean instance. Measured
# 2026-08-16 against a container serving current `development`, seeded by this
# script: of 90 `locator.click: Test timeout` failures, **84** logged
# `#firstrunwizard … subtree intercepts pointer events`.
#
# ⚠️ The symptom does NOT look like an overlay. Playwright reports the target as
# "visible, enabled and stable" and then retries the click until the test times
# out — so it presents as a dozen unrelated flaky-click defects, not one cause.
#
# 33 spec files carry a `dismissWizard()` helper for exactly this, but a helper
# that runs inside the test races the modal's own mount, and cannot help a spec
# whose first action is a click. Removing the app removes the race.
#
# Test-environment setup, not a product change: nothing in shillinq depends on
# `firstrunwizard`, and it is disabled only on the instance under test.
#
# Effect, nothing else changed between the runs:
#
#     three worst spec files   26 failed  ->  9 failed / 17 passed
#     FULL chromium suite      200 passed / 71 failed / 3 skipped
#                         ->   213 passed / 49 failed / 7 skipped
#
if [ -f "./occ" ]; then
	if php ./occ app:disable firstrunwizard >/dev/null 2>&1; then
		echo "[ci-seed] disabled Nextcloud's firstrunwizard (its modal intercepts pointer events)."
	else
		echo "[ci-seed] firstrunwizard not present or already disabled."
	fi
fi

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
	python3 - "$1" "$2" "$3" "$4" <<'PY'
import json, sys
path, kind, code = sys.argv[1], sys.argv[2], sys.argv[3]
limit = int(sys.argv[4]) if len(sys.argv) > 4 else 10**9
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

# A TRUNCATED PAGE MUST NOT READ AS "MISSING".
#
# This check asks for a bounded page and then concludes absence from what came
# back. If the instance holds MORE than that bound, the required slug can be on
# a page we never fetched and the check reports it missing when it is present.
#
# Observed on a shared dev instance carrying 407 registers against a `_limit`
# of 300: the probe declared `shillinq` missing while `?_limit=1000` returned
# it. The error text was confident and completely wrong, and it named the app's
# own register — the most alarming possible false positive.
#
# So: if the page came back FULL, we cannot distinguish "absent" from
# "truncated", and saying nothing is the only honest answer.
if missing and len(items) >= limit:
    print(f'::error::{kind} lookup returned exactly {len(items)} item(s) — the '
          f'requested page limit. This page is FULL, so {missing} may simply be '
          f'on a page that was never fetched. This is NOT evidence of absence.')
    print(f'::error::Re-run with a higher _limit (or paginate) before trusting '
          f'any "missing" verdict here.')
    sys.exit(1)
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
	"${BASE}/index.php/apps/openregister/api/registers?_limit=2000" || echo 000)"
verify "$REG_BODY" registers "$REG_CODE" 2000

SCH_BODY="$(mktemp)"
SCH_CODE="$(curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	-o "$SCH_BODY" -w '%{http_code}' --max-time 300 \
	"${BASE}/index.php/apps/openregister/api/schemas?_limit=5000" || echo 000)"
verify "$SCH_BODY" schemas "$SCH_CODE" 5000

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

# ── 2b. Complete the first-time setup wizard (ADR-042) ───────────────────────
# THE REGISTER EXISTING IS NOT ENOUGH. `src/manifest.json` declares
# `setup.enabled: true`, so until the wizard's four REQUIRED steps are done the
# SPA renders a modal `<dialog>Set up this app</dialog>` OVER the whole app and
# no page content mounts at all.
#
# This is not a hypothesis. It is what the first run of this job recorded
# (30858234537): 83 failures whose message was
#
#     expect(locator('main, [role="main"]')).toBeVisible() — element(s) not found
#
# and whose captured accessibility snapshot showed Nextcloud's own header,
# then `dialog "Set up this app"` with the tablist `Welkom bij Shillinq /
# Juridische regio (land) / Organisatietype …`. Nothing was broken — the app
# was simply un-set-up, and the specs' `dismissWizard()` helper only closes
# Nextcloud's `#firstrunwizard`, not shillinq's own setup dialog.
#
# So complete the wizard the same way an administrator would, over its own
# admin API (`appinfo/routes.php`: setup#saveConfig, setup#runAction,
# setup#status). Per `SetupController::status()` the four required keys are
# `legal_country`, `legal_region`, `rgs_template` and `administration_id`;
# the fifth step (`seed`) is `required: false` but is run anyway because it is
# what puts a chart of accounts, BTW tariffs and BBV taakvelden in the
# administration — without them the ledger/tax pages render empty shells.
#
# `nl` + `gemeente` + `bbv` is chosen deliberately, not arbitrarily:
# tests/e2e/bbv-compliance.spec.ts states in its header that "the specs assume
# a `gemeente`-type administration is the active one", and `manifest.setup`'s
# own `suggestMap` maps gemeente → bbv. No manifest page or navigation entry
# declares a `visibility` rule at all (checked: zero occurrences), so this
# choice cannot hide a page from the non-government specs.
setup_post() {
	local url="$1" payload="$2" body code
	body="$(mktemp)"
	code="$(
		curl -sS -o "$body" -w '%{http_code}' --max-time 900 \
			-u "${USER_NAME}:${USER_PASS}" \
			-X POST -H 'Content-Type: application/json' -H 'OCS-APIRequest: true' \
			--data "$payload" "${BASE}${url}" || echo 000
	)"
	echo "[ci-seed] POST ${url} -> HTTP ${code}"
	head -c 800 "$body"; echo
	if [ "$code" != "200" ]; then
		echo "::error::Setup step ${url} returned HTTP ${code}; the first-time wizard cannot complete."
		echo "::error::Every UI spec would then fail on 'main not found' while a 'Set up this app' dialog covers the SPA."
		exit 1
	fi
}

setup_post '/index.php/apps/shillinq/api/setup/config' \
	'{"legal_country":"nl","legal_region":"gemeente","rgs_template":"bbv"}'
setup_post '/index.php/apps/shillinq/api/setup/action/init-administration' '{}'
setup_post '/index.php/apps/shillinq/api/setup/action/seed' '{}'

# The OPTIONAL step too, not just the required ones.
#
# `status()` recomputes every step from its own evidence and writes
# `setup_completed_version` only when the REQUIRED ones are done, so
# `completed: true` says nothing about `demo-data`. Since nextcloud-vue 2.21 an
# outstanding OPTIONAL step is enough to open the wizard on its own
# (nextcloud-vue#806 stopped it short-circuiting on `completed`), so an undone
# `demo-data` puts the dialog over the SPA and every click lands on the overlay.
#
# Measured: 59 failures across 18 unrelated spec files, every one
# `locator.click: Test timeout`, from the merge of #1295 onward.
#
# `demo_data_decided` is the app's own DEALT-WITH flag, not "demo objects
# exist" -- SetupController says re-offering the import every visit would make
# "no thanks" impossible to express. Writing `skipped` is what an operator who
# declined leaves behind, and it avoids seeding a dataset the specs do not
# expect. Same fix as buildiq#523.
if [ -f "${SERVER_DIR}/occ" ]; then
	if (cd "${SERVER_DIR}" && php occ config:app:set shillinq demo_data_decided --value=skipped); then
		echo "[ci-seed] demo-data step marked decided (skipped)."
	else
		echo "::warning::could not set demo_data_decided; the wizard may reopen over the SPA."
	fi
fi

# VERIFY, do not assume. `status()` is also what persists
# `setup_completed_version`, which is the manifest's `completionConfigKey` —
# so this call is both the assertion and the last write the wizard needs.
STATUS_BODY="$(mktemp)"
STATUS_CODE="$(curl -sS -o "$STATUS_BODY" -w '%{http_code}' --max-time 300 \
	-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/shillinq/api/setup/status" || echo 000)"
echo "[ci-seed] setup/status HTTP ${STATUS_CODE}"
cat "$STATUS_BODY"; echo
python3 - "$STATUS_BODY" "$STATUS_CODE" <<'PY'
import json, sys
path, code = sys.argv[1], sys.argv[2]
raw = open(path).read()
if code != '200':
    print(f'::error::setup/status returned HTTP {code}; cannot confirm the wizard is complete.')
    print(raw[:500])
    sys.exit(1)
body = json.loads(raw)
undone = [k for k, v in (body.get('steps') or {}).items() if not v.get('done')]
# EVERY step, not just `completed`. `completed` reflects the REQUIRED steps
# only, so it stayed true while `demo-data` sat undone -- and since
# nextcloud-vue 2.21 that alone reopens the wizard over the SPA. This guard
# passed straight through the exact failure it exists to prevent.
if body.get('completed') is not True or undone:
    print(f'::error::Shillinq first-time setup is NOT complete. Unfinished steps: {undone}')
    print('::error::The SPA will render a "Set up this app" dialog over every page and no spec can reach `main`.')
    sys.exit(1)
print('[ci-seed] first-time setup complete:', json.dumps(body.get('steps')))
PY

# ── 2c. Seed the bookings-calendar fixtures ──────────────────────────────────
# `tests/e2e/bookings-resource-calendar.spec.ts` asserts against a calendar that
# has bookings on it — a rendered month grid, a highlighted conflicting pair, and
# a 409 from the create endpoint. Nothing above provisions any of that. Step 2
# only verifies that the `Booking` SCHEMA exists, and a schema with no objects
# renders an empty grid, which is exactly what a broken calendar renders too. So
# every one of those assertions had no fixture to assert on and that spec has
# never been able to pass.
#
# WHY THE DATES ARE COMPUTED, NOT LITERAL
# ---------------------------------------
# Two independent windows have to agree, and both are anchored on "today":
#
#   1. `CalendarController::listBookings()` defaults its range to
#      `today .. today + 30 days` when the caller sends no start/end — and
#      CalendarView.vue sends none. A booking dated in the past is simply not
#      in the response.
#   2. `CalendarView.vue` anchors its grid on `startDate` (the host page passes
#      today) and has NO month-navigation controls. Only bookings whose local
#      start date falls in the CURRENT month can be rendered at all.
#
# Hardcoded May-2026 fixtures would satisfy neither on any other day, so the
# windows below are generated relative to today at seed time.
#
# WHY THE COVERAGE IS CONTIGUOUS AND OVERSHOOTS THE DAY
# ----------------------------------------------------
# The spec drives a `datetime-local` input, which is LOCAL wall-clock time, and
# `playwright.config.ts` sets no `timezoneId` — so the browser uses the runner's
# zone and "today at 12:00" can land anywhere in `today-1 22:00Z … today+1 00:00Z`
# once every real UTC offset (-12 … +14) is allowed for. The ten bookings below
# tile `today-1 20:00Z → today+2 00:00Z` without a gap, so the conflict test gets
# its 409 in ANY zone the runner happens to be in, rather than only in UTC.
#
# bk-002 and bk-003 deliberately OVERLAP each other and are both `pending`,
# which is what `CalendarView.isConflict()` renders with the conflict class —
# that is the "seed conflict pair" the spec's highlight assertion needs. They are
# written through OpenRegister's generic object API rather than shillinq's
# `POST /api/v2/calendars/{id}/bookings`, because that endpoint's whole job is to
# REFUSE an overlapping pair; the fixture has to be able to create the very state
# the product prevents.
#
# NOTE also that no booking starts in local hour 0. The day-view slot button
# renders its bookings INSIDE the button, so a slot that contains a booking
# swallows the click on the span (`@click.stop`) instead of emitting
# `slot:clicked`. The spec clicks the FIRST slot (hour 0); keeping that hour free
# of booking starts keeps that click unambiguous.
CAL_ID="e2e-cal-001"
RES_ID="e2e-res-001"

# The administration id to stamp on the fixtures. `CalendarController::index()`
# filters the calendar LIST by the caller's `activeAdministrationId`, so a
# fixture carrying the wrong one is created successfully, is readable by id, and
# is invisible in the picker — a write that "succeeds" into a scope nobody reads.
# Ask the instance which id that actually is instead of guessing.
CTX_BODY="$(mktemp)"
CTX_CODE="$(curl -sS -o "$CTX_BODY" -w '%{http_code}' --max-time 300 \
	-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/shillinq/api/administrations/context" || echo 000)"
echo "[ci-seed] administrations/context -> HTTP ${CTX_CODE}"

ADM_ID="$(python3 - "$CTX_BODY" "$CTX_CODE" <<'PY'
import json, sys
path, code = sys.argv[1], sys.argv[2]
if code != '200':
    print('')
    sys.exit(0)
try:
    body = json.load(open(path))
except Exception:
    print('')
    sys.exit(0)
print((body.get('activeAdministrationId') or ''))
PY
)"

if [ -z "$ADM_ID" ]; then
	# No active administration resolved. buildContext() derives it from an
	# AdministrationMembership, and the setup wizard's `init-administration`
	# seeds the Administration record without one, so this is the expected
	# state on a fresh CI instance rather than a fault introduced here.
	# Fall back to the code the wizard persisted as `administration_id`, which is
	# `administrations[0].administrationCode` of
	# lib/Settings/seeds/administraties/default.json — read from the repo, not
	# invented here.
	ADM_ID="ADM-001"
	echo "[ci-seed] no activeAdministrationId in the context response; stamping fixtures with '${ADM_ID}' (the bundled default administration code)."
fi
echo "[ci-seed] bookings fixtures administrationId: ${ADM_ID}"

# Idempotency probe. `GET /api/v2/calendars/{id}` resolves through the same
# `loadCalendar()` the rest of the API uses, so a 200 here means the fixture is
# genuinely reachable by the code under test — not merely present in some table.
CAL_PROBE="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 300 \
	-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/shillinq/api/v2/calendars/${CAL_ID}" || echo 000)"
echo "[ci-seed] calendars/${CAL_ID} probe -> ${CAL_PROBE}"

FIXDIR="$(mktemp -d)"
python3 - "$FIXDIR" "$CAL_ID" "$RES_ID" "$ADM_ID" <<'PY'
import json, os, sys
from datetime import datetime, timedelta, timezone

fixdir, cal_id, res_id, adm_id = sys.argv[1:5]

# Anchor on today's UTC midnight so every window is expressible as an offset.
day = datetime.now(timezone.utc).replace(hour=0, minute=0, second=0, microsecond=0)


def at(offset_hours):
    """UTC wire timestamp `offset_hours` from today's UTC midnight."""
    return (day + timedelta(hours=offset_hours)).strftime('%Y-%m-%dT%H:%M:%SZ')


with open(os.path.join(fixdir, 'calendar.json'), 'w') as fh:
    json.dump({
        'administrationId': adm_id,
        'calendarId': cal_id,
        'resource': res_id,
        'timeZone': 'Europe/Amsterdam',
        # REQUIRED. The Calendar schema is assembled from TWO register
        # fragments and OpenRegister validates against the MERGED result, so
        # the effective required set is the union of both:
        #   lib/Settings/register.d/bookings-resource-calendar.json
        #     -> administrationId, calendarId, resource, timeZone, status
        #   lib/Settings/register.d/10-bookings-resource-calendar.json
        #     -> resource, timeZone, organization, status
        # Reading only the first fragment loses `organization`, and the create
        # then fails with HTTP 400 "The required property (organization) is
        # missing" (run 30893116659). `organization` is a plain string FK, and
        # `org-001` is the value that fragment's own bundled seed objects use.
        'organization': 'org-001',
        'status': 'active',
    }, fh)

# (id, start hour offset, end hour offset, status, title)
# Contiguous cover of today-1 20:00Z … today+2 00:00Z; bk-002 and bk-003 overlap
# between +06 and +08 and are both pending.
rows = [
    ('bk-001', -4, 1, 'confirmed', 'E2E overnight handover'),
    ('bk-002', 1, 8, 'pending', 'E2E conflicting morning block A'),
    ('bk-003', 6, 12, 'pending', 'E2E conflicting morning block B'),
    ('bk-004', 12, 16, 'confirmed', 'E2E afternoon session'),
    ('bk-005', 16, 20, 'confirmed', 'E2E evening session'),
    ('bk-006', 20, 24, 'confirmed', 'E2E late shift'),
    ('bk-007', 24, 30, 'confirmed', 'E2E next-day night shift'),
    ('bk-008', 30, 36, 'confirmed', 'E2E next-day morning'),
    ('bk-009', 36, 42, 'confirmed', 'E2E next-day afternoon'),
    ('bk-010', 42, 48, 'confirmed', 'E2E next-day evening'),
]

names = []
for booking_id, start_h, end_h, status, title in rows:
    payload = {
        'administrationId': adm_id,
        'bookingId': booking_id,
        'calendar': cal_id,
        'resource': res_id,
        'title': title,
        'startTime': at(start_h),
        'endTime': at(end_h),
        'attendee': 'E2E Fixture',
        'status': status,
    }
    name = f'booking-{booking_id}.json'
    with open(os.path.join(fixdir, name), 'w') as fh:
        json.dump(payload, fh)
    names.append(name)

with open(os.path.join(fixdir, 'index.txt'), 'w') as fh:
    fh.write('\n'.join(names) + '\n')

print(f'[ci-seed] fixture window: {at(-4)} .. {at(48)} (contiguous)')
PY

# POST one object into the shillinq register through OpenRegister's generic
# object API. Admin Basic auth + OCS-APIRequest for the same CSRF reason as the
# import above.
#
# $3 is the consequence line — what breaks if this object is missing. It is
# optional and defaults to the bookings wording this helper was written for,
# because an error that confidently names the WRONG spec is worse than a vague
# one: a failed CommitmentBudget used to print "the calendar grid renders empty"
# and send the reader to look at bookings code.
or_create() {
	local schema="$1" payload="$2" consequence="${3:-}" body code
	body="$(mktemp)"
	code="$(
		curl -sS -o "$body" -w '%{http_code}' --max-time 300 \
			-u "${USER_NAME}:${USER_PASS}" \
			-X POST -H 'Content-Type: application/json' -H 'OCS-APIRequest: true' \
			--data "@${payload}" \
			"${BASE}/index.php/apps/openregister/api/objects/shillinq/${schema}" || echo 000
	)"
	if [ "$code" != "200" ] && [ "$code" != "201" ]; then
		echo "::error::Creating a ${schema} fixture returned HTTP ${code}."
		echo "::error::Payload: $(cat "$payload")"
		echo "::error::Response: $(head -c 800 "$body")"
		if [ -n "$consequence" ]; then
			echo "::error::${consequence}"
		else
			echo "::error::bookings-resource-calendar.spec.ts asserts against these objects; without them"
			echo "::error::the calendar grid renders empty and every one of its assertions fails on a"
			echo "::error::selector timeout that blames the UI rather than the missing fixture."
		fi
		exit 1
	fi
}

if [ "$CAL_PROBE" = "200" ]; then
	echo "[ci-seed] calendar ${CAL_ID} already present — not recreating it."
else
	echo "[ci-seed] creating calendar ${CAL_ID}."
	or_create Calendar "${FIXDIR}/calendar.json"
fi

# Per-BOOKING idempotency, not per-calendar. A run that created the calendar and
# then failed part-way through the bookings would otherwise be skipped forever
# by a calendar-only check, and every later run would fail the count gate below
# without ever repairing the gap. Ask which bookingIds are already there and
# create exactly the missing ones.
EXISTING_BODY="$(mktemp)"
EXISTING_CODE="$(curl -sS -o "$EXISTING_BODY" -w '%{http_code}' --max-time 300 \
	-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/shillinq/api/v2/calendars/${CAL_ID}/bookings" || echo 000)"
EXISTING_IDS="$(mktemp)"
python3 - "$EXISTING_BODY" "$EXISTING_CODE" > "$EXISTING_IDS" <<'PY'
import json, sys
path, code = sys.argv[1], sys.argv[2]
if code != '200':
    sys.exit(0)
try:
    body = json.load(open(path))
except Exception:
    sys.exit(0)
for booking in (body.get('bookings') or []):
    bid = booking.get('bookingId') or booking.get('id')
    if bid:
        print(bid)
PY
echo "[ci-seed] bookings already present on ${CAL_ID}: $(wc -l < "$EXISTING_IDS")"

while read -r fixture; do
	[ -n "$fixture" ] || continue
	# booking-bk-004.json -> bk-004
	fixture_id="${fixture#booking-}"
	fixture_id="${fixture_id%.json}"
	if grep -qxF "$fixture_id" "$EXISTING_IDS"; then
		continue
	fi
	echo "[ci-seed] creating booking ${fixture_id}."
	or_create Booking "${FIXDIR}/${fixture}"
done < "${FIXDIR}/index.txt"

# VERIFY through the endpoint the UI actually calls. Creating ten objects and
# reading ten back are different claims: the read applies the calendar filter and
# the 30-day range window, either of which can drop every row while all ten
# writes reported success.
BK_BODY="$(mktemp)"
BK_CODE="$(curl -sS -o "$BK_BODY" -w '%{http_code}' --max-time 300 \
	-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/shillinq/api/v2/calendars/${CAL_ID}/bookings" || echo 000)"
echo "[ci-seed] calendars/${CAL_ID}/bookings -> HTTP ${BK_CODE}"
python3 - "$BK_BODY" "$BK_CODE" <<'PY'
import json, sys
path, code = sys.argv[1], sys.argv[2]
raw = open(path).read()
if code != '200':
    print(f'::error::Reading back the seeded bookings returned HTTP {code}, so the count below '
          f'would prove nothing. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print('::error::The bookings endpoint did not return JSON (HTTP 200). First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
bookings = body.get('bookings')
if not isinstance(bookings, list):
    print("::error::The bookings response has no 'bookings' array — the envelope changed and "
          "CalendarView.vue reads that exact key.")
    print(raw[:500])
    sys.exit(1)
pending = [b for b in bookings if b.get('status') == 'pending']
print(f'[ci-seed] seeded bookings readable: {len(bookings)} (pending: {len(pending)})')
if len(bookings) < 10:
    print(f'::error::Expected 10 seeded bookings on the calendar, got {len(bookings)}.')
    print('::error::The month/week/day render assertions have nothing to render.')
    sys.exit(1)
if len(pending) < 2:
    print(f'::error::Expected at least 2 pending (conflicting) bookings, got {len(pending)}.')
    print("::error::The conflict-highlight assertion looks for the conflict class, which "
          "CalendarView.isConflict() derives from status == 'pending'.")
    sys.exit(1)
print('[ci-seed] bookings-calendar fixtures OK.')
PY

# Report — but do NOT gate on — whether the fixture is visible in the ORG-SCOPED
# calendar list. The spec deep-links to /bookings/calendar/e2e-cal-001, which
# resolves by id and does not consult this list, so an invisible calendar cannot
# fail the spec. It would, however, leave the param-less menu entry showing an
# empty picker, and that is worth naming rather than discovering later.
IDX_BODY="$(mktemp)"
IDX_CODE="$(curl -sS -o "$IDX_BODY" -w '%{http_code}' --max-time 300 \
	-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/shillinq/api/v2/calendars" || echo 000)"
python3 - "$IDX_BODY" "$IDX_CODE" "$CAL_ID" <<'PY'
import json, sys
path, code, cal_id = sys.argv[1], sys.argv[2], sys.argv[3]
if code != '200':
    print(f'[ci-seed] calendar index probe -> HTTP {code} (picker visibility unknown).')
    sys.exit(0)
try:
    body = json.load(open(path))
except Exception:
    print('[ci-seed] calendar index probe returned non-JSON (picker visibility unknown).')
    sys.exit(0)
ids = [c.get('id') for c in (body.get('calendars') or [])]
if cal_id in ids:
    print(f'[ci-seed] {cal_id} IS visible in the org-scoped calendar index.')
else:
    print(f'[ci-seed] NOTE: {cal_id} is NOT in the org-scoped calendar index (saw: {ids}).')
    print('[ci-seed] The spec deep-links by calendar id and is unaffected; the param-less')
    print("[ci-seed] menu entry's picker will look empty until the admin user has an")
    print('[ci-seed] AdministrationMembership, which the setup wizard does not create.')
PY

# ── 2b. Commitment fixtures for the budget-line drilldown ────────────────────
#
# WHY THIS EXISTS. `budget-line-commitments.spec.ts` skips its real assertions
# when no CommitmentLine is visible, and in CI it always was — so the one spec
# that proves REQ-VPL-011 end to end had never run here. It reported as a pass.
#
# The bundled seeds in `lib/Settings/register.d/bookkeeping-verplichtingen-
# administratie.json` DO carry commitments, but every one of them is stamped
# `administrationId: "adm-demo"`, while first-time setup creates `ADM-001` and
# makes that the caller's active administration. The page scopes the aggregation
# to the caller's administration (it must — a declared aggregation cannot name a
# per-caller value, see openregister#2852), so it correctly returned zero groups
# over data that was plainly in the register. Nothing errored: an aggregation
# that matches nothing is an empty result, which renders as the page's own empty
# state and reads as "no data yet".
#
# Fixtures are created HERE, under the resolved ${ADM_ID}, for the same reason
# the bookings fixtures are: the shipped seeds are demo content with their own
# administration, and rewriting them to ADM-001 would bend product data to suit
# a test. This also keeps the two mutually-exclusive specs honest — the drilldown
# one now runs, and the empty-state one skips for a reason it states.
#
# Two lines in ONE coderingscombinatie on purpose: the aggregation must SUM them
# into a single row, so a regression that returned rows un-summed would fail
# here rather than look plausible.
CMT_NUMBER="E2E-VPL-001"
CMT_PROGRAMME="5.1"
CMT_COST_CENTRE="FAC-2026"
CMT_GL="4400"
CMT_YEAR="2026"

CMT_PROBE_BODY="$(mktemp)"
CMT_PROBE="$(curl -sS -o "$CMT_PROBE_BODY" -w '%{http_code}' --max-time 300 \
	-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/objects/shillinq/CommitmentLine?commitment=${CMT_NUMBER}&_limit=5" \
	|| echo 000)"

CMT_EXISTING="$(python3 - "$CMT_PROBE_BODY" "$CMT_PROBE" <<'PY'
import json, sys
path, code = sys.argv[1], sys.argv[2]
if code != '200':
    print(0)
    sys.exit(0)
try:
    body = json.load(open(path))
except Exception:
    print(0)
    sys.exit(0)
results = body.get('results') if isinstance(body, dict) else body
print(len(results or []))
PY
)"

if [ "$CMT_EXISTING" -ge 2 ]; then
	echo "[ci-seed] commitment fixtures already present (${CMT_EXISTING} lines) — not recreating."
else
	echo "[ci-seed] creating commitment fixtures under administration ${ADM_ID}."
	CMTDIR="$(mktemp -d)"
	python3 - "$CMTDIR" "$ADM_ID" "$CMT_NUMBER" "$CMT_PROGRAMME" "$CMT_COST_CENTRE" "$CMT_GL" "$CMT_YEAR" <<'PY'
import json, os, sys
out, adm, number, programme, cost_centre, gl, year = sys.argv[1:8]
year = int(year)

def write(name, payload):
    with open(os.path.join(out, name), 'w') as fh:
        json.dump(payload, fh)

# The join side. `join.on` is {programme: programmeCode}, so programmeCode here
# MUST equal the lines' `programme` or the joined figures come back absent and
# every Authorized cell renders as zero.
write('budget.json', {
    'administrationId': adm,
    'programmeCode': programme,
    'costCentre': cost_centre,
    'financialYear': year,
    'description': 'E2E budget line for the committed-vs-realised drilldown',
    'authorised_amount': 80000000,
    'realised_amount': 2500000,
})

write('commitment.json', {
    'administrationId': adm,
    'commitmentNumber': number,
    'sourceReference': 'e2e/budget-line-commitments',
    'kind': 'purchase_order',
    'status': 'committed',
    'currency': 'EUR',
    'total_amount_excl_vat': 15000000,
})

# `afgesloten: false` is REQUIRED, not incidental: the declared aggregation
# filters on it, and openregister#2852 refuses any caller filter that would
# relax a declared key. A line seeded without it (or with true) is invisible to
# the page no matter what else is right.
for n, (amount, remaining, invoiced) in enumerate(
    [(10000000, 9000000, 1000000), (5000000, 4500000, 500000)], start=1
):
    write(f'line{n}.json', {
        'administrationId': adm,
        'commitment': number,
        'ruleNumber': n,
        'description': f'E2E commitment line {n}',
        'financialYear': year,
        'amount_excl_vat': amount,
        'generalLedgerAccount': gl,
        'costCentre': cost_centre,
        'programme': programme,
        'remaining_committed': remaining,
        'invoiced_amount': invoiced,
        'afgesloten': False,
    })

print(f'[ci-seed] commitment fixtures written for {number} '
      f'({programme}/{cost_centre}/{year}/{gl}).')
PY

	CMT_WHY="budget-line-commitments.spec.ts (REQ-VPL-011) needs these; without them the page has no rows, the spec SKIPS its assertions, and the suite reports a pass."
	or_create CommitmentBudget "${CMTDIR}/budget.json" "$CMT_WHY"
	or_create Commitment "${CMTDIR}/commitment.json" "$CMT_WHY"
	or_create CommitmentLine "${CMTDIR}/line1.json" "$CMT_WHY"
	or_create CommitmentLine "${CMTDIR}/line2.json" "$CMT_WHY"
fi

# Prove the AGGREGATION returns the row, not merely that the objects exist.
#
# Creating four objects and declaring victory is the failure this whole section
# is repairing: the objects were always there. What was broken was whether the
# aggregation could see them. Ask the endpoint the page asks, with the filter the
# page sends, and gate on a group coming back.
AGG_BODY="$(mktemp)"
AGG_CODE="$(curl -sS -o "$AGG_BODY" -w '%{http_code}' --max-time 300 \
	-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/objects/aggregations/shillinq/CommitmentLine/committedVsRealisedPerBudgetLine?filter%5BadministrationId%5D=${ADM_ID}" \
	|| echo 000)"
python3 - "$AGG_BODY" "$AGG_CODE" "$CMT_PROGRAMME" <<'PY'
import json, sys
path, code, programme = sys.argv[1], sys.argv[2], sys.argv[3]
if code != '200':
    print(f'::error::The committed-vs-realised aggregation returned HTTP {code}.')
    print('::error::budget-line-commitments.spec.ts would skip its assertions and report a PASS.')
    sys.exit(1)
try:
    body = json.load(open(path))
except Exception:
    print('::error::The aggregation returned non-JSON.')
    sys.exit(1)
groups = body.get('groups') or []
mine = [g for g in groups if (g.get('keys') or {}).get('programme') == programme]
if len(mine) > 1:
    print(f'::error::The aggregation returned {len(mine)} groups for programme {programme}; '
          'expected exactly one.')
    print('::error::The two fixture lines share one coderingscombinatie, so they must collapse '
          'into a single group. More than one means the grouping is not composite.')
    print(f'::error::Groups: {json.dumps(mine)[:600]}')
    sys.exit(1)
if not mine:
    print(f'::error::The aggregation returned no group for programme {programme}.')
    print(f'::error::Saw {len(groups)} group(s): {json.dumps(groups)[:600]}')
    print('::error::The fixtures were created, so this is the aggregation not seeing them —')
    print('::error::most likely an administration mismatch or the declared `afgesloten` filter.')
    print('::error::Without a group the spec skips and the suite reports a pass over a broken page.')
    sys.exit(1)
g = mine[0]
values = g.get('values') or {}
joined = g.get('joined') or {}
committed = values.get('sum_remaining_committed')
invoiced = values.get('sum_invoiced_amount')
authorised = joined.get('CommitmentBudget.authorised_amount')
print(f'[ci-seed] aggregation group OK: keys={json.dumps(g.get("keys"))} '
      f'committed={committed} invoiced={invoiced} authorised={authorised}')
# Both fixture lines must be SUMMED into this one group.
#
# `>=`, not `==`, on purpose. The bundled seeds currently sit in `adm-demo` so
# ADM-001 holds only these two lines, but that is a fact about today's seed data,
# not a contract. Pinning the exact total would turn "somebody finally moved the
# demo seeds into the default administration" into a red build with a message
# about summing — punishing the fix. The floor still catches what matters: if the
# aggregation returned one line instead of two, or stopped summing, the total
# drops below it. The single-group check above is what proves the collapse.
FLOOR_COMMITTED, FLOOR_INVOICED, FLOOR_AUTHORISED = 13500000, 1500000, 80000000
if not isinstance(committed, (int, float)) or committed < FLOOR_COMMITTED:
    print(f'::error::sum_remaining_committed={committed}, expected at least {FLOOR_COMMITTED} '
          '(the two fixture lines, 9000000 + 4500000).')
    print('::error::The rows are reaching the page un-summed, or one line is missing.')
    sys.exit(1)
if not isinstance(invoiced, (int, float)) or invoiced < FLOOR_INVOICED:
    print(f'::error::sum_invoiced_amount={invoiced}, expected at least {FLOOR_INVOICED} '
          '(1000000 + 500000).')
    sys.exit(1)
if not isinstance(authorised, (int, float)) or authorised < FLOOR_AUTHORISED:
    print(f'::error::The CommitmentBudget join returned authorised={authorised}, '
          f'expected at least {FLOOR_AUTHORISED}.')
    print('::error::A missing join is the difference between the Authorized column showing a '
          'budget and showing zero, and both render without erroring.')
    sys.exit(1)
print('[ci-seed] commitment fixtures OK — the drilldown spec will run, not skip.')
PY

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
#
# ⚠️ THE CONTENT-TYPE CHECK ALONE CANNOT CATCH THE CASE THIS GATE NAMES.
# The comment directly above says the way to prove the gate can fail is to
# TRUNCATE the bundle. Follow that instruction against a content-type-only
# check and the gate PASSES: a 0-byte `shillinq-main.js` is still served as
# `200 application/javascript`. The gate would have gone green over exactly
# the failure it was written to catch — the same shape of defect as the job
# being `skipped` in the first place.
#
# So the SIZE is gated too. `%{size_download}` is already the third field of
# BUNDLE_INFO; it just was not being read. Measured on a healthy build the
# bundle is ~12.2 MB (run 30881358951 logged 12242717 bytes before its
# deliberate truncation). The floor is set at 1 MB — two orders of magnitude
# below a real build, and above anything a stub, an empty file or a
# misrouted ~40 KB Nextcloud error page could produce. It is a floor on
# "something real was served", not an assertion about bundle size, so a
# legitimate build shrinking by half still passes.
BUNDLE_MIN_BYTES=1000000

if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	case "$BUNDLE_INFO" in
		*javascript*)
			echo "[ci-seed] bundle content-type verified as JavaScript."
			;;
		*)
			echo "::error::The Shillinq frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::Check the 'Build app frontend' step — a missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac

	# Third field of "<http_code> <content_type> <size_download>".
	BUNDLE_BYTES="$(printf '%s\n' "$BUNDLE_INFO" | awk '{print $3}')"
	case "$BUNDLE_BYTES" in
		''|*[!0-9]*)
			echo "::error::Could not read a byte count from the bundle probe (BUNDLE_INFO='${BUNDLE_INFO}')."
			echo "::error::Refusing to treat an unreadable size as a pass — that is how a truncated bundle gets through."
			exit 1
			;;
	esac
	if [ "$BUNDLE_BYTES" -lt "$BUNDLE_MIN_BYTES" ]; then
		echo "::error::The Shillinq frontend bundle served only ${BUNDLE_BYTES} bytes (floor ${BUNDLE_MIN_BYTES})."
		echo "::error::It is being served as JavaScript, so a content-type check passes — but there is no application in it."
		echo "::error::Every UI spec would then fail on a selector timeout, and the API-only specs would still pass, which reads like a partial outage rather than a broken build."
		exit 1
	fi
	echo "[ci-seed] bundle verified: ${BUNDLE_BYTES} bytes of JavaScript (floor ${BUNDLE_MIN_BYTES})."
fi

echo "[ci-seed] done."
