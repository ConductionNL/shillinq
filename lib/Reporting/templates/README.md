<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

# Shillinq document-report templates

The DOCUMENT report generators
(`lib/Reporting/Generator/*ReportGenerator.php`) build their PhpWord document
from a **default layout defined entirely in code** — see
`AbstractDocumentReportGenerator::newDocument()` and the `add*` build helpers.
A fresh shillinq install therefore renders every jaarrekening, balans,
winst-en-verliesrekening, management letter and BBV-jaarstuk **without any
external template file present in this directory**.

This directory is the home for *optional* sample template assets. It is empty by
design on a clean install; the catalogue `templateId` values below identify the
in-code layouts and are the names a customised template would adopt when one is
authored (templates are customised in docudesk, never required here):

| Report type        | Catalogue `templateId`       | In-code builder                       |
| ------------------ | ---------------------------- | ------------------------------------- |
| `annual-accounts`  | `shillinq-jaarrekening`      | `AnnualAccountsReportGenerator`       |
| `balance-sheet`    | `shillinq-balans`            | `BalanceSheetReportGenerator`         |
| `profit-loss`      | `shillinq-winst-verlies`     | `ProfitLossReportGenerator`           |
| `management-letter`| `shillinq-management-letter` | `ManagementLetterReportGenerator`     |
| `bbv-jaarstukken`  | `shillinq-bbv-jaarstukken`   | `BbvJaarstukkenReportGenerator`       |

## Rendering pipeline

A single PhpWord document drives all three writers, all bundled in OpenRegister
(no office/PDF dependency is added to shillinq's `composer.json`):

- **DOCX** — `IOFactory::createWriter($phpWord, 'Word2007')`
- **ODT**  — `IOFactory::createWriter($phpWord, 'ODText')`
- **PDF**  — `Settings::setPdfRendererName(Settings::PDF_RENDERER_DOMPDF)` +
  `Settings::setPdfRendererPath(<bundled dompdf dir>)` then
  `IOFactory::createWriter($phpWord, 'PDF')`

Figures are pulled from the real OpenRegister objects in the `shillinq` register
via `ObjectService`: `FinancialStatement` (jaarrekening), `Account` + `GLLine`
(balans / W&V), `BbvStatement` (BBV-jaarstukken) and `RuleAuditService`
compliance findings (management letter).
