# Design — ICP-opgaaf (Intra-Community Supplies)

## Context

Dutch VAT law (Articles 262-271 of Directive 2006/112/EC, implemented in Wet OB 1968 Article 37a) requires businesses supplying goods or services to VAT-registered buyers in other EU member states to file a separate quarterly (or monthly if goods supplies exceed EUR 50,000) summary called the ICP-opgaaf. The Belastingdienst cross-checks these totals via the VIES network and against rubriek 3b of the regular BTW-aangifte. Mismatches, late filings, or missing VIES evidence trigger administrative fines (up to EUR 5,514 per 2026 boetekader) and exposure to VAT liability.

Today Shillinq's bookkeepers maintain parallel Excel, have no VIES validation of buyer VAT-IDs (creating silent zero-rating of invalid IDs), no automatic periodicity switch, and no reconciliation against the BTW-aangifte. This change locks a complete ICP pipeline into declarative metadata: VIES validation at invoice time, automatic period selection, aggregation per buyer + supply type, SBR/Digipoort XML generation matching the BTW-aangifte connector's infrastructure, and reconciliation guards.

Per ADR-022, approval workflow comes from OpenRegister. Per ADR-031, VIES calls and SBR XML composition are declarative calculations, not orchestration services.

## Goals

- Express the entire ICP surface as **declarative metadata** — schemas + lifecycle + calculations + aggregations + manifest entries — per ADR-031.
- Consume OpenRegister's approval-workflow abstraction — per ADR-022. Zero app-local submission table.
- Capture **VIES evidence at invoice time** with timestamp preservation for good-faith defence (Implementing Regulation 282/2011 Article 18).
- Implement **automatic periodicity switching** (quarterly ↔ monthly) based on cumulative goods-supply thresholds per Article 263 paragraph 1bis of the Directive.
- Aggregate ICP supplies by **buyer VAT-ID and supply type** (goods L, services S, triangulation T), handling debit/credit signs for credit notes.
- Generate **SBR-conformant XBRL** matching the Digipoort schema used for BTW-aangifte submission (single connector, no parallel Digipoort config).
- Enforce **reconciliation guards** — block submission if ICP total diverges from BTW-aangifte rubriek 3b by more than rounding tolerance.
- Support **correction workflow** — corrected filings submitted as separate opgaven with original VIES evidence preserved.
- Enable **audit-trail export** for Belastingdienst inspection (XBRL + CSV + invoice PDFs).
- Make the spec a **competent-bookkeeper readable contract** — Dutch SME ICP flow recognisable end-to-end (VIES validation → invoice tagging → aggregation → periodic filing → audit trail).

## Non-Goals

- No PHP VIES orchestration service, no `ViesService.php`.
- No PHP ICP aggregation service, no `IcpLedgerService.php`.
- No PHP SBR XML generator, no `SbrXmlBuilder.php`.
- No ViDA near-real-time reporting — forward-compatible but 2025 go-live out of scope.
- No multi-currency ICP — T3 variant scoped to EUR only.
- No OSS-EU integration — separate capability with shared decision point at B2B/VAT-ID check.

## Decisions

### D1 — ICP is a filing register that materialises from invoice metadata, not a GL sub-ledger

`IcpSupply` is a **derived register** — each supply is captured as a first-class object (with its own VIES validation timestamp for audit proof), but it derives from `Invoice` lines where `Invoice.icpContext.treatAsIcp = true`. The `IcpOpgaaf` register aggregates these supplies by `(buyerVatId, supplyType)` and emits zero-rated GL accounts (8190/8195/8196) as posted journal entries per the RGS chart-of-accounts pattern.

**Alternative considered**: ICP supplies stored only as GL line metadata (no register). Rejected — bookkeepers need to drill into individual supplies by buyer/period/supply-type; aggregation and correction workflows require supply-level state (e.g., which supplies belong in which opgaaf).

### D2 — VIES validation is a declarative calculation at invoice-save time, with outage fallback and retry scheduling

When an invoice is saved with `icpContext.treatAsIcp: true`, the system invokes VIES as an `x-openregister-calculations` field on the `Invoice.icpContext.viesValidationId` FK. The result is a `ViesValidation` record capturing `requestId` (Belastingdienst audit proof), `valid` (boolean), `name` + `address` (VIES disclosure), and `validUntil` (typically `validationTimestamp + 1 day`).

