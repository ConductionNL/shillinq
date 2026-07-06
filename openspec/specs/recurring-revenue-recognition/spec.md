---
status: in-progress
---

# recurring-revenue-recognition Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- `order-revenue-recognition`
- `order-revenue-recognition-engine`

## Purpose

Defines the booking-term data model (`SalesOrder` + `SalesOrderLine`) and the
**recognized recurring revenue per period** metric (IFRS 15 / ASC 606 over-time recognition),
replacing the retired run-rate MRR approach. The order is the actual booking term; recognition
is prorated to the overlap of each recurring line's term with the reporting period, with line
`amount` normalized to a monthly rate by `frequentie`. One-off lines (implementation/setup) are
recognized separately and never counted as recurring revenue. An optional `contractId` string
references the legal agreement without modeling a Contract entity.

The schemas are fully declarative OpenRegister config (ADR-001, ADR-031). The recognition
computation is an ADR-031 exception service (`RevenueRecognitionService`) — the grammar cannot
express runtime-period-parameterized interval-overlap proration — delivered by the chained
`order-revenue-recognition-engine` change (kind: code, ADR-032). The pipelinq dashboard widget
consumes the recognition endpoint downstream.

## Requirements

The full requirement set is authored as a delta in
`openspec/changes/order-revenue-recognition/specs/recurring-revenue-recognition/spec.md` and is
folded into this file at archive time. Until then, refer to that change for the normative
requirements and scenarios:

- SalesOrder SHALL model the booking term as a first-class declarative schema.
- SalesOrderLine SHALL declare line nature (`RECURRING` | `ONE_OFF`) and recognition method
  (`OVER_TIME` | `POINT_IN_TIME`) declaratively, with term-inheritance from the order.
- Recognized recurring revenue for a period SHALL be the term-overlap-prorated, frequency-
  normalized sum of `RECURRING` lines, excluding one-off lines.
- The recognition computation SHALL be an ADR-031 exception service in the chained code change.
- The recognition arithmetic, one-off split, ARR view and the RBAC-guarded read endpoint
  (`GET /api/recognition/recurring-revenue`) SHALL be realized by the `order-revenue-recognition-engine`
  change (kind: code) — see its delta for the normative engine requirements and scenarios.

### Requirement: Recognized recurring revenue is computed from booking terms, not run-rate MRR

The capability SHALL model booking terms declaratively (`SalesOrder` +
`SalesOrderLine`) and SHALL report recognized recurring revenue per period as
the term-overlap-prorated, frequency-normalized sum of `RECURRING` lines,
excluding one-off lines — realized by the two in-flight changes listed above,
whose deltas carry the normative per-requirement scenarios and are folded in
here at archive time.

#### Scenario: Period recognition excludes one-off lines

- GIVEN a SalesOrder whose lines include `RECURRING` and `ONE_OFF` natures
- WHEN recognized recurring revenue is computed for a reporting period
- THEN only `RECURRING` lines contribute, prorated to the overlap of each line's term with the period and normalized to a monthly rate by `frequentie`
- @e2e exclude pure backend recognition arithmetic with no browser surface; normative scenarios live in the in-flight change deltas (order-revenue-recognition / order-revenue-recognition-engine) and their PHPUnit coverage
