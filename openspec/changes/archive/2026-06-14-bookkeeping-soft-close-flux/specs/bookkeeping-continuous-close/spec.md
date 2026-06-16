# Spec: bookkeeping-continuous-close

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** `../bookkeeping-period-close/spec.md` (T1 period entity),
`../bookkeeping-general-ledger/spec.md` (GL source + target),
`../bookkeeping-ifrs15-revenue/spec.md` (revenue cut-off delegation),
`../bookkeeping-treasury-ihb/spec.md` (FX + interest accruals),
`../bookkeeping-accounts-payable/spec.md` (AP cut-off),
`../bookkeeping-accounts-receivable/spec.md` (AR cut-off)

## ADDED Requirements

### Requirement: REQ-CLS-001: Period lifecycle SHALL enforce stage transitions and posting restrictions per stage

The system MUST support a period lifecycle with five stages:
- **open** — default state; all posting allowed
- **soft-closed** — soft-close job completed; posting restricted to corrections + accrual reversals
- **hard-closed** — operator manual transition; no posting except via controller override + exception journal
- **audited** — external auditor sign-off; no posting
- **locked** — period locked forever; no posting

Transitions MUST be tracked in `PeriodStatus.stageChangeHistory` with timestamp, actor, and reason.

GL posting preconditions MUST enforce: no posting to periods
in hard-closed, audited, or locked stages unless actor has
override privilege.

#### Scenario: Soft-closed period rejects regular posting, allows accrual reversal

- **GIVEN** period March is soft-closed as of 17 March
- **WHEN** a user attempts to post a regular GL transaction dated 18 March
- **THEN** the system MUST reject with reason "Period is soft-closed; only accrual reversals + corrections allowed"
- **AND** a user with accrual-reversal privilege attempting to reverse the rent accrual MUST succeed

#### Scenario: Hard-closed period rejects posting unless override

- **GIVEN** period March is hard-closed on 4 April
- **WHEN** a user attempts to post an invoice dated 29 March
- **THEN** the system MUST reject with reason "Period is hard-closed; post to April with prior-period adjustment flag instead"
- **AND** if a controller overrides and posts to March, the posting MUST be flagged as a "post-close exception" for audit trail

#### Scenario: Locked period allows no posting

- **GIVEN** period March is locked (year-end sign-off)
- **WHEN** any user attempts to post any transaction
- **THEN** the system MUST refuse with reason "Period is locked"

### Requirement: REQ-CLS-002: Nightly soft-close job SHALL execute per administratie and complete by 07:00 local with full trial balance

The system MUST execute a nightly soft-close job per administratie that:
1. Executes all configured auto-accrual rules (per REQ-CLS-003)
2. Calls FX revaluation via `bookkeeping-treasury-ihb` module
3. Calls interest accrual via treasury module
4. Calls revenue cut-off via `bookkeeping-ifrs15-revenue` module
5. Calls lease postings via `bookkeeping-ifrs16-leases` module (if implemented)
6. Executes intercompany matching against GL transactions
7. Generates complete trial balance (all GL accounts + balances)
8. Marks period as "soft-closed" with timestamp
9. Emits `ContinuousCloseAlert` for any failed rule execution
10. Returns success/failure status + posting count to n8n orchestrator

Target completion: 07:00 local time. If execution exceeds 07:00,
soft-close completes but flux analysis runs on-demand during
business hours (separate, non-blocking).

#### Scenario: Soft-close job runs 17 March; posts pro-rata accruals

- **GIVEN** administratie NL-001, period March, 17 days elapsed
- **WHEN** soft-close job runs at 00:30 on 17 March
- **THEN** the system MUST post pro-rata accruals:
  - Rent: EUR 6,580 (17/31 × 12,000)
  - Utilities: EUR X (3% of month-to-date revenue)
  - Salaries: per payroll cadence (e.g., EUR 8,200 if 4 salaried staff × EUR 2,050/week)
  - Interest: daily rate × 17 days
- **AND** each accrual offset by a contra entry to the accrued-expense account
- **AND** trial balance is complete by 06:45
- **AND** period is marked soft-closed with timestamp 06:45

#### Scenario: Soft-close job fails on FX revaluation; alert routed to CFO

- **GIVEN** administratie has 5 open FX positions
- **WHEN** soft-close job runs and treasury module returns HTTP 500
- **THEN** the system MUST emit `ContinuousCloseAlert` with severity:error, text "FX revaluation failed; please review treasury module", routed to CFO + Controller
- **AND** soft-close MUST halt (partial postings rolled back)
- **AND** job returns failure status to n8n for retry

### Requirement: REQ-CLS-003: Auto-accrual rules SHALL be configurable with 5 calculation methods and 3 reversal patterns