On VIES outage (HTTP 5xx, SOAP `MS_UNAVAILABLE`), the system checks for a recent (< 30 days) valid `ViesValidation` for the same VAT-ID. If found, it attaches that prior validation, sets `vatIdValidationStatus: vies_outage`, and queues a revalidation job. If revalidation fails after 14 days, the bookkeeper is escalated. Good-faith defence is preserved by the timestamped record per Implementing Regulation 282/2011 Article 18.

**Alternative considered**: VIES validation as a separate pre-invoice service call (bookkeeper enters VAT-ID, system pre-validates, then they invoice). Rejected — invoices without validation could still be saved; no tight coupling between validation evidence and invoice evidence.

**Alternative considered**: No validation, just log every ID for manual audit. Rejected — creates silent zero-rating exposure; no good-faith defence on penalty challenge.

### D3 — Periodicity is auto-switched quarterly → monthly based on cumulative goods-supply threshold (EUR 50,000), decision point: end of Q1/Q2/Q3

Every time an `IcpSupply` is added (or an existing supply is corrected/voided), the system compares the cumulative `(supplyType L + T)` amount in the current quarter against EUR 50,000. If the threshold is breached at any point during the quarter, a `PeriodicitySwitch` log entry is created with `status: switch_to_monthly` and `effectiveDate: first_day_of_next_month`. Filing periodicity for all subsequent periods is monthly until the next calendar year, at which point the system checks the prior four quarters' goods supplies; if all were below EUR 50,000, it offers to switch back to quarterly with explicit bookkeeper confirmation.

**Alternative considered**: VIES-like threshold check — only enforce monthly retroactively after the quarter closes. Rejected — Belastingdienst regulation Article 32 Uitvoeringsregeling OB mandates the switch to be effective from the **first day of the month following** the breach, so the system must detect and notify mid-quarter.

**Alternative considered**: Bookkeeper manually selects quarterly vs monthly. Rejected — error-prone; automating the legal threshold is the whole point of the system.

### D4 — ICP ledger is declared as an `x-openregister-aggregations` query grouping `IcpSupply` by `(buyerVatId, supplyType)`

The "ICP ledger" view (the table the bookkeeper sees and which is filed) is not a separate register — it's an aggregation query that groups all `IcpSupply` records for a period by `(buyerVatId, supplyType)` and sums their amounts. Debit/credit signs are handled: credit notes create negative `IcpSupply.amountExclVat` values, so the sum is naturally correct. The aggregation output becomes the `IcpOpgaaf.lines[]` array.

**Alternative considered**: Manual `IcpLine` table — bookkeeper or system aggregates supplies into a separate lines register. Rejected — per ADR-031, pure aggregation is an extension, not a service; no state needed beyond the supplies themselves.

### D5 — IcpOpgaaf lifecycle is draft → finalized → submitted → accepted/rejected/corrected

`IcpOpgaaf` is a filing register with states:
- `draft` — system-generated aggregation of the period's supplies, editable by bookkeeper (e.g., to override supply types or exclude specific invoices).
- `finalized` — bookkeeper locks the draft, system validates reconciliation against BTW-aangifte rubriek 3b and SBR schema, no longer editable.
- `submitted` — bookkeeper submits to Digipoort (or archives for manual upload); Belastingdienst processes.
- `accepted` — Belastingdienst confirms receipt and validation pass.
- `rejected` — Belastingdienst validation failure; bookkeeper corrects and refiles.
- `corrected` — correction filing referencing the original period (new `IcpOpgaaf` with `type: correction` + `correctsPeriod: <original>`).

Approval routing comes from OpenRegister per ADR-022 (e.g., CFO must approve filings > EUR 100,000).

**Alternative considered**: Single `submitted` state; no draft phase. Rejected — bookkeepers need to review the aggregation before filing, especially in case of split invoices or supply-type ambiguity.

### D6 — Reconciliation against BTW-aangifte rubriek 3b is a gate on finalize, not on submit

