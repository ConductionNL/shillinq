# Design — Continuous-Close and Flux Analysis

## Context

The shift from periodic 10-15 working day month-end close to
continuous close is the operating model behind every fast-close
benchmark. The core building blocks are: automated accruals,
continuous reconciliation, period-locked transactions, exception-
driven review, and flux analysis with explanations attached to
material variances.

This change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire continuous-close surface as **declarative
  metadata** — schemas + lifecycle + rule-driven accrual
  computation + flux analysis — per ADR-031.
- Make the spec a **competent-controller readable contract** —
  Dutch SMB month-end-close flow recognisable end-to-end (accrual
  configuration, soft-close execution, flux analysis, close
  checklist, board-pack narrative).
- Keep the soft-close orchestration **minimal** — one service
  class per ADR-031 exception that sequences accrual rules,
  delegates to IFRS modules, and emits alerts.
- Enable **flux explanations to scale** — rule-based attribution
  covers 80% of variances; remainder escalates to owner within
  24-hour SLA.

## Non-Goals

- No imperative accrual-posting service; accrual computation is
  declarative rule evaluation.
- No custom matching engine for intercompany reconciliation;
  existing GL transaction matching is delegated.
- No PSD2 live-feed connectors — T4.
- No rolling forecast — budget/prior-period/prior-year only for T2.

## Decisions

### D1 — Period lifecycle is a register with stage transitions

`PeriodStatus` register carries administratie, period, stage
(open | soft-closed | hard-closed | audited | locked), stage-
change history, owner per stage, and posting-restriction flags.
Posting to a hard-closed period without override is prevented by
GL posting precondition per REQ-CLS-001.

**Alternative considered**: Period as a property of the Period
entity (T1 period-close module). Rejected — close checklists,
accruals, and flux are period-scoped workflows that need first-
class registers for lifecycle, audit, and operator confirmation.

### D2 — Auto-accrual rules are declarative with 5 calculation methods

`AutoAccrualRule` register declares target GL account, calculation
method (fixed amount, percentage, straight-line, days-elapsed,
external-lookup), source data, frequency, and reversal pattern
(first-of-month, on-receipt, on-settlement). Each `AutoAccrualPosting`
record links back to rule version + resulting JournalEntry for
audit trail.

**Alternative considered**: One `AccrualService.php` that evaluates
all rules in a loop. Rejected — per ADR-031, rule evaluation is
declarative; the service only orchestrates the sequence.

### D3 — Soft-close job is orchestrated by one service; accrual + FX + depreciation + delegation are calculation/aggregation

The nightly `SoftCloseExecutor` service (per ADR-031 exception)
sequences: execute accrual rules (via aggregation + calculation),
call `bookkeeping-treasury-ihb` for FX revaluation + interest,
call `bookkeeping-ifrs15-revenue` for revenue cut-off, call
`bookkeeping-ifrs16-leases` for lease postings, execute
intercompany matching. Each sub-task is declarative (rule, calc,
or delegation); the service is glue code only.

**Alternative considered**: Monolithic `CloseEngine` service. Rejected
— modularization via delegation to T2 sibling specs.

### D4 — Flux analysis runs post-soft-close and on-demand

`FluxRun` timestamp, scope (administratie, segment, cost centre),
comparison basis (budget | forecast | prior period | prior year),
materiality thresholds (absolute + percentage). For each GL account
or KPI, compute variance, classify materiality, and either auto-
explain or escalate to owner. `FluxAttribution` records carry
quantified driver decomposition (volume, price, mix, FX, one-off).

**Alternative considered**: Continuous intra-month flux monitoring
with no materiality threshold. Rejected — information overload;
exception-driven review is more actionable.

### D5 — Close-checklist template is reusable with task dependencies

`CloseChecklistTemplate` per administratie type; `CloseChecklistInstance`
instantiated per period. Tasks carry status, owner, due-by, completed-at,
and evidence attachment. Dependencies are declarative (e.g.,
"Bank rec done" must complete before "Period reconciled").

**Alternative considered**: Hardcoded checklist list in a PHP
service. Rejected — reusability + operator customization require
a register.

### D6 — Materiality thresholds are per administratie + account group

`MaterialityPolicy` register carries administratie, account-group
thresholds (absolute floor + percentage floor), special rules for
cash + tax + revenue (lower thresholds). Flux items are routed
to owner if variance > threshold.

**Alternative considered**: Single global materiality threshold.
Rejected — cash + tax + revenue are higher-risk accounts that need
lower thresholds in most orgs.

### D7 — Flux narrative is aggregated and ordered by variance size

`FluxAttribution` records, once owner-explained or auto-resolved,
are aggregated into a flux narrative (Markdown/PDF/JSON) ranked
by absolute variance. The narrative becomes page 1 of the monthly
board pack.

**Alternative considered**: Real-time flux dashboard without
narrative. Rejected — board-pack narrative is the standard governance
artifact in Dutch SMEs; dashboards are T4+ analytics.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Period entity + lifecycle | T1 `bookkeeping-period-close` module | `PeriodStatus` register extends with soft-close + hard-close stages; depends on T1's period-close module for base entity |
| GL line source + target | T1 `bookkeeping-general-ledger` | Accrual rules target GL accounts; flux analysis sources GL balances; FX revaluation + interest delegate to treasury module |
| Accrual rule evaluation | None (new) | Declarative rule register + calculation/aggregation engine |
| FX revaluation + interest | T2 `bookkeeping-treasury-ihb` | Soft-close job delegates; treasury module returns posting instructions |
| Revenue cut-off | T2 `bookkeeping-ifrs15-revenue` | Soft-close job delegates; revenue module returns posting instructions |
| Lease postings | T2 `bookkeeping-ifrs16-leases` | Soft-close job delegates; lease module returns posting instructions |
| Intercompany matching | T1 GL matching (future or existing) | Soft-close job delegates GL transaction matching |
| Close checklist | None (new) | Reusable template + per-period instantiation |
| Flux materiality rules | None (new) | Per-administratie + account-group thresholds |
| Flux explanation | None (new) | Rule-based driver decomposition; owner-text fallback |
| Flux narrative export | T2 `bookkeeping-document-attachment-integration` | Narrative exported to PDF/Markdown/JSON; archived via docudesk |
| Audit trail | OR `x-openregister-audit` (ADR-022) | Automatic on all register mutations |

