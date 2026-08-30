# Proposal: bookkeeping-icp-opgaaf

`kind: config` per ADR-032 — the centre of mass is declarative schemas (`IcpSupply`, `ViesValidation`, `IcpOpgaaf`, `PeriodicitySwitch`) + `x-openregister-lifecycle` + `x-openregister-calculations` for VIES validation + SBR/Digipoort XML composition. No PHP VIES orchestration service, no PHP ICP aggregation service (subject to ADR-031 exception: at most one single-method guard for VIES outage retry scheduling if the engine cannot express conditional job-scheduling).

## Summary

Introduce the **intra-community supplies (ICP) filing** capability for Shillinq as one of the T3 VAT compliance capabilities (per `adr-001-bookkeeping-tier-roadmap.md`). This change declares the `IcpSupply`, `ViesValidation`, `IcpOpgaaf`, and `PeriodicitySwitch` registers; the ICP lifecycle consuming OpenRegister's approval-workflow per ADR-022 (no app-local submission table); VIES validation at invoice time with good-faith evidence preservation; automatic periodicity switching on the EUR 50,000 goods threshold per Article 263 of the VAT Directive; SBR-conformant XBRL generation matching the Digipoort schema for BTW-aangifte submission; reconciliation guards against rubriek 3b mismatches; triangulation supply handling; and audit-trail export bundles for Belastingdienst inspection. The capability materialises zero-rated accounts per the RGS chart-of-accounts pattern (accounts 8190/8195/8196) and extends both `Invoice` and `Counterparty` entities with ICP-context fields.

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app structure.

**Depends on:** [`bookkeeping-accounts-receivable-core`](../bookkeeping-accounts-receivable-core/proposal.md) (extends Invoice + Counterparty), [`bookkeeping-chart-of-accounts`](../bookkeeping-chart-of-accounts/proposal.md) (zero-rated accounts 8190/8195/8196), [`bookkeeping-vat-btw-filing`](../bookkeeping-vat-btw-filing/proposal.md) (reads rubriek 3b for reconciliation).

## Motivation

Dutch VAT law requires businesses that supply goods or services to VAT-registered buyers in other EU member states to file a separate ICP-opgaaf (intra-community supplies list) every quarter (monthly if goods supplies exceed EUR 50,000). The Belastingdienst cross-checks these totals via the VIES network and against rubriek 3b of the regular BTW-aangifte. Today Shillinq has zero ICP capability — bookkeepers maintain parallel Excel sheets, manually paste totals into the portal, and risk penalties up to EUR 5,514 per late/incorrect filing, plus full VAT liability if a buyer's VAT-ID was invalid (no good-faith defence without VIES evidence).

The legacy ICP feature request cluster (intelligence-db `competitor_features`, app_slug=shillinq) ranks ICP filing as high-priority for SMEs selling cross-border. Per ADR-022, approval comes from OpenRegister's workflow abstraction; per ADR-031, VIES calls and SBR XML composition are declarative calculations, not orchestration services.

