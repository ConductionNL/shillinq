# Tasks: reports-via-docudesk

## Implementation Tasks

### Task 1: Build the `ReportSection`/`ReportTable` block accumulators and `DocudeskUnavailableException`
- **spec_ref**: `openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-002`
- **files**: `lib/Reporting/ReportSection.php` (new), `lib/Reporting/ReportTable.php` (new),
  `lib/Reporting/DocudeskUnavailableException.php` (new)
- **acceptance_criteria**:
  - GIVEN a `ReportSection` WHEN `addText()`, `addTextBreak()`, `addTitle()`,
    `addTable()->addRow()->addCell()->addText()` are called in the same
    sequence the PhpWord `Section`/`Table`/`Cell` API was previously called
    THEN `toArray()` returns an ordered, JSON-serialisable block list
    preserving that sequence and every text/style/width argument passed
  - GIVEN `DocudeskUnavailableException` WHEN thrown and caught as
    `\Throwable` THEN it carries a human-readable message and is
    distinguishable via `instanceof` from any other exception type
- [x] Implement
- [x] Test

### Task 2: Rewrite `AbstractDocumentReportGenerator` — remove PhpWord, add docudesk hand-off
- **spec_ref**: `openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-001`, `#req-rvd-002`, `#req-rvd-003`, `#req-rvd-004`, `#req-rvd-005`
- **files**: `lib/Reporting/Generator/AbstractDocumentReportGenerator.php`
- **acceptance_criteria**:
  - GIVEN the rewritten file WHEN `grep -n "PhpOffice" AbstractDocumentReportGenerator.php`
    runs THEN it returns zero matches
  - GIVEN `generate($context, $format)` WHEN docudesk is available and a
    template resolves THEN it returns a `GeneratedFile` whose `content`
    equals the fake `DocumentService::generateDocument()`'s `['content']`
    verbatim, `format` maps `odt`→`odf`/`pdf`→`pdf` in the outgoing options,
    and `dataRefs` passed is `[]` while `adHocData.report` carries the built
    `ReportSection` tree
  - GIVEN `docudeskAvailable()` is false WHEN `generate()` is called THEN it
    throws `DocudeskUnavailableException` before any `loadObjects()` call
    (proven by a spy/counting fake ObjectService recording zero invocations)
  - GIVEN zero or multiple matching docudesk templates WHEN `generate()` is
    called THEN it throws with a message naming the searched namespace/category
    and `DocumentService::generateDocument()` is never invoked
  - GIVEN `addHeading`/`addDetailsTable`/`addAmountTable`/`addParagraph`/
    `addNote`/`addCover` WHEN called on a `ReportSection` THEN each produces
    the block shape REQ-RVD-002/REQ-RVD-006 document (same semantic content
    as their prior PhpWord-writing versions, e.g. an empty `addDetailsTable`
    row set still yields the "Geen gegevens beschikbaar." note)
- [x] Implement
- [x] Test

### Task 3: Port the five concrete generators onto `ReportSection`
- **spec_ref**: `openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-001`, `#req-rvd-002`
- **files**: `lib/Reporting/Generator/BalanceSheetReportGenerator.php`,
  `lib/Reporting/Generator/ProfitLossReportGenerator.php`,
  `lib/Reporting/Generator/AnnualAccountsReportGenerator.php`,
  `lib/Reporting/Generator/ManagementLetterReportGenerator.php`,
  `lib/Reporting/Generator/BbvJaarstukkenReportGenerator.php`
- **acceptance_criteria**:
  - GIVEN each file WHEN `grep -n "PhpOffice" <file>` runs THEN it returns
    zero matches, and `build()`'s first parameter is typed `ReportSection`
    (no `PhpWord`/`Section` import remains)
  - GIVEN `BbvJaarstukkenReportGenerator`'s two hand-rolled tables (programme
    list, taakveld list) WHEN rendered through the new `ReportTable` THEN
    they carry the same rows/columns/values as before, with
    `\PhpOffice\PhpWord\Shared\Converter::cmToTwip(N)` calls replaced by
    plain numeric width hints
  - GIVEN each generator's existing OpenRegister loading/classification
    logic (account bucketing, GL-movement derivation, rule-audit
    summarisation, BBV programme/taakveld/fixed-asset aggregation) WHEN
    exercised against the same fixture rows as before THEN it produces
    numerically identical figures — this task changes rendering, not
    business logic