**Net new code in implementation cycle**: 11 schema declarations + 3
lifecycle blocks + 3 aggregation queries + 1 calculation for flux
materiality + 1 orchestration service (`SoftCloseExecutor`, ~150
LOC) + 3 manifest entry pairs.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Accrual rule definition + execution | Declarative register + calculation/aggregation | Pure data + computation |
| Soft-close orchestration | Imperative `SoftCloseExecutor` service (ADR-031 exception) | Sequences accrual + FX + IFRS modules; glue code only |
| FX revaluation + interest | Delegation to treasury module | Owned by sibling spec |
| Revenue cut-off | Delegation to IFRS 15 module | Owned by sibling spec |
| Lease postings | Delegation to IFRS 16 module | Owned by sibling spec |
| Period-lock enforcement | Declarative GL posting precondition on PeriodStatus.stage | Engine evaluates; no service |
| Flux analysis algorithm | Declarative aggregation + calculation | Pure data + computation |
| Flux auto-explanation | Declarative rule-based driver decomposition | Pure attribution logic |
| Close-checklist lifecycle | Declarative `x-openregister-lifecycle` | State machine |
| Materiality threshold evaluation | Declarative aggregation + calculation | Pure comparison |

`SoftCloseExecutor` authorized per ADR-031 exception because it is
orchestration glue — no policy decision-making.

## Seed Data

5 example accrual rules (Dutch naming):

1. **Rent Accrual** — fixed 12,000 EUR/month to 4001-rent,
   contra 2100-accrued-rent, reverse 1st-of-month.
2. **Utilities Accrual** — 3% of month-to-date revenue to 4005-utilities,
   contra 2105-accrued-utilities, reverse on invoice receipt.
3. **Salaries Accrual** — lookup from payroll calendar per role,
   reverse on settlement.
4. **Interest Accrual** — daily from loan schedule to 4010-interest,
   contra 2110-accrued-interest, reverse 1st-of-month.
5. **Depreciation** — straight-line per asset schedule to 6001-depreciation,
   contra 1050-accumulated-depreciation, reverse never.

Sample materiality policy:
- Operational accounts: 1000 EUR absolute or 2% of account balance.
- Cash accounts: 100 EUR absolute or 0.5%.
- Tax accounts: 50 EUR absolute or 0.1%.
- Revenue accounts: 500 EUR absolute or 1%.

Default close checklist (reusable):
- [ ] Bank reconciliation completed
- [ ] AP cut-off reviewed
- [ ] AR ageing reviewed
- [ ] Accruals posted
- [ ] FX revaluation done
- [ ] Intercompany matched
- [ ] Depreciation posted
- [ ] Payroll booked
- [ ] Tax provision posted
- [ ] Flux analysis reviewed
- [ ] Board pack drafted

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Soft-close job exceeds 07:00 window | Flux analysis runs on-demand during business hours; accrual + FX prioritized for on-time completion |
| Accrual reversal orphaned if invoice never arrives | Three reversal patterns; on-receipt pattern ensures no orphans; auto-cleanup rule for aged accruals is future enhancement |
| Flux auto-explanation covers only 80% of variances | Owner escalation with 24-hour SLA; ML-driven attribution is future roadmap item |
| Materiality threshold tuning burden on controller | Defaults from APQC + Hackett; operator customizable per administratie + account group; industry-pack templates future item |
| Multiple administratie close checklists unsynced | Template-per-type allows reuse; dependencies ensure logical sequencing; cross-administratie consolidation is future item |
| Flux narrative narrative bloat if many unexamined items | Materiality filtering + materiality-escalation route keeps narrative focused on material exceptions |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with 11 new
   schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with 3 new menu entries + their
   pages (additive).
3. `lib/Service/SoftCloseExecutor.php` ships with orchestration
   logic (~150 LOC, ADR-031 exception annotated).
4. A nightly cron job or n8n workflow is configured to invoke
   `SoftCloseExecutor` per administratie.

Down-direction: registers are non-destructive — reverting removes
the manifest entries; periods + accruals + flux items remain
queryable but unreferenced.

## Open Questions

1. **Soft-close window timing** — is 07:00 local a realistic target
   for full trial balance completion? If not, what window is
   acceptable? What is the SLA for flux-analysis availability?
2. **Flux auto-explanation coverage** — what percentage should be
   auto-explained? 80% assumed; confirm during discovery.
3. **Rolling forecast** — spec assumes rolling forecast will be
   defined separately (T3+). Correct? If flux analysis should include
   rolling-forecast comparison in T2, that module must be available.
4. **Accrual-cleanup policy** — should aged accruals (e.g., rent
   accrual older than 2 months unreversed) be auto-cleaned or
   escalated? Current scope: manual review; future enhancement.
5. **Post-close adjustment visibility** — confirm that post-close
   adjustments should be flagged in next month's flux narrative.
6. **Close-checklist evidence attachment** — should evidence be
   stored in docudesk or app-local? Assuming docudesk per ADR-022;
   confirm during discovery.
