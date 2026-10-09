---
kind: code
depends_on: []
---

# Proposal: reports-via-docudesk

## Summary

`lib/Reporting/Generator/AbstractDocumentReportGenerator.php` and its five
concrete subclasses (`BalanceSheetReportGenerator`, `ProfitLossReportGenerator`,
`AnnualAccountsReportGenerator`, `ManagementLetterReportGenerator`,
`BbvJaarstukkenReportGenerator`) `use PhpOffice\PhpWord\{Element\Section,
IOFactory, PhpWord}` and construct `new PhpWord()` to build DOCX/ODT/PDF
documents in-process, using dompdf for the PDF path. `phpoffice/phpword` is in
neither shillinq's `composer.json` require NOR its `vendor/` — the code only
resolves today because OpenRegister's and docudesk's vendor directories happen
to be autoloaded on the same PHP instance. ADR-075 D3 bans "loading another
app's vendor directory" by name and already lists shillinq's `Reporting/`
stack as a known violation; ADR-087 extends the same one-channel principle to
office-suite interaction generally. This change removes PhpWord from `lib/`
entirely and re-implements the five document generators as docudesk
consumers: each one assembles a structured report body (the data-aggregation
logic is unchanged) and hands it to docudesk's `DocumentService::
generateDocument()` for rendering, mirroring the hrmq `payslip-pdf-docudesk`
consumption pattern ("hrmq assembles data, docudesk renders — no Dompdf/Twig
in hrmq").

## Motivation

1. **Fragile by accident, not by contract.** The current code has no
   composer/vendor relationship to PhpWord or dompdf at all — it works only
   because two unrelated apps' vendor trees happen to be co-loaded. Neither
   `composer audit` nor ADR-093's dependency cooldown can see this surface,
   because from shillinq's own dependency graph it does not exist. A version
   bump, an uninstall, or a reorder of either app breaks report generation
   with no warning to anyone who reads shillinq's own manifest.
2. **The failure mode was silent.** `ReportGenerationService::generators()`
   discovers generators by glob-and-instantiate and catches `\Throwable` at
   instantiation time, logging a warning and simply omitting the generator
   from the discovered set. If the cross-app vendor reach ever stops
   resolving, the five reports do not error loudly — they vanish from the
   catalogue and the caller gets a generic `no-generator` 422 with no
   indication that docudesk (the fleet's actual document-generation owner)
   was never consulted.
3. **The correct pattern is already proven in the fleet.** hrmq's
   `HrDocumentService` (spec `hrmq-docudesk-documents` / `payslip-pdf-
   docudesk`) is the reference consumer: duck-typed, same-instance resolution
   of `OCA\DocuDesk\Service\{DocumentService,TemplateService}` by string FQCN,
   config-first/discovery-second/fail-closed template selection, and an
   explicit `skipped-no-docudesk` outcome when docudesk is absent — never a
   silent drop. This change ports that pattern into shillinq's reporting
   subsystem.

## Non-Goals

- Authoring the five docudesk-side Twig/HTML `namespace: "shillinq"` Template
  objects themselves. hrmq's own reference change explicitly treats "seeding
  starter templates into docudesk" as a follow-up cross-app write, not part
  of the consumption leaf — this change follows the same precedent. It ships
  the consumption leaf (generators + fail-closed discovery + visible
  degradation) and the `lib/Settings/docudesk-templates.json` declaration
  docudesk template authoring is scoped against; template content authoring
  is handed back explicitly (see design.md).
- Building ADR-075's fleet-wide "published PHP contract" (interface package /
  capability registry). ADR-075 is `Proposed`, not `Accepted`, and no such
  contract exists in docudesk today — hrmq's own shipped implementation
  duck-types by FQCN, same as this change. Removing the OR-vendor-dir-reach
  violation (the specific thing ADR-075 D3 bans by name) is in scope;
  publishing a versioned contract for the whole fleet is not.
- Changing the six OTHER report generators in `lib/Reporting/Generator/`
  (SAF-T, XAF, trial balance, general ledger, IV3, VAT return, rule audit) —
  they are "data" kind (native XML/CSV/XBRL), never touched PhpWord, and are
  out of this defect's blast radius.