`AutoAccrualRule` register MUST declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `ruleName` | string | Yes | Human-readable name |
| `targetGLAccount` | string (FK) | Yes | Target GL account code |
| `contraGLAccount` | string (FK) | Yes | Contra/accrued-expense account |
| `calculationMethod` | enum | Yes | One of: fixed-amount, percentage-of-revenue, straight-line-from-contract, days-elapsed-of-period, external-lookup |
| `calculationParameters` | JSON object | Yes | Parameters per method (e.g., `{amount: 12000}` for fixed, `{rate: 0.03, sourceField: "revenue_mtd"}` for percentage) |
| `reversalPattern` | enum | Yes | One of: first-of-next-month, on-receipt-of-invoice, on-settlement |
| `frequency` | enum | Yes | daily, weekly, monthly (execution frequency) |
| `administrationId` | string (FK) | Yes | FK to administration |
| `lifecycleState` | enum | Yes | active, disabled, archived |

Each execution MUST create an `AutoAccrualPosting` record linking
rule version + resulting JournalEntry for audit trail.

Reversals MUST be posted with contra-entry type (reversing entry)
per IAS 8.

#### Scenario: Fixed-amount rent accrual; daily execution; reverse 1st-of-month

- **GIVEN** rule: rent accrual, EUR 12,000/month to 4001-rent contra 2100-accrued-rent
- **WHEN** soft-close job runs 17 March
- **THEN** posting MUST be: DR 6001-accruals EUR 6,580 CR 2100-accrued-rent EUR 6,580
- **AND** a reversing entry MUST be queued for 1 April: DR 2100-accrued-rent EUR 6,580 CR 6001-accruals EUR 6,580
- **AND** when 1 April arrives, the reversing entry MUST post automatically

#### Scenario: Percentage-of-revenue utilities; reverse on invoice receipt

- **GIVEN** rule: utilities, 3% of month-to-date revenue
- **WHEN** soft-close runs 17 March and MTD revenue = EUR 450,000
- **THEN** posting MUST be: DR 6005-accruals EUR 13,500 CR 2105-accrued-utilities EUR 13,500
- **AND** reversal MUST be queued but NOT posted until an AP invoice matching the utilities supplier is received + posted
- **AND** when utilities invoice posts, system MUST auto-match + post reversal

#### Scenario: Contract-lookup interest; daily execution; straight-line

- **GIVEN** rule: interest, 5% annual on EUR 100,000 loan, 365-day year
- **WHEN** soft-close runs daily
- **THEN** posting MUST be: DR 6010-accruals EUR 1,370 (100,000 × 0.05 / 365) CR 2110-accrued-interest EUR 1,370
- **AND** no reversal (interest accrues daily; no reversal pattern)

### Requirement: REQ-CLS-004: Close-checklist template SHALL be reusable per administratie type with task dependencies and SLA

`CloseChecklistTemplate` register MUST declare a reusable list of
close tasks per administratie type. `CloseChecklistInstance` MUST
be instantiated automatically when a period opens.

| Template Field | Type | Purpose |
|---|---|---|
| `templateName` | string | e.g., "Standard SMB Close" |
| `administrationTypeId` | string | FK to administration type (BV, NV, eenmanszaak) |
| `tasks` | array | List of task definitions |

Each task definition MUST carry:

| Task Field | Type | Purpose |
|---|---|---|
| `taskId` | string | Unique within template |
| `taskName` | string | e.g., "Bank reconciliation completed" |
| `taskOwner` | string or role | e.g., "Bank Rec Officer" or "Finance Manager" |
| `dueBefore` | enum | e.g., "end-of-period+2", "end-of-period+5" |
| `dependsOn` | array | List of `taskId` that must complete first |
| `evidenceRequired` | boolean | Whether attachment/evidence is needed |

`CloseChecklistInstance` MUST track per period:
- Task status (pending, in-progress, completed, overdue)
- Owner (may differ from template default)
- Completed timestamp
- Evidence attachment
- SLA breach: if completion > dueBefore, escalate to period owner

#### Scenario: Period opens; checklist is auto-instantiated

- **GIVEN** period April opens
- **WHEN** period transitions to "open" state
- **THEN** system MUST instantiate `CloseChecklistInstance` from the default template
- **AND** all tasks MUST be in "pending" state with their template owners assigned

#### Scenario: Task dependency enforced

- **GIVEN** "Period reconciled" task depends on "Bank rec completed"
- **WHEN** a user marks "Period reconciled" as done
- **THEN** the system MUST check if "Bank rec completed" is also done
- **AND** if not done, MUST refuse with error "Prerequisite task 'Bank rec completed' not yet done"

#### Scenario: SLA escalation on overdue task

- **GIVEN** "Flux analysis reviewed" task due before end-of-period+5 = 5 April
- **WHEN** 6 April arrives and task is still pending
- **THEN** system MUST send escalation alert to task owner + period owner
- **AND** flag task as "overdue" in UI

