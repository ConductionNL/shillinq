# Proposal: bookkeeping-ifrs-16-lease

`kind: config` per ADR-032 — the centre of mass is lease-contract declaration, payment-schedule generation logic, and IFRS 16 disclosure-table automation.

## Summary

Introduce IFRS 16 Leases (effective 1 January 2019) as a first-class capability inside Shillinq's bookkeeping engine. This change adds five new declarative schemas — lease-contract, lease-payment-schedule, lease-reassessment-event, lease-disclosure-table, lease-portfolio-exemption — plus targeted extensions to fixed-asset and general-ledger-account schemas to connect RoU assets and lease liabilities to the depreciation and GL posting engines. The capability enables a full lease register, automatic RoU asset and lease liability recognition at commencement, periodic interest and depreciation posting, reassessment workflows for modifications and remeasurement events, short-term and low-value exemptions, and audit-ready disclosure tables.

The change conforms to IFRS 16 standard, EFRAG implementation guidance, and the RJ 292 Dutch GAAP equivalent. Cross-dependencies are documented with bookkeeping-general-ledger (GL postings), bookkeeping-fixed-assets-depreciation (RoU asset lifecycle), docudesk (source contract PDFs), organisations (lessor counterparties), and decidesk (materiality-based approval routing).

## Motivation

A Shillinq customer managing a mid-market lease portfolio (e.g., 50–500 leases across vehicles, real estate, IT hardware, machinery) today maintains lease registers in Excel, backs them with PDF contracts in Google Drive or docudesk, and hands the spreadsheet to an external IFRS 16 specialist (or a Big-4 firm at EUR 30K+/year) to compute the RoU asset, lease liability, disclosure tables, and journal entries. The specialist's work is a black box: the customer gets an Excel export, three disclosure notes, and a signing-off email.

When the next period closes or a contract is modified, the customer must return to the spreadsheet, re-enter the changes, and re-engage the specialist for recomputation. The result is slow (weeks per close), expensive (EUR 500+ per lease reassessment), and fragile (a missing extension-option flag or an IBR rounding error is caught in audit, not in the register).

IFRS 16 is mechanically correct but operationally painful. Shillinq's competitive edge is that customers use a single platform for all bookkeeping workflows (GL posting, invoicing, AP/AR, payroll, procurement, treasury) instead of juggling domain-specific tools. Introducing IFRS 16 as a built-in capability proves that Shillinq can handle real financial reporting — not just accrual invoicing — and unlocks the door to ESRS sustainability reporting, full IFRS consolidation, and the mid-market segment (EUR 20M–500M turnover, typically the first cohort to adopt IFRS voluntarily because of PE ownership or group reporting requirements).

The success criterion is that a Shillinq customer should be able to capitalise their full lease portfolio, generate audit-ready journal entries every period, produce the IFRS 16 disclosure note without rework, and pass a Big-4 IFRS 16 audit walkthrough without the auditor reaching for Excel.

## Affected Projects

- [x] Project: shillinq — adds 5 new register schemas, extends fixed-asset and account schemas, ships disclosure-table generation logic, integrates with bookkeeping-general-ledger for period-end posting, integrates with decidesk for reassessment approval routing above a EUR 100K RoU threshold
- [x] Project: bookkeeping-general-ledger (T1) — no changes required; IFRS 16 consumes the GL posting surface generically via the journal-entry API
- [x] Project: bookkeeping-fixed-assets-depreciation (T4-base) — extends fixed-asset schema with `is-rou-asset` boolean and `source-lease` FK; RoU assets follow the standard depreciation schedule automatically
- [ ] Project: docudesk — no changes; IFRS 16 references docudesk attachments for lease PDFs and modification evidence via foreign-key URIs per ADR-022
- [ ] Project: organisations — no changes; IFRS 16 references lessor counterparties already held in the organisations register
- [ ] Project: decidesk — integrates via webhook on reassessment events exceeding EUR 100K RoU impact threshold; routes to a board-decision flow configured in the Shillinq app

## Scope

### In Scope

