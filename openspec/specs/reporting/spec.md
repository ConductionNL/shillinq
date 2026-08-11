# Spec: reporting

**Status:** reverse-engineered from shipped code
**Scope:** shillinq
**Tier:** T2 (cross-domain delivery surface)

## Purpose

This capability owns the **Reporting & Compliance section**: the catalogue of
every report shillinq can produce, the contract each report's generator
implements, the orchestration that renders a report and archives the resulting
file, the HTTP surface in front of it, and the three pages that surface it to an
operator.

It deliberately does **not** own what any individual report *says*. The content,
statutory basis and derivation rules of each report belong to its own domain
capability — `bookkeeping-vat-btw-filing` for the BTW-aangifte,
`bookkeeping-iv3-reporting` for IV3, `bookkeeping-trial-balance` for the
proef- en saldibalans, `bookkeeping-titel-9-jaarrekening` for the jaarrekening,
`bookkeeping-bbv-compliance` for the BBV jaarstukken, `bookkeeping-rule-engine`
for the compliance audit, `bookkeeping-multi-administratie` for the XAF 3.2
auditfile. This capability is the **delivery** layer those reports are handed to:
catalogue entry, generator contract, render, store, tag, record, list, download.

That split is load-bearing and is why this spec exists as its own capability
rather than as extra requirements bolted onto the domain specs. Several domain
capabilities specify their figures as *declarative OpenRegister aggregations and
explicitly forbid a PHP report builder* (`bookkeeping-trial-balance` REQ-TB-001,
`bookkeeping-iv3-reporting` REQ-IV3-004, `bookkeeping-vat-btw-filing`
REQ-VBTW-004). The generators in `lib/Reporting/Generator/` are PHP renderers.
Annotating them against those requirements would claim conformance to a rule the
code does not follow. They are annotated here instead, and the tension is
recorded in **Notes** below so it is decided rather than hidden.

## Requirements

### Requirement: REQ-RPT-001 — A single static catalogue SHALL be the source of truth for every report shillinq can produce

`ReportCatalogue` MUST be the only registry of report types. Each entry MUST
carry an `id`, a human `label`, a `category` drawn from a fixed ordered category
list (`tax`, `statements`, `ledger`, `audit-file`, `public-sector`,
`compliance`), a `kind` of either `data` or `document`, the `formats` it offers
in preference order, a `description`, and — for document reports — the default
`templateId` shipped in `lib/Reporting/templates/`.

The catalogue MUST drive both the overview cards and the generator lookup, so a
report cannot appear in the UI without a generator behind it, and no second
registry of report types may be introduced.

#### Scenario: A report type is looked up by id

- **GIVEN** the catalogue declares a report with id `trial-balance`
- **WHEN** `ReportCatalogue::byId('trial-balance')` is called
- **THEN** it returns that entry, and `byId()` on an id the catalogue does not
  declare returns `null` rather than throwing

### Requirement: REQ-RPT-002 — Every report type SHALL be produced by exactly one generator implementing a single contract

Each report type MUST have one class implementing `ReportGeneratorInterface`,
which declares `reportType()` (the catalogue id it serves),
`supportedFormats()` (in preference order) and `generate(array $context, string
$format)`. `generate()` MUST return a `GeneratedFile` — an immutable value
object carrying the rendered bytes, the suggested file name, the MIME type and
the short format label — and MUST NOT persist anything itself.

Generators MUST be discovered by scanning `lib/Reporting/Generator/` and
indexing implementations by `::reportType()`, and MUST be constructible with no
constructor arguments, resolving their collaborators lazily from the server
container.

#### Scenario: An unknown report type is refused rather than rendered

- **GIVEN** a generation request naming a report type with no generator
- **WHEN** the request is orchestrated
- **THEN** it returns an error result naming the report type, and no file is
  written and no record is created

#### Scenario: An unsupported format falls back to the generator's preferred one

- **GIVEN** a generator whose `supportedFormats()` is `['csv']`
- **WHEN** generation is requested in `pdf`
- **THEN** the report is rendered in `csv`, the generator's first supported
  format, rather than failing

### Requirement: REQ-RPT-003 — Data reports SHALL be rendered byte-natively, with an empty period still producing a valid file

A `kind: data` report (SAF-T XML, XAF, IV3, BTW-aangifte, trial balance CSV,
general-ledger CSV, rule-audit CSV) MUST be rendered directly with `XMLWriter`
or `fputcsv` into `php://temp`. No XML DOM, spreadsheet or office library may be
used on this path.

The row source MUST be OpenRegister, reached through the shared
`ReportDataTrait` so every data generator uses the same register resolution,
period/administration scoping, row normalisation and money/CSV/XML formatting.

When a source schema returns no rows for the requested period, the generator
MUST still emit a structurally valid, well-formed file with its containers
present and its totals at zero. It MUST NOT fatal and MUST NOT emit a truncated
document.

#### Scenario: An empty period yields a valid empty document

- **GIVEN** a period in which the source schema holds no rows
- **WHEN** a data report is generated
- **THEN** the output is well-formed, its container elements or header row are
  present, and its totals are zero

### Requirement: REQ-RPT-004 — Document reports SHALL be rendered through the office libraries OpenRegister already bundles, from an in-code default layout

A `kind: document` report (jaarrekening, balans, winst-en-verliesrekening,
management letter, BBV jaarstukken) MUST be built as a single PhpWord document
from a default layout defined in code — so a fresh install renders without any
external template asset — and emitted as DOCX (`Word2007`), ODT (`ODText`) or
PDF (dompdf via `Settings::PDF_RENDERER_DOMPDF`).