### Requirement: REQ-CLS-005: Flux analysis SHALL run post-soft-close, computing variance vs budget/PY/PP/forecast with materiality-driven routing

Post-soft-close, the system MUST execute a `FluxRun` that:
1. Scope: per administratie, or per segment, or per cost centre (operator choice)
2. Comparison basis: budget vs actual, prior-period vs actual, prior-year vs actual, rolling-forecast vs actual (selectable)
3. For each GL account or KPI:
   - Compute variance (absolute + percentage)
   - Classify materiality: immaterial, material, highly-material
4. Route per materiality:
   - Immaterial: ignored
   - Material: attempted auto-explanation via rule-based drivers (REQ-CLS-006)
   - Highly-material: escalated to account owner regardless of auto-explanation

Materiality thresholds per `MaterialityPolicy` register:
- Per administratie
- Per account group (operational, cash, tax, revenue, etc.)
- Absolute floor + percentage floor (max of both applies)
- Special lower thresholds for cash + tax + revenue

#### Scenario: COGS variance +180K vs budget (15% adverse); auto-explain volume/price/mix/FX

- **GIVEN** COGS budget EUR 1.2M; actual EUR 1.38M; variance +EUR 180K / +15%
- **WHEN** flux analysis runs
- **THEN** system MUST decompose:
  - Volume effect: +10% units → +EUR 80K
  - Price effect: raw-material price +6% → +EUR 60K
  - Mix effect: shift to lower-margin SKUs → +EUR 20K
  - FX effect: USD purchases at weaker EUR rate → +EUR 20K
- **AND** publish explanation to flux narrative
- **AND** mark variance as "explained" if all components below materiality threshold

#### Scenario: Cash variance +EUR 50K; immaterial

- **GIVEN** cash account threshold: EUR 100 absolute or 0.5% of balance
- **WHEN** cash balance variance = +EUR 50K but balance = EUR 10M
- **THEN** percentage = 0.5%, equals threshold
- **AND** variance marked "immaterial" per tie-break rule (max of absolute/percentage)

#### Scenario: Tax provision variance +EUR 500; highly-material

- **GIVEN** tax account threshold: EUR 50 absolute or 0.1% of balance
- **WHEN** tax provision variance = +EUR 500
- **THEN** variance is +1000% above threshold → "highly-material"
- **AND** escalated to tax officer for explanation regardless of auto-explanation

### Requirement: REQ-CLS-006: Material flux items above materiality threshold SHALL receive rule-based auto-explanation or owner escalation with 24-hour SLA

For each `FluxItem` classified as "material" or above:

1. **Attempt auto-explanation** via driver decomposition:
   - Volume: unit count variance
   - Price: unit cost variance
   - Mix: product-mix shift variance
   - FX: foreign-exchange revaluation variance
   - One-off: adjustments, write-offs, one-time items

2. If auto-explanation covers ≥80% of variance:
   - Mark item status: "auto-explained"
   - Publish explanation to flux narrative
   - No owner escalation

3. If auto-explanation covers <80%:
   - Create escalation to GL account owner
   - SLA: 24 hours from soft-close timestamp
   - Owner MUST provide free-text explanation or accept auto-explanation
   - If SLA breached without owner response:
     - Send escalation alert to period owner (CFO)
     - Include "unexplained flux item" in close-quality KPIs

#### Scenario: Salaries variance +12% explained by 4 new hires in March

- **GIVEN** salaries budget EUR 50K; actual EUR 56K; variance +EUR 6K / +12%
- **WHEN** flux analysis decomposes variance
- **THEN** system MUST recognize volume change: 5 employees → 9 employees = +4 hires
- **AND** auto-explanation MUST be: "+12% explained by 4 new hires in March starting at EUR 1,500/person"
- **AND** mark as "auto-explained"; no owner escalation

#### Scenario: Freight variance +EUR 8K; 50% explained by volume; 50% unexplained

- **GIVEN** freight budget EUR 20K; actual EUR 28K; variance +EUR 8K
- **WHEN** auto-explanation decomposes: volume +50% = +EUR 4K; remainder +EUR 4K unattributed
- **THEN** system MUST mark as "partially explained"
- **AND** escalate to logistics manager with text "Variance +EUR 8K partially explained by volume increase; please explain remaining +EUR 4K"
- **AND** SLA: 24 hours

### Requirement: REQ-CLS-007: Flux narrative SHALL aggregate owner-explained variances, ranked by absolute variance, exportable to PDF/Markdown/JSON

After all `FluxItem` records are marked (auto-explained, owner-explained,
or unexplained), the system MUST generate a `FluxNarrative` that:

