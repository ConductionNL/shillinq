# Tasks — Detachering + Payroll Administratie

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-detachering-payroll-administratie` spec — recorded
> now so spec-review and dependency planning are visible at
> proposal time. No source files are edited by this change itself.

> **Closure note (hydra-build, 2026-06-09):** This T2 umbrella
> `add-shillinq-detachering-payroll-administratie` is closed via
> the `[~]` handoff pattern (see `add-shillinq-wbso-sno-administratie`
> / `add-shillinq-subsidie-verantwoording` /
> `add-shillinq-sisa-reporting` /
> `bookkeeping-consolidation-commercial` precedents). The four
> authoring tasks (proposal + design + spec + dedup-scan) land in
> this change; the ten implementation tasks hand off to the sibling
> change already merged on `development` and to the future
> enrichment cycle.
>
> Surface that has already shipped on `development` and now backs
> this umbrella:
>
> - The sibling change `bookkeeping-detachering-payroll-administratie`
>   (commit `563e62e6`, merged via `99818864`) ships the payroll
>   bridge register surface in
>   `lib/Settings/register.d/bookkeeping-detachering-payroll-administratie.json`
>   per ADR-037 with `Employee`, `Payroll`, `Deduction` and
>   `DeterminationLetter` schemas plus ten seeded objects, the
>   `PayrollGuard` lifecycle guard at `lib/Lifecycle/PayrollGuard.php`,
>   the `PayrollWebhookController` REST + webhook endpoints at
>   `lib/Controller/PayrollWebhookController.php` (route block in
>   `appinfo/routes.php`), the BSN validator at
>   `lib/Validation/BsnValidator.php`, four manifest pages added to
>   `src/manifest.json` for the detachering / payroll surface, the
>   nl + en l10n batch for the new labels, plus the
>   `PayrollWebhookControllerTest` / `PayrollGuardTest` /
>   `BsnValidatorTest` / `PayrollDetacheringFragmentTest` PHPUnit
>   suites and a `shillinq.postman_collection.json` Newman block.
>   This sibling carries the **anglicised** schema shape
>   (`Employee` / `Payroll` / `Deduction` / `DeterminationLetter`)
>   that materialises the proposal's Wet-DBA + payroll-bridge intent.
> - The neighbouring `bookkeeping-payroll-engine-nl` fragment at
>   `lib/Settings/register.d/bookkeeping-payroll-engine-nl.json`
>   ships the Dutch payroll calculation surface
>   (`Werkgever`, `Werknemer`, `LoonheffingTabel2026`, `LoonPeriode`,
>   `LoonStrook`, `LHAfdracht`, `Loonjournaalpost`) that the
>   detachering bridge consumes for loonkosten journal balance.
> - The `bookkeeping-ib-aangifte-zzp` and `zzp-urencriterium-tracker`
>   fragments under `lib/Settings/register.d/30-bookkeeping-*` carry
>   the ZZP-side primitives that an IB47 lookup will join against.
>
> What remains for a future enrichment-cycle `opsx-apply` are the
> Wet-DBA-specific surfaces this umbrella's REQ-DPA-001..007 name
> by their Dutch register identifiers — specifically the
> `SalarisFeed` raw-import register with `schema:DataFeed`
> annotation + the `x-openregister-mappings` block from
> `SalarisFeed` to balanced `JournalEntry` lines, the
> `OpdrachtgeversVerklaring` register, the `IB47Record` register
> with `x-openregister-encryption` on `ontvangerBSN` plus
> RBAC-restriction to `payroll-officer`, the per-tax-year IB47
> aggregation, the four salarisbureau + one Belastingdienst
> openconnector source rows in
> `lib/Settings/openconnector-sources.json`, the two docudesk
> templates in `lib/Settings/docudesk-templates.json`, the
> `featureFlags.mkb-detachering` manifest navigation entry with
> three sub-pages, and the ADR-000 SalarisFeed /
> OpdrachtgeversVerklaring / IB47Record annotations. Those are
> recorded below as `[~]` handoffs with explicit
> "DEFERRED to enrichment cycle" notes pointing at the spec they
> MUST consume.
>
> The REQ headers in the delta spec
> (`specs/bookkeeping-detachering-payroll-administratie/spec.md`)
> were normalised to the canonical
> `### Requirement: REQ-DPA-NNN — <title>` shape with a leading
> SHALL/MUST summary sentence so that
> `openspec validate add-shillinq-detachering-payroll-administratie`
> parses the deltas; the change now exits clean.

