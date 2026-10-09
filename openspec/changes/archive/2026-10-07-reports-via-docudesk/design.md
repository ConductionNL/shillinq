# Design: reports-via-docudesk

## Context

`AbstractDocumentReportGenerator` currently does two jobs in one class: (1)
load + aggregate/classify OpenRegister objects into report content (per
generator — balance grouping, revenue/expense classification, rule-audit
summarisation, BBV programme/taakveld tables), and (2) lay that content out
into a `PhpOffice\PhpWord\PhpWord` document via a shared vocabulary of
protected helpers (`addCover`, `addHeading`, `addDetailsTable`,
`addAmountTable`, `addParagraph`, `addNote`, `addSection`) plus a few direct
`Section`/`Table`/`Cell` calls in `BbvJaarstukkenReportGenerator` and
`AnnualAccountsReportGenerator`'s private helpers, then serialises through
PhpWord's DOCX/ODT writers or a dompdf-backed PDF writer.

Job (1) is legitimate shillinq business logic (ADR-081 rule 6: "the domain
app supplies context and classification"). Job (2) is exactly the capability
ADR-075 assigns to docudesk. The fix separates them: job (1) stays, almost
byte-for-byte; job (2) is replaced by a hand-off to docudesk's
`DocumentService::generateDocument(templateId, dataRefs, options)`.

## Decisions

### D1 — Keep the same helper vocabulary, change what it writes into

Rewriting every `build()`/private-helper method in five files to talk to a
brand-new API would touch ~1500 lines and re-risk every generator's
classification logic for no reason — that logic never touched PhpWord. Instead,
`AbstractDocumentReportGenerator` gains a small in-house `ReportSection` /
`ReportTable` pair (`lib/Reporting/ReportSection.php`,
`lib/Reporting/ReportTable.php`) that mimics the *shape* of the PhpWord calls
already in use (`addText`, `addTextBreak`, `addTitle`, `addTable`→`addRow`→
`addCell`→`addText`) but accumulates an ordered list of plain-array "blocks"
instead of mutating a Word document. The five generators' `build()` signature
changes from `build(PhpWord $phpWord, array $context)` to
`build(ReportSection $section, array $context)`, their one-line
`$section = $this->addSection($phpWord);` is deleted (the base now constructs
the top-level `ReportSection` itself), and their PhpWord-specific imports are
removed. `BbvJaarstukkenReportGenerator`'s two hand-rolled tables (programme
list, taakveld list) keep using `$section->addTable(...)` / `$table->addRow()`
/ `$table->addCell($widthCm, $style)->addText(...)` against `ReportTable`
instead of PhpWord's `Element\Table`/`Element\Cell` — the twip-converting
`\PhpOffice\PhpWord\Shared\Converter::cmToTwip(N)` calls become plain `N` (a
relative width hint a docudesk template may use or ignore; twips were never
meaningful once nothing renders to an actual Word document).

The resulting `ReportSection::toArray()` is a plain, JSON-serialisable tree:
`{type: heading|text|textBreak|note|amountTable|detailsTable|table, ...}` per
block — semantically the same content the old PhpWord document carried, just
undressed of layout/typography concerns docudesk now owns.

### D2 — The hand-off: dataRefs empty, computed body in `adHocData`

Unlike hrmq's documents (one Employee + one Contract/Payslip — docudesk
re-resolves them itself via `dataRefs`), these five reports are aggregates
over many `Account`/`GLLine`/other rows with classification logic
(account-type bucketing, GL-movement derivation, rule-audit summarisation)
that has no docudesk-side equivalent to recompute from raw refs. Per ADR-081
rule 6, shillinq supplies "context and classification"; docudesk supplies
rendering. So `dataRefs = []` and the entire computed `ReportSection::toArray()`
tree travels as `options.adHocData.report` (`{title, subtitle, blocks}`),
alongside `options.adHocData.meta` (`reportType`, `period`, `administrationId`,
`generatedAt`). `options.format` maps the caller's requested format to
docudesk's vocabulary (`odt` → docudesk's `odf`; `pdf` → `pdf`) —
`VALID_FORMATS` in `docudesk/lib/Service/DocumentService.php` is
`['pdf', 'odf', 'html']`, which has no `docx`, so `docx` output support is
dropped, not reimplemented. `ReportCatalogue`'s five document-kind entries'
`formats` arrays change from `['docx', 'odt', 'pdf']` to `['odt', 'pdf']`.

### D3 — Template discovery reuses the catalogue's existing (previously dead) `templateId` field

