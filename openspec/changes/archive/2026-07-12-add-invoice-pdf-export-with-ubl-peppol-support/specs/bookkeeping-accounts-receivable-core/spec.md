# bookkeeping-accounts-receivable-core Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- add-invoice-pdf-export-with-ubl-peppol-support

## Purpose

Delta to the AR-core capability adding the Peppol **delivery-status** dimension to
`ARInvoice`. This is additive and orthogonal to the existing invoice
`lifecycleState` (REQ-AR-004): delivery status tracks the transmission of the
e-invoice over Peppol, not the accounting/payment state of the invoice. It
realises the T4 attachment reserved by REQ-AR-009. Declared declaratively per
ADR-031.

## ADDED Requirements

@e2e exclude schema field + declarative sub-lifecycle declaration — not browser-testable (the UI action is covered by bookkeeping-einvoicing-ubl-peppol REQ-EINV-007).

### Requirement: REQ-AR-011 — The `ARInvoice` schema SHALL declare a Peppol delivery-status field and a declarative delivery sub-lifecycle

The `ARInvoice` schema MUST declare the following additive fields (all optional,
defaulting so existing invoices remain valid):

| Field | Type | Required | Description |
|---|---|---|---|
| `deliveryStatus` | enum | No | One of `not-sent`, `queued`, `sent`, `delivered`, `rejected`, `failed` — default `not-sent` |
| `transmissionId` | string | No | Peppol message/transmission id (URN) returned by the transmission port |
| `payloadFileUri` | string | No | Docudesk/Files FK URI of the stored UBL / PDF-A3 artefact |
| `deliveryDetail` | string | No | Last delivery-status `detail` message (rejection/failure reason) |

The delivery status transitions MUST be declared via
`x-openregister-lifecycle` (a delivery sub-lifecycle keyed on `deliveryStatus`,
distinct from the `lifecycleState` lifecycle) with the following transitions:

| From | To | Trigger |
|---|---|---|
| `not-sent` | `queued` | operator triggers Send e-invoice (after validation) |
| `queued` | `sent` | access point accepts (`nl.conduction.peppol.delivery.status` `status: sent`) |
| `sent` | `delivered` | recipient access point confirms (`status: delivered`) |
| `queued` | `rejected` | access point/recipient rejects (`status: rejected`) |
| `sent` | `rejected` | recipient rejects after acceptance (`status: rejected`) |
| `queued` | `failed` | transmission error (`status: failed`) |
| `rejected` | `queued` | operator re-sends after correction |
| `failed` | `queued` | operator re-sends |

The delivery sub-lifecycle MUST NOT alter the accounting `lifecycleState`
(REQ-AR-004): an invoice can be `issued`/`overdue`/`paid` independently of its
`deliveryStatus`.

#### Scenario: Schema validator accepts an ARInvoice with a delivery status

- GIVEN the `ARInvoice` schema after this delta
- WHEN an object with required AR fields plus `deliveryStatus: "queued"`,
  `transmissionId: "urn:uuid:00000000-0000-0000-0000-000000000000"` is validated
- THEN validation MUST pass

#### Scenario: Existing invoices remain valid without the new field

- GIVEN a pre-existing `ARInvoice` object with no `deliveryStatus`
- WHEN it is loaded and re-validated
- THEN validation MUST pass and `deliveryStatus` MUST be treated as `not-sent`

#### Scenario: Delivery sub-lifecycle is independent of accounting state

- GIVEN an `ARInvoice` in `lifecycleState: paid` and `deliveryStatus: sent`
- WHEN a delivery-status event advances `deliveryStatus` to `delivered`
- THEN `lifecycleState` MUST remain `paid` (unchanged)
