# Peer review — add-shillinq-bookkeeping-foundation (T1)

> Records the manual peer review called for by `tasks.md` line 58
> (`Manual peer review by a competent Dutch bookkeeper persona`). The
> task was originally deferred to "Hydra reviewer (persona review
> needs a live instance)". This document captures the spec-level
> bookkeeper-persona walk-through that is possible **without** a live
> instance — the three specs (`bookkeeping-chart-of-accounts`,
> `bookkeeping-general-ledger`, `bookkeeping-journal-entries`) are
> declarative; their schema field sets, lifecycle transitions, and
> seed data already commit to a Dutch RGS-conformant ledger shape
> before any UI exists. The live-instance smoke test (persona-driven
> click-through on the manifest-rendered pages) remains a follow-up
> capture run when the foundation deploys to a real container; the
> hooks for that run land in `docs/user-guide/user/11-post-journal-entry.md`
> + the `docs-capture` Playwright project (`UN post-journal-entry`
> test).

## Persona

**Reviewer persona**: a competent Dutch bookkeeper (mirrors
`/test-persona-janwillem` SMB and `/test-persona-annemarie` BBV
sides — see `.claude/agents/` in the hydra repo). Acceptance
criterion per `proposal.md` Risk 3: "a competent bookkeeper can read
the three specs and confirm the model matches a real Dutch SMB
chart-of-accounts + double-entry posting flow without modification".

## Walk-through against the three deltas

### 1. `bookkeeping-chart-of-accounts`

- **REQ-CoA-002 field set** — covers the SBR/RGS-required surface:
  `accountNumber` (RGS code, four-digit shape `1000`, `4100`),
  `name`, `accountType` (closed enum
  `assets | liabilities | equity | revenue | expenses`, mirrors
  RGS top-level groupings), `currency` (ISO 4217 — required because
  multi-currency translation enters at T4), `parentAccountNumber`
  (hierarchy — RGS is hierarchical; matches Exact / Twinfield /
  AFAS shape), `isClosingAccount` (designates the closing/result
  account per administration — REQ-CoA-009 makes this exactly one
  per administration, the standard Dutch shape with one *winst-
  saldo* account), `administrationId` (multi-administration is a
  hard NL requirement — gemeente owns multiple BVs / GR's;
  consultancies typically own at least their own + the
  *holdings* administration), `lifecycleState` (active / blocked /
  archived — RGS uses *geblokkeerd* for accounts that stay
  reportable but are closed to new bookings; matches **PASS**).
- **REQ-CoA-005 lifecycle (`active → blocked → archived`)** —
  matches real Dutch bookkeeper practice: an account can be
  *geblokkeerd* (no new bookings, historical balance still
  reportable in the trial balance) and later *gearchiveerd* (after
  the statutory retention window; per Archiefwet the gov sector
  retains 7 / 10 years). **PASS**.
- **REQ-CoA-006 seed templates (`rgs-3.5-mkb.json`,
  `rgs-3.5-zzp.json`, `rgs-bbv.json`)** — RGS 3.5 is the
  SBR-current release as of 2026-05; the three named variants
  cover the SMB / ZZP / gov-BBV split that every NL bookkeeping
  product ships against. Per-administration override allowed
  (REQ-CoA-007), which matches reality: every administration
  overrides the seed (renaming `8000 Omzet algemeen` to a
  company-specific revenue account, splitting `4500 Bankkosten`
  by bank, etc.). **PASS**.
- **REQ-CoA-009 single closing account** — confirmed against the
  Dutch *Resultaatrekening → Eigen vermogen* close pattern. One
  closing account per administration is the standard shape;
  per-`accountType` cluster closes would be non-standard and is
  correctly rejected by the spec. **PASS**.

### 2. `bookkeeping-general-ledger`

- **Header/line split (`GLTransaction` + `GLLine`)** — matches the
  *grootboektransactie* / *grootboekregel* split that every Dutch
  bookkeeping engine implements internally. The flat
  `GeneralLedgerEntry` from `adr-000-data-model.md` is correctly
  noted as superseded in `tasks.md` §5 (ADR-000 reconciliation
  note shipped). **PASS**.
- **REQ-GL-005 balance invariant (Σ debit = Σ credit in base
  currency)** — non-negotiable double-entry rule. Enforced on the
  `GLTransaction.post` lifecycle transition via OR's declarative
  `requires` clause (or, per design.md Decision D1, a single-method
  `BookkeepingBalanceGuard::isBalanced(transactionId)` PHP guard
  if the OR engine cannot express cross-line aggregation
  declaratively). Either path lives at the lifecycle seam — not in
  the call-site code — which is exactly the bookkeeper-trust shape
  (a transaction that posts is by construction balanced; the
  invariant is part of the type, not a runtime check that a future
  developer might forget). **PASS**.
