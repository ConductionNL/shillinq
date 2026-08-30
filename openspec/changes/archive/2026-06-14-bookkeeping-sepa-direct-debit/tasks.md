# Tasks — SEPA Direct Debit (Incasso)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the
> `bookkeeping-sepa-direct-debit` spec — they are recorded now so the
> spec-review gate, dependency planning, and tier-cascade impact are
> all visible at proposal time. No source files are edited by this change
> itself.

## Tasks

- [x] Task 1: Confirmed no prior `bookkeeping-sepa-direct-debit` spec, no SEPA schemas, and no `Sepa*`/`Collection*`/`Incasso*` PHP classes pre-existed (grep of `lib/`); no legacy incasso code found
- [x] Task 2: Spec `specs/bookkeeping-sepa-direct-debit/spec.md` authored (shipped with the change) — REQ-SDD-001..010 with RFC 2119 + GIVEN/WHEN/THEN scenarios
- [x] Task 3: `proposal.md` authored (shipped with the change)
- [x] Task 4: `design.md` authored with decisions D1–D11 (shipped with the change)
- [x] Task 5: `SepaMandate` schema declared in `lib/Settings/register.d/bookkeeping-sepa-direct-debit.json` (ADR-037 fragment, NOT the monolith) with all REQ-SDD-001 fields + preNotificationDays + reviewFlag
- [x] Task 6: `DirectDebitCollection` schema declared in the fragment with all REQ-SDD-002 fields; sequenceType is an enum and auto-derived (SequenceTypeGuard), never operator-input
- [x] Task 7: `DirectDebitBatch` schema declared in the fragment with all REQ-SDD-005 fields + isArchived retention flag
- [x] Task 8: `RTransaction` schema declared in the fragment with all REQ-SDD-007 fields
- [x] Task 9: `PreNotification` schema declared in the fragment with all REQ-SDD-003 fields
- [x] Task 10: `CustomerMaster.defaultMandateId` overlay declared in `lib/Settings/register.d/bookkeeping-sepa-direct-debit.json` (REQ-SDD-002). The shillinq `CustomerMaster` schema (owned by `add-shillinq-bookkeeping-compliance.json`) is the AR-core analogue of the company-wide `Counterparty` entity referenced in design D1; AR-core is now on `development` (`ARInvoice` + `CustomerMaster` shipped) so the field lands here rather than waiting for the not-yet-existing `Counterparty` schema. Nullable FK to `SepaMandate` with `x-openregister-relations.defaultMandate` declared.
- [x] Task 11: `ARInvoice.paymentMethod` / `directDebitMandateId` / `directDebitPreNotificationInvoiceId` overlay declared in `lib/Settings/register.d/bookkeeping-sepa-direct-debit.json` (REQ-SDD-002, REQ-SDD-003). `paymentMethod` is an enum (`bank_transfer | direct_debit | cash | card | other`, default `bank_transfer`); `directDebitMandateId` is a nullable FK to `SepaMandate`; `directDebitPreNotificationInvoiceId` is a nullable self-FK to `ARInvoice` covering the design D3 invoice-line pre-notification channel. `x-openregister-relations.directDebitMandate` and `directDebitPreNotificationInvoice` declared.
- [x] Task 12: `x-openregister-lifecycle` on `SepaMandate` (`pending → active → cancelled | expired | suspended`) with `MandateGuard::canActivate/canCancel/canExpire` (ADR-031 exception guards)
- [x] Task 13: `x-openregister-lifecycle` on `DirectDebitCollection` (`scheduled → submitted → accepted_by_bank → succeeded | rejected | refunded`, `rejected → scheduled` repost) with PreNotification + Reposting guards
- [x] Task 14: Sequence-type derivation implemented as `lib/Lifecycle/SequenceTypeGuard::deriveSequenceType` (FRST/RCUR/OOFF per mandate history) + `canScheduleCollection` (one-off second-collection refusal); sequenceType is an enum so manual input is constrained at the schema level (ADR-031 exception; OR aggregations DSL cannot yet express the history query)
- [x] Task 15: Pre-notification blocking implemented as `lib/Lifecycle/PreNotificationGuard::canIncludeInBatch` (proof + calendar-day lead) — referenced from the collection `submit` transition `requires`
- [x] Task 16: Submission-window validation implemented as `lib/Lifecycle/SubmissionWindowGuard::isWithinWindow` (D-5/D-2/D-1 business-day arithmetic with inline Dutch 2025-2027 holidays)
- [x] Task 17: pain.008 **validation gate partially implemented**: `lib/Validation/IbanValidator` (ISO 13616 mod-97) ships and the schema carries controlSum/collectionCount/pain008Xml. The XML **marshalling** (PmtInf blocking, CdtrSchmeId, XSD validation) is **DEFERRED** to the `bookkeeping-bank-connectors` stack per the proposal (consumes the contract, does not implement the marshaller).
- [x] Task 18: pain.002 ingestion **spec-side declared, runtime handler deferred**. The batch/collection lifecycle transitions it drives (`submit` → `submitted`, `submitted → accepted_by_bank | rejected`) are declared in `lib/Settings/register.d/bookkeeping-sepa-direct-debit.json`; pain.002 ingestion itself is event-driven (design D6) and consumes the `bookkeeping-bank-connectors` webhook/poll contract per `proposal.md` (lines 11-12, 168-169). The ingestion handler lands with bank-connectors; this change's in-scope deliverable (transition declarations) is complete.
- [x] Task 19: camt.054 reconciliation **spec-side declared, runtime handler deferred**. The `RTransaction` schema (REQ-SDD-007 fields, non-deletable per REQ-SDD-010) and collection `refund` transition are declared in `lib/Settings/register.d/bookkeeping-sepa-direct-debit.json`; camt.054 reconciliation consumes the `bookkeeping-bank-connectors` delivery contract AND the not-yet-merged Invoice/journal entities (mark-paid + journal reversal) per `proposal.md` (lines 11-12, 37). The handler lands with bank-connectors + AR-core; this change's in-scope deliverable (schema + transition) is complete.
- [x] Task 20: Reposting eligibility implemented as `lib/Lifecycle/RepostingEligibilityGuard::canRepost` (ISO 20022 reason-code heuristic: bank-problem repostable, debtor-refusal refused with `sdd.mandate.debtor_refusal`) — referenced from the collection `repost` transition. The create-new-collection wiring + UI button land with the collection write path (needs live OR object writes).
- [x] Task 21: Mandate cancellation blocking implemented: `MandateGuard::canCancel` records `cancellationReason`; `SequenceTypeGuard::canScheduleCollection` refuses collections against cancelled/expired/suspended/pending mandates (`sdd.mandate.cancelled` / `sdd.mandate.expired`)
- [x] Task 22: 36-month dormancy expiry implemented: `MandateGuard::canExpire` (date arithmetic, unit-tested) + `lib/Job/MandateDormancyExpiryJob` (daily TimedJob registered via `appinfo/info.xml` `<background-jobs>`). The bookkeeper notification (email + dashboard alert) is **DEFERRED** to the fleet notification engine.
- [x] Task 23: Audit-trail export implemented for the mandate dossier: `GET /api/v1/sepa-mandate/{mandateId}/audit-export` (`SepaAuditController` + `SepaAuditService`, IDOR-safe tenant scoping, `#[NoAdminRequired]`) → ZIP of mandate.json + collections.csv/json + r-transactions.json + pre-notifications.json + archived pain fragments. The **invoice** dossier endpoint and **journal-line** inclusion are **DEFERRED** (depend on the not-yet-merged Invoice/journal entities). **Unit-test coverage (W18):** `tests/Unit/Service/SepaAuditServiceTest.php` (7 tests) pins the dossier-assembly contract — empty-id short-circuit, missing mandate, cross-tenant IDOR refusal, canonical ZIP file set (mandate.json, collections.csv/json, r-transactions.json, pre-notifications.json), per-collection archived `pain.008` inclusion, filename slugging of unsafe chars, single-tenant accept-any path, and CSV escaping of commas/quotes.
- [x] Task 24: pain-file archival governance **partially implemented**: `DirectDebitBatch.isArchived` retention flag (default true) + RBAC denies `delete` on `RTransaction`. The explicit `delete-with-retention-override` endpoint is **DEFERRED** to a follow-up (requires OR retention-policy hooks / live object deletion).
- [x] Task 25: 4 manifest navigation entries (`SEPA Mandates`, `Direct Debit Collections`, `Batches`, `Rejections & Returns`) under a new `Incasso` group + their `type: index` / `type: detail` pages added to `src/manifest.json`; every menu route resolves to a page id (consistency check clean — the residual structural-lint enum warning is pre-existing and environment-only, the canonical schema is absent from the worktree)
- [x] Task 26: `openspec/architecture/adr-000-data-model.md` updated with `SepaMandate`, `DirectDebitCollection`, `DirectDebitBatch`, `RTransaction`, `PreNotification` entity sections noting FK relationships

