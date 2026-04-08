# Design: Core — Shillinq

## Architecture Overview

Shillinq follows the Conduction thin-client pattern: no own database tables. All entity data is stored and retrieved via OpenRegister's `ObjectService` API. The PHP backend is minimal — it handles register setup, search provider registration, admin settings, export generation, and background import jobs. The Vue 2.7 + Pinia frontend communicates directly with OpenRegister's REST API for all CRUD operations.

```
Browser (Vue 2.7 + Pinia)
    │
    ├─ OpenRegister REST API  (entity CRUD, search, object counts)
    │
    └─ Shillinq OCS API       (export, import trigger, preferences)
            │
            └─ PHP Services   (ExportService, ImportService, NotificationService)
                    │
                    └─ OpenRegister ObjectService (PHP)
```

## Data Model

### Organization (`schema:Organization`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| name | string | Yes | — | Legal name |
| registrationNumber | string | No | — | KvK number |
| email | string | No | — | |
| phone | string | No | — | |
| website | string | No | — | |
| address | string | No | — | Street + number |
| city | string | No | — | |
| country | string | No | — | ISO 3166-1 alpha-2 |

### AppSettings (`custom`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| key | string | Yes | — | Setting identifier, e.g. `fiscalYearStart` |
| value | string | Yes | — | Always stored as string |
| dataType | string | No | string | string / boolean / number / json |
| category | string | No | — | UI grouping: appearance / notifications / integrations |
| editable | boolean | No | true | False = managed by config, UI disables input |
| userId | string | No | — | Null = app-wide; set = per-user preference |

### Dashboard (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| title | string | Yes | — | Dashboard title |
| description | string | No | — | |
| layoutType | string | Yes | grid | grid / flexbox / custom |
| isDefault | boolean | No | false | Default dashboard for the user |
| theme | string | No | — | NL Design System theme variant |
| userId | string | No | — | Owner; null = shared |

### DataJob (`schema:Thing`)

| Property | Type | Required | Default | Notes |
|----------|------|----------|---------|-------|
| fileName | string | Yes | — | Uploaded file name |
| entityType | string | Yes | — | Target schema, e.g. `Organization` |
| status | string | Yes | pending | pending / processing / completed / failed |
| totalRecords | number | No | — | Rows in the CSV |
| processedRecords | number | No | 0 | Successfully imported |
| failedRecords | number | No | 0 | Rows that failed validation |
| errorLog | string | No | — | JSONL of per-row errors |
| startedAt | datetime | No | — | |
| completedAt | datetime | No | — | |
| userId | string | No | — | User who triggered the import |

## OpenRegister Register Definition

Location: `lib/Settings/ShillinqRegister.json`

```json
{
  "openapi": "3.0.0",
  "info": { "title": "Shillinq", "version": "0.1.0" },
  "components": {
    "schemas": {
      "Organization": { ... },
      "AppSettings": { ... },
      "Dashboard": { ... },
      "DataJob": { ... }
    }
  }
}
```

Imported via `ConfigurationService::importFromApp('shillinq')` in `lib/Migration/RepairStep.php`.

## Backend Components

### `lib/Settings/AdminSettings.php`
Implements `OCP\Settings\ISettings`. Returns `TemplateResponse` for `templates/admin.php`. Registered in `appinfo/info.xml` under `<settings><admin>`.

### `lib/Search/ShillinqSearchProvider.php`
Implements `OCP\Search\IProvider`. The `search()` method fans out to each schema via `ObjectService::searchObjects($schema, $query)` and maps results to `OCP\Search\SearchResult`. Registered in `appinfo/info.xml` under `<search><provider>`.

### `lib/Notification/ShillinqNotifier.php`
Implements `OCP\Notification\INotifier`. Handles subjects `datajob_completed` and `datajob_failed`. Registered in `appinfo/info.xml` under `<notification><notifier>`.

### `lib/Controller/ExportController.php`
OCS API controller. Routes:
- `GET /apps/shillinq/api/v1/export/{schema}?format=csv&filters=...` — streams CSV
- `GET /apps/shillinq/api/v1/export/{schema}?format=xlsx&filters=...` — streams XLSX

XLSX generation uses `ZipArchive` + `XMLWriter` (Office Open XML) with no external dependencies.

### `lib/Controller/ImportController.php`
OCS API controller. Routes:
- `POST /apps/shillinq/api/v1/import/{schema}` — accepts multipart CSV, creates `DataJob`, enqueues `ImportBackgroundJob`
- `GET /apps/shillinq/api/v1/import/{jobId}/preview` — returns first 10 rows with validation results

### `lib/BackgroundJob/ImportBackgroundJob.php`
Extends `OC\BackgroundJob\QueuedJob`. Reads the CSV, validates each row against schema, creates/updates OpenRegister objects, updates `DataJob` progress counters, and fires `datajob_completed` or `datajob_failed` notification on finish.

## Frontend Components

### Directory Structure

