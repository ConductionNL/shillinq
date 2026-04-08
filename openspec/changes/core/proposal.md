---
status: proposed
source: specter
features: [dashboard, openregister-schemas, sidebar-navigation, entity-list-views, entity-detail-views, entity-forms, global-search, admin-settings, seed-data, faceted-filtering, breadcrumb-navigation, nextcloud-notifications, csv-import, csv-export, user-preferences]
---

# Core — Shillinq

## Summary

Implements the foundational infrastructure for Shillinq: all OpenRegister schema definitions, the Nextcloud-style UI shell (sidebar, breadcrumbs, dashboard), reusable entity CRUD patterns (list, detail, form), global search, admin settings, seed data, CSV import/export, faceted filtering, user preferences, and Nextcloud notification integration. This change establishes the complete application skeleton that all domain-specific modules (invoicing, bookkeeping, procurement, contracts) build on.

## Demand Evidence

All 15 features in this change carry a market demand score of 1,000 and are classified as `core` in the Specter intelligence model. Every downstream module — accounts payable, invoicing, procurement, contracts — depends on these foundations being in place first.

Key stakeholder pain points addressed:

- **Head of Finance**: needs reliable audit-trail drill-down and fast data access — global search and entity detail views with tabbed sections address this directly.
- **Accounts Payable Clerk**: processes high volume of invoices — CSV import, faceted filtering, and entity list views with sorting/pagination reduce manual effort.
- **Chief Procurement Officer**: needs spend visibility and compliance dashboards — dashboard overview cards and admin settings for app-wide configuration deliver this.

## What Shillinq Already Has

The repository is bootstrapped from the Conduction Nextcloud app starter template. The following is already in place:

- OpenRegister-based thin-client architecture (no own DB tables)
- PHP AppFramework skeleton (`lib/`, `appinfo/`, `templates/`)
- Vue 2.7 + Pinia frontend scaffolding (`src/`)
- `lib/Settings/` with a basic register definition placeholder
- CI/CD configuration (`app-config.json`, GitHub Actions)

### What Is Missing

- No OpenRegister schemas defined for any entity
- No dashboard, sidebar, or navigation components
- No entity list, detail, or form views
- No global search integration
- No admin settings UI
- No seed data
- No CSV import or export
- No faceted filtering
- No user preferences
- No Nextcloud notification hooks

## Scope

### In Scope

1. **OpenRegister schema definitions** — JSON Schema definitions for `Organization`, `AppSettings`, `Dashboard`, and `DataJob` registered via `lib/Settings/ShillinqRegister.json` and imported via `ConfigurationService` on repair step
2. **Dashboard** — main landing page (`src/views/dashboard/`) with summary cards (open invoices, pending approvals, recent activity), quick action buttons, and NL Design System theme support; Pinia store `src/store/modules/dashboard.js`
3. **Sidebar navigation** — Nextcloud-style left sidebar with collapsible sections for each app domain (Bookkeeping, Invoicing, Procurement, Contracts, Settings); badge counts via OpenRegister object counts
4. **Entity list views** — reusable `CnIndexPage`-based list pages with column sorting, pagination, row actions (view, edit, delete), and sticky header; per-entity views in `src/views/{entity}/`
5. **Entity detail views** — `CnDetailPage`-based detail pages with header metadata block, tabbed sections (Details, History, Related), and contextual action bar
6. **Entity create/edit forms** — `CnFormDialog`-based forms generated via `fieldsFromSchema()` with client-side required-field validation, field type coercion, and save/cancel actions
7. **Global search** — Nextcloud search provider (`lib/Search/ShillinqSearchProvider.php`) querying all registered schemas via OpenRegister's search API; returns typed results with entity icons
8. **Admin settings page** — Nextcloud admin section (`lib/Settings/AdminSettings.php`) with app-wide configuration: default currency, fiscal year start, OpenRegister register references, and feature toggles
9. **Seed data** — demo records for all four schemas (ADR-016): 1 Organization, 3 AppSettings, 1 Dashboard, and representative DataJob entries; loaded via `lib/Migration/SeedData.php` repair step
10. **Faceted filtering** — filter sidebar or chip strip on all list views using `filtersFromSchema()`; URL-encoded filter state for shareable filtered views
11. **Breadcrumb navigation** — context-aware breadcrumbs rendered by a shared `ShillinqBreadcrumb` Vue component; uses `vue-router` route meta to build the path
12. **Nextcloud notification integration** — `lib/Notification/ShillinqNotifier.php` implementing `INotifier`; initial notifications for DataJob completion (import finished/failed) and admin-configurable events
13. **CSV import** — `DataJob`-backed CSV upload flow: file picker, column mapper (auto-detect headers), validation preview (first 10 rows), background import job; supported for `Organization` entity as the reference implementation
14. **CSV/Excel export** — export current filtered list view to CSV or XLSX; implemented in `lib/Controller/ExportController.php` using PHP's native CSV functions and a lightweight XLSX writer; no new Composer packages
15. **User preferences** — per-user settings stored as `AppSettings` objects scoped to `userId`: language, date format (`DD-MM-YYYY` / `YYYY-MM-DD` / `MM/DD/YYYY`), and notification opt-in per event type; preferences UI in `src/views/appSettings/`

