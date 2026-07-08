# Change: xaf-auditfile-export-generator

## Why

Shillinq claims to export the Dutch **Auditfile Financieel (XAF 3.2)** but does
not. This is both a readiness-honesty defect (a claim with no backing code, and
a task marked done that was not) and a genuine table-stakes gap for a Dutch
bookkeeping product.

The claim:

- `bookkeeping-multi-administratie` REQ-MA-007 has the scenario **"Full
  administratie export in Auditfile XAF"** — an accountant requests a full,
  administration-scoped export and receives a ZIP "in gestandaardiseerd
  Auditfile XAF-formaat".
- The archived change `2026-06-14-bookkeeping-multi-administratie` ticks
  Task 16 **`[x]` "Implement administratie-scoped export / Auditfile XAF
  generation"**.

The reality (verified at HEAD):

- The only shipped code is `AdministrationController::exportScope()`, which
  returns a **scope descriptor only** — `{format: 'xaf-3.2', includes: [...]}` —
  and never generates a byte. Its own docblock says the export "is bound to this
  single administrationId" without producing one. There is **no `.../export`
  route** that streams a file (`appinfo/routes.php` registers only
  `administration#exportScope`).
- The one XMLWriter audit-file generator that exists,
  `lib/Reporting/Generator/SaftReportGenerator.php`, emits **OECD SAF-T 2.00**
  (`urn:OECD:StandardAuditFile-Tax:2.00`) — a *different* standard from the Dutch
  **XAF 3.2** (`http://www.auditfiles.nl/XAF/3.2`, maintained by the
  Belastingdienst / XBRL Nederland). SAF-T is not a substitute: an accountant or
  the Belastingdienst asking for "het auditfile" in the Netherlands means XAF.
- `openspec/architecture/adr-000-data-model.md` itself admits "the actual XAF
  byte stream … [is] deferred to a follow-up cycle against a live OpenRegister
  instance."

XAF export is exactly what an external accountant needs to take the books into
their own package (and pairs with the `shillinq-accountant-portal-audience`
change, which makes that accountant a first-class user). This change makes the
claim true.

## What Changes

- **ADDED** `REQ-MA-011` to `bookkeeping-multi-administratie` — Shillinq SHALL
  ship a real XAF 3.2 (Auditfile Financieel) export generator that produces a
  valid, correctly-namespaced XAF document scoped to a single administration, and
  a streaming administration-export route that fulfils the REQ-MA-007 "Full
  administratie export in Auditfile XAF" scenario. The OECD SAF-T generator is
  explicitly NOT the Dutch XAF and MUST NOT be conflated with it.
- New `lib/Reporting/Generator/XafAuditfileGenerator.php` (id `xaf`), built
  byte-native with `XMLWriter` mirroring `SaftReportGenerator`'s pattern and
  `ReportDataTrait`, assembling the XAF `header` / `company` /
  `generalLedger` (from `Account`) / `customersSuppliers` (from `Payee` +
  customer master) / `transactions` (from `GLTransaction` + `GLLine`) blocks
  under the `http://www.auditfiles.nl/XAF/3.2` namespace.
- New administration-scoped streaming export route/controller action that emits
  the XAF file — and, per the scenario, a ZIP bundling the XAF plus the
  administration's attached NC-Files documents — enforcing `administrationId`
  data isolation.
- `AdministrationController::exportScope()`'s `format: 'xaf-3.2'` descriptor now
  resolves to real bytes.

## Impact

- Affected spec: `bookkeeping-multi-administratie` (ADDED `REQ-MA-011`; the
  REQ-MA-007 XAF scenario becomes backed).
- Affected code: one new generator class, one export route + controller action;
  the SAF-T generator is untouched (it stays as the OECD SAF-T surface).
- Corrects a falsely-ticked task (archived change Task 16) — this change is its
  honest completion.
- Out of scope: incremental backup and the 7-year archival read-only mode
  (REQ-MA-007's other scenarios) — those are separate and already partly
  covered; this change is XAF export only.
