# bookkeeping-multi-currency Specification (delta)

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- ar-billing-completeness

@e2e exclude pure backend/ledger: realised-FX settlement posting is schema + service + balanced GL behaviour, exercised by PHPUnit — not browser-testable

## Purpose

Adds the settlement-time REALISED foreign-exchange leg to the existing
multi-currency capability. shillinq already issues AR invoices in a foreign
currency (`ARInvoice.currency`), carries a daily `FxRate` register
(REQ-MC-002), and — since `fx-period-end-revaluation` — posts the UNREALISED
period-end mark of open `FXPosition` balances (REQ-MC-006/007/008). What was
missing is the realised gain/loss that crystallises when a foreign-currency
receivable is actually collected at a payment-date rate different from the
invoice-date rate it was booked at. Before this change no code posted it, so a
EUR-functional administration invoicing in USD and collecting at a different
dollar rate silently mis-stated income.

## ADDED Requirements

### Requirement: REQ-MC-010: Settlement of a foreign-currency AR invoice MUST post the realised FX gain/loss as a balanced GL entry

When an `ARInvoice` whose `currency` differs from the administration's
`Administration.functionalCurrency` is settled, `OCA\Shillinq\Service\Treasury\
RealisedFxSettlementService` MUST compute the realised difference
`foreignAmount x (paymentRate - invoiceRate)` in functional-currency cents and,
when it is non-zero, post a self-balancing two-line `GLTransaction`: a realised
GAIN debits the AR-control clearing account and credits the realised-gain
account (default `8022`); a realised LOSS debits the realised-loss account
(default `8023`) and credits AR-control. In both directions `debit == credit ==
|difference|` and `isBalanced` is true. The invoice-date rate is the invoice's
booked `fxRate` when present, else the `FxRate` register at the invoice date;
the payment-date rate is the gateway-reported rate when present, else the
`FxRate` register at the settlement date. A parallel append-only
`RealisedFxPosting` audit record MUST be written. Resolution gaps (same
currency, missing rate, zero movement) post nothing and MUST NOT block or
un-settle the payment (fail-open). The realised gain/loss accounts are distinct
from the unrealised `8020`/`8021` pair so the two legs never conflate.

#### Scenario: Foreign-currency invoice collected at a stronger rate posts a realised gain
- **GIVEN** a USD invoice for 100000 booked at invoice-date rate 0.90 in a EUR-functional administration
- **WHEN** it settles at payment-date rate 0.93
- **THEN** a balanced `GLTransaction` is posted debiting AR-control `1130` €3000.00 and crediting realised-gain `8022` €3000.00 (debit == credit == 300000 cents), and a `RealisedFxPosting` with `direction: "gain"` and `realisedDeltaCents: 300000` is written

#### Scenario: Foreign-currency invoice collected at a weaker rate posts a realised loss
- **GIVEN** the same USD invoice booked at invoice-date rate 0.93
- **WHEN** it settles at payment-date rate 0.90
- **THEN** a balanced `GLTransaction` is posted debiting realised-loss `8023` €3000.00 and crediting AR-control `1130` €3000.00 (debit == credit == 300000 cents), and a `RealisedFxPosting` with `direction: "loss"` and `realisedDeltaCents: -300000` is written

#### Scenario: Missing rate or same-currency settlement posts nothing and never blocks the payment
- **GIVEN** a functional-currency (EUR) invoice, or a foreign-currency invoice for which neither a booked rate nor any `FxRate` snapshot resolves
- **WHEN** it settles
- **THEN** no `GLTransaction` and no `RealisedFxPosting` are written, the settlement still succeeds, and the reason (`same-currency` / `no-rate`) is reported

#### Scenario: RealisedFxPosting schema and seed data are declared
- **GIVEN** `lib/Settings/register.d/realised-fx-settlement.json`
- **WHEN** the fragment is inspected
- **THEN** a `RealisedFxPosting` schema is declared with `direction`, `realisedDeltaCents`, `invoiceRate`, `paymentRate`, `gainLossGLAccount` and `arControlGLAccount` properties, and the seed `objects` include one `gain` and one `loss` RealisedFxPosting
