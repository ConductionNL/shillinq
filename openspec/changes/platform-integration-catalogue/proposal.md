---
kind: code
depends_on: [adopt-connection-registry]
---

# Proposal: platform-integration-catalogue

## Summary

A bookkeeper looks for a ready-made link to their bank, payment provider,
web shop or payroll bureau and switches it on from one place. Shillinq only
lists the status of its own fifteen connection families, eleven of them
unavailable. The fleet's integrations live in integriq, which already ships
a connector catalogue. This change gives shillinq an Integrations page that
shows integriq's catalogue items relevant to bookkeeping, with their status
and a link to switch each on in integriq.

## Motivation

One platform row of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26), decided `build`
by the OpenSpec pass of 2026-09-27.

**`plt-marketplace`**, "Pick from a marketplace of ready-made
integrations." Rated no, built state none. Matrix evidence: "no integration
catalogue; ExternalAdaptersStatus /external-adapters lists the 15
connections of lib/Settings/connections.json, of which 11 are marked
unavailable." Four competitors rate it yes:

- exact-online: https://www.exact.com/nl/producten/boekhouden/features-en-prijzen, "In de Exact Online App Store kun je zakelijke software van derde partijen vinden die je direct kunt koppelen", and https://apps.exactonline.com.
- moneybird: https://www.moneybird.nl/product/koppelingen/, a directory with categories Webwinkels, PSP's, Voorraadbeheer, CRM, Kassa.
- snelstart: https://www.snelstart.nl/koppelingen, "Kies uit meer dan 300 koppelingen" (https://www.snelstart.nl/ondernemer/inkaart).
- odoo: https://apps.odoo.com/apps/modules/19.0, the Odoo Apps Store.

The open change `adopt-connection-registry` moves shillinq's connection
status page onto integriq's connection registry; it is a status page, not a
catalogue to choose from.

## Affected Projects

- [ ] Project: `shillinq`: an Integrations page over integriq's catalogue, filtered to bookkeeping categories, next to the connection status page.

## Scope

### In Scope

- An Integrations page of cards: integriq catalogue items in the bank, payments, e-invoicing, tax filing, commerce and payroll categories, with name, description, status and a link to the item in integriq.
- The shillinq connection families that have no catalogue item yet, shown as "not available yet" with the reason from `connections.json`.
- A visible empty state when integriq is not installed.

### Out of Scope

- Adding catalogue items or connectors. They belong to integriq (ADR-091); the missing bookkeeping items are listed for its owner.
- Enabling or instantiating from shillinq. That stays in integriq's catalogue with its authorization (ADR-023).

## Approach

A declarative page over integriq's `catalog_item` objects, the way
`adopt-connection-registry` reads `app_connection`. Details are in design.md.

## New Dependencies

None.

## Impact

- `src/manifest.d/`: one page and a menu entry beside External Connections.
- `lib/Settings/connections.json`: a catalogue item slug per family where one exists.

## Cross-Project Dependencies

- integriq: `catalog_item` objects materialised by `MaterializeCatalogItems` with `category` and live status (`docs/features/connector-catalog.md`, `lib/Service/CatalogRegistryService.php`). Bookkeeping connectors (PSD2 bank feeds, Mollie, Peppol, Digipoort SBR) need catalogue items in integriq; that is integriq's work and is listed in this lane's hand-back, not specified here.

## Risks

### Risk 1: The page shows few cards at first
**Severity:** Medium. **Mitigation:** it shows the families that are coming as "not available yet" with their reason, so the page is honest about what exists.

## Rollback Strategy

Remove the page and its menu entry.

## Open Questions

None.
