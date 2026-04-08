---
status: proposed
---

# Core — Shillinq

## Purpose

Defines the foundational requirements for Shillinq's application shell, data layer, and reusable UI patterns. Every domain module (invoicing, bookkeeping, procurement, contracts) depends on these core capabilities. The spec covers OpenRegister schema registration, dashboard, navigation, entity CRUD patterns, global search, admin settings, seed data, import/export, filtering, and notifications.

Stakeholders: Head of Finance, Accounts Payable Clerk, Chief Procurement Officer.

## Requirements

### REQ-CORE-001: OpenRegister Schema Registration [must]

The app MUST register all entity schemas in OpenRegister via `lib/Settings/ShillinqRegister.json` (OpenAPI 3.0.0 format) and import them during the repair step using `ConfigurationService::importFromApp()`. Each schema MUST use a `schema.org` vocabulary type annotation and include all properties from the data model.

**Scenarios:**

1. **GIVEN** Shillinq is installed **WHEN** the Nextcloud repair step runs **THEN** a register named `shillinq` is created in OpenRegister containing schemas for `Organization`, `AppSettings`, `Dashboard`, and `DataJob`, each with all specified properties and types.

2. **GIVEN** the repair step has already run **WHEN** it runs again **THEN** existing schemas are updated (not duplicated) and no data is lost.

3. **GIVEN** a schema has a required property (e.g., `Organization.name`) **WHEN** a client attempts to create an object omitting that property **THEN** OpenRegister's validation rejects the request with a 422 and field-level error message.

4. **GIVEN** a schema property is of type `datetime` **WHEN** an object is retrieved via the OpenRegister API **THEN** the value is returned as an ISO 8601 string (`YYYY-MM-DDTHH:mm:ssZ`).

### REQ-CORE-002: Dashboard with Overview Cards and Quick Actions [must]

The app MUST provide a dashboard as the default landing page at route `/`. The dashboard MUST display summary cards for key business metrics and quick action buttons for common tasks.

**Scenarios:**

1. **GIVEN** a logged-in user navigates to the Shillinq app root **WHEN** the dashboard loads **THEN** at least four summary cards are displayed (e.g., Organizations, Pending Imports, App Settings, Recent Activity) each showing a count fetched from OpenRegister.

2. **GIVEN** the dashboard is displayed **WHEN** the user clicks a quick action button (e.g., "New Organization") **THEN** the corresponding `CnFormDialog` opens pre-configured for that entity type.

3. **GIVEN** the dashboard loads and OpenRegister returns counts **WHEN** a count changes (e.g., a new organization is added) **THEN** the card count updates after the next page load or refresh.

4. **GIVEN** the Nextcloud instance uses an NL Design System theme **WHEN** the dashboard renders **THEN** all cards and buttons use NL Design System CSS variables for colors, spacing, and typography.

### REQ-CORE-003: Sidebar Navigation with Collapsible Sections [must]

The app MUST render a Nextcloud-style left sidebar using `NcAppNavigation` with named sections that can be collapsed. Each section heading MUST show a badge count reflecting live object counts from OpenRegister.

**Scenarios:**

1. **GIVEN** a user opens Shillinq **WHEN** the sidebar renders **THEN** navigation items are grouped into sections: Dashboard, Bookkeeping, Invoicing, Procurement, Contracts, and Settings.

2. **GIVEN** a sidebar section contains sub-items **WHEN** the user clicks the section heading **THEN** the sub-items toggle between visible and hidden, and the collapsed state is persisted in localStorage.

3. **GIVEN** there are 12 organizations in OpenRegister **WHEN** the "Organizations" nav item is displayed **THEN** a badge showing "12" appears next to the item label.

4. **GIVEN** the user is on the Organizations list page **WHEN** the sidebar renders **THEN** the "Organizations" nav item is visually highlighted as the active route.

### REQ-CORE-004: Entity List Views with Sorting and Pagination [must]

Each entity MUST have a `CnIndexPage`-based list view at `src/views/{entity}/{Entity}Index.vue`. The list MUST support server-side sorting by any column, pagination with configurable page size, and row-level actions.

