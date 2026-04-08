# Tasks: core

## 1. OpenRegister Schema Definitions

- [x] 1.1 Add `organization` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `shillinq_register.json` is processed by OpenRegister
    - THEN schema `organization` MUST be registered with all properties from the data model table
    - AND `name` MUST be marked as `required`
    - AND `x-schema-org` annotation MUST be `schema:Organization`

- [x] 1.2 Add `appSettings` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is registered
    - THEN properties `key` (required), `value` (required), `dataType`, `category`, `editable` MUST exist
    - AND `dataType` MUST have enum `["string","boolean","number","json"]`
    - AND `category` MUST have enum `["appearance","notifications","integrations","general"]`

- [x] 1.3 Add `dashboard` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is registered
    - THEN properties `title` (required), `layoutType` (required), `description`, `isDefault`, `theme` MUST exist
    - AND `layoutType` MUST have enum `["grid","flexbox","custom"]`
    - AND `x-schema-org` MUST be `schema:Thing`

- [x] 1.4 Add `dataJob` schema to `lib/Settings/shillinq_register.json`
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-001`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is registered
    - THEN properties `fileName` (required), `entityType` (required), `status` (required) MUST exist
    - AND `status` MUST have enum `["pending","processing","completed","failed"]`
    - AND numeric properties `totalRecords`, `processedRecords`, `failedRecords` MUST have `default: 0`
    - AND `startedAt`, `completedAt` MUST be `format: date-time`

- [x] 1.5 Remove `example` schema stub from `lib/Settings/shillinq_register.json`
  - **files**: `lib/Settings/shillinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the updated register file
    - THEN the `example` schema placeholder MUST be removed
    - AND the `x-openregister.description` MUST be updated to match the app description

## 2. Seed Data

- [x] 2.1 Add Organization seed objects to `lib/Repair/CreateDefaultConfiguration.php`
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-002`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN the repair step runs on a fresh install
    - THEN 2 Organization objects MUST be created: "Acme BV" (Amsterdam, NL) and "Beta Corp" (Rotterdam, NL)
    - AND if objects already exist for the schema, NO new objects MUST be created

- [x] 2.2 Add AppSettings seed objects to Repair step
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-002`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN a fresh install
    - THEN 4 AppSettings objects MUST be seeded: `language`, `dateFormat`, `notificationEmail`, `notificationInApp`
    - AND idempotency check MUST use `key` as the unique field

- [x] 2.3 Add Dashboard and DataJob seed objects to Repair step
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-002`
  - **files**: `lib/Repair/CreateDefaultConfiguration.php`
  - **acceptance_criteria**:
    - GIVEN a fresh install
    - THEN 1 Dashboard seed object MUST be created with `isDefault: true`
    - AND 1 DataJob seed object MUST be created with `status: "completed"` for the sample import

## 3. Pinia Stores

- [x] 3.1 Create `src/store/modules/organization.js`
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-005, #REQ-CORE-006, #REQ-CORE-007`
  - **files**: `src/store/modules/organization.js`
  - **acceptance_criteria**:
    - GIVEN the store is initialized
    - THEN `useOrganizationStore` MUST be created via `createObjectStore('organization')`
    - AND the store MUST be registered in `src/store/store.js`

- [x] 3.2 Create `src/store/modules/appSettings.js`
  - **files**: `src/store/modules/appSettings.js`
  - **acceptance_criteria**:
    - GIVEN the store is initialized
    - THEN `useAppSettingsStore` MUST be created via `createObjectStore('appSettings')`

- [x] 3.3 Create `src/store/modules/dashboard.js`
  - **files**: `src/store/modules/dashboard.js`
  - **acceptance_criteria**:
    - GIVEN the store is initialized
    - THEN `useDashboardStore` MUST be created via `createObjectStore('dashboard')`

- [x] 3.4 Create `src/store/modules/dataJob.js`
  - **files**: `src/store/modules/dataJob.js`
  - **acceptance_criteria**:
    - GIVEN the store is initialized
    - THEN `useDataJobStore` MUST be created via `createObjectStore('dataJob')`

- [x] 3.5 Update `src/store/store.js` to register all four new stores
  - **files**: `src/store/store.js`
  - **acceptance_criteria**:
    - GIVEN `initializeStores()` is called
    - THEN all four stores (organization, appSettings, dashboard, dataJob) MUST be initialized and returned

## 4. Dashboard View

