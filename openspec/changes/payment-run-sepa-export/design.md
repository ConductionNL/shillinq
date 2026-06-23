## Context

`consolidate-accounts-payable` (the dependency of this change) introduces the
`PaymentRun` schema — a batch of approved `Payee` payments with the declarative
lifecycle `draft → approved → exported → reconciled` and a `paymentLines[]`
array (`payeeId`, `payeeName`, `creditorIban`, `amount`, `remittanceInfo`,
`apTransactionRef`) plus `debtorAccountIban`, `executionDate`, `currency`,
`totalAmount`, `exportedFileRef`, `exportedAt`. It deliberately stops at the
data model.

This change implements the export of an approved `PaymentRun` into a bank
file, and the reconciliation of an exported run against an imported CAMT.053
bank statement (closing the lifecycle loop). The canonical in-app pattern for
"render bytes → store tagged file in Files → record metadata" already exists:
`lib/Reporting/ReportGenerationService.php` auto-discovers generators
(`lib/Reporting/Generator/*.php`), renders via native `XMLWriter`/`fputcsv`
(office rendering via the PHPOffice stack bundled by OR — no shillinq composer
addition), writes under `/Shillinq/Reports/<administrationId>/`, applies system
tags, and records a `GeneratedReport` object. We mirror that exactly for
payment-run exports.

## Goals / Non-Goals

**Goals:**

- Generate a **pain.001.001.03** SEPA Credit Transfer XML from an approved
  `PaymentRun`, one `CdtTrfTxInf` per payment line.
- Generate a **CSV fallback** for the same run.
- Store both under `/Shillinq/PaymentRuns/<administrationId>/`, tagged.
- Write back `exportedFileRef` + `exportedAt`; drive `approved → exported`.
- Trigger from the `PaymentRun` detail page via an ADR-016 route.
- **Reconcile** an exported run against an imported **CAMT.053** bank
  statement: parse the statement, match booked credit-transfer entries to the
  run's `paymentLines[]` (EndToEndId primary; amount + creditor IBAN
  fallback), set `reconciledAt`, and drive `exported → reconciled` on a full
  match; leave partial/unmatched lines `exported` with a mismatch note.
- Trigger reconciliation from the `PaymentRun` detail page via an ADR-016 route.
- Add **no new composer dependency**.

**Non-Goals:**

- Bank submission / PSD2 / direct bank API push (operator downloads the file).
- iDEAL links (the legacy `PaymentRun` had these; out of scope here).
- Multi-currency (EUR only; matches AP-core T2 scope).
- Re-defining the `PaymentRun` schema or lifecycle (owned by the dependency).
- Reconciling against non-CAMT.053 statement formats (MT940, CSV statements) —
  CAMT.053 (ISO 20022) only here.
- Auto-posting reconciled payments to the GL (a later concern).

## Decisions

### D1 — Reuse the ReportGenerationService pattern, do not invent a new one

A `PaymentRunExportService` orchestrates: discover generator(s) → render bytes
→ store tagged file via `IRootFolder` → record/return the file ref. The two
generators (`SepaPain001Generator`, `PaymentRunCsvGenerator`) implement a small
interface and are auto-discovered by glob, exactly like
`lib/Reporting/Generator/*`. *Alternative considered:* fold rendering into the
controller — rejected; it duplicates storage/tagging and bloats the controller.

### D2 — pain.001.001.03 specifically (not .09 / .08)

`.001.001.03` is the SEPA Credit Transfer rulebook version still accepted by
NL banks for batch upload and is the version the legacy schema referenced. The
generator emits the canonical structure (see REQ-SEPA-002). All placeholder
values (IBAN `NL00BANK0123456789`, BIC `<BANKNL2A>`, `MsgId`
`MSGID-PLACEHOLDER`) are SAFE — never realistic secrets.

### D3 — Keep the CSV fallback

We ship both XML and CSV. *Alternative considered:* pain.001-only — rejected;
the CSV fallback is cheap (one `fputcsv` generator) and useful for operators /
banks that ingest CSV. **(DEFERRED_QUESTION — keep vs drop CSV.)**

### D4 — Trigger via Vue button + controller endpoint (provisional)

A "Export to bank" button on the `PaymentRun` detail page calls a controller
endpoint registered in `appinfo/routes.php` (ADR-016), auth-guarded per
ADR-005. *Alternative considered:* an OR lifecycle action that runs the export
as a side effect of the `export` transition (no shillinq route). The button +
controller is the lightest path consistent with ADR-016/ADR-004 and keeps the
export observable/retriable; the OR-action route would couple file generation
into the lifecycle engine. **(DEFERRED_QUESTION — Vue+controller vs OR action.)**

