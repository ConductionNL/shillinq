# Design — Schatkistbankieren (Treasury Banking Compliance)

**status: pr-created**

## Context

Dutch government entities and public-sector organizations must comply with strict
treasury banking regulations (schatkistbankieren) enforced by the Ministry of Finance.
These regulations require segregation of treasury-managed bank accounts, continuous
compliance monitoring, and periodic regulatory reporting for central banking oversight.

Per the legacy governance features from intelligence-db (`competitor_features` with
`app_slug=shillinq`), compliance monitoring + treasury account management + regulatory
reporting are top-tier governance-asked features. Per ADR-022, approval routing comes
from OR, not from an app-local table. Per ADR-031, compliance scoring and regulatory
export are declarative calculations, not a `ComplianceService`. This change locks
those decisions into the spec.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the
standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the entire schatkistbankieren-compliance surface as **declarative metadata** —
  schemas + lifecycle + calculations + aggregations + manifest entries — per ADR-031.
- Consume OR's approval-workflow abstraction — per ADR-022. Zero parallel approval table.
- Make the spec a **competent-compliance-officer readable contract** — Dutch government
  treasury banking requirements recognisable end-to-end (account intake, compliance
  verification, periodic monitoring, regulatory reporting).
- Keep the compliance precondition shape flexible so future governance tiers attach
  additively without destructive migration.

## Non-Goals

- No PHP compliance service, no `ComplianceService.php`.
- No PHP regulatory-report generator, no `ReportGeneratorService`.
- No live bank integration — T3+.
- No multi-currency compliance — T5.
- No custom compliance workflows — declarative baseline only.

## Decisions

### D1 — Treasury accounts are master-list governance objects

`TreasuryAccount` is a master-list register carrying treasury-specific fields
(IBAN, compliance classification, admin-scoped attributes). Activating a treasury
account triggers compliance verification per the BankingRule criteria. Treasury
accounts map to Chart-of-Accounts entries for GL-integration per T1.

**Alternative considered**: Treasury accounts directly embedded in Account without
a separate register. Rejected — treasury-specific compliance metadata, audit trails,
and approval workflows differ from general ledger accounts; separation is required
for governance segregation and reporting.

### D2 — Banking rules are configurable compliance criteria

`BankingRule` is a register defining compliance criteria (IBAN format requirements,
segregation rules, transaction-limit thresholds, reporting-period obligations).
Operators configure rules per administration; rules are evaluated declaratively
during treasury-account lifecycle transitions.

**Alternative considered**: Hard-coded compliance rules in the spec. Rejected per
ADR-022 — compliance requirements vary by administration and government level
(municipal, provincial, national, waterboard). Rules must be operator-configurable.

### D3 — Multi-criteria conditional compliance precondition

When a treasury account is activated, OR's lifecycle engine evaluates all applicable
`BankingRule` criteria (IBAN format, segregation, admin approval). If any criterion
fails, activation is blocked pending remediation. The precondition is conditional:
rules apply only if marked active in that administration.

If OR's lifecycle engine cannot express multi-criteria conditional logic, ADR-031's
exception path applies: a single-method `OCA\Shillinq\Lifecycle\ComplianceValidator::
isCompliant(...)` ships, cited in the spec.

### D4 — Compliance reports are aggregated snapshots

A `ComplianceReport` is a register carrying a periodic snapshot of compliance status
(calculated at report-generation time), compliance-score metrics (automated calculation
from BankingRule criteria match), and regulatory-export status. Operator triggers
report generation; the calculation outputs a scored summary queryable by period and
administration.

**Alternative considered**: A `ComplianceService` that orchestrates rule evaluation
+ report generation. Rejected per ADR-031 — the calculation extension covers scoring;
aggregation extension handles grouping by period; report lifecycle is declarative.

### D5 — Compliance metrics are aggregations

`x-openregister-aggregations` query grouping `ComplianceReport` by
`(administrationId, reportPeriod)` and aggregating compliance scores per criteria
category (IBAN hygiene, segregation, approval, reporting). Compliant/non-compliant
status computed from score thresholds.

