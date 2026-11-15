# shillinq-invoice-quick-draft Specification

## ADDED Requirements

### Requirement: REQ-IQD-008: Quick drafts saved before ARInvoice 0.16.0 SHALL get their reference and line accounts from their audit trail

For an ARInvoice with a `DRAFT-` number the repair step of REQ-RIN-011 SHALL read
the invoice's create audit entry and fill a blank `customerReference` from it, and
each line without a `glAccount` from the account at the same position in the
entry's `invoiceLines`, or in the older `lines` shape. A value already there SHALL
NOT be written. An audit trail that cannot be read SHALL skip that draft only.

#### Scenario: A quick draft gets its reference and accounts from its create entry

- GIVEN a quick draft without a reference whose create audit entry holds reference `PO-12` and account 8020 on line 1
- WHEN the repair step runs
- THEN the draft carries reference `PO-12` and line 1 account 8020
- @e2e exclude repair step; covered by `BackfillArInvoiceProvenanceTest::testAQuickDraftGetsItsReferenceAndAccountsFromItsAudit`

#### Scenario: The older lines shape is read and a reference is kept

- GIVEN a quick draft with reference `KEEP` whose create entry holds `lines` with account 8030
- WHEN the repair step runs
- THEN the reference stays `KEEP` and line 1 gets account 8030
- @e2e exclude repair step; covered by `BackfillArInvoiceProvenanceTest::testTheOlderQuickDraftLinesShapeIsRead`

#### Scenario: An unreadable audit trail skips only that draft

- GIVEN a quick draft whose audit trail cannot be read and a generated invoice that fits its profile
- WHEN the repair step runs
- THEN only the generated invoice is saved and no warning is written
- @e2e exclude repair step; covered by `BackfillArInvoiceProvenanceTest::testAnUnreadableAuditSkipsOnlyThatDraft`