**Scenarios:**

1. **GIVEN** there are 150 organization records **WHEN** the Organization list view loads with default page size 20 **THEN** the first 20 records are displayed and a pagination control shows pages 1–8.

2. **GIVEN** the Organization list is displayed **WHEN** the user clicks the "Name" column header **THEN** the list re-fetches from OpenRegister sorted ascending by `name`; clicking again reverses to descending.

3. **GIVEN** a list row **WHEN** the user clicks the row action "View" **THEN** they are navigated to the entity detail page for that record.

4. **GIVEN** a list row **WHEN** the user clicks "Delete" and confirms **THEN** the object is deleted via OpenRegister API, the list refreshes, and a success toast is shown.

5. **GIVEN** the list is on page 3 **WHEN** the user sorts by a column **THEN** the view resets to page 1 with the new sort applied.

### REQ-CORE-005: Entity Detail Views with Tabbed Sections [must]

Each entity MUST have a `CnDetailPage`-based detail view at `src/views/{entity}/{Entity}Detail.vue` with a header metadata block, at least two tabs (Details and History), and a contextual action bar.

**Scenarios:**

1. **GIVEN** a user navigates to `/organizations/{id}` **WHEN** the detail page loads **THEN** the page header shows the organization's name, registration number, and creation date; the action bar shows Edit and Delete buttons.

2. **GIVEN** the detail page is open **WHEN** the user clicks the "History" tab **THEN** the audit log for that OpenRegister object is fetched and displayed as a timeline of changes.

3. **GIVEN** an entity has relations (e.g., Organization → AppSettings) **WHEN** the user opens the "Related" tab **THEN** linked objects are listed with direct links to their own detail pages.

4. **GIVEN** the user clicks "Edit" in the action bar **WHEN** the form dialog opens **THEN** all current field values are pre-populated from the OpenRegister object.

### REQ-CORE-006: Entity Create/Edit Forms with Validation [must]

Each entity MUST use a `CnFormDialog`-based form generated from `fieldsFromSchema()`. The form MUST enforce client-side validation: required fields marked, type-coerced inputs, save disabled until all required fields are valid.

**Scenarios:**

1. **GIVEN** the Organization schema has `name` as required **WHEN** the user opens the create form and clicks Save without entering a name **THEN** the name field shows an inline error "This field is required" and the save button remains disabled.

2. **GIVEN** a field is of type `boolean` **WHEN** it is rendered in the form **THEN** it appears as a toggle switch, not a text input.

3. **GIVEN** a field is of type `datetime` **WHEN** the user interacts with it **THEN** a date-time picker is shown and the value is stored in ISO 8601 format.

4. **GIVEN** the user fills all required fields and clicks Save **WHEN** the OpenRegister API returns 201 Created **THEN** the dialog closes, the list view refreshes, and a "Saved" toast notification is shown.

5. **GIVEN** the OpenRegister API returns a 422 with field errors **WHEN** the form receives the error response **THEN** each affected field shows the server-side error message inline.

### REQ-CORE-007: Global Search Across All Entity Types [must]

The app MUST register a Nextcloud search provider (`lib/Search/ShillinqSearchProvider.php`) that queries all registered OpenRegister schemas and returns grouped results in the Nextcloud unified search bar.

**Scenarios:**

1. **GIVEN** a user types "Conduction" in the Nextcloud search bar **WHEN** the search provider runs **THEN** results from all Shillinq schemas containing "Conduction" are returned, grouped by entity type (e.g., "Organizations", "Settings").

2. **GIVEN** search returns 3 organizations and 1 app setting **WHEN** the results are displayed **THEN** each result shows the entity name, type icon, and a direct link to the detail page.

3. **GIVEN** there are no matching records across any schema **WHEN** the search runs **THEN** no Shillinq results appear in the unified search (the section is omitted, not shown empty).

4. **GIVEN** the user clicks a search result **WHEN** the click handler fires **THEN** the user is navigated directly to the entity detail page within the Shillinq app.

### REQ-CORE-008: Admin Settings Page with App Configuration [must]

