# Change: integration-config-to-openconnector

## Why

Wave 2 of the fleet's connector-consolidation programme (ADR-067 "shared
egress plane", ADR-091 "externally-authenticated API surface belongs to
openconnector", ADR-022 "apps consume OR abstractions") draws a hard boundary:
integration **configuration** (credentials, endpoints, protocol mapping,
auth flow) belongs to openconnector; a leaf app holds only a **source slug
reference**. Shillinq already states this rule for one integration family in
its own spec — `openspec/specs/bookkeeping-bank-connectors/spec.md` REQ-BC-001
("PSD2 AIS aggregator integrations SHALL be consumed from openconnector per
ADR-022") — but the app's admin surface does not yet reflect it for the other
integrations it ships.

**What exists today, re-verified against HEAD:**

- `lib/Controller/ExternalAdaptersAdminController.php`'s `ADAPTERS` registry
  (lines 114–439) declares **15 adapter families**, not 14. The audit brief's
  "14 external adapter ports under `lib/Service/External/{Bunq,Cbs,
  CcmRuleEngine,CsrdEsrsXbrl,DepositPayment,Digipoort,Ib47,Kvk,Mollie,RvO,
  Salarisbureau,Sisa,TreasuryRate,Uwv}/`" counts *directories* correctly (14),
  but `lib/Service/External/Cbs/` holds **two** interfaces —
  `CbsBestandenAdapterInterface` and `CbsIv3AdapterInterface` — each with its
  own admin registry entry, own `sourceSlug`, and own nav page. The correct
  family count is **15**. See the derived table in `design.md` §1.
- `src/manifest.d/external-adapters-w8.json` (10,922 bytes) declares one
  top-level "External Connections" menu group with **16 children**: the
  `ExternalAdaptersStatus` index leaf plus **15** `ExternalAdapterDetail`
  leaves (one per family), each a distinct page/route (lines 3–269).
- `lib/Settings/openconnector-sources.json` declares exactly **one** source —
  slug `bzk-sisa-upload-2026` — of the 15. Its `_meta.description` already
  states the discipline this change generalises: "Credentials and protocol
  mapping are managed entirely in openconnector; Shillinq holds only the
  source slug reference."
- **A slug mismatch, found while cross-checking the one declared source
  against the controller it is supposed to back**: the controller's `bzk-sisa`
  family entry declares `sourceSlug: 'bzk-sisa'`
  (`ExternalAdaptersAdminController.php:252`), but the only entry in
  `openconnector-sources.json` is keyed `bzk-sisa-upload-2026`. Neither this
  change nor its implementer knows, from the shillinq repo alone, which slug
  is actually provisioned in a live openconnector instance today. This is
  flagged as an open question (see Open Questions) rather than silently
  "corrected" in either direction — renaming a slug that an operator has
  already provisioned against is a live-configuration break, not a text edit.
- **ADR-067 rule 2 compliance gap**: rule 2 permits a `Log*Adapter` stub "only
  while the integration is explicitly declared unimplemented (manifest
  `optionalIntegrations` / roadmap entry)". `grep -rn "optionalIntegrations"`
  across `src/manifest.json` + `src/manifest.d/*.json` returns zero matches;
  the only `roadmap` hit is the unrelated ADR-018 "Features & roadmap" page
  (`src/manifest.json:2598`). Shillinq ships 15 dormant log-stub families with
  no manifest-level "these are declared unimplemented" record — the condition
  ADR-067 rule 2 requires for the stub to be a legitimate posture rather than
  an orphaned-capability defect (gate-52 family) is currently unmet.
- 14+ (now confirmed 15) full admin pages — one per adapter family — exist
  purely to display a static activation recipe (config keys, source slug,
  feature flag, ordered steps) that is already fully expressed as data in the
  controller's `ADAPTERS` registry. Per this task's brief, these collapse into
  one roster page; per ADR-022 ("apps consume OR abstractions... resolving
  cross-app existence checks through the owning app's data"), the roster's
  "is this slug provisioned" column should be resolved by reading
  openconnector's own `Source` records rather than re-declaring provisioning
  state locally.

## What Changes

- **ADDED** `REQ-ICO-001` — `lib/Settings/openconnector-sources.json` MUST
  declare all 15 adapter-family source slugs (not just `bzk-sisa-upload-2026`)
  as configuration stubs following the file's existing `_meta` discipline,
  with the slug list resolved by REQ-ICO-006's open question first.
- **ADDED** `REQ-ICO-002` — The 15 per-adapter `ExternalAdapterDetail` admin
  pages collapse into one "External Connections" roster page: one row per
  family (declared slug, activation summary, provisioned-in-openconnector
  status, dormant/live state).
- **ADDED** `REQ-ICO-003` — Provisioned status MUST be resolved by querying
  OpenRegister's generic object API for `register: openconnector, schema:
  source, slug: <family.sourceSlug>` (an existing, documented OR abstraction
  per ADR-022 — confirmed the `source` schema exists at
  `openconnector/lib/Settings/openconnector_register.json:70` and every seeded
  source object carries `@self.slug`), with a fail-soft fallback to "declared,
  provision in OpenConnector" plus a deep link when the lookup cannot resolve
  (OR unavailable, openconnector not installed, or the slug not found).
- **ADDED** `REQ-ICO-004` — Removing the 15 per-adapter pages MUST remove
  their manifest page entries and their nav/route entries in the same edit,
  so no page becomes orphaned-but-still-routed. This repo has no `check:nav-
  reachability` gate yet (`nav-reachability-gate` is a proposed sibling
  change, not yet implemented — `tests/validate-nav-reachability.js` does not
  exist at HEAD); this requirement is written so the change is safe under
  that gate whenever it lands, and is verified manually per `design.md` §2 in
  the meantime.
- **ADDED** `REQ-ICO-005` — `src/manifest.json` MUST carry a machine-readable
  declaration naming the 15 dormant adapter families as unimplemented
  optional integrations, satisfying ADR-067 rule 2. The exact shape (a
  top-level `optionalIntegrations` array vs. a narrower app-local
  declaration) is an open question for the implementer/architecture owner —
  see Open Questions; ADR-022's `optionalIntegrations` +
  `CapabilityProviderInterface` mechanism is built for a different class of
  registry-resolved capability (`workflow-engine`, `signing-provider`,
  `pdf-export`, `ocr-engine`, `geocoding`) and may not be the right vehicle
  for 15 point-to-point external APIs; this requirement mandates the
  *declaration*, not a specific mechanism.
- **ADDED** `REQ-ICO-006` — Non-goals, explicitly preserved in shillinq:
  `MatchingRules`, `BankingRules`, reconciliation UI, `ImportWizard` (as a
  documented manual CAMT/MT940 fallback only), the 14 adapter port interfaces
  + their `Log*Adapter` stubs, and the two webhook receivers
  (`depositWebhook`, `paymentRequestWebhook`) under the ADR-081 machine-to-
  machine carve-out. `payrollWebhook#receive` is explicitly OUT of this
  change's scope — it is queued for removal by the separate
  `payroll-leaves-to-hrmq` change.
- **ADDED** `REQ-ICO-007` — Playwright e2e coverage for the roster page,
  replacing (not supplementing — the old surfaces are gone) the three
  existing per-adapter-page specs (`tests/e2e/external-adapters.spec.ts`,
  `tests/e2e/visual/external-adapters.visual.spec.ts`,
  `tests/e2e/workflows/external-adapters-admin.spec.ts`).
- **Explicitly out of scope (handed back to the orchestrator as cross-repo
  work, per this change's brief — see `design.md` §5 for the full list)**:
  provisioning any of the 14 still-undeclared openconnector `Source` records
  for real; adding a slug-scoped deep-link query parameter to openconnector's
  generic Sources admin UI if one does not already exist; resolving the
  `bzk-sisa` vs `bzk-sisa-upload-2026` slug mismatch against whatever is
  actually live; and any change to
  `openconnector/openspec/specs/{psd2-ais-bank-feed-connector,live-payment-
  providers,corporate-card-feed,webhook-signing,source-management,http-call-
  engine,job-scheduling}/spec.md`.

## Impact

- Affected spec: new capability `integration-config-to-openconnector` (this
  app has no existing spec covering the External Connections admin surface —
  `bookkeeping-bank-connectors` covers the PSD2/bank-connector consumption
  rule but not the adapter-roster admin UI).
- Affected code (to be created/changed by the implementer): `lib/Settings/
  openconnector-sources.json` (15 slug stubs), `src/manifest.d/external-
  adapters-w8.json` (collapse to 1 page + 1 menu leaf — see `design.md` §3 for
  the byte-budget estimate), `src/views/external-adapters/
  ExternalAdaptersStatus.vue` (rewritten into the roster component;
  `ExternalAdapterDetail.vue` is removed — no longer referenced by any
  route), `lib/Controller/ExternalAdaptersAdminController.php` (extend
  `#index` to resolve provisioned status per REQ-ICO-003; `#show` may be
  removed once no page deep-links to a single family), `src/manifest.json`
  (ADR-067 rule 2 declaration), the three e2e spec files named above.
- No changes to `lib/Service/External/**` (adapter interfaces + `Log*Adapter`
  stubs stay, per REQ-ICO-006) and no changes to the webhook receiver
  controllers.
- Dependency-shaped but not a hard `depends_on`: `nav-reachability-gate`
  (queued, not yet implemented) would mechanically re-confirm REQ-ICO-004
  once it lands; this change does not wait for it.
