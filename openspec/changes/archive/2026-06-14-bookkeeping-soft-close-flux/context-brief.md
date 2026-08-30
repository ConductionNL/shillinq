---
status: draft
---
# Continuous-Close and Flux Analysis

## Purpose

Replace the traditional 10-15 working day month-end close with a continuous (daily soft-close) discipline and add automated flux analysis (variance explanation) so finance teams report numbers within 1-3 business days and spend the remaining time investigating outliers rather than chasing accruals. The shift from periodic close to continuous close is a well-documented finance-modernisation pattern (Hackett Group, APQC, McKinsey "Finance 2030") and is the operating model behind every fast-close benchmark: Cisco closes books in 3 days, Coca-Cola in 4, Microsoft in 2. Even mid-market SMEs achieve a 5-day close once the core building blocks are in place: automated accruals, continuous reconciliation, period-locked transactions, exception-driven review, and flux analysis with explanations attached to material variances.

This module delivers those building blocks. It runs nightly soft-close jobs that auto-generate accruals (utilities, payroll, interest, rent, depreciation, IFRS 15 revenue cut-off, IFRS 16 lease postings) so that any morning the trial balance approximates an actual month-end. It defines a period-close playbook with checklists, RACI, and a controlled workflow that moves a period through stages (open → soft-closed → hard-closed → audited → locked). It runs flux analysis after every soft-close: for each GL line, segment, cost centre, or KPI, the system computes the variance against budget, prior period, prior year, and rolling forecast, classifies materiality, and either auto-explains (using rule-based attribution: "Salaries +12% explained by 4 new hires in March") or routes to the responsible owner for a free-text explanation. Material variances surface as alerts and accumulate into a flux narrative that becomes the first page of the monthly board pack.

The result: fewer surprises at month-end, faster reporting cadence, audit-defensible variance explanations, and a finance team that operates as analysts rather than data assemblers.

## Data Model

- **PeriodStatus**: administratie reference, period (year, month), stage (open | soft-closed | hard-closed | audited | locked), stage-change history, owner per stage.
- **CloseChecklistTemplate**: reusable list of close tasks (bank-rec done, AP cut-off done, AR ageing reviewed, accruals posted, FX revaluation done, intercompany matched, depreciation posted, payroll booked, tax provision posted, flux reviewed, board pack drafted, etc.) with default owners and dependencies.
- **CloseChecklistInstance**: instantiated per period, tasks with status, owner, due-by, completed-at, evidence attachment.
- **AutoAccrualRule**: rule definition (target GL account, calculation method, source data, frequency, reversal pattern), e.g. "monthly rent: 12K to 4001-rent, contra 2100-accrued, auto-reverse first-of-next-month".
- **AutoAccrualPosting**: result of rule execution per period, with link back to rule and to resulting JournalEntry.
- **FluxRun**: timestamp, scope (administratie, segment, cost centre), comparison basis (budget | forecast | prior period | prior year), materiality threshold (absolute + percentage).
- **FluxItem**: GL account or KPI, current value, comparison value, absolute variance, percentage variance, materiality classification, auto-explanation, owner, owner-explanation, status (open | explained | escalated | accepted).
- **FluxAttribution**: rule-based decomposition of a variance into drivers (volume, price, mix, FX, one-off), each with quantified contribution.
- **MaterialityPolicy**: per administratie + account-group thresholds (absolute floor + percentage floor); supports lower thresholds for cash, tax, revenue.
- **ContinuousCloseAlert**: triggered by any threshold breach during a soft-close run; routed to owner via configured channel.
- **CloseMetrics**: time-to-close per stage per period, count of post-close adjustments, count of unexplained flux items, audit-correction rate (number of audit adjustments / total close adjustments).

## Requirements

- **REQ-CLS-001** The system MUST support a configurable period lifecycle with at least the stages open, soft-closed, hard-closed, audited, locked, and MUST enforce posting restrictions per stage (e.g. no postings to a hard-closed period without controller override).
- **REQ-CLS-002** A soft-close job MUST run nightly per administratie, executing all configured auto-accrual rules, FX revaluation, depreciation calculation, IFRS 15 revenue cut-off (delegating to that module), IFRS 16 lease postings, and intercompany matching, producing a complete trial balance by 07:00 local.
- **REQ-CLS-003** Auto-accrual rules MUST be definable with calculation methods (fixed amount, straight-line from contract, percentage of revenue, days-elapsed of period, lookup from external source), reversal pattern (first-of-next-month, on-receipt-of-invoice, on-settlement), and audit trail of each posting linked back to the rule version.
- **REQ-CLS-004** A close-checklist template MUST be definable per administratie type and instantiated automatically when a new period opens; the system MUST enforce task dependencies and SLA breaches MUST escalate.
- **REQ-CLS-005** Flux analysis MUST run after every soft-close and on demand, comparing the current period to budget, prior period, prior year, and rolling forecast, with materiality thresholds applied per account group.
- **REQ-CLS-006** Each flux item above materiality MUST receive a rule-based auto-explanation where possible (driver decomposition: volume × price × mix × FX × one-off) and otherwise be routed to the GL account owner with an SLA of 24 hours for explanation.
- **REQ-CLS-007** The system MUST aggregate flux explanations into a flux narrative ordered by absolute variance, suitable for inclusion in the monthly board pack and exportable to PDF, Markdown, and JSON for embedding in other reports.
- **REQ-CLS-008** Comparative dashboards MUST visualise current month vs budget, vs prior period, vs prior year, vs forecast at administratie, segment, and consolidated level, with drill-down to the underlying GL transactions in two clicks.
- **REQ-CLS-009** The system MUST track and publish close-quality KPIs: time-to-close, count of post-close adjustments, audit-correction ratio, percentage of flux items explained within SLA, and trend these over the last 12 periods.
- **REQ-CLS-010** All automated postings (accruals, reversals, flux-driven adjustments) MUST be auditable to source rule, source data, posting user (system), and timestamp, with reversal/correction workflows preserving original entries.

