# reports-via-docudesk Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- reports-via-docudesk

## Purpose

Removes `phpoffice/phpword` (resolved today only via cross-app vendor-dir
reach into OpenRegister/docudesk's own vendor trees — banned by ADR-075 D3)
from shillinq's `lib/Reporting/Generator/` document report stack, and
re-implements the five document report generators (balance sheet, profit and
loss, annual accounts, management letter, BBV jaarstukken) as docudesk
consumers per the hrmq `payslip-pdf-docudesk` reference pattern: shillinq
assembles the report's structured content, docudesk renders it. Docudesk's
absence degrades to a visible, distinguishable outcome, never a silent drop
(ADR-081 rule 7).

## ADDED Requirements

@e2e exclude backend-only defect fix (generator internals + service error
handling); no new UI surface — the existing Reporting & Compliance overview
page and generate/download flow are unchanged black-box, and shillinq has no
Playwright coverage of the reporting-generate endpoint today (tracked
separately, not introduced or removed by this change)

### Requirement: REQ-RVD-001: The five document report generators SHALL carry no PhpOffice reference anywhere in `lib/`

`AbstractDocumentReportGenerator` and its five subclasses
(`BalanceSheetReportGenerator`, `ProfitLossReportGenerator`,
`AnnualAccountsReportGenerator`, `ManagementLetterReportGenerator`,
`BbvJaarstukkenReportGenerator`) MUST NOT `use` any `PhpOffice\PhpWord\*`
class, construct `new PhpWord()`, call `IOFactory::createWriter()`, or resolve
`\Dompdf\Dompdf` by any means (reflection, `class_exists`, or otherwise).
`composer.json` MUST NOT declare `phpoffice/phpword` (per the binding
ruling: "if we use docudesk as a leaf then we won't need PHPWord as a
dependency" — PhpWord disappears, it is not re-declared).

#### Scenario: Zero PhpOffice references in lib/

- **GIVEN** the shillinq `lib/` tree after this change
- **WHEN** `grep -rn "PhpOffice" lib/` is run
- **THEN** it returns zero matches

#### Scenario: composer.json carries no PhpWord dependency

- **GIVEN** `composer.json`
- **WHEN** its `require` and `require-dev` sections are inspected
- **THEN** neither contains `phpoffice/phpword`

### Requirement: REQ-RVD-002: A document generator SHALL assemble a structured report body and hand it to docudesk's `DocumentService::generateDocument()`; it MUST NOT render bytes itself

A document report generator SHALL assemble its content as a `ReportSection`
block tree and MUST NOT construct, serialise, or write any document/PDF
bytes itself — rendering is docudesk's exclusive responsibility (ADR-075).

Each of the five generators' `build(ReportSection $section, array $context)`
method retains its existing OpenRegister object loading and business
classification logic (account-type bucketing, GL-movement derivation,
rule-audit summarisation, BBV programme/taakveld aggregation) unchanged, but
writes into a `ReportSection` (an in-memory block accumulator) instead of a
`PhpOffice\PhpWord\PhpWord` document. `AbstractDocumentReportGenerator::
generate()` serialises the resulting block tree into
`options.adHocData.report`, resolves the docudesk template id per REQ-RVD-004,
and calls `OCA\DocuDesk\Service\DocumentService::generateDocument($templateId,
[], $options)` (empty `dataRefs` — the computed body, not raw object refs,
travels in `adHocData`, per ADR-081 rule 6: shillinq supplies context and
classification, docudesk renders) resolved by string FQCN through
`\OCP\Server::get()` — no compile-time `use` import of any `OCA\DocuDesk\*`
class, no composer/info.xml dependency on docudesk.

#### Scenario: A generator's build() output reaches docudesk's adHocData

- **GIVEN** a `BalanceSheetReportGenerator` and a fake `DocumentService` whose
  `generateDocument()` records the `$options` it was called with
- **WHEN** `generate(['administrationId' => 'admin-1'], 'pdf')` is invoked
  against fixture `Account` rows
- **THEN** `$options['adHocData']['report']['blocks']` contains the
  balance-sheet's activa/passiva amount-table blocks with the same figures
  the OpenRegister fixture rows imply, and `$options['dataRefs']` is not
  referenced by the call (the method signature's third positional argument
  carries no raw `Account` refs)

#### Scenario: The rendered file's bytes come from docudesk's response

- **GIVEN** a fake `DocumentService::generateDocument()` returning
  `['content' => 'FAKE-PDF-BYTES']`
- **WHEN** a document generator's `generate()` is called with `format: 'pdf'`
- **THEN** the returned `GeneratedFile->content` equals `'FAKE-PDF-BYTES'`
  verbatim — the generator does not transform, wrap, or re-encode it

### Requirement: REQ-RVD-003: Requested formats SHALL map to docudesk's supported vocabulary; `docx` output is no longer offered

A document generator SHALL only offer formats docudesk's `DocumentService`
actually supports (`pdf`, `odf`, mapped from the public `odt` label) and MUST
NOT offer `docx`.

`AbstractDocumentReportGenerator::supportedFormats()` returns `['odt', 'pdf']`
(previously `['docx', 'odt', 'pdf']`). A requested format of `odt` maps to
docudesk's `format: 'odf'` option value; `pdf` maps to `pdf` directly.
`ReportCatalogue`'s five document-kind entries' `formats` arrays are updated
to `['odt', 'pdf']`.

#### Scenario: odt request maps to docudesk's odf format key

- **GIVEN** a fake `DocumentService::generateDocument()` that records the
  `$options['format']` it received
- **WHEN** a document generator's `generate($context, 'odt')` is called
- **THEN** the recorded `$options['format']` equals `'odf'`, and the returned
  `GeneratedFile->format` equals `'odt'` (the public-facing label is
  unchanged so existing download links/extensions keep working)

#### Scenario: An unsupported format falls back to the first supported one

- **GIVEN** a document generator
- **WHEN** `generate($context, 'docx')` is called (no longer supported)
- **THEN** the generator falls back to `'odt'` (the first entry of
  `supportedFormats()`), matching the pre-existing fallback behaviour for any
  unsupported format

### Requirement: REQ-RVD-004: Template selection SHALL be config-first, namespace/category discovery second, and fail closed on zero or multiple matches

For a given `reportType`, `selectTemplate()` first checks the `IAppConfig`
key `documents_template_{reportType}` (an admin-set docudesk template UUID);
when empty, it calls `TemplateService::getTemplatesByNamespace('shillinq')`
and filters for `category === ReportCatalogue::byId($reportType)['templateId']`
(e.g. `shillinq-balans` for `balance-sheet`). Exactly one match is used; zero
or multiple matches MUST fail the generation attempt with a diagnostic naming
the `(namespace, category)` pair searched — the generator MUST NOT guess
between templates that produce official financial/statutory documents.

#### Scenario: Configured template UUID wins over discovery

- **GIVEN** `IAppConfig` holds `documents_template_balance-sheet =
  "11111111-1111-1111-1111-111111111111"` and docudesk also has a discoverable
  `namespace: shillinq, category: shillinq-balans` template with a different id
- **WHEN** `selectTemplate()` runs for `balance-sheet`
- **THEN** the configured UUID is used, and `TemplateService::
  getTemplatesByNamespace()` is not consulted

#### Scenario: Zero matching templates fails closed with a diagnostic

- **GIVEN** no `IAppConfig` override and no docudesk template with
  `namespace: shillinq, category: shillinq-balans`
- **WHEN** `balance-sheet` generation is attempted
- **THEN** the attempt fails with a message naming `shillinq` and
  `shillinq-balans`, and `DocumentService::generateDocument()` is never called

#### Scenario: Multiple matching templates fails closed rather than guessing

- **GIVEN** two docudesk templates both with `namespace: shillinq, category:
  shillinq-balans`
- **WHEN** `balance-sheet` generation is attempted
- **THEN** the attempt fails with a diagnostic naming both candidate ids, and
  neither is rendered

### Requirement: REQ-RVD-005: Docudesk's absence SHALL be a visible, distinguishable outcome — never a silent drop

A document generator SHALL detect docudesk's absence before attempting to
render, and the system MUST record and surface that outcome visibly and
distinguishably from a generic failure — never as a silent drop (ADR-081
rule 7).

`AbstractDocumentReportGenerator::docudeskAvailable()` duck-type probes
`IAppManager::isInstalled('docudesk')` plus resolvability of
`OCA\DocuDesk\Service\{DocumentService,TemplateService}`. When false,
`generate()` throws `OCA\Shillinq\Reporting\DocudeskUnavailableException`
before any object loading or template lookup. `ReportGenerationService::
generate()` catches this exception ahead of its generic `\Throwable` handler,
writes a `GeneratedReport` record with `status: 'unavailable'` (added to the
schema's `status` enum alongside `generating`/`ready`/`failed`), and returns
`{error: 'docudesk-unavailable', reportType, message, record}`.
`ReportingController::generate()` maps `error: 'docudesk-unavailable'` to
`503 Service Unavailable` (other `{error}` envelopes keep the existing `422`).

#### Scenario: Docudesk absent yields a visible unavailable record, not silence

- **GIVEN** `docudeskAvailable()` returns `false` (docudesk not installed)
- **WHEN** `POST /api/reporting/generate` is called for `balance-sheet`
- **THEN** the response is `503` with `{error: 'docudesk-unavailable', ...}`,
  AND a `GeneratedReport` object exists (findable via `listGenerated()`) with
  `reportType: 'balance-sheet'` and `status: 'unavailable'` — the attempt is
  recorded, not dropped

#### Scenario: Docudesk absence is distinguishable from a generic generation failure

- **GIVEN** two failure causes: (a) docudesk not installed, (b) a template
  lookup throwing an unrelated exception
- **WHEN** each is triggered via `generate()`
- **THEN** (a) returns `error: 'docudesk-unavailable'` / HTTP 503 and writes a
  `status: 'unavailable'` record, while (b) returns `error:
  'generation-failed'` / HTTP 422 as before — the two causes are never
  reported identically

#### Scenario: Generator discovery no longer silently drops a generator

- **GIVEN** `ReportGenerationService::generators()`'s glob-and-instantiate
  discovery
- **WHEN** every file under `lib/Reporting/Generator/*.php` is instantiated
- **THEN** all five document generators are always present in the discovered
  index regardless of docudesk's install state — docudesk availability is
  checked lazily inside `generate()`, never at instantiation, so a docudesk
  outage cannot make a report type vanish from `GET /api/reporting/types`

### Requirement: REQ-RVD-006: Template content authoring is declared, not fabricated by the leaf

This change SHALL declare the block vocabulary each of the five docudesk
templates must render, and MUST NOT fabricate the docudesk-side Twig/HTML
`content` itself — that is out of scope, handed back explicitly.

`lib/Settings/docudesk-templates.json` gains five entries (`shillinq-balans`,
`shillinq-winst-verlies`, `shillinq-jaarrekening`,
`shillinq-management-letter`, `shillinq-bbv-jaarstukken`) documenting the
`ReportSection` block vocabulary (`heading`/`text`/`textBreak`/`note`/
`amountTable`/`detailsTable`/`table`) each corresponding docudesk
`namespace: "shillinq"` Template object must be able to render. This change
does NOT create those five docudesk Template objects (Twig/HTML `content`) —
per hrmq's own `hrmq-docudesk-documents` precedent ("seeding starter
templates into docudesk" is an explicit Non-Goal, "follow-up — cross-app
write"), template content authoring is handed back as follow-up work.

#### Scenario: Five new declarations exist and document the block vocabulary

- **GIVEN** `lib/Settings/docudesk-templates.json`
- **WHEN** parsed as JSON
- **THEN** it contains one entry per `templateId` in
  `['shillinq-balans', 'shillinq-winst-verlies', 'shillinq-jaarrekening',
  'shillinq-management-letter', 'shillinq-bbv-jaarstukken']`, each describing
  which `ReportSection` block types the corresponding report emits

#### Scenario: No fabricated docudesk Template objects are claimed to exist

- **GIVEN** this change's task list
- **WHEN** inspected for a task claiming to create docudesk `template`-schema
  OpenRegister objects
- **THEN** no such task exists — REQ-RVD-004's zero-match fail-closed path is
  the honest, current behaviour until a docudesk-side follow-up seeds the
  five templates