- [x] 4.1 Implement `src/views/dashboard/DashboardPage.vue` with summary cards and quick actions
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-003`
  - **files**: `src/views/dashboard/DashboardPage.vue`
  - **acceptance_criteria**:
    - GIVEN the dashboard page loads
    - THEN summary cards MUST show counts for Organizations and open DataJobs
    - AND each card MUST be clickable to navigate to the list view
    - AND quick-action buttons MUST include "Add Organization", "Import CSV", "Export Data"
    - AND the 5 most recent DataJobs MUST be listed with status color-coding

## 5. Organization Views

- [x] 5.1 Create `src/views/organization/OrganizationList.vue`
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-005, #REQ-CORE-010`
  - **files**: `src/views/organization/OrganizationList.vue`
  - **acceptance_criteria**:
    - GIVEN the list view renders
    - THEN `CnIndexPage` MUST be used with columns from `columnsFromSchema('organization')`
    - AND column Name MUST be sortable
    - AND pagination MUST show 20 items per page
    - AND row actions: View, Edit, Delete MUST be present
    - AND faceted filter chips MUST be generated via `filtersFromSchema('organization')`

- [x] 5.2 Create `src/views/organization/OrganizationDetail.vue`
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-006`
  - **files**: `src/views/organization/OrganizationDetail.vue`
  - **acceptance_criteria**:
    - GIVEN an organization detail page renders
    - THEN detail view MUST show all organization properties with tabs: Details, Settings
    - AND breadcrumb MUST show: Shillinq > Organizations > {name}
    - AND contextual actions Edit and Delete MUST be present

- [x] 5.3 Integrate `CnFormDialog` for Organization create/edit
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-007`
  - **files**: `src/views/organization/OrganizationList.vue`, `src/views/organization/OrganizationDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the create/edit form opens
    - THEN fields MUST be generated via `fieldsFromSchema('organization')`
    - AND `name` MUST be marked as required with validation error on empty submit
    - AND saving MUST call `organizationStore.saveObject(data)`

## 6. AppSettings Admin Page

- [x] 6.1 Create `src/views/appSettings/AppSettingsPage.vue`
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-008`
  - **files**: `src/views/appSettings/AppSettingsPage.vue`
  - **acceptance_criteria**:
    - GIVEN an admin opens Shillinq admin settings
    - THEN settings MUST be grouped by category (appearance, notifications, integrations)
    - AND editable settings MUST render as input fields; `editable: false` settings as read-only
    - AND saving a setting MUST update the AppSettings object via `appSettingsStore.saveObject(data)`
    - AND a success notification MUST be shown after save

## 7. DataJob Views and CSV Import

- [x] 7.1 Create `src/views/dataJob/DataJobList.vue`
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-003`
  - **files**: `src/views/dataJob/DataJobList.vue`
  - **acceptance_criteria**:
    - GIVEN the DataJob list renders
    - THEN `CnIndexPage` MUST show columns: fileName, entityType, status (color-coded), processedRecords, failedRecords, completedAt
    - AND status MUST be color-coded: green (completed), yellow (processing/pending), red (failed)

- [x] 7.2 Create `src/views/dataJob/DataJobDetail.vue`
  - **files**: `src/views/dataJob/DataJobDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a DataJob detail renders
    - THEN detail view MUST show all DataJob properties
    - AND errorLog MUST be shown in a scrollable pre-formatted block if non-empty

- [x] 7.3 Create `src/views/dataJob/CsvImportDialog.vue` — multi-step CSV import
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-011`
  - **files**: `src/views/dataJob/CsvImportDialog.vue`
  - **acceptance_criteria**:
    - GIVEN a user opens the import dialog
    - THEN 4 steps MUST be shown: Upload, Map Columns, Preview, Confirm
    - AND Upload step MUST validate CSV MIME type and read headers
    - AND Map step MUST show each CSV header mapped to schema property dropdowns
    - AND Preview step MUST show first 5 rows with validation errors highlighted in red
    - AND Confirm step MUST create a DataJob with `status: "processing"` on submit
    - AND on DataJob completion the user MUST receive a Nextcloud notification

## 8. Navigation and App Shell

- [x] 8.1 Update `src/navigation/MainMenu.vue` with new sections and badge counts
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-004`
  - **files**: `src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - GIVEN the sidebar renders
    - THEN sections MUST include: Dashboard, Organizations, Data Jobs, Settings
    - AND Data Jobs section MUST show a badge with count of pending + processing DataJobs
    - AND sections MUST be collapsible with state persisted in `localStorage`

- [x] 8.2 Create `src/components/Breadcrumb.vue`
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-014`
  - **files**: `src/components/Breadcrumb.vue`
  - **acceptance_criteria**:
    - GIVEN a breadcrumb prop `[{ label: 'Organizations', route: '#/organizations' }, { label: 'Acme BV' }]` is passed
    - THEN each segment except the last MUST render as a clickable link
    - AND the last segment MUST render as plain text (current page)