1. Lists variances by account, ranked by absolute variance (largest first)
2. Includes: account name, budget/actual/variance, explanation (auto or owner-text)
3. Excludes immaterial items
4. Summary: total variances, total explained, total unexplained, SLA-breach count
5. Exportable formats: PDF (1-page summary + detail), Markdown (for wiki/email), JSON (for board-pack embedding)

#### Scenario: Flux narrative for March 2026; 5 material items

| Account | Budget | Actual | Variance | Explanation |
|---|---|---|---|---|
| COGS | 1.2M | 1.38M | +180K (+15%) | Volume +10%, price +6%, mix +20K, FX +20K |
| Salaries | 50K | 56K | +6K (+12%) | 4 new hires starting 1 March |
| Freight | 20K | 28K | +8K (+40%) | Volume +50%; remaining +EUR 4K unexplained (SLA breach) |
| R&D | 30K | 29K | -1K (-3%) | Immaterial; excluded from narrative |
| Cash | varies | | | Immaterial threshold; excluded |

**Summary**: 5 material items reviewed; 4 explained (80%); 1 unexplained (SLA breach flagged). Total adverse variance EUR 194K.

**Export**: PDF rendered with company letterhead, period, CFO signature line; Markdown for email; JSON for board-pack embed.

### Requirement: REQ-CLS-008: Comparative dashboards SHALL display current vs budget/prior-period/prior-year at administratie/segment/consolidated level with drill-down

The system MUST provide dashboards (to be implemented; spec defines contract):

- **By administratie**: Current month vs budget, vs prior period, vs prior year
- **By segment** (cost centre, department, product line): Same comparisons
- **Consolidated**: Group-level across all administraties
- **Drill-down**: From administratie → segment → GL account → underlying transactions in 2 clicks

Metrics: absolute variance, percentage variance, materiality flag, explanation status.

### Requirement: REQ-CLS-009: Close-quality KPIs SHALL track time-to-close, post-close adjustments, audit-correction ratio, and SLA compliance over 12 periods

The system MUST collect and publish `CloseMetrics` per administratie, tracked over 12 periods:

| Metric | Definition | Purpose |
|---|---|---|
| Time-to-close | Days from period-end to hard-close (excluding audit-lock) | Continuous-close capability metric |
| Post-close adjustments | Count of entries posted to a period after hard-close | Control quality metric |
| Audit-correction ratio | (audit adjustments) / (total close adjustments) | Audit-effectiveness metric |
| Flux-SLA compliance | % of material flux items explained within 24-hour SLA | Close-discipline metric |
| Unexplained flux items | Count of material variances still unexplained at board-pack publication | Exception metric |

Trend over 12 months; dashboard to be implemented separately.

#### Scenario: Close quality report for January 2026

- Time-to-close: 4 days (2 days faster than prior year)
- Post-close adjustments: 2 entries (EUR 8K)
- Audit-correction ratio: 0% (no audit adjustments)
- Flux-SLA compliance: 95% (1 of 20 material items missed 24-hour SLA)
- Unexplained flux items: 1 (freight variance, owner unresponsive)

### Requirement: REQ-CLS-010: All automated postings (accruals, reversals, FX, depreciation) SHALL be auditable to rule, source data, user, timestamp, and reversible

Every `AutoAccrualPosting`, FX revaluation posting, depreciation
posting, and generated JournalEntry MUST carry:

- Link to source rule (rule ID + rule version)
- Link to source data (e.g., payroll calendar, contract, GL balance)
- Posting user: "SYSTEM:SoftCloseExecutor" or specific service
- Timestamp (creation + modification)
- Audit trail immutable per ADR-022
- Reversal/correction workflow: original entry preserved; reversal posted as separate entry with audit link

## MODIFIED Requirements (if amending existing specs)

None. This is an ADDED-only change.

## Regulatory & Standards References

- **IFRS / IAS**: IAS 1 (presentation), IAS 8 (accounting policies), IAS 10 (events after reporting period), IAS 34 (interim reporting)
- **Dutch GAAP**: BW2 Title 9 / RJ (period reporting)
- **COSO 2013**: Internal control framework (period close as key control)
- **APQC**: Process Classification Framework (8.0 Manage Financial Resources) — close benchmarks
- **Hackett Group**: "World-Class Finance" — 3-5 day close target
- **AICPA / IIA**: Management review controls + journal-entry controls
- **Sarbanes-Oxley s.404**: Journal-entry control requirements (if applicable to group)

## Implementation Notes (for opsx-apply cycle)

- Soft-close orchestration service (`SoftCloseExecutor`) is ADR-031 exception — annotate with ADR reference.
- All registers carry `x-openregister-audit: true` per REQ-CLS-010.
- Flux analysis is triggered via POST route for on-demand execution; cron/n8n calls same endpoint.
- Period-lock enforcement is a GL posting precondition; no separate service.
- Accrual rules are declarative; any imperative calculation is delegated to OR calculation engine or to sibling modules (treasury, IFRS).
