# Tasks — BBV Compliance

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-bbv-compliance`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `BbvAccountMapping`/`BbvTaakveld` schema and no `bookkeeping-bbv-compliance` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)
- [x] Task 2: Author `specs/bookkeeping-bbv-compliance/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (operations + NL compliance core)` / `Depends on: bookkeeping-chart-of-accounts (T1), bookkeeping-general-ledger (T1)` header, `REQ-BBV-NNN` requirements with RFC 2119 keywords, `#### Scenario:` GIVEN/WHEN/THEN blocks
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec; include Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Reuse Analysis, Seed Data, and Declarative-vs-imperative decision tables; declare the mapping-as-register decision (D1) and the post-precondition decision (D3)
- [x] Task 5: Declare the `BbvAccountMapping` schema in `lib/Settings/shillinq_register.json` with REQ-BBV-002 fields (administrationId, accountNumber, taakveld, programmaCode, paragraafCode, bcfCompensable, iv3Bucket, autorisatieniveau); declare `(administrationId, accountNumber)` unique constraint
- [x] Task 6: Declare the `BbvTaakveld` schema in `lib/Settings/shillinq_register.json` (code, description, parentCode, level) per REQ-BBV-005; loaded from `bbv-taakvelden-2024.json` seed
- [x] Task 7: Extend T1 `GLTransaction.post` lifecycle with the BBV-mapping precondition (scoped to `gemeente`/`provincie`/`waterschap` administration types, forward-only by `postingDate`) per REQ-BBV-003
- [x] Task 8: Ship `lib/Settings/seeds/bbv-taakvelden-2024.json` (full BBV bijlage IV catalogue ~50 codes) with SPDX header + `_meta.bbvVersion: "2024"` per REQ-BBV-005
- [x] Task 9: Ship `lib/Settings/seeds/rgs-to-bbv-mapping.json` (default RGS 3.5 → taakveld mapping ~150 records with bcfCompensable + iv3Bucket) with SPDX header + `_meta.source: "seeded"` per REQ-BBV-006
- [x] Task 10: Extend the repair step under `lib/Migration/` to import the BBV seeds for new `gemeente`/`provincie`/`waterschap` administrations only (skip for non-municipal admins); idempotent on re-run
- [x] Task 11: Add `Overheid > BBV-mapping` navigation + pages to `src/manifest.json` with `type: index` + `type: detail`, visibility predicate scoped to municipal administration types per REQ-BBV-007; `node tests/validate-manifest.js` exits 0
- [x] Task 12: Update `openspec/architecture/adr-000-data-model.md` with the 2 new entities (`BbvAccountMapping`, `BbvTaakveld`) and their `Primary spec:` references

## Verification

`openspec validate` must exit clean on the change folder. Municipal-controller-persona peer review (e.g. `/test-persona-noor`) confirms the mapping shape matches Commissie BBV handreiking guidance. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (mapping as register not enum; post-precondition declarative not service-class; manifest carries navigation; no parallel link tables). No source code changes outside `openspec/changes/add-shillinq-bbv-compliance/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering BBV precondition fails posting for unmapped account on municipal admin, succeeds on non-municipal admin, and bypasses for historic postings; mapping override audit-trail correctness; Playwright MCP browser tests for the BBV-mapping index/detail pages including visibility predicate; `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/bbv-compliance.md` per ADR-030 journeydoc convention and commits a BBV-mapping index screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `BBV`, `Taakveld`, `Programma`, `Paragraaf`, `Autorisatieniveau`, `Compensabele BTW`, `Overheid`, `BBV-mapping`.
