# Spec: integration-config-to-openconnector (delta)

## ADDED Requirements

### Requirement: REQ-ICO-001 — `openconnector-sources.json` MUST declare every adapter-family source slug

`lib/Settings/openconnector-sources.json` MUST carry one stub entry per
adapter family declared in `ExternalAdaptersAdminController::ADAPTERS`
(15 families at HEAD — see `design.md` §1's derived table), each following
the file's existing `_meta` discipline: the entry's `slug` MUST equal that
family's `sourceSlug` field in the controller, its `description` MUST state
that credentials/protocol mapping live in openconnector and shillinq holds
only the slug reference, and it MUST NOT contain a credential, endpoint
secret, or client-secret value. A family whose `sourceSlug` is disputed
(`design.md` §6.1) MUST NOT be silently renamed by this requirement's
implementer without the verification `design.md` §6.1 describes.

@e2e exclude static JSON config-declaration requirement, no browser-visible behaviour — covered by `tests/validate-seeds.js`-equivalent JSON structural validation

#### Scenario: Every declared adapter family has a matching source stub

- **GIVEN** the 15 `sourceSlug` values in `ExternalAdaptersAdminController::ADAPTERS`
- **WHEN** `lib/Settings/openconnector-sources.json` is parsed
- **THEN** every one of the 15 slugs appears as a `sources[].slug` entry

#### Scenario: No stub entry carries a real credential

- **GIVEN** every entry in `lib/Settings/openconnector-sources.json`
- **WHEN** the file is scanned for `apiKey`, `clientSecret`, `password`, or a
  bearer-token-shaped string literal
- **THEN** none is found — every entry's `configuration` describes only
  documentation placeholders (endpoint shape, auth method name), matching the
  existing `bzk-sisa-upload-2026` entry's pattern

### Requirement: REQ-ICO-002 — The 15 per-adapter admin pages MUST collapse into one External Connections roster page

The manifest MUST NOT declare a distinct page/route per adapter family.
Instead, exactly one page (reusing the existing `ExternalAdaptersStatus` id
and `/external-adapters` route, per `design.md` §2's churn-avoidance
reasoning) MUST render one row per family, sourced from
`GET /api/admin/external-adapters`. Each row MUST show: the family title, the
declared `sourceSlug`, the dormant/live badge (unchanged existing behaviour),
and the provisioning status defined by REQ-ICO-003.

@e2e integration-config-to-openconnector::roster-page-lists-all-15-families

#### Scenario: The roster page lists all 15 declared families

- **GIVEN** an authenticated admin navigates to `/apps/shillinq/external-adapters`
- **WHEN** the page finishes loading
- **THEN** exactly 15 rows are rendered, one per family in the live
  `ExternalAdaptersAdminController#index` response

#### Scenario: No per-adapter detail route exists any more

- **GIVEN** the manifest at HEAD after this change
- **WHEN** `src/manifest.d/external-adapters-w8.json`'s `pages[]` array is
  inspected
- **THEN** it contains exactly one page entry (the roster), and no page
  entry with a `config.adapterId` field remains

### Requirement: REQ-ICO-003 — Provisioned status MUST be resolved via OpenRegister's generic object API against openconnector's `source` schema, with a fail-soft fallback

For each family, the backend MUST attempt to resolve an OpenRegister object
in `register: 'openconnector', schema: 'source'` whose slug equals the
family's declared `sourceSlug`. On resolution, the row's provisioning status
MUST be `provisioned`. On a confirmed not-found result, the status MUST be
`declared-not-provisioned`. On any error resolving the lookup itself
(OpenRegister unavailable, openconnector not installed, DI resolution
failure), the status MUST be `unknown`, the error MUST be logged at
`warning` level (mirroring `ExternalAdaptersAdminController::
resolveDormancy()`'s existing pattern), and the row MUST still render with a
deep link to openconnector's Source admin rather than failing the whole
roster response.

@e2e integration-config-to-openconnector::provisioned-row-shows-slug-status

#### Scenario: A provisioned source shows its slug status

- **GIVEN** an openconnector `Source` object exists with slug matching one
  family's declared `sourceSlug`
- **WHEN** the roster page loads
- **THEN** that family's row shows provisioning status `provisioned`

#### Scenario: An undeclared source falls back to the deep-link prompt

- **GIVEN** no openconnector `Source` object exists for a family's declared
  `sourceSlug`
- **WHEN** the roster page loads
- **THEN** that family's row shows "declared, provision in OpenConnector"
  with a link to openconnector's Source admin

#### Scenario: OpenRegister unavailable degrades every row, not the whole page

- **GIVEN** the OpenRegister object-service DI resolution throws for every
  lookup
- **WHEN** `GET /api/admin/external-adapters` is called
- **THEN** the response is still 200 with all 15 rows present, each carrying
  provisioning status `unknown`, and a `warning`-level log entry exists per
  attempted lookup

### Requirement: REQ-ICO-004 — Removing a per-adapter page MUST remove its page entry and its nav/route entry in the same edit

No commit in this change's implementation MAY leave a `menu[]` node with a
`route` value that no longer resolves to an entry in `pages[]`, and no
commit MAY leave a `pages[]` entry with no reachable `menu[]` node, at any
point in the change's history — the removal of a family's page and its nav
leaf MUST happen atomically. This requirement is written to hold under
`nav-reachability-gate`'s reachability relation (`design.md` §2) whenever
that gate is implemented, and is checked manually against `buildManifest()`
semantics in the meantime per that section.

@e2e exclude manifest-structural invariant, not a runtime browser behaviour — verified by inspecting the final `src/manifest.d/external-adapters-w8.json` diff and, once available, by `npm run check:nav-reachability`

#### Scenario: The final manifest has no dangling route

- **GIVEN** the manifest fragment after this change
- **WHEN** every `menu[].route` value is checked against `pages[].id`
- **THEN** every route resolves to an existing page, and every page is
  reachable from some `menu[]` node

### Requirement: REQ-ICO-005 — The manifest MUST declare the 15 dormant adapter families as unimplemented optional integrations, satisfying ADR-067 rule 2

`src/manifest.json` (or an equivalent machine-readable manifest-level
declaration) MUST record, for each of the 15 families, that its integration
is declared unimplemented pending a real binding — the condition ADR-067
rule 2 requires for a `Log*Adapter` stub to be a legitimate posture rather
than an orphaned-capability defect. The exact declaration mechanism (a
top-level `optionalIntegrations` array per ADR-022's existing pattern, or a
narrower app-local shape) is left to the implementer per `design.md` §6.2's
open question; either satisfies this requirement provided the declaration is
machine-readable and enumerates all 15 families by their `sourceSlug`.

@e2e exclude manifest-declaration presence check, not a browser-visible behaviour — verified by a structural assertion against `src/manifest.json`

#### Scenario: Every dormant family has a manifest-level unimplemented-integration declaration

- **GIVEN** the manifest after this change
- **WHEN** the chosen declaration structure is parsed
- **THEN** all 15 `sourceSlug` values from `ExternalAdaptersAdminController::ADAPTERS`
  appear, each marked as an unimplemented/roadmap integration

### Requirement: REQ-ICO-006 — Non-goals: what stays in shillinq unchanged

This change MUST NOT modify `MatchingRules`, `BankingRules`, the
reconciliation UI, `ImportWizard`, any of the 14 `lib/Service/External/**`
adapter port interfaces or their `Log*Adapter` stubs, or the `depositWebhook`
/ `paymentRequestWebhook` receiver controllers (kept under the ADR-081
machine-to-machine carve-out). `ImportWizard` MUST remain documented as a
manual CAMT/MT940 fallback path only — not re-scoped as a primary ingestion
mechanism by this change. `payrollWebhook#receive` MUST NOT be touched by
this change; its removal is scoped to the separate `payroll-leaves-to-hrmq`
change.

@e2e exclude negative/scope-boundary requirement — verified by diff inspection, no new browser behaviour to assert

#### Scenario: The diff touches no adapter interface or webhook receiver file

- **GIVEN** this change's implementation diff
- **WHEN** it is inspected for changes under `lib/Service/External/**` or to
  `depositWebhook`/`paymentRequestWebhook`/`payrollWebhook` controller
  methods
- **THEN** no such changes are present

### Requirement: REQ-ICO-007 — Playwright e2e coverage MUST exist for the roster page and MUST replace the removed per-adapter-page specs

The three existing e2e spec files that exercise the now-removed per-adapter
surfaces (`tests/e2e/external-adapters.spec.ts`,
`tests/e2e/visual/external-adapters.visual.spec.ts`,
`tests/e2e/workflows/external-adapters-admin.spec.ts`) MUST be replaced by
coverage of the roster page — either by rewriting them in place or by a new
spec file, but the implementation MUST NOT leave a spec file asserting
against a route this change deletes (e.g. `/external-adapters/mollie`). The
new/rewritten coverage MUST assert: all 15 rows render, each row's deep link
to openconnector's Source admin is a well-formed URL, and at least one row
whose `sourceSlug` matches a real provisioned source shows the `provisioned`
status.

@e2e integration-config-to-openconnector::deep-links-are-well-formed

#### Scenario: Every row's deep link is a well-formed URL

- **GIVEN** the roster page has finished loading
- **WHEN** each row's "provision in OpenConnector" link `href` is inspected
- **THEN** every link is a well-formed, non-empty URL pointing at
  openconnector's Source admin surface

#### Scenario: No test file references a removed per-adapter route

- **GIVEN** the test suite after this change
- **WHEN** `tests/e2e/**` is scanned for `/external-adapters/<family-id>`
  path literals
- **THEN** no match is found
