---
kind: code
depends_on: []
---

# Proposal: revive-gl-tax-capabilities

## Summary

Hydra gate-52 (`orphaned-write-capability`) flagged 28 zero-caller
side-effecting methods in shillinq; the 2026-07-14 triage
(`scratchpad/orphan-triage-2026-07-14.md`) named a **GL / tax cluster** as
the highest-blast-radius group: `DisposalJournalEmitter::emit`,
`IntercompanyJournalService::reconcileVariance`,
`GRIRClearingService::reconcileGRIRSaldoForPeriod` and
`OssPaymentReconciliation::reconcileDistribution` (shillinq#446, and the
already-filed #417 / #418 / #424). Each is fully implemented, its spec says
`done`, its unit tests pass — and **nothing invokes it**, so the general
ledger is silently wrong: an asset disposal never reaches the GL, an
intercompany pair is never reconciled, the GR/IR period-end control is
unreachable, and an OSS-VAT distribution discrepancy is never detected.

This change verifies each method against HEAD (Step 1 of the brief — see
`design.md` for the per-method verdict table with caller evidence), then
wires each of the four to its real trigger. Verification surfaced **three
further defects that made the "obvious" wiring a no-op**, all fixed here:

1. **The `FixedAsset` `dispose` transition cannot execute at all.** Its
   `x-openregister-lifecycle` declares no `field`, no `states`, and the
   `dispose` transition has neither `from` nor `to` — OpenRegister's
   `TransitionEngine::transition()` therefore throws
   *"Transition dispose is not allowed from current state ''"* on the first
   attempt. Wiring the emitter to a transition that can never fire would
   have shipped a second dead capability.
2. **The `OssPaymentReconciliation::canMarkPaid` guard tag is
   unregistered.** `OssReturn.pay` and `OssPayment.reconcile` both declare
   `"requires": "OCA\\Shillinq\\Service\\OssPaymentReconciliation::canMarkPaid"`,
   but no DI service is registered under that literal tag, so
   `LifecycleGuardRegistry::resolve()` throws and **both transitions
   hard-fail with HTTP 500** (the shillinq#425/#433 defect class). The
   reconciliation trigger was behind a transition nobody could take.
3. **`GLLine.subLedgerType` has no `fixed-asset` member.** The emitter tags
   every disposal line `subLedgerType: "fixed-asset"`; the enum is
   `[ap, ar, project, none, inventory]`, so a disposal line would have been
   rejected by schema validation even once the journal was emitted.

## Motivation

A dead posting method and a wrong ledger are the same thing to an
accountant. The disposal journal is the entry that zeroes an asset's
carrying amount: without it the asset account and the accumulated-
depreciation account both keep a balance for an asset that no longer
exists, and the boekwinst/boekverlies never hits the P&L. The GR/IR saldo
is the control that proves goods received were invoiced. The OSS
per-country distribution check is the only thing that catches the
Belastingdienst distributing a consolidated EU-VAT payment differently from
what was declared. All three are money-path controls that the app currently
claims to have.

## Scope

In scope (the GL/tax cluster of the triage table):

- `DisposalJournalEmitter::emit` → wire to the `FixedAsset` disposal
  transition (and repair the transition so it can fire).
- `IntercompanyJournalService::reconcileVariance` (plus `buildMirror`,
  `isBalanced` — same class, same dead cluster) → wire to the
  `IntercompanyJournalEntry` `link` transition + an `eliminate` guard.
- `GRIRClearingService::reconcileGRIRSaldoForPeriod` → give it the
  operator-reachable endpoint shillinq#424 asks for.
- `OssPaymentReconciliation::reconcileDistribution` (plus `canMarkPaid`,
  whose guard tag is broken) → wire to `OssPayment` creation.

Out of scope (separate follow-ups on #446): the IFRS-16 lease cluster,
`WidgetService::createAppointment`, the disclosure/EMU/ACM exports, the
approval/activity emitters, and the four dead `SettingsService::seed*`
duplicates.

## Non-goals

- No new posting arithmetic. Every debit/credit rule already exists in the
  four services; this change supplies the missing triggers and the schema /
  DI repairs those triggers need in order to actually run.
- No automatic `OssReturn → paid` transition. REQ-OSS-008 says the
  reconciliation *permits* the transition; the operator still takes it (the
  guard now resolves, so they can).