**Alternative considered**: A PHP `ComplianceAggregationService`. Rejected per
ADR-031 — aggregation engine handles GROUP BY + score computation.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Treasury account lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `TreasuryAccount` with multi-criteria compliance precondition; audit trail per T2 `bookkeeping-audit-trail` |
| Compliance approval routing | OR approval-workflow (ADR-022) | Consumed via `x-openregister-lifecycle.requires`; no shillinq approval table |
| Compliance rule definition | New T2 register (`BankingRule`) | Operator-configurable per administration; evaluated declaratively in `TreasuryAccount` lifecycle |
| Compliance score calculation | OR `x-openregister-calculations` (ADR-031) | Scoring field on `ComplianceReport.complianceScore`; automated criteria match; no PHP service |
| Compliance aggregation | OR `x-openregister-aggregations` (ADR-031) | GROUP BY `(administrationId, reportPeriod)` with score summaries |
| Audit trail materialisation | T2 `bookkeeping-audit-trail` | Automatic on lifecycle transitions; compliance events tracked |
| Treasury account classification | T1 `Account` hierarchy | Treasury accounts linked to Chart-of-Accounts for GL classification |
| Manifest navigation | T1 manifest pattern | 3 entries (Treasury Accounts, Banking Rules, Compliance Reports) + their pages |

**Net new code in implementation cycle**: 3 schema declarations + 1 lifecycle block + 1
calculation field + 1 aggregation + 3 manifest entry pairs. At most 1 single-method PHP
guard (`ComplianceValidator`) gated by ADR-031 exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Treasury account lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Multi-criteria compliance precondition | Declarative if engine supports multi-criteria clauses; else single-method PHP guard (`ComplianceValidator`) per ADR-031 exception | Resolution in discovery; spec shape-neutral |
| Compliance approval routing | Consumed from OR approval-workflow | ADR-022 |
| Banking rule evaluation | Declarative (rule application in lifecycle preconditions) | Pure data-driven criteria matching |
| Compliance score calculation | Declarative (`x-openregister-calculations`) | Weighted criteria match → score transformation |
| Compliance aggregation | Declarative (`x-openregister-aggregations`) | GROUP BY + SUM with period-based bucketing |
| Lifecycle-transition audit logging | T2 `bookkeeping-audit-trail` extension | Automatic tracking per spec |

No service class authored in this envelope (subject to ADR-031 exception: at most one
single-method `ComplianceValidator`).

## Seed Data

Treasury accounts and banking rules are operator-authored on first use per administration.
Three example banking rules are included as seed data per ADR-001 (app-config template):
1. `rule-iban-format` — validates Dutch IBAN format (^NL[0-9]{2}[A-Z]{4}[0-9]{10}$)
2. `rule-segregation` — ensures treasury accounts are segregated by administration
3. `rule-approval-required` — requires admin approval before activation

These rules are loaded via `ConfigurationService::importFromApp()` on first install.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Multi-criteria compliance precondition not declaratively expressible | Single-method `ComplianceValidator` per ADR-031 exception; remove when OR's engine supports multi-criteria clauses |
| Compliance scoring weights too rigid | Operator-configurable rule weights per administration; resolved during implementing cycle's UX review |
| Regulatory reporting format misalignment with government standards | Coordinate with Ministry of Finance + government stakeholders; pin format version in spec |
| Banking rule complexity grows as new compliance requirements emerge | Modular rule register; new rules added via operator configuration, not code changes |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the three schemas (additive —
   no existing schema changes).
2. Seed data (3 base banking rules) loaded via `importFromApp()` on first install.
3. `src/manifest.json` is patched with 3 new menu entries + their pages (additive).
4. If multi-criteria compliance precondition cannot be expressed declaratively,
   `lib/Lifecycle/ComplianceValidator.php` ships (single method, ~30 LOC, ADR-031
   exception annotated).

Down-direction: registers are non-destructive — reverting removes the manifest entries
+ lifecycle bindings; treasury accounts and compliance reports remain queryable but
unreferenced.

## Open Questions

1. **Multi-criteria compliance precondition shape** — resolved in `opsx-ff` discovery;
   spec shape-neutral.
2. **Compliance scoring weights and administration-specific overrides** — resolved during
   implementing cycle's configuration review.
3. **Regulatory reporting format alignment with Dutch government standards** — coordinated
   with Ministry of Finance during spec implementation.
4. **Banking rule versioning and historical compliance tracking** — resolved during
   implementing cycle's data-model review.
