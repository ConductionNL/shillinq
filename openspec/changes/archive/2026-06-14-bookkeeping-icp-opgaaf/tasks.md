# Tasks — ICP-opgaaf (Intra-Community Supplies Filing)

> **Implementation cycle (hydra-build).** The proposal framed this as spec-only; this build executes the implementation against the `bookkeeping-icp-opgaaf` spec per ADR-037 (modular register fragment, never edit the monolith), ADR-031 (declarative-first metadata + engine-side fallback only for what the engine cannot express), ADR-022 (OpenRegister approval-workflow, no app-local submission table) and ADR-005 (auth/IDOR posture).
>
> Tasks 12, 19, 20, 21 are now IMPLEMENTED against the `ViesValidation` / `IcpSupply` / `IcpOpgaaf` schemas this change owns (`ViesService` VIES validation + outage fallback, `IcpService::createCorrection`, `ViesOutageRetryJob`, `IcpService::exportForInspection` + controller endpoints / routes), correcting the spec's now-impossible references to `Invoice.icpContext` (the AR-core Invoice register schema does not exist in this app yet — see below).
>
> Tasks 9, 10, 18, 26 are now LANDED (follow-up build): `bookkeeping-accounts-receivable-core` just merged the `ARInvoice` and `CustomerMaster` schemas in this app, unblocking the deferred extensions. Tasks 9/10 deep-merge `ARInvoice.icpContext` and `CustomerMaster.vatId*` into the existing register fragment (ADR-037 modular — monolith never edited; the fragment's existing properties union additively onto the AR-core schemas). Task 18 ships the `ArInvoiceIcpPdfRenderer` service + `GET /api/icp/invoice-pdf` controller endpoint emitting the reverse-charge notice + buyer/seller VAT-ID + supply-type indication per Article 226 paragraph 11a of VAT Directive 2006/112/EC (REQ-ICP-007); rendering loudly fails with `icp.invoice.vatid.missing` on a treatAsIcp invoice without a buyer VAT-ID. Task 26 ships the Playwright manifest-shell smoke (`tests/e2e/icp-opgaaf.spec.ts`); the full end-to-end ICP flows (VIES round-trip, ledger drill-down, Digipoort submission, audit ZIP export) remain `@e2e exclude` — they need a live instance with the openconnector VIES + Digipoort integrations + docudesk PDF surface seeded, which is out of this app's scope per the fragment `_meta`. The VIES validation logic (Task 12) now re-attaches as a declarative calculation on `ARInvoice.icpContext.viesValidationId` per the spec; the imperative `ViesService` remains as the engine-side fallback per ADR-031.

## Tasks

- [x] Task 1: Confirm no `bookkeeping-icp-opgaaf` capability spec already exists, no `IcpSupply`/`ViesValidation`/`IcpOpgaaf`/`PeriodicitySwitch` schemas are declared, and no `lib/Service/ICP*` / `lib/Service/Vies*` / `lib/Service/Sbr*` PHP classes are present (per ADR-031 anti-pattern enumeration); confirm `Invoice.icpContext` and `Counterparty.vatId*` extension points are available.

- [x] Task 2: Author `specs/bookkeeping-icp-opgaaf/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (VAT compliance)` / `Depends on: bookkeeping-accounts-receivable-core, bookkeeping-chart-of-accounts, bookkeeping-vat-btw-filing` header; `REQ-ICP-NNN` requirements using RFC 2119 keywords; `#### Scenario:` blocks with GIVEN/WHEN/THEN; cite Articles 262-271 VAT Directive, Article 37a Wet OB, Article 32 Uitvoeringsregeling OB, Implementing Regulation 282/2011 Article 18 for good-faith defence, SBR NT18 taxonomy, and VIES SOAP/REST endpoints.

- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Approach / Dependencies / Impact / Risks (VIES outage fallback, EUR 50,000 threshold mid-year logic, reconciliation mismatch tolerance, SBR taxonomy updates, triangulation VAT-ID detection) / Rollback / Open Questions (VIES outage retry scheduling, Digipoort pre-filing validation, triangulation auto-detection, VAT-ID caching policy).

- [x] Task 4: Author `design.md` with Context (VAT filing requirements, penalty schedule, VIES network audit), Goals (declarative metadata, VIES evidence preservation, periodicity auto-switch, reconciliation guards), Non-Goals (no PHP orchestration, no ViDA, no multi-currency), Decisions (D1 ICP as derived register from invoices, D2 VIES as calculation with outage fallback, D3 periodicity threshold-driven, D4 ledger aggregation via `x-openregister-aggregations`, D5 correction filings as separate opgaven, D6 reconciliation on finalize not submit, D7 triangulation separate line), Reuse Analysis, Declarative-vs-imperative, Seed Data examples (3-5 `IcpSupply`, `ViesValidation`, `IcpOpgaaf` records with Dutch values).

- [x] Task 5: Declare the `ViesValidation` schema in `lib/Settings/shillinq_register.json` with all REQ-ICP-001 + REQ-ICP-009 fields: `id`, `vatId` (canonical form), `validationTimestamp`, `validUntil`, `valid` (boolean), `name`, `address`, `requestId` (Belastingdienst audit proof), `outage` (flag for transient VIES outages), administrationId; Schema.org annotation `schema:VerificationEvent`.

- [x] Task 6: Declare the `IcpSupply` schema in `lib/Settings/shillinq_register.json` with all REQ-ICP-001 + REQ-ICP-003 + REQ-ICP-006 fields: `id`, `invoiceId` (FK), `invoiceLineId` (FK), `supplyDate` (time-of-supply per Article 64), `buyerVatId` (canonical), `buyerCountry` (ISO 3166-1 alpha-2), `supplyType` (enum: L/S/T), `amountExclVat` (EUR, two decimals, half-even), `viesValidationId` (FK to ViesValidation record), `reportedInOpgaafId` (nullable, set when supply is included in submitted IcpOpgaaf); Schema.org annotation `schema:Transaction`.

- [x] Task 7: Declare the `IcpOpgaaf` schema in `lib/Settings/shillinq_register.json` with all REQ-ICP-003 through REQ-ICP-010 fields: `id`, `period` (YYYY-Qn or YYYY-MM format), `periodicity` (enum: quarterly/monthly), `status` (enum: draft/finalized/submitted/accepted/rejected/corrected), `type` (enum: initial/correction), `correctsPeriod` (nullable, set for correction filings per REQ-ICP-008), `correctionReason` (text), `lines` (array of {buyerVatId, supplyType, amountExclVat} aggregations), `totalGoods` (sum of L + T), `totalServices` (sum of S), `totalTriangulation` (sum of T only, for reporting clarity), `belastingdienstKenmerk` (reference from submission), `submittedAt` (datetime), `xmlPayload` (archived XBRL per 10-year bewaarplicht), `administrationId` (FK), `auditBundle` (FK to file attachment per REQ-ICP-010); Schema.org annotation `schema:Report`.

- [x] Task 8: Declare the `PeriodicitySwitch` schema in `lib/Settings/shillinq_register.json` with REQ-ICP-002 fields: `id`, `administrationId` (FK), `switchFrom` (enum: quarterly/monthly), `switchTo` (enum: quarterly/monthly), `triggerDate` (date when threshold was breached), `triggerAmount` (cumulative goods supply amount that triggered), `triggerQuarter` (YYYY-Qn), `effectiveDate` (first day of next month), `status` (enum: active/reversed), `reversedAt` (datetime, set when switch is reverted per REQ-ICP-002), `reversalReason` (text); Schema.org annotation `schema:Event`.

- [x] Task 9: Extended `ARInvoice` schema (T2 AR sub-ledger, just merged) with the `icpContext` sub-object per REQ-ICP-001, REQ-ICP-006, REQ-ICP-007: `icpContext: { treatAsIcp, supplyType (L/S/T), viesValidationId FK→ViesValidation, triangulation }`. Implemented as a deep-merge property addition in `lib/Settings/register.d/bookkeeping-icp-opgaaf.json` (ADR-037 — monolith never edited; the fragment's `components.schemas.ARInvoice.properties` block unions additively onto the AR-core schema).

- [x] Task 10: Extended `CustomerMaster` schema (this app's customer entity per the brief contact-is-NC-entity rule — no separate Counterparty schema invented) with VAT-ID verification fields per REQ-ICP-001, REQ-ICP-009: `vatId` (canonical EU form), `vatIdValidatedAt`, `vatIdValidUntil`, `vatIdValidationStatus` (unchecked/valid/invalid/vies_outage). Implemented as a deep-merge property addition in `lib/Settings/register.d/bookkeeping-icp-opgaaf.json`; the existing free-form `btwNumber` is left untouched (transition compatibility).

- [x] Task 11: Declare `x-openregister-lifecycle` on `IcpOpgaaf` per REQ-ICP-004, REQ-ICP-005 with states: `draft` → `finalized` (gate: reconciliation check against rubriek 3b per REQ-ICP-004, SBR schema validation per REQ-ICP-005) → `submitted` (approval workflow per ADR-022) → `accepted` / `rejected` / `corrected` (post-Belastingdienst states).

- [x] Task 12: VIES validation implemented in `lib/Service/ViesService.php` — calls the EU VIES REST proxy via `IClientService`, canonicalises the VAT-ID, persists an immutable `ViesValidation` record (with `requestId` audit proof), and on a transient outage reuses a recent (< 30 days) valid record and flags `outage: true` for the daily retry job (REQ-ICP-001, REQ-ICP-009). Response parsing is a pure `parseViesResponse()` transform (unit-tested without a network). Exposed as `POST /api/icp/validate-vat-id`. (Spec corrected: implemented against the owned `ViesValidation` schema rather than the not-yet-existent `Invoice.icpContext`; re-attaches as a declarative calculation when AR-core declares Invoice.)

- [x] Task 13: Implement SBR XBRL generation as `x-openregister-calculations` field on `IcpOpgaaf.xmlPayload` per REQ-ICP-005 — conform to `bd-rpt-icp-2026.xsd` (NT18), include BSN/RSIN, period in `gl-cor:periodIdentifier`, one `bd-i:IntracommunautairePrestatieMutatieSpecificatie` per line, validate against schema on finalize.

- [x] Task 14: Implement periodicity auto-switch logic per REQ-ICP-002 — on each `IcpSupply` add/update, sum cumulative goods supplies (supplyType L+T) per calendar quarter, if >= EUR 50,000 create `PeriodicitySwitch` to monthly effective first day of next month; at calendar year boundary offer switch-back to quarterly if all prior 4 quarters were below EUR 50,000.

- [x] Task 15: Implement ICP ledger aggregation as `x-openregister-aggregations` query per REQ-ICP-003 — GROUP BY `(buyerVatId, supplyType)`, SUM amounts (with sign preservation for credit notes), sort by buyerVatId.

- [x] Task 16: Implement reconciliation gate on `IcpOpgaaf.finalize()` per REQ-ICP-004 — read period's submitted/draft BTW-aangifte, compare `IcpOpgaaf.total` against `BtwAangifte.rubriek3b`, allow EUR 1 tolerance, block with drill-down if diverge.

- [x] Task 17: Implement triangulation handling per REQ-ICP-006 — on `Invoice.icpContext.supplyType: T`, validate C-party VAT-ID (not A-party) against VIES, aggregate separately on `(buyerVatId, T)` line (never merged with L or S).

- [x] Task 18: AR invoice ICP overlay rendering shipped in `lib/Service/ArInvoiceIcpPdfRenderer.php` + `IcpController::renderInvoicePdf()` (exposed at `GET /api/icp/invoice-pdf`). On `ARInvoice.icpContext.treatAsIcp: true` the document includes the buyer VAT-ID (C-party for triangulation supplies per REQ-ICP-006), the seller NL VAT-ID, the "VAT reverse-charged" notice (Dutch translation: "BTW verlegd" via the i18n layer), and the supply-type label (Goods / Services / Triangulation). Rendering loudly fails with HTTP 422 + `icp.invoice.vatid.missing` when the buyer VAT-ID is absent (REQ-ICP-007). Pure unit-tested (`tests/Unit/Service/ArInvoiceIcpPdfRendererTest.php`). The HTML output is the canonical body; downstream PDF binarisation (mPDF / wkhtmltopdf / docudesk render pipeline) wraps it identically to the existing `InvoicePdfGenerator` pattern.

- [x] Task 19: Correction workflow implemented in `IcpService::createCorrection()` — materialises a new `IcpOpgaaf` with `type: correction`, `correctsPeriod`, `correctionReason` and the aggregated corrective lines (positive/negative), re-attaching the original contemporaneous `ViesValidation` evidence by buyer VAT-ID (no VIES re-query, REQ-ICP-008) via `ViesService::findRecentValid()`. Starts in `draft` so the reconciliation gate still applies. Exposed as `POST /api/icp/correction`. Digipoort delivery of the correction is the openconnector integration (deferred — live instance).

- [x] Task 20: VIES outage retry scheduling implemented in `lib/BackgroundJob/ViesOutageRetryJob.php` (TimedJob, 24h interval) + `IcpService::pendingOutages()` — distinguishes `valid: false` (definitive) from `outage: true` (transient), re-validates pending outages daily via `ViesService::validate()`, and escalates to the bookkeeper via the notification manager once an outage has persisted beyond 14 days (REQ-ICP-009, subject `icp.vies.outage_escalated`).

- [x] Task 21: Audit-trail export implemented in `IcpService::exportForInspection()` — builds a `ZipArchive` bundle containing the archived XBRL payload (`opgaaf.xbrl`), the Belastingdienst `kenmerk.txt`, the `supplies.csv` of all supplies with their VIES request IDs (via `IcpCalculator::buildSuppliesCsv`), and a `manifest.txt` (REQ-ICP-010). Exposed as `GET /api/icp/audit-export` (the server temp path is stripped from the response — no path leak). Source-invoice PDF attachment (docudesk/OR file surface) is the deferred live-instance step.

- [x] Task 22: Add 3 chart-of-accounts entries per proposal and design: `8190 Omzet ICP goederen` (zero-rated), `8195 Omzet ICP diensten` (zero-rated), `8196 Omzet ICP driehoekstransacties` (zero-rated), per RGS pattern; routing helper: supplyType L → 8190, S → 8195, T → 8196.

- [x] Task 23: Add 3 manifest navigation entries per REQ-ICP-003, REQ-ICP-010: `ICP Supplies` (ledger view, lists all supplies aggregated by period/buyer), `ICP Filings` (list of submitted opgaven with status), `ICP Audit Trail` (inspection bundle export + compliance log) — plus their `type: index` and `type: detail` pages.

- [x] Task 24: Update `openspec/architecture/adr-000-data-model.md` with `ViesValidation`, `IcpSupply`, `IcpOpgaaf`, `PeriodicitySwitch` entries, `Invoice.icpContext` extension, `Counterparty.vatId*` extension fields, reconciling against any existing `Vat*` / `Filing` data-model entries.

- [x] Task 25: Create integration test fixtures (PHPUnit) for: VIES validation success/failure/outage scenarios, periodicity threshold detection and switch-back, ICP aggregation with credit notes, reconciliation mismatch detection, triangulation line separation, SBR XBRL schema validation, correction filing with evidence preservation, audit-trail ZIP export.

- [x] Task 26: Playwright manifest-shell smoke shipped in `tests/e2e/icp-opgaaf.spec.ts` — confirms the ICP-opgaaf SPA route resolves inside the shillinq app namespace. Full end-to-end ICP flows (VIES round-trip, ledger drill-down, Digipoort submission, audit ZIP export) are documented as `@e2e exclude` in the spec module-doc and remain deferred: they require a live OpenRegister instance with openconnector VIES + Digipoort integrations seeded (out of this app's scope per the fragment `_meta`).

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
