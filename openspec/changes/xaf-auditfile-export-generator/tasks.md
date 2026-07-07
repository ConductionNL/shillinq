# Tasks: xaf-auditfile-export-generator

## 1. XAF 3.2 generator
- [ ] 1.1 Add `lib/Reporting/Generator/XafAuditfileGenerator.php` implementing
      `ReportGeneratorInterface` (`id()` → `'xaf'`), `use ReportDataTrait`, built
      byte-native with `XMLWriter` under namespace
      `http://www.auditfiles.nl/XAF/3.2`.
- [ ] 1.2 Emit the mandatory XAF blocks: `header`, `company`, `generalLedger`
      (from `Account`), `customersSuppliers` (from `Payee` + customer master),
      `transactions` (from `GLTransaction` + `GLLine`). Emit empty-but-well-formed
      containers when a block has no rows (mirror `SaftReportGenerator`).
- [ ] 1.3 Filter every source query strictly on the context `administrationId`.

## 2. Streaming export route
- [ ] 2.1 Add an administration-scoped streaming export route + controller action
      that emits the XAF file for the scope returned by `exportScope()`.
- [ ] 2.2 For a full export, bundle the XAF plus the administration's attached
      NC-Files documents into a ZIP (reference/stream from Files — link, don't
      store). Register the route in `appinfo/routes.php` with an explicit auth
      attribute.

## 3. Register the generator
- [ ] 3.1 Register `xaf` alongside `saft` in the reporting generation wiring
      (`ReportGenerationService` / DI), leaving `SaftReportGenerator` untouched.

## 4. Tests
- [ ] 4.1 PHPUnit: generate an XAF for a fixture administration and validate the
      output against the XAF 3.2 XSD (schema-valid, correct namespace).
- [ ] 4.2 PHPUnit: assert the namespace is the Dutch XAF 3.2 URI, not the OECD
      SAF-T URI.
- [ ] 4.3 PHPUnit: two-administration fixture — assert zero cross-administration
      rows in the `WERK-001` export.
- [ ] 4.4 PHPUnit: controller test — the streaming route returns bytes (and a ZIP
      for full export), not just the descriptor.
