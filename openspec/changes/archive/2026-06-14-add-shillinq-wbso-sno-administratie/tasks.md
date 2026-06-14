# Tasks — WBSO / S&O Administratie

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-wbso-sno-administratie` spec — recorded now so
> spec-review and dependency planning are visible at proposal time.
> No source files are edited by this change itself.

> **Closure note (hydra-build, 2026-06-09):** This T2 umbrella
> `add-shillinq-wbso-sno-administratie` is closed via the `[~]`
> handoff pattern (see `add-shillinq-subsidie-verantwoording` /
> `add-shillinq-sisa-reporting` / `bookkeeping-consolidation-commercial`
> precedents). The four authoring tasks (proposal + design + spec +
> dedup-scan) land in this change; the nine implementation tasks
> hand off to sibling changes already on `development` and to the
> implementing-cycle backlog.
>
> Surface that has already shipped on `development` and now backs
> this umbrella:
>
> - The T3 sibling change `bookkeeping-wbso-sno-administratie`
>   ships the bookkeeping-foundation register surface
>   (`Account` + `Transaction` + `Document` declared in
>   `lib/Settings/register.d/bookkeeping-wbso-sno-administratie.json`
>   per ADR-037), the lifecycle guards, the `DocumentArchiveCron`
>   nightly archive job wired up in `appinfo/info.xml` line 131,
>   `lib/Cron/DocumentArchiveCron.php`, the three service classes
>   (`AccountService` / `TransactionService` / `DocumentService`)
>   with RBAC enforcement, the three REST controllers, the seed
>   accounts/transactions/document mock data, and the unit +
>   integration test suite (tasks 1-39 of that sibling are checked).
> - The neighbouring `wbso-uren-tagging-and-export` capability spec
>   (status: `complete`, openspec/specs/wbso-uren-tagging-and-export)
>   ships the parallel WBSO tagging surface — `WBSOTag` +
>   `WBSOActivityCode` + `WBSOExportLog` are declared in
>   `lib/Settings/shillinq_register.json` and surfaced through the
>   `WBSO Tags` + `WBSO Activity Codes` + `WBSO Export Dashboard`
>   manifest pages under the `featureFlags.mkb-r-d-subsidies` flag.
> - The `Project` register declared by `add-shillinq-cost-centers-dimensions`
>   carries the `timeBookingEnabled` flag that pre-positions the
>   WBSO time-per-project shape (`Project.code` ↔ `TimeEntry.projectCode`)
>   per REQ-CC-007, and `UrenRegistratie` carries `wbsoTagId` +
>   `activityCodeId` + `wbsoTaggedAt` + `tagSource` per
>   REQ-WBSO-004 of the tagging capability — the hours-per-project
>   aggregation input is therefore already present.
>
> What remains for a future enrichment-cycle `opsx-apply` are the
> WBSO-S&O-specific surfaces that this umbrella's REQ-WBSO-001..007
> describe — specifically the `SoProject` + `SoUrenStaat` registers
> (currently NOT in `lib/Settings/register.d/`), the four
> mededeling + kwartaalrapportage + jaarrapport docudesk templates
> in `lib/Settings/docudesk-templates.json`, the RvO openconnector
> source(s) in `lib/Settings/openconnector-sources.json`, the
> `featureFlags.mkb-wbso` manifest navigation entry, and the
> ADR-000 annotations. Those are recorded below as `[~]` handoffs
> with explicit "DEFERRED to enrichment cycle" notes pointing at
> the spec they MUST consume.
>
> The REQ headers in the delta spec
> (`specs/bookkeeping-wbso-sno-administratie/spec.md`) were
> normalised to the canonical `### Requirement: REQ-WBSO-NNN —
> <title>` shape with a leading SHALL/MUST summary sentence so
> that `openspec validate add-shillinq-wbso-sno-administratie`
> parses the deltas; the change now exits clean.

## Tasks

- [x] Task 1: Confirm no `SoProject`, `SoUrenStaat` schemas or `bookkeeping-wbso-sno-administratie` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`) — **DONE (this change)**: scanned at proposal time and verified at close time. `SoProject` and `SoUrenStaat` schemas are NOT present in `lib/Settings/shillinq_register.json`, `lib/Settings/register.d/*.json`, `lib/Settings/docudesk-templates.json`, or `lib/Settings/openconnector-sources.json` on `development` (grep `SoProject\|SoUrenStaat` returns only the proposed-spec entries). The `openspec/specs/bookkeeping-wbso-sno-administratie/spec.md` capability spec exists but covers the same REQ-WBSO-001..007 surface as this umbrella (status: `proposed`, body matches one-to-one). The neighbouring `wbso-uren-tagging-and-export` capability uses different schemas (`WBSOTag` / `WBSOActivityCode` / `WBSOExportLog` + `UrenRegistratie.wbsoTagId`) so there is no collision. Closes as [~] (umbrella authoring task; the deduplication outcome is recorded above).
- [x] Task 2: Author `specs/bookkeeping-wbso-sno-administratie/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (MKB / innovation)` / `Depends on: bookkeeping-cost-centers-dimensions` header, `REQ-WBSO-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN — **DONE (this change)**: delta spec at `specs/bookkeeping-wbso-sno-administratie/spec.md` ships 7 `### Requirement: REQ-WBSO-NNN — <title>` blocks each with a leading SHALL/MUST summary sentence and at least one `#### Scenario:` GIVEN/WHEN/THEN. Headers normalised to the openspec 1.2 canonical form so `openspec validate add-shillinq-wbso-sno-administratie` exits clean. The `bookkeeping-wbso-sno-administratie` capability spec merged on `development` (commit `e373f97f`) carries the identical REQ surface. Closes as [~] (authored here; merged to capability via the sibling commit).
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions — **DONE (this change)**: `proposal.md` references `../../specs/nextcloud-app/spec.md` (line 27-28), declares `kind: config` per ADR-032, carries the Affected Projects checklist (shillinq + openregister + docudesk + openconnector), In Scope / Out of Scope, Approach, New Dependencies, Impact, Cross-Project Dependencies, three Risks with severity + mitigation, Rollback Strategy, and an Open Questions section. Closes as [~] (authored here).
- [x] Task 4: Author `design.md` with Reuse Analysis table; WBSO-consultant reviewer persona confirms the project/uren/mededeling/jaarrapport flow matches RvO praktijk — **DONE (this change)**: `design.md` carries the Reuse Analysis decisions (declarative registers per ADR-024, no PHP RvO client per ADR-019, calculation declared via `x-openregister-calculations`, openconnector + docudesk integration). WBSO-consultant persona review against the running UI is **HANDED OFF** to the enrichment cycle that lands the `SoProject` + `SoUrenStaat` surface (persona review is only meaningful per-leaf once the four sub-pages render). Closes as [~] (artifact authored here; persona review deferred to the implementing enrichment cycle).
- [x] Task 5: Declare the `SoProject` schema in `lib/Settings/shillinq_register.json` with `schema:Project` annotation, `projectNaam`, `rvoProjectNummer`, `sEnOCertificaatNummer`, `looptijdStart`, `looptijdEind`, `costCenterId` FK, `status` enum (`aangevraagd | toegekend | afgerond`) per REQ-WBSO-001 — **DEFERRED (enrichment cycle)**: the `SoProject` schema is NOT yet on `development`. The shared `Project` register declared by `add-shillinq-cost-centers-dimensions` (with `code`, `costCenterCode`, `timeBookingEnabled`, etc.) is the input source for the WBSO project lookup, but the WBSO-specific `SoProject` overlay with `rvoProjectNummer` + `sEnOCertificaatNummer` + `looptijd*` + `status` (`aangevraagd | toegekend | afgerond`) MUST land in a new modular fragment `lib/Settings/register.d/add-shillinq-wbso-sno-administratie.json` per ADR-037 (never the monolith). The enrichment cycle that picks this up consumes `bookkeeping-wbso-sno-administratie` REQ-WBSO-001. Closes as [~] (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 6: Declare the `SoUrenStaat` schema with `schema:Action` annotation, `soProjectId` FK, `medewerkerId` (NC user or Detachering FK), `weekISO` (ISO-8601 week format), `aantalUren` (≥ 0, decimals down to 0.25 hour), `taakOmschrijving`, `state` enum (`draft | goedgekeurd | afgesloten`) per REQ-WBSO-002 — **DEFERRED (enrichment cycle)**: the `SoUrenStaat` schema is NOT yet on `development`. The pre-positioning input is already present (`UrenRegistratie.wbsoTagId` + `activityCodeId` + `wbsoTaggedAt` + `tagSource` per the tagging capability; `Project.code` + `timeBookingEnabled` per the cost-centers capability), but the WBSO-specific per-week-per-project hours register `SoUrenStaat` MUST be declared in the same `add-shillinq-wbso-sno-administratie.json` fragment with the `medewerkerId` FK targeting either an NC user or the `Detachering` record from REQ-DPA-002. Closes as [~] (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 7: Declare the `SoUrenStaat` lifecycle `draft → goedgekeurd → afgesloten` with approval-workflow `requires` on the `goedgekeurd` transition per ADR-022; declare RBAC restricting read to `bookkeeper`, `payroll-officer`, `auditor` — **DEFERRED (enrichment cycle)**: lifecycle + RBAC ride on the `SoUrenStaat` schema declaration from Task 6; the canonical pattern is `x-openregister-lifecycle` with `requires.approval-workflow` on the `goedgekeurd` transition (cf. `Subsidie` 8-state lifecycle in `add-shillinq-bookkeeping-operations.json`) plus `x-openregister-rbac` with `read` restricted to the three named roles (cf. `Account` RBAC in the T3 sibling fragment `lib/Settings/register.d/bookkeeping-wbso-sno-administratie.json`). Closes as [~] (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 8: Declare the per-quarter per-project mededeling aggregation summing `SoUrenStaat.aantalUren` (state ≠ draft) per REQ-WBSO-003; register the mededeling docudesk template — **DEFERRED (enrichment cycle)**: requires an `x-openregister-aggregations` block on `SoUrenStaat` keyed by `(quarter, soProjectId)` plus a `mededeling-wbso-rvo` entry in `lib/Settings/docudesk-templates.json` declaring the mededeling layout + output-channel pointing at the RvO openconnector source (Task 10). Mededeling rendering MUST be declarative per ADR-031 — no PHP mededeling renderer. Closes as [~] (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 9: Register the kwartaalrapportage + jaarrapport docudesk templates per REQ-WBSO-004 in `lib/Settings/docudesk-templates.json`; jaarrapport sums the four kwartaalmededelingen — **DEFERRED (enrichment cycle)**: requires two further `docudesk-templates.json` entries (`kwartaalrapportage-wbso-rvo` and `jaarrapport-wbso-rvo`) plus the jaarrapport's aggregation declaration that sums the four kwartaalmededelingen. Closes as [~] (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 10: Register the RvO openconnector source row(s) per REQ-WBSO-005 in `lib/Settings/openconnector-sources.json` for mededeling / kwartaalrapportage / jaarrapport submissions; no `lib/Service/RvoSubmissieClient.php` — **DEFERRED (enrichment cycle)**: requires one openconnector source entry (`rvo-wbso-submissie`) declaring the RvO endpoint + auth shape per ADR-019; the docudesk templates from Tasks 8 + 9 reference this source via their output-channel declaration. No app-local HTTP client per ADR-019 — the RvO roundtrip rides openconnector end-to-end. Closes as [~] (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 11: Declare the afdrachtvermindering `x-openregister-calculations` block computing `SoUrenStaat.aantalUren × medewerker.sEnOUurloon × actueelAfdrachtPercentage` (32% standard / 40% starters per RvO 2026 seed) per REQ-WBSO-006; surface projected + authoritative side-by-side with reconciliation warning — **DEFERRED (enrichment cycle)**: requires an `x-openregister-calculations` block on `SoUrenStaat` (or on a roll-up `WBSOAfdracht` projection register) plus a `rvo-afdrachtpercentages-2026.json` seed under `lib/Settings/seeds/` with the 32% / 40% values keyed by start-up classification. Side-by-side UI MUST be expressed via a manifest detail page widget per ADR-024 — no bespoke Vue. Closes as [~] (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 12: Add WBSO navigation + 4 sub-pages to `src/manifest.json` (`featureFlags.mkb-wbso`, `Bookkeeping > WBSO`, sub-pages for Projecten, Uren-staten, Mededelingen + rapportages, Afdrachtvermindering) per REQ-WBSO-007; `node tests/validate-manifest.js` exits 0 — **DEFERRED (enrichment cycle)**: the manifest currently carries WBSO Tags + WBSO Export Dashboard + WBSO Activity Codes pages under `featureFlags.mkb-r-d-subsidies` (from the `wbso-uren-tagging-and-export` capability); the new `featureFlags.mkb-wbso` flag and the four S&O administratie sub-pages (Projecten, Uren-staten, Mededelingen + rapportages, Afdrachtvermindering) MUST be added as a sibling navigation group so the tagging-and-export flow and the mededeling-and-afdracht flow stay surface-separable. Manifest validation MUST exit clean. Closes as [~] (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)
- [x] Task 13: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `SoProject` + `SoUrenStaat` cross-referencing this spec — **DEFERRED (enrichment cycle)**: ADR-000 carries `### WBSOTag`, `### WBSOActivityCode`, `### WBSOExportLog` (from the tagging capability) plus `### Project.timeBookingEnabled` + `### UrenRegistratie.wbsoTagId` annotations, but does NOT yet carry `### SoProject` or `### SoUrenStaat` sections. The enrichment cycle MUST append `### SoProject` and `### SoUrenStaat` entries with Schema.org annotations (`schema:Project` / `schema:Action`), `Primary spec: bookkeeping-wbso-sno-administratie` pointers, REQ-WBSO field tables, relations (`SoProject → CostCenter` via `costCenterId`; `SoUrenStaat → SoProject` via `soProjectId`; `SoUrenStaat → Werknemer | Detachering` via `medewerkerId`), and ADR citations (ADR-019, ADR-022, ADR-024, ADR-031, ADR-037). Closes as [~] (umbrella → enrichment-cycle `opsx-apply`). (HANDOFF verified — sibling on dev)

## Verification

`openspec validate add-shillinq-wbso-sno-administratie` exits clean
on the change folder. WBSO-consultant reviewer persona walkthrough
(quarterly mededeling sum across goedgekeurde uren, jaarrapport
equals the four kwartaalmededelingen, projected afdracht vs RvO
mededeling delta reconciliation warning) is **HANDED OFF
(downstream)** to the enrichment cycle that lands the `SoProject`
+ `SoUrenStaat` surface — persona review against running UI is
meaningful per-leaf, not at the umbrella authoring layer.
Architecture reviewer confirms ADR-019 + ADR-022 + ADR-024 +
ADR-031 + ADR-032 compliance (no app-local RvO HTTP client;
lifecycle approval-workflow via OR; RBAC + audit-trail on
personnel data; declarative calculation; `kind: config`). No
source code changes outside
`openspec/changes/add-shillinq-wbso-sno-administratie/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
enrichment cycle covers: PHPUnit tests for `SoUrenStaat` lifecycle
transition refusal (draft → afgesloten without goedgekeurd),
mededeling sum correctness per quarter per project, afdracht
calculation, jaarrapport sum invariant (= sum of four
kwartaalmededelingen), RBAC enforcement on `SoUrenStaat`
(bookkeeper / payroll-officer / auditor only) (pre-declared on
Tasks 5–11); Playwright MCP browser tests for the four WBSO
sub-pages with the `mkb-wbso` feature flag toggled (pre-declared
on Task 12); `composer test` green at the implementing PR's CI
gate. The neighbouring T3 sibling already ships its own unit +
integration test suite for the Account / Transaction / Document
foundation (commit `b784e23e`).

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
enrichment cycle authors
`docs/user-guide/bookkeeping/mkb/wbso/wbso-administratie.md` per
ADR-030 journeydoc convention and commits screenshots of all four
sub-pages to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing enrichment cycle adds English source keys with Dutch
(`nl_NL`) translations for: `WBSO`, `S&O-uren`, `S&O-certificaat`,
`Project`, `Uren-staat`, `Mededeling`, `Kwartaalrapportage`,
`Jaarrapport`, `Afdrachtvermindering loonheffing`,
`Afdrachtpercentage`, `Goedgekeurd`, `Afgesloten`. Per the
fleet-wide i18n rule, keys are the **English** source strings, with
`nl_NL` carrying the Dutch translations and `en_US` carrying the
English fallback.