### D5 — Lifecycle transition via OR engine, not a PHP state machine

The export service performs the `approved → exported` transition through
OpenRegister's lifecycle engine (the transition is declared by the dependency
change). The service sets `exportedFileRef`/`exportedAt` and requests the
transition; it does not hand-roll a state machine (ADR-031).

### D6 — Reconcile an exported run against an imported CAMT.053 statement

The reconciliation flow closes the lifecycle: an operator imports a **CAMT.053**
(ISO 20022 bank-to-customer account statement) file for the run's debtor
account. A `Camt053StatementParser` parses the statement's booked entries
(`<Ntry>` with `<CdtDbtInd>DBIT` for outgoing transfers, each carrying
`<EndToEndId>`, amount, and creditor IBAN under `<TxDtls>`). A
`PaymentRunReconciliationService` matches each parsed entry back to the run's
`paymentLines[]`:

- **Primary match: `EndToEndId`.** Each exported line carries a deterministic
  `EndToEndId` (`<runNumber>-<lineIndex>`, e.g. `PR-2026-001-1`) emitted in the
  pain.001 export; the statement echoes it. An exact `EndToEndId` match is
  authoritative.
- **Fallback match: amount + creditor IBAN.** When a statement entry lacks (or
  truncates) the `EndToEndId`, match on `(amount, creditorIban)` against an
  as-yet-unmatched line.

On a **full** match (every line matched), the service sets `reconciledAt` and
requests the declarative `exported → reconciled` transition through OR's
lifecycle engine. On a **partial/unmatched** result, the run STAYS `exported`,
and the service records a mismatch note (which lines/entries did not match) on
the run — it does NOT force the transition.

*Alternative considered:* match on amount alone — rejected; two lines can share
an amount, so IBAN (and primarily `EndToEndId`) is needed to disambiguate.

ADR-031: **CAMT.053 parsing is imperative document/file ingestion** — the
mirror image of the pain.001 export generator and justified the same way
(ADR-031 §"Document/file generation/ingestion with app-specific formats" — the
schema engine has no opinion on parsing an ISO 20022 statement XML). The parser
+ match service use native `XMLReader`/`SimpleXML`; **no new composer
dependency**. The `exported → reconciled` transition itself stays
**declarative** (OR lifecycle, owned by the dependency change); the service
only *requests* it — it does not hand-roll a state machine. All CAMT.053
fixture values (IBAN `NL00BANK0123456789`, BIC `<BANKNL2A>`, `EndToEndId`
`PR-2026-001-1`) are SAFE placeholders.

## Declarative-vs-imperative decision (ADR-031)

**This change is a justified imperative exception.** Per ADR-031 §"What apps
SHOULD still write in PHP" → *Document/PDF/document generation with
app-specific templates* (and the symmetric file-*ingestion* case), rendering a
SEPA pain.001 XML / CSV and parsing a CAMT.053 statement are exactly the
document generation + file-ingestion surfaces the schema engine has no opinion
on — identical in character to `ReportGenerationService` (the canonical in-app
precedent). The imperative pieces are scoped narrowly:

| Piece | Placement | Justification |
|---|---|---|
| pain.001 XML rendering | **Imperative** `SepaPain001Generator` | Document generation (ADR-031 explicit exception); native `XMLWriter`, no new dep. |
| CSV rendering | **Imperative** `PaymentRunCsvGenerator` | Document generation; `fputcsv`. |
| Store + tag file | **Imperative** `PaymentRunExportService` | Files/tag side effects; mirrors `ReportGenerationService`. |
| `approved → exported` transition | **Declarative** (OR lifecycle, owned by dependency) | The export service *requests* the declared transition; it does NOT implement a state machine. |
| `exportedFileRef` / `exportedAt` write-back | data write via OR ObjectService | A plain field set on the object, not lifecycle/aggregation logic. |
| CAMT.053 statement parsing | **Imperative** `Camt053StatementParser` | Document/file ingestion (ADR-031 explicit exception); native `XMLReader`/`SimpleXML`, no new dep. Mirror image of the pain.001 export generator. |
| Reconciliation matching (EndToEndId / amount+IBAN) | **Imperative** `PaymentRunReconciliationService` | Format-specific matching of parsed statement entries to lines — file-ingestion glue, not lifecycle/aggregation/calculation logic. |
| `exported → reconciled` transition | **Declarative** (OR lifecycle, owned by dependency) | The reconciliation service *requests* the declared transition on a full match; it does NOT implement a state machine. |
| `reconciledAt` write-back + mismatch note | data write via OR ObjectService | Plain field sets on the object, not lifecycle/aggregation logic. |

