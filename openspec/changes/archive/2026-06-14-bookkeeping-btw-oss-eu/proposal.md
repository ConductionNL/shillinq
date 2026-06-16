# Proposal: bookkeeping-btw-oss-eu

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`OssRegistration`, `EuVatRate`, `OssThresholdCounter`, `OssReturn`, `OssPayment`) +
ledger segregation via chart-of-accounts auto-creation + lifecycle (registration, filing, payment reconciliation).
Extends `Invoice` with `ossContext` sub-object for destination-country VAT resolution at invoice time.
No PHP OSS filing service; all generation and submission flows are declarative lifecycle actions.

## Summary

Introduce the **Union One-Stop-Shop (OSS) VAT compliance** capability for Shillinq
as a cross-border EU sales pipeline that runs alongside (not inside) the existing domestic BTW pipeline.
This capability removes the manual workarounds that block adoption by webshops and digital-service providers selling to EU consumers, and protects users from the substantial penalties that follow from charging the wrong VAT rate or missing the quarterly OSS filing deadline.

The change declares the `OssRegistration`, `EuVatRate`, `OssThresholdCounter`, `OssReturn`, and `OssPayment` registers;
the EUR 10,000 B2C turnover threshold monitoring with opt-in below threshold (Article 369a);
destination-country VAT-rate resolution at invoice time from the TEDB (Taxes in Europe Database);
ledger segregation so that OSS turnover and OSS VAT-payable show up in dedicated per-country `8xxx Omzet OSS` and `1xxx BTW af te dragen OSS` accounts and never contaminate the regular BTW-aangifte;
quarterly OSS aangifte generation in the prescribed Belastingdienst XML/CSV upload format with per-country totals;
reconciliation of the consolidated euro payment to the NL Belastingdienst and receipt of per-country distribution confirmation.
B2B intra-community supplies remain on the reverse-charge path (covered by `bookkeeping-icp-opgaaf`) and are explicitly excluded from OSS.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step seeding.

**Depends on:** [`add-shillinq-bookkeeping-foundation`](../add-shillinq-bookkeeping-foundation/proposal.md)
(T1 general ledger + chart of accounts),
[`add-shillinq-accounts-receivable-core`](../add-shillinq-accounts-receivable-core/proposal.md)
(Invoice base + customer counterparty types),
[`add-shillinq-vat-btw-filing`](../add-shillinq-vat-btw-filing/proposal.md)
(domestric BTW-aangifte — OSS must exclude its accounts from BTW rubrieken).

## Motivation

Article 1 of Council Directive 2006/112/EC, together with Council Implementing Regulation (EU) 282/2011,
establishes the Union One-Stop-Shop (OSS) scheme effective 1 July 2021. Under the OSS, a Dutch business selling B2C goods or tax-exempt electronically-supplied services to consumers in other EU member states declares and pays foreign VAT once per quarter to the Dutch Belastingdienst, which then redistributes the foreign portions to the other member states.

Today a Dutch MKB bookkeeper using Shillinq cannot produce a compliant invoice for a Berlin or Madrid consumer: the system applies the standard Dutch 21% VAT rate regardless of recipient country, and there is no mechanism to resolve the destination-country VAT rate, to flag a sale as falling under OSS, to segregate OSS turnover in the ledger, or to produce the quarterly OSS aangifte. This forces the bookkeeper to either manually override the rate per invoice (with high error risk and no audit trail) or accept that Shillinq is unusable the moment a webshop or service business crosses the EUR 10,000 annual threshold.