- Five new schemas: `lease-contract` (master register), `lease-payment-schedule` (period-by-period derived table), `lease-reassessment-event` (modification/remeasurement log), `lease-disclosure-table` (period-end snapshot), `lease-portfolio-exemption` (policy elections)
- IFRS 16 classification engine: wizard-driven decision tree (IFRS 16 lease yes/no → finance vs operating under old rules → short-term exemption → low-value exemption)
- RoU asset and lease liability recognition at lease commencement: opening balance computed from present value of unavoidable payments (using contract IBR), posted to GL via batched journal entry, fixed-asset record created with `is-rou-asset=true` flag
- Incremental borrowing rate (IBR) derivation: four supported methods (group policy matrix, yield-curve + spread, weighted-average external debt, external quote) with timestamp, approver sign-off, supporting documents, and freezing against later edits
- Periodic posting: monthly/quarterly month-end automation generates journal entries covering interest expense, lease liability payment split, and RoU depreciation (delegated to bookkeeping-fixed-assets-depreciation via the fixed-asset record)
- Reassessment and remeasurement workflows: indexation clauses (CPI, fixed-percent, sector-index), extension-option reassessment, termination-option reassessment, payment/scope/term modifications, IBR reset, impairment, partial terminations, abandonments — each with guided workflow, before/after snapshots, and GL posting
- Short-term (≤12 months) and low-value (asset value when new ≤ ~USD 5,000) exemptions: policy elections by asset class, contract-by-contract application, straight-line expense posting
- Disclosure table generation: IFRS 16.51 quantitative disclosures (RoU additions/depreciation/disposals by class, liability maturity analysis, weighted-average IBR, expense breakdown) + IFRS 16.59 qualitative narrative seeds, exportable to XBRL via bookkeeping-sbr-xbrl-reporting
- Transition support: modified-retrospective (standard approach) and full-retrospective (comparative restatement) with one-time wizard, practical expedient elections, and disclosure note
- Audit pack export: one-click bundle of lease register (PDF + CSV), per-lease payment schedules, IBR derivation evidence, every reassessment event with snapshots, disclosure table, transition methodology, supporting documents, sign-off page

### Out of Scope

- ASC 842 (US GAAP) reconciliation — Phase 2
- Cloud-infrastructure lease accounting (dedicated server / dark fibre) — out of scope but anticipated in design; no anti-patterns in schema
- Sale-and-leaseback accounting in full detail (IFRS 16 November 2023 amendments) — deferred to Phase 2; schema carries `purchase-option` field for future expansion
- Real-time lease-portfolio analytics dashboard (launchpad) — deferred to Phase 2; disclosure table is the output
- Lessor accounting (IFRS 16.63 onwards) — out of scope; Shillinq is for lessees

## Key Risks

### Risk 1: IBR derivation correctness
The IBR is the single most material IFRS 16 judgement; a 0.5% error on a EUR 1M lease liability is EUR 5K impact. The system supports four methods, each with different data sources (group policy, yield curve, GL account balances, manual input). A customer incorrectly configured to use the yield-curve method when the group policy applies could generate non-compliant output.

**Mitigation**: The wizard includes an "IBR method selection guide" (flow chart in app + help text); the portal publishes Big-4 external documentation on IBR best practices; the initial customer onboarding includes a "IBR policy review" call with the treasurer.