No new lifecycle/aggregation/calculation/notification service is introduced —
only document generation + file ingestion + their storage/match glue, which
ADR-031 explicitly permits.

## Seed Data (ADR-001)

This change adds behaviour, not schemas, so it relies on the seed objects
introduced by `consolidate-accounts-payable` (the consultancy/travel-agency/
municipality payees + the approved `PR-2026-001`). For export testing it
exercises that approved run; the expected generated pain.001 (SAFE
placeholders) is:

```xml
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03">
  <CstmrCdtTrfInitn>
    <GrpHdr>
      <MsgId>MSGID-PLACEHOLDER</MsgId>
      <CreDtTm>2026-06-30T10:00:00</CreDtTm>
      <NbOfTxs>2</NbOfTxs>
      <CtrlSum>1497.50</CtrlSum>
      <InitgPty><Nm>Consultancy Demo B.V.</Nm></InitgPty>
    </GrpHdr>
    <PmtInf>
      <PmtInfId>PR-2026-001</PmtInfId>
      <PmtMtd>TRF</PmtMtd>
      <BtchBookg>true</BtchBookg>
      <ReqdExctnDt>2026-07-01</ReqdExctnDt>
      <Dbtr><Nm>Consultancy Demo B.V.</Nm></Dbtr>
      <DbtrAcct><Id><IBAN>NL00BANK9999999999</IBAN></Id></DbtrAcct>
      <DbtrAgt><FinInstnId><BIC>BANKNL2A</BIC></FinInstnId></DbtrAgt>
      <CdtTrfTxInf>
        <PmtId><EndToEndId>PR-2026-001-1</EndToEndId></PmtId>
        <Amt><InstdAmt Ccy="EUR">892.50</InstdAmt></Amt>
        <CdtrAgt><FinInstnId><BIC>BANKNL2A</BIC></FinInstnId></CdtrAgt>
        <Cdtr><Nm>Eneco Energie B.V.</Nm></Cdtr>
        <CdtrAcct><Id><IBAN>NL00BANK0123456789</IBAN></Id></CdtrAcct>
        <RmtInf><Ustrd>ENECO-2026-04-0001</Ustrd></RmtInf>
      </CdtTrfTxInf>
      <CdtTrfTxInf>
        <PmtId><EndToEndId>PR-2026-001-2</EndToEndId></PmtId>
        <Amt><InstdAmt Ccy="EUR">605.00</InstdAmt></Amt>
        <CdtrAgt><FinInstnId><BIC>TESTNL2A</BIC></FinInstnId></CdtrAgt>
        <Cdtr><Nm>Jan de Vries (ZZP)</Nm></Cdtr>
        <CdtrAcct><Id><IBAN>NL00TEST0222222222</IBAN></Id></CdtrAcct>
        <RmtInf><Ustrd>JDV-2026-06-0003</Ustrd></RmtInf>
      </CdtTrfTxInf>
    </PmtInf>
  </CstmrCdtTrfInitn>
</Document>
```

Corresponding CSV fixture (`PR-2026-001.csv`):
```
payeeName,creditorIban,amount,currency,remittanceInfo,apTransactionRef
Eneco Energie B.V.,NL00BANK0123456789,892.50,EUR,ENECO-2026-04-0001,ap-txn-eneco-2026-04-0001
Jan de Vries (ZZP),NL00TEST0222222222,605.00,EUR,JDV-2026-06-0003,ap-txn-jdv-2026-06-0003
```

The travel-agency (freelancer/ZZP creditor) + the consultancy (debtor/initiating
party) appear in the run; the municipality payee exercises a third payee type
in a second seed run if needed. All IBAN/BIC are SAFE placeholders.

For reconciliation testing, a matching CAMT.053 fixture
(`PR-2026-001.camt053.xml`, SAFE placeholders) echoes the two exported
EndToEndIds so the run fully reconciles:

