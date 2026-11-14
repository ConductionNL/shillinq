---
status: done
---

# Spec: reporting-report-library

**Status:** built (written after the fact, 2026-10-07, from the code on development at b6ae88193)
**Scope:** shillinq
**Parity rows:** `rep-report-library`, `rep-gl-report`, `rep-export`

## Purpose

Shillinq keeps its standard reports in one catalogue. A user opens the Reporting & compliance overview, picks a report, chooses an administration, a period and a format, and gets a file stored in their Nextcloud files with a record they can find and download again later.

This spec describes what the code does today. It was written after the code, because the report library shipped without a spec and its classes carried `@spec exclude` for that reason. Where the code falls short of what the catalogue promises, the requirement says so instead of describing the promise.

The files it describes:

- `lib/Reporting/ReportCatalogue.php`: the catalogue of report types and categories.
- `lib/Reporting/ReportGenerationService.php`: generation, file storage, tags and the `GeneratedReport` record.
- `lib/Reporting/Generator/*.php`: one generator per report type, found by `ReportGeneratorInterface`.
- `lib/Controller/ReportingController.php` and `appinfo/routes.php:829-832`: the four `/api/reporting/*` routes.
- `src/components/reporting/ReportingComplianceOverview.vue`, `src/modals/GenerateReportDialog.vue`, `src/components/reporting/GeneratedReportsIndex.vue`: the pages, registered in `src/manifest.d/reporting-compliance.json`.

Document-kind reports (annual accounts, balance sheet, profit and loss, BBV jaarstukken, management letter) are rendered by docudesk; that hand-off belongs to the open change `reports-via-docudesk` and is described here only where the library reacts to it.

## Requirements

@e2e exclude after-the-fact spec of shipped behaviour; no e2e test is added in a docs-only round

### Requirement: The catalogue SHALL list every standard report in one place, grouped by category

`ReportCatalogue::all()` MUST be the single list of report types. Each entry MUST carry an `id`, a `label`, a `category`, a `kind` (`data` or `document`), the `formats` offered to the user, a `description` and, for document reports, a `templateId`. `ReportCatalogue::CATEGORIES` MUST define the categories and their display order: tax filings, statements, ledger and balances, audit files, public sector, and compliance. `GET /api/reporting/types` MUST return the categories and the entries grouped under them, and MUST answer 401 to a request without a logged-in user. The Reporting & compliance overview (`/reporting-compliance`) MUST show each entry as a card under its category, with a category filter and a free-text search.

#### Scenario: A user finds every report on one page
- **WHEN** a logged-in user opens Reporting & compliance
- **THEN** the page SHALL call `GET /api/reporting/types`
- **AND** it SHALL show one card per catalogue entry (17 today), grouped under the six categories in catalogue order

#### Scenario: An anonymous request gets no catalogue
- **WHEN** `GET /api/reporting/types` is called without a user session
- **THEN** the response SHALL be 401 with `error: not-logged-in`

### Requirement: Generating a report SHALL run the report type's generator for one administration, period and format

`POST /api/reporting/generate` MUST take `reportType`, `period`, `administrationId` and `format`. It MUST answer 400 when `reportType` is empty, and 404 when a named administration is not one the user may access (`AdministrationContextService::canAccess`). `ReportGenerationService::generate()` MUST answer `unknown-report-type` for an id the catalogue does not hold, and `no-generator` when no class in `lib/Reporting/Generator/` reports that type; the controller MUST turn both into 422. When the generator does not support the requested format, the service MUST use the generator's first supported format instead of failing. The `GenerateReportDialog` modal, opened from a card's generate button, MUST collect the administration, the period (year, quarter or month) and the format before it posts.

#### Scenario: A report with a generator produces a file
- **WHEN** a user generates `trial-balance` for an administration they may access, for period `2026`, in format `csv`
- **THEN** the response SHALL be 201 with the saved `GeneratedReport` record and a `downloadPath`

#### Scenario: A catalogue entry without a generator fails visibly
- **WHEN** a user generates `icp-opgaaf`, `ib-aangifte`, `vpb-aangifte`, `sbr-xbrl` or `audit-trail`
- **THEN** the service SHALL log `no generator for report type`
- **AND** the response SHALL be 422 with `error: no-generator`

#### Scenario: An unsupported format falls back to the generator's own
- **WHEN** a user asks for `trial-balance` as `pdf`, which the catalogue offers but `TrialBalanceReportGenerator` does not support
- **THEN** the service SHALL generate it as `csv`, the generator's first supported format

