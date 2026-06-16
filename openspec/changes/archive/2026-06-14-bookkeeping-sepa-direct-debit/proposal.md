# Proposal: bookkeeping-sepa-direct-debit

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`SepaMandate`, `DirectDebitCollection`, `DirectDebitBatch`,
`RTransaction`, `PreNotification`) + extensions to `Counterparty` +
`Invoice` + mandate-driven workflow. No Java pain.008 library
invocation wrapper, no PHP collection-initiation service; mandate
registry, sequence-type logic, pre-notification guards, batch
generation validation, and pain.002/camt.054 ingestion all
declarative or gated by ADR-031 exception single-method guards. No
UBL outbound — pain files are internal to the bank-connector
integration per `bookkeeping-bank-connectors`.

## Summary

Introduce the **SEPA Direct Debit (incasso)** capability for Shillinq
as a T2 compliance + operations feature. This capability closes the
single largest practical gap versus incumbents (Exact, AFAS,
Moneybird) for Dutch SME recurring-revenue operators. The change
declares the `SepaMandate`, `DirectDebitCollection`, `DirectDebitBatch`,
`RTransaction`, and `PreNotification` registers; the mandate
lifecycle (`pending → active → cancelled → expired`); the
sequence-type derivation (FRST/RCUR/OOFF/FNAL per mandate history);
pre-notification validation (14-day default per rulebook, configurable);
submission-window enforcement (D-5 for FRST/OOFF CORE, D-2 for
RCUR/FNAL CORE, D-1 for B2B); ISO 20022 pain.008 generation with
EBA/Equens/Dutch-overlay validation; pain.002 and camt.054 ingestion
to close collections and emit R-transaction records; reposting logic
for rejected collections; mandate cancellation; and the 7-year audit
trail (bewaarplicht).

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure.

**Depends on:** [`bookkeeping-accounts-receivable-core`](../add-shillinq-accounts-receivable-core/proposal.md)
(Invoice + Counterparty entities), [`bookkeeping-bank-connectors`](../bookkeeping-bank-connectors/proposal.md)
(pain.008 submission, pain.002/camt.054 ingestion), [`bookkeeping-chart-of-accounts`](../add-shillinq-chart-of-accounts/proposal.md)
(bank account + receivable accounts for journal posting on success).

## Motivation

SEPA Direct Debit is the canonical payment scheme for Dutch SME
recurring revenue: associations (sports clubs, music schools), small
SaaS, fitness studios, after-school care, tuition, utilities, property
management rent collection, tax-advice retainers. The absence of
incasso functionality in shillinq forces a 4–6 hour/month manual loop
(Excel upload to bank portal, separate mandate spreadsheet, manual
pre-notification, reconciliation by eye when rejections arrive).
This feature closes that gap: automated mandate registry, sequence-type
derivation, 14-day pre-notification, pain.008 generation, rejection
reposting, dormancy expiry, and the 7-year audit trail for tax and
AVG/GDPR review.

Per ADR-022, mandate workflow and reposting logic come from OR
abstractions or ship as single-method guards per ADR-031. Per
ADR-031, pain.002/camt.054 ingestion is event-driven (not a
`PaymentStatusReconciliationJob` PHP class).

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-sepa-direct-debit`); declares 5 new registers
  (`SepaMandate`, `DirectDebitCollection`, `DirectDebitBatch`,
  `RTransaction`, `PreNotification`) with lifecycles; extends
  `Counterparty` and `Invoice`; adds manifest navigation entries
  (Mandates, Collections, Batches, Rejections/Returns).
- [ ] Project: openregister — no source changes; consumes
  `x-openregister-lifecycle`, scheduled-workflow (mandate dormancy
  expiry), event emissions (pain.002/camt.054 ingestion triggers).
- [ ] Project: openconnector — pain.008 submission connector and
  pain.002/camt.054 polling/ingestion connectors per bank-connectors
  spec (no changes in this proposal — integration contract already
  defined).

## Scope

### In Scope

- One new capability spec (`bookkeeping-sepa-direct-debit`) — see the
  `specs/` folder.
- The `SepaMandate` register with full SEPA Core and B2B rulebook
  compliance (scheme, type, status, signed-at, debtor IBAN/BIC, name,
  account-type, first/last collection dates, dormancy tracking,
  mandate document attachment, cancellation reason).
- The `DirectDebitCollection` register with sequence-type derivation
  (FRST/RCUR/OOFF/FNAL), submission-window validation, and full
  lifecycle (scheduled → submitted → accepted → succeeded / rejected /
  refunded).
- The `DirectDebitBatch` register aggregating collections into
  pain.008 with EBA/Equens/Dutch-overlay validation; pain.002 and
  camt.054 ingestion to update batch and collection status; archive
  of all pain files for 7-year bewaarplicht.
- The `RTransaction` register capturing bank-side R-transaction
  records (rejects, returns, refunds, reversals, revocations) per ISO
  20022 reason codes.
- The `PreNotification` register with 14-day default (configurable
  per mandate), multi-channel dispatch (email, letter, invoice line),
  and blocking logic preventing collection without pre-notification
  proof.
- Extensions to `Counterparty` (optional `defaultMandateId`) and
  `Invoice` (`paymentMethod` and `directDebitMandateId`).
- Mandate cancellation with "no further collection" enforcement; 36-month
  dormancy expiry (per rulebook) with automatic status transition.
- Reposting logic for rejected collections (bank-side problems,
  not debtor refusals): one-click retry creating a new collection
  linked via `repostedAsCollectionId`.
- Mandate document attachment (scanned signature or digital-signing
  evidence) stored per OR files-attached-to-object pattern.

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, bank-connector wrappers, pain.008/pain.002/camt.054
  XML marshalling are deliberately not in this proposal; tasks
  reference them but implementation lands via separate `opsx-apply`
  cycle.
- **Multi-currency** — pain.008 is EUR-only in SEPA; multi-currency
  translation is T5.
- **Peppol/e-invoicing** — incasso is payment mechanism, not invoice
  format; e-invoicing lands in a separate spec.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-sepa-direct-debit`** — declares the five new registers,