## Verification

`openspec validate` must exit clean on the change folder. Bookkeeper-persona peer review (e.g. `/test-persona-janwillem` for SMB bookkeeper, or `/test-persona-annemarie` for VNG accountant) confirms the incasso flow matches Dutch SMB practice (mandate intake → collection sequence-type derivation → pre-notification dispatch → pain.008 batch generation → pain.002 acceptance → camt.054 settlement or R-transaction handling → reposting for bank problems → mandate cancellation / dormancy expiry → audit-trail export). Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (mandate workflow declarative or ADR-031-exception-annotated guard; pain.008/pain.002/camt.054 handled per bank-connector contract; no app-local collection-orchestration service; manifest carries navigation; audit trail non-deletable for 7 years). Legal/compliance reviewer confirms SDD Core + B2B rulebook compliance (sequence types, submission windows, pre-notification timeline, 36-month dormancy, reason codes, R-transaction handling per PSD2 Art. 76 refund rights). No source code changes outside `openspec/changes/bookkeeping-sepa-direct-debit/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for:

- **Unit tests** (PHPUnit): sequence-type derivation (FRST/RCUR/OOFF/FNAL per mandate history), pre-notification creation + blocking, submission-window calculation (business days, Dutch holidays), mandate cancellation + dormancy expiry, reposting eligibility (reason-code heuristic), pain.002/camt.054 parsing + status update, R-transaction creation + journal reversal
- **Integration tests**: mandate → collection → pre-notification → batch generation → pain.008 validation; pain.002 ingestion updating batch/collection states; camt.054 debit confirmation closing collection + journal posting; camt.054 return reversing journal
- **Playwright MCP browser tests**: manifest navigation (Mandates, Collections, Batches, Rejections pages); mandate detail (lifecycle transitions, cancel action); collection detail (repost action, reason-code display); batch generation workflow (pre-notification check, submission-window check, pain.008 XML display); audit-bundle export
- **Performance tests**: batch generation with 1000 collections (10 s target); camt.054 ingestion with 500 R-transactions (5 s target); audit-export with 24-month history (10 s target)
- `composer test` green at implementing PR's CI gate

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors:

- `docs/user-guide/bookkeeping/sepa-direct-debit.md` per ADR-030 journeydoc convention: mandate intake workflow, pre-notification dispatch, batch generation + submission, pain.002/camt.054 reconciliation, R-transaction handling, reposting, dormancy renewal, audit-export for tax/compliance review
- Screenshot gallery in `docs/images/sepa-*`: mandate detail, collection list, batch generation (pain.008 summary), rejection handling, audit-export result
- Reference table: SDD CORE submission windows, B2B windows, sequence-type rules, reason-code taxonomy (bank problems vs debtor refusals), R-transaction types, pre-notification channels
- Troubleshooting: "why was my batch rejected?", "how do I repost a failed collection?", "what does this reason code mean?", "how do I export for my accountant?"

## i18n (company-wide ADR-007)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for:

- Entity names: `SEPA Mandate`, `Direct Debit Collection`, `Batch`, `Rejection`, `Return`, `Refund`
- Status values: `pending`, `active`, `cancelled`, `expired`, `scheduled`, `submitted`, `accepted_by_bank`, `succeeded`, `rejected`, `refunded`
- Sequence types: `FRST` (first), `RCUR` (recurrent), `OOFF` (one-off), `FNAL` (final)
- Actions: `Cancel Mandate`, `Retry Collection`, `Generate Batch`, `Export Audit Trail`, `Delete with Retention Override`
- Notifications: `Pre-notification sent`, `Collection succeeded`, `Collection rejected (reason: AC04)`, `Mandate expired after 36 months inactivity`, `High refund rate — review mandate eligibility`
- Validation errors: `sdd.mandate.scheme.mismatch`, `sdd.mandate.reference.duplicate`, `sdd.mandate.oneoff.second_collection_refused`, `sdd.prenotification.too_short`, `sdd.submission.window.late`, `sdd.mandate.signed.after.collection`, `sdd.mandate.debtor_refusal`, `sdd.mandate.cancelled`, `sdd.mandate.expired`
- Reason codes (ISO 20022): `AC01` (invalid debit date), `AC04` (closed account), `AM04` (insufficient funds), `MS03` (no reason given), `MD01` (no mandate), `MD06` (consumer refund request), etc.
