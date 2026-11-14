# Design: platform-integration-catalogue

Read at shillinq development `79f438f33` and integriq development on
2026-09-27.

## Context

- **shillinq's connections.** `lib/Settings/connections.json` lists fifteen families (`digipoort-sbr`, `salarisbureau`, `rvo`, `ib47`, `cbs-bestanden`, `cbs-iv3`, `bzk-sisa`, `mollie`, `bunq`, `kvk`, `uwv`, `treasury-rates`, `ccm-rule-engine`, `csrd-esrs-xbrl`, `deposit-payment`), eleven with `available: false`. The status page `ExternalAdaptersStatus` shows them; the open change `adopt-connection-registry` moves it onto integriq's `app_connection` register as an `index` page preset to `app=shillinq`.
- **integriq's catalogue.** integriq's Catalog page (`/catalog`) lists `catalog_item` objects materialised on every upgrade by `lib/Repair/MaterializeCatalogItems.php` from its adapters, seeded source templates and configuration templates, each with a `category` (`lib/Service/CatalogRegistryService.php`), a live status (available or dormant, `GET /api/catalog/items/{id}/status`) and Enable or Instantiate actions gated by the `catalog.instantiate` action (ADR-023). Its categories today are government and document oriented (Geo / Maps, Government messaging, ...).

## Goals / Non-Goals

**Goals**
- One page in shillinq to find ready-made integrations for bookkeeping and see which are on.

**Non-Goals**
- New connectors, switching them on from shillinq.

## Decisions

### D1. A card page over integriq's objects

`Integrations` page (route `/integrations`, menu entry in the settings
foldout beside External Connections) is an index over the integriq
register's `catalog_item` schema in card layout, with a default filter on
the categories `Bank`, `Payments`, `E-invoicing`, `Tax filing`, `Commerce`
and `Payroll`. Each card shows name, description, category and status and
links to the item's detail in integriq's Catalog page. The page is declared
in `src/manifest.d/` the way `adopt-connection-registry` declares its page
over `app_connection`, with `requires` naming integriq so the shell shows an
empty state without it.

### D2. Families without an item say so

`connections.json` gains an optional `catalogItem` slug per family. A small
section under the cards lists families without a matching item as "not
available yet", with the family's existing `note`. When integriq adds an
item, the slug is filled and the family moves into the cards.

Alternative considered: a shillinq-owned catalogue schema. Rejected: two
catalogues of one fleet's integrations is one too many (ADR-091, ADR-022).

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| The page | Declarative: a manifest index page over another app's schema | Page configuration. |
| Families without an item | Declarative: data in `connections.json` rendered by the page | No code. |

## Seed Data

No schema is added or changed. With integriq's catalogue as it stands, the
page lists no bookkeeping card yet and fifteen families as not available
yet; after integriq adds a Mollie item, `mollie` gets `catalogItem: mollie`
and shows as a card with status dormant until configured.

## Risks / Trade-offs

- [integriq's category names change] → the filter lives in page config and is one edit.

## Migration Plan

None.

## Open Questions

None.