When `IcpOpgaaf.finalize()` is called, the system reads the period's submitted or draft BTW-aangifte and compares `IcpOpgaaf.total` against `BtwAangifte.rubriek3b` (both in EUR, two-decimal rounding). If they match within EUR 1 tolerance, finalize succeeds and the filing can proceed. If they diverge, finalize fails with a drill-down listing the supplies in one return but not the other, allowing the bookkeeper to identify miscodings or missing invoices.

**Alternative considered**: Reconciliation happens at submit time. Rejected — by that point, the BTW-aangifte may have been revised; the gate should be when the ICP filing is locked for regulatory purposes.

**Alternative considered**: No reconciliation check. Rejected — Belastingdienst audit letter on mismatch is the most common compliance failure; the system must be a safety check.

### D7 — Triangulation is flagged as `supplyType: T` on the invoice, aggregated separately from L and S

Triangulation transactions (ABC-levering, where the Dutch seller is the B-party under Article 141's simplification) are invoices where the `Invoice.icpContext.supplyType: "T"`. On aggregation, they are reported on a separate line from regular goods (`L`) and services (`S`), even if the buyer VAT-ID is the same. The C-party VAT-ID (not the A-party) is validated against VIES and reported on the filing.

**Alternative considered**: Triangulation merged with goods supplies. Rejected — Belastingdienst taxonomy has separate buckets (`supplyType=T` in XBRL); must report separately for audit compliance.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| ICP filing lifecycle | OpenRegister `x-openregister-lifecycle` (ADR-031) | Lifecycle on `IcpOpgaaf` with draft → finalized → submitted → accepted/rejected/corrected; consuming OpenRegister approval-workflow per ADR-022 |
| ICP approval routing | OpenRegister approval-workflow (ADR-022) | Consumed via `x-openregister-lifecycle.requires`; no shillinq approval table |
| VIES validation | OpenRegister `x-openregister-calculations` (ADR-031) + openconnector VIES integration | Calculation field on `Invoice.icpContext.viesValidationId` + `ViesValidation` register for evidence storage |
| SBR XBRL generation | OpenRegister `x-openregister-calculations` + openconnector Digipoort connector | XML composition as a calculation field on `IcpOpgaaf.xmlPayload`; reuses existing BTW-aangifte Digipoort credentials |
| ICP ledger aggregation | OpenRegister `x-openregister-aggregations` | GROUP BY `(buyerVatId, supplyType)` with SUM of amounts; credit-note sign-handling automatic |
| Zero-rated GL accounts | T2 chart-of-accounts (RGS pattern) | Accounts 8190/8195/8196 declared per architecture; invoice posting helper routes ICP supplies to correct account per `supplyType` |
| Invoice extension | T2 accounts-receivable-core | `Invoice.icpContext` sub-object (treatAsIcp, supplyType, viesValidationId) |
| Counterparty extension | T2 accounts-receivable-core | `Counterparty.vatId`, `vatIdValidatedAt`, `vatIdValidUntil`, `vatIdValidationStatus` fields |
| BTW-aangifte rubriek 3b read | T3 vat-btw-filing | Cross-register query for reconciliation guard (REQ-ICP-004) |
| Audit trail | T2 bookkeeping-audit-trail (OR audit-trail-immutable) | Automatic on `IcpOpgaaf` and `ViesValidation` lifecycle transitions |
| Manifest navigation | T1 manifest pattern | 3 entries (ICP Supplies, ICP Filings, ICP Audit Trail) + their pages |

**Net new code in implementation cycle**: 4 schema declarations + 1 lifecycle block on `IcpOpgaaf` + 2 calculation fields (VIES validation, SBR XML) + 1 aggregation + 1 reconciliation gate function + invoice PDF rendering extension + 3 manifest entry pairs. At most 1 single-method PHP guard (`ViesOutageRetryScheduler`) gated by ADR-031 exception if the engine cannot express conditional daily retry jobs.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| ICP filing lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine (draft → finalized → submitted → accepted/rejected/corrected) |
| ICP approval routing | Consumed from OpenRegister approval-workflow | ADR-022 |
| VIES validation at invoice time | Declarative calculation with outage fallback | Query external VIES API; fallback logic is data-driven (check prior valid record) |
| VIES outage retry scheduling | Declarative if engine supports conditional job scheduling; else single-method PHP guard (`ViesOutageRetryScheduler`) per ADR-031 exception | Decision pending `opsx-ff` discovery |
| Periodicity auto-switch | Declarative (threshold comparison on `IcpSupply` add/update) | Pure threshold logic; decision point is end-of-quarter or year boundary |
| ICP ledger aggregation | Declarative (`x-openregister-aggregations`) | GROUP BY + SUM with debit/credit sign preservation |
| Reconciliation guard (BTW-aangifte rubriek 3b match) | Declarative validation on finalize | Simple amount comparison with tolerance; cross-register query |
| SBR XBRL generation | Declarative calculation | Pure data → XML transformation |
| GL account routing | Declarative (chart-of-accounts pattern + `supplyType` → account mapping) | No service; posting helper is configuration, not logic |
| Correction workflow | Declarative (new `IcpOpgaaf` with `type: correction` + `correctsPeriod` FK) | Pure data model; existing lifecycle covers transitions |
| Audit-trail export | Declarative (OR's file-attached-to-object + manifest query) | Aggregation + file bundling, no custom service |

No service class authored in this envelope (subject to ADR-031 exception: at most one single-method `ViesOutageRetryScheduler` if the engine cannot express conditional job scheduling).

## Seed Data

Example ICP supplies and filings:

### IcpSupply records (invoice-derived)
- Goods to BE0123456789: EUR 25,000 (invoiceId: INV-2026-0001, supplyDate: 2026-06-15)
- Services to DE0987654321: EUR 12,500 (invoiceId: INV-2026-0002, supplyDate: 2026-06-20)
- Triangulation (C-party FR0555666777): EUR 5,000 (invoiceId: INV-2026-0003, supplyDate: 2026-06-25)

### ViesValidation records
- VAT-ID BE0123456789: valid=true, name="Acme Belgium BVBA", requestId="BE-2026-06-15-001", validUntil="2026-06-16"
- VAT-ID DE0987654321: valid=true, name="Berlin GmbH", requestId="DE-2026-06-20-001", validUntil="2026-06-21"
- VAT-ID FR0555666777: valid=true, name="Sarl Triangulation", requestId="FR-2026-06-25-001", validUntil="2026-06-26"

### IcpOpgaaf aggregation (Q2 2026)
- period: "2026-Q2"
- periodicity: "quarterly"
- status: "draft"
- lines:
  - buyerVatId: "BE0123456789", supplyType: "L", amountExclVat: 25000.00
  - buyerVatId: "DE0987654321", supplyType: "S", amountExclVat: 12500.00
  - buyerVatId: "FR0555666777", supplyType: "T", amountExclVat: 5000.00
- total: 42500.00
- totalGoods: 30000.00 (25000 L + 5000 T)
- totalServices: 12500.00
- totalTriangulation: 5000.00

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| VIES outage mid-quarter blocks invoices from being tagged as ICP | REQ-ICP-009 outage fallback: use recent valid validation, mark status as `vies_outage`, requeue daily. Good-faith defence via timestamped `ViesValidation` record. |
| Periodicity switch to monthly mid-year reduces filing frequency flexibility | Article 263 paragraph 1bis is mandatory; system enforces but also provides switch-back path (after 4 quarters below threshold, offer to revert at year boundary). |
| Reconciliation mismatch on EUR 1 rounding edge case | REQ-ICP-004 allows EUR 1 tolerance (standard two-decimal rounding). If still diverge, drill-down identifies supplies in one return but not the other. |
| SBR/Digipoort taxonomy updates may break schema validation | Spec pins to NT18 (`bd-rpt-icp-2026.xsd`). Future ViDA upgrades (NT20+) handled in T5. Schema validation errors surfaced verbatim to bookkeeper during finalization. |
| Triangulation supply-type detection may be ambiguous from invoice flow | REQ-ICP-006 requires explicit `triangulation: true` flag on invoices; system does not auto-detect. Resolved in UX review. |

## Constraints

1. All amounts in EUR, two decimals, rounded half-even (per ICP-opgaaf spec).
2. VIES validation timestamps are immutable for audit proof (never updated retroactively).
3. Correction filings (`type: correction`) must reference the original `IcpOpgaaf` and attach original `ViesValidation` records (no re-validation).
4. Periodicity can only switch at quarter boundaries (not mid-month).
5. ICP filings are locked 10 years (per bewaarplicht); deletion not permitted.
