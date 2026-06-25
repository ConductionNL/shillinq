## 1. Generators (document generation)

- [x] 1.1 Add a `PaymentRunGeneratorInterface` under `lib/PaymentRun/Generator/` (mirroring `lib/Reporting/Generator/ReportGeneratorInterface`) with a `format()` discriminator (`sepa-pain001` / `csv`) and a `render(PaymentRun): RenderedFile` method.
- [x] 1.2 Implement `SepaPain001Generator` rendering pain.001.001.03 XML via native `XMLWriter` (NO new composer dependency): `GrpHdr` (MsgId, CreDtTm, NbOfTxs, CtrlSum, InitgPty), one `PmtInf` (PmtInfId, PmtMtd=TRF, BtchBookg, ReqdExctnDt, Dbtr/DbtrAcct[IBAN]/DbtrAgt), one `CdtTrfTxInf` per payment line (EndToEndId, InstdAmt[Ccy=EUR], CdtrAgt[BIC optional], Cdtr, CdtrAcct[IBAN], RmtInf[Ustrd/Strd]). NbOfTxs = line count; CtrlSum = totalAmount.
- [x] 1.3 Implement `PaymentRunCsvGenerator` rendering the CSV fallback via `fputcsv` (columns: payeeName, creditorIban, amount, currency, remittanceInfo, apTransactionRef).

## 2. Export orchestration service

- [x] 2.1 Add `PaymentRunExportService` under `lib/PaymentRun/` that auto-discovers the generators by glob (mirroring `ReportGenerationService`), validates the `PaymentRun` is in state `approved` (reject otherwise), and validates each line has a `creditorIban`.
- [x] 2.2 Store the rendered XML + CSV under `/Shillinq/PaymentRuns/<administrationId>/` via `OCP\Files\IRootFolder` (create folders as needed); apply system tags (payment-run number, administration, file type) via `ISystemTagManager` + `ISystemTagObjectMapper`. Fail-soft on Files/tag errors (log warning, do not crash).
- [x] 2.3 On success: set `PaymentRun.exportedFileRef` (XML file ref) + `exportedAt` via OR ObjectService, then request the declarative `approved → exported` lifecycle transition through OpenRegister's lifecycle engine. Do NOT hand-roll a state machine.

## 3. Route + controller (ADR-016 / ADR-005)

- [x] 3.1 Add a `PaymentRunController::export($id)` method that resolves the `PaymentRun`, authorises the caller for its administration (ADR-005 guard), and invokes `PaymentRunExportService`. Declare the Nextcloud auth posture via attribute.
- [x] 3.2 Register the export route in `appinfo/routes.php` (the only registration path per ADR-016).

## 4. Frontend trigger (ADR-004)

- [x] 4.1 Add an "Export to bank" action on the `PaymentRun` detail page that POSTs to the export endpoint and reflects the resulting `exported` state + stored file reference (via `loadState`/store refresh, not DOM data-attributes).

## 5. Reconciliation — CAMT.053 import + match (REQ-SEPA-007)

- [x] 5.1 Add a `Camt053StatementParser` under `lib/PaymentRun/` that parses an ISO 20022 CAMT.053 statement via native `XMLReader`/`SimpleXML` (NO new composer dependency): extract booked outgoing entries (`<Ntry>` with `<Sts>BOOK</Sts>` + `<CdtDbtInd>DBIT</CdtDbtInd>`), each yielding `EndToEndId` (from `<NtryDtls>/<TxDtls>/<Refs>/<EndToEndId>`), amount (`Ccy="EUR"`), and creditor IBAN (`<CdtrAcct>/<Id>/<IBAN>`).
- [x] 5.2 Add a `PaymentRunReconciliationService` under `lib/PaymentRun/` that matches parsed entries to the run's `paymentLines[]`: primary key `EndToEndId` (`<runNumber>-<lineIndex>`), fallback `(amount, creditorIban)` against an as-yet-unmatched line. Validate the run is in state `exported` (reject otherwise).
- [x] 5.3 On a FULL match (every line matched): set `PaymentRun.reconciledAt` via OR ObjectService, then request the declarative `exported → reconciled` lifecycle transition through OpenRegister's lifecycle engine. Do NOT hand-roll a state machine. On a PARTIAL/unmatched result: leave the run `exported` and record a mismatch note (which lines/entries did not match); do NOT transition.
- [x] 5.4 Add a `PaymentRunController::reconcile($id)` method that resolves the `PaymentRun`, authorises the caller for its administration (ADR-005 guard), accepts the uploaded CAMT.053, and invokes `PaymentRunReconciliationService`. Declare the Nextcloud auth posture via attribute. Register the reconcile route in `appinfo/routes.php` (ADR-016).
- [x] 5.5 Add a "Reconcile / import statement" action on the `PaymentRun` detail page that uploads a CAMT.053 to the reconcile endpoint and reflects the resulting state (`reconciled` on full match, or `exported` + mismatch note on partial) via `loadState`/store refresh (not DOM data-attributes).