mandate lifecycle and dormancy expiry, sequence-type derivation,
pre-notification blocking, submission-window validation, pain.008
generation with schema validation, pain.002/camt.054 ingestion,
R-transaction capture, reposting logic, mandate cancellation, and
audit-trail / bewaarplicht archival.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-SDD-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions (`x-openregister-lifecycle`,
`ScheduledWorkflow`, `x-openregister-aggregations`), existing ISO 20022
XSD validation libraries (already in bookkeeping-bank-connectors stack).

## Impact

- `lib/Settings/shillinq_register.json` — adds 5 new schemas
  (`SepaMandate`, `DirectDebitCollection`, `DirectDebitBatch`,
  `RTransaction`, `PreNotification`); extends 2 existing schemas
  (`Counterparty`, `Invoice`); declares lifecycle on `SepaMandate`
  and `DirectDebitCollection`; declares scheduled-workflow for
  mandate dormancy expiry.
- `src/manifest.json` — adds 4 navigation entries + their
  `type: index` + `type: detail` pages (Mandates, Collections,
  Batches, Rejections).
- No new PHP services (subject to ADR-031 exception: at most
  single-method guards for mandate cancellation and reposting
  decision logic if OR abstractions are not yet stable).
- No new bespoke Vue components; index/detail pages are OR-native
  register-table + register-detail.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle`,
  `ScheduledWorkflow` (mandate dormancy expiry), event-emission
  primitives (pain.002/camt.054 ingestion triggers), aggregations
  (payment-matched collection count per batch).
- **T2 bank-connectors** — depends on
  `bookkeeping-bank-connectors` for pain.008 submission endpoint,
  pain.002/camt.054 polling/ingestion delivery contract.
- **T2 accounts-receivable-core** — depends on `bookkeeping-accounts-receivable-core`
  for Invoice and Counterparty entities, successful-collection
  closure linking.
- **T1 chart-of-accounts** — depends on `bookkeeping-chart-of-accounts`
  for bank account + receivable accounts to post on collection success.

## Risks

### Risk 1: Mandate cancellation decision logic may require a PHP guard

**Severity**: Low
**Mitigation**: Mandate cancellation (transition `active → cancelled`,
refuse future collections) is a pure state guard; declarative if OR
can express "no further collections against a cancelled mandate" via
`x-openregister-lifecycle.requires` precondition; else ADR-031
single-method `MandateCancellationGuard`. Resolved in `opsx-ff`
discovery.

### Risk 2: Reposting rejection detection (debtor refusal vs bank problem) requires heuristic

**Severity**: Medium
**Mitigation**: ISO 20022 pain.002 reason codes separate debtor
refusals (MD01 "no mandate", MD06 "consumer refund request") from
bank problems (AM04 "insufficient funds", AC04 "closed account").
The spec gates reposting on reason-code heuristics; a single-method
`RepostingEligibilityGuard` per ADR-031 exception evaluates the
heuristic. Spec-level logic documented; guard is optional optimization.

### Risk 3: pain.008/pain.002/camt.054 XML marshalling complexity

**Severity**: Medium
**Mitigation**: ISO 20022 XML generation and ingestion requires
robust XSD validation. The spec declares the validation contract
(EBA Clearing + Equens + Betaalvereniging Nederland overlay) but
does NOT implement marshallers; marshalling lives in the separate
`bookkeeping-bank-connectors` stack (already in place). This spec
consumes the contract only.

### Risk 4: Submission-window enforcement requires business-day arithmetic

**Severity**: Low
**Mitigation**: Submission windows are D-5 business days (FRST/OOFF
CORE), D-2 business days (RCUR/FNAL CORE), D-1 business day (B2B).
Calculation requires Dutch holiday calendar. The spec declares the
window semantics; business-day arithmetic lives in OR's calendar
abstraction (if available) or a single-method `SubmissionWindowGuard`
per ADR-031.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — mandates and collections remain
queryable.

## Open Questions

1. **OR ScheduledWorkflow stability** — mandate dormancy expiry
   (36-month auto-expire) requires scheduled-job triggering. Does OR
   have a stable `ScheduledWorkflow` primitive? If not, ADR-031
   exception path applies (single-method `MandateDormancyGuard`).
2. **Event-emission for pain.002/camt.054 ingestion** — the spec
   assumes bank-connector emits events on pain.002/camt.054 arrival;
   does OR have stable event-emission? If not, bank-connector
   calls shillinq webhooks (contract in separate integration spec).
3. **Betaalvereniging Nederland overlay XSD** — the Dutch overlay
   may not be public in EBA/Equens repositories. Source?
4. **Business-day calendar** — OR has a holiday calendar abstraction?
   Or does shillinq maintain its own (or inline the 2026 Dutch
   public holidays)?
5. **Mandate document file size** — scanned signatures are often
   100–500 KB. OR's file-attachment pattern supports large blobs?
   Or should documents live in docudesk?