`ReportCatalogue::REPORTS` already carries a per-entry `templateId` (e.g.
`shillinq-balans`, `shillinq-winst-verlies`, `shillinq-jaarrekening`,
`shillinq-management-letter`, `shillinq-bbv-jaarstukken`) that no code read —
grep confirms zero references outside the catalogue definition itself. Rather
than invent a second key, template discovery in
`AbstractDocumentReportGenerator::selectTemplate()` now uses that value as the
docudesk template `category`, mirroring hrmq's `category === documentType`
convention but through the catalogue's own naming: config override first
(`IAppConfig` key `documents_template_{reportType}`, an admin-set docudesk
template UUID), else exactly one `namespace: "shillinq"` docudesk template
whose `category` equals the catalogue's `templateId` — zero or multiple
matches fail closed with a diagnostic (never guess between templates that
produce statutory/financial documents), exactly hrmq's D3.

### D4 — Docudesk absence is a distinguishable, visible outcome — not a silent drop

Per ADR-081 rule 7 ("A source app MUST degrade gracefully when the receiver
is absent — an unsent allocation is a visible pending state, never a silent
drop") and hrmq's `skipped-no-docudesk` precedent:

- `AbstractDocumentReportGenerator::docudeskAvailable()` duck-type probes
  `IAppManager::isInstalled('docudesk')` plus resolvability of
  `OCA\DocuDesk\Service\{DocumentService,TemplateService}` via
  `\OCP\Server::get()` (string FQCN only — no compile-time import, no
  composer/info.xml dependency on docudesk), exactly hrmq's probe.
- `generate()` checks this FIRST, before any object loading, and throws a new
  `OCA\Shillinq\Reporting\DocudeskUnavailableException` when false — a
  distinct type from a generic rendering failure.
- `ReportGenerationService::generate()` now catches
  `DocudeskUnavailableException` *before* its existing catch-all `\Throwable`
  branch. Previously, ANY generator exception — including a docudesk-absent
  probe failure, a template-lookup miss, or a genuine bug — collapsed into
  the same undiagnostic `{error: 'generation-failed'}` response with **no**
  `GeneratedReport` record written at all (the record-then-render order only
  wrote a record on success). The new branch writes a `GeneratedReport` with
  `status: 'unavailable'` (new enum value, `lib/Settings/register.d/
  reporting-generated-report.json`) BEFORE returning, so a docudesk-absent
  attempt is visible in the generated-reports index — not merely a JSON
  error that disappears the moment the HTTP response is discarded. The
  controller (`ReportingController::generate()`) maps
  `error: 'docudesk-unavailable'` to `503 Service Unavailable` (previously
  every `{error}` envelope, including this one, mapped to a blanket 422).
- This is a **structural** improvement independent of any one bug: because
  `docudeskAvailable()` is checked lazily inside `generate()` rather than at
  class-load/instantiation time, `ReportGenerationService::generators()`'s
  glob-and-instantiate discovery can no longer silently drop a generator from
  the catalogue the way it could when a missing PhpWord class threw a fatal
  `\Error` at container-resolution time — the generator always exists; only
  the render step can be unavailable, and that step now says so explicitly.

### D5 — Template content authoring is handed back to docudesk (scoped, not half-done)

`lib/Settings/docudesk-templates.json` already declares 15 report/letter
templates in a shillinq-authored, sections/columns/aggregation-shaped JSON
format for other capabilities (innovatiebox, R&D subsidies, dunning, VPB).
Investigation confirms **this file is not mechanically imported by
docudesk or shillinq at runtime** — neither app's `lib/` greps for
`docudesk-templates.json`, and its shape (top-level `sections`/`columns`/
`aggregation`/`totals` keys) does not match docudesk's own `template` OR
schema (`docudesk_register.json` → `template`: requires `name`, `content`
(Twig/HTML string), `namespace`; optional `category`). It is a **design-intent
declaration**, not a live integration surface — exactly the role hrmq's own
reference change assigns the same problem: hrmq's `hrmq-docudesk-documents`
design.md lists "seeding starter templates into docudesk" as an explicit
**Non-Goal**, "follow-up — cross-app write", because template *content*
(the actual Twig markup) is authored in docudesk by a human/admin, not
generated by the consuming leaf app.

This change follows that precedent exactly: it (a) extends
`docudesk-templates.json` with five new entries (`shillinq-balans`,
`shillinq-winst-verlies`, `shillinq-jaarrekening`,
`shillinq-management-letter`, `shillinq-bbv-jaarstukken`) documenting the
`ReportSection::toArray()` block vocabulary each template must render
(heading/text/textBreak/note/amountTable/detailsTable/table — the same shape
every existing declared template in the file already documents structurally),
and (b) does **not** claim those five docudesk `namespace: "shillinq"`
Template objects exist yet. `selectTemplate()`'s zero-match branch fails
closed with a diagnostic naming exactly which `(namespace, category)` pair
was not found — which is also today's honest state for hrmq's own four
letter-document types (design.md: "Non-Goals: seeding starter templates").
Creating those five Template objects in docudesk (admin UI or a future
docudesk-side seed) is explicitly **handed back** as follow-up work, not
absorbed into this change.

## Non-Goals (see proposal.md)

Restated for locality: no docudesk-side template content authoring, no
ADR-075 fleet-wide published-contract package, no changes to the six
"data"-kind report generators.