#### Scenario: Another tenant's administration is refused
- **WHEN** a user posts an `administrationId` they have no access to
- **THEN** the response SHALL be 404 with `error: not-found` and no generator SHALL run

### Requirement: A generated report SHALL be stored as a file with tags and a GeneratedReport record

After a generator returns, the service MUST write the file to the user's folder `Shillinq/Reports/<administrationId>/` with a name that does not overwrite an existing file, tag it with `shillinq-report:<type>`, `shillinq-period:<period>`, `shillinq-administration:<id>` and `shillinq-category:<category>`, and save a `GeneratedReport` object carrying the type, label, category, period, administration, format, file name, path and id, size, timestamp, user and `status: ready`. When docudesk is absent for a document report, the service MUST still save a record with `status: unavailable` and the controller MUST answer 503 with `error: docudesk-unavailable`, so the attempt can be found later instead of disappearing. Any other generator failure MUST answer 422 with `error: generation-failed`.

#### Scenario: The file lands in the user's files, tagged
- **WHEN** a generation succeeds for administration `A1`
- **THEN** a file SHALL exist under `Shillinq/Reports/A1/` in the user's files
- **AND** it SHALL carry the four `shillinq-*` system tags

#### Scenario: A document report without docudesk leaves a record
- **WHEN** a user generates `balance-sheet` and docudesk is not installed
- **THEN** a `GeneratedReport` with `status: unavailable` SHALL be saved
- **AND** the response SHALL be 503 with `error: docudesk-unavailable`

### Requirement: Generated reports SHALL be listed per accessible administration and downloadable

`GET /api/reporting/generated` MUST return the `GeneratedReport` records of every administration the user may access, or of one named administration after an access check (404 otherwise), filtered by `reportType`, `period` and `category` when given. `GET /api/reporting/download/{id}` MUST stream the stored file as a download, and MUST answer 404 when the record does not exist, belongs to an administration the user may not access, or its file is gone or unreadable. The Generated reports page (`/reporting-compliance/generated`) MUST list the records with category, period and administration filters and a download link per row.

#### Scenario: A user downloads a report they generated earlier
- **WHEN** a user opens Generated reports and clicks a row's download link
- **THEN** the browser SHALL receive the stored file with its name and mime type

#### Scenario: Another administration's report stays hidden
- **WHEN** a user requests `/api/reporting/download/{id}` for a record of an administration they cannot access
- **THEN** the response SHALL be 404 with `error: not-found`

### Requirement: The general ledger report SHALL give one card per account with a running balance

`GeneralLedgerReportGenerator` (report type `general-ledger`, format `csv`) MUST load the `GLLine` objects filtered by `administrationId` and by `periodId` when a period is given, group them by `accountNumber` (lines without one under `(unassigned)`), sort the accounts by number and the lines within an account by line number and then transaction id. Each row MUST carry the account number and name, line number, transaction id, date (posting date, else value date), description, debit, credit and a running balance that starts at zero per account, adds debits and subtracts credits. The only filters are administration and period; the running balance does not include an opening balance from earlier periods.

#### Scenario: Running balance per account
- **WHEN** account 1100 has a debit of 100.00 and then a credit of 30.00 in the chosen period
- **THEN** the card for 1100 SHALL show running balances 100.00 and 70.00

#### Scenario: Period filter narrows the lines
- **WHEN** a user generates `general-ledger` for period `P2026-03`
- **THEN** only `GLLine` objects with that `periodId` SHALL appear

### Requirement: Report output SHALL be offered as CSV, XML, or PDF through docudesk

Data reports MUST be written by shillinq's own generators in the formats they support: CSV for `general-ledger`, `trial-balance` and `rule-audit`, XML for `vat-return`, `saft` and `xaf`, XML or CSV for `iv3`. PDF and ODT MUST come only from the document reports, which hand their content to docudesk (`AbstractDocumentReportGenerator`). CSV and Excel export of any list page is not shillinq's: it is the mass export of `CnIndexPage` in `@conduction/nextcloud-vue`, backed by OpenRegister's export, and the segment profit and loss CSV belongs to `bookkeeping-cost-centers-dimensions`.

#### Scenario: A data report downloads as CSV
- **WHEN** a user generates `rule-audit` in format `csv`
- **THEN** the stored file SHALL be a CSV produced by `RuleAuditReportGenerator`

#### Scenario: A PDF needs docudesk
- **WHEN** a user generates `profit-loss` as `pdf` on an instance without docudesk
- **THEN** no file SHALL be produced and the response SHALL be 503 with `error: docudesk-unavailable`
