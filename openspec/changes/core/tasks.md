# Tasks: Core Platform

## Requirements

### REQ-CORE-001: OpenRegister Register Definition [must]

Define all 14 Shillinq schemas in `lib/Settings/shillinq_register.json` using OpenAPI 3.0 format with `x-openregister` extension.

**GIVEN** the app is installed on a Nextcloud instance with OpenRegister
**WHEN** the repair step runs
**THEN** all 14 schemas (Organization, FiscalYear, Account, JournalEntry, SalesInvoice, PurchaseInvoice, PurchaseOrder, Contract, BankStatement, Budget, FinancialReport, BBVAccount, SpendReport, AITask) are available in the `shillinq` register

**Test data:** Fresh Nextcloud installation with OpenRegister ≥ 0.2.10

---

### REQ-CORE-002: Repair Step — Register Import [must]

`InitializeSettings` repair step imports the register definition idempotently.

**GIVEN** a fresh Nextcloud installation
**WHEN** the app is enabled (or upgraded)
**THEN** `ConfigurationService::importFromApp('shillinq')` is called and completes without error, schemas are queryable via OpenRegister REST API

**GIVEN** the repair step has already run
**WHEN** the app is upgraded again
**THEN** the repair step runs without throwing an error and does not create duplicate schemas

---

### REQ-CORE-003: Settings API [must]

`SettingsController` exposes GET and POST endpoints for reading and writing app configuration.

**GIVEN** an authenticated Nextcloud admin
**WHEN** `GET /index.php/apps/shillinq/api/settings` is called
**THEN** the response includes `{ openRegisters: true, isAdmin: true, register: "shillinq", ... }`

**GIVEN** an authenticated non-admin user
**WHEN** `GET /index.php/apps/shillinq/api/settings` is called
**THEN** the response includes `{ openRegisters: true, isAdmin: false }` (no sensitive config)

**GIVEN** an authenticated admin
**WHEN** `POST /index.php/apps/shillinq/api/settings` is called with `{ "register": "my-register" }`
**THEN** the setting is persisted and returned on the next GET

---

### REQ-CORE-004: App.vue — OpenRegister Gate [must]

The app shell shows an empty state if OpenRegister is not installed, and the full UI when it is.

**GIVEN** OpenRegister is NOT installed
**WHEN** a user opens the Shillinq app
**THEN** `NcEmptyContent` is shown with a message directing them to install OpenRegister; navigation is not shown

**GIVEN** OpenRegister IS installed
**WHEN** a user opens the Shillinq app
**THEN** the main navigation and dashboard are shown

---

### REQ-CORE-005: Dashboard [must]

`Dashboard.vue` uses `CnDashboardPage` with four KPI cards and a status donut chart.

**GIVEN** a user with at least one open SalesInvoice and one Budget
**WHEN** the dashboard loads
**THEN** all four KPI cards display correct counts/amounts: open verkoopfacturen, openstaand crediteuren, budget resterend, te verwerken bankafschriften

**GIVEN** zero objects exist in any schema
**WHEN** the dashboard loads
**THEN** all KPI cards show 0 and the chart shows an empty state, without throwing errors

---

### REQ-CORE-006: Sidebar Navigation [must]

`MainMenu.vue` renders collapsible domain sections with route links.

**GIVEN** any authenticated user
**WHEN** the app loads
**THEN** `NcAppNavigation` shows sections: Debiteuren, Crediteuren, Boekhouding, Inkoop & Contracten, Planning & Rapportages, Stamgegevens — each with the correct child routes

**GIVEN** the user is on the Verkoopfacturen list page
**WHEN** they look at the navigation
**THEN** the Verkoopfacturen item is highlighted as active

---

### REQ-CORE-007: Entity List Views [must]

Each entity type has a `CnIndexPage` list view with sorting, pagination, and search.

**GIVEN** 50 SalesInvoice objects exist
**WHEN** the user navigates to `/verkoopfacturen`
**THEN** a paginated list is shown, defaulting to 20 items per page, sortable by `factuurnummer`, `factuurdatum`, `status`, `totaalInclBtw`

**GIVEN** the user types "Gemeente" in the search bar
**WHEN** the search debounces (300 ms)
**THEN** only invoices matching "Gemeente" in any indexed field are shown

**GIVEN** the user selects "Vervallen" in the status filter
**WHEN** the filter is applied
**THEN** only SalesInvoice objects with `status = vervallen` are shown

---

### REQ-CORE-008: Entity Detail Views [must]

Each entity has a `CnDetailPage` with labelled fields, related entities, and `CnObjectSidebar`.

**GIVEN** the user clicks on a SalesInvoice in the list
**WHEN** the detail page loads
**THEN** all fields are displayed in labelled `CnDetailCard` sections, and the sidebar shows Files, Notes, and Audit Trail tabs

