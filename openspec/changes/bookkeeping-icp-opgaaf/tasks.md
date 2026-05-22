# Tasks — ICP-opgaaf (Intra-Community Supplies Filing)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work an `opsx-apply` cycle will execute against the `bookkeeping-icp-opgaaf` spec — they are recorded now so the spec-review gate, dependency planning, and tier-cascade impact are all visible at proposal time. No source files are edited by this change itself.

## Tasks

- [ ] Task 1: Confirm no `bookkeeping-icp-opgaaf` capability spec already exists, no `IcpSupply`/`ViesValidation`/`IcpOpgaaf`/`PeriodicitySwitch` schemas are declared, and no `lib/Service/ICP*` / `lib/Service/Vies*` / `lib/Service/Sbr*` PHP classes are present (per ADR-031 anti-pattern enumeration); confirm `Invoice.icpContext` and `Counterparty.vatId*` extension points are available.

- [ ] Task 2: Author `specs/bookkeeping-icp-opgaaf/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (VAT compliance)` / `Depends on: bookkeeping-accounts-receivable-core, bookkeeping-chart-of-accounts, bookkeeping-vat-btw-filing` header; `REQ-ICP-NNN` requirements using RFC 2119 keywords; `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite Articles 262-271 VAT Directive, Article 37a Wet OB, Article 32 Uitvoeringsregeling OB, Implementing Regulation 282/2011 Article 18 for good-faith defence, SBR NT18 taxonomy, and VIES SOAP/REST endpoints.

- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Approach / Dependencies / Impact / Risks (VIES outage fallback, EUR 50,000 threshold mid-year logic, reconciliation mismatch tolerance, SBR taxonomy updates, triangulation VAT-ID detection) / Rollback / Open Questions (VIES outage retry scheduling, Digipoort pre-filing validation, triangulation auto-detection, VAT-ID caching policy).

- [ ] Task 4: Author `design.md` with Context (VAT filing requirements, penalty schedule, VIES network audit), Goals (declarative metadata, VIES evidence preservation, periodicity auto-switch, reconciliation guards), Non-Goals (no PHP orchestration, no ViDA, no multi-currency), Decisions (D1 ICP as derived register from invoices, D2 VIES as calculation with outage fallback, D3 periodicity threshold-driven, D4 ledger aggregation via `x-openregister-aggregations`, D5 correction filings as separate opgaven, D6 reconciliation on finalize not submit, D7 triangulation separate line), Reuse Analysis, Declarative-vs-imperative, Seed Data examples (3-5 `IcpSupply`, `ViesValidation`, `IcpOpgaaf` records with Dutch values).

- [ ] Task 5: Declare the `ViesValidation` schema in `lib/Settings/shillinq_register.json` with all REQ-ICP-001 + REQ-ICP-009 fields: `id`, `vatId` (canonical form), `validationTimestamp`, `validUntil`, `valid` (boolean), `name`, `address`, `requestId` (Belastingdienst audit proof), `outage` (flag for transient VIES outages), administrationId; Schema.org annotation `schema:VerificationEvent`.

- [ ] Task 6: Declare the `IcpSupply` schema in `lib/Settings/shillinq_register.json` with all REQ-ICP-001 + REQ-ICP-003 + REQ-ICP-006 fields: `id`, `invoiceId` (FK), `invoiceLineId` (FK), `supplyDate` (time-of-supply per Article 64), `buyerVatId` (canonical), `buyerCountry` (ISO 3166-1 alpha-2), `supplyType` (enum: L/S/T), `amountExclVat` (EUR, two decimals, half-even), `viesValidationId` (FK to ViesValidation record), `reportedInOpgaafId` (nullable, set when supply is included in submitted IcpOpgaaf); Schema.org annotation `schema:Transaction`.

- [ ] Task 7: Declare the `IcpOpgaaf` schema in `lib/Settings/shillinq_register.json` with all REQ-ICP-003 through REQ-ICP-010 fields: `id`, `period` (YYYY-Qn or YYYY-MM format), `periodicity` (enum: quarterly/monthly), `status` (enum: draft/finalized/submitted/accepted/rejected/corrected), `type` (enum: initial/correction), `correctsPeriod` (nullable, set for correction filings per REQ-ICP-008), `correctionReason` (text), `lines` (array of {buyerVatId, supplyType, amountExclVat} aggregations), `totalGoods` (sum of L + T), `totalServices` (sum of S), `totalTriangulation` (sum of T only, for reporting clarity), `belastingdienstKenmerk` (reference from submission), `submittedAt` (datetime), `xmlPayload` (archived XBRL per 10-year bewaarplicht), `administrationId` (FK), `auditBundle` (FK to file attachment per REQ-ICP-010); Schema.org annotation `schema:Report`.

- [ ] Task 8: Declare the `PeriodicitySwitch` schema in `lib/Settings/shillinq_register.json` with REQ-ICP-002 fields: `id`, `administrationId` (FK), `switchFrom` (enum: quarterly/monthly), `switchTo` (enum: quarterly/monthly), `triggerDate` (date when threshold was breached), `triggerAmount` (cumulative goods supply amount that triggered), `triggerQuarter` (YYYY-Qn), `effectiveDate` (first day of next month), `status` (enum: active/reversed), `reversedAt` (datetime, set when switch is reverted per REQ-ICP-002), `reversalReason` (text); Schema.org annotation `schema:Event`.

- [ ] Task 9: Extend `Invoice` schema in `lib/Settings/shillinq_register.json` with `icpContext` sub-object per REQ-ICP-001, REQ-ICP-006, REQ-ICP-007: `icpContext: { treatAsIcp: boolean, supplyType: enum(L/S/T), viesValidationId: FK to ViesValidation, triangulation: boolean }`.

- [ ] Task 10: Extend `Counterparty` schema in `lib/Settings/shillinq_register.json` with VAT-ID validation fields per REQ-ICP-001, REQ-ICP-009: `vatId` (canonical), `vatIdValidatedAt` (datetime), `vatIdValidUntil` (datetime), `vatIdValidationStatus` (enum: unchecked/valid/invalid/vies_outage).

- [ ] Task 11: Declare `x-openregister-lifecycle` on `IcpOpgaaf` per REQ-ICP-004, REQ-ICP-005 with states: `draft` → `finalized` (gate: reconciliation check against rubriek 3b per REQ-ICP-004, SBR schema validation per REQ-ICP-005) → `submitted` (approval workflow per ADR-022) → `accepted` / `rejected` / `corrected` (post-Belastingdienst states).

- [ ] Task 12: Implement VIES validation as `x-openregister-calculations` field on `Invoice.icpContext.viesValidationId` per REQ-ICP-001 — call ec.europa.eu/taxation_customs/vies SOAP/REST endpoint, persist `ViesValidation` record, handle outage per REQ-ICP-009 (check prior valid record < 30 days, queue revalidation job).

- [ ] Task 13: Implement SBR XBRL generation as `x-openregister-calculations` field on `IcpOpgaaf.xmlPayload` per REQ-ICP-005 — conform to `bd-rpt-icp-2026.xsd` (NT18), include BSN/RSIN, period in `gl-cor:periodIdentifier`, one `bd-i:IntracommunautairePrestatieMutatieSpecificatie` per line, validate against schema on finalize.

- [ ] Task 14: Implement periodicity auto-switch logic per REQ-ICP-002 — on each `IcpSupply` add/update, sum cumulative goods supplies (supplyType L+T) per calendar quarter, if >= EUR 50,000 create `PeriodicitySwitch` to monthly effective first day of next month; at calendar year boundary offer switch-back to quarterly if all prior 4 quarters were below EUR 50,000.

- [ ] Task 15: Implement ICP ledger aggregation as `x-openregister-aggregations` query per REQ-ICP-003 — GROUP BY `(buyerVatId, supplyType)`, SUM amounts (with sign preservation for credit notes), sort by buyerVatId.

- [ ] Task 16: Implement reconciliation gate on `IcpOpgaaf.finalize()` per REQ-ICP-004 — read period's submitted/draft BTW-aangifte, compare `IcpOpgaaf.total` against `BtwAangifte.rubriek3b`, allow EUR 1 tolerance, block with drill-down if diverge.

- [ ] Task 17: Implement triangulation handling per REQ-ICP-006 — on `Invoice.icpContext.supplyType: T`, validate C-party VAT-ID (not A-party) against VIES, aggregate separately on `(buyerVatId, T)` line (never merged with L or S).

- [ ] Task 18: Extend invoice PDF rendering per REQ-ICP-007 — on `Invoice.icpContext.treatAsIcp: true`, include buyer VAT-ID, seller NL VAT-ID, "BTW verlegd / VAT reverse-charged" notice, supply-type; fail render with `icp.invoice.vatid.missing` if buyer VAT-ID absent.

- [ ] Task 19: Implement correction workflow per REQ-ICP-008 — new `IcpOpgaaf` with `type: correction`, `correctsPeriod`, `correctionReason`; re-attach original `ViesValidation` records (no re-query); support correction as separate filing in Digipoort.

- [ ] Task 20: Implement VIES outage retry scheduling per REQ-ICP-009 — distinguish `valid: false` (definitive rejection) from `outage: true` (transient); schedule daily revalidation job; escalate to bookkeeper after 14 days without answer.

- [ ] Task 21: Implement audit-trail export per REQ-ICP-010 — on `IcpOpgaaf.exportForInspection()`, produce ZIP bundle containing: XBRL payload, Belastingdienst kenmerk, CSV of 47+ supplies with VIES request IDs, source invoice PDFs (via docudesk FK), manifest; deliver within 10 seconds.

- [ ] Task 22: Add 3 chart-of-accounts entries per proposal and design: `8190 Omzet ICP goederen` (zero-rated), `8195 Omzet ICP diensten` (zero-rated), `8196 Omzet ICP driehoekstransacties` (zero-rated), per RGS pattern; routing helper: supplyType L → 8190, S → 8195, T → 8196.

- [ ] Task 23: Add 3 manifest navigation entries per REQ-ICP-003, REQ-ICP-010: `ICP Supplies` (ledger view, lists all supplies aggregated by period/buyer), `ICP Filings` (list of submitted opgaven with status), `ICP Audit Trail` (inspection bundle export + compliance log) — plus their `type: index` and `type: detail` pages.

- [ ] Task 24: Update `openspec/architecture/adr-000-data-model.md` with `ViesValidation`, `IcpSupply`, `IcpOpgaaf`, `PeriodicitySwitch` entries, `Invoice.icpContext` extension, `Counterparty.vatId*` extension fields, reconciling against any existing `Vat*` / `Filing` data-model entries.

- [ ] Task 25: Create integration test fixtures (PHPUnit) for: VIES validation success/failure/outage scenarios, periodicity threshold detection and switch-back, ICP aggregation with credit notes, reconciliation mismatch detection, triangulation line separation, SBR XBRL schema validation, correction filing with evidence preservation, audit-trail ZIP export.

- [ ] Task 26: Create browser tests (Playwright) for: invoice ICP-context tagging, PDF reverse-charge notice rendering, ICP ledger view (period selector, buyer drill-down), filing finalization with reconciliation gate, Digipoort submission workflow (manual or auto if connector ready), audit export link.

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g., `/test-persona-janwillem` for SMB exporting to EU) confirms the ICP flow matches Dutch SMB practice (invoice VIES validation → ICP tagging → quarterly/monthly aggregation → reconciliation against BTW-aangifte → Digipoort filing → audit-trail export for inspection). Compliance reviewer confirms ADR-022 + ADR-031 compliance (no app-local approval table; no PHP VIES orchestration service; no PHP SBR generator; lifecycle declarative or ADR-031-exception-annotated guard; manifest carries navigation). No source code changes outside `openspec/changes/bookkeeping-icp-opgaaf/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for:
- **PHPUnit unit tests** for: VIES validation call + fallback on outage, periodicity threshold detection + switch logic, aggregation by (buyerVatId, supplyType) with credit-note signs, reconciliation mismatch detection, triangulation VAT-ID capture, SBR XBRL composition + schema validation, correction filing with evidence re-attachment, outage revalidation scheduling (pre-declared on Tasks 12–21).
- **Playwright MCP browser tests** for: invoice ICP-context UI, PDF reverse-charge notice, ICP ledger view by period/buyer, finalization + reconciliation gate, Digipoort submission (mock or live depending on connector maturity), audit-trail ZIP export (pre-declared on Task 26).
- **Integration test fixtures** (mock VIES service, mock Digipoort responses, sample invoices with different supply types / countries / validation states).
- `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:
- `docs/user-guide/bookkeeping/icp-filing.md` per ADR-030 journeydoc convention (how to tag invoices as ICP, understand periodicity switch, reconcile with BTW-aangifte, submit to Digipoort, export for inspection).
- Screenshots: invoice ICP-context form, ICP ledger by period, finalization reconciliation gate, PDF reverse-charge notice, audit-trail export dialog.
- Compliance guide: VIES evidence preservation, good-faith defence per Implementing Regulation 282/2011 Article 18, penalty avoidance (timely filing, reconciliation match, SBR schema validation).

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:
- `Intra-Community Supplies`, `ICP Filing`, `ICP Ledger`, `ICP Audit Trail`
- `VIES Validation`, `Valid`, `Invalid`, `Outage`, `Revalidation Required`
- `Supply Type`, `Goods`, `Services`, `Triangulation`
- `Buyer VAT-ID`, `Reverse-Charge`, `Periodicity Switch`, `Quarterly`, `Monthly`
- `Reconciliation Check`, `Mismatch Detected`, `BTW-aangifte Rubriek 3b`, `Export for Inspection`
- `Correction Filing`, `Amended Return`, `Original Period`, `Belastingdienst Reference`
- Error messages: `icp.vatid.invalid`, `icp.reconciliation.mismatch`, `icp.btw.missing`, `icp.xbrl.validation_error`, `icp.invoice.vatid.missing`, `icp.vies.outage_escalated`
