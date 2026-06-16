# Design — SEPA Direct Debit (Incasso)

## Context

SEPA Direct Debit is the backbone of recurring-revenue collection for
Dutch SMEs: associations, small SaaS, fitness studios, after-school
care, property management. The scheme is governed by the SDD Core
Rulebook (consumer debtors) and SDD B2B Rulebook (business debtors),
with technical exchange in ISO 20022 pain.008 (initiation), pain.002
(status), and camt.054 (reconciliation).

Per ADR-022, mandate workflow and collection orchestration come from
OR abstractions or single-method guards per ADR-031. Per ADR-031,
pain.002/camt.054 ingestion is event-driven, not a PHP
reconciliation job. The spec declares the five register schemas,
the mandate lifecycle with dormancy expiry, sequence-type derivation,
pre-notification blocking, submission-window enforcement, and audit-trail
archival.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire incasso surface as **declarative metadata** —
  schemas + lifecycle + sequence-type logic + validation guards —
  per ADR-031.
- Consume OR's lifecycle abstraction and scheduled-workflow —
  per ADR-022. Zero parallel mandate-management PHP service.
- Make the spec a **competent-bookkeeper readable contract** —
  Dutch SMB incasso flow recognisable end-to-end (mandate intake,
  sequence-type derivation, pre-notification, pain.008 batch,
  pain.002/camt.054 reconciliation, R-transaction handling, reposting,
  dormancy expiry, audit trail).
- Respect the SDD CORE + B2B rulebooks end-to-end: scheme rules
  (CORE for consumers, B2B for business), sequence-type rules
  (FRST/RCUR/OOFF/FNAL per mandate history), pre-notification
  (14-day default, down to contractual minimum), submission windows
  (D-5, D-2, D-1), signature/mandate-document archival, 7-year
  bewaarplicht, dormancy expiry at 36 months.

## Non-Goals

- No PHP mandate-management service, no `SepaCollectionService.php`.
- No Java pain.008 library wrapper.
- No UBL / Peppol outbound — incasso is payment only; e-invoicing
  is a separate spec.
- No multi-currency — SEPA is EUR-only; T5 multi-currency is
  separate.

## Decisions

### D1 — Mandate is the source of truth for incasso eligibility

A `SepaMandate` must exist and be `active` before any collection can
be scheduled. Mandate status is `pending` (awaiting signature),
`active` (ready for collections), `cancelled` (no more collections),
or `expired` (36-month dormancy rule). Once cancelled or expired,
no new `DirectDebitCollection` can reference the mandate.

### D2 — Sequence-type is derived from mandate history, not operator-specified

`DirectDebitCollection.sequenceType` (FRST / RCUR / OOFF / FNAL) is
calculated by the system, not operator input:
- First collection on a recurring mandate → FRST
- Subsequent collections after a successful one → RCUR
- Last collection before mandate expires or transitions → FNAL
- One-off mandates → OOFF (always; only one collection allowed)

This avoids operator error that causes bank-charged rejections.

### D3 — Pre-notification is legally mandated before pain.008 submission

A `PreNotification` must be created (14 days before collection by
default, configurable down to contractual minimum per SDD rulebook).
The collection MUST NOT be included in a pain.008 batch unless the
pre-notification has been:
- Sent (email, letter, SMS)
- OR marked as carried on the invoice line (vooraankondiging)

This prevents the "no pre-notification" refund right that the Payment
Services Directive PSD2 grants the debtor (unconditional 8-week
reversal).

### D4 — Submission windows enforce SDD Core and B2B timelines

- FRST / OOFF on SDD Core: batch submitted D-5 business days before
  `requestedCollectionDate`
- RCUR / FNAL on SDD Core: batch submitted D-2 business days before
- All sequence types on SDD B2B: batch submitted D-1 business day before

Failure to meet the window causes automatic bank rejection (reason
code AC01 "Invalid Debit Date") and charged fees.

### D5 — pain.008 generation validates against EBA + Dutch overlay

The system generates ISO 20022 pain.008.001.02 and MUST validate
against:
- EBA Clearing official XSD
- Equens (major Dutch processing hub) overlay
- Betaalvereniging Nederland Dutch overlay (if public; else source
  from Betaalvereniging directly)

Validation MUST complete before the batch is marked `generated` and
eligible for submission.

### D6 — pain.002 and camt.054 ingestion is event-driven