**GIVEN** the user opens the Audit Trail tab on an edited JournalEntry
**WHEN** the tab renders
**THEN** all previous versions with before/after snapshots are shown (provided by OpenRegister — no custom code)

---

### REQ-CORE-009: Entity Create/Edit Forms [must]

`CnFormDialog` auto-generates forms from each schema definition.

**GIVEN** the user clicks "Nieuw" on the Contracten list page
**WHEN** the form dialog opens
**THEN** all required fields (`referentie`, `titel`, `startdatum`, `status`) are shown with correct input types; saving creates a new Contract object in OpenRegister

**GIVEN** the user edits a PurchaseInvoice and sets `status` to `goedgekeurd`
**WHEN** they save
**THEN** the object is updated, the list view reflects the new status, and an audit trail entry is created automatically

**GIVEN** a required field is left empty
**WHEN** the user tries to save
**THEN** an inline validation error is shown for each missing required field; the dialog does not close

---

### REQ-CORE-010: Admin Settings Page [must]

`Settings.vue` shows `CnVersionInfoCard` (first), `CnRegisterMapping`, and configuration sections.

**GIVEN** an admin opens the settings page
**WHEN** the page loads
**THEN** `CnVersionInfoCard` is the first element; `CnRegisterMapping` allows linking schemas to a custom register; a "Re-importeer register" button is visible

**GIVEN** the admin clicks "Re-importeer register"
**WHEN** the action completes
**THEN** `POST /api/settings/load` is called, a success notification is shown, and schemas are refreshed

---

### REQ-CORE-011: Seed Data [must]

Seed data is importable via OpenRegister and provides a realistic demo environment.

**GIVEN** a fresh installation
**WHEN** the admin imports the seed data JSON from the design.md
**THEN** organizations, fiscal years, accounts, invoices, contracts, and budgets appear in the respective list views with correct field values

**GIVEN** the seed data has been imported
**WHEN** the dashboard loads
**THEN** all four KPI cards show non-zero values reflecting the seed objects

---

### REQ-CORE-012: Global Search [should]

Full-text search across all entity types via OpenRegister `IndexService`.

**GIVEN** seed data is loaded
**WHEN** the user searches for "Gemeente Westerkwartier" in the global search bar
**THEN** results include the matching Organization and any SalesInvoice linked to that organization

---

### REQ-CORE-013: Faceted Filtering [should]

Per-schema facets are available on all list views via `CnFacetSidebar`.

**GIVEN** the user is on the SalesInvoice list page
**WHEN** they open the filter sidebar
**THEN** facets for `status`, `boekjaarId`, and `valuta` are shown with record counts per value

---

### REQ-CORE-014: CSV Import [should]

`CnMassImportDialog` allows bulk import of any entity type from CSV.

**GIVEN** the user has a CSV file with 100 Organization records
**WHEN** they upload it via the "Importeer" button
**THEN** the import wizard maps CSV columns to schema fields, validates, and creates objects; a summary shows success/error counts

---

### REQ-CORE-015: CSV/Excel Export [should]

`CnMassExportDialog` exports the current list view to CSV or Excel.

**GIVEN** the user has filtered the SalesInvoice list to show only `vervallen` invoices
**WHEN** they click "Exporteer"
**THEN** a dialog offers CSV and Excel formats; the export contains exactly the filtered records with selected columns

---

### REQ-CORE-016: Nextcloud Notifications [should]

`NotificationService` triggers notifications for invoice due, contract expiry, and budget overshoot.

**GIVEN** a SalesInvoice has `vervaldatum` = today and `status` = `verzonden`
**WHEN** the background job runs
**THEN** AR admin users receive a Nextcloud notification: "Verkoopfactuur 2026-0043 is vervallen"

**GIVEN** a Contract has `einddatum` within `opzegtermijnDagen` days
**WHEN** the background job runs
**THEN** contract managers receive a Nextcloud notification: "Contract CNT-2024-007 verloopt over 14 dagen"

---

## Implementation Tasks

### Phase A — Backend Foundation

- [ ] **A1** — Replace placeholder schema in `lib/Settings/shillinq_register.json` with all 14 production schemas (Organization, FiscalYear, Account, JournalEntry, SalesInvoice, PurchaseInvoice, PurchaseOrder, Contract, BankStatement, Budget, FinancialReport, BBVAccount, SpendReport, AITask) per the data model in `design.md`
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-001`
- [ ] **A2** — Verify `lib/Repair/InitializeSettings.php` correctly calls `ConfigurationService::importFromApp('shillinq')` and is registered in `lib/AppInfo/Application.php`
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-002`
- [ ] **A3** — Update `SettingsController` to return `openRegisters`, `isAdmin`, and all relevant config keys; add `POST /api/settings/load` endpoint
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-003`
- [ ] **A4** — Add `NotificationService` wrapping `IManager`; register four notification types (invoice overdue, contract expiring, budget exceeded, invoice awaiting approval)
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-016`
- [ ] **A5** — Add background job (`OCP\BackgroundJob\TimedJob`) that triggers notification events daily
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-016`
- [ ] **A6** — Write PHPUnit unit tests for `SettingsService` and `NotificationService` (≥ 3 test methods each)
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-003`

