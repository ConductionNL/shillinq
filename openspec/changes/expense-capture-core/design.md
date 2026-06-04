# Design — Expense Capture (Core)

**status: pr-created**

## Context

Shillinq's bookkeeping mandate requires every reimbursable outlay
to be posted to the GL and cost centre for management reporting and
VAT recovery audits. Receipt photo upload, mileage tracking, and
per-diem allowances are the three vectors identified in market
intelligence as standard in 17 of 26 competitors.

Per ADR-022, approval routing comes from OR, not from an app-local
table. Per ADR-031, mileage auto-rate calculation and per-diem rate
application are declarative calculations where feasible, not
imperative `ExpenseService` methods. This change locks those
decisions into the spec.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire expense-capture surface as **declarative
  metadata** — schemas + lifecycle + calculations + aggregations +
  manifest entries — per ADR-031.
- Consume OR's approval-workflow abstraction — per ADR-022. Zero
  parallel approval table.
- Make the spec a **compliance-aware bookkeeper readable contract**
  — Dutch SMB expense flow recognisable end-to-end (receipt intake,
  approval, multi-currency conversion, GL posting, cost-centre
  allocation).
- Support three discrete expense vectors (receipt, mileage, per-diem)
  grouped into a single claim for approval and reimbursement.
- Keep the FK shape open for future OCR integration and bank-
  statement matching without destructive migration.

## Non-Goals

- No PHP expense service, no `ExpenseService.php`.
- No photo OCR or text extraction — T3 enhancement.
- No bank-statement expense matching — T4 capability.
- No advanced tax recovery or deduction planning — future phase.
- No email receipt forwarding — out of scope (docudesk handles).

## Decisions

### D1 — Expense claim is a register carrying N line-item entries

An `ExpenseClaimEntry` is a register carrying the metadata for a
submitted claim. Related receipts, mileage entries, and per-diem
records are linked via FK relations. Posting a claim materialises
a balanced GL entry with per-line cost-centre allocation.

**Alternative considered**: A `Receipt` register as the unit of
posting (one GL entry per receipt). Rejected — expense approval and
reimbursement typically cover multiple receipts + mileage + per-diem
as a single claim; separate GL postings would fragment the audit
trail and confuse per-claim reporting.

### D2 — Expense approval routing comes from OR per ADR-022

The expense lifecycle declares `requires` clauses that consume OR's
approval-workflow abstraction (`x-openregister-approval`). No
app-local approval table in shillinq.

**Alternative considered**: A shillinq `ApprovalService` mirroring
legacy expense-management apps. Rejected per ADR-022 enumerated
anti-pattern.

### D3 — Multi-currency conversion at capture time, stored in base currency

When a receipt is uploaded in a foreign currency (e.g., EUR → USD),
the exchange rate is looked up or entered by the operator, and the
converted amount (in base EUR) is stored on the `Receipt`. The
original currency and rate are retained for audit.

**Alternative considered**: Store receipt in original currency;
convert at report time. Rejected — GL posting must happen in a
single currency per administration; deferring conversion fragments
the audit trail and makes cost-centre reporting ambiguous.

### D4 — Mileage total is auto-calculated from distance × rate

A `MileageEntry` accepts distance (manual or from route), looks up
the configured rate per km (usually €0.16–€0.21 per Dutch tax rules,
variable by vehicle and year), and calculates total.

**Alternative considered**: Operator manually enters distance and
total; system validates. Rejected — auto-calculation prevents
arithmetic errors and documents the rate used (important for tax
audit).

### D5 — Per-diem is looked up from country + night-count tables

A `PerDiem` entry accepts the country (NL, FI, etc.), night count,
and looks up the official daily rate for that country (e.g., NL
€125/day, FI €45/day per market intelligence). Operator enters the
dates and country; system calculates amount.

**Alternative considered**: Operator manually enters amount. Rejected
— per-diem is highly regulated by tax authorities; hard-coding the
rate prevents audit drift and simplifies compliance attestation.

### D6 — Three discrete expense vectors, one claim

A claim bundles multiple receipts, mileage journeys, and per-diem
days into a single approval + reimbursement. Each vector has its own
schema and lifecycle state, but all are tied to a parent claim.

