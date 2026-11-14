# Tasks: three-way-match-delivery-date-exception

- [x] **T1**: ThreeWayMatch `matchStatus` gets `exception_date` in its enum and a lifecycle state (`lib/Settings/register.d/bookkeeping-purchase-order-3way-01-schemas-and-registers.json`)
- [x] **T2**: The matching engine sets `exception_date` when the delivery date is outside the tolerance, ranked after quantity and before a delta within tolerance
  - Accepted goods receipt lines carry their receipt's `receivedAt`
  - The expected date falls back from the order line to the order
  - PHPUnit: REQ-TWM-003-01 to REQ-TWM-003-06
- [x] **T3**: `ExceptionResolutionService` resolves `exception_date` like the other exceptions
- [x] **T4**: The three-way match list and exception panel show `exception_date` with a translated label
- [ ] **T5**: On archive, add `exception_date` to the match_status row of the `bookkeeping-purchase-order-3way` data table
