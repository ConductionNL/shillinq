# Design: arinvoice-field-backfill-and-bt10

## Architecture Overview

```
upgrade ─> BackfillArInvoiceProvenance.run
             ├─ RecurringInvoiceProfile rows (by id)
             └─ each ARInvoice ─ ArInvoiceProvenance
                  ├─ DRAFT- number ─> getLogs(uuid) create entry ─> fillFromAudit
                  └─ otherwise ─> matchProfile (one fit) ─> fillFromProfile
                  save only when something was filled

e-invoice ─> ArInvoiceUblMapper: customerReference ─> cbc:BuyerReference (BT-10)

executeStage ─> pause guard ─> DunningLetterComposer.prepare (voluntary letter)
             ─> DunningLetterComposer.compose (record, template fallback)
             ─> DunningStageDispatcher ─> save
```

## Decisions

- D1. Match by the generator's fingerprint, not by line names. Invoices from before
  REQ-RIN-009 have no lines at all, so the amount, date and customer are the only
  shared evidence. Indexation that changed a price makes an old invoice not fit,
  which leaves it alone rather than attaching it to the wrong profile.
- D2. Ambiguity is refused, not resolved. Two profiles for one customer on the same
  day and amount cannot be told apart; the summary line counts them.
- D3. The derivation is its own class (`Repair/Support/ArInvoiceProvenance`), so the
  rules are tested without OpenRegister and the step stays an I/O loop.
- D4. The audit read is best-effort per draft: `getLogs()` failing is logged at
  info level and skips that draft, it does not fail the step.
- D5. BT-10 sits after the document currency, the UBL 2.1 element order; a blank
  reference writes no element, since an empty `cbc:BuyerReference` fails Schematron.
- D6. The composition moves, the orchestration stays. `DunningLetterComposer` takes
  the voluntary policy, the reminder letter and the template registry; the run count
  it needs is passed as a callable so it is only read for a voluntary contribution,
  as before. `DunningRunService` keeps `VoluntaryContributionPolicy` because
  `tickInvoice()` uses it. The stale Dutch parameter list on `executeStage()` is
  replaced by a pointer to `compose()`. The class drops from 1,299 to 1,259 lines.

## Declarative-vs-imperative decision

A repair step is imperative by nature (a one-time data fix); the UBL mapper and the
dunning service were already imperative. No new behaviour of a declarative kind.

## Nextcloud Integration

`IRepairStep` registered in `appinfo/info.xml` post-migration, after
`BackfillPaymentRequestCustomer`.

## Security Considerations

The step reads and writes with `_rbac: false` and `_multitenancy: false`, like every
repair step (no user in the repair context). It only fills declared fields on the
same record; nothing crosses an administration, since the administration is part
of the match.

## File Structure

- `lib/Repair/BackfillArInvoiceProvenance.php` (new)
- `lib/Repair/Support/ArInvoiceProvenance.php` (new)
- `lib/Service/Dunning/DunningLetterComposer.php` (new)
- `lib/Service/DunningRunService.php`
- `lib/Service/EInvoice/ArInvoiceUblMapper.php`
- `appinfo/info.xml`
- tests: `tests/Unit/Repair/BackfillArInvoiceProvenanceTest.php`,
  `tests/Unit/Service/Dunning/DunningLetterComposerTest.php`,
  `tests/Unit/Service/EInvoice/ArInvoiceUblMapperTest.php`,
  `tests/Unit/Service/Support/DuckObjectServiceAdapter.php` (forwards `getLogs()`)

## Seed Data

No schema change, no seed change.

## Risks / Trade-offs

See the proposal.

## Migration Plan

The repair step is the migration: idempotent, never overwriting, safe to rerun.