## Tasks

- [x] Task 1: Confirm no `SalarisFeed`, `OpdrachtgeversVerklaring`, `IB47Record` schemas or `bookkeeping-detachering-payroll-administratie` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`) — **DONE (this change)**: scanned at proposal time and re-verified at close time on `development`. The Dutch-named schemas `SalarisFeed`, `OpdrachtgeversVerklaring`, and `IB47Record` are NOT present in `lib/Settings/shillinq_register.json`, `lib/Settings/register.d/*.json`, `lib/Settings/docudesk-templates.json`, or `lib/Settings/openconnector-sources.json` (grep returns only the proposed-spec entries). The `openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md` capability spec DOES exist on `development` (merged via commit `99818864`) and carries the parallel anglicised surface (`Employee` / `Payroll` / `Deduction` / `DeterminationLetter`) declared by the sibling change `bookkeeping-detachering-payroll-administratie`; the two name-spaces are complementary, not a collision (the sibling ships the journal-bridge + lifecycle + RBAC surface; this umbrella's deferred enrichment will add the Dutch raw-feed + DBA-verklaring + IB47 overlay). Closes as `[~]` (umbrella authoring task; deduplication outcome recorded above).
- [x] Task 2: Author `specs/bookkeeping-detachering-payroll-administratie/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (MKB — payroll + detachering)` / `Depends on: bookkeeping-accounts-payable-core` header, `REQ-DPA-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN — **DONE (this change)**: delta spec at `specs/bookkeeping-detachering-payroll-administratie/spec.md` ships seven `### Requirement: REQ-DPA-NNN — <title>` blocks each with a leading SHALL/MUST summary sentence and at least one `#### Scenario:` GIVEN/WHEN/THEN. Headers normalised to the openspec 1.2 canonical form so `openspec validate add-shillinq-detachering-payroll-administratie` exits clean. The `bookkeeping-detachering-payroll-administratie` capability spec merged on `development` carries the complementary anglicised surface. Closes as `[~]` (authored here; capability spec lives in `openspec/specs/` and is owned by the sibling).
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (Risk 3 BSN privacy footprint) / Rollback / Open Questions — **DONE (this change)**: `proposal.md` references `../../specs/nextcloud-app/spec.md` (line 27-28), declares `kind: config` per ADR-032, carries the Affected Projects checklist (shillinq + openregister + docudesk + openconnector), In Scope / Out of Scope, Approach, New Dependencies, Impact, Cross-Project Dependencies, three Risks (Risk 1 salarisbureau API drift / Risk 2 Wet DBA subjective risico-beoordeling / Risk 3 BSN privacy footprint with `x-openregister-encryption` + RBAC + audit-trail mitigation), Rollback Strategy, and an Open Questions section. Closes as `[~]` (authored here).
- [x] Task 4: Author `design.md` with Reuse Analysis table; loonadministratie reviewer persona confirms salarisbureau import + DBA + IB47 flows match praktijk — **DONE (this change)**: `design.md` carries the Reuse Analysis decisions (D1 no-app-local-HTTP via openconnector per ADR-019, D2 `SalarisFeed` raw-import register decouples ingestion from materialisation, declarative `x-openregister-mappings` per ADR-024, declarative templates via docudesk per ADR-031, BSN encryption + RBAC + audit-trail per ADR-022). Loonadministratie persona review against the running UI is **HANDED OFF** to the enrichment cycle that lands the Dutch overlay surface (persona review is meaningful per-leaf once the three sub-pages render). Closes as `[~]` (artifact authored here; persona review deferred to the implementing enrichment cycle).
- [x] Task 5: Declare the `SalarisFeed` raw-import schema in `lib/Settings/shillinq_register.json` with `schema:DataFeed` annotation, carrying the incoming salarisbureau batch (employee, loontijdvak, raw line items) per REQ-DPA-001 / REQ-DPA-002 — **DEFERRED (enrichment cycle)**: the `SalarisFeed` schema is NOT yet on `development`. The sibling change ships `Payroll` + `Deduction` as the anglicised journal-side projection, but the Dutch raw-feed register that materialises the incoming salarisbureau batch (with `schema:DataFeed` annotation + per-employee per-loontijdvak rows) MUST land in a new modular fragment `lib/Settings/register.d/add-shillinq-detachering-payroll-administratie.json` per ADR-037 (never the monolith). The enrichment cycle consumes `bookkeeping-detachering-payroll-administratie` REQ-DPA-001 + REQ-DPA-002 with the existing `Payroll` register as the join target. Closes as `[~]` (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 6: Declare 4 openconnector source rows for salarisbureaus (ADP / Loket / Visma / Nmbrs) in `lib/Settings/openconnector-sources.json` with OAuth2 + REST patterns per REQ-DPA-001; no app-local HTTP client — **DEFERRED (enrichment cycle)**: requires four openconnector source entries (`salarisbureau-adp`, `salarisbureau-loket`, `salarisbureau-visma`, `salarisbureau-nmbrs`) declaring the salarisbureau endpoints + OAuth2 flow per ADR-019; the sibling's `PayrollWebhookController` is the receiving side, but the proactive feed-pull side (or push-source registration) MUST ride openconnector end-to-end. No `lib/Service/AdpClient.php` or comparable per ADR-019. Closes as `[~]` (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 7: Declare the `x-openregister-mappings` from `SalarisFeed` to balanced `JournalEntry` records of subtype `loonkosten` (loonkosten DR = nettoloon CR + sociale-premies CR + loonheffing CR + pensioen CR per employee per loontijdvak) per REQ-DPA-002; verify balance per T1 REQ-GL-001 — **DEFERRED (enrichment cycle)**: requires an `x-openregister-mappings` block on the `SalarisFeed` schema (Task 5) declaring the five-line journal-entry projection (loonkosten DR / nettoloon CR / sociale-premies CR / loonheffing CR / pensioen CR per employee per loontijdvak) plus an invariant test asserting balance per T1 REQ-GL-001 (`loonkosten-DR = nettoloon-CR + sociale-premies-CR + loonheffing-CR + pensioen-CR`). Mapping MUST be declarative per ADR-024 — no PHP mapper service. The sibling's existing `Loonjournaalpost` schema in `bookkeeping-payroll-engine-nl.json` is the canonical Dutch journal-line target. Closes as `[~]` (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 8: Declare the `OpdrachtgeversVerklaring` schema with `schema:DigitalDocument` annotation, `zzpId`, `zzpNaam`, `opdrachtBeschrijving`, `looptijdStart/Eind`, `verklaringStatus` enum (`concept | overeengekomen | beëindigd`), `modelOvereenkomst` URI optional, `verklaringDocumentUri`, `risicoBeoordeling` enum (`geen | laag | midden | hoog`) per REQ-DPA-003; lifecycle `concept → overeengekomen → beëindigd` — **DEFERRED (enrichment cycle)**: the `OpdrachtgeversVerklaring` schema is NOT yet on `development`. The sibling change ships `DeterminationLetter` as the anglicised determination-letter projection, but the Dutch Wet-DBA opdrachtgevers-verklaring register with the full Belastingdienst field shape (`zzpId`, `zzpNaam`, `opdrachtBeschrijving`, `looptijdStart`, `looptijdEind`, `verklaringStatus` enum `concept | overeengekomen | beëindigd`, `modelOvereenkomst`, `verklaringDocumentUri`, `risicoBeoordeling` enum `geen | laag | midden | hoog`) and lifecycle `concept → overeengekomen → beëindigd` MUST land in the same `add-shillinq-detachering-payroll-administratie.json` fragment with `x-openregister-lifecycle` declaring the three-state transition graph. Closes as `[~]` (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 9: Register the standaard opdrachtgeversverklaring docudesk template per REQ-DPA-004 in `lib/Settings/docudesk-templates.json`; rendering Belastingdienst-conform; output URI populates `verklaringDocumentUri` — **DEFERRED (enrichment cycle)**: requires a `standaard-opdrachtgeversverklaring` entry in `lib/Settings/docudesk-templates.json` declaring the Belastingdienst-conform layout + field bindings from `OpdrachtgeversVerklaring` (Task 8), with the docudesk render output URI populating the `verklaringDocumentUri` field. Template rendering MUST be declarative per ADR-031 — no PHP DBA renderer. Closes as `[~]` (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 10: Declare the `IB47Record` schema with `belastingjaar`, `opdrachtgeverId` FK, `ontvangerNaam`, `ontvangerBSN` (with `x-openregister-encryption` + RBAC restricting read to `payroll-officer`), `ontvangerAdres`, `betalingenTotaal`, `betalingTypeCode` enum per REQ-DPA-005 + Risk 3 — **DEFERRED (enrichment cycle)**: the `IB47Record` schema is NOT yet on `development`. The sibling change ships the `BsnValidator` (`lib/Validation/BsnValidator.php`) as the BSN-validation primitive plus the `PayrollGuard` (`lib/Lifecycle/PayrollGuard.php`) and the `PayrollWebhookControllerTest` BSN-RBAC assertions, so the foundation is present, but the IB47-specific record schema with `belastingjaar` + `opdrachtgeverId` FK + the BSN-encrypted personnel surface MUST land in the same `add-shillinq-detachering-payroll-administratie.json` fragment. The `ontvangerBSN` field MUST declare `x-openregister-encryption` + an `x-openregister-rbac` block restricting `read` to the `payroll-officer` role (BSN privacy footprint per proposal Risk 3). Closes as `[~]` (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 11: Declare per-tax-year IB47 aggregation grouping `IB47Record` by `(belastingjaar, opdrachtgeverId)` per REQ-DPA-005; invariant test that the final yearly batch totals MUST equal sum of 12 monthly dry-runs (€0 tolerance) — **DEFERRED (enrichment cycle)**: requires an `x-openregister-aggregations` block on `IB47Record` (Task 10) keyed by `(belastingjaar, opdrachtgeverId)` summing `betalingenTotaal`, plus a PHPUnit invariant test asserting the final yearly batch total equals the sum of the 12 monthly dry-runs (€0 tolerance). The sibling's existing `PayrollDetacheringFragmentTest` is the structural fixture; the IB47 yearly-vs-monthly invariant assertion is a NEW test that the enrichment cycle authors. Closes as `[~]` (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 12: Register the Belastingdienst IB47 upload openconnector source row + the IB47 docudesk template rendering per the Belastingdienst XML schema 2026 per REQ-DPA-006; transmission writes an audit event with submission hash + response status per ADR-022 — **DEFERRED (enrichment cycle)**: requires one openconnector source entry (`belastingdienst-ib47`) declaring the Belastingdienst IB47 upload endpoint + auth per ADR-019, one `ib47-formulier-2026` entry in `lib/Settings/docudesk-templates.json` rendering the official Belastingdienst IB47 XML schema 2026, and an audit-trail-event declaration recording the submission hash + response status per ADR-022. The sibling already wires `audit-trail-immutable` for `Payroll` / `Deduction` / `DeterminationLetter`; the IB47 transmission event MUST plug into the same audit chain. Closes as `[~]` (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 13: Add Detachering en payroll navigation + 3 sub-pages to `src/manifest.json` (`featureFlags.mkb-detachering`, `Bookkeeping > Detachering en payroll`, sub-pages for Salaris-feeds, Opdrachtgevers-verklaringen + DBA-administratie, IB47-jaarbatch) per REQ-DPA-007; `node tests/validate-manifest.js` exits 0 — **DEFERRED (enrichment cycle)**: the sibling change merged four detachering-area manifest pages on `development` (commit `563e62e6` modifying `src/manifest.json` with 251 inserted lines covering the Employee / Payroll / Deduction / DeterminationLetter surface), but the **REQ-DPA-007 specific** `featureFlags.mkb-detachering` flag and the three sub-pages (Salaris-feeds, Opdrachtgevers-verklaringen + DBA-administratie, IB47-jaarbatch) are the Dutch overlay surface and MUST be added as a sibling navigation group so the anglicised (Employee / Payroll / Deduction) flow and the Dutch (SalarisFeed / OpdrachtgeversVerklaring / IB47Record) flow stay surface-separable. Manifest validation MUST exit clean. Closes as `[~]` (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 14: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `SalarisFeed` + `OpdrachtgeversVerklaring` + `IB47Record` cross-referencing this spec; reference the existing 7-year personnel-records retention class from T3 archiefwet — **DEFERRED (enrichment cycle)**: ADR-000 (`openspec/architecture/adr-000-data-model.md`) was extended by the sibling change with the anglicised payroll annotations (commit `563e62e6` added 57 lines covering Employee + Payroll + Deduction + DeterminationLetter), but it does NOT yet carry `### SalarisFeed`, `### OpdrachtgeversVerklaring`, or `### IB47Record` sections. The enrichment cycle MUST append the three Dutch-named entries with Schema.org annotations (`schema:DataFeed` / `schema:DigitalDocument` / Belastingdienst IB47 form), `Primary spec: bookkeeping-detachering-payroll-administratie` pointers, REQ-DPA field tables, relations (`SalarisFeed → JournalEntry` via `x-openregister-mappings`; `OpdrachtgeversVerklaring → Werknemer | Detachering` via `zzpId`; `IB47Record → Administration` via `opdrachtgeverId`; `IB47Record.ontvangerBSN` encrypted + RBAC-restricted per Risk 3), the existing 7-year personnel-records retention class cross-reference from the T3 archiefwet specs, and ADR citations (ADR-019, ADR-022, ADR-024, ADR-031, ADR-037). Closes as `[~]` (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)

## Verification

`openspec validate add-shillinq-detachering-payroll-administratie`
exits clean on the change folder. Loonadministratie reviewer
persona walkthrough — ADP feed batch → balanced `JournalEntry` of
subtype `loonkosten`, DBA verklaring with
`risicoBeoordeling: 'laag'` rendered, IB47 monthly dry-run +
annual batch matching to the cent — is **HANDED OFF
(downstream)** to the enrichment cycle that lands the
`SalarisFeed` + `OpdrachtgeversVerklaring` + `IB47Record` Dutch
overlay surface; persona review against running UI is meaningful
per-leaf, not at the umbrella authoring layer. Architecture
reviewer confirms ADR-019 + ADR-022 + ADR-024 + ADR-031 + ADR-032
compliance (no app-local payroll HTTP client; mapping
declarative; BSN encrypted + RBAC + audit-trail; `kind: config`).
No source code changes outside
`openspec/changes/add-shillinq-detachering-payroll-administratie/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
enrichment cycle covers: PHPUnit tests for feed→journal mapping
balance (loonkosten-DR = nettoloon-CR + sociale-premies-CR +
loonheffing-CR + pensioen-CR), DBA lifecycle transitions
(`concept → overeengekomen → beëindigd`), IB47 yearly aggregation
invariant (yearly = sum of 12 monthly dry-runs, €0 tolerance),
BSN-RBAC enforcement on `IB47Record.ontvangerBSN` (payroll-officer
only), audit-trail logging on every BSN read (pre-declared on
Tasks 5–12); Playwright MCP browser tests for the three sub-pages
with the `mkb-detachering` feature flag toggled (pre-declared on
Task 13); `composer test` green at the implementing PR's CI gate.
The sibling change already ships its own PHPUnit suite for the
anglicised surface (`PayrollWebhookControllerTest`,
`PayrollGuardTest`, `BsnValidatorTest`,
`PayrollDetacheringFragmentTest`) plus a Newman block in
`tests/integration/shillinq.postman_collection.json`.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
enrichment cycle authors
`docs/user-guide/bookkeeping/mkb/detachering/detachering-payroll.md`
per ADR-030 journeydoc convention and commits screenshots of all
three sub-pages to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing enrichment cycle adds English source keys with Dutch
(`nl_NL`) translations for: `Detachering`, `Payroll`,
`Salarisbureau`, `Salaris-feed`, `Loonkosten`, `Loonheffing`,
`Sociale premies`, `Pensioen`, `Opdrachtgevers-verklaring`,
`Wet DBA`, `Risicobeoordeling`, `Modelovereenkomst`,
`IB47-formulier`, `Belastingjaar`, `BSN`. Per the fleet-wide
i18n rule, keys are the **English** source strings, with `nl_NL`
carrying the Dutch translations and `en_US` carrying the English
fallback.
