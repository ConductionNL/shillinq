# Design: billing-inherited-defects

## Architecture Overview

```
portal pay ─> PortalPaymentSessionService.findOwnedPayableInvoice
                find(uuid) ─miss─> findAll(slug, customerId) ─> owner + state check
recurring  ─> buildArInvoicePayload (+ invoiceNumber, periodId, line glAccount)
quick draft ─> buildInvoicePayload (+ line glAccount)
                 └─> ARInvoice 0.16.0 declares recurringProfileId, billingPeriod,
                     customerReference, invoiceLines[].glAccount
leaf API ─> PaymentRequest 0.6.0 declares requestedBy
executeStage ─> templateId: caller > stage > voluntary letter > DunningTemplateRegistry
```

## Decisions

- D1. Declare the provenance fields rather than move the guard. The alternative
  guard, a deterministic invoice number, would make the number the identity of
  the period; declaring the two fields keeps REQ-RIN-004 as written and the
  number free to be renumbered on posting.
- D2. The fragment `register.d/billing-inherited-defects.json` holds the new
  properties. Fragments deep-merge in file-name order and a later scalar wins, so
  ARInvoice's version is bumped where the last version is set
  (`school-contributions.json`, 0.15.0 to 0.16.0) and the new fragment states the
  same 0.16.0. PaymentRequest's only other version (`ar-invoice-payment-links.json`,
  0.5.0) sorts before the new fragment, so its 0.6.0 there wins.
- D3. `DunningTemplateRegistry` is wired, not removed. The voluntary letter
  (D28, `VoluntaryReminderTemplate`) only covers a voluntary contribution; an
  ordinary stage still needs a default template id. The fallback sits in
  `executeStage()` through `DunningTemplateRegistry::resolve()`, after the voluntary
  preparation, because every route to a run
  (tick and HTTP) passes there.
- D4. The invoice number: `REC-<yyyymm>-<8>-<nn>` from the profile id and the
  count of earlier invoices of that profile and period (the guard's own query).
- D5. The lookup catches only `DoesNotExistException` from `find()`; any other
  failure stays a downstream error, as the findAll failure was.

## Declarative-vs-imperative decision

No new behaviour of the listed kinds: schema properties only, plus fixes to
existing imperative code (the lookup, the payload builders, the template pick).

## Nextcloud Integration

`IAppConfig` for the registry override (existing).

## Security Considerations

The uuid lookup reads with `_rbac: false` as before and checks ownership on the
record, so a foreign invoice still collapses to the same forbidden result (no
existence oracle, REQ-SPPI-003).

## File Structure

- `lib/Service/Payment/PortalPaymentSessionService.php`
- `lib/Service/RecurringInvoiceGenerator.php`
- `src/modals/invoiceQuickDraft.js`
- `lib/Service/DunningRunService.php`
- `lib/Settings/register.d/billing-inherited-defects.json` (new),
  `school-contributions.json` (version)
- tests: `PortalPaymentSessionServiceTest`, `RecurringInvoiceGeneratorTest`,
  `tests/Unit/Register/BillingPayloadDeclaredFieldsTest.php` (new),
  `PaymentRequestLeafProviderTest`, `DunningRunServiceTest`, `invoiceQuickDraft.spec.js`

## Seed Data

No new schema; the new properties are nullable. Existing ARInvoice seed rows stay
valid. No seed change.

## Risks / Trade-offs

- Invoices written before this change lost the dropped values; not back-filled.

## Migration Plan

See migration.md: additive properties, no migration class.
