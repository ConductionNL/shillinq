# Flag a delivery outside the date tolerance

## Why

A tolerance profile has `date_tolerance_days`, and REQ-TWM-001 computes a `date_delta` per line. Nothing acts on it. The match_status enum has no date status, so a delivery that arrives two weeks late still matches as `auto_approved`.

Ruben decided on 2026-11-02: a delivery date outside the tolerance gets its own status, `exception_date`.

## What changes

- **A new status.** The ThreeWayMatch match_status enum gets `exception_date`.
- **The date is checked per line.** The expected date is the order line's `expected_delivery_date`, or the order's when the line has none. The actual date is the `received_at` of the goods receipt the line matched.
- **Early and late both count.** The tolerance is plus or minus `date_tolerance_days`.
- **No tolerance means zero tolerance.** A profile without `date_tolerance_days`, or no profile at all, allows no day of difference. Price and quantity work the same way today.
- **No expected date, no check.** A line without an expected date, or matched to a service receipt, keeps its status.
- **A date breach ranks below price and quantity.** The order is missing_po, missing_grn, price, quantity, date, then a delta within tolerance.

## Why this order

A missing order or receipt leaves nothing to compare, so those come first. A price or quantity breach changes the amount you pay. A date breach does not: the goods arrived in the agreed amount at the agreed price, only on another day. It still needs a person, so it ranks above a delta within tolerance. `fraud_alert` is not set by the matching engine, so it stays out of this order.

## Capabilities

### Modified capabilities

- `bookkeeping-three-way-matching-engine`: adds REQ-TWM-003, the delivery date exception.

## Impact

- `lib/Service/ThreeWayMatchingEngine.php`: the date axis fails into `exception_date`, and accepted receipt lines carry their receipt's `receivedAt`.
- `lib/Service/ExceptionResolutionService.php`: `exception_date` is resolvable like the other exceptions.
- `lib/Settings/register.d/bookkeeping-purchase-order-3way-01-schemas-and-registers.json`: the enum value and its lifecycle state. The register needs a reimport on a running instance.
- `src/components/three-way-match/`: the filter and the status labels.
- The umbrella spec `bookkeeping-purchase-order-3way` lists the enum in its data table. A table has no delta form, so that row is updated when this change is archived.