The app MUST register an admin settings section in Nextcloud (`lib/Settings/AdminSettings.php`) accessible only to Nextcloud admins. It MUST expose configurable app-wide settings stored as `AppSettings` objects in OpenRegister.

**Scenarios:**

1. **GIVEN** a Nextcloud admin opens Administration → Shillinq **WHEN** the settings page renders **THEN** fields are shown for: default currency (text, default "EUR"), fiscal year start month (1–12, default 1), and OpenRegister register URL.

2. **GIVEN** the admin changes the fiscal year start to 4 (April) and saves **WHEN** the save request completes **THEN** the `AppSettings` object with `key = "fiscalYearStart"` is updated in OpenRegister and a success message is shown.

3. **GIVEN** a non-admin user attempts to access the admin settings route **WHEN** the request is processed **THEN** the PHP controller returns HTTP 403 Forbidden.

4. **GIVEN** an `AppSettings` object with `editable = false` **WHEN** it is rendered in the admin UI **THEN** its input is disabled and a tooltip explains it is managed by configuration.

### REQ-CORE-009: Seed Data for Onboarding [must]

The app MUST load representative demo data during the repair step so new users can explore the app immediately. Seed data MUST be idempotent and keyed on a stable identifier to prevent duplication on re-runs.

**Scenarios:**

1. **GIVEN** a fresh Shillinq installation **WHEN** the repair step completes **THEN** at least one `Organization` object (name: "Demo B.V.", country: "NL"), three `AppSettings` objects (currency, fiscalYearStart, dateFormat), and one `Dashboard` object exist in OpenRegister.

2. **GIVEN** the repair step has already seeded data **WHEN** it runs again **THEN** no duplicate objects are created; existing seed objects are left unchanged.

3. **GIVEN** a user deletes a seed record **WHEN** the repair step runs next **THEN** the deleted seed record is NOT recreated (idempotency is keyed on first-run, not enforced permanently).

4. **GIVEN** the seed data includes a `DataJob` with `status = "completed"` **WHEN** a new user opens the DataJob list **THEN** the demo import job is visible and they can inspect its `errorLog` field for an explanation.

### REQ-CORE-010: Faceted Filtering on List Views [should]

All entity list views MUST support faceted filtering using `filtersFromSchema()` from `@conduction/nextcloud-vue`. Active filters MUST be reflected in the URL query string for shareable filtered views.

**Scenarios:**

1. **GIVEN** the Organization list view is open **WHEN** the user selects filter "Country = NL" from the filter panel **THEN** the list re-fetches with the filter applied and the URL updates to `?filters[country]=NL`.

2. **GIVEN** multiple filters are active (Country = NL, City = Amsterdam) **WHEN** the user removes the City filter chip **THEN** only the Country filter remains active and the list refreshes accordingly.

3. **GIVEN** a user pastes a URL with `?filters[country]=NL` into a browser **WHEN** the page loads **THEN** the filter is pre-applied and the chip "Country: NL" is shown in the filter bar.

4. **GIVEN** a list with active filters **WHEN** the user clicks "Clear all filters" **THEN** all filters are removed, the URL query is cleared, and the full unfiltered list is shown.

### REQ-CORE-011: Breadcrumb Navigation [should]

The app MUST display context-aware breadcrumbs on all non-dashboard pages. Breadcrumbs MUST be generated from `vue-router` route metadata and MUST be clickable links.

**Scenarios:**

1. **GIVEN** a user is on the Organization detail page for "Demo B.V." **WHEN** the breadcrumb renders **THEN** it shows: Shillinq > Organizations > Demo B.V.

2. **GIVEN** the user clicks "Organizations" in the breadcrumb **WHEN** the navigation fires **THEN** they are taken to the Organization list view.

3. **GIVEN** a user is on the admin settings page **WHEN** the breadcrumb renders **THEN** it shows: Shillinq > Settings > Admin.

### REQ-CORE-012: Nextcloud Notification Integration [should]

The app MUST implement `OCP\Notification\INotifier` in `lib/Notification/ShillinqNotifier.php` and register it in `appinfo/info.xml`. Initial notification triggers MUST cover DataJob completion events (import finished, import failed).