When the bank delivers pain.002 (acceptance/rejection) or camt.054
(debit confirmation / R-transaction), the bank-connector emits an
event that shillinq consumes. Events trigger lifecycle transitions on
`DirectDebitBatch` and `DirectDebitCollection` (e.g.,
`submitted → accepted_by_bank` / `rejected` / `succeeded`). No
polling PHP job; pure event semantics per ADR-031.

### D7 — R-transaction (reject/return/refund) is captured separately

ISO 20022 R-transaction records (reason codes like MS03 "no reason
given", AC04 "closed account", MD01 "no mandate", MD06 "consumer
refund request") are parsed from pain.002 and camt.054 and stored
in the `RTransaction` register with full reason code + text + bank
details for audit and reposting decision.

### D8 — Reposting is available for bank problems, not debtor refusals

Rejected collections with bank-problem reason codes (insufficient
funds, closed account, technical error) can be reposted one-click:
create a new `DirectDebitCollection` with the same amount, same
mandate, next valid collection date, and original invoice link
preserved via `repostedAsCollectionId`.

Rejected collections with debtor-refusal codes (no mandate, consumer
refund request, debtor's bank refuses debtor's instruction) MUST NOT
be reposted automatically; the bookkeeper must pursue the receivable
through other means (dunning workflow, manual bank transfer request,
write-off).

### D9 — Mandate cancellation blocks future collections

A bookkeeper may cancel a mandate (e.g., customer unsubscribed). The
mandate transitions to `cancelled` with `cancellationReason` recorded.
The system MUST refuse to schedule any new collection against a
cancelled mandate.

### D10 — Dormancy expiry (36 months) is automatic

Per the SDD rulebook, if a mandate has not been used (no successful
collection) for 36 months, it MUST automatically expire. A scheduled
job checks `lastUsedAt` daily and transitions `active → expired` if
the threshold is crossed. The bookkeeper is notified to re-collect
the mandate signature for continued billing.

### D11 — Audit trail and pain-file archival for 7-year bewaarplicht

Every pain.008, pain.002, and camt.054 file MUST be archived (not
deleted) for 7 years per Dutch tax law (VAT Act, VAT Records
Directive). The system MUST be able to export, on demand, a
per-mandate or per-invoice audit bundle containing the mandate
document, all collections, all pain files, all R-transactions, and
all journal entries.

Deletion of archived pain files MUST be an explicit bookkeeper action
with a retention-override warning.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Mandate lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `SepaMandate` (`pending → active → cancelled → expired`); no PHP service |
| Sequence-type derivation | OR `x-openregister-aggregations` (or single method per ADR-031) | Precondition on `DirectDebitCollection` creation: query mandate's previous collections, evaluate sequence-type rule |
| Pre-notification blocking | OR `x-openregister-lifecycle.requires` precondition | Precondition on batch generation: query whether all collections have pre-notification proof; block if any missing |
| Submission-window validation | OR calendar abstraction (or single-method guard per ADR-031) | Precondition on batch generation: query business-day distance from today to `requestedCollectionDate`; reject if insufficient |
| pain.008 generation + validation | T2 bank-connectors stack (already in place) | XSD validation contract consumed; marshalling delegated to connector |
| pain.002/camt.054 ingestion | OR event-emission (or bank-connector webhooks) | Consume events; trigger lifecycle transitions |
| R-transaction capture | No existing parallel (new register) | `RTransaction` schema stores parsed reason codes + text + bank metadata |
| Mandate document attachment | OR files-attached-to-object pattern | `SepaMandate.mandateDocument` stored per OR's attachment contract |
| Reposting eligibility | Single-method guard per ADR-031 exception | `RepostingEligibilityGuard` evaluates reason-code heuristic; optional if reason codes alone suffice |
| Dormancy expiry | OR `ScheduledWorkflow` (or job trigger per ADR-031) | Daily job (or OR scheduled-workflow) checks `lastUsedAt` date and transitions `active → expired` |
| Audit-trail archival | T2 audit-trail (automatic on lifecycle transitions) + file store | Automatic audit on all transitions; pain files stored in non-deletable archive; export endpoint provides 7-year bundle |

**Net new code in implementation cycle**: 5 schema declarations + 4
lifecycle blocks + 3–4 precondition blocks (sequence-type, pre-notification,
submission-window, optional reposting) + pain file archival + export
endpoint. At most 2–3 single-method guards per ADR-031 exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Mandate lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine (pending → active → cancelled → expired) |
| Sequence-type derivation | Declarative aggregation query or single-method guard (ADR-031) | Query mandate's collection history; pure SUM/COUNT logic |
| Pre-notification blocking | Declarative precondition (`x-openregister-lifecycle.requires`) | Query whether all collections have proof; block if missing |
| Submission-window validation | Declarative precondition or single-method guard (business-day calendar) | SUM business days between today and collection date; compare vs SDD window |
| pain.008 generation | Consumed from bank-connectors (marshalling) | Spec declares validation contract only; marshalling is connector responsibility |
| pain.002/camt.054 ingestion | Event-driven lifecycle transitions | No reconciliation job; pure event semantics |
| R-transaction capture | Declarative schema + event handler | Parse reason codes from pain.002/camt.054; write to register |
| Mandate cancellation → blocks future collections | Declarative precondition or single-method guard | Check mandate status = `cancelled` on collection creation; refuse if true |
| Reposting eligibility | Single-method heuristic guard per ADR-031 exception (optional) | Evaluate reason-code set; separate debtor-refusal from bank-problem |
| Dormancy expiry | Scheduled-workflow transition (OR) or single-method daily job | Check `lastUsedAt` date; transition if > 36 months |
| Audit-trail export | Declarative query + file assembly | Query mandate + collections + pain files + journal entries; assemble ZIP |

No full service class authored in this envelope (subject to ADR-031
exception: at most 2–3 single-method guards).

## Seed Data

None. Mandates are bookkeeper-authored on first use (or imported from
existing bank-portal exports in a separate migrate-legacy-mandates task,
out of scope here). No templates.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR ScheduledWorkflow not yet stable (dormancy expiry) | Spec shape-neutral; single-method daily job per ADR-031 exception if OR primitive unavailable; remove job when OR extension lands |
| Business-day calendar for submission-window enforcement | Use OR's calendar abstraction if available; else inline Dutch 2026 public holidays with documented update cadence; single-method `SubmissionWindowGuard` per ADR-031 |
| pain.008 marshalling complexity (XSD validation, Equens/Betaalvereniging overlays) | Leverage existing bank-connectors stack; spec declares validation contract only; marshallers are connector responsibility |
| Reposting heuristic (debtor refusal vs bank problem) may have edge cases | Spec documents the ISO 20022 reason-code mapping; single-method `RepostingEligibilityGuard` per ADR-031 if more-than-code-matching logic is needed |
| Mandate document storage (scanned signature, digital-signing evidence) bloats OR file store | OR's file-attachment pattern should support 500 KB blobs; confirm max-file-size contract; if insufficient, documents store in docudesk (separate integration task) |
| Betaalvereniging Nederland overlay XSD not public | Contact Betaalvereniging directly; XSD lives in the repo under `lib/Xsd/`; updates on Betaalvereniging announcement |
| pain.002/camt.054 ingestion (event vs webhook vs poll) contract with bank-connector | Resolved in `opsx-ff` discovery (openconnector integration spec); spec is abstraction-agnostic |
| EU Directive 2025 SEPA timeline changes | Keep EBA/Equens XSD pins in code; notify reviewer if rulebook version drifts during implementation cycle |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the five
   schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with 4 new menu entries + their
   pages (additive).
3. If OR's ScheduledWorkflow is not yet stable, `lib/Job/MandateDormancyExpiryJob.php`
   ships (single method, ~30 LOC, ADR-031 exception annotated).
4. pain.008/pain.002/camt.054 marshalling lives in the separate
   bank-connectors implementation cycle (no changes here).

Down-direction: registers are non-destructive — reverting removes
the manifest entries; mandates and collections remain queryable but
unreferenced.

## Open Questions

1. **OR ScheduledWorkflow stability** — resolved in `opsx-ff`
   discovery; ADR-031 exception job if needed.
2. **Business-day calendar** — OR abstraction or inline 2026
   holidays? Resolved during implementing cycle.
3. **pain.002/camt.054 event contract** — OR events or bank-connector
   webhooks? Resolved in openconnector integration spec during `opsx-ff`.
4. **Betaalvereniging overlay XSD source** — public in EBA/Equens
   repos, or direct contact required? Resolved during XSD validation
   audit in implementing cycle.
5. **Mandate document file size limit** — OR's file-attachment max
   size? If < 500 KB, document storage strategy (docudesk)
   documented as out-of-scope follow-up.
6. **Reposting decision heuristic** — reason-code matching alone
   sufficient, or more-complex heuristic needed? Resolved in
   discovery cycle.