```
src/
  views/
    dashboard/
      DashboardIndex.vue        # Main dashboard page
    organization/
      OrganizationIndex.vue     # CnIndexPage list
      OrganizationDetail.vue    # CnDetailPage detail
    appSettings/
      AppSettingsIndex.vue      # CnIndexPage list (admin)
      AppSettingsDetail.vue     # CnDetailPage
      UserPreferences.vue       # Per-user preferences form
    dashboard/
      DashboardIndex.vue
      DashboardDetail.vue
    dataJob/
      DataJobIndex.vue
      DataJobDetail.vue
      DataJobImport.vue         # Multi-step import wizard
  store/
    modules/
      organization.js           # createObjectStore('Organization')
      appSettings.js            # createObjectStore('AppSettings')
      dashboard.js              # createObjectStore('Dashboard')
      dataJob.js                # createObjectStore('DataJob')
  components/
    ShillinqBreadcrumb.vue      # Breadcrumb component
    ShillinqSidebar.vue         # NcAppNavigation wrapper
    ExportButton.vue            # CSV/XLSX export trigger
    FilterPanel.vue             # filtersFromSchema() wrapper
```

### Store Pattern

All stores use `createObjectStore` from `@conduction/nextcloud-vue`:

```js
// src/store/modules/organization.js
import { createObjectStore } from '@conduction/nextcloud-vue'
export const useOrganizationStore = createObjectStore('Organization', {
  register: 'shillinq',
  schema: 'Organization',
})
```

### List View Pattern

```vue
<!-- OrganizationIndex.vue -->
<CnIndexPage
  :columns="columnsFromSchema(schema)"
  :filters="filtersFromSchema(schema)"
  :store="useOrganizationStore()"
  @create="openCreateDialog"
  @view="navigateToDetail"
  @delete="confirmDelete"
/>
```

### Form Dialog Pattern

```vue
<!-- triggered from list or detail page -->
<CnFormDialog
  :fields="fieldsFromSchema(schema)"
  :object="selectedObject"
  @save="handleSave"
  @cancel="closeDialog"
/>
```

### Breadcrumb Component

Route meta drives breadcrumb rendering:

```js
// router/index.js
{
  path: '/organizations/:id',
  name: 'organization-detail',
  meta: {
    breadcrumb: [
      { label: 'Shillinq', to: '/' },
      { label: 'Organizations', to: '/organizations' },
      { label: ':name', dynamic: true },
    ]
  }
}
```

## Seed Data

Location: `lib/Migration/SeedData.php` — called from the repair step.

```php
// Keyed on 'key' field; findOrCreate prevents duplication
$this->seedObject('Organization', 'slug', 'demo-bv', [
    'name'               => 'Demo B.V.',
    'registrationNumber' => '12345678',
    'city'               => 'Amsterdam',
    'country'            => 'NL',
]);

$this->seedObject('AppSettings', 'key', 'currency', [
    'key'      => 'currency',
    'value'    => 'EUR',
    'dataType' => 'string',
    'category' => 'general',
    'editable' => true,
]);

$this->seedObject('AppSettings', 'key', 'fiscalYearStart', [
    'key'      => 'fiscalYearStart',
    'value'    => '1',
    'dataType' => 'number',
    'category' => 'general',
    'editable' => true,
]);

$this->seedObject('AppSettings', 'key', 'dateFormat', [
    'key'      => 'dateFormat',
    'value'    => 'DD-MM-YYYY',
    'dataType' => 'string',
    'category' => 'appearance',
    'editable' => true,
]);

$this->seedObject('Dashboard', 'title', 'Default Dashboard', [
    'title'       => 'Default Dashboard',
    'layoutType'  => 'grid',
    'isDefault'   => true,
]);

$this->seedObject('DataJob', 'fileName', 'demo-import.csv', [
    'fileName'         => 'demo-import.csv',
    'entityType'       => 'Organization',
    'status'           => 'completed',
    'totalRecords'     => 5,
    'processedRecords' => 5,
    'failedRecords'    => 0,
    'errorLog'         => '',
]);
```

## Affected Files

New files created by this change:

**PHP**
- `lib/Settings/ShillinqRegister.json`
- `lib/Settings/AdminSettings.php`
- `lib/Settings/AdminSettingsSection.php`
- `lib/Search/ShillinqSearchProvider.php`
- `lib/Notification/ShillinqNotifier.php`
- `lib/Controller/ExportController.php`
- `lib/Controller/ImportController.php`
- `lib/BackgroundJob/ImportBackgroundJob.php`
- `lib/Migration/RepairStep.php` (extend existing)
- `lib/Migration/SeedData.php`
- `lib/Service/ExportService.php`
- `lib/Service/ImportService.php`
- `templates/admin.php`

**Vue / JS**
- `src/views/dashboard/DashboardIndex.vue`
- `src/views/organization/OrganizationIndex.vue`
- `src/views/organization/OrganizationDetail.vue`
- `src/views/appSettings/AppSettingsIndex.vue`
- `src/views/appSettings/AppSettingsDetail.vue`
- `src/views/appSettings/UserPreferences.vue`
- `src/views/dashboard/DashboardDetail.vue`
- `src/views/dataJob/DataJobIndex.vue`
- `src/views/dataJob/DataJobDetail.vue`
- `src/views/dataJob/DataJobImport.vue`
- `src/store/modules/organization.js`
- `src/store/modules/appSettings.js`
- `src/store/modules/dashboard.js`
- `src/store/modules/dataJob.js`
- `src/components/ShillinqBreadcrumb.vue`
- `src/components/ShillinqSidebar.vue`
- `src/components/ExportButton.vue`
- `src/components/FilterPanel.vue`
- `src/router/index.js` (extend existing)

**Modified**
- `appinfo/info.xml` — register search provider, notifier, admin settings, background job
- `lib/AppInfo/Application.php` — register services
