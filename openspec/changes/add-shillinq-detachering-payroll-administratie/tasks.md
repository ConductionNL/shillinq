# Tasks — Detachering + Payroll Administratie

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-detachering-payroll-administratie` spec — recorded
> now so spec-review and dependency planning are visible at
> proposal time. No source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `SalarisFeed`, `OpdrachtgeversVerklaring`, `IB47Record` schemas or `bookkeeping-detachering-payroll-administratie` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`)
- [x] Task 2: Author `specs/bookkeeping-detachering-payroll-administratie/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (MKB — payroll + detachering)` / `Depends on: bookkeeping-accounts-payable-core` header, `REQ-DPA-NNN` requirements, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks (Risk 3 BSN privacy footprint) / Rollback / Open Questions
- [x] Task 4: Author `design.md` with Reuse Analysis table; loonadministratie reviewer persona confirms salarisbureau import + DBA + IB47 flows match praktijk
- [x] Task 5: Declare the `SalarisFeed` raw-import schema in `lib/Settings/register.d/add-shillinq-detachering-payroll-administratie.json` with `schema:DataFeed` annotation, carrying the incoming salarisbureau batch (employee, loontijdvak, raw line items) per REQ-DPA-001 / REQ-DPA-002
- [x] Task 6: Declare 4 openconnector source rows for salarisbureaus (ADP / Loket / Visma / Nmbrs) in `lib/Settings/openconnector-sources.json` with OAuth2 + REST patterns per REQ-DPA-001; no app-local HTTP client
- [x] Task 7: Declare the `x-openregister-mappings` from `SalarisFeed` to balanced `JournalEntry` records of subtype `loonkosten` (loonkosten DR = nettoloon CR + sociale-premies CR + loonheffing CR + pensioen CR per employee per loontijdvak) per REQ-DPA-002; verify balance per T1 REQ-GL-001
- [x] Task 8: Declare the `OpdrachtgeversVerklaring` schema with `schema:DigitalDocument` annotation, `zzpId`, `zzpNaam`, `opdrachtBeschrijving`, `looptijdStart/Eind`, `verklaringStatus` enum (`concept | overeengekomen | beëindigd`), `modelOvereenkomst` URI optional, `verklaringDocumentUri`, `risicoBeoordeling` enum (`geen | laag | midden | hoog`) per REQ-DPA-003; lifecycle `concept → overeengekomen → beëindigd`
- [x] Task 9: Register the standaard opdrachtgeversverklaring docudesk template per REQ-DPA-004 in `lib/Settings/docudesk-templates.json`; rendering Belastingdienst-conform; output URI populates `verklaringDocumentUri`
- [x] Task 10: Declare the `IB47Record` schema with `belastingjaar`, `opdrachtgeverId` FK, `ontvangerNaam`, `ontvangerBSN` (with `x-openregister-encryption` + RBAC restricting read to `payroll-officer`), `ontvangerAdres`, `betalingenTotaal`, `betalingTypeCode` enum per REQ-DPA-005 + Risk 3
- [x] Task 11: Declare per-tax-year IB47 aggregation grouping `IB47Record` by `(belastingjaar, opdrachtgeverId)` per REQ-DPA-005; invariant test that the final yearly batch totals MUST equal sum of 12 monthly dry-runs (€0 tolerance)
- [x] Task 12: Register the Belastingdienst IB47 upload openconnector source row + the IB47 docudesk template rendering per the Belastingdienst XML schema 2026 per REQ-DPA-006; transmission writes an audit event with submission hash + response status per ADR-022
- [x] Task 13: Add Detachering en payroll navigation + 3 sub-pages to `src/manifest.d/add-shillinq-detachering-payroll-administratie.json` (`featureFlag: mkb-detachering`, `Bookkeeping > Detachering en payroll`, sub-pages for Salaris-feeds, Opdrachtgevers-verklaringen + DBA-administratie, IB47-jaarbatch) per REQ-DPA-007
- [x] Task 14: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `SalarisFeed` + `OpdrachtgeversVerklaring` + `IB47Record` cross-referencing this spec; reference the existing 7-year personnel-records retention class from T3 archiefwet

## Verification

`openspec validate` must exit clean on the change folder.
Loonadministratie reviewer persona walks through worked examples —
ADP feed batch → balanced JournalEntry of subtype `loonkosten`,
DBA verklaring with `risicoBeoordeling: 'laag'` rendered, IB47
monthly dry-run + annual batch matching to the cent. Architecture
reviewer confirms ADR-019 + ADR-022 + ADR-024 + ADR-031 + ADR-032
compliance (no app-local payroll HTTP client; mapping declarative;
BSN encrypted + RBAC + audit-trail). No source code changes outside
`openspec/changes/add-shillinq-detachering-payroll-administratie/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementing
cycle covers: PHPUnit tests for feed→journal mapping balance,
DBA lifecycle transitions, IB47 yearly aggregation invariant,
BSN-RBAC enforcement, audit-trail logging on every BSN read
(pre-declared on Tasks 5–12); Playwright MCP browser tests for the
three sub-pages (pre-declared on Task 13); `composer test` green
at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementing
cycle authors
`docs/user-guide/bookkeeping/mkb/detachering/detachering-payroll.md`
per ADR-030 journeydoc convention and commits screenshots of all
three sub-pages to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementing cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `Detachering`, `Payroll`, `Salarisbureau`,
`Salaris-feed`, `Loonkosten`, `Loonheffing`, `Sociale premies`,
`Pensioen`, `Opdrachtgevers-verklaring`, `Wet DBA`,
`Risicobeoordeling`, `Modelovereenkomst`, `IB47-formulier`,
`Belastingjaar`, `BSN`.