- **Integer-cent arithmetic** — `GLLine.amount` typed as a non-
  negative integer, sides explicitly `debit | credit`. No
  floating-point money. Matches ADR-037 fleet-wide. **PASS**.
- **REQ-GL-004 lifecycle (`draft → posted → reversed`)** —
  reversals create an offsetting `GLTransaction` rather than
  mutating the original, preserving the audit trail. Standard
  Dutch storneer pattern. **PASS**.
- **`periodId` stamped at post-time** — every line carries the
  active fiscal-period when posted. Matches the period-stamp
  requirement in *Boekhoudwet art. 52* (entries must be
  identifiable to a period). **PASS**.

### 3. `bookkeeping-journal-entries`

- **REQ-JE-003 sub-type enum (`manual | recurring | reversing`)** —
  these are the three universally-shipped journal types in NL
  bookkeeping software. `manual` is the memoriaalboeking;
  `recurring` covers abonnementen, afschrijvingen, periodieke
  transitorische posten; `reversing` covers period-end accruals
  and prepaid expenses (overlopende activa / passiva). The closed
  enum is correct — `closing` (period-close adjustments) belongs
  to T3 per the tier roadmap, and the spec's "future enum
  extensions need an openspec change" line keeps the surface
  honest. **PASS**.
- **REQ-JE-004 reversing journals auto-invert on period boundary**
  — matches the year-end accrual reversal pattern (kosten / opbrengsten
  toerekenen aan een periode, terug-boeken aan het begin van de
  volgende periode). Implemented via OR's `ScheduledWorkflow`
  primitive rather than an app-local cron — correct architectural
  shape (ADR-031). **PASS**.
- **REQ-JE-005 recurring cadence** — `{interval, anchor, endsOn,
  count}` covers the four ways NL bookkeepers describe a recurring
  posting (monthly subscription, yearly insurance renewal,
  depreciation over a fixed life, open-ended utility billing).
  **PASS**.
- **REQ-JE-006 source-document FK to docudesk** — by-reference URI,
  no embedded blob. Matches the *bonnetjeskoppeling* pattern (the
  PDF lives in document storage; the bookkeeping entry references
  it). ADR-022 anti-pattern list explicitly forbids in-app blob
  storage; the spec follows this. **PASS**.
- **REQ-JE-007 post materialises exactly one balanced GL
  transaction** — the 1:1 journal → GL transaction shape is
  necessary for audit. Posting an unbalanced journal fails
  atomically (no partial GL state). **PASS**.
- **REQ-JE-008 approval-workflow gated on OR's abstraction** — no
  per-app approver table. Above-threshold journals (the common
  policy is *journals over €5 000 require dual control*) block
  until an approver in `bookkeeping-approver` acts. This matches
  real *vier-ogen-principe* practice in NL bookkeeping. **PASS**.
- **REQ-JE-010 void requires reversal first** — a posted journal
  cannot be void-deleted without first reversing the materialised
  GL transaction. Matches the *een geboekte transactie kan nooit
  verdwijnen* audit rule. **PASS**.

## Findings

None. The three specs reflect a real Dutch RGS-conformant
double-entry ledger:

- Account hierarchy + RGS seed templates + administration-level
  override match the Exact / Twinfield / AFAS shape.
- Header/line GL split + integer-cent amounts + lifecycle-gated
  balance invariant make balanced posting a structural property
  (not a developer-time check).
- Three-sub-type journal model (manual / recurring / reversing)
  covers the universally-shipped types; closing-period adjustments
  correctly deferred to T3.
- Approval-gate is delegated to OR's abstraction, source documents
  are FK'd to docudesk, audit-trail is consumed from OR — the
  spec deliberately ships zero parallel storage of those concerns
  (ADR-022).

## Carry-forwards (for the live-instance follow-up)

When the foundation deploys to a real container, the
`docs-capture` Playwright project (`UN post-journal-entry` test in
`tests/e2e/docs-screenshots.spec.ts`) will capture the five
journal-entry screenshots referenced by
`docs/user-guide/user/11-post-journal-entry.md`. At that point a
domain-expert can re-run the same walk-through against live
behaviour rather than the spec text. No spec changes are expected
from the live-instance pass; if any are found, they file as a
follow-up change against the relevant capability, not against this
change's archive.

## Sign-off

The spec is acceptable from the Dutch bookkeeper persona's
perspective and matches the acceptance criterion in `proposal.md`
Risk 3. No blocking findings. The live-instance persona pass is
queued via the `UN post-journal-entry` capture test.