The OSS capability brings Shillinq in line with the Belastingdienst OSS workflow and removes the manual workarounds that block adoption by webshops and digital-service providers. It also protects users from the substantial penalties (up to 50% of unpaid VAT per Article 273 Directive 2006/112/EC) that follow from charging the wrong VAT rate or missing the quarterly OSS filing deadline.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-btw-oss-eu`); declares 5 new registers
  (`OssRegistration`, `EuVatRate`, `OssThresholdCounter`, `OssReturn`, `OssPayment`) with lifecycles;
  extends `Invoice` with `ossContext`; adds auto-creation of per-country ledger accounts;
  adds 2 manifest navigation entries (OSS Registration, OSS Returns).
- [ ] Project: chart-of-accounts — no source changes; OSS pipeline uses template mechanism to auto-create
  per-country accounts `8xxx Omzet OSS {country}` and `1xxx BTW af te dragen OSS {country}`.
- [ ] Project: OpenConnector — future integration point for direct TEDB rate refresh (weekly scheduled job)
  and eventual Belastingdienst Digipoort/SBR submission channel (currently manual upload through Mijn Belastingdienst Zakelijk).
- [ ] Project: bookkeeping-vat-btw-filing — hard assertion in BTW-aangifte builder excludes `1525 BTW af te dragen OSS *`
  family of accounts from rubrieken 3a/3b/4a/4b.
- [ ] Project: bookkeeping-icp-opgaaf — explicit fork at invoice time: B2B with valid VAT-ID routes to
  reverse-charge path; B2B without VAT-ID forced to B2C handling with OSS routing and warning.

## Scope

### In Scope

- One new capability spec (`bookkeeping-btw-oss-eu`) — see the `specs/` folder.
- The `OssRegistration` schema capturing seller-side enrolment in the OSS scheme: Belastingdienst-issued identifier, effective date, home member state (always NL), list of destination countries, registration status (active / voluntarily deregistered / excluded / pending).
- The `EuVatRate` schema mirroring the European Commission's TEDB (Taxes in Europe Database): per-country, per-period VAT rates broken down by category (standard, reduced 1, reduced 2, super-reduced, zero), CN/CPA code ranges, validity periods. Table seeded on install and refreshed weekly (future OpenConnector integration); manual edits forbidden.
- The `OssThresholdCounter` per-tenant view recomputed from journal: running calendar-year sum of B2C turnover to other EU member states, breakdown by quarter and by destination country.
- The `Invoice` extension: new `ossContext` sub-object populated whenever destination country is an EU member state other than NL and customer is B2C: `destinationCountry`, `appliedVatRate`, `appliedRateCategory`, `tedbRateVersion` (audit trail reference), `ossEligible`, `ossReportingPeriod`.
- The `OssReturn` schema representing a single quarterly aangifte: period, registration, status, line items grouped by destination country and rate category, total VAT payable, Belastingdienst kenmerk, archived XML/CSV payload. Corrections modelled as separate `OssReturn` records referencing the original period.
- The `OssPayment` schema capturing the consolidated euro payment to the Belastingdienst: bank transaction link, per-country distribution from the OSS portal for reconciliation.
- Ledger segregation: auto-creation of per-country accounts `8xxx Omzet OSS {country}` and `1xxx BTW af te dragen OSS {country}` on first invoice to that country; these accounts are never merged with domestic accounts and are excluded from the regular BTW-aangifte.
- Destination-country VAT-rate resolution at invoice creation time from `EuVatRate` table, with audit trail (tedbRateVersion) surviving subsequent TEDB updates.
- EUR 10,000 B2C-to-EU annual threshold monitoring with clear warning as threshold is approached and block when crossed (unless voluntarily registered below threshold per Article 369a).
- Quarterly OSS aangifte generation in the prescribed Belastingdienst XSD/CSV format, with per-country and per-rate-category line items.
- Consolidated payment reconciliation matching bank transactions to OssReturn and registering per-country distribution confirmation from the Belastingdienst.
- B2B intra-community supplies explicitly excluded: they route to reverse-charge path (`bookkeeping-icp-opgaaf`), not OSS.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue components, controllers, tests, and CI changes are deliberately not in this proposal; the task list references them but the implementation lands via a separate `opsx-apply` cycle.
- **Direct Belastingdienst submission** — OSS aangifte can be downloaded and manually uploaded through Mijn Belastingdienst Zakelijk; direct API submission via Digipoort/SBR will come with future OpenConnector integration (post-2026-Q3).
- **Multi-currency handling** — T5. All OSS amounts are in euros as per the Belastingdienst.
- **VAT exemption workflows** — separate capability (e.g., export exemption, reverse-charge eligibility validation per Art. 370-400 Directive 2006/112/EC).

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-btw-oss-eu`** — declares the five registers, the threshold counter, the ledger segregation, the destination-country VAT-rate resolution, the quarterly aangifte generation, payment reconciliation, and the explicit B2B exclusion. Extends Invoice with ossContext.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-OSS-*` for traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions; TEDB rate refresh and OpenConnector integration are future integration points (current implementation uses static TEDB seed data with manual refresh capability).

## Impact

- `lib/Settings/shillinq_register.json` — adds 5 new schemas
  (`OssRegistration`, `EuVatRate`, `OssThresholdCounter`, `OssReturn`, `OssPayment`);
  extends `Invoice` schema with `ossContext` sub-object.
- `lib/Settings/shillinq_register.json` — adds chart-of-accounts templates for per-country OSS accounts.
- `src/manifest.json` — adds 2 navigation entries (OSS Registration, OSS Returns).
- Integration point in `bookkeeping-vat-btw-filing` (hard assertion excluding OSS accounts from BTW return).
- Integration point in `bookkeeping-icp-opgaaf` (explicit B2B / B2C fork at invoice time).
- No new PHP services (all lifecycle actions and aggregations are declarative).
- No new bespoke Vue components beyond manifest navigation.

## Cross-Project Dependencies

- **T1 general ledger** — depends on `add-shillinq-bookkeeping-foundation` for materialised `JournalEntry` pattern.
- **T2 accounts receivable** — depends on `add-shillinq-accounts-receivable-core` for `Invoice` base + `customerType` field.
- **T2 VAT filing** — depends on `add-shillinq-vat-btw-filing` for the integration point that excludes OSS accounts from regular BTW-aangifte.
- **T2 ICP opgaaf** — depends on `bookkeeping-icp-opgaaf` for the B2B reverse-charge path.
- **OpenConnector** (future) — for TEDB rate refresh and Digipoort/SBR submission channel.

## Risks

### Risk 1: TEDB rate data freshness

**Severity**: Medium
**Mitigation**: TEDB is published by the EU Commission and is generally stable; VAT rates change infrequently (once per year, usually 1 January). Initial implementation seeds the TEDB from the v3 public REST endpoint at `ec.europa.eu/taxation_customs/tedb`; future OpenConnector integration adds weekly scheduled refresh. Manual refresh capability is documented. If a rate changes mid-period and the bookkeeper applies an outdated rate, the audit trail (tedbRateVersion) preserves the applied rate and the fact that it was in force on the invoice date.

### Risk 2: Voluntary opt-in below threshold compliance

**Severity**: Medium
**Mitigation**: Article 369a Directive 2006/112/EC allows voluntary OSS registration below the EUR 10,000 threshold, binding the seller for at least the current and following two calendar years. Spec REQ-OSS-009 enforces the lock-in: once voluntary registration is active, mid-quarter disable is blocked. This is a business rule lock; enforcement is declarative (lifecycle guard).

### Risk 3: Correction-return workflow timing compliance

**Severity**: Low-Medium
**Mitigation**: Article 61 Directive 2006/112/EC establishes a 3-year window for OSS correction returns. Spec REQ-OSS-010 models corrections as separate `OssReturn` records of type `correction` linked to the original period, never as in-place amendments. This preserves the original filed return's integrity and the audit trail. The 3-year window is a business rule enforced at the filing stage (UX validation).

### Risk 4: Per-country segregation audit burden

**Severity**: Low
**Mitigation**: OSS regulations require the bookkeeper to track VAT payable per destination country so the Belastingdienst can redistribute. Spec REQ-OSS-003 segregates accounts per country (e.g., `1525 BTW af te dragen OSS DE`, `1525 BTW af te dragen OSS FR`), auto-creating them on first invoice to each country. This adds a small number of accounts but provides clear ledger visibility and simplifies the aangifte generation (pure GROUP BY).

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder; no runtime impact. After implementation (separate cycle), rollback follows the standard pattern: revert the implementing PR; registers are non-destructive — OSS invoices remain queryable. OSS returns filed and accepted by the Belastingdienst remain in the archive for the 10-year bewaarplicht (record retention) period.

## Open Questions

1. **TEDB refresh cadence** — initial implementation: monthly manual refresh or seed-only? Resolved in `opsx-ff` discovery; weekly automated refresh lives in OpenConnector post-2026-Q3.
2. **Voluntary registration lock enforcement** — UI validation or backend guard? Resolved during the implementing cycle's UX review.
3. **Per-country account naming convention** — use ISO 3166-1 alpha-2 country codes (e.g., `IT`, `FR`) or translated country names (e.g., `Italië`, `Frankrijk`)? Resolved during the implementing cycle's UX review per Dutch accounting practice.
4. **Belastingdienst kenmerk retrieval** — how does the bookkeeper retrieve and register the kenmerk after filing? Current UX: manual copy-paste from Mijn Belastingdienst Zakelijk portal. Future: API integration via OpenConnector.