## 6. Tests + fixtures (SAFE placeholders)

- [x] 6.1 Add a unit test asserting the pain.001 canonical structure against the seeded approved `PR-2026-001`: `GrpHdr/NbOfTxs`=2, `GrpHdr/CtrlSum`=1497.50, one `CdtTrfTxInf` per line, each with EndToEndId + InstdAmt[Ccy=EUR] + Cdtr + CdtrAcct/IBAN + RmtInf/Ustrd. Use SAFE placeholder IBAN/BIC/MsgId fixtures only (e.g. `NL00BANK0123456789`, `<BANKNL2A>`, `MSGID-PLACEHOLDER`).
- [x] 6.2 Add a unit test asserting the CSV fallback has a header + one row per line with matching amount + creditorIban.
- [x] 6.3 Add a test asserting export is rejected for a non-`approved` `PaymentRun` and for an unauthorised caller.
- [x] 6.4 Add a placeholder CAMT.053 fixture (`PR-2026-001.camt053.xml`, SAFE placeholders — IBAN `NL00BANK0123456789`, EndToEndIds `PR-2026-001-1`/`-2`, statement id `STMT-PLACEHOLDER`) echoing both exported EndToEndIds; add a "partial" variant (`PR-2026-001.partial.camt053.xml`) omitting the second entry.
- [x] 6.5 Add a unit test asserting reconciliation of `PR-2026-001` against the full CAMT.053 fixture matches both lines, sets `reconciledAt`, and transitions the run to `reconciled`; and a test asserting the partial fixture matches one line, leaves the run `exported`, sets a mismatch note, and does NOT set `reconciledAt`.
- [x] 6.6 Add a test asserting the amount + creditor IBAN fallback matches a line when the statement entry omits the `EndToEndId`, and that reconcile is rejected for a non-`exported` run and an unauthorised caller.

## 7. Verify

- [x] 7.1 Confirm no new composer dependency was added (native `XMLWriter`/`fputcsv`/`XMLReader` only) — composer.json/lock diff empty; SPDX/forbidden-pattern/route-reachability gates spot-checked clean; phpcs 0 errors on new lib/.
- [x] 7.2 Run `openspec validate payment-run-sepa-export --strict` and confirm it passes — valid.
- [x] 7.3 Live-verify export — DONE end-to-end. Seeded membership+payees+AP invoices+approved PaymentRun PR-2026-001 via OR API; `POST /api/v1/payment-runs/{id}/export` → 200, both `PR-2026-001.pain001.xml` + `.csv` stored under `/Shillinq/PaymentRuns/adm-demo/`, `exportedFileRef`/`exportedAt` set, lifecycle approved→exported. pain.001 verified: `pain.001.001.03` ns, NbOfTxs=2, CtrlSum=1497.50, PmtMtd=TRF, EndToEndIds PR-2026-001-1/-2, amounts 1000.00/497.50, creditor IBANs correct.
- [x] 7.4 Live-verify reconcile — DONE end-to-end. CAMT.053 (full, multipart upload) → 200 result=full, matched 2/2, `reconciledAt` set, lifecycle exported→reconciled. Partial/fallback paths covered by passing `PaymentRunReconciliationServiceTest` (5 tests) + fixtures. NOTE: reconcile reads the statement from a multipart `file` upload (the raw-body path is empty under NC request handling) — the Vue modal uploads a file, so it uses the working path.