Those libraries MUST be taken from the PHPOffice stack bundled in OpenRegister,
a runtime dependency whose autoloader is always active. This capability MUST NOT
add an office or PDF dependency to shillinq's `composer.json`.

The editable formats MUST be offered ahead of PDF, because template
customisation is docudesk's job, not shillinq's.

#### Scenario: A document report renders without a shipped template asset

- **GIVEN** a fresh install with no customised template
- **WHEN** a document report is generated as DOCX
- **THEN** it renders from the in-code default layout, and shillinq's
  `composer.json` declares no office or PDF package

### Requirement: REQ-RPT-005 — Generation SHALL store the rendered file in Nextcloud Files, tag it, and record a GeneratedReport

After a generator returns, the orchestration MUST write the bytes into the
current user's Files home under `/Shillinq/Reports/<administrationId>/`,
de-duplicating the file name rather than overwriting an existing report.

It MUST apply the system tags `shillinq-report:<type>`,
`shillinq-period:<period>`, `shillinq-administration:<id>` and
`shillinq-category:<category>` to the stored file, and MUST record a
`GeneratedReport` object through OpenRegister's `ObjectService` carrying the
report type and label, category, period, administration, format, file name,
file path, file id, size, generation timestamp, generating user, status and the
applied tags.

The response MUST always carry a stable download path derived from the record
id, so the caller can offer a download link regardless of how persistence went.

#### Scenario: A generated report is archived and retrievable

- **GIVEN** a report is generated for administration `WERK-001`, period `2026-Q1`
- **WHEN** generation completes
- **THEN** the file exists under `/Shillinq/Reports/WERK-001/`, carries the four
  system tags, and a `GeneratedReport` record points at it by file id

### Requirement: REQ-RPT-006 — The reporting HTTP surface SHALL expose catalogue, generation, listing and download

Four endpoints MUST be served: `GET /api/reporting/types` (the catalogue grouped
by category, plus the category display labels), `POST /api/reporting/generate`,
`GET /api/reporting/generated` (the recorded `GeneratedReport` rows, filterable
by report type, period, administration and category) and
`GET /api/reporting/download/{id}` (streams the stored file).

Every endpoint MUST be `#[NoAdminRequired]` — finance officers and controllers,
not only admins, work with reports — and MUST reject an anonymous request. File
access on the download path MUST be resolved through the current user's Files
home, so a record id never grants access to another user's file.

#### Scenario: An anonymous caller is rejected

- **WHEN** any reporting endpoint is called without a session
- **THEN** the response is an authentication error and no report is generated or
  streamed

### Requirement: REQ-RPT-007 — The section SHALL surface as an overview page, an isolated generate dialog, and a generated-reports index

The overview page MUST render the catalogue as category-grouped cards with a
per-card format picker, a category filter and a free-text search, replacing the
report leaves that were scattered across the Belastingen, Bookkeeping,
PublicSector and Purchasing menus.

The generate flow MUST live in its own `.vue` file under `src/modals/` and be
imported by the overview page — never inlined into it. It collects period,
administration and format, POSTs the generation request, emits the result so the
parent can offer a download link, and on failure surfaces the error inline and
stays open.

The generated-reports index MUST list the archived `GeneratedReport` rows with a
download link per row and filters for category, period and administration.

Both pages MUST be registered as `kind: "page"` custom components with their
routes declared in the ADR-037 manifest fragment
`src/manifest.d/reporting-compliance.json`; `src/manifest.json` MUST NOT be
edited directly.

#### Scenario: Generating from a card leads to a downloadable archived report

- **GIVEN** an operator on the overview page
- **WHEN** they pick a format on a report card, choose period and administration
  in the dialog, and confirm
- **THEN** the dialog reports success with a download link, and the report
  appears in the generated-reports index

### Requirement: REQ-RPT-008 — Every external call on the generation path SHALL be fail-soft

Container resolution, Files access, system tagging and object persistence MUST
each degrade rather than crash the request: a failure is logged as a warning and
the caller receives a usable result. A generator that throws MUST be caught and
reported as a generation error naming the report type.

A report whose file could not be stored MUST NOT be recorded as if it had been,
and a record that could not be persisted MUST NOT lose the rendered result to
the caller.

#### Scenario: Tagging is unavailable

- **GIVEN** the system-tag manager cannot resolve or assign a tag
- **WHEN** a report is generated
- **THEN** the file is still stored, the record is still written, a warning is
  logged, and the request succeeds

## Notes

**Why this capability was written.** The `reporting-compliance-consolidation`
change directory that these classes were annotated against was never committed
to this repository, so 22 `@spec` tags across `lib/Reporting/**`,
`lib/Controller/ReportingController.php` and the three reporting Vue files
pointed at a path that has never existed. This spec is reverse-engineered from
the shipped code so those tags have a canonical home. **It documents what the
code does today; it is not a design decision that was reviewed before the code
was written, and it deserves a human's eyes.**

**The declarative-first tension.** `bookkeeping-trial-balance` REQ-TB-001,
`bookkeeping-iv3-reporting` REQ-IV3-004 and `bookkeeping-vat-btw-filing`
REQ-VBTW-004 each require their figures to come from declarative OpenRegister
aggregations and explicitly rule out a PHP report builder walking the ledger.
`TrialBalanceReportGenerator`, `Iv3ReportGenerator` and
`VatReturnReportGenerator` are PHP renderers that read rows and sum them in PHP.
Either the generators should become thin renderers over declarative
aggregations, or those three requirements should be amended to allow a rendering
pass on top of them. Until that is decided, REQ-RPT-003 describes the renderers
as they are and does not claim conformance to the declarative requirements.

**Cross-capability content ownership.** The figures and statutory basis of each
report remain specified by its domain capability. This capability governs only
how a report is catalogued, rendered, archived and served.