### Phase B — Frontend Store & Router

- [ ] **B1** — Update `src/store/store.js` → `initializeStores()` to register all 14 entity types via `objectStore.registerObjectType()`
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-007`
- [ ] **B2** — Update `src/router/index.js` with named routes for all entity list and detail views; add catch-all redirect to `/`
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-007`
- [ ] **B3** — Update `src/App.vue` to show `NcLoadingIcon` during init, `NcEmptyContent` if `openRegisters = false`, and full UI otherwise; inject `sidebarState`
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-004`

### Phase C — Navigation

- [ ] **C1** — Rewrite `src/navigation/MainMenu.vue` with `NcAppNavigation` and six domain sections (Debiteuren, Crediteuren, Boekhouding, Inkoop & Contracten, Planning & Rapportages, Stamgegevens), each with correct child `NcAppNavigationItem` entries and route links
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-006`

### Phase D — Dashboard

- [ ] **D1** — Rewrite `src/views/Dashboard.vue` using `CnDashboardPage`; fetch open SalesInvoice, PurchaseInvoice, Budget, and BankStatement counts in parallel via `Promise.all`; render 4 `CnStatsBlock` KPI cards + `CnChartWidget` (donut: SalesInvoice status distribution) + recent JournalEntry table
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-005`

### Phase E — Entity Views (repeat for each entity type)

For each entity: Organization, FiscalYear, Account, JournalEntry, SalesInvoice, PurchaseInvoice, PurchaseOrder, Contract, BankStatement, Budget, FinancialReport:

- [ ] **E1.x** — Create `src/views/{entity}/{Entity}Index.vue` using `CnIndexPage` + `useListView`; configure columns via `columnsFromSchema()`, filters via `filtersFromSchema()`
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-007`
- [ ] **E2.x** — Create `src/views/{entity}/{Entity}Detail.vue` using `CnDetailPage` + `CnDetailCard` sections + `CnObjectSidebar`; handle `isNew = entityId === 'new'` mode
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-008`

### Phase F — Settings Page

- [ ] **F1** — Update `src/views/settings/Settings.vue`: `CnVersionInfoCard` (first) → `CnRegisterMapping` → `CnSettingsSection` for app config; add "Re-importeer register" button calling `POST /api/settings/load`
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-010`

### Phase G — Seed Data

- [ ] **G1** — Create `openspec/changes/core/seed/` directory with one JSON file per schema (organizations.json, fiscal-years.json, accounts.json, sales-invoices.json, purchase-invoices.json, contracts.json, budgets.json) containing the seed objects from `design.md`
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-011`
- [ ] **G2** — Document the import procedure in `docs/SEED_DATA.md` (drag-drop into OpenRegister import, or CLI command)
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-011`

### Phase H — Translations

- [ ] **H1** — Add Dutch (`l10n/nl.js` + `l10n/nl.json`) and English (`l10n/en.js` + `l10n/en.json`) translation strings for all user-visible labels introduced in Phases C–F
  - `@spec openspec/changes/core/tasks.md#REQ-CORE-004`

### Phase I — Quality

- [ ] **I1** — Run `composer check:strict` and fix all PHPCS, PHPMD, Psalm, and PHPStan errors
- [ ] **I2** — Run `npm run lint` and fix all ESLint and Stylelint errors
- [ ] **I3** — Verify all `<style>` blocks use `scoped` attribute (ADR-010)
- [ ] **I4** — Verify no hardcoded colors or hex values in CSS (ADR-010); all styling uses NL Design System CSS variables
- [ ] **I5** — Verify WCAG AA compliance: keyboard navigation works on all list/detail views, all form fields have labels, no color-only status indicators

## Deduplication Check (ADR-012)

| Check | Result |
|---|---|
| `ObjectService` CRUD — custom? | No — using `CnIndexPage` / `CnDetailPage` |
| Import/export — custom? | No — `CnMassImportDialog` / `CnMassExportDialog` |
| Search — custom? | No — `IndexService` + `CnFilterBar` |
| Faceted filters — custom? | No — `CnFacetSidebar` |
| Audit trail — custom? | No — `CnObjectSidebar` + `CnAuditTrailTab` |
| Dashboard layout — custom? | No — `CnDashboardPage` |
| Notification dispatch — custom? | Trigger logic only; `IManager` wrapping is minimal |
| Pagination / list state — custom? | No — `useListView` composable |
| Store / Pinia — custom? | No — `createObjectStore` |