**Scenarios:**

1. **GIVEN** a CSV import DataJob transitions to `status = "completed"` **WHEN** the background job finishes **THEN** a Nextcloud notification is sent to the user who initiated the import with message "Import of {fileName} completed: {processedRecords} records imported".

2. **GIVEN** a CSV import DataJob transitions to `status = "failed"` **WHEN** the background job finishes **THEN** a notification is sent with message "Import of {fileName} failed: {failedRecords} errors. View details." and a link to the DataJob detail page.

3. **GIVEN** a user has opted out of import notifications in their user preferences **WHEN** the DataJob completes **THEN** no notification is sent to that user.

4. **GIVEN** the `ShillinqNotifier` is registered **WHEN** a notification is parsed for display **THEN** the notifier returns a human-readable subject and body using the Nextcloud `l10n` translation system.

### REQ-CORE-013: CSV Import for Bulk Data Loading [should]

The app MUST provide a guided CSV import flow backed by `DataJob` objects. The flow consists of: (1) file picker, (2) column mapper, (3) validation preview, (4) background import execution.

**Scenarios:**

1. **GIVEN** a user clicks "Import CSV" on the Organization list **WHEN** they upload a CSV file with headers `name,registrationNumber,city,country` **THEN** the column mapper auto-detects matching schema fields and pre-fills the mapping.

2. **GIVEN** the column mapping is confirmed **WHEN** the user proceeds to the preview step **THEN** the first 10 rows of data are shown in a table with per-row validation results (valid / error with reason).

3. **GIVEN** the preview shows 2 rows with errors (missing required `name`) **WHEN** the user initiates the import **THEN** valid rows are imported, errored rows are skipped, and the DataJob `errorLog` records each failed row with the reason.

4. **GIVEN** a DataJob is in `status = "processing"` **WHEN** the user views the DataJob detail page **THEN** a progress indicator shows `processedRecords / totalRecords` and the page auto-refreshes every 5 seconds.

5. **GIVEN** the import completes **WHEN** the user returns to the Organization list **THEN** the newly imported records appear and the list count is updated.

### REQ-CORE-014: CSV/Excel Export of List Views [should]

All entity list views MUST support export of the currently filtered and sorted result set to CSV and XLSX format via `lib/Controller/ExportController.php`. No new Composer packages may be introduced.

**Scenarios:**

1. **GIVEN** the Organization list has filter "Country = NL" active with 30 matching records **WHEN** the user clicks "Export CSV" **THEN** a CSV file is downloaded containing exactly those 30 records with all visible column headers.

2. **GIVEN** the user clicks "Export XLSX" **THEN** a valid `.xlsx` file is downloaded using PHP's `ZipArchive` and `XMLWriter` (no external library); the file opens in Excel/LibreOffice with correct column headers.

3. **GIVEN** a list has no active filters and 500 records **WHEN** the user exports **THEN** all 500 records are included in the export (no pagination limit applied to export).

4. **GIVEN** an export is requested for a field containing commas (e.g., address "Main St, 1") **WHEN** the CSV is generated **THEN** the field is properly quoted per RFC 4180.

### REQ-CORE-015: User Preferences for Display and Notification Settings [should]

Each user MUST be able to configure personal display preferences stored as `AppSettings` objects in OpenRegister scoped to their `userId`. Preferences MUST override app-wide defaults for the current user.

**Scenarios:**

1. **GIVEN** a user opens their Shillinq preferences page **WHEN** the page loads **THEN** fields are shown for: interface language (dropdown), date format (DD-MM-YYYY / YYYY-MM-DD / MM/DD/YYYY), and notification toggles per event type.

2. **GIVEN** the user sets date format to "YYYY-MM-DD" and saves **WHEN** they view any entity detail page **THEN** all dates are rendered in YYYY-MM-DD format regardless of the app-wide default.

3. **GIVEN** the user disables "Import notifications" **WHEN** a DataJob completes for that user **THEN** no Nextcloud notification is delivered.

4. **GIVEN** a user has no saved preferences **WHEN** the app renders dates **THEN** the app-wide default date format (from AdminSettings) is used as the fallback.