This is one of eight T3 VAT capability changes; this proposal scopes the complete ICP-from-invoice pipeline.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-icp-opgaaf`); extends 2 existing entities (`Invoice.icpContext`, `Counterparty.vatId*` fields); declares 4 new registers (`IcpSupply`, `ViesValidation`, `IcpOpgaaf`, `PeriodicitySwitch`); adds zero-rated accounts 8190/8195/8196 to chart-of-accounts; adds 3 manifest navigation entries (ICP Supplies, ICP Filings, ICP Audit Trail).
- [ ] Project: openconnector — integrates VIES SOAP/REST endpoint (ec.europa.eu/taxation_customs/vies) for live VAT-ID validation with fallback on outage; integrates Digipoort SBR connector reusing BTW-aangifte credentials + PKIoverheid certificate for XML submission (no new auth required).
- [ ] Project: openregister — consumes existing `x-openregister-lifecycle` (for IcpOpgaaf states), `x-openregister-calculations` (VIES validation + SBR XML generation), `x-openregister-aggregations` (ICP ledger aggregation by buyer VAT-ID + supply type). If the conditional "retry VIES on outage" job scheduling cannot be expressed declaratively, ADR-031's exception path applies (one single-method guard).

## Scope

### In Scope

- One new capability spec (`bookkeeping-icp-opgaaf`) — see the `specs/` folder.
- The `IcpSupply` register capturing each intra-community supply with invoice link, buyer VAT-ID, supply type (goods/services/triangulation), amount, and VIES validation timestamp (good-faith evidence).
- The `ViesValidation` register recording every VIES query with requestId (Belastingdienst audit proof per Implementing Regulation 282/2011 Article 18), validity result, name + address disclosure, and outage flag for retry scheduling.
- The `IcpOpgaaf` register representing filed returns: period (YYYY-Qn or YYYY-MM), periodicity (`quarterly` / `monthly`), status (`draft` / `submitted` / `accepted` / `rejected` / `corrected`), aggregated lines by buyer VAT-ID + supply type, totals, Belastingdienst kenmerk, and archived XBRL payload (10-year bewaarplicht).
- The `PeriodicitySwitch` log recording every transition between quarterly and monthly with trigger amount, trigger quarter, and effective period.
- Extension of `Invoice` entity: `icpContext` sub-object set when counterparty is B2B in another EU member state with a valid VAT-ID (`treatAsIcp`, `supplyType`, `viesValidationId`).
- Extension of `Counterparty` entity: `vatId`, `vatIdValidatedAt`, `vatIdValidUntil`, `vatIdValidationStatus` (`valid` / `invalid` / `unchecked` / `vies_outage`).
- VIES validation at invoice creation time with transient outage fallback (use prior valid validation, requeue for revalidation).
- Automatic periodicity switch from quarterly to monthly when cumulative goods supplies (supplyType `L` and `T`) in any calendar quarter exceed EUR 50,000.
- ICP ledger aggregation by `(buyerVatId, supplyType)` with debit/credit sign handling for credit notes.
- Reconciliation guard: block submission if ICP total diverges from BTW-aangifte rubriek 3b by more than rounding tolerance (EUR 1).
- SBR-conformant XBRL/XML generation per `bd-rpt-icp-2026.xsd` (NT18) for Digipoort submission.
- Triangulation handling: flag `supplyType: T` separately from goods (`L`) and services (`S`).
- Invoice PDF representation: mandatory reverse-charge notice + buyer VAT-ID + seller NL VAT-ID + supply-type indication per Article 226 paragraph 11a of the VAT Directive.
- Correction workflow: corrected filings submitted as separate `IcpOpgaaf` of type `correction` with original `ViesValidation` evidence preserved.
- Audit-trail export: ZIP bundle containing XBRL, CSV of underlying supplies with VIES request IDs, and source invoice PDFs (REQ-ICP-010 compliance).

### Out of Scope

- **Implementation code** — spec-only change. PHP VIES clients, Vue components, controllers, tests, and CI changes are deliberately not in this proposal; the task list references them but the implementation lands via a separate `opsx-apply` cycle.
- **ViDA near-real-time digital reporting** — forward-compatible per IcpSupply granularity, but 2025 ViDA go-live outside this brief's scope.
- **Multi-currency ICP** — T3 variant; all amounts in this brief are EUR.
- **OSS-EU integration** — separate capability; fork at the B2B/VAT-ID decision point (ICP path vs OSS path per REQ-OSS-006).

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-icp-opgaaf`** — declares the four registers, the ICP lifecycle (consuming OpenRegister approval-workflow), VIES validation at invoice time, periodicity auto-switch, aggregation by buyer VAT-ID + supply type, reconciliation guards, SBR/Digipoort XML composition, triangulation handling, invoice PDF notices, correction workflow, and audit-trail export.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-ICP-*` for traceability.

## New Dependencies

- **openconnector** — VIES SOAP/REST client integration (reuses existing HTTP connectors).
- **openconnector** — Digipoort SBR connector (reuses existing PKIoverheid certificate + credentials from BTW-aangifte connector).
- No new PHP libraries beyond OR's existing calculation + lifecycle engines.

## Impact

- `lib/Settings/shillinq_register.json` — adds 4 new schemas (`IcpSupply`, `ViesValidation`, `IcpOpgaaf`, `PeriodicitySwitch`); extends `Invoice` and `Counterparty` with ICP-context and VAT-ID validation fields; declares lifecycle on `IcpOpgaaf`, calculations on VIES validation + SBR XML generation, aggregation on ICP ledger.
- `lib/Settings/chart_of_accounts.json` — adds 3 zero-rated accounts (8190 Omzet ICP goederen, 8195 Omzet ICP diensten, 8196 Omzet ICP driehoekstransacties).
- `src/manifest.json` — adds 3 navigation entries (ICP Supplies, ICP Filings, ICP Audit Trail) + their `type: index` / `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: one single-method `ViesOutageRetryScheduler` if the engine cannot express conditional job scheduling).
- Invoice PDF rendering extended to emit reverse-charge + VAT-ID notices on ICP invoices.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle` (for IcpOpgaaf state machine), `x-openregister-calculations` (VIES validation, SBR XML), `x-openregister-aggregations` (ICP ledger). If conditional job scheduling cannot be expressed, ADR-031 exception applies.
- **T2 accounts-receivable-core** — depends on `Invoice` + `Counterparty` extension points.
- **T2 chart-of-accounts** — depends on zero-rated account templates.
- **T3 vat-btw-filing** — depends on read access to submitted BTW-aangifte rubriek 3b for reconciliation (REQ-ICP-004).
- **openconnector** — VIES + Digipoort integrations.

## Risks

### Risk 1: VIES is a foreign (EC) service with availability uncertainty

**Severity**: Medium
**Mitigation**: REQ-ICP-009 distinguishes definitive rejections from transient outages. On outage, the system uses a recent (< 30 days) valid `ViesValidation` and sets `vatIdValidationStatus: vies_outage`, queuing revalidation daily. If revalidation fails after 14 days, the bookkeeper is escalated to manual verification. Good-faith defence is preserved by the timestamped `ViesValidation` record per Implementing Regulation 282/2011 Article 18.

### Risk 2: Periodicity switch from quarterly to monthly is irreversible mid-year

**Severity**: Low
**Mitigation**: REQ-ICP-002 allows switch-back to quarterly only at calendar year boundaries, after four consecutive quarters below EUR 50,000. The threshold is EUR 50,000 cumulative (supplyType `L` and `T` only, per Article 263 paragraph 1bis); the system notifies the bookkeeper upon switch and logs it in `PeriodicitySwitch`.

### Risk 3: ICP reconciliation mismatch with BTW-aangifte rubriek 3b may block submission on rounding edge cases

**Severity**: Low
**Mitigation**: REQ-ICP-004 allows EUR 1 rounding tolerance. If totals still diverge, the system presents a drill-down listing the missing/extra supplies and their amounts, allowing the bookkeeper to identify whether an invoice was miscoded or belongs in a different period.

### Risk 4: SBR taxonomy updates may break schema validation

**Severity**: Medium
**Mitigation**: The spec pins the XBRL schema to NT18 (`bd-rpt-icp-2026.xsd`). Taxonomy upgrades (e.g., ViDA NT20+) are handled in a future T5 change; schema validation errors are surfaced verbatim to the bookkeeper during finalization (REQ-ICP-005).

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no runtime impact. After implementation (separate cycle), rollback follows the standard pattern: revert the implementing PR; the four registers remain queryable but unreferenced; invoices lose their `icpContext` overlay but remain in AR.

## Open Questions

1. **VIES outage retry scheduling** — see Risk 1; if OR's lifecycle engine cannot schedule conditional daily retries, a single-method guard per ADR-031 exception applies (resolved in `opsx-ff` discovery).
2. **Digipoort SBR submission workflow** — whether pre-filing validation should block or warn on schema errors (resolved during implementing cycle's UX review).
3. **Triangulation B-party VAT-ID capture** — whether the system should auto-detect triangulation from invoice flow or require explicit operator flag (resolved during implementing cycle's design review).
4. **VAT-ID caching policy** — whether valid VAT-IDs are re-validated at filing time or use the original invoice timestamp (resolved during compliance review; spec assumes invoice-time evidence is authoritative per Regulation 282/2011 Article 18).