```xml
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt>
    <Stmt>
      <Id>STMT-PLACEHOLDER</Id>
      <Acct><Id><IBAN>NL00BANK9999999999</IBAN></Id></Acct>
      <Ntry>
        <Amt Ccy="EUR">892.50</Amt>
        <CdtDbtInd>DBIT</CdtDbtInd>
        <Sts>BOOK</Sts>
        <NtryDtls><TxDtls>
          <Refs><EndToEndId>PR-2026-001-1</EndToEndId></Refs>
          <Amt Ccy="EUR">892.50</Amt>
          <CdtrAcct><Id><IBAN>NL00BANK0123456789</IBAN></Id></CdtrAcct>
        </TxDtls></NtryDtls>
      </Ntry>
      <Ntry>
        <Amt Ccy="EUR">605.00</Amt>
        <CdtDbtInd>DBIT</CdtDbtInd>
        <Sts>BOOK</Sts>
        <NtryDtls><TxDtls>
          <Refs><EndToEndId>PR-2026-001-2</EndToEndId></Refs>
          <Amt Ccy="EUR">605.00</Amt>
          <CdtrAcct><Id><IBAN>NL00TEST0222222222</IBAN></Id></CdtrAcct>
        </TxDtls></NtryDtls>
      </Ntry>
    </Stmt>
  </BkToCstmrStmt>
</Document>
```

A second "partial" fixture (`PR-2026-001.partial.camt053.xml`) omits the second
`Ntry` so reconciliation matches only line 1 → the run STAYS `exported` with a
mismatch note. All IBAN/EndToEndId/statement-id values are SAFE placeholders.

## Risks / Trade-offs

- **[Bank rejects the XML on a schema technicality]** → Generate against the
  pain.001.001.03 XSD element order; cover `GrpHdr` totals + element presence
  with a unit test asserting the canonical structure (REQ-SEPA-002).
- **[`Payee.iban`/`bic` missing on a line]** → The line carries its own
  `creditorIban`; BIC is optional in pain.001. Validate `creditorIban` presence
  per line before export; fail the export with a clear message if absent.
- **[Lifecycle transition races the file write]** → Write + tag the file first,
  then set `exportedFileRef`/`exportedAt`, then request `approved → exported`
  through the OR engine; fail-soft and leave the run `approved` if storage
  fails (idempotent retry).
- **[Placeholder values leaking into real exports]** → Placeholders live only
  in fixtures/tests; real exports read live `PaymentRun`/`Payee` data.
  gitleaks-safe placeholders (uppercase, `<angle-brackets>`, `NL00…`) only.
- **[A partial CAMT.053 silently flips the run to reconciled]** → The
  reconciliation service transitions `exported → reconciled` ONLY on a full
  match (every line matched); a partial/unmatched result leaves the run
  `exported` and records a mismatch note — never a forced transition.
- **[Ambiguous statement entry matches the wrong line]** → `EndToEndId` is the
  primary, authoritative key (deterministic `<runNumber>-<lineIndex>`); the
  amount + creditor IBAN fallback only applies to an as-yet-unmatched line, so
  two equal-amount lines are still disambiguated.

## Migration Plan

1. Add `lib/PaymentRun/` generators + export service (mirror `lib/Reporting/`).
2. Register the export route + controller (`appinfo/routes.php`, ADR-016).
3. Add the "Export to bank" action on the `PaymentRun` detail page.
4. Add unit tests over the pain.001 structure + CSV against the seed run.
5. Add `Camt053StatementParser` + `PaymentRunReconciliationService` under
   `lib/PaymentRun/`; register the reconcile route + controller.
6. Add the "Reconcile / import statement" action on the detail page.
7. Add unit tests over CAMT.053 parsing + matching (full + partial fixtures).

Rollback: remove the route/controller/service/generators/parser + the
detail-page actions. No schema or data migration is involved (the schema +
both lifecycle transitions are owned by the dependency change).

## Open Questions

- CSV fallback: keep vs pain.001-only — provisional: keep (D3).
- Trigger: Vue button + controller vs OR lifecycle action — provisional:
  Vue button + controller (D4).

## Resolved Decisions

- **Reconciliation** — RESOLVED: included in this change (D6). Import a
  CAMT.053 statement, match booked entries to `paymentLines[]` (EndToEndId
  primary; amount + creditor IBAN fallback), and drive `exported → reconciled`
  (set `reconciledAt`) on a full match; partial/unmatched lines stay `exported`
  with a mismatch note. CAMT.053 parsing is imperative file ingestion (ADR-031),
  justified like the export generator; no new composer dependency.