- [x] Implement
- [x] Test

### Task 4: Wire format mapping and the catalogue's `templateId` into discovery; drop `docx`
- **spec_ref**: `openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-003`, `#req-rvd-004`
- **files**: `lib/Reporting/ReportCatalogue.php`, `lib/Reporting/ReportGeneratorInterface.php`
- **acceptance_criteria**:
  - GIVEN `ReportCatalogue::REPORTS` WHEN inspected THEN the five
    document-kind entries' `formats` arrays are `['odt', 'pdf']`
    (previously `['docx', 'odt', 'pdf']`), and each entry's existing
    `templateId` value is unchanged (now consumed by `selectTemplate()`)
  - GIVEN `ReportGeneratorInterface`'s docblock WHEN read THEN it describes
    the docudesk hand-off, not "PHPOffice libraries bundled in OpenRegister"
- [x] Implement
- [x] Test

### Task 5: `ReportGenerationService` — visible, distinguishable docudesk-unavailable outcome
- **spec_ref**: `openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005`
- **files**: `lib/Reporting/ReportGenerationService.php`,
  `lib/Settings/register.d/reporting-generated-report.json`
- **acceptance_criteria**:
  - GIVEN `generate()`'s try/catch around `$generator->generate()` WHEN a
    `DocudeskUnavailableException` is thrown THEN a `GeneratedReport` record
    is saved with `status: 'unavailable'` BEFORE the method returns, and the
    returned array is `{error: 'docudesk-unavailable', reportType, message,
    record}` — distinct from the generic `{error: 'generation-failed'}` path
    for any other `\Throwable`
  - GIVEN `reporting-generated-report.json`'s `GeneratedReport.status` enum
    WHEN inspected THEN it is `['generating', 'ready', 'failed',
    'unavailable']` and the schema `version` is bumped
  - GIVEN a second, unrelated exception type thrown from a generator WHEN
    `generate()` is called THEN the existing `{error: 'generation-failed'}` /
    no-record behaviour is unchanged (regression check)
- [x] Implement
- [x] Test

### Task 6: `ReportingController` — map `docudesk-unavailable` to 503
- **spec_ref**: `openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005`
- **files**: `lib/Controller/ReportingController.php`
- **acceptance_criteria**:
  - GIVEN `generate()`'s existing `if (isset($result['error'])) { return new
    JSONResponse($result, Http::STATUS_UNPROCESSABLE_ENTITY); }` WHEN
    `$result['error'] === 'docudesk-unavailable'` THEN the status code is
    `Http::STATUS_SERVICE_UNAVAILABLE` (503) instead; every other `{error}`
    envelope keeps `422`
- [x] Implement
- [x] Test

### Task 7: Declare the five report templates in `docudesk-templates.json`; hand back content authoring
- **spec_ref**: `openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-006`
- **files**: `lib/Settings/docudesk-templates.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN parsed as JSON THEN it contains the five new
    `templateId` entries listed in REQ-RVD-006, each documenting the block
    vocabulary its docudesk Template must render
  - GIVEN this tasks.md WHEN read THEN no task claims to create the docudesk
    `template`-schema OpenRegister objects themselves — that is explicitly
    out of scope (design.md D5)
- [x] Implement
- [x] Test

### Task 8: Verification sweep
- **spec_ref**: `openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-001`
- **files**: (verification only, no source changes)
- **acceptance_criteria**:
  - GIVEN the finished change WHEN `grep -rn "PhpOffice" lib/` runs THEN it
    returns zero matches
  - GIVEN every changed `.php` file WHEN `php -l` runs against each THEN
    every file reports "No syntax errors detected"
  - GIVEN the reporting namespace's PHPUnit suite WHEN
    `vendor/bin/phpunit -c phpunit-unit.xml --filter Report` runs THEN the
    full tally (not a truncated subset) is read and reported, including the
    new docudesk-absent visible-outcome test
  - GIVEN the hydra gate runner WHEN invoked with
    `HYDRA_GATE_BASE_REF=origin/development` against this branch THEN its
    output is captured and reported (pass/fail per gate, not summarised away)
  - GIVEN `openspec validate reports-via-docudesk --strict` WHEN run THEN it
    passes
- [x] Implement
- [x] Test
