# Spec: bookkeeping-multi-administratie (delta)

## ADDED Requirements

### Requirement: REQ-MA-011 Shillinq SHALL export a real XAF 3.2 Auditfile Financieel per administration

The system SHALL produce a valid **XAF 3.2 (Auditfile Financieel)** document
scoped to a single administration, and SHALL expose a streaming administration-
export route that delivers it — fulfilling the REQ-MA-007 "Full administratie
export in Auditfile XAF" scenario, which today has no backing code (the shipped
`AdministrationController::exportScope()` returns only a scope descriptor).

The generator MUST emit the XAF namespace `http://www.auditfiles.nl/XAF/3.2`
(the Dutch national standard maintained by the Belastingdienst / XBRL
Nederland) and MUST assemble the mandatory XAF blocks — `header`, `company`,
`generalLedger` (from the administration's `Account` chart of accounts),
`customersSuppliers` (from the `Payee` / customer master), and `transactions`
(from `GLTransaction` + their `GLLine` children) — byte-native via `XMLWriter`.

The OECD **SAF-T** generator (`SaftReportGenerator`,
`urn:OECD:StandardAuditFile-Tax:2.00`) is a **different** standard and MUST NOT
be substituted for the Dutch XAF: a request for "het auditfile" in the
Netherlands means XAF 3.2. Both generators MAY coexist (`saft` and `xaf` report
ids).

The export MUST enforce `administrationId` data isolation — no journal line,
account, or relation belonging to another administration may appear in the file.
Per the REQ-MA-007 scenario the full export MUST be deliverable as a ZIP bundling
the XAF document plus the administration's attached NC-Files documents.

#### Scenario: Full administration export produces a valid, namespaced XAF 3.2 file

- **GIVEN** an accountant requests the full export of administration `WERK-001` for financial year 2026 (via UI or API, administration-scoped)
- **WHEN** the XAF export runs
- **THEN** it returns an XAF document under the `http://www.auditfiles.nl/XAF/3.2` namespace containing the `header`, `company`, `generalLedger`, `customersSuppliers`, and `transactions` blocks assembled from `WERK-001`'s `Account`, `Payee`/customer, `GLTransaction`, and `GLLine` data
- @e2e exclude byte-stream export contract, covered by PHPUnit generator + route tests — not browser-observable

#### Scenario: XAF is not SAF-T

- **WHEN** the `xaf` export is generated
- **THEN** it declares the Dutch XAF 3.2 namespace `http://www.auditfiles.nl/XAF/3.2`, NOT the OECD SAF-T namespace `urn:OECD:StandardAuditFile-Tax:2.00`
- **AND** the pre-existing `SaftReportGenerator` remains available and unchanged as the separate OECD SAF-T surface
- @e2e exclude format-identity assertion, covered by PHPUnit — not browser-observable

#### Scenario: Administration data isolation in the export

- **GIVEN** two administrations `WERK-001` and `WERK-002` with data in the same register
- **WHEN** the XAF export runs for `WERK-001`
- **THEN** every account, relation, and journal line in the file has `administrationId == WERK-001` and no `WERK-002` row appears
- @e2e exclude data-scoping assertion, covered by PHPUnit — not browser-observable

#### Scenario: The exportScope descriptor resolves to real bytes

- **GIVEN** `AdministrationController::exportScope()` returns `{ format: 'xaf-3.2', ... }`
- **WHEN** the streaming administration-export route for that scope is called
- **THEN** it responds with the generated XAF 3.2 file (and, for a full export, a ZIP bundling the XAF plus the administration's attached documents) rather than only the descriptor
- @e2e exclude REST export contract, covered by PHPUnit controller test — not browser-observable