**Alternative considered**: One unified "expense" schema with a
`type` field. Rejected — the three vectors have distinct validation
(photo vs. distance vs. date), distinct rate lookups, and distinct
business rules; unified schema would overload the entity.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Expense claim lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `ExpenseClaimEntry` with approval-workflow guard; materialises balanced GL entry per GL-posting pattern |
| Expense approval routing | OR approval-workflow (ADR-022) | Consumed via `x-openregister-lifecycle.requires`; no shillinq approval table |
| Multi-currency conversion | multi-currency capability (T2) | Exchange-rate lookup at receipt upload time; original currency + rate retained for audit |
| Mileage rate lookup | KvK/government tax tables (master data) | Rates stored per fiscal year in `MileageRate` master table; `MileageEntry.totalAmount` is a calculation field `distance × rate` |
| Per-diem rate lookup | IRS/government daily allowance tables (master data) | Rates stored per country + fiscal year in `PerDiemRate` master table; `PerDiem.allowanceAmount` is a calculation field `nights × rate` |
| GL cost-centre posting | T1 `JournalEntry` materialisation pattern (REQ-JE-007) | Same lifecycle action shape; emits one balanced GL entry per posted expense claim |
| Photo storage | Nextcloud File API or S3 (deployment choice) | `Receipt.photoUri` stores the URI; no new storage abstraction |
| Audit trail | T2 `bookkeeping-audit-trail` (OR audit-trail-immutable) | Automatic on lifecycle transitions |
| Manifest navigation | T1 manifest pattern | 3 entries (Receipts, Expense Claims, Mileage Log) + their pages |

**Net new code in implementation cycle**: 4 schema declarations + 1
lifecycle block + 2 calculation fields (`MileageEntry.totalAmount`,
`PerDiem.allowanceAmount`) + 1 aggregation (expense report by category)
+ 3 manifest entries. No PHP service classes (subject to
ADR-031 exception: at most one single-method photo-validation
guard).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Expense claim lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Expense approval routing | Consumed from OR approval-workflow | ADR-022 |
| Multi-currency conversion | Lookup + store in base currency at capture | Rate is external; conversion is deterministic; stored result is auditable |
| Mileage rate calculation | Declarative (`x-openregister-calculations` on `MileageEntry.totalAmount`) | Pure lookup + multiply; no state |
| Per-diem rate lookup | Declarative (`x-openregister-calculations` on `PerDiem.allowanceAmount`) | Pure lookup + multiply; no state |
| Photo file validation | Declarative if engine supports file-type checks; else single-method PHP guard per ADR-031 exception | File validation is low-complexity; acceptable as exception if needed |
| GL materialisation on post | Lifecycle action invoking T1's existing materialisation extension | No new service |

No service class authored in this envelope (subject to ADR-031
exception: at most one single-method photo-validation guard).

## Seed Data

Three master-data tables for rates:

**MileageRate** (per fiscal year, vehicle type, tax jurisdiction):
```
| fiscalYear | country | vehicleType | ratePerKm | notes |
| 2026 | NL | car | 0.21 | IB 2025 rules |
| 2026 | NL | motorcycle | 0.16 | IB 2025 rules |
| 2026 | FI | car | 0.42 | Finnish tax rules |
```

**PerDiemRate** (per country, calendar year):
```
| calendarYear | country | dailyRate | currency | source |
| 2026 | NL | 125.00 | EUR | Ministry travel rules |
| 2026 | FI | 45.00 | EUR | Finnish govt tables |
| 2026 | US | 150.00 | EUR | US federal rates |
```

Operators maintain these per fiscal year; rates lock at claim submission
for audit immutability.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Multi-currency rate unavailable at capture time | Operator prompted to enter manual rate or use prior-day rate; system warns on deviation from official rate |
| Mileage distance manual entry is error-prone | T2 accepts manual; T3 adds optional address-to-address geocoding; both stored for audit |
| Per-diem rates change mid-year | Rates are locked per fiscal year on claim submission; mid-year changes require new claim |
| Photo file validation may not be expressible declaratively | If engine cannot express file-type + size checks, single-method PHP guard ships per ADR-031 exception |
| Claim grouping adds complexity to partial approval | Spec defines per-line approval thresholds; if a single receipt exceeds threshold but others don't, only the high-risk lines are escalated (per ADR-022 threshold routing) |

## Out-of-Scope Decisions (T3+)

- **OCR**: Receipt text extraction deferred to T3.
- **Bank integration**: Statement-matching deferred to T4.
- **Email forwarding**: Handled by docudesk (out of scope).
- **Tax deduction planning**: Advanced rules deferred.
- **Mobile app**: UI deferred to later phase.