### Out of Scope

- Domain-specific entities (invoices, purchase orders, contracts, ledger accounts) — covered in separate changes
- Bank reconciliation, VAT reporting, financial statements — downstream changes
- Multi-currency conversion rates — deferred
- OAuth / SSO configuration — Nextcloud handles authentication
- PDF generation — separate change

## Acceptance Criteria

1. GIVEN a new Shillinq installation WHEN the admin enables the app THEN the OpenRegister register is created with all four schemas and seed data is loaded automatically
2. GIVEN a logged-in user WHEN they open Shillinq THEN the dashboard shows summary cards, the sidebar lists all app sections, and the page title shows a breadcrumb
3. GIVEN an Organization list view with 50 records WHEN the user sorts by name and filters by country = "NL" THEN only matching records appear sorted correctly and pagination reflects the filtered count
4. GIVEN a user on the Organization list view WHEN they click "New" THEN a form dialog opens with all schema-defined fields, required fields marked, and save is blocked until required fields are filled
5. GIVEN an admin on the settings page WHEN they change the default fiscal year start THEN the new value is persisted as an AppSettings object and takes effect immediately across the UI
6. GIVEN a user in the search bar WHEN they type "Acme" THEN results from all schemas containing "Acme" appear grouped by entity type with icons and direct links
7. GIVEN a user uploading a CSV of 100 organizations WHEN the import completes THEN a Nextcloud notification is delivered with the import summary (processed / failed counts) and a link to the DataJob detail
8. GIVEN a filtered list view with 30 records WHEN the user clicks "Export CSV" THEN a CSV file is downloaded containing exactly the 30 filtered records with all visible columns
9. GIVEN a user who sets their date format preference to DD-MM-YYYY WHEN they view any entity detail page THEN all dates are displayed in DD-MM-YYYY format
10. GIVEN an entity detail page WHEN the user clicks a breadcrumb segment THEN they are navigated to the correct parent list view

## Risks and Dependencies

- **OpenRegister availability**: Shillinq is fully dependent on OpenRegister being installed and its `ObjectService` API being accessible. The UI should show a clear gate/error if OpenRegister is missing.
- **`@conduction/nextcloud-vue` version**: `CnIndexPage`, `CnDetailPage`, and `CnFormDialog` must match the version in `package.json`. Verify component APIs before wiring up `columnsFromSchema()` / `fieldsFromSchema()`.
- **Seed data idempotency**: The repair step must be idempotent — re-running it on an existing installation must not duplicate seed records. Use `findOrCreate` logic keyed on a stable slug/key field.
- **CSV XLSX export without new packages**: A lightweight XLSX writer using only PHP's built-in `ZipArchive` and `XMLWriter` is feasible but requires careful testing with large datasets (>10,000 rows).
- **Global search performance**: OpenRegister's search API is queried per-schema; with many schemas the search provider must fan out efficiently or use a combined search endpoint if available.
