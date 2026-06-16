---
sidebar_position: 4
title: Reassessment
description: Capture every IFRS 16 modification, remeasurement, indexation, extension reassessment, impairment, and termination as an immutable LeaseReassessmentEvent.
---

# Reassessment events

Over the life of a lease, modifications and remeasurement events occur: the lessor adjusts payments, the lessee extends the term, an indexation clause triggers, an asset is impaired or returned. Each event is captured in a `LeaseReassessmentEvent` record with before/after snapshots, supporting evidence, balanced GL postings, and an approval route — and once posted to GL it is immutable.

## The four event entry points

`LeaseReassessmentService` exposes four entry points; all are administration-scoped (ADR-005), all return the persisted event payload, all stamp the `sourceLease` FK for ADR-022 audit:

### Indexation — `recordIndexationEvent`

Use when a CPI / fixed-percent / sector-index clause fires. Pass the new payment amount; the service builds the before/after snapshot, recomputes the post-event liability from the new payment stream, and applies a catch-up RoU adjustment (IFRS 16.42). The event-type is `indexation-remeasurement`.

### Extension-option reassessment — `recordExtensionOptionReassessment`

Use when the operator decides an extension option is now "reasonably certain" (or no longer). Pass the updated `extensionOptions` array. Schedule length changes, the post-event liability is recomputed, and the GL gets a catch-up adjustment for the new horizon. The event-type is `extension-option-reassessment`.

### Modification — `recordModification`

Use for scope / term / payment / IBR-reset modifications per IFRS 16.44. Pass the field overrides and (optionally) the remeasurement approach:

- `catch-up-adjustment` (default) — new IBR at modification date applies; liability + RoU adjusted in the period.
- `prospective` — future schedule absorbs the change; no GL line in the modification period.
- `separate-lease` — IFRS 16.44 separate-lease branch; the caller creates a new lease record.

The event-type is auto-resolved from the field-delta:

| Field delta | Event-type |
|---|---|
| Only `basePaymentAmount` | `payment-modification` |
| Any `nonCancellableTermMonths` change | `term-modification` |
| Only `ibrPercent` | `IBR-reset` |
| Anything else | `scope-modification` |

### Impairment — `recordImpairment`

Use when the RoU asset is impaired (damage, end-of-use). Pass the recoverable value; the service writes the RoU down to that value and emits a P&L loss line (Dr. `lease-modification-gain-loss`, Cr. `rou-asset`). Reversal (positive delta) is supported. The event-type is `impairment`.

## Status, threshold, and decidesk routing (REQ-LR-007)

Every event is created with one of two statuses:

- **`pending-approval`** — RoU adjustment magnitude exceeds the EUR 100,000 decidesk threshold. The caller fires a webhook to decidesk; GL posting is blocked until the board approves.
- **`approved`** — RoU adjustment below the threshold; auto-approved.

`approvalThresholdCents()` returns the current threshold (`10_000_000` = EUR 100,000 in cents).

## Reassessment-number sequence

Numbers are sequential per lease: `<leaseNumber>-reassess-001`, `…-002`, … The next number is derived from the count of prior events for the source lease (administration-scoped). Two-event race conditions are handled by OR's own id uniqueness.

## Immutability (REQ-LR-006)

Once `postedToGl` is set, the event is immutable. Corrections create a new event ("adjustment reassessment") that references the original via `correctionOf`.

## Examples

### Indexation (CPI +2.1 %)

```php
$service->recordIndexationEvent(
    leaseContractId:    'lease-v',
    administrationId:   'adm-1',
    newPaymentAmount:   1021.0,         // was 1000.0
    triggerDescription: 'CPI +2.1% YoY',
    approver:           'person-1',
);
```

### Extension reasonably certain

```php
$service->recordExtensionOptionReassessment(
    leaseContractId:        'lease-v',
    administrationId:       'adm-1',
    updatedExtensionOptions: [
        ['months' => 24, 'exerciseLikelihood' => 'reasonably-certain'],
    ],
    triggerDescription:     'Board decision: renew for 2 years',
    approver:               'person-2',
);
```

### Scope modification (extra floor on a building lease)

```php
$service->recordModification(
    leaseContractId:    'lease-r',
    administrationId:   'adm-1',
    newTerms:           [
        'nonCancellableTermMonths' => 96, // was 60
        'basePaymentAmount'        => 150_000.0, // was 100_000.0
        'ibrPercent'               => 5.5,
    ],
    approach:           'catch-up-adjustment',
    triggerDescription: 'Extra floor + extended term',
    approver:           'person-3',
);
```

### Impairment (damaged vehicle)

```php
$service->recordImpairment(
    leaseContractId:    'lease-v',
    administrationId:   'adm-1',
    recoverableValue:   5_000.0,
    triggerDescription: 'Vehicle damaged, recoverable per insurer',
    approver:           'person-4',
);
```
