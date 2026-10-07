# shillinq-invoice-quick-draft Specification

## ADDED Requirements

### Requirement: REQ-IQD-007: The quick draft SHALL write only declared fields, its reference and line account included

ARInvoice SHALL declare `customerReference` and a `glAccount` on each
`invoiceLines` item. The quick draft SHALL write the reference as
`customerReference` and the default GL account (or a line's own) as each line's
`glAccount`. Every payload key and line key SHALL be declared on ARInvoice.

#### Scenario: The reference and GL account survive the save

- GIVEN a draft with reference `PO-42` and GL account `8000`
- WHEN the payload is built
- THEN `customerReference` is `PO-42`, each line's `glAccount` is `8000`
- AND every key is declared on the effective ARInvoice register
- @e2e exclude payload builder; covered by `tests/vitest/invoiceQuickDraft.spec.js`
