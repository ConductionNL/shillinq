# Time & Expense Invoice Intake Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- time-expense-invoice-intake

## Purpose

Defines shillinq's authenticated, idempotent billing-intake endpoint that
accepts a batch of externally-approved time entries (and optionally expenses)
from another Conduction app — pipelinq — and materialises them into a single
draft `BillableInvoice` under the Time & Materials model. This is the
shillinq half of the pipelinq time-billing handoff (leaf-ownership: shillinq
owns invoicing, pipelinq emits approved time). It reuses the existing
`InvoiceGenerationService` machinery from `invoice-from-time-and-expense` and
adds only the ingress adapter + an idempotency ledger. See ADR-005 (auth),
ADR-031 (external-integration imperative exception), ADR-037 (register
fragments).

## ADDED Requirements

### Requirement: Authenticated billing-intake endpoint

The system MUST expose `POST /apps/shillinq/api/billing/time-intake` as an
authenticated endpoint requiring a non-guest Nextcloud session
(`#[NoAdminRequired]`, never `#[PublicPage]`). The administration (tenant)
scope MUST be resolved server-side from the session; a client-supplied
`administrationId` MUST be ignored.

#### Scenario: Anonymous request is rejected
- GIVEN no authenticated user in the session
- WHEN a client POSTs to `/apps/shillinq/api/billing/time-intake`
- THEN the endpoint MUST respond `401` with an error body
- AND no `TimeIntakeBatch`, `UrenRegistratie`, or invoice is created

#### Scenario: Administration is resolved server-side
- GIVEN an authenticated user whose active administration is `adm-1`
- AND a request body that also contains `"administrationId": "adm-999"`
- WHEN the batch is ingested
- THEN all created objects MUST be scoped to `adm-1`
- AND the client-supplied `adm-999` MUST be ignored

### Requirement: Draft a single T&M invoice from an approved batch

The system MUST validate the batch and create exactly one draft
`BillableInvoice` with `billingModel = t_and_m` and one
`BillableInvoiceLine` per accepted time entry, delegating invoice
construction (line aggregation, totals, rate snapshot) to the existing
`InvoiceGenerationService`. Each inbound entry MUST be materialised as a
`UrenRegistratie` row stamped with `externalId`, `sourceApp`, and
`sourceBatchId`. A `billingModel` other than `t_and_m` MUST be rejected `422`.

#### Scenario: New batch drafts one invoice
- GIVEN an authenticated user and a batch with `batchId` `B1`, two entries
  (120 min @ €150/hr, 90 min @ €100/hr), `billingModel` `t_and_m`
- WHEN the batch is ingested
- THEN one draft `BillableInvoice` MUST be created with `status = "draft"`
- AND two `BillableInvoiceLine` rows MUST exist, one per entry
- AND two `UrenRegistratie` rows MUST be created carrying `externalId`
  `pl-time-1001` / `pl-time-1002`, `sourceApp = "pipelinq"`, and
  `sourceBatchId = B1`
- AND the response MUST be
  `{invoiceId, invoiceNumber, status: "draft", lines: 2, duplicated: false}`

#### Scenario: Non-T&M billing model is rejected
- GIVEN a batch with `billingModel = "fixed_fee"`
- WHEN the batch is ingested
- THEN the endpoint MUST respond `422`
- AND no invoice, batch, or `UrenRegistratie` row is created

#### Scenario: Empty or malformed batch is rejected
- GIVEN a batch with an empty `entries` array or a missing `batchId`
- WHEN the batch is ingested
- THEN the endpoint MUST respond `400`
- AND no objects are created

### Requirement: Idempotent intake by batch id

The system MUST record each processed batch as a `TimeIntakeBatch` keyed on
`(administrationId, batchId)`. Re-POSTing the same `batchId` MUST return
`200` with the existing invoice reference and `duplicated: true`, creating no
duplicate invoice and no duplicate `UrenRegistratie` rows. Reusing a
`batchId` with a materially different payload MUST be rejected `409`.

