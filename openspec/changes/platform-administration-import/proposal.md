---
kind: code
depends_on: []
---

# Proposal: platform-administration-import

## Summary

Shillinq can parse an XAF auditfile and profiles for five packages, and it can stage, validate, dry-run, post and reverse an import, but no screen or transition runs any of it. This change wires the ImportBatch lifecycle to the pipeline and builds the import wizard, so a bookkeeper moves an administration over from another package in one guided run.

## Motivation

The build-all pass of 2026-09-29 decided `build` for these `building` rows of
the shillinq capability matrix (`openspec/parity/capabilities.json`), because
no change covered their missing half. Each row keeps `built.state: building`
with `built.change` naming this change until it ships.

**`plt-import`**, "Move an administration over from another bookkeeping package." Shillinq rated partial, built state `building`. The matrix evidence for the built half: "src/manifest.d/administration-import-migration.json: the ImportWizard page is marked x-deferred, its component does not exist and it renders an empty state; ImportBatches and ImportMappings index pages remain"

- exact-online (yes): https://www.exact.com/nl/producten/overstappen: "Of je nu al een Exact-pakket gebruikt ... of de overstap maakt vanaf een ander softwarepakket ... onze consultants staan voor je klaar" and conversion tools; https://www.exact.com/nl/producten/boekhouden/features-en-prijzen: "Importeer en exporteer data in XML en CSV"
- snelstart (yes): https://kennisplein.snelstart.nl/klanten/s/article/overstappen-naar-snelstart : 'In SnelStart 12 kun je klanten, leveranciers, artikelen, boekingen en grootboekrekeningen importeren' from Excel/CSV; the article also covers 'Een ander administratiebestand importeren in SnelStart (of een auditfile .xaf)' Note: SnelStart 12 only ('niet beschikbaar in SnelStart Web'); consultants offer migration help
- twinfield (yes): https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/starten-met-twi-3041256: "Bij een conversie met de conversietool kan worden gekozen voor conversie vanuit een auditfile, of vanuit een API koppeling"; paid conversion service in https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/abonnementen-en-3041270

## What is already built

- `lib/Service/Import/ImportPipelineService.php`: `stage`, `resolveMappings`, `validate`, `dryRun`, `post`, `reverse`, idempotency keys; `AuditfileParser.php`; profiles `XafGenericProfile`, `EBoekhoudenProfile`, `ExactOnlineProfile`, `MoneybirdProfile`, `SnelstartProfile` (`lib/Service/Import/ImportProfile/`).
- `ImportBatch` and `ImportMapping` with a ten-transition lifecycle (`lib/Settings/register.d/administration-import-migration.json`); only `parse` and `reverse` have a guard (`lib/Lifecycle/ImportBatchGuard.php`), and no transition calls the pipeline.
- `ImportBatches` and `ImportMappings` index pages; the `ImportWizard` page is marked `x-deferred` and renders an empty state (`src/manifest.d/administration-import-migration.json`).

## What this change adds

- Declared lifecycle actions: `parse` stages the files (then `staged` or a parse error), `validate` writes the validation report and moves to `validated` or `validation_failed`, `dryRun` writes the dry-run report, `post` posts and moves to `posted` or `posting_failed`, `reverse` reverses.
- `ImportWizard.vue`: pick files from Nextcloud Files, choose the source package, review mappings, see validation findings (errors block), see the dry-run, post. Each step drives one transition on the same `ImportBatch`.
- The `x-deferred` marker is removed; the page is reachable from the settings menu.