### Risk 2: Lease term "reasonably certain" judgement
IFRS 16.23 defines lease term to include periods covered by extension options "reasonably certain to be exercised". This is a judgement call (e.g., is a 5-year car lease with a 2-year extension "reasonably certain"? Depends on the business decision and the lessor's incentives). A customer who misjudges this will understate the liability.

**Mitigation**: The schema carries a `reassessment-trigger` workflow that auditors and customers must run before every period close. The workflow surfaces all extension options not yet deemed "reasonably certain" and prompts a yes/no reassessment. The resulting snapshots are audit-visible.

### Risk 3: Reconciliation with external IFRS 16 tools
Some customers may run Shillinq *and* Visual Lease / LeaseAccelerator in parallel during transition. A discrepancy (e.g., the external tool recognises an extension option as "reasonably certain" but Shillinq does not) is visible only if both are exported side-by-side. The customer may not discover it until audit.

**Mitigation**: The disclosure table export includes a "audit reconciliation" section with reference data (lease numbers, contract dates, IBR, liability opening/closing balances) that can be copy/pasted into a checklist against the external tool's output. The first 3 months of a Shillinq IFRS 16 adoption is typically billed as "intensive customer support" (10 hours included, then EUR 150/hour overage).

### Risk 4: Multi-currency leases
A EUR-functional-currency company with a lease denominated in USD (e.g., equipment from a US lessor paid in USD). The IBR may be quoted in USD, the payment schedule is in USD, but the GL posting and RoU balance sheet value are in EUR. FX re-measurement happens every period.

**Mitigation**: The schema carries a `payment-currency` field and an `fx-rate` field per period. The design assumes multi-currency support is already in the GL (bookkeeping-general-ledger posts in any registered currency). FX gain/loss on lease liability is computed per period and posted to a dedicated GL account. The feature is deferred to Phase 2 if the GL does not yet support multi-currency.

## Rollback

If a customer's lease data in Shillinq is discovered to be non-compliant in audit (e.g., a EUR 500K lease was marked as "low-value exempt" incorrectly), the remediation path is:

1. Correct the lease classification/parameters in the Shillinq register
2. Run the "retrospective recalculation" workflow — the system regenerates payment schedules, reassessment events, and disclosure tables for all prior periods
3. Post correction journal entries (catch-up adjustments) dated to the earliest affected period
4. Resubmit the corrected disclosure table to the auditor

This is all within Shillinq; no Excel export/manipulation is needed. If the correction is complex (e.g., a scope modification with a new IBR curve mid-way through the lease), the system generates a detailed audit trail (before/after snapshots, approver sign-off, posted GL references) for the auditor to trace.

If the entire IFRS 16 capability is to be rolled back (e.g., a group decided to delay IFRS adoption by 1 year), the customer:
1. Deletes all `lease-*` records (via OR cascade delete)
2. Reverses all GL postings (via a "IFRS 16 reversal journal" that voids the period-end postings in bulk)
3. Deletes the `is-rou-asset` fixed-asset records (returns to operating lease accounting, or the asset is re-classified as a regular maintenance asset)
4. The GL returns to its pre-IFRS-16 state

Rollback is low-friction because IFRS 16 records are entirely separate from the core GL; no backfill or data surgery is needed.

## Open Questions

1. **Lease term re-evaluation cadence**: Should the system automatically trigger a "reassess all extension options" workflow before every period close, or only when the customer marks a lease as "requires manual reassessment"? A monthly auto-trigger may create approval fatigue; a manual-only trigger may miss a changed circumstance.
   - **Proposal**: Manual-first (customer or auditor triggers the workflow) with a "days since last reassessment" warning in the detail view. Configurable by tenant via a `reassessment-reminder-days` setting (default 90 days).

2. **IBR reset on modification**: When a lease is modified (e.g., a new floor added to a building lease), should the IBR be reset to the rate at the modification date (per IFRS 16.44) or kept at the original commencement rate? The spec mandates reset, but the customer may have data showing the group's new policy is to keep the original rate for immaterial modifications.
   - **Proposal**: The schema carries a `rem-measurement-approach` enum (catch-up-adjustment | prospective | separate-lease) as required by IFRS 16.44, so the customer can elect one per modification. The system enforces consistency within a cohort (e.g., all extension-option reassessments use the same approach) via a configurable policy.

3. **CSRD E1 (Scope 1+2 carbon) integration**: The lease register will be queried by bookkeeping-csrd-esrs to compute carbon footprint of leased vehicles and buildings. Should the schema carry an `asset-class` enum (vehicle | real-estate | IT-hardware | machinery | other) so the query can filter, or is the asset-class derivable from the lessor's counterparty metadata?
   - **Proposal**: The schema carries `asset-class` as a required field on `lease-contract`. The enum aligns with common GHG Protocol categorizations (Scope 1 = vehicles; Scope 2 = leased buildings with direct energy bills; Scope 3 = outsourced logistics). CSRD can filter by class to avoid double-counting.

## Timeline

Tier 4-specialized (this change) is dependent on:
- T1 (bookkeeping-chart-of-accounts, -general-ledger, -journal-entries) ✓ in T1 change
- T2 (bookkeeping-trial-balance, -accounts-payable-core, -accounts-receivable-core, -period-close, -financial-statements) ✓ in T2 change
- T4-base (bookkeeping-fixed-assets-depreciation, -multi-currency) — fixed-asset requirement met in T4-base; multi-currency deferred to Phase 2

The change is proposed for parallel merge with T4-base (T1 → T2 → T3 → T4-base / T4-specialized in parallel).

Estimated opsx-apply cycle: 8–10 weeks (register setup, payment-schedule generator, reassessment workflow, disclosure-table export, GL integration, testing with a pilot customer's lease portfolio).

---

## See also

- `context-brief.md` — detailed feature narratives, user stories, customer journey maps
- `design.md` — technical architecture, seed data examples, decision rationale
- `specs/bookkeeping-lease-contracts/spec.md` — lease-contract schema and workflows
- `specs/bookkeeping-lease-accounting/spec.md` — payment-schedule generation, RoU/liability recognition
- `specs/bookkeeping-lease-reassessment/spec.md` — modification/remeasurement events and GL posting
- `specs/bookkeeping-lease-exemptions/spec.md` — short-term and low-value exemptions, policy elections
- `specs/bookkeeping-lease-disclosures/spec.md` — IFRS 16 disclosure table generation and export
- IFRS 16 *Leases* (IASB, effective 1 January 2019)
- EFRAG implementation guidance and the IASB November 2023 amendments on sale-and-leaseback
- RJ 292 (Richtlijnen voor de Jaarverslaggeving) — Dutch GAAP lease accounting standard
- OpenSpec tier roadmap: `openspec/architecture/adr-001-bookkeeping-tier-roadmap.md`
