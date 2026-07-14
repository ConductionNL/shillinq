# revive-gl-tax-capabilities Specification

**Status**: done
**Scope**: shillinq
**OpenSpec changes**:
- revive-gl-tax-capabilities (2026-07-14, archived)

## Purpose

Supplies the missing triggers for shillinq's GL/tax cluster of orphaned
write capabilities (hydra gate-52; shillinq#446, #417, #418, #424). Four
fully-implemented, fully-tested money-path methods —
`DisposalJournalEmitter::emit`,
`IntercompanyJournalService::reconcileVariance`,
`GRIRClearingService::reconcileGRIRSaldoForPeriod`,
`OssPaymentReconciliation::reconcileDistribution` — had zero production
callers, so the general ledger was silently wrong while the app and its
test suite stayed green. No posting arithmetic is added or changed; this
capability is the missing wiring, plus the three lifecycle/schema/DI
repairs without which that wiring could never fire (see `design.md` D1–D2).

## ADDED Requirements

### Requirement: REQ-GLTAX-001: A disposed FixedAsset MUST post a balanced disposal journal

When a `FixedAsset` transitions to `retired`, the system MUST materialise
exactly one balanced `GLTransaction` (+ its `GLLine` rows) that credits the
asset account for its gross acquisition cost, debits accumulated
depreciation for the depreciation actually posted for that asset, debits
the clearing account for the disposal proceeds, and posts the difference
(proceeds − book value) as a gain (credit) or a loss (debit). The system
MUST refuse to persist a journal whose debits do not equal its credits. An
asset with no acquisition cost MUST be skipped without error.

The `FixedAsset` `dispose` transition MUST be executable: the schema's
`x-openregister-lifecycle` MUST declare its `field`, its `states`, and an
explicit `from`/`to` on every transition.

#### Scenario: Disposing an asset at a loss posts a balanced journal

- **GIVEN** an active `FixedAsset` (`purchaseCost` 25000.00, asset account
  `0220`, accumulated-depreciation account `0225`) with a
  `DepreciationSchedule` recording `accumulatedDepreciation` 4600.00, and a
  disposal at `salvageProceeds` 18000.00
- **WHEN** the asset transitions `active → retired`
- **THEN** one `GLTransaction` MUST be persisted with four `GLLine` rows —
  credit `0220` 25000.00, debit `0225` 4600.00, debit the clearing account
  18000.00, debit the loss account 2400.00 (book value 20400.00 − proceeds
  18000.00)
- **AND** the sum of the debit lines MUST equal the sum of the credit lines
  (25000.00).

#### Scenario: Scrapping an asset with zero proceeds books the full residual as a loss

- **GIVEN** the same asset scrapped with `salvageProceeds` 0
- **WHEN** the asset transitions `active → retired`
- **THEN** the journal MUST debit the loss account for the full remaining
  book value (20400.00) and MUST remain balanced.

### Requirement: REQ-GLTAX-002: Linking an intercompany pair MUST reconcile the two sides

When an `IntercompanyJournalEntry` transitions to `gekoppeld`, the system
MUST locate the counter-side entry (same `intercompanyNumber`, the
administrations swapped). When no counter-side exists it MUST create one
from `IntercompanyJournalService::buildMirror()`. It MUST then compute the
variance between the two booked amounts via
`IntercompanyJournalService::reconcileVariance()` and persist it as
`varianceAmount` on both sides.

An `IntercompanyJournalEntry` MUST NOT be able to transition to
`eliminatie_geboekt` while the pair is out of balance; the guard MUST deny
the transition when no counter-side entry can be resolved (fail-closed).

#### Scenario: A diverging counter-side is flagged with its variance

- **GIVEN** a source `IntercompanyJournalEntry` for 1000.00 and a
  counter-side entry for 950.00, same `intercompanyNumber`
- **WHEN** the source entry transitions to `gekoppeld`
- **THEN** both entries MUST carry `varianceAmount` 50.00
- **AND** an `eliminate` transition on either side MUST be denied.

### Requirement: REQ-GLTAX-003: The GR/IR period-end saldo MUST be operator-reachable

The system MUST expose the GR/IR clearing-account period reconciliation
(`GRIRClearingService::reconcileGRIRSaldoForPeriod()`) on an authenticated
endpoint scoped to an administration the caller can access, returning the
debit total, the credit total, the saldo and whether the account is
balanced for the period. A request for an administration outside the
caller's scope MUST be masked as 404.

#### Scenario: An operator reads the GR/IR saldo for a period

- **GIVEN** an authenticated user with access to administration `adm-1` and
  a period `2026-Q2` holding one 18500.40 GR/IR clearing credit with no
  matching settlement debit
- **WHEN** the user requests `GET /api/gr-ir/saldo?administrationId=adm-1&periodId=2026-Q2`
- **THEN** the response MUST be 200 with `saldoCents: -1850040` and
  `balanced: false`.

### Requirement: REQ-GLTAX-004: A recorded OSS payment MUST be reconciled against its return

When an `OssPayment` is created, the system MUST compare the per-country
distribution confirmed by the Belastingdienst against the per-country
totals declared on the linked `OssReturn`
(`OssPaymentReconciliation::reconcileDistribution()`). A fully matching
distribution MUST drive the payment to `reconciled`; any divergence MUST
drive it to `discrepancy` and persist the per-country differences on the
payment.

The `OssReturn.pay` and `OssPayment.reconcile` transitions MUST resolve
their declared `requires` guard: the literal
`OCA\Shillinq\Service\OssPaymentReconciliation::canMarkPaid` tag MUST be
registered so the transition is enforced instead of failing with HTTP 500.

#### Scenario: A diverging distribution flags the payment

- **GIVEN** an `OssReturn` declaring DE 1802.00 / FR 1440.00 and an
  `OssPayment` whose confirmed distribution is DE 1802.00 / FR 1400.00
- **WHEN** the payment record is created
- **THEN** the payment MUST end in `discrepancy` carrying a per-country
  difference of −40.00 for FR.

#### Scenario: A matching distribution reconciles the payment

- **GIVEN** the same return and a confirmed distribution of DE 1802.00 /
  FR 1440.00 on a payment carrying a linked `bankTransactionId` for the
  return total
- **WHEN** the payment record is created
- **THEN** the payment MUST end in `reconciled` with no differences.