#### Scenario: Replayed batch returns the existing invoice
- GIVEN batch `B1` was already ingested and produced invoice `bil-2026-0042`
- WHEN an identical request with `batchId` `B1` is POSTed again
- THEN the endpoint MUST respond `200` with `invoiceId = "bil-2026-0042"`
  and `duplicated: true`
- AND no second invoice and no additional `UrenRegistratie` rows are created

#### Scenario: Reused batch id with a different payload is rejected
- GIVEN batch `B1` was already ingested with two entries
- WHEN a request with `batchId` `B1` but a different set of entries is POSTed
- THEN the endpoint MUST respond `409`
- AND the stored invoice for `B1` is unchanged

### Requirement: Cross-batch duplicate-time protection

The system MUST prevent the same approved time from being billed twice under
different batch ids. An entry whose `externalId` already exists for the
administration (from a prior batch) MUST NOT create a new billable
`UrenRegistratie` row or a new invoice line; the batch MUST be rejected `422`
identifying the offending `externalId`. Invoice generation MUST additionally
run the existing `InvoiceDeduplicationService` so a `timeEntryId` cannot
appear in more than one draft/posted invoice.

#### Scenario: Re-sent entry under a new batch id is blocked
- GIVEN entry `externalId = "pl-time-1001"` was materialised by batch `B1`
- WHEN a new batch `B2` contains an entry with `externalId = "pl-time-1001"`
- THEN the endpoint MUST respond `422` naming `pl-time-1001`
- AND no invoice is created for `B2`

## Non-Functional Requirements

- **Performance:** synchronous drafting of a ≤200-entry batch MUST complete
  at p95 < 2s; idempotent replay MUST complete at p95 < 300ms.
- **Accessibility:** no new UI surface — draft invoices appear in the
  existing invoice list UI, whose accessibility is covered by
  `invoice-from-time-and-expense`.
- **Internationalization:** Dutch and English MUST be supported for any
  operator-facing error/notification strings (ADR-007); the machine contract
  fields are English-only identifiers.

## Acceptance Criteria

- [ ] `POST /apps/shillinq/api/billing/time-intake` is registered in
  `appinfo/routes.php` with an auth attribute (no `#[PublicPage]`).
- [ ] Anonymous request returns `401`; client `administrationId` is ignored.
- [ ] A valid T&M batch drafts exactly one `BillableInvoice` (`status=draft`)
  with one line per entry.
- [ ] Each entry materialises a `UrenRegistratie` row with `externalId`,
  `sourceApp`, `sourceBatchId`.
- [ ] Replaying the same `batchId` returns `duplicated: true` and no
  duplicate objects.
- [ ] Reused `batchId` with a different payload returns `409`.
- [ ] A cross-batch duplicate `externalId` returns `422`.
- [ ] Non-T&M `billingModel` returns `422`; empty/malformed batch returns `400`.
- [ ] Response matches `{invoiceId, invoiceNumber?, status:"draft", lines:n, duplicated:bool}`.
- [ ] `TimeIntakeBatch` schema and the three `UrenRegistratie` provenance
  fields ship as a `register.d` fragment (canonical register untouched).
- [ ] Seed data exists for `TimeIntakeBatch` and the provenance fields.

## Notes

- Cross-repo consumer: pipelinq change `time-billing-handoff-emit` codes
  against the frozen contract in `contract.md`. This change ships first;
  pipelinq's manifest capability moves from `blocked-on-prereq` to buildable
  once the route is live.
- Deferred questions (tracked in `design.md`): expense passthrough shape,
  `rateRef` failure mode, `organisationRef` identity resolution.
- ADR-031: the ingress adapter is a legitimate imperative exception
  (external-app integration); aggregation + invoice lifecycle stay in the
  existing declarative/service layer and are not re-implemented.