- [x] 8.3 Create `src/components/GlobalSearch.vue`
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-009`
  - **files**: `src/components/GlobalSearch.vue`
  - **acceptance_criteria**:
    - GIVEN the search bar is mounted in the app header
    - THEN input MUST be debounced 300 ms
    - AND results MUST be fetched from all registered schemas using `_search` parameter
    - AND results MUST be grouped by schema type in the dropdown
    - AND selecting a result MUST navigate to the entity's detail view
    - AND clearing input MUST close the dropdown

- [x] 8.4 Create `src/components/ExportButton.vue`
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-012`
  - **files**: `src/components/ExportButton.vue`
  - **acceptance_criteria**:
    - GIVEN an ExportButton is rendered on a list view
    - THEN clicking "Export CSV" MUST download a CSV of all currently filtered rows
    - AND clicking "Export Excel" MUST download an `.xlsx` file with headers on row 1
    - AND the exported data MUST respect current sort order and active filters

- [x] 8.5 Update `src/App.vue` to add new routes, global search, and breadcrumb slot
  - **files**: `src/App.vue`
  - **acceptance_criteria**:
    - GIVEN the app loads
    - THEN routes MUST be registered for: `#/dashboard`, `#/organizations`, `#/organizations/:id`, `#/data-jobs`, `#/data-jobs/:id`, `#/settings`
    - AND `GlobalSearch` MUST be mounted in the header area
    - AND `Breadcrumb` MUST receive route-derived breadcrumb props

## 9. Notification & Activity Integration

- [x] 9.1 Create `lib/Service/NotificationService.php`
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-015`
  - **files**: `lib/Service/NotificationService.php`
  - **acceptance_criteria**:
    - GIVEN a DataJob transitions to `completed` or `failed`
    - THEN `NotificationService::notifyImportComplete()` MUST dispatch a `INotification` to the initiating user
    - AND the notification MUST include subject "Import complete: {fileName}" and a deep link to `#/data-jobs/{id}`
    - AND self-action guard: if author === recipient, no notification is sent

- [x] 9.2 Create `lib/Activity/ShillinqActivityProvider.php` and supporting classes
  - **files**: `lib/Activity/ShillinqActivityProvider.php`, `lib/Activity/Filter.php`, `lib/Activity/Setting/DataImport.php`
  - **acceptance_criteria**:
    - GIVEN a DataJob completes
    - THEN an activity event of type `shillinq_datajob` MUST be published via `IManager::publish()`
    - AND the Filter class MUST allow filtering Shillinq events in the Activity app
    - AND Setting class MUST expose stream + email toggles per user

- [x] 9.3 Register activity provider and notification provider in `lib/AppInfo/Application.php`
  - **files**: `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - GIVEN the app boots
    - THEN `ShillinqActivityProvider` MUST be registered via `IActivityManager`
    - AND `INotificationManager` MUST have the app's notification handler registered

## 10. User Preferences

- [x] 10.1 Implement per-user preference storage and retrieval for language, dateFormat, and notification toggles
  - **spec_ref**: `specs/core/spec.md#REQ-CORE-013`
  - **files**: `src/views/settings/UserPreferencesPage.vue` (create), `lib/Controller/UserPreferencesController.php` (create)
  - **acceptance_criteria**:
    - GIVEN a user opens Shillinq user settings
    - THEN fields for language, dateFormat, notificationEmail, notificationInApp MUST be shown
    - AND saving MUST call `IUserPreferences::setPreference('shillinq', key, value)`
    - AND loaded preferences MUST apply app-wide (e.g., date display format)

## 11. i18n

- [x] 11.1 Add English translations in `l10n/en.json` for all new UI strings
  - **files**: `l10n/en.json`
  - **acceptance_criteria**:
    - GIVEN the app renders in English
    - THEN all navigation labels, form labels, action buttons, and notification subjects MUST be translated
    - AND no hardcoded English strings MUST appear outside `t('shillinq', '...')` calls

- [x] 11.2 Add Dutch translations in `l10n/nl.json`
  - **files**: `l10n/nl.json`
  - **acceptance_criteria**:
    - GIVEN the Nextcloud instance language is set to Dutch
    - THEN all UI strings MUST render in Dutch
    - AND translation keys MUST match those in `en.json`

## 12. Unit Tests

- [x] 12.1 Add unit tests for `NotificationService.php`
  - **files**: `tests/Unit/Service/NotificationServiceTest.php`
  - **acceptance_criteria**:
    - GIVEN a DataJob completion event
    - THEN `notifyImportComplete()` MUST be tested for: correct subject, correct recipient, deep link format
    - AND self-action guard (author === recipient → no notification) MUST be tested

- [x] 12.2 Add unit tests for `CreateDefaultConfiguration.php` Repair step
  - **files**: `tests/Unit/Repair/CreateDefaultConfigurationTest.php`
  - **acceptance_criteria**:
    - GIVEN a fresh install (0 objects)
    - THEN seed data MUST be created for all four schemas
    - GIVEN objects already exist
    - THEN no duplicate objects MUST be created