### GIVEN/WHEN/THEN scenarios

**GIVEN** an administratie with auto-accrual rules for rent (EUR 12K/month), utilities (3% of revenue), salaries (per payroll calendar), and interest (per loan schedule), **WHEN** the nightly soft-close job runs on 17 March, **THEN** the system MUST post pro-rata accruals for the 17 days elapsed of March (rent EUR 6,580, utilities based on month-to-date revenue, salaries based on the payroll cadence, interest at the daily accrual), each reversed by an offsetting entry on 1 April, and present a complete soft-closed trial balance by 07:00 with a banner indicating "Soft-closed as of 17 March".

**GIVEN** flux analysis shows COGS variance of +180K vs budget (15% adverse) for the period, **WHEN** the rule-based attribution engine analyses the drivers, **THEN** the system MUST decompose the variance into volume effect (+10% volume = +80K), price effect (raw-material price +6% = +60K), mix effect (shift to lower-margin SKUs = +20K), and FX (USD purchases at weaker EUR = +20K), publish the explanation to the flux narrative, and only escalate to the COGS owner if any single component is itself above its own materiality threshold.

**GIVEN** period March is hard-closed on 4 April and an invoice dated 29 March arrives on 8 April, **WHEN** the user attempts to post the invoice with dated 29 March, **THEN** the system MUST refuse the posting in the closed period, suggest posting to April with a prior-period adjustment flag, increment the post-close-adjustment counter for March, and include the adjustment in the next month's flux narrative as a "PY/PP adjustment" line for transparency.

## Standards & Sources

- **IFRS / IAS** baseline (specifically IAS 1 presentation, IAS 8 accounting policies and corrections, IAS 10 events after the reporting period, IAS 34 interim reporting)
- **BW2 Title 9 / RJ** Dutch GAAP for period reporting
- **COSO 2013** internal control framework (period close as a key control)
- **APQC** Process Classification Framework category 8.0 (Manage Financial Resources) benchmarks for time-to-close
- **Hackett Group** "World-Class Finance" close benchmarks (3-5 day close target)
- **AICPA / IIA** guidance on management's review controls and journal-entry controls
- **Sarbanes-Oxley s.404** journal-entry controls (relevant for any group with US-listed parent)
- Competitor reference models: BlackLine Close, FloQast, Trintech Cadency, Workiva Wdesk, OneStream, Vena Solutions, Sage Intacct Continuous Close, NetSuite Period Close Checklist, Prophix, Limelight Finance, Numeric, Mosaic, Truewind
- Books: "Closing the Books" (Steven Bragg), "Fast Close" (Steven Bragg), "Continuous Accounting" (Tagetik / Wolters Kluwer)

## Cross-app integration

- **bookkeeping-period-close**: the canonical period entity lives there; this module extends it with continuous-close and flux behaviour.
- **bookkeeping-general-ledger**: source of all account balances feeding flux analysis and target of all auto-accrual postings.
- **bookkeeping-ifrs15-revenue**: the nightly soft-close triggers the revenue cut-off recognition.
- **bookkeeping-treasury-ihb**: nightly soft-close consumes treasury postings (FX revaluation, interest accruals) and feeds liquidity KPIs.
- **bookkeeping-consultancy-project-accounting**: project-level percentage-of-completion is one input to revenue accrual and to cost-of-goods-sold flux explanation.
- **bookkeeping-accounts-payable** + **bookkeeping-accounts-receivable**: ageing reports feed the AP/AR cut-off checklist tasks; unposted received-not-invoiced (GR/IR) drives auto-accrual for goods receipts.
- **bookkeeping-budgeting-forecasting** (future): provides the budget and rolling forecast that flux analysis compares against.
- **launchpad**: tiles for close countdown, flux heatmap, time-to-close trend, top-10 unexplained variances.
- **n8n**: orchestrates the nightly soft-close pipeline, sequencing the modules, handling retries, and routing alerts.
- **docudesk**: archives the period-end flux narrative, the close-checklist evidence, and the signed-off board pack.

## Target users

- **Controller / Hoofd Administratie** running the close, owning the close-checklist and the flux narrative.
- **CFO** consuming the flux narrative as the basis for the monthly board discussion and the early-warning system for in-month deviations.
- **GL Accountant** owning specific GL accounts and responsible for explaining flux items within SLA.
- **FP&A team** comparing actuals to budget and updating rolling forecasts based on flux insights.
- **Internal audit** validating that controls (segregation of duties, journal review, period-lock enforcement) are operating.
- **External auditor** at year-end, using the close-quality KPIs and audit-correction ratio as evidence of an effective close process.
- **Operational managers** (sales lead, ops lead) receiving auto-routed flux items for their P&L line and explaining them in-app rather than via spreadsheet email chains.
- **Audit committee** seeing the trend of time-to-close and post-close adjustments as a governance KPI.
