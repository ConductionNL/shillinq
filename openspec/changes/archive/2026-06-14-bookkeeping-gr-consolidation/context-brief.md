# Context Brief: Gemeenschappelijke Regeling Consolidation

**App:** Shillinq — Merged into budgetq
**Spec:** bookkeeping-gr-consolidation
**Platform:** Nextcloud + OpenRegister

## Features (2 total, sorted by market demand)

### Group consolidation with real-time consolidated financial reporting
**demand: 134** (38 tender mentions) | Category: other

### Inter-company posting with automatic consolidation eliminations
**demand: 46** (14 tender mentions) | Category: other

## User Stories

(No user stories linked to this spec. Generate from the features above.)

## Customer Journeys

(No journeys linked. Infer from stakeholders and features above.)

## Stakeholders

(No stakeholders linked. Infer from the features and user stories above.)

## Company-Wide Architecture Rules (29 ADRs)

These rules are MANDATORY for all Conduction apps.

### ADR-001-data-layer
- ALL domain data → OpenRegister objects. NO custom Entity/Mapper for domain data.
- App config → `IAppConfig`. NOT OpenRegister.
- Cross-entity references: OpenRegister relations (register+schema+objectId). NO foreign keys.
  MUST NOT store foreign keys or embed full objects.

### Schema standards

- Schemas: PascalCase, schema.org vocabulary, explicit types + required flags + description field.
- MUST NOT invent custom property names when a schema.org equivalent exists.
- Contact schemas MUST align with vCard properties (fn, email, tel, adr).
- Dutch government fields SHOULD use a mapping layer translating between international standards
  and Dutch specs — do not hardcode Dutch field names as primary.
- Schema changes that remove or rename properties are BREAKING. Adding optional properties is non-breaking.

### Register templates

- Location: `lib/Settings/{app}_register.json` (OpenAPI 3.0 + `x-openregister` extensions).
- Three template categories:
  - **App configuration** — define data models (schemas/registers/views/mappings).
    Mark with `x-openregister.type: "application"`.
  - **Mock data** — fictional but realistic seed data for dev/test.
    Mark with `x-openregister.type: "mock"`.
  - **Government standards** — aligned to Dutch API specs (BAG, BRP, KVK, DSO).
- Import mechanism: `ConfigurationService::importFromApp(appId, data, version, force)` →
  `ImportHandler::importFromApp()`. Called from repair step or `SettingsLoadService`.
- Idempotency: re-importing with `force: false` MUST NOT create duplicates. Match by slug
  using `ObjectService::searchObjects` with `_rbac: false` and `_multitenancy: false`.
  Use `version_compare` for skip logic.

### Seed data

Apps that store data in OpenRegister are empty on first install. An empty app cannot be
meaningfully tested — there are no objects to view, search, filter, or interact with.
This blocks both automated browser testing and manual QA. The Loadable Register Template
pattern (see Register templates above) already supports seed data via `components.objects[]`
with the `@self` envelope.

**Requirements:**

- Every app using OpenRegister MUST include 3-5 realistic objects per schema in
  `lib/Settings/{app}_register.json`.
- Use `@self` envelope: `{ "@self": { "register": ..., "schema": ..., "slug": ... }, ...properties }`.
  Register/schema MUST match keys; slug is unique human-readable identifier for matching.
- Use general organisation data (municipality, consultancy, travel agency, non-profit) —
  NOT context-specific. Varied, realistic field values.
- Mock data quality: real Dutch street names, valid postcodes (`[1-9][0-9]{3}[A-Z]{2}`),
  correct municipality/KVK codes, BSNs that pass 11-proef. Fictional but distinguishable from real.
- Cross-register consistency: BRP→BAG, KVK→BAG, DSO→BAG references must be valid.
- Loaded on install alongside schemas via same `importFromApp()` pipeline.
- MUST be idempotent — re-importing skips existing objects matched by slug.

**In OpenSpec artifacts:**

- **In design.md**: MUST include a Seed Data section when change introduces/modifies schemas —
  define seed objects per schema with concrete field values and related items (files, notes, tasks, contacts).
- **In tasks.md**: MUST include a seed data generation task when change introduces/modifies schemas.

**Exceptions** (no seed data required):

- **nldesign** — has no OpenRegister schemas.
- **ExApp sidecar wrappers** (openklant, opentalk, openzaak, valtimo, n8n-nextcloud) — proxy
  external services and do not use OpenRegister.
- **nextcloud-vue** — shared library, no seed data applicable.
- Changes that only modify frontend components or non-schema backend logic (e.g., settings,
  permissions) do not require seed data.

**Limitations:** OpenRegister's `ImportHandler` currently supports only flat seed objects.
Related items (files, notes, tasks, contacts) linked through the relation system are tracked
in OpenRegister's pending `seed-related-items` openspec change (see
`openregister/openspec/changes/seed-related-items/`). Until that lands, seed data is limited
to object properties defined in schemas.

### Deduplication check

- Before proposing new capability: search `openspec/specs/` and `openregister/lib/Service/` for overlap
  with ObjectService, RegisterService, SchemaService, ConfigurationService, and shared Vue components.
- If similar capability exists: MUST reference it and explain why new code is needed rather than extending.
- Proposals duplicating existing functionality without justification MUST be rejected.
- **In design.md**: MUST include a "Reuse Analysis" section listing existing OpenRegister services leveraged.
- **In tasks.md**: MUST include a "Deduplication Check" task verifying no overlap — document findings
  even if "no overlap found".

### Schema migrations

- Breaking schema changes → new migration in repair step. NEVER modify existing migrations.

### OpenRegister + @conduction/nextcloud-vue — DO NOT REBUILD

The platform provides 258+ backend methods and 69+ frontend components. Apps ONLY build
custom logic for domain-specific business rules. Everything below is provided for FREE.

**CRUD & Data Management** (use ObjectService + CnIndexPage + CnDetailPage):
- Single & bulk create, read, update, delete — `ObjectService.saveObject()`, `deleteObject()`
- List with pagination, sorting, filtering — `ObjectService.findAll()` + `CnDataTable`
- Schema-driven forms — `CnFormDialog` (auto-generates from schema) or `CnAdvancedFormDialog`
- Detail views — `CnDetailPage` with `CnDetailGrid`, `CnDetailCard` sections
- Record merging/deduplication — `ObjectService.mergeObjects()`
- Object locking — `ObjectService.lockObject()` / `unlockObject()`

**Import & Export** (use ImportService/ExportService + CnMassImportDialog/CnMassExportDialog):
- CSV, Excel, JSON import with intelligent field mapping — `ImportService`
- CSV, Excel, JSON export with column selection — `ExportService`
- Bulk import with validation and progress — `CnMassImportDialog`
- Filtered export with format picker — `CnMassExportDialog`
- NO custom import dialogs, parsers, upload handlers, or export controllers

**Search & Discovery** (use IndexService + CnFilterBar + CnFacetSidebar):
- Full-text search with field weighting — `IndexService`
- Faceted navigation with counts — `FacetBuilder` + `CnFacetSidebar`
- Semantic search with embeddings — `VectorizationService`
- Hybrid search (keyword + semantic) — automatic
- Search analytics — `SearchTrailService` (popular terms, activity)
- NO custom search endpoints, query builders, or search pages

**File Management** (use FileService + CnObjectSidebar):
- Upload (single/multipart), download, share links — `FileService`
- File tagging, public/private toggle — `FileService`
- Bulk download as ZIP — `createObjectFilesZip()`
- Text extraction from PDFs/Office docs — `TextExtractionService`
- File tab in object sidebar — `CnObjectSidebar` → `CnFilesTab`
- NO custom file upload components, file controllers, or download handlers

**Audit & Compliance** (use AuditTrailService + CnObjectSidebar):
- Full change tracking with before/after snapshots — automatic
- Audit trail tab — `CnObjectSidebar` → `CnAuditTrailTab`
- GDPR data subject access requests — `inzageverzoek()`, `verwerkingsregister()`
- Audit export and analytics — `AuditTrailController`
- NO custom audit logging, change tracking, or compliance controllers

**Dashboard & Analytics** (use CnDashboardPage + CnChartWidget + CnStatsBlock):
- Drag-drop widget dashboard — `CnDashboardPage` with GridStack
- KPI cards — `CnKpiGrid`, `CnStatsBlock`, `CnStatsPanel`
- Charts (line/bar/pie/donut) — `CnChartWidget` (ApexCharts)
- Data tables as widgets — `CnTableWidget`
- Editable data grids — `CnObjectDataWidget`
- NO custom dashboard layouts, chart components, or KPI cards

**Forms & Dialogs** (use CnFormDialog + schema-driven generation):
- Auto-generated create/edit forms — `CnFormDialog` reads schema → generates fields
- JSON/metadata editing — `CnAdvancedFormDialog` with Properties/Data/Metadata tabs
- Schema editor — `CnSchemaFormDialog`
- Delete/Copy/Mass operations — `CnDeleteDialog`, `CnCopyDialog`, `CnMassDeleteDialog`
- NO custom form components, validation logic, or dialog wrappers

**Navigation & Pagination** (use CnPagination + CnActionsBar + useListView):
- Pagination control with size selector — `CnPagination`
- Action bar (add, search, toggle views) — `CnActionsBar`
- List state management — `useListView` composable (handles search, filter, sort, page)
- Detail state management — `useDetailView` composable
- NO custom pagination logic, debounced search, or list state management

**Authorization & RBAC** (use AuthorizationService + PropertyRbacHandler):
- Role-based access control — `AuthorizationService`
- Field-level permissions — `PropertyRbacHandler`
- Object-level restrictions — `PermissionHandler`
- Authorization audit — `AuthorizationAuditService`
- NO custom permission checks, role systems, or access control middleware

**Webhooks & Events** (use WebhookService):
- Create, test, retry webhooks — `WebhookService`
- CloudEvents format — automatic
- Event subscriptions — selective per schema/action
- NO custom webhook controllers or event dispatchers

**Notifications & Activity** (use NotificationService + ActivityService):
- Nextcloud notifications — `NotificationService`
- Activity feed — `ActivityService`
- Calendar events — `CalendarEventService`
- Deck/Kanban cards — `DeckCardService`

**Store & State** (use createObjectStore + plugins):
- Object stores — `createObjectStore(name)` generates Pinia CRUD store
- Store plugins: `auditTrails`, `files`, `lifecycle`, `relations`, `search`, `selection`
- Column/field/filter generation from schema — `columnsFromSchema()`, `fieldsFromSchema()`
- NO custom Pinia stores for CRUD, Vuex, or manual API call management

**Chat & AI** (use ChatService):
- Multi-turn conversation — `ChatService`
- RAG-based knowledge retrieval — `ContextRetrievalHandler`
- LLM response generation — `ResponseGenerationHandler`

**Data Retention & Archival** (use ArchivalService):
- Legal hold — `LegalHoldService`
- Destruction schedules — `DestructionService`
- Retention policies — `RetentionService`

**Semantic & Hybrid Search** (use SolrController + SettingsController):
- Semantic search via vector embeddings — `SettingsController.semanticSearch()`
- Hybrid search (keyword + semantic combined) — `SolrController.hybridSearch()`
- Vector embedding generation — `VectorizationService`
- NO custom search algorithms — configure via OpenRegister settings

**GraphQL API** (use GraphQLController):
- Query objects across schemas via GraphQL — `GraphQLController.execute()`
- Alternative to REST for complex cross-entity queries

**Organization / Multi-Tenancy** (use OrganisationController):
- Organization CRUD — `OrganisationController`
- Tenant-scoped data isolation — automatic via `TenantLifecycleService`
- NO custom multi-tenancy logic

**Task & Workflow Management** (use TasksController + WorkflowEngineController):
- Task creation and tracking — `TasksController`
- Workflow orchestration — `WorkflowEngineRegistry`
- Scheduled workflows — `ScheduledWorkflowController`
- NO custom task/workflow systems

**Text Extraction** (use FileTextController):
- Extract text from PDFs and Office docs — `TextExtractionService`
- Entity recognition (PII detection) — `EntityRecognitionHandler`
- Content anonymization — automatic

**Timeline & Stages** (use CnTimelineStages):
- Workflow progression visualization — `CnTimelineStages` component
- Stage tracking with status colors

### What apps SHOULD build (custom business logic only):
- External API integrations (SAP, Peppol, TenderNed, etc.)
- PDF/document generation with business-specific templates
- Workflow triggers and business rules specific to the domain
- Notification dispatch with app-specific event types
- Custom settings pages with app-specific configuration
- Background jobs for domain-specific processing

### ADR-002-api
- URL pattern: `/index.php/apps/{app}/api/{resource}` — lowercase plural, hyphens.
- Methods: GET=read, POST=create, PUT=update, DELETE=remove. No custom methods.
- Pagination: support `_page` + `_limit`. Response includes `total`, `page`, `pages`.
- Errors: appropriate HTTP status + `message` field. NO stack traces in responses.
- Auth: Nextcloud built-in only. NO custom login/session/token flows.
- Public endpoints: annotate `#[PublicPage]` + `#[NoCSRFRequired]`. Register CORS OPTIONS route.

### ADR-003-backend
- **Controller → Service → Mapper** (strict 3-layer). Controllers NEVER call mappers directly.
- Controllers: thin (<10 lines/method). Routing + validation + response only.
- Services: ALL business logic. Stateless — no instance state between requests.
- Mappers: DB CRUD only. No business logic.
- DI: constructor injection with `private readonly`. NO `\OC::$server` or static locators.
- Entity setters: POSITIONAL args only. `$e->setName('val')` — NEVER `$e->setName(name: 'val')`.
  (`__call` passes `['name' => val]` but `setter()` uses `$args[0]`.)
- Routes: `appinfo/routes.php`. Specific routes BEFORE wildcard `{slug}` routes.
- Config: `IAppConfig` with sensitive flag for secrets. NEVER read DB directly.
- Lifecycle: schema init via repair steps (`IRepairStep`), background via job queue, events via dispatcher.
- **Spec traceability**: every class and public method MUST have `@spec` PHPDoc tag(s) linking to
  the OpenSpec change that caused it: `@spec openspec/changes/{name}/tasks.md#task-N`.
  Multiple `@spec` tags allowed (code touched by multiple changes). File-level `@spec` in header docblock.
  This enables: code → docblock → spec traceability alongside code → git blame → commit → issue → spec.

### ADR-004-frontend
- **Vue 2 + Pinia + @nextcloud/vue + @conduction/nextcloud-vue**. NO Vuex. Options API only.
- State: Pinia stores in `src/store/modules/`. Use `createObjectStore` for OpenRegister CRUD.
- API calls: `axios` from `@nextcloud/axios` — auto-attaches CSRF token. NEVER raw `fetch()` for mutations.
  Loading state with `try/finally`.
- Translations: ALL user-visible strings via `t(appName, 'text')`. NO hardcoded strings.
  Translation keys MUST be English — Dutch translations go in `l10n/nl.json`.
- CSS: ONLY Nextcloud CSS variables (`var(--color-primary-element)`, etc.). NO hardcoded colors.
  NEVER reference `--nldesign-*` directly — nldesign app handles theming.
- Router: history mode, base `generateUrl('/apps/{app}/')`. Requires matching PHP routes in `routes.php`.
  Deep link URL templates MUST match the router mode — use path format (`/apps/{app}/entities/{uuid}`),
  NOT hash format (`/apps/{app}/#/entities/{uuid}`).
- OpenRegister dependency: settings returns `openRegisters` (bool) + `isAdmin`.
  Show empty state if OR missing. NEVER use `OC.isAdmin` — get from backend.
- NEVER `window.confirm()` or `window.alert()` — use `NcDialog` or `CnFormDialog` (WCAG, theming).
- NEVER read app state from DOM (`document.getElementById`, `dataset`) — use backend API or store.
- NEVER pass server-side data (e.g. app version) via DOM attributes. Use `IInitialState::provideInitialState('key', $value)` in PHP and `loadState('appid', 'key', default)` from `@nextcloud/initial-state` in Vue. DOM data-attributes are not the Nextcloud-idiomatic pattern and break on CSP-hardened instances.
- NEVER add admin settings Vue components (e.g. `AdminRoot.vue`) to the vue-router. Admin settings are registered via `AdminSettings.php` and rendered by Nextcloud's settings framework — adding them to the router makes them publicly accessible as frontend routes, bypassing all server-side access checks.
- NEVER create manual `<label>` elements for `NcSelect` — always use the built-in `inputLabel` prop (or `ariaLabelCombobox` for combobox mode). Manual labels break the component's internal accessibility wiring.
- NEVER write modal or dialog markup inline inside a parent component. Every modal/dialog MUST live in its own `.vue` file: `src/modals/` for `NcModal`-based components, `src/dialogs/` for `NcDialog`-based ones. Import and register it in the parent.
- EVERY `await store.action()` call MUST be wrapped in `try/catch` with user-facing error feedback.
- NEVER import from `@nextcloud/vue` directly — use `@conduction/nextcloud-vue` which re-exports all
  NC components plus Conduction components. This ensures consistent theming and component versions.
- EVERY component used in `<template>` MUST be imported AND registered in `components: {}`.
  Vue 2 silently renders unknown elements — missing imports cause invisible runtime failures.

### NL Design System

- ALL UI components MUST use CSS custom properties from NL Design System tokens.
- MUST support theme switching via nldesign app's token sets.
- MUST meet WCAG AA compliance: keyboard-navigable, associated labels, color is not the sole
  method of conveying information.
- SHOULD work on 320px–1920px viewports; critical functionality MUST work at 768px (tablet).
- Exceptions: PDF generation (docudesk), admin-only screens (simpler styling allowed).

### @conduction/nextcloud-vue — ALWAYS check before building custom

**Pages & Layout:**
  `CnIndexPage` (schema-driven list+CRUD) | `CnDetailPage` (detail+sidebar) |
  `CnPageHeader` (title+icon) | `CnActionsBar` (add+search+toggle)

**Data Display:**
  `CnDataTable` (sortable+paginated) | `CnCardGrid` + `CnObjectCard` (card views) |
  `CnDetailGrid` (label-value pairs) | `CnFilterBar` (search+filters) |
  `CnFacetSidebar` (faceted filters) | `CnPagination` | `CnCellRenderer` (type-aware)

**Forms & Dialogs:**
  `CnFormDialog` (schema-driven create/edit) | `CnAdvancedFormDialog` (properties+JSON+metadata) |
  `CnSchemaFormDialog` (JSON Schema editor) | `CnTabbedFormDialog` (tabbed form framework) |
  `CnDeleteDialog` | `CnCopyDialog`

**Mass Actions:**
  `CnMassDeleteDialog` | `CnMassCopyDialog` | `CnMassExportDialog` (CSV/JSON/XML) |
  `CnMassImportDialog` (upload+summary) | `CnMassActionBar` (floating selection bar)

**Dashboard & Widgets:**
  `CnDashboardPage` (GridStack drag-drop layout) | `CnDashboardGrid` (layout engine) |
  `CnWidgetWrapper` (widget shell) | `CnWidgetRenderer` (NC Dashboard API v1/v2) |
  `CnChartWidget` (ApexCharts: area/line/bar/pie/donut/radial) |
  `CnTableWidget` (data table widget) | `CnTileWidget` (quick-access tile) |
  `CnInfoWidget` (label-value grid) | `CnKpiGrid` (responsive KPI layout) |
  `CnStatsBlock` (metric card) | `CnStatsPanel` (stats sections) | `CnProgressBar` |
  `CnObjectDataWidget` (schema-driven editable data grid, inline edit + save via objectStore) |
  `CnObjectMetadataWidget` (read-only object metadata display)

**UI Elements:**
  `CnStatusBadge` | `CnEmptyState` | `CnIcon` (MDI) | `CnCard` | `CnDetailCard` |
  `CnRowActions` | `CnTimelineStages` (workflow progression) |
  `CnUserActionMenu` (user context menu) | `CnJsonViewer` (CodeMirror)

**Detail Sidebar:**
  `CnObjectSidebar` (Files/Notes/Tags/Tasks/Audit tabs) | `CnIndexSidebar` |
  `CnNotesCard` (inline notes) | `CnTasksCard` (inline tasks)

**Settings:**
  `CnSettingsSection` + `CnVersionInfoCard` (MUST be first on admin pages) |
  `CnSettingsCard` | `CnConfigurationCard` | `CnRegisterMapping`
  User settings: `NcAppSettingsDialog` (NOT `NcDialog`)

**Composables:**
  `useListView` (search/filter/sort/pagination) | `useDetailView` (load/edit/delete) |
  `useSubResource` (related items) | `useDashboardView` (widgets/layout/edit)

**Store Plugins:**
  `auditTrailsPlugin` | `relationsPlugin` | `filesPlugin` | `lifecyclePlugin` |
  `selectionPlugin` | `searchPlugin` | `registerMappingPlugin`

**Utilities:**
  `columnsFromSchema()` | `filtersFromSchema()` | `fieldsFromSchema()` |
  `formatValue()` | `buildHeaders()` | `buildQueryString()`

### Page Construction Patterns (follow these recipes)

**App.vue:** `NcContent` → 3 states: loading (`NcLoadingIcon`), no-OpenRegister (`NcEmptyContent`),
  ready (`MainMenu` + `NcAppContent` + `router-view` + optional `CnIndexSidebar`).
  Inject `sidebarState` for child components. `created()` calls `initializeStores()`.

**MainMenu:** `NcAppNavigation` with `NcAppNavigationItem` per route (icon + name + `:to`).
  Footer: `NcAppNavigationSettings` (gear foldout) with admin/config nav items.
  Settings item emits `@click="$emit('open-settings')"` — opens `NcAppSettingsDialog` modal.
  Do NOT route to `/settings` — in-app settings is a modal overlay, not a page.

**Dashboard:** `CnDashboardPage` with `CnStatsBlock` KPIs (4 cards: open/overdue/value/completed),
  status distribution chart, "My Work" list (grouped: overdue → due this week → rest).
  Fetch all collections in parallel via `Promise.all`. Widget templates via `#widget-{id}` slots.

**Index page:** `CnIndexPage` with `useListView(entityType, { sidebarState, objectStore })`.
  Inject sidebarState. Row click → `$router.push({ name: 'EntityDetail', params: { id } })`.
  Add button → new entity detail with id='new'.

**Detail page:** Two modes — edit (form component) / view (`CnDetailPage` + `CnDetailCard` sections).
  Header actions: Edit + Delete buttons. Related entities in table inside `CnDetailCard`.
  Props: `entityId` from route. `isNew = entityId === 'new'`. Sidebar via `CnObjectSidebar`.
  **Relations:** Every entity referenced in the spec MUST have a `CnDetailCard` section.
  Use `fetchUsed` for reverse lookups (find objects that reference THIS entity) and
  `fetchUses` for forward lookups (find objects THIS entity references).
  If the spec lists a "linked X section", it MUST be implemented — not deferred or stubbed.

**Settings — two surfaces, never a route:**
  *Admin settings* (`/settings/admin/{appid}`): `AdminRoot.vue` rendered by `settings.js` entry point,
  registered via `AdminSettings.php`. Layout: `CnVersionInfoCard` (FIRST) → `CnRegisterMapping` →
  `CnSettingsSection` per feature. Load via `GET /api/settings`, save via `POST /api/settings`.
  *In-app settings*: `UserSettings.vue` wrapping `NcAppSettingsDialog` — opened as a modal from the
  gear menu (`@open-settings` event on MainMenu), handled in `App.vue` with `:open` / `@update:open`.
  Do NOT create a `/settings` route. Do NOT create a standalone `SettingsView.vue` page component.

**Router:** Flat routes (no nesting), all named, props via arrow function for params.
  Routes: `/` (Dashboard), `/{entities}` (list), `/{entities}/:id` (detail).
  No `/settings` route — settings is a modal (see Settings section above).

**Store init:** `initializeStores()` in `store/store.js` — fetches settings, then calls
  `objectStore.registerObjectType(name, schemaSlug, registerSlug)` for each entity.
  Object store uses `createObjectStore` with plugins (files, auditTrails, relations).
  Settings store: Pinia `defineStore` with `fetchSettings()` and `saveSettings()`.

### Build / bundling — webpack.config.js

The base `@nextcloud/webpack-vue-config` ships sensible defaults, but most app
configs replace `webpackConfig.plugins` wholesale to add VueLoaderPlugin /
NodePolyfillPlugin without duplicates. That replacement strips the base's
`DefinePlugin` for `appName` / `appVersion` along with everything else. Every
config that touches `webpackConfig.plugins` MUST add them back explicitly.

- **MUST set `appName` and `appVersion` defines** when `webpackConfig.plugins` is replaced.
  `@nextcloud/vue` reads them at module-eval time as bare globals:

  ```js
  let realAppName = 'missing-app-name'
  try { realAppName = appName }     catch { logger.error('appName was not set...') }
  let realAppVersion = ''
  try { realAppVersion = appVersion } catch { logger.error('appVersion was not set...') }
  ```

  Without `DefinePlugin` replacing those bare identifiers at build time the try
  blocks throw and every widget mount logs `[ERROR] @nextcloud/vue: The
  '@nextcloud/vue' library was used without setting / replacing the 'appName'`.
  The required block, after `new VueLoaderPlugin()` and `new NodePolyfillPlugin(...)`:

  ```js
  new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
  new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
  ```

- **NEVER unconditionally override `devtool` to `inline-source-map`.** The earlier
  `webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'` line picks
  the right setting for both modes — both write the map to a separate `.js.map`
  file. An `inline-source-map` override base64-encodes the entire map *into*
  every emitted JS file, ~doubling each bundle. Source-map debugging works the
  same way either way; browser dev-tools pick up `.js.map` automatically.

- **SHOULD apply `optimization.splitChunks` when an app has 2+ entry-points** —
  each dashboard widget, settings page, and main bundle is one entry-point, and
  every entry independently inlines Vue + `@nextcloud/vue` + `pinia` +
  `vue-material-design-icons` + `@conduction/nextcloud-vue` (~3 MB minified)
  unless told otherwise. The base config sets `splitChunks` but only with the
  default `chunks: 'async'`, which never splits sync imports. Override to
  `chunks: 'all'` with explicit `cacheGroups` and **stable filenames** so each
  entry's PHP `Util::addScript` call can reference the chunk directly without a
  manifest:

  ```js
  webpackConfig.optimization = {
      ...(webpackConfig.optimization || {}),
      splitChunks: {
          ...(webpackConfig.optimization?.splitChunks || {}),
          chunks: 'all',
          cacheGroups: {
              default: false,
              defaultVendors: false,
              ncVue: {
                  name: appId + '-shared-nc-vue',
                  // Matches both node_modules entries AND the monorepo-dev alias
                  // `../nextcloud-vue/src/...` which webpack resolves outside
                  // node_modules when @conduction/nextcloud-vue is aliased to it.
                  test: /[\\/]node_modules[\\/](@nextcloud[\\/]vue|@conduction[\\/]nextcloud-vue)[\\/]|[\\/]nextcloud-vue[\\/]src[\\/]/,
                  priority: 30, reuseExistingChunk: true, enforce: true,
                  filename: appId + '-shared-nc-vue.js',
              },
              vendor: {
                  name: appId + '-shared-vendor',
                  test: /[\\/]node_modules[\\/](vue|pinia|vue-material-design-icons|@vueuse|core-js)[\\/]/,
                  priority: 20, reuseExistingChunk: true, enforce: true,
                  filename: appId + '-shared-vendor.js',
              },
          },
      },
  }
  ```

  Each entry's PHP `load()` then attaches the shared chunks **before** the
  per-entry bundle. `Util::addScript` dedupes by `(app, file)` so the shared
  chunks emit once even when every dashboard widget calls it:

  ```php
  Util::addScript(Application::APP_ID, Application::APP_ID.'-shared-vendor');
  Util::addScript(Application::APP_ID, Application::APP_ID.'-shared-nc-vue');
  Util::addScript(Application::APP_ID, Application::APP_ID.'-myWidget');
  ```

  Working examples: `pipelinq/webpack.config.js`, `procest/webpack.config.js`,
  `docudesk/webpack.config.js`. Order matters in PHP: vendor → nc-vue → entry.

- **TypeScript apps**: handle `.ts` via `babel-loader` + `@babel/preset-typescript`,
  NOT `ts-loader`. Mixing `ts-loader` with the base config's `babel-loader` for `.js`
  produces two different module-ID schemes; `splitChunks: { chunks: 'all' }` then
  fails at first widget mount with `TypeError: Cannot read properties of undefined
  (reading 'call')` because the per-widget runtime can't resolve modules emitted
  into shared chunks under the foreign ID space (even with `runtimeChunk: 'single'`).
  Babel handling both `.js` and `.ts` produces one consistent module graph that
  survives the split. Type-checking moves to `npx tsc --noEmit` (run separately in
  CI / IDE) — the build only strips types. Required pieces:

  ```js
  // .babelrc — add the preset alongside @babel/preset-env
  { "presets": ["@babel/preset-env", "@babel/preset-typescript"] }
  ```

  ```js
  // webpack.config.js — filter out the base's ts-loader rule, then add babel
  webpackConfig.module.rules = webpackConfig.module.rules.filter(rule =>
      !(rule && rule.use && (
          (typeof rule.use === 'string' && rule.use === 'ts-loader')
          || (Array.isArray(rule.use) && rule.use.some(u => (u?.loader || u) === 'ts-loader'))
          || (typeof rule.use === 'object' && rule.use.loader === 'ts-loader')
      ))
      && !(rule && rule.loader === 'ts-loader')
  )
  webpackConfig.module.rules.push({
      test: /\.ts$/,
      exclude: /node_modules/,
      use: { loader: 'babel-loader' },
  })
  ```

  Working example: `opencatalogi/webpack.config.js` (also includes the standard
  `appName`/`appVersion` `DefinePlugin` and the splitChunks block).

### ADR-005-security
- Auth: Nextcloud built-in ONLY. NO custom login, sessions, tokens, password storage.
- Admin check: `IGroupManager::isAdmin()` on BACKEND. Frontend-only checks = vulnerability.
- Per-object authorization (IDOR prevention): every mutation endpoint that operates on a specific
  object MUST check that the authenticated user owns, is in the group of, or is admin for THAT
  object — not just that they are logged in. `#[NoAdminRequired]` opens the endpoint to all users;
  without a per-object check, any user can modify any object by guessing its ID.
  Pattern: fetch object → extract `assigneeUserId`/`assigneeGroupId`/`createdBy` → check
  (owner OR in group OR admin) → throw `OCSForbiddenException` if none apply. Extract into a
  reusable `authorizeXxx(object, user)` service method, called from every PUT/POST/DELETE.
- Multi-tenant isolation: enforce at API/service level, not UI only.
- NO PII in logs, error responses, or debug output.
- Audit trails: use `$user->getUID()` — NEVER `$user->getDisplayName()` (mutable, spoofable).
- Identity: always derive from `IUserSession` on backend — NEVER trust frontend-sent user IDs or display names.
- Nextcloud endpoint defaults: NO annotation = admin-only. Non-admin endpoints (agent/staff actions)
  MUST have `#[NoAdminRequired]` attribute. Pair every `#[NoAdminRequired]` with a per-object auth
  check — never trust the session alone for mutation.
- **Auth attribute must match the method's actual requirement** (semantic consistency, not just
  syntactic presence — observed 2026-04-23 on decidesk#44 where the builder satisfied the route-
  auth gate by adding `#[NoAdminRequired]` to a method whose body calls `requireAdmin()`):
  - `#[PublicPage]` — genuinely public; body MUST NOT call `requireAdmin()`, `isAdmin()`, or
    return `Http::STATUS_UNAUTHORIZED/FORBIDDEN` conditionally. Use for login pages, OAuth
    callbacks, public manifests.
  - `#[NoAdminRequired]` — any authenticated user allowed; body MUST carry a per-object auth
    check (ADR-005 Rule 3 / `hydra-gate-no-admin-idor`). Body MUST NOT call `requireAdmin()` —
    that semantics belongs on `#[AuthorizedAdminSetting]` instead.
  - `#[AuthorizedAdminSetting(Application::APP_ID)]` — admin-only, framework-enforced at the
    middleware layer. Preferred for methods that call `requireAdmin()` / `isAdmin()` in body;
    lifts the check out of the controller into the routing table where it is declarative
    and grep-able.
  - No annotation — admin-only by Nextcloud default; prefer the explicit
    `#[AuthorizedAdminSetting]` for clarity.
  Enforcement: `hydra-gate-semantic-auth` (gate-9) catches common mismatches (`NoAdminRequired`
  + `requireAdmin()` body, `PublicPage` + body auth check). Gate-5 remains syntactic-only
  (attribute present); gate-9 is the semantic layer.
- Input validation: all user-supplied strings that flow into URLs (query params, path segments)
  MUST be URL-encoded (`encodeURIComponent` in Vue/JS, `rawurlencode` in PHP). Email Message-IDs,
  file names, and free-text fields commonly contain `<`, `>`, `/`, `@`, `&` which break unencoded.
- File uploads: validate type + size before storage.
- API responses: NO stack traces, SQL, or internal paths.
- Error messages: use static, generic messages (`'Operation failed'`, `'Not authorized'`) — NEVER
  return `$e->getMessage()` to clients. Log the real error server-side with `$this->logger->error()`.
- Test collections: NEVER commit default credentials — use env variable placeholders.

### ADR-006-metrics
- Every app: `GET /api/metrics` (Prometheus text, admin auth) + `GET /api/health` (JSON, public).
- Metric names: `{app}_` prefix. MUST include `{app}_health_status` and `{app}_info`.
- Health check MUST verify OpenRegister connectivity (for apps that depend on it).

### ADR-007-i18n
# ADR-007: Internationalization (i18n)

## Status
Accepted

## Context
All Conduction Nextcloud apps serve Dutch government users but must support multiple languages. We need a consistent approach to internationalization across all apps.

## Decision

### Primary Language: English
- **English (en) is the source/primary language** for all code and translation keys.
- All `t()` keys and `$this->l10n->t()` strings MUST be written in English.
- `l10n/en.json` is the identity-mapped source file (key == value).
- Hardcoded Dutch strings in code MUST be converted to English keys with Dutch translations in `nl.json`.

### Sentence Case for All UI Strings
- All translation keys and user-facing strings MUST use **sentence case**: only the first word is capitalized.
- Correct: `"Add directory"`, `"No results found"`, `"Delete selected"`, `"Save configuration"`
- Wrong (title case): `"Add Directory"`, `"No Results Found"`, `"Delete Selected"`
- Wrong (all lowercase): `"add directory"`, `"no results found"`
- **Exceptions** that keep their capitalization:
  - Proper nouns and product names: `"OpenRegister"`, `"Nextcloud"`, `"GitHub"`, `"DocuDesk"`
  - Acronyms: `"API"`, `"URL"`, `"PDF"`, `"SOLR"`, `"JSON"`, `"RBAC"`, `"OAS"`
  - Single-word strings still start with a capital: `"Delete"`, `"Search"`, `"Save"`

### Required Languages
- Minimum: English (en) + Dutch (nl) translations.
- `l10n/en.json` and `l10n/nl.json` MUST exist in every app with a UI.
- Both files MUST contain exactly the same keys, with zero gaps.

### Frontend Translation
- JS: `t(appName, 'key')` for singular, `n(appName, 'singular', 'plural', count)` for plurals.
- `Vue.mixin({ methods: { t, n } })` for Options API components.
- `<script setup>` components MUST import `t` directly from `@nextcloud/l10n` (mixin does not apply).

### Backend Translation
- PHP: `$this->l10n->t('key')` for user-facing messages in JSONResponse.
- Controllers returning user-facing messages MUST inject `OCP\IL10N`.
- Log messages, internal exceptions, and database values are NOT translated.

### API and Data
- API field names: always English (language-neutral data layer).
- Date/number formatting: respect user locale via Nextcloud core.
- Each app with OpenRegister: define `register-i18n` spec listing translatable fields.

### Shared Component Library (@conduction/nextcloud-vue)
- The shared library does NOT translate internally — it accepts pre-translated strings via props.
- Components have English defaults for all label/text props (e.g., `addLabel="Add"`, `cancelLabel="Cancel"`).
- Consumer apps are responsible for passing `t()` results as prop values.
- The library lists `@nextcloud/l10n` as a peer dependency, not a direct dependency.

## Consequences
- All apps maintain two translation files that must stay in sync.
- Dutch strings used as translation keys (e.g., `t('app', 'Besluiten')`) are a violation — the English equivalent must be the key.
- Title case in translation keys (e.g., `"Add Directory"`) is a violation — use sentence case (`"Add directory"`).
- New features must include both `en.json` and `nl.json` entries before merging.

### ADR-008-testing
- Every new PHP service/controller → PHPUnit tests in `tests/Unit/` (≥3 methods).
- Every new Vue component → test file (if test framework exists).
- Every new API endpoint → Newman/Postman collection in `tests/integration/`.
- Every spec scenario → browser test (GIVEN/WHEN/THEN verified via Playwright).
- All tests MUST pass in `composer check:strict`.
- Integration tests MUST cover error paths (403, 401, 400) — not just happy path (200).
- Test collections: use env variable placeholders for credentials — NEVER hardcode defaults.

### Smoke testing (before opening PR)

After implementing, verify your code actually works — quality gates catch lint/types, not logic:

1. Call each new API endpoint with `curl` — verify response shape and status code
2. Test at least one error path per endpoint (missing param, wrong auth, invalid input)
3. If the spec says a feature is deferred, verify it is NOT registered/enabled
4. If tasks.md marks a task `[x]`, verify it is fully implemented — not a stub or TODO

### Task completeness verification

Before marking a task `[x]` in tasks.md or opening a PR:
- Re-read every task in tasks.md
- For each `[x]` task, verify the implementation exists AND works — not a placeholder
- Stub components, empty relation sections, and TODO comments are NOT complete
- If a task cannot be completed, leave it `[ ]` and explain in the PR description

### See also

- [ADR-029: Route reachability gate](adr-029-route-reachability-gate.md) —
  the per-PR mechanical gate that validates every controller method
  registered in `appinfo/routes.php` is reachable from at least one
  Vue/REST entry point. Closes the "tested but never wired up" failure
  mode the smoke-testing rule above addresses interactively.

### ADR-009-docs
- Every user-facing feature → docs in `docs/` with screenshots from running app.
- English primary, Dutch recommended. Update docs when behavior changes.

### ADR-010-nl-design
- ALL UI: CSS custom properties from NL Design System tokens. NO hardcoded colors, fonts, spacing.
- Theme switching: support `nldesign` app's token sets (Rijkshuisstijl, Utrecht, municipality-specific).
- Components: `@nextcloud/vue` primary. Custom components styled via NL Design tokens only.
- Scoped styles: ALL `<style>` blocks MUST use `scoped` attribute.
- WCAG AA mandatory: keyboard-navigable, labelled forms, color not sole conveyor, alt text on images.
- Responsive: work from 320px to 1920px. Critical features accessible at 768px.
- Specs: reference token names ("primary action color") NOT hex values. Include a11y verification in ACs.
- Exception: PDF generation (docudesk) may use fixed dimensions. Admin screens MAY simplify but MUST meet WCAG AA.

### ADR-011-schema-standards
- schema.org types/properties as primary vocabulary (`schema:Person`, `schema:Organization`, `schema:Event`).
- Contact schemas: align with vCard properties (`fn`, `email`, `tel`, `adr`).
- Dutch government fields: mapping layer translating between international standards and Dutch APIs (VNG, ZGW).
- NO custom property names when schema.org equivalent exists.
- Relations: OpenRegister relation mechanism (register + schema + objectId). NO foreign keys or embedded objects.
- Versioning: removing/renaming properties = BREAKING → migration via repair step. Adding optional = non-breaking.
- Specs MUST define data models using schema.org vocabulary; design docs MUST include schema definitions with types, required flags, relations.
- Exception: app-specific workflow states (pipeline stages, process statuses) MAY use custom vocabularies.

### ADR-012-deduplication
- Before proposing new capability: search OpenRegister specs + services for overlap. Reference + justify if similar exists.
- Design docs MUST include "Reuse Analysis" listing which OpenRegister services are leveraged.
- If logic could benefit other apps → propose adding to OpenRegister core, not app-specific.
- Tasks MUST include "Deduplication Check" verifying no overlap with:
  ObjectService, RegisterService, SchemaService, ConfigurationService, shared specs, @conduction/nextcloud-vue.
- Document findings even if "no overlap found".
- Exception: OpenRegister checks internal duplication only. nldesign checks token sets. nextcloud-vue checks own components.

### ADR-013-container-pool
# ADR-013: Unified Container Pool

**Status:** accepted
**Date:** 2026-04-12

## Context

Specter (intelligence/research) and Hydra (build/review/merge) both run LLM workloads in Docker containers. Today they operate independently: Hydra spins up builder/reviewer/security containers on demand, Specter has a separate `run_llm_containers.sh` wrapper. Both compete for the same Claude Max rate limits.

We want to unify these into a **single priority-scheduled container pool** so that:
- Critical work (bugfixes, reviews) preempts lower-priority work (discovery, research)
- A fixed number of containers (e.g. 10) run continuously, pulling from a shared queue
- Token rotation and rate limit recovery happen at the pool level, not per-script
- Adding a new workload type (audit, spec generation, test) is just a new queue entry

## Decision

### Container types (priority order)

| Priority | Type | Source | Container image | Model | Fallback |
|----------|------|--------|-----------------|-------|----------|
| 1 | **code-review** | Hydra: PR code review + in-container fixes | `hydra-reviewer` | sonnet | opus |
| 2 | **security-review** | Hydra: PR security review + in-container fixes | `hydra-security` | sonnet | opus |
| 3 | **applier** | Hydra: binary go/no-go gate (no fix authority) | `hydra-applier` | sonnet | opus |
| 4 | **build** | Hydra: initial spec build | `hydra-builder` | haiku | — |
| 5 | **audit** | Hydra: codebase audit | `hydra-builder` | sonnet | opus |
| 6 | **spec-generation** | Specter: push_spec_pipeline | `specter-llm-worker` | sonnet | haiku |
| 7 | **schema-synthesis** | Specter: generate/dedup schemas | `specter-llm-worker` | haiku | — |
| 8 | **classification** | Specter: classify/redistribute features | `specter-llm-worker` | haiku | — |
| 9 | **translation** | Specter: translate requirements | `specter-llm-worker` | haiku | — |
| 10 | **discovery** | Specter: research, feature extraction | `specter-llm-worker` | haiku | — |

**No-loop policy (openspec/changes/no-loop-review-pipeline):** Reviewers own fix
authority. The Applier is a read-only final gate that emits a binary pass/fail
verdict — it never modifies files. Every post-review outcome is terminal:
merge (on `applier:pass` or reviews passed with zero fixes) or `needs-input`
(on `applier:fail`, reviewer `agent-maxed-out`, or post-review deterministic
check failure). There is no fix-iteration loop and no `bugfix` container.

### Model strategy

**Principle:** Use the cheapest model that can do the job. Reserve expensive models for judgment work.

| Work type | Model | Rationale |
|-----------|-------|-----------|
| Build (implementation) | **Haiku** | Clear instructions (tasks.md, design.md). Pattern-following, not judgment. Faster and cheaper — 5 parallel Haiku builds burn far less quota than Sonnet. |
| Fix-quality / fix-browser (pre-review) | **Haiku** | "Fix this PHPCS error" or "fix this browser test failure" — explicit, targeted corrections triggered by deterministic check output during the build phase. |
| Code review (+ in-container fix authority) | **Sonnet → Opus** | Judgment + bounded fixes. Sonnet is the primary; falls back to Opus when Sonnet quota exhausted. Budget: 40 turns (up from 20) to cover review + self-verified fixes. |
| Security review (+ in-container fix authority in PR mode) | **Sonnet → Opus** | Critical: injection vectors, auth bypasses, secret leaks. Same fallback logic. Budget: 40 turns in PR mode, 120 in full-audit mode (audit mode has no fix authority). |
| Applier (Axel Pliér) | **Sonnet → Opus** | Final binary go/no-go. No fix tools. Reads hydra.json + PR state + ADRs, emits `{pass, blocking[]}`. Budget: 20 turns. |
| Audit | **Sonnet → Opus** | Full codebase analysis — needs depth. |

**Quota optimization:** Claude Max plans have separate "Sonnet only" and "all models" weekly limits. By defaulting builders to Haiku, the Sonnet quota is reserved for reviews only (~20 turns each, 2 per PR). When Sonnet runs out, reviews fall back to the **deeper** model (Opus), not the shallower one — because reviews are the last line of defense before human approval.

**Overrides:** Set `HYDRA_BUILDER_MODEL`, `HYDRA_REVIEWER_MODEL`, or `HYDRA_REVIEWER_FALLBACK_MODEL` env vars to change defaults.

### Architecture

```
┌─────────────────────────────────────────────────────┐
│  Scheduler (cron or daemon)                         │
│                                                     │
│  reads: queue table (postgres)                      │
│  writes: container assignments, status updates      │
│                                                     │
│  ┌──────────────────────────────────────────┐       │
│  │ Pool: 10 container slots                 │       │
│  │                                          │       │
│  │  slot-1: [bugfix]     ← highest prio     │       │
│  │  slot-2: [code-review]                   │       │
│  │  slot-3: [build]                         │       │
│  │  slot-4: [build]                         │       │
│  │  slot-5: [classify]                      │       │
│  │  slot-6: [classify]                      │       │
│  │  slot-7: [translate]                     │       │
│  │  slot-8: [discovery]                     │       │
│  │  slot-9: [idle]       ← waiting for work │       │
│  │  slot-10: [idle]                         │       │
│  └──────────────────────────────────────────┘       │
│                                                     │
│  Token rotation: credentials.json (work → private)  │
│  Rate limit: pool-level tracking per account        │
│  Preemption: low-prio containers stopped when       │
│              high-prio work arrives and pool is full │
└─────────────────────────────────────────────────────┘
```

### Queue table (future)

```sql
CREATE TABLE container_queue (
    id SERIAL PRIMARY KEY,
    type VARCHAR(50) NOT NULL,        -- bugfix, code-review, build, classify, etc.
    priority INTEGER NOT NULL,         -- 1=highest
    payload JSONB NOT NULL,            -- script args, spec slug, issue URL, etc.
    status VARCHAR(20) DEFAULT 'pending', -- pending, running, completed, failed
    container_id VARCHAR(100),         -- docker container name when running
    token_account VARCHAR(50),         -- which OAuth account is assigned
    created_at TIMESTAMP DEFAULT NOW(),
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    exit_code INTEGER,
    error_message TEXT
);
```

### Phased rollout

**Phase 1 (now):** All LLM calls containerized. Specter scripts run via `run_llm_containers.sh`. Hydra containers use `run_container_with_fallback`. Both read from `credentials.json`. No shared queue yet — each system schedules its own containers.

**Phase 2:** Shared queue table. A single scheduler script replaces both `cron-hydra.sh` dispatch and `run_llm_containers.sh`. Pool size configurable. Priority enforcement by not starting low-prio work when high-prio is queued.

**Phase 3:** Preemption. Running low-priority containers can be stopped (gracefully, with checkpoint) when high-priority work arrives and all slots are occupied. Container images support checkpoint/resume via DB state.

### Current state (Phase 1)

**Container images:**

| Image | Size | Purpose |
|-------|------|---------|
| `conduction/nextcloud-test:stable31` | 1.5GB | Prebuild NC server + PostgreSQL + OpenRegister (cloned) |
| `hydra-builder:latest` | 1.9GB | Code implementation: NC test env + Claude CLI + PHP + skills |
| `hydra-reviewer:latest` | 1.3GB | Code review + bounded in-container fix authority (Juan Claude van Damme) |
| `hydra-security:latest` | 1.9GB | Security review + bounded in-container fix authority (Clyde Barcode) |
| `hydra-applier:latest` | 1.0GB | Binary go/no-go gate; no Write/Edit tools (Axel Pliér) |
| `specter-spec-writer:latest` | ~800MB | Spec generation: Claude CLI + openspec CLI + skills (no PHP) |
| `specter-llm-worker:latest` | ~500MB | Intelligence pipeline: Claude CLI + DB access |

**Credential separation:**
- **Specter:** `concurrentie-analyse/secrets/credentials.json` (work + private tokens)
- **Hydra:** `hydra/secrets/credentials.json` (work token only)

**Token detection:**
- Container mode: uses exit code (0 = success, non-zero checks output for rate limit)
- Local mode: checks output text for "rate limit" / "auth failed" strings

**NC test environment:**
- Prebuild image with PostgreSQL (matches production, not SQLite)
- Builder `COPY --from=conduction/nextcloud-test` at build time
- Entrypoint starts PG + enables OpenRegister at runtime
- Each container gets its own isolated NC+PG instance

**Spec generation flow:**
- `push_spec_pipeline.py` prepares repos in parallel, generates in `specter-spec-writer` containers
- Each spec gets its own container + clone (compartmentalized)
- Dependency tiers control ordering: Phase 1 → Phase 2 → Phase 3 → Phase 4
- Specs with met deps push to development directly (doc-only merge guard)
- Issues created with `yolo` label → Hydra auto-builds, reviews, merges, closes issue

### Container capability profiles

Each container persona runs with a different Linux capability set determined by the trust we extend to it. This is load-bearing for runtime behaviour — a container's `/workspace` is ONLY writable by the claude user if the build or the entrypoint arranges it, and the two code paths diverge based on cap profile.

| Persona | Caps added | Claude user | Workspace setup |
|---------|-----------|-------------|-----------------|
| Builder | SETUID, SETGID, DAC_OVERRIDE, CHOWN, FOWNER | Dropped via `gosu` at run time | Entrypoint chowns at start, relies on DAC_OVERRIDE |
| Reviewer | SETUID, SETGID, DAC_OVERRIDE, CHOWN, FOWNER | Same as builder | Same — entrypoint chown |
| Security | SETUID, SETGID, DAC_OVERRIDE, CHOWN, FOWNER | Same | Same |
| **Applier** | **None** (minimum-cap — read-only judge) | **Runs as `claude:claude` via `docker --user`** (no gosu drop possible — can't setuid without SETUID) | **Must be pre-chowned at IMAGE BUILD TIME** — no runtime chown possible |

**The applier's minimum-cap profile has a hard consequence:** its Dockerfile MUST contain
```dockerfile
RUN mkdir -p /workspace && chown claude:claude /workspace && chmod 0775 /workspace
```
before the `WORKDIR /workspace` directive. Otherwise the non-root claude user cannot write files into its own workdir, `hydra_prefetch_pr_context` silently fails every redirect, Claude runs 0 turns, and the orchestrator records `pass=null, turns=0 → applier:fail`. Observed on decidesk#44 2026-04-23 06:01 UTC — looked like a harness bug, real cause was one missing `chown` line in the Dockerfile.

This is **the rule for any future minimum-cap persona**: if you drop DAC_OVERRIDE + SETUID for security reasons, the Dockerfile owns workspace ownership — the entrypoint cannot.

## Consequences

- All LLM calls go through containers — no direct `claude -p` from host scripts
- Token management is centralized per system (Specter has private fallback, Hydra doesn't)
- Container exit code determines token rotation (not mid-session JSONL text)
- Prebuild NC image eliminates 30-60s clone overhead per builder container
- Container images are the unit of deployment — version, test, rollback independently
- ADR-000 convention: every repo's data model is at `openspec/architecture/adr-000-data-model.md`
- `context-brief.md` in each change directory carries intelligence data through the full pipeline
- Minimum-cap containers (applier) require Dockerfile-time workspace chown; higher-cap containers can chown at runtime. This split is permanent — don't ship a new minimum-cap persona without pre-chowning.

### ADR-014-licensing
- Licence: EUPL-1.2 (European Union Public Licence).
- `appinfo/info.xml`: MUST use `<licence>agpl</licence>` — Nextcloud app store does not recognise EUPL.
- This is intentional dual-tagging, NOT a conflict. Do NOT change info.xml to eupl. Do NOT flag as review finding.

## PHP files — PHPDoc tags only

License and copyright metadata on PHP files lives **only** in the main file docblock as PHPDoc tags:

```php
<?php

/**
 * Short Description
 *
 * Longer description.
 *
 * @category Controller
 * @package  OCA\{AppName}\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/{change-name}/tasks.md#task-N
 */

declare(strict_types=1);
```

**Required tags on every PHP file:** `@author`, `@copyright`, `@license`, `@link`, `@spec`. File-level `@spec` links back to the OpenSpec change that created or last modified the file (ADR-003). Classes and public methods also carry their own `@spec` tag.

**Do NOT add:**
- `SPDX-FileCopyrightText: ...` lines in the docblock — that duplicates `@copyright`.
- `SPDX-License-Identifier: ...` lines in the docblock — that duplicates `@license`.
- `// SPDX-*` line comments before or after the docblock.

## Vue / JS / CSS files

These file types don't carry PHPDoc. Use SPDX header as the first line:

- Vue: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
- JS / TS: `// SPDX-License-Identifier: EUPL-1.2`
- CSS / SCSS: `/* SPDX-License-Identifier: EUPL-1.2 */`

## Repo-level REUSE compliance

Every app repo SHOULD carry a `REUSE.toml` at its root declaring license + copyright for every file pattern. This is the authoritative source for REUSE compliance — `reuse lint` reads it instead of requiring per-file SPDX headers for PHP files:

```toml
version = 1

[[annotations]]
path = "**/*.php"
SPDX-FileCopyrightText = "2026 Conduction B.V. <info@conduction.nl>"
SPDX-License-Identifier = "EUPL-1.2"
```

## Hydra quality gate

`scripts/run-quality.sh`'s `spdx-headers` gate enforces: every `lib/**/*.php` file has both `@license` and `@copyright` PHPDoc tags. Missing either fails the gate.

### ADR-015-common-patterns
- Common Conduction patterns. These apply to ALL apps. Every item below was found 3+ times
  across multiple code reviews. Get these right during implementation — not after review.
- When fixing any pattern violation, ALWAYS generalize: grep for the same issue across ALL
  files and fix every instance in one pass. Fixing one file while leaving the same issue in
  nine others guarantees another review round.

### OpenRegister ObjectService API
- `findObject($register, $schema, $id)` — 3 positional args, register first
- `findObjects($register, $schema, $params)` — 3 positional args, $params is filter array
- `saveObject($register, $schema, $object)` — 3 positional args, $object is array
- NEVER `getObject($id)` or `saveObject($data)` — those 1-arg signatures do not exist
- When unsure, check the OpenRegister source or existing app code

### Store registration (Vue/Pinia)
- Register each entity type ONCE in `src/store/store.js` via `createObjectStore`
- NEVER register in both `OBJECT_TYPES` and `ENTITY_STORES` — pick one pattern
- Type names: kebab-case (`action-item`), NOT camelCase (`actionItem`)
- Use platform `createObjectStore` — do NOT build custom stores (hand-rolled object.js)

### Authorization enforcement
- ALL mutation endpoints MUST have `IGroupManager::isAdmin()` check on backend
- Settings endpoints: `#[AuthorizedAdminSetting]` or `@RequireAdmin` annotation
- NEVER rely on frontend-only auth — always enforce on backend
- User identity: derive from `IUserSession` — NEVER trust frontend-sent user IDs
- Null dependency checks: throw 503, do NOT silently return empty response

### Error responses
- NEVER return `$e->getMessage()` to API — use static, generic error messages
- Pattern: `catch (\Throwable $e) { return new JSONResponse(['message' => 'Operation failed'], 500); }`
- Log the real error: `$this->logger->error('Context', ['exception' => $e]);`
- Frontend: EVERY `await store.action()` MUST be in `try/catch` with user feedback

### API calls & CSRF
- Use `axios` from `@nextcloud/axios` for ALL API calls — it auto-attaches the CSRF token
- NEVER use raw `fetch()` for mutations — missing requesttoken causes silent 403 failures
- Pattern: `import axios from '@nextcloud/axios'` + `const { data } = await axios.post(url, payload)`

### Vue component imports
- NEVER import from `@nextcloud/vue` directly — use `@conduction/nextcloud-vue` which re-exports everything
- EVERY component used in `<template>` MUST be imported AND listed in `components: {}`
- Vue 2 silently renders unknown elements — a missing import = invisible runtime failure
- Pre-commit check: for every `<NcFoo>` or `<CnFoo>` in template, verify the import exists

### SPDX headers (see also ADR-014)
- EVERY new file needs an SPDX header — apply to ALL new files in one pass
- PHP: `// SPDX-License-Identifier: EUPL-1.2` after `<?php`
- Vue: `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line
- JS: `// SPDX-License-Identifier: EUPL-1.2` as first line

### Dependency management
- When importing from a package, verify it exists in `package.json` before committing
- `@nextcloud/auth` for `getRequestToken()` — add to dependencies if missing
- Run `npm ci && npm run lint` to catch `n/no-extraneous-import` BEFORE pushing

### Translations (i18n)
- ALL user-visible strings: `this.t('appid', 'text')` in Vue, `$this->l->t('text')` in PHP
- NEVER hardcode Dutch or English strings in templates, CSV headers, or notifications
- NEVER bare `t()` in Vue — always `this.t()` (Options API)

### Data patterns
- Relations: verify `fetchUsed` vs `fetchUses` direction — wrong direction = empty cards
- Lifecycle: use the service's `transitionLifecycle()` — NEVER `saveObject()` directly for status
- Pagination: `_limit: 999` silently undercounts — use proper pagination or document the cap

### Nextcloud UI patterns
- NEVER `window.confirm()` or `window.alert()` — use `NcDialog` or `CnFormDialog`
- NEVER read app state from DOM (`document.getElementById`, `dataset`) — use backend API
- Audit trails: use `$user->getUID()` — NEVER `$user->getDisplayName()` (mutable, spoofable)
- Deferred features: if spec says "defer to phase N", do NOT register/enable them in info.xml or anywhere else
- Router: history mode with `generateUrl` base (see ADR-004). Deep link URLs must use path format, NOT hash format.
- Relations: `fetchUsed` = reverse lookup (who references me), `fetchUses` = forward lookup (what do I reference)
- Detail views: every spec-required "linked X section" MUST have a `CnDetailCard` — never stub or omit

### Pre-commit verification (run before EVERY commit)

Before committing, verify your code against these patterns:

1. **SPDX headers**: `grep -rL 'SPDX-License-Identifier' src/ lib/ --include='*.php' --include='*.vue' --include='*.js'`
   → Add headers to EVERY file missing one — all of them, not just one.
2. **ObjectService calls**: `grep -rn 'findObject\|saveObject\|findObjects' lib/ --include='*.php'`
   → Verify every call has 3 positional args: `($register, $schema, $idOrParams)`
3. **Error responses**: `grep -rn 'getMessage()' lib/Controller/ --include='*.php'`
   → Replace any `$e->getMessage()` in JSONResponse with a static error string
4. **Auth checks**: For every POST/PUT/DELETE controller method, verify `IGroupManager::isAdmin()` is called
5. **Store registration**: `grep -rn 'registerObjectType\|OBJECT_TYPES\|ENTITY_STORES' src/`
   → Verify each entity registered exactly once, kebab-case names
6. **Dependencies**: `npm run lint` — catches missing package.json entries
7. **Translations**: `grep -rn "'" src/ --include='*.vue' | grep -v "this\.t\|import\|//\|console"` — scan for hardcoded strings
8. **try/catch**: `grep -rn 'await.*Store\.' src/ --include='*.vue'` — verify every store call is wrapped
9. **No raw fetch**: `grep -rn 'fetch(' src/ --include='*.vue' --include='*.js'` — must use `@nextcloud/axios`, not raw fetch (CSRF)
10. **Import source**: `grep -rn "from '@nextcloud/vue'" src/` — must be zero matches. Use `@conduction/nextcloud-vue` instead.
11. **Component imports**: for every `<NcFoo>` or `<CnFoo>` in templates, verify the component is imported AND in `components: {}`
12. **Type slug consistency**: verify every entity type string across ALL files (store, search, routes, views) uses the same kebab-case slug — `grep -rn "agendaItem\|governanceBody\|actionItem" src/` should return zero matches
13. **Translation keys**: `grep -rn "t('.*'," src/ --include='*.vue' --include='*.js'` — verify ALL t() keys are English, not Dutch. Dutch translations go in `l10n/nl.json`.
14. **Route consistency**: verify every entity type referenced in search, navigation, or links has a matching named route in `src/router/`
15. **Task completeness**: re-read tasks.md — every `[x]` task must be fully implemented, not a stub

If ANY check fails, fix ALL instances (not just the first one) before committing.

### ADR-016-routes
- Routes: `appinfo/routes.php` is the ONLY registration path. NO runtime-registered routes, NO route
  fragments in `info.xml`, NO bootstrapped route providers added from `Application::register()`.
- `info.xml` is app metadata only (name, version, dependencies, categories, screenshots). It must
  never carry `<route>` / `<navigation>` entries that map URLs to controllers.
- Every route entry names `controller#method` explicitly — no wildcard auto-discovery, no regex
  generators. Snake_case controller maps to CamelCase class: `meeting#public_state` →
  `MeetingController::publicState()`. Lowering discoverability is the point: grepping `routes.php`
  returns the full URL surface area of the app.
- Admin settings pages: register the settings section via `\OCP\Settings\ISection` in
  `Application::register()`, but the settings URL itself is a standard `appinfo/routes.php` entry
  pointing at a controller method marked with `#[AuthorizedAdminSetting(Application::APP_ID)]`.
- Public (unauthenticated) endpoints: declare `#[PublicPage]` + `#[NoCSRFRequired]` on the method,
  and keep the route in `appinfo/routes.php` — do not invent a separate public-routes file.
- Rationale: the mechanical gates (`hydra-gate-route-auth`) scan `appinfo/routes.php` only. Every
  endpoint living there gets its auth attribute verified; an endpoint registered elsewhere
  bypasses the gate and can ship to production without its middleware posture checked. One file,
  one gate, no drift.
- Gate layering: `hydra-gate-route-auth` (gate-5) is **syntactic** — it verifies the method
  carries any of the four valid auth attributes (`#[PublicPage]` / `#[NoAdminRequired]` /
  `#[NoCSRFRequired]` / `#[AuthorizedAdminSetting]`). It does NOT check that the chosen attribute
  matches the method's actual requirement. The **semantic** layer is `hydra-gate-semantic-auth`
  (gate-9) which enforces attribute-to-body consistency per ADR-005. Both gates must pass —
  syntactic alone produces the "minimum-to-clear-the-gate" anti-pattern where a builder adds
  the cheapest attribute (`#[NoAdminRequired]`) to a method whose body calls `requireAdmin()`
  just to pass gate-5. See ADR-005 for the full attribute-to-body mapping.
- Migration: any app with routes declared in `info.xml` or injected via `Application::boot()` must
  move them to `appinfo/routes.php` before the next build — the gate treats such endpoints as
  absent, and any related controller method without an auth attribute will surface as a FAIL.

### ADR-017-component-composition
# ADR-017: Component Composition Rules

## Status
Accepted

## Date
2026-04-14

## Context

Conduction apps share a Vue component library (`@conduction/nextcloud-vue`) that provides self-contained, higher-level components like `CnObjectDataWidget`, `CnStatsPanel`, `CnDetailPage`, and `CnTimelineStages`. These components internally render their own card wrappers (`CnDetailCard`), headers, and layout containers.

Developers have been wrapping these self-contained components inside additional layout containers (e.g. `CnDetailCard` wrapping `CnObjectDataWidget`), producing a "card-in-card" visual artifact where headers and borders are doubled. This was found across Procest, Pipelinq, and earlier OpenCatalogi iterations.

The same principle applies to `CnDetailPage` which renders its own `NcAppContent` wrapper — apps must not add another `NcAppContent` around it.

## Decision

### Self-contained components render their own container

The following components are **self-contained** and MUST NOT be wrapped in `CnDetailCard`, `NcAppContent`, or other layout containers:

| Component | Renders its own | Use directly inside |
|---|---|---|
| `CnObjectDataWidget` | `CnDetailCard` | `CnDetailPage` slot, `<div>`, or grid cell |
| `CnObjectMetadataWidget` | `CnDetailCard` | `CnDetailPage` slot, `<div>`, or grid cell |
| `CnStatsPanel` | Sections with headers | `CnDetailPage` slot or `<div>` |
| `CnDetailPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnDashboardPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnIndexPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnTimelineStages` | Standalone timeline | Inside `CnDetailCard` or any container (no own card) |

### How to identify self-contained components

A component is self-contained if its template root is a card, panel, or page-level wrapper. Check the component source: if it starts with `<CnDetailCard>`, `<div class="cn-*-card">`, or similar, it manages its own container.

### Correct patterns

```vue
<!-- CORRECT: CnObjectDataWidget renders its own card -->
<CnObjectDataWidget
  :schema="schema"
  :object-data="data"
  title="Case Information" />

<!-- CORRECT: CnTimelineStages is NOT self-contained, wrap it -->
<CnDetailCard :title="t('app', 'Status')">
  <CnTimelineStages :stages="stages" :current-stage="current" />
</CnDetailCard>
```

### Anti-patterns

```vue
<!-- WRONG: Double card wrapping -->
<CnDetailCard :title="t('app', 'Case Information')">
  <CnObjectDataWidget :schema="schema" :object-data="data" />
</CnDetailCard>

<!-- WRONG: Double page wrapping -->
<NcAppContent>
  <CnDetailPage :title="title">...</CnDetailPage>
</NcAppContent>
```

### External sidebar pattern

Components like `CnDetailPage` that support sidebars communicate with a parent-provided `objectSidebarState` via Vue's `provide`/`inject`. The sidebar component (`CnObjectSidebar`) MUST be rendered at the `NcContent` level in `App.vue`, NOT inside `NcAppContent`:

```vue
<!-- App.vue -->
<NcContent app-name="myapp">
  <MainMenu />
  <NcAppContent>
    <router-view />
  </NcAppContent>
  <CnObjectSidebar v-if="objectSidebarState.active" ... />
</NcContent>
```

## Consequences

- Developers must check if a shared component is self-contained before wrapping it
- The component library documents which components are self-contained in their JSDoc headers
- Code reviews should flag card-in-card nesting as a pattern violation
- Existing violations should be fixed when encountered (per ADR-015 pre-existing issues rule)

### ADR-018-widget-header-actions
# ADR-018: Widget Header Actions Pattern

## Status
Accepted

## Date
2026-04-14

## Context

Card and widget components across Conduction apps need action controls (buttons, dropdowns, selects) for user interactions like changing status, adding items, or toggling views. Developers have been placing these controls inline with card content, taking up vertical space and creating inconsistent layouts.

Nextcloud's own UI pattern places actions in the title bar (top-right) of panels and sidebars. Our shared component library should enforce this same pattern so all card/widget components have a consistent location for actions.

## Decision

### All card/widget components MUST support a `header-actions` slot

Every component that renders a title bar or header MUST provide a `header-actions` slot positioned in the **top-right of the header**, inline with the title. This is the standard location for action controls.

### Standard slot name: `header-actions`

All components use the slot name `header-actions` for consistency. Components that previously used `actions` retain it for backwards compatibility but `header-actions` is the canonical name.

### Component support status

All card/widget components in `@conduction/nextcloud-vue` now support `header-actions`:

| Component | Slot name | Notes |
|---|---|---|
| `CnDetailCard` | `header-actions` | Primary card component |
| `CnWidgetWrapper` | `header-actions` | Dashboard widget container |
| `CnObjectDataWidget` | `header-actions` | Passes through to CnDetailCard |
| `CnObjectMetadataWidget` | `header-actions` | Passes through to CnDetailCard |
| `CnStatsPanel` | `header-actions` | Added in this ADR |
| `CnSettingsCard` | `header-actions` | Added in this ADR |
| `CnConfigurationCard` | `header-actions` + `actions` (legacy) | `header-actions` added alongside existing `actions` |
| `CnVersionInfoCard` | `header-actions` + `actions` (legacy) | `header-actions` added alongside existing `actions` |

### What goes in header-actions

- Status change dropdowns / selects
- Add/create buttons
- Toggle switches (e.g. edit mode)
- Refresh buttons
- Filter controls specific to this widget

### What does NOT go in header-actions

- Save/cancel for the entire page (those belong in `CnDetailPage` `#header-actions`)
- Bulk action toolbars (those belong in `CnMassActionBar`)
- Form inputs that are part of the data being edited

### Usage pattern

```vue
<CnDetailCard :title="t('app', 'Status')">
  <template #header-actions>
    <NcSelect
      v-model="selectedStatus"
      :options="statusOptions"
      :placeholder="t('app', 'Change status...')" />
  </template>

  <!-- Card content -->
  <CnTimelineStages :stages="stages" :current-stage="current" />
</CnDetailCard>
```

### New components

When creating new card or widget components, the `header-actions` slot MUST be included from the start. The standard template pattern:

```vue
<div class="cn-my-widget__header">
  <h4 class="cn-my-widget__title">{{ title }}</h4>
  <div v-if="$slots['header-actions']" class="cn-my-widget__header-actions">
    <slot name="header-actions" />
  </div>
</div>
```

With CSS:
```css
.cn-my-widget__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.cn-my-widget__header-actions {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
}
```

## Consequences

- All existing card components now support `header-actions`
- New components must include this slot from creation
- Existing apps should migrate inline actions to `header-actions` when touching those files
- Code reviews should flag action controls placed in card content as a pattern violation
- The `actions` slot name in CnConfigurationCard and CnVersionInfoCard is deprecated but retained for backwards compatibility

### ADR-019-integration-registry
# ADR-019: Integration Registry Pattern

## Status
Proposed

## Date
2026-04-21

## Context

Conduction apps (OpenCatalogi, Procest, Pipelinq, LaunchPad, Decidesk, DocuDesk, ZaakAfhandelApp, Larpingapp, Softwarecatalog, OpenRegister itself) all consume the same set of "things linked to an object" — files, notes, tasks, calendar events, mail, contacts, deck cards, talk conversations, and an expanding catalogue of NC-ecosystem and external services.

Until now this was implemented in two rigid places:

- `OCA\OpenRegister\Service\LinkedEntityService::TYPE_COLUMN_MAP` — a hardcoded PHP constant naming the 8 supported NC entity types.
- `@conduction/nextcloud-vue::CnObjectSidebar` — a Vue component with 5 hardcoded tabs and inline imports for each.

Adding a new integration required modifying both core OR and the shared component library. External services (OpenProject, XWiki, ...) had no path at all. Of the 8 backend-supported types, only 5 had sidebar UI and only 2 had widget components — a glaring asymmetry that grew worse with every new backend integration that landed without UI.

## Decision

Adopt a **two-sided integration registry** pattern as the canonical mechanism for declaring "things that can be linked to or rendered alongside an OpenRegister object."

### The contract — one provider, three artifacts

Every integration ships a vertical slice declared via:

1. A PHP class implementing `OCA\OpenRegister\Service\Integration\IntegrationProvider` (registered via DI tag `IntegrationProvider`).
2. A frontend registration call `OCA.OpenRegister.integrations.register({ id, label, icon, tab, widget, ... })`.

The two registrations share the same `id` — backend and frontend are paired by id, not by import.

### Three-stage filter

What the user actually sees is decided by three independent filters, each with distinct ownership:

| Stage | Owner | Question |
|---|---|---|
| **Registry** | Provider author (system) | Does this integration exist + is the required NC app installed? |
| **Schema** | Schema author (data designer) | Is this integration relevant to objects of this schema? |
| **Component** | Page author (app developer) | Should this integration appear on THIS surface? |

Stage 1 is `IntegrationRegistry::getEnabled()`. Stage 2 is the schema's `configuration.linkedTypes` whitelist. Stage 3 is the rendering component's `excludeIntegrations` prop (or equivalent layout choice).

Each stage has clear ownership; debugging "why isn't X showing?" walks the three stages in order.

### Widget parity is a hard rule

Registering an integration without **both** a sidebar tab component **and** a card widget component is a CI-enforced failure. The check runs in pre-commit, repository CI, and the hydra quality gate. Tab-only or widget-only integrations are not permitted.

### Four widget surfaces with graceful fallback

Widgets render across four surfaces: `user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`. A registered widget receives the `surface` as a prop and may branch internally. Optional surface-specific components (`widgetCompact`, `widgetExpanded`, `widgetEntity`) are used when present. A new surface added in the future falls back to the main `widget` — no re-registration required from existing integrations.

### External integrations route through OpenConnector

Providers may declare `getStorageStrategy() === 'external'` and reference an OpenConnector source. OR's `ExternalIntegrationRouter` handles dispatch + auth-status surfacing. OR does not own credentials — OpenConnector does. The provider declares its `authRequirements()` so OR can show a unified admin UI and surface auth status via OCS capabilities.

### Schema validator is registry-driven

`Schema::validateLinkedTypesValue()` consults `IntegrationRegistry::listIds()` rather than a hardcoded constant. New integrations are immediately valid as `linkedTypes` values without core changes.

### Reference-property auto-rendering

A new schema property marker `referenceType: <integration-id>` causes `CnFormDialog` and `CnDetailGrid` to render the matching integration's `single-entity` widget inline next to the property. The integration registry is the single source of truth for "how to render a linked thing of this type" everywhere it appears, not just in sidebars and dashboards.

## Consequences

### Positive

- **Extensibility**: any Conduction app, third-party integrator, or external-service connector can add an integration without modifying OR core or `@conduction/nextcloud-vue`.
- **Consistency**: every integration is rendered the same way, with the same lifecycle, the same RBAC hooks, the same auth surface, the same parity contract.
- **Discoverability**: integrations are advertised via OCS capabilities — mobile apps, partner integrations, and other NC apps can discover what's available without proprietary endpoints.
- **Parallelism**: leaf changes (one per integration) hang off this contract and run in parallel through hydra's pool. The current backend-vs-UI asymmetry cannot recur — parity is enforced.
- **Future flexibility**: the contract is "linked thing"–shaped so `RelationsService` (object↔object) can be unified under the same registry in a future change without breaking changes.

### Negative

- **Onboarding ceremony**: adding a new integration means more files than before (provider, tab, widget, registration, spec delta, tests). Mitigated by `scripts/scaffold-integration.sh <id>` which generates the skeleton.
- **Bundle discipline**: an integration that fails to register (wrong load order, missed `register()` call) silently vanishes. Mitigated by the parity CI gate catching missing declarations pre-merge and a dev-mode warning when a backend provider has no frontend counterpart.
- **One more abstraction**: developers reading sidebar/dashboard code must understand "why isn't this just a static import?" Mitigated by the developer guide and this ADR.

### Migration risks

- **Schema `linkedTypes` referencing not-yet-registered ids**: handled — validation is permissive on read (warns but doesn't reject), strict on write only when adding.
- **External consumers of `LinkedEntityService::TYPE_COLUMN_MAP`**: the constant is private-by-convention and not documented as public API; we don't expect external consumers. It is `@deprecated` here and removed in a follow-up cleanup change once built-in providers stabilise.
- **`CnObjectSidebar` props/slots**: every existing prop and slot is preserved. Snapshot tests guard against regressions on the 5 existing tabs.

## Companion ADR

This ADR codifies the **mechanism**. A separate companion ADR — **ADR-020: Apps Consume OpenRegister Abstractions** — codifies the broader **principle**: Conduction apps hook into OpenRegister's abstractions (registers, schemas, objects, integrations, RBAC, audit, archival, ...) rather than building parallel mechanisms. ADR-020 is authored separately; ADR-019 is the first concrete instance of that principle being applied systematically.

## Implementation reference

- Umbrella change: `openregister/openspec/changes/pluggable-integration-registry/` (proposal, design, tasks, spec, hydra.json)
- Implementation files: `openregister/lib/Service/Integration/`, `nextcloud-vue/src/integrations/`
- Developer guide: `openregister/docs/integrations/README.md`
- Scaffold script: `openregister/scripts/scaffold-integration.sh`
- Parity check: `openregister/scripts/check-integration-parity.sh`

## References

- ADR-004 — Frontend (Vue 2, axios, components)
- ADR-007 — i18n (nl + en required)
- ADR-010 — NL Design System
- ADR-011 — Schema standards
- ADR-017 — Component composition
- ADR-018 — Widget header actions
- ADR-020 — Apps consume OR abstractions (companion, separate change)

## Ownership

OpenRegister team owns the registry contract, the built-in providers, and the schema validator changes. `@conduction/nextcloud-vue` maintainers own the frontend registry, surface contracts, and the three new widgets. Each integration leaf change has its own owner.

### ADR-020-gate-scope-to-pr-diff
# ADR-020 — Mechanical gates are scoped to the PR diff, not the whole repo

## Context

Hydra's 8 mechanical gates (`scripts/run-hydra-gates.sh`) were authored as repo-wide scanners: every `lib/**.php` file was checked on every pipeline run. This made pre-existing debt in unchanged files block every new PR. Concretely, decidesk#44 / #45 bounced through `code-review:fail → security-review:fail → needs-input` multiple cycles because `lib/Controller/SettingsController.php` (not touched by either PR) had two genuine findings — missing `#[AuthorizedAdminSetting]` on `load()` and missing `STATUS_UNAUTHORIZED` guard on `index()`. The reviewer cannot fix unchanged files in bounded scope, the builder will not re-enter fix mode for someone else's debt, and the applier refuses to override reviewer-fail verdicts. Result: two genuinely-clean PRs stuck in a ping-pong for days.

The reviewer's CLAUDE.md has long instructed Claude to apply the diff scope manually, but that is (a) advisory, not enforced, and (b) wastes turns on every run.

## Decision

Every mechanical gate in `scripts/run-hydra-gates.sh` must honor the `--scope-to-diff [BASE_REF]` flag. When set, the gate iterates only over files added, copied, modified, or renamed (`--diff-filter=ACMR`) between `BASE_REF` (default `origin/development`) and `HEAD`. Inherited debt in unchanged files is documented by a full-repo cleanup PR, not enforced via review blockers on unrelated work.

All four pipeline positions that invoke gates use `--scope-to-diff`:

| Position | Invocation site | Why scope-to-diff |
|---|---|---|
| Builder Rule 0b wrapper | `images/builder/entrypoint.sh` | Builder is creating the PR; the diff is its output. |
| Code reviewer pre-flight | `images/reviewer/entrypoint.sh` | Juan reviews the PR, not the base branch. |
| Code reviewer post-flight | `images/reviewer/entrypoint.sh` | Post-flight gate fails when Juan introduces debt; inherited debt is out of scope. |
| Security reviewer pre-flight | `images/security/entrypoint.sh` | Same rationale as code review. |
| Security reviewer post-flight | `images/security/entrypoint.sh` | Same. |

The applier runs no gates directly — it consumes the reviewers' verdicts, which now reflect scope-correct findings.

Base ref is overridable via the `HYDRA_GATE_BASE_REF` env var (default `origin/development`) for repos with a different mainline.

### Override: full-branch scope (`HYDRA_REVIEW_SCOPE=full`)

Diff scope is the default and the right choice for steady-state pipeline traffic. There are still legitimate cases for a one-off whole-branch sweep — onboarding a new repo, dedicated tech-debt audits, validating a long-lived branch before merge. Setting `HYDRA_REVIEW_SCOPE=full` (env var on the supervisor / `manual-review.sh` / `dev-run.sh` invocation) opts out of diff scoping for that run:

- The reviewer + security entrypoints drop `--scope-to-diff` from both pre-flight and post-flight `run-hydra-gates.sh` invocations — every gate scans the whole repo.
- Juan's and Clyde's prompts are rewritten to "FULL-BRANCH AUDIT MODE — review every file under /workspace/repo, not just the PR diff" so inherited debt becomes in-scope for fix authority.
- Composer/npm audit + Semgrep + manual OWASP review were never diff-scoped, so they keep behaving the same.

**Expected impact:** every PR run with `HYDRA_REVIEW_SCOPE=full` against a repo with backlog will fail until the backlog clears. This is by design — the override exists for audits, not for routine review. Default stays diff-scoped; the org-wide policy in this ADR is unchanged.

Future work (deferred): wire `HYDRA_REVIEW_SCOPE=full` to a per-issue `ready-for-full-audit` label so the override is opt-in per-PR rather than supervisor-wide.

Gate 4 (`composer-audit`) is skipped entirely when scope-to-diff is active and neither `composer.json` nor `composer.lock` is in the diff — dep vulnerabilities are unchanged if deps are unchanged. Gate 6 (`orphan-auth`) scopes the *defining* file by diff but keeps its caller grep repo-wide so a method newly-added in the PR is still validated against any legitimate same-file or cross-file caller.

## Consequences

**Positive**
- Existing debt in unchanged files no longer blocks PRs on unrelated features. The decidesk#44/#45 ping-pong is structurally impossible going forward.
- Builder, reviewer, and security all see the same scoped gate output — no more cycle-of-life where each position reads different baselines.
- Faster pipeline runs: scanning ~20 changed files instead of ~200+ repo files per gate.

**Negative**
- Inherited debt is genuinely invisible to the pipeline until it lands in a PR. Mitigation: a full-repo audit (scope-to-diff off) runs on the `ready-for-audit` label via `cron-audit.sh`, keeping the base-branch state observable.
- A PR that ONLY modifies a file lightly (e.g. renames it) may have gates pass on that file even if it has pre-existing debt. Acceptable — gates judge what the PR touched, not the file's full history.

**Deferred to Phase G.1**
- `composer check:strict` (phpcs, phpmd, psalm, phpstan) and `phpunit` / `npm run lint` are still full-repo. They run inside `composer`/`phpunit` which don't accept per-file scoping cleanly without per-tool argument passthrough. The same scoping story will land there next; for now, the reviewer's manual scope filter (`/tmp/pr-scope.txt`) remains the safety net.

## Verification

Smoke-test on decidesk PR #131 (feature/47/p2-motion-and-voting-core-t2) 2026-04-23:
- Full-repo scan: 2 FAIL (SettingsController in unchanged file)
- `--scope-to-diff --base origin/development`: ALL 8 GATES GREEN

The PR is now unblockable by unrelated debt without sacrificing gate coverage on the 19 files it actually changed.

### ADR-021-bounded-fix-scope-by-shape
# ADR-021: Reviewer bounded-fix scope is defined by change shape, not line count

**Status:** accepted
**Date:** 2026-04-23

## Context

The reviewer containers (Juan Claude van Damme for code, Clyde Barcode for security) run with bounded fix authority — they MAY apply small remediations in-container, commit, and push. The original rule in their CLAUDE.md:

> The fix is bounded to **1–3 lines in one file**.

This rule was an attempt to keep reviewers out of architectural territory. In practice it failed in two directions:

**1. Wrong-shaped for common security patterns.** A typical missing-authorization fix — add a `checkUserRole($uid, ['chair','secretary'])` block with try/catch — is 5–10 physical lines. Reviewers correctly declined to fix under the 3-line rule. On decidesk#45 (PR#129), Clyde flagged the same two auth stubs across **eight review cycles** from 2026-04-21 to 2026-04-23, each time declining as "exceeds 3-line bounded fix scope" or "architectural decision needed". The fix was literally mirroring a sibling method (`transitionLifecycle`) in the same class — zero new concepts, just apply the existing pattern. The 3-line limit turned a mechanical fix into architectural churn.

**2. Ambiguous under formatter changes.** Does "line" mean physical lines? Logical statements? With braces? A single prettier or phpcs run can convert a 3-line compact form into a 7-line expanded form and flip fix authority on or off. Reviewers should not be measuring code in a unit that formatters can redefine.

Meanwhile, genuine architectural work — new services, new schemas, new DI — IS well understood across the team. The category error was confusing "how much code changes" with "how much thinking changes".

A 10-line change that mirrors a sibling method is safer than a 2-line change that invents a new concept. We should scope by what the change touches, not by its size.

## Decision

Reviewer bounded-fix scope is defined by **change shape**, not line count. A fix is in-scope when ALL of these hold:

1. **The shape is one of:**
   - Modify an existing method body (guard clause, try/catch, validation, escape, swap unsafe call for safe one)
   - Add a new **private** helper method in the same class (no public API change)
   - Apply a pattern that **already exists in the same file or class, OR in a sibling controller/service of the same app** — mirror the precedent
   - Add a missing attribute / annotation / docblock tag
   - Swap an unsafe API for its safe counterpart (`md5` → `password_hash`, raw SQL → prepared statement, raw HTML → `htmlspecialchars`)
   - **Add a constructor parameter to inject a dependency that is already injected in a sibling controller/service of the same app** — strictly to enable a mechanical fix above (e.g. `IUserSession` → null-check → 401, `IGroupManager` → `isAdmin()` guard). The registration block in `Application.php` is updated at the same time.

2. **The change does NOT:**
   - Introduce a brand-new dependency that no sibling class in the same app already uses (first-use DI is an architectural choice — escalate)
   - Add a new service, class, interface, or route
   - Touch database schema or migrations
   - Change any public method signature visible to callers outside the class
   - Rewrite the file's top-level control flow

3. **Self-verify stays green.** Semgrep (security) or phpcs + covering phpunit (code) on the touched file produces 0 new findings.

The "sibling precedent" clause is explicit: **if a method in the same class OR in a sibling controller/service of the same app demonstrates the fix, the "architectural decision needed" escape hatch does NOT apply.** This is the clause that closes the #45 trap — the precedent in `transitionLifecycle` makes mirroring it mechanical, regardless of how many lines the mirror takes. The sibling-class extension closes the #73 trap — `MinutesVersionController`, `DecisionSearchController`, and `NotificationSubscriptionController` each lacked `IUserSession` and required a new constructor param to add auth guards, but `MinutesApprovalController` in the same app already injected it; mirroring that constructor shape is mechanical, not architectural. The bright line stays at **first-use DI** — a dependency no sibling class in the same app already uses is a genuine architectural choice and still escalates.

## Consequences

**Positive**
- Auth-guard mirroring is now in-scope for reviewers — the most common security-fix pattern stops escalating.
- Scope is robust under formatter changes: `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` on one line or three lines is the same fix.
- The "architectural" label is reserved for genuine architectural work (new services, new roles, new DI) where a human really does need to decide something.
- Fewer `needs-input` escalations on recurring findings — fewer retry cycles — less pipeline capacity burned per PR.

**Negative**
- Reviewers have slightly more scope and therefore slightly more room to make wrong calls. Mitigations:
  - The self-verify gate (Semgrep / phpcs + phpunit green on the touched file) is unchanged — still a hard stop on regressions.
  - "No new DI / schema / public signature" is a bright line that protects the expensive classes of change.
  - "Pattern exists in same file/class" is conservative — it prevents invention, only permits mirroring.
- Reviewers now need to read adjacent methods in the same class to check for precedent. This is a small turn-count cost but produces strictly better fixes.

**Neutral**
- Line-count as a heuristic is abandoned. Reviewers still prefer small fixes over large ones — the shape rules make that natural without encoding a brittle number.

## Implementation

Applied to:
- `images/reviewer/CLAUDE.md` — the "Bound-fixable" row in the fix-category table + the "Warnings ARE in scope for fix" section
- `images/security/CLAUDE.md` — the "What you MAY fix in-container" and "What you MUST NOT fix" sections

Rolled out via PR [#136](https://github.com/ConductionNL/hydra/pull/136), 2026-04-23.

## References

- Observed failure: decidesk#45 security-review, 8 cycles documented in [docs/retrospectives/decidesk-44-45-phase-g.md](../../docs/retrospectives/decidesk-44-45-phase-g.md)
- Observed failure: decidesk#73 security-review, 5+ cycles 2026-04-23 — 7 WARNING gate-7 findings across `MinutesVersionController`, `DecisionSearchController`, `NotificationSubscriptionController`; each cycle declined under the "no new DI" rule even though `MinutesApprovalController` in the same app already injected the needed `IUserSession` / `IGroupManager`. Manually closed by the operator, driving the sibling-class relaxation above.
- ADR-013 (container pool) defines the reviewer personas; this ADR defines their authority surface.
- ADR-020 (gate scope-to-diff) is the adjacent Phase G work — together these two ADRs remove the two biggest classes of false-escalation observed on the pipeline.

### ADR-022-apps-consume-or-abstractions
# ADR-022: Apps Consume OpenRegister Abstractions

## Status
Accepted

## Date
2026-04-23 (proposed) — 2026-05-03 (accepted; promoted after the
OR-abstraction audit confirmed this is already operating policy and
that the per-app `adr-000` files in procest and pipelinq are the
duplicate-ADR pattern this ADR was written to prevent).

## Context

Conduction maintains ~13 Nextcloud apps (decidesk, docudesk, pipelinq, procest, opencatalogi, openconnector, launchpad, larpingapp, shillinq/budgetq, zaakafhandelapp, nldesign, softwarecatalog, and the in-flight idea apps). Each app needs features that overlap heavily: objects with schemas, role-based access, audit trails, archival/retention policies, mapping/transformation, relation management, sidebar tabs with notes/tasks/files, dashboard widgets, integrations with NC-native and external services.

OpenRegister has grown into the **foundation** that provides these as shared abstractions: registers, schemas, objects, RBAC, audit-trail-immutable, archival-destruction-workflow, mappings, relations, object-interactions, and — with ADR-019 — a pluggable integration registry.

When a new app is built (or an existing app evolves), its authors face a choice: consume OR's abstraction, or build a parallel mechanism in-app. The "parallel mechanism" path is attractive at first — it's self-contained, it can be tweaked without coordinating with OR, and it avoids adding a dependency. But every instance observed so far has produced the same end state over time:

- **Duplicate data models** (an app-local Person vs OR contacts; an app-local AccessRule vs OR RBAC).
- **Drift** — app-local audit trails stop tracking things OR's audit does (replayable ordering, hash chains, retention-aware purge).
- **Missed features** — an app that rolled its own "linked files" sidebar never gets calendar/deck/polls/maps/collectives when OR adds them to the integration registry.
- **Impossible cross-app queries** — "show me all cases assigned to Jan across all Conduction apps" requires the contact linkage to be uniform.
- **Duplicate ADRs** — app-local ADRs restating what OR's already decided, then drifting.

ADR-019 codified the **mechanism** for one specific class of abstraction (integrations). This ADR codifies the **principle** that generalises: when OR has an abstraction that fits, apps consume it rather than reinvent.

## Decision

### Apps consume OpenRegister abstractions over local duplication

When an app needs functionality that OR already provides as an abstraction, the app MUST consume the OR abstraction. Rolling a parallel implementation in-app is not permitted unless explicitly justified (see "exceptions" below).

### What counts as an "OR abstraction"

Any capability exposed by OpenRegister that has a contract, a public API, and is documented as reusable. The current list (non-exhaustive):

| Abstraction | What it provides |
|---|---|
| **Registers + schemas + objects** | Versioned typed entities with validation, queries, events |
| **Authorization RBAC** | Role + scope + object-level permissions, per-schema and per-property |
| **Audit trail (immutable)** | Append-only hash-chained event log per object |
| **Archival + destruction workflow** | Retention classification, archival, purge — aligned with Archiefwet |
| **Mappings** | Cross-system transformation between source + target schemas |
| **Relations** | Typed links between OR objects |
| **Object interactions** (`object-interactions` spec) | Files, notes, tasks, tags, audit per object — the built-in part of the integration registry |
| **Integration registry (ADR-019)** | Pluggable NC-native + external integrations with tab+widget parity |
| **Audit hash chain** | Cryptographic verification of audit event order |
| **Content versioning** | Snapshot/restore of object states |
| **Deep link registry** | Cross-app navigation with stable object references |
| **TMLO metadata** | Dutch-gov metadata vocabulary compliance |
| **MCP discovery** | AI-agent discovery endpoint for all OR-backed capabilities |
| **Events + webhooks** | CloudEvents over NC's event dispatcher |
| **Schema declarative extensions** (`x-openregister-{lifecycle, aggregations, calculations, notifications, relations, widgets}`) | Behaviour declared as schema metadata in the app's register file instead of written as service classes — state machines, aggregations, derived fields, notifications, declarative relations, dashboard widgets. See ADR-031 for the keep-vs-migrate table and enforcement contract. |

New abstractions land in OR via its own openspec process. When they're merged, this ADR's list updates.

### The positive case — how to consume

1. **Use OR's PHP service via DI injection.** Don't wrap it in an app-local service that adds nothing. Thin adapters are fine; duplication isn't.
2. **Register for OR's extensibility points.** The integration registry takes DI-tagged providers (ADR-019). RBAC takes scoped role definitions. Audit takes event listeners. Apps extend through these points, not by building parallel machinery.
3. **Follow OR's schemas when OR has a schema.** If OR already defines a `contact` or `case` or `organisation` model, an app using those concepts MUST reuse the OR schema and its register — not a local copy with the same-ish fields.
4. **Call OR's REST API from the frontend via `@conduction/nextcloud-vue`.** The shared library wraps OR's API; apps that bypass it and call OR's raw endpoints re-solve problems the shared lib already solved.

### Anti-patterns

These have all been observed and should be treated as review-blocking:

- **Parallel link tables.** An app creating its own `{app}_email_links` / `{app}_contact_links` table when OR's integration registry already provides the equivalent via `openregister_*_links`. (Observed via decidesk's initial CalDAV plan using `X-DECIDESK-*` properties duplicating OR's `X-OPENREGISTER-*` mechanism.)
- **App-local schema validators.** An app writing its own JSON schema validation when OR already validates against the schema it owns.
- **Home-grown audit trails.** An app writing to a private events table instead of OR's audit trail for actions on OR-owned objects.
- **App-local RBAC on OR objects.** An app defining its own role/permission scheme for objects that live in OR's register.
- **Duplicate sidebar tab systems.** An app registering its own object-sidebar tabs outside the integration registry (ADR-019).
- **App-local "linked bookmarks/files/notes/..." that mirror an OR integration.** If OR has an integration for it, the app consumes it.
- **Duplicate ADRs.** An app-local ADR restating an org-wide ADR. The stale copies of `adr-004-frontend.md` in app repos (removed 2026-04-19) are the canonical example.

### Exceptions (when an app may build a parallel mechanism)

A parallel mechanism is acceptable only when one of the following is true, **and documented in an app-local ADR that references this ADR and justifies the divergence**:

1. **Fundamentally different domain requirements.** The app's use-case has constraints OR can't satisfy (e.g., sub-millisecond latency, append-only write with no read, special encryption-at-rest keys per tenant).
2. **OR is blocked on a dependency the app can't wait for.** Time-sensitive delivery where adding the feature to OR would push out 3+ months, and the app ships its own interim solution with an explicit migration plan.
3. **Prototype / spike.** Temporary local code with a written sunset date (max 90 days) and an owner.

Every exception requires an app-local ADR. "We didn't know OR had this" is not an exception.

### Enforcement

- **Code review gate.** Reviewers reject PRs that duplicate an OR abstraction without an explicit ADR-backed justification.
- **Specter's spec generation** surfaces applicable OR abstractions in each app's context brief (ADR-019 already flows in via `generate_spec_content.py`). The expectation is that feature specs reference the OR abstraction they consume.
- **Hydra quality gate (future).** A mechanical gate that flags common anti-patterns — parallel link tables, duplicate ADR files, schema-validator reinvention, local RBAC code acting on OR objects. Tracked as a follow-up to this ADR; implementation issue to be opened separately.
- **This ADR list updates when OR adds an abstraction.** Keeping the list current is the OR team's responsibility; when a new abstraction becomes stable, it goes in this table via a small PR against this file.

## Consequences

### Positive

- **One source of truth per capability.** Features of files/notes/tasks/calendar/mail/contacts/etc. evolve in OR; every app benefits.
- **Cross-app consistency.** "Jan is the applicant on this case" means the same thing in procest, pipelinq, and zaakafhandelapp.
- **Smaller apps.** Each app ships less code because it consumes more. A new app in 2026 should be mostly schemas + app-specific business logic; the plumbing is OR.
- **Uniform audit/RBAC/retention.** Government compliance (Archiefwet, AVG, Woo, BIO) has one implementation to verify, not 13.
- **The integration registry compounds.** When OR adds the `integration-calendar` leaf, every app using OR objects gets meeting linkage without any per-app work.

### Negative

- **App authors need to learn OR's contracts.** The onboarding curve for a new Conduction developer includes understanding OR's schemas, RBAC model, audit trail, and integration registry. Mitigated by OR's docs + this ADR list.
- **OR becomes a bottleneck for shared changes.** If a capability needs a fix, OR has to ship it. Mitigated by keeping OR fast-moving + prioritising the long-tail abstractions that unblock multiple apps.
- **Exception discipline matters.** Without rigorous review of the app-local ADR justifications, exceptions become the norm. Mitigated by the code-review gate and the explicit sunset date on prototype exceptions.

### Migration

Apps currently in violation (openconnector's bespoke linked-entity handling, decidesk's X-DECIDESK-* CalDAV properties, app-local audit copies) are not required to migrate immediately. Each gets a tracked "consume-OR-abstraction" issue with a target date. See the openregister integration registry umbrella ([openregister#1307](https://github.com/ConductionNL/openregister/issues/1307)) for the calendar/email/deck/contacts/talk migration pattern.

## Related

- **ADR-019** — Integration Registry Pattern (the first concrete instance of this principle).
- **ADR-031** — Schema-declarative business logic over service classes (the schema-engine dual to this ADR — when the abstraction is a schema extension, it's consumed in the schema register, not in a service class).
- **Openregister spec** — `openregister/openspec/changes/pluggable-integration-registry/` (the implementation that made the integration class of abstractions consumable).
- **Stale-duplicate incident 2026-04-19** — app repos carried stale copies of `adr-004-frontend.md` that drifted from the hydra master; removed across all app repos. The lesson that seeded this ADR.

## Ownership

- The OR team owns the list of abstractions in this ADR.
- Each app's maintainers own applying it inside their repo.
- Hydra reviewers enforce it at code-review time.

### ADR-023-action-authorization
# ADR-023: Action-level authorization via admin-configured action/group mappings

**Status:** accepted
**Date:** 2026-04-23

## Context

Conduction apps mix **data authorization** (who can read/write which OpenRegister objects) and **action authorization** (who can invoke which controller methods / workflow steps). The two are related but not the same:

- A chair of "Board A" can read all Board A minutes (data RBAC → OpenRegister) AND can invoke `generateMinutesDraft()` on them (action RBAC → app).
- A regular member of Board A can read the same minutes (data RBAC → OpenRegister) but CANNOT invoke `generateMinutesDraft()` (action RBAC denies).
- A Nextcloud admin can invoke `create()` on `SettingsController` (action RBAC → admin-only) regardless of any board membership.

OpenRegister already owns the **data** layer: object-level ownership, schema/register permissions, per-relation filtering (ADR-022 lists RBAC as one of the shared abstractions it provides). Apps consume this cleanly.

Apps DO NOT have a shared pattern for the **action** layer. Observed across decidesk / docudesk / pipelinq, the action-auth implementations range from:

- `IGroupManager::isAdmin()` hardcoded checks in controller bodies (wrong — locks governance actions to Nextcloud sysadmins, not to chairs/secretaries — see #44 / #45 on 2026-04-23)
- Missing entirely (the endpoint gates on data RBAC alone — wrong for actions that cross objects, like "generate report across all boards I chair")
- Inline `!in_array('chair', $roles)` checks that are (a) not discoverable by admins, (b) require a code change to adjust, (c) duplicated across controllers

The consistent answer needs to: live in app code (each app has its own actions), be **declarative** (admin can see and change the matrix without touching code), and be **testable** (gate-7 / gate-9 can mechanically verify each routed action either delegates to this service or is explicitly marked admin-only).

## Decision

### Rule 1 — Data RBAC is OpenRegister's job; apps never roll their own

OpenRegister decides for itself who may read / write / list which objects. App code that fetches, lists, or mutates domain objects MUST go through OpenRegister's `ObjectService` and trust the service's filtering + per-object permissions. Apps do not implement:

- Object-ownership checks (OpenRegister does it via `createdBy` / `owner` / schema settings)
- Register/schema-level access gates (OpenRegister does it via register permissions)
- Group-based read/write filtering on data (OpenRegister does it via `relations.group` / schema RBAC)
- Schema / register configuration (that's OpenRegister's own admin UI, not the consuming app's)

If the data-layer RBAC has a gap, **fix it in OpenRegister** (ADR-012 — push logic up to the shared foundation, don't re-implement per app).

### Rule 2 — Action RBAC is the app's job, declared in admin settings

Every app defines a registry of **actions** — named operations that a controller method executes. Examples (decidesk):

- `minutes.generate-draft` — produces a draft from a meeting transcript
- `minutes.distribute` — sends final minutes to the governance body
- `decision.publish` — marks a decision as published, triggers notifications
- `analytics.view-summary` — reads aggregate metrics across bodies
- `settings.write` — admin-only settings writes

Each action is mapped to a set of **user groups** via an admin-configured matrix, stored in `IAppConfig` under a well-known key. Every app maintains its own seed data for the initial mapping; the template ships a skeleton file per app that declares the action list with `["admin"]` as the default for every action. This default is **the safest first-install posture** — nothing is accidentally opened to non-admins until an admin explicitly broadens it. The admin settings panel is the only place to edit the matrix.

```json
// stored as IAppConfig["decidesk"]["actions"]
//
// First-install values (seed from the app, admin-only everywhere).
// The admin editing the matrix is the only path to broaden — code
// changes must not relax the default.
{
  "minutes.generate-draft":   ["admin"],
  "minutes.distribute":       ["admin"],
  "decision.publish":         ["admin"],
  "analytics.view-summary":   ["admin"],
  "settings.write":           ["admin"]
}
```

After admin customization (example — illustrative, not default):

```json
{
  "minutes.generate-draft":   ["chairs", "secretaries"],
  "minutes.distribute":       ["chairs", "secretaries"],
  "decision.publish":         ["chairs"],
  "analytics.view-summary":   ["chairs", "secretaries", "board-members"],
  "settings.write":           ["admin"]
}
```

**Naming convention**: `<domain>.<verb-phrase>` with dot as separator, lowercase, hyphens-in-phrases. `minutes.generate-draft`, `decision.publish`, `analytics.view-summary`. NOT `decidesk:minutes:generateDraft`. This keeps the keys grep-friendly, stable across refactors, and matches how schema keys look in OpenRegister.

The **admin settings panel** (registered via `\OCP\Settings\ISection`, route carries `#[AuthorizedAdminSetting(Application::APP_ID)]`) renders this matrix: rows = actions, columns = user groups, checkboxes = allowed. Admin edits + saves → `IAppConfig` updated. NO code change required to adjust who can do what.

Controllers enforce the mapping with a single helper call:

```php
#[NoAdminRequired]
public function generateDraft(string $minutesId): JSONResponse {
    $user = $this->userSession->getUser();
    if ($user === null) {
        return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
    }

    $this->actionAuth->requireAction($user, 'minutes.generate-draft');
    // Throws OCSForbiddenException if none of $user's groups are mapped
    // to 'minutes.generate-draft' in the admin matrix.

    // ... data-layer work via ObjectService (OpenRegister enforces its own
    //     per-object permissions on top of this action check).
}
```

### Rule 3 — When admin IS required (not delegated to action RBAC)

The following stay `#[AuthorizedAdminSetting(Application::APP_ID)]` and live **only on the admin settings page** — they are NOT expressible as action mappings because they are the plumbing the action matrix itself depends on:

- **Configuring the action ↔ group matrix** (the admin settings panel itself)
- **App configuration** — any `IAppConfig` writes (feature flags, feature toggles, workflow parameters, anything that affects app-wide behavior)
- **Backup / restore operations** — data export, re-import, cross-environment migration
- **App integration configuration** — connections to external systems (n8n, SOLR, external APIs), webhook URLs, integration feature flags
- **Credential management** — API keys, OAuth tokens, basic-auth credentials for any third-party service
- **One-off admin operations** — re-import seed data, purge caches, run migrations, trigger re-indexing

Everything a non-admin (chair / secretary / board-member / agent / regular user) might legitimately invoke during normal operation = an **action**, gated via `requireAction()`. Admin settings page handles the plumbing; user settings page / per-user UI never touches the plumbing. The user settings page is for user-personal preferences only (UI theme, notification opt-ins) — not for anything the action matrix references.

Rule of thumb: if the operation mutates something the action matrix references (keys the matrix looks up, values the matrix resolves to, integrations the actions depend on) → admin. Everything else → action.

### Rule 4 — Middleware attribute + body check layered

Per ADR-005 and ADR-016:

- `#[PublicPage]` — genuinely public (login pages, OAuth callbacks). Body does NO auth check.
- `#[NoAdminRequired]` — any authenticated user may reach the endpoint. Body **MUST** call `$this->actionAuth->requireAction($user, 'action.name')` for action-level gating. Absence of this call is a gate-9 failure — see enforcement below.
- `#[AuthorizedAdminSetting(Application::APP_ID)]` — framework-level admin gate for the exceptions in Rule 3. Body does no further admin check (the middleware already enforced it).

### Rule 5 — Gate-9 enforces the action-auth pattern mechanically

`hydra-gate-9` (semantic-auth) is extended to check:

| Pattern | Verdict |
|---|---|
| `#[NoAdminRequired]` + body calls `$this->actionAuth->requireAction(...)` | PASS |
| `#[NoAdminRequired]` + body calls `$this->authorize*(...)` (per-object auth helper per ADR-005 Rule 3) | PASS |
| `#[NoAdminRequired]` + body calls `$this->requireAdmin()` / `isAdmin()===false`→403 | FAIL — the wrong layer; use `#[AuthorizedAdminSetting]` for admin-only or `requireAction()` for role-based |
| `#[NoAdminRequired]` + no recognized auth gate in body | FAIL — inadequately gated, open endpoint |
| `#[PublicPage]` + any body auth check | FAIL — public is public, no body checks |
| `#[AuthorizedAdminSetting]` + `requireAction()` in body | PASS but redundant (middleware already gated to admin) — not a fail, but the lint could suggest removal |

Enforcement rolls out in two phases to give apps time to migrate without breaking their pipelines:

1. **Soft-fail phase** (announce in ADR): gate emits warnings, doesn't fail the gate. Apps that haven't migrated yet stay green.
2. **Hard-fail phase** (date-stamped): gate treats missing `requireAction()` as FAIL. Decided when majority of apps have adopted the pattern.

## Consequences

### Positive
- Governance actions (minutes drafting, decision publishing, quorum checks) can be delegated to chairs / secretaries / board members — NOT Nextcloud sysadmins. Current decidesk bug class (#44 + #45) goes away structurally.
- Admins can re-map actions to groups without a code change — useful when an org shifts responsibilities mid-deployment.
- One helper (`$this->actionAuth->requireAction()`) per gated method — consistent, grep-able, testable.
- Gate-7 / gate-9 enforcement has a clear target to check for (`requireAction()` call in body).
- Template repo ships this out of the box — new apps inherit the pattern instead of each rolling their own.

### Negative
- Initial setup burden: admin must populate the action matrix on first install. Mitigated with sensible defaults in `create-labels`-style seed data per app.
- Two layers of auth per request (action matrix check + OpenRegister per-object check) = two service calls per gated endpoint. Negligible cost (both are app-local memory or indexed DB).
- Admin who mis-configures the matrix can lock chairs out of essential actions. Mitigated with a "reset to defaults" button + `occ decidesk:actions:reset`.

### Neutral
- Replaces "lock everything to admin" over-restriction with "configurable by admin" flexibility. For ops that currently have only Nextcloud admins, the first-install default can be "admin-only" per action — the matrix is editable but the safe default survives if nobody touches it.

## Implementation plan

1. **This ADR** — accepted.
2. **Reference implementation in decidesk**:
   - New `OCA\Decidesk\Service\ActionAuthService` with `requireAction(IUser $user, string $action): void` — throws `OCSForbiddenException` when $user's groups don't intersect the matrix entry for $action
   - New `OCA\Decidesk\Settings\ActionMatrixAdmin` settings section (`\OCP\Settings\ISettings` + template) showing the action×group matrix, admin-only
   - `IAppConfig` key `decidesk.actions` storing the JSON mapping
   - Refactor the 13 + 2 controller methods caught by gate-9 on #44 / #45 to use `requireAction()`
   - **Seed data per app** — each app ships its own `actions.seed.json` (or equivalent) declaring the action list with `["admin"]` as default. App migration runs it on first install.
3. **Port to `nextcloud-app-template`**: copy `ActionAuthService` + skeleton settings panel + seed-data pattern. Parametrized so new apps just declare their action names. Default values all `["admin"]`.
4. **Gate-9 extension (soft-fail phase first)**:
   - Detect `#[NoAdminRequired]` + body-has-`requireAction()`-call → PASS
   - Detect `#[NoAdminRequired]` + body-has-`authorize*()`-call (per-object auth per ADR-005) → PASS
   - Detect `#[NoAdminRequired]` + no recognized gate → emit warning (soft-fail)
   - Detect `#[NoAdminRequired]` + `requireAdmin()` / `isAdmin()===false` → FAIL (hard — the wrong layer)
   - Warnings hit the verdict JSON but do not set the gate to FAIL during migration.
5. **Migrate existing apps** (hydra, decidesk first, then docudesk / pipelinq / procest / …) to the new pattern.
6. **Gate-9 hard-fail phase**: after apps are migrated, flip warnings → fails. Date-stamp to set on the PR that ships the hard-fail variant.
7. **Unblock #44 + #45**: once decidesk has `ActionAuthService`, their 13+2 methods plug into `requireAction('minutes.generate-draft')` etc. The current parked state resolves as a retry cycle.

## References

- ADR-005 (security) — per-object authorization rule + admin checks
- ADR-016 (routes) — auth attribute rules + gate layering
- ADR-021 (bounded-fix scope) — mentions `checkUserRole($uid, ['chair','secretary'])` as the correct shape (now formalized via `requireAction`)
- ADR-022 (apps consume OR abstractions) — lists RBAC as one of OpenRegister's shared abstractions; this ADR clarifies that the scope is **data** RBAC, not **action** RBAC
- decidesk#44 / #45 — both pending role-based fix that this ADR unblocks

### ADR-024-app-manifest
# ADR-024: App Manifest (fleet-wide adoption)

## Status
Proposed

## Date
2026-05-03

## Context

`@conduction/nextcloud-vue` ships a manifest renderer end-to-end (schema,
loader, validator, `CnAppRoot` / `CnAppNav` / `CnPageRenderer` components,
four-tier adoption guide, ≥1 production consumer). Spec at
`nextcloud-vue/openspec/changes/add-json-manifest-renderer/specs/json-manifest-renderer/spec.md`
(17 REQ-JMR-* requirements). Decidesk is the only adopter today (Tier 4,
39 pages, v0.3.0 — `decidesk/src/manifest.json`).

Without a fleet-wide convention, the manifest stays a one-off:

- New apps re-roll their own router config + sidebar + dependency-check
  + page dispatch logic instead of consuming `CnAppRoot`.
- Cross-app admin UIs ("App Builder" — admin tweaks menu order, hides
  pages, overrides locale) have nothing to plug into per-app.
- Consumer apps that *want* the renderer don't know which Tier to start
  at, where the manifest file lives, or what the validation contract is.
- Filename / location drift will set in (every app picks its own path)
  unless the convention is pinned.

This ADR codifies the convention; the renderer itself stays governed by
`add-json-manifest-renderer` in nextcloud-vue.

## Decision

**Every Conduction app SHOULD ship a `src/manifest.json` validated
against the canonical schema. New apps MUST adopt at least Tier 1 from
inception.**

Specifically:

1. **Schema source** — the canonical schema is
   `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`.
   Apps MUST set `$schema` to the published URL of this file (for
   editor auto-validation); they MUST NOT fork or duplicate the schema.
2. **Location** — `src/manifest.json` (next to `main.js` /
   `App.vue`). Bundled into the build; served by webpack as a static
   import.
3. **Loader contract** — every consumer's `main.js` MUST
   `import bundled from './manifest.json'` and pass it to
   `useAppManifest(appId, bundled)`. The bundled-only path is
   CSP-clean; the backend-merge hook is opt-in.
4. **Backend endpoint convention** — apps MAY implement
   `GET /index.php/apps/{appId}/api/manifest` returning a partial
   override blob (admin-customised menu order, hidden pages, locale
   overrides). Apps that don't yet implement it return 404; the loader
   silently falls back. Response shape is a partial of the canonical
   schema (top-level `additionalProperties: false`).
5. **Validation gate** — every consumer MUST add
   `npm run check:manifest` to its `package.json` scripts (calls
   `validateManifest` from the library at build time); CI fails on
   schema errors. Mirror the pattern of nextcloud-vue's `check:docs`.
6. **i18n** — `label` and `title` are translation keys consumed by the
   app's own `t()` function; the manifest itself ships keys, not
   strings. Aligns with ADR-007 and the i18n-* shared specs.
7. **Versioning** — `manifest.version` follows semver of content; the
   library-side schema version is in the schema's `"version"` field.
   Apps set `manifest.version` to `0.x.y` while iterating; bump to
   `1.0.0` when the manifest stabilises.
8. **Tier choice** — adoption is tiered (1 = `useAppManifest` only;
   2 = + `CnPageRenderer`; 3 = + `CnAppNav`; 4 = full `CnAppRoot`
   shell). Each app picks its own Tier and may upgrade incrementally.
9. **Per-app adoption** — each app gets its own openspec change
   (`{app}-adopt-manifest`) referencing this ADR. The change MUST
   include: (1) generated `src/manifest.json` from the existing router,
   (2) an explicit Tier choice, (3) a regression test confirming all
   routes still resolve, (4) reviewer sign-off that the manifest does
   not duplicate or contradict the canonical schema.
10. **Apps that should NOT depend on OpenRegister** — launchpad and
    nldesign MUST NOT list `openregister` in `manifest.dependencies`.
    Per `feedback_launchpad-no-or-dependency.md`, launchpad is a BI surface
    that talks to OR via runtime GraphQL only; nldesign is a theme
    layer. Other apps SHOULD list every cross-app dependency the user
    needs installed for the app to function.

## Consequences

- The `CnAppRoot` shell becomes the default UI shell across the fleet;
  per-app router boilerplate shrinks toward zero.
- Cross-app admin tooling ("App Builder", `/api/manifest` consumers,
  manifest-aware audits) has a stable contract to target.
- Reviewers gain a fleet-wide gate: a PR adding routes that aren't
  reflected in `src/manifest.json` is treated as drift. (Pairs with
  ADR-029 route-reachability gate.)
- Migration order recommendation (cheapest → highest-value):
  `launchpad` → `larpingapp` / `softwarecatalog` → `openregister` →
  remaining apps. Decidesk is already Tier 4 and serves as the
  reference.
- App-manifest extensions (e.g. `theme: { primary, accent, logoUrl }`,
  `roles[]`) are out of scope for v1; revisit in a successor ADR if
  patterns surface during adoption.
- The `type` enum (`index | detail | dashboard | custom`) is closed;
  new built-in types require a library-level openspec change in
  nextcloud-vue, not an app-side override. Apps register custom page
  types via the `customComponents` prop on `CnAppRoot`.

## See also

- `nextcloud-vue/openspec/changes/add-json-manifest-renderer/` — the
  library-side spec the renderer ships against.
- `decidesk/src/manifest.json` — canonical Tier-4 example.
- ADR-022 (apps consume OR abstractions) — the manifest is the FE
  side of the same principle.
- ADR-029 (route-reachability gate) — pairs with the manifest's
  `pages[]` declaration.

### ADR-025-i18n-source-of-truth
# ADR-025: i18n source-of-truth and API language negotiation

## Status
Proposed

## Date
2026-05-03

## Context

OpenRegister already implements a partial i18n stack — `LanguageService`,
`LanguageMiddleware`, `TranslationHandler`, `TranslationProjectionService`,
`TranslationStatusService`, `Translation` entity — and exposes it via
`Accept-Language` request parsing + `Content-Language` /
`X-Content-Language-Fallback` response headers. The
[register-i18n spec](../../../openregister/openspec/specs/register-i18n/spec.md)
calls this work "partially implemented".

Two concrete gaps surfaced from the OR-abstraction audit (R4 + R5,
2026-05-03) that block consumer apps (notably opencatalogi for Pages /
Menu items / Publications / Themes / Glossary) from adopting i18n:

### Gap 1 — peer-language model has no source-of-truth

The Translation table tracks `(object_uuid, property, language, value,
status)`. Every language is treated symmetrically. A reader of an
English value cannot tell whether English is the canonical original or
a translation derived from Dutch. `TranslationStatusService` knows
"approved" / "draft" / "machine_translated" / "human_reviewed" but has
no automatic "outdated" trigger when the source value changes — the
spec calls for this on line 221 but the column doesn't exist.

### Gap 2 — request-side language negotiation is incomplete

`Accept-Language` works; `?_lang=` / `?language=` / `?lang=` is not
recognised — only `_translations=all` toggles the all-languages path.
PATCH/PUT bodies are silently treated as updates to the register's
default language; clients that want to send an English-only update
have to send the full language-keyed object.

### Why now

Three apps are about to adopt i18n at scale (opencatalogi multi-language
content, decidesk policy translations, procest bezwaar / parafering
notifications). Without a stable source-of-truth contract and a
predictable API surface, each app would invent its own conventions.

## Decision

Adopt the **source-of-truth model** and tighten the API contract. Two
linked openspec changes implement it:

1. **`openregister/openspec/changes/i18n-source-of-truth/`** —
   schema-level + DB-level + render-metadata work
2. **`openregister/openspec/changes/i18n-api-language-negotiation/`** —
   request/response contract tightening

### Source-of-truth contract

- **Schema property** — translatable properties MAY declare
  `sourceLanguage: "<bcp47>"` (e.g.
  `{"omschrijving": {"type": "string", "translatable": true,
  "sourceLanguage": "nl"}}`). If omitted, defaults to
  `Register.defaultLanguage`.
- **Object-level override** — objects MAY declare
  `_translationMeta.<property>.sourceLanguage = "<bcp47>"` to override
  the schema default for that single object. Rare but useful for
  English-authored content.
- **`Translation.sourceLanguage` column** — the
  `openregister_translations` table gains a non-null
  `source_language VARCHAR(16)` column. On projection, populated from
  the schema/object resolution above.
- **Render metadata** — the response body MUST expose, for each served
  translatable value:
  - which language was actually served (`Content-Language` header,
    already present)
  - which language is the source-of-truth (new `X-Source-Language`
    response header)
  - whether the served language equals the source (i.e. "this is the
    original") or is a translation (existing
    `X-Content-Language-Fallback` header expanded; new optional
    JSON envelope `_meta.languageMeta` for property-level granularity
    when `_translationMeta=true` is requested)
- **Source-change automation** — when a translatable property in the
  source language is updated, OR MUST flip every non-source-language
  Translation row for that property to status `outdated` via
  `TranslationStatusService::markDerivedTranslationsOutdated()`.
- **Query filters** — `TranslationController` search MUST accept
  `?sourceLanguage=<bcp47>`, `?isOutOfDate=true`, and
  `?compareToSource=true`.

### API language negotiation contract

- **Request precedence** (highest first):
  1. Query parameter `?_lang=<bcp47>` (canonical name)
  2. Query parameter `?language=<bcp47>` (alias)
  3. Header `Accept-Language: <RFC 9110>`
  4. Default — register's default language (first element of
     `Register.languages`); if none, `nl`
- **Response headers**:
  - `Content-Language: <bcp47>` — language served (already implemented)
  - `X-Content-Language-Fallback: true` — set when fallback was used
    (already implemented)
  - `X-Source-Language: <bcp47>` — canonical source language for the
    translatable properties on this response (new)
- **Missing translation** — keep the silent 200-fallback behaviour;
  do not 406. The `X-Content-Language-Fallback` header signals it.
- **Write-side disambiguation** — PATCH/PUT/POST MAY send
  `X-Translation-Target-Language: <bcp47>` to indicate the body updates
  that target language. Default (no header) preserves current behaviour
  (body merged into the register's default language). Bodies MAY also
  send full language-keyed objects directly (existing path); the
  header is the ergonomic shortcut.
- **Bulk listings** — `GET /api/objects/{r}/{s}` honours the same
  request precedence and returns each object resolved to that language.
- **Validation laxity** — the API accepts any BCP-47 code on
  `?_lang=`; if the register doesn't support it, the fallback chain
  resolves and the response declares the language actually served.

## Consequences

- Two parallel openspec changes land:
  `openregister/openspec/changes/i18n-source-of-truth/` and
  `openregister/openspec/changes/i18n-api-language-negotiation/`.
  They share a migration (the `source_language` column) but otherwise
  decouple; the API change can ship before the DB change if needed.
- Client SDKs (frontend stores in `@conduction/nextcloud-vue`,
  consumer apps, Newman test collections) gain a reliable way to ask
  for and detect a specific language.
- opencatalogi's multi-language editing UI work (the empty state R3
  surfaced on Pages / Menus / Publications / Themes / Glossary) becomes
  a straight implementation task — it can rely on this ADR's contract
  rather than inventing one.
- TranslationStatusService becomes an active workflow engine (the
  source-change → outdated trigger), not just a passive projector.
- A migration must back-fill `source_language` for existing
  Translation rows. Default = `Register.defaultLanguage`; back-fill
  job runs once at upgrade time.
- A successor ADR should pin per-property override granularity (currently
  the decision sketch allows schema, object, and register levels — open
  question whether all three are worth the complexity; flagged in O4
  open questions).
- Consumer apps that only care about the simple case (one language,
  bundled at install time) keep working unchanged — every new field
  and header is opt-in.

## See also

- `openregister/openspec/specs/register-i18n/spec.md` — the existing
  partial spec that this ADR formalises into source-of-truth + API
  negotiation contracts.
- ADR-007 (i18n) — the pre-existing "Dutch + English minimum"
  baseline. This ADR layers source-of-truth + API contract on top.
- `feedback_i18n-requirement.md` — operational rule that all apps
  must support nl + en.
- ADR-022 (apps consume OR abstractions) — once this ADR lands,
  consumer apps consume the contract instead of building their own.

### ADR-029-route-reachability-gate
# ADR-029: Mechanical gate for controller-route reachability

**Status:** proposed

## Context

ADR-008 already requires every new API endpoint to ship with a Newman/Postman
collection plus a `curl` smoke check before PR. That guidance is advisory — no
mechanical gate enforces it. The 2026-05-01 audit on `openregister`
(`docs/development-notes/AUDIT_2026-05-01.md`) caught the consequences:

**Three production-bug classes hidden behind passing unit tests.**

1. **Route-gap.** A controller method returns `JSONResponse`/`StreamResponse`,
   has unit-test coverage, and the spec's `tasks.md` marks `[x] Register
   route` — but the route is missing from `appinfo/routes.php`. Endpoint
   returns 404 from the router. The audit found 41 such cases on `openregister`
   in a single day:
   - 13 in `profile-actions` (already archived as shipped)
   - 14 in `nextcloud-entity-relations`
   - 16 in `workflow-operations`
   - 10 in `file-actions`
   - others scattered across `tags`, `notes`, `tmlo`, `linked-entity-types`,
     `fileSidebar`.
2. **Wrong controller binding.** A route IS registered, but the controller
   class named in the route entry doesn't expose the method — typically because
   the method moved to a sibling controller during a namespace refactor. Calling
   the URL throws `ReflectionException` and 500s. Caught 4 instances in PR
   `#1402` (`Settings\SolrManagement#getObjectCollectionFields` and friends —
   the methods actually live on `Settings\ConfigurationSettings` /
   `Settings\FileSettings`).
3. **Per-instance state not persisted.** A handler stores cross-request state
   in `private array $foo = []`. Per-request unit tests are green; real
   PHP-FPM workers see an empty map after each request boundary. Caught on
   `FileLockHandler` (`openregister` commit `22c5625ef` — moved to ICache).

All three pass unit-test green because the unit-test fixture instantiates the
controller/handler in-process — never crossing the HTTP boundary or a worker
restart. The defects only surface when a real HTTP request reaches the router
or when a second request lands on a fresh handler instance.

## Decision

A new mechanical gate, `hydra-gate-route-reachability` (gate-12), runs in the
builder pre-flight and reviewer pre/post-flight positions. It enforces three
invariants on the PR diff:

### Invariant 1 — Every response-returning controller method is routed

For each PHP file under `lib/Controller/**Controller.php` in the diff, scan
public methods whose return type contains `JSONResponse`, `StreamResponse`,
`DataDownloadResponse`, `DataResponse`, `Response`, `RedirectResponse`, or
`TemplateResponse`. For each match, derive the expected route name as
`{snake_case_controller}#{methodName}` (e.g. `WorkflowExecutionController::index`
→ `workflow-executions#index`) and require a corresponding entry in
`appinfo/routes.php`.

Resource auto-routes (`registers`, `schemas`, `sources`, `configurations`,
`applications`, `agents`, `endpoints`, `mappings`, `consumers`) are excluded —
those auto-generate `index/show/create/update/destroy`. Methods named `helper*`,
`assert*`, `validate*`, or marked `@internal` are also excluded.

**Failure surface:** "Method `X::y` returns Response but no route in
`appinfo/routes.php` names `x#y`. Either register the route or drop the method."

### Invariant 2 — Every route binds to a method that exists

For each `['name' => 'foo#bar', ...]` entry in `appinfo/routes.php` in the diff,
load the resolved controller class (`FooController` for `foo#bar`,
`Settings\Foo` for `Settings\Foo#bar`) and assert it exposes a public method
named `bar`. This catches namespace-refactor leftovers like the four
`Settings\SolrManagement#getObjectCollectionFields` routes that pointed at
methods now living on `Settings\ConfigurationSettings`.

**Failure surface:** "Route `foo#bar` resolves to `FooController::bar` but
that method doesn't exist on the class. Probable cause: the method moved
during a namespace refactor."

### Invariant 3 — Newman case present for new routes

For each new route added in the PR diff, require a Postman/Newman entry under
`tests/integration/` whose `request.url` matches the route's URL pattern.
Soft-fail (warning, not block) on first introduction; hard-fail after a 30-day
grace period to give legacy debt time to be migrated.

**Failure surface:** "Route `foo#bar` added but no Newman case under
`tests/integration/` exercises `<URL pattern>`. ADR-008 mandates per-endpoint
integration coverage."

### Out of scope (Invariant 0)

Per-instance state vs distributed cache (the FileLockHandler class) is NOT
covered by this gate — detecting "this private array should have been an
ICache" needs semantic understanding the gate cannot mechanically derive.
That class of bug is owned by the security/code reviewer's runtime-semantics
checks and by ADR-005's per-attribute pairing.

## Consequences

### Positive

- The 41 `openregister` route-gap cases caught by the audit could not have
  shipped under this gate.
- The 4 wrong-controller-binding ReflectionException cases cannot ship — gate
  reflects the route's target class and asserts the method exists at lint time.
- The advisory guidance in ADR-008 ("every endpoint → Newman case") gets a
  mechanical backstop.

### Negative

- Inherited debt at the moment of gate landing: any pre-existing unrouted
  Response method or wrong-binding entry will block PRs touching unrelated
  files in the same controller. Mitigation: **gate scopes to the PR diff per
  ADR-020** (only files added/modified between the diff's BASE_REF and HEAD).
  Inherited debt is closed by a one-shot full-repo cleanup PR, not enforced
  on every PR.

- The Newman invariant (#3) requires a working Newman environment in CI. Apps
  that don't yet run Newman against their stack get a 30-day grace window
  before the warning becomes a hard fail; the cleanup is a `tests/integration/`
  scaffold, not a per-PR ask.

### Migration

1. Implement `hydra-gate-route-reachability.sh` under `scripts/`. Honour
   `--scope-to-diff [BASE_REF]` per ADR-020.
2. Wire into `images/builder/entrypoint.sh` post-build, `images/reviewer/`
   pre-flight, `images/security/` pre-flight.
3. Run a one-shot full-repo audit on `openregister`, `decidesk`, `procest`,
   `pipelinq`, `opencatalogi`, `larpingapp`, `launchpad` to catalog inherited
   route-gap and wrong-binding debt. Each app gets a single cleanup PR.
4. After 30 days from gate landing, flip the Newman warning to a hard fail.

### Cross-references

- **ADR-008** — testing harness (Newman + curl smoke). This ADR makes the
  mechanical enforcement of the Newman bullet.
- **ADR-016** — `appinfo/routes.php` is the only registration path. This ADR
  enforces that every method that needs routing IS routed there.
- **ADR-020** — gate scope follows the PR diff. Required for this gate not
  to bounce on inherited debt.
- **ADR-021** — reviewer bounded-fix scope by shape. A gate finding scoped
  to the PR diff is in shape; a finding in unchanged files is not.

## Alternatives considered

1. **Strengthen ADR-008 wording without a new gate.**
   Rejected. The wording was already there. The audit caught what advisory
   wording cannot prevent: the human writing the controller method genuinely
   forgot to register the route, or pasted the wrong controller name. Only a
   mechanical lint catches both.

2. **Run Newman against a live NC instance in CI as the gate.**
   Rejected as a first step — too slow per PR, and conflates "endpoint is
   reachable" (this ADR) with "endpoint is correct" (separate concern). The
   gate stays static-analysis only; the live-env integration suite stays in
   the existing `composer check:strict` flow.

3. **Auto-generate routes from controller annotations.**
   Rejected per ADR-016: "Every route entry names `controller#method`
   explicitly — no wildcard auto-discovery, no regex generators." Auto-gen
   solves the route-gap problem but loses the explicit single-file URL
   surface that ADR-016 mandates for grep-ability and the auth gate.

### ADR-030-journeydoc-pattern
# ADR-030: Journeydoc — capture-driven user documentation

## Status
Proposed

## Date
2026-05-06

## Context

LaunchPad documentation surfaced a reusable workflow: 15 step-by-step
tutorial pages were authored alongside a Playwright spec that drives
each user journey end-to-end and captures a fresh PNG at every step.
The result is a Docusaurus site whose screenshots stay in sync with
the live UI — re-run the capture spec after any UI change and every
image refreshes automatically.

The workflow has eight reusable artifacts, none of which is launchpad-
specific: a `tutorials/{user,admin}/` markdown structure, a
`docs-screenshots.spec.ts` capture spec, a per-track `_category_.json`
sidebar config, a Playwright `docs-capture` project flag, a Docusaurus
config snippet (`onBrokenMarkdownImages: 'warn'` + `static/`-based
asset path), a `data-testid` instrumentation convention on the
high-traffic Vue surfaces, a screenshot output dir
(`docs/static/screenshots/tutorials/{track}/<file>.png`) reachable at
the canonical `/screenshots/...` URL, and a per-app domain wired into
`docs/static/CNAME` + `docs/docusaurus.config.js url` +
`.github/workflows/documentation.yml cname:`.

Without a fleet-wide convention each app re-discovers the gotchas in
isolation:

- Path mistakes (e.g. `docs/screenshots/` instead of
  `docs/static/screenshots/`) silently ship broken images to
  production.
- Selector brittleness on the capture spec. The launchpad first run
  passed only 6/15 stories; pulling stable `data-testid`s into the
  Vue components took 28 testids across 8 components and lifted the
  pass rate to 9/15.
- Branch-name policy violations on the doc PRs themselves
  (`feat/...` → rejected, must be `feature/...`).
- Domain wiring: three places need to agree on the docs hostname or
  GitHub Pages serves a 404 / mismatched cert.

## Decision

**Every Conduction app SHALL produce its end-user documentation via
the journeydoc pattern. The pattern lives in hydra; per-app adoption
is bootstrapped by the `journeydoc-init` skill.**

Specifically:

1. **Source layout**:
   ```
   docs/
   ├── tutorials/
   │   ├── _category_.json                # "Tutorials" parent
   │   ├── user/
   │   │   ├── _category_.json            # "User guide"
   │   │   └── 01-…, 02-…, NN-…           # one numbered story per page
   │   └── admin/
   │       ├── _category_.json            # "Admin guide"
   │       └── 01-…, 02-…, NN-…
   └── static/
       └── screenshots/
           └── tutorials/
               ├── user/<file>.png        # captured by the spec
               └── admin/<file>.png
   ```
   Page filenames carry numeric prefixes for `sidebar_position`
   ordering. Docusaurus strips the prefix from the URL — the canonical
   page URL is `/docs/tutorials/{track}/{slug-without-prefix}`.

2. **Markdown image references** are root-absolute:
   `![alt](/screenshots/tutorials/user/<file>.png)` — never relative.
   Docusaurus serves `docs/static/*` from the build root; relative
   paths to `docs/screenshots/` are NOT copied and silently 404 on
   deploy.

3. **Capture spec** lives at
   `tests/e2e/docs-screenshots.spec.ts`, runs under a dedicated
   `docs-capture` Playwright project that the regression `chromium`
   project explicitly excludes. The spec writes PNGs to
   `docs/static/screenshots/tutorials/{track}/<file>.png` so a single
   `npx playwright test --project docs-capture` refreshes every image
   in the docs site.

4. **Test-id convention** for stable selectors that the capture spec
   targets: `data-testid="<scope>-<element>"`, where `<scope>` is the
   feature surface (e.g. `dashboard`, `widget`, `cog`, `ctx`,
   `admin`). Capture-relevant surfaces — modal containers, primary
   buttons, list-row actions, context menu items — MUST carry a
   testid.

5. **Per-page structure** is fixed:
   - `# Title`
   - one-line description in frontmatter (`description:` field)
   - `## Goal`
   - `## Prerequisites`
   - `## Steps` (numbered, each with one inline screenshot)
   - `## Verification`
   - `## Common issues` (table)
   - `## Reference` (cross-link into existing `features/*.md`)

6. **Domain wiring** is consistent across three files:
   - `docs/static/CNAME` — single line, the FQDN
   - `docs/docusaurus.config.js` — `url` field
   - `.github/workflows/documentation.yml` — `cname:` workflow input
   All three must equal `<app-slug>.conduction.nl` (default) unless an
   app has an established alternate domain.

7. **Docusaurus config**:
   - `markdown.hooks.onBrokenMarkdownImages: 'warn'` so a fresh
     checkout that hasn't run the capture spec yet can still build.
   - `i18n.locales` is `['en']` until an app has actual translated
     markdown. Stale `nl/current.json` translation strings break SSR
     rendering when the docs source moves faster than the translation.

8. **Cross-links between tutorial pages** use the on-disk filename
   including the numeric prefix (e.g.
   `[create](02-create-dashboard.md)`). Docusaurus resolves them; the
   rendered `<a href>` points at the prefix-stripped URL.

## Tooling

Three hydra skills implement the pattern:

| Skill | Purpose |
|---|---|
| `journeydoc-init` | Bootstrap a new app — drops the 8-artifact scaffold, fills `{{APP_SLUG}}` / `{{APP_TITLE}}` / `{{DOMAIN}}` from the repo's `composer.json` + `package.json`, opens a PR. |
| `journeydoc-add-story` | Append one tutorial page to an already-journeydoc-ed app — markdown skeleton + capture-spec test block + sidebar entry. |
| `journeydoc-instrument` | Audit a Vue file, propose `data-testid`s on the capture-relevant anchors, apply them after confirmation, run vitest. |

Templates under `hydra/templates/journeydoc/` are the canonical source
for what `journeydoc-init` drops into a target repo. When the pattern
evolves, update the templates first; the skill picks the changes up
automatically.

## Consequences

**Positive**:
- Every app's docs site stays current automatically — UI change → run
  capture spec → docs match again.
- New contributors land on a documented user journey, not a dump of
  feature reference pages.
- Visual regressions surface as failing screenshots in the capture
  spec, not as "looks fine in dev" silence.
- The pattern can be extended (video recordings, accessibility audits,
  i18n parity checks) without rewriting the capture-spec scaffold.

**Negative**:
- Adds one more piece of infrastructure per app to maintain (the
  capture spec). Mitigated by the `journeydoc-add-story` skill so the
  per-page incremental cost is one skill invocation.
- Selector misses on the first capture run are common — depending on
  the app's UI complexity 30-60% of stories typically need a second
  pass with `journeydoc-instrument` to add the missing `data-testid`s.
- Broken-images warnings on a fresh clone (before the capture spec
  runs) are accepted — the docusaurus config is set to warn rather
  than fail. Apps that want a strict build can flip to `'throw'` once
  their screenshots are committed.

## Out of scope

- **Translated tutorials.** The pattern ships English-first; adding
  per-locale capture spec runs (each in a different language) is a
  follow-up. ADR-007 (i18n) governs translation responsibilities for
  app source code; tutorial markdown sits outside that contract for
  now.
- **Video / GIF capture.** The pattern is still-image-only. Drag and
  resize flows are captured at three frames (start, mid-drag,
  post-drop) rather than as a video. The `playwright-video` package
  could extend this without changing the markdown shape.
- **Cross-app deduplication.** Each app owns its own tutorials. There
  is no plan to build a unified "Conduction tutorials" portal that
  aggregates every app's tutorials.

## References

- LaunchPad PR #132 (initial pattern landing) and PR #134 (path / static
  fix). Both merged into launchpad `development`.
- `hydra/templates/journeydoc/` — canonical templates.
- `hydra/.claude/skills/journeydoc-{init,add-story,instrument}/` —
  the three skills.
- ADR-008 (testing) — capture spec lives under `tests/e2e/` per the
  Playwright convention.
- ADR-009 (documentation) — per-app docs site convention this builds
  on.

### ADR-031-schema-declarative-business-logic
# ADR-031: Schema-declarative business logic over service classes

## Status
Proposed

## Date
2026-05-06

## Context

OpenRegister has grown a set of schema extension points —
`x-openregister-lifecycle`, `x-openregister-aggregations`,
`x-openregister-calculations`, `x-openregister-notifications`,
`x-openregister-relations`, and `x-openregister-widgets` — that let
an app declare behaviour as schema metadata in its register file
(`lib/Settings/{app}_register.json`) instead of writing PHP service
code.

ADR-024 made the **frontend** declarative (manifest-driven UI,
`CnAppRoot` shell from `src/manifest.json`). ADR-022 said apps consume
OR abstractions over local duplication. But there is no ADR yet that
ties these together with the **backend** declarative path: when OR can
express behaviour as schema metadata, prefer that over a service class.

The 2026-05-06 readiness audit on decidesk surfaced the gap concretely:

- Decidesk's schema register declares 4 lifecycle blocks, 2
  notifications, 1 aggregation, 1 calculation. Recent commits migrated
  Meeting/Motion/Amendment lifecycles, ActionItem calculations + an
  aggregation, Meeting notifications + calendar-provider — proving the
  declarative engine is real and works end-to-end.
- Yet 16 PHP services still exist (~5,500 LOC). Roughly **~3,000 LOC
  across MotionService, VotingService, AgendaService, QuorumService,
  ActionItemAnalyticsService, VotingBehaviourService,
  DecisionNotificationService, MinutesService, and the
  OverdueActionItemsJob** implements state machines, aggregations,
  calculations, or notifications that the schema engine could now
  express declaratively.
- New specs are not yet steering authors toward the declarative path:
  the `besluiten-management` proposal (relocated 2026-04-30) is full
  prose, with zero `x-openregister-*` references.
- The Hydra builder's container CLAUDE.md, the reviewer prompts, and
  the spec-writer skills (`opsx-ff`, `app-create`) make zero mention of
  `x-openregister-lifecycle/-aggregations/-calculations/-notifications`.
  Builder Rule 1 ("copy existing patterns") therefore points at the
  wrong reference: with services as the dominant pattern, the builder
  faithfully produces another service.

Without an explicit "declarative-first" rule:

- Hydra continues to ship service code that should have been schema
  metadata. The migration target slips further away every cycle.
- Spec authors describe behaviour in prose ("lifecycle: submitted →
  debating → voting → adopted") without specifying *whether* it lands
  in the register or as service code. Both satisfy the contract; only
  one inherits the OR-side benefits (audit-trailed transitions, RBAC
  per state, replayability, automatic CloudEvents, MCP discovery,
  declarative GraphQL).
- Reviewers gate on the wrong thing: `hydra-gate-route-auth` enforces
  correct auth attributes on a controller method that *shouldn't have
  been written* in the first place.
- Apps drift: each one re-implements lifecycle/aggregation/notification
  logic in subtly different shapes, blocking clean later migration.

This ADR closes the gap. ADR-022 said "consume OR abstractions over
local duplication". This ADR adds: when the abstraction is a schema
extension, it is consumed *in the schema register*, not in a service.

## Decision

### Schema-declarative business logic is the default

**When OR provides an `x-openregister-*` extension that fits the
requirement, apps MUST declare the behaviour in their schema register
(`lib/Settings/{app}_register.json`) instead of writing a PHP service
class.**

The supported extensions and what they replace:

| Extension | Replaces (in the service layer) | Schema-engine benefits |
|---|---|---|
| `x-openregister-lifecycle` | State machines, transition guards, `setStatus`/`transitionTo`/`advance*Phase` service methods | Audit trail of every transition, RBAC per state, replayable on restore, automatic CloudEvent on every transition, lifecycle-aware queries |
| `x-openregister-aggregations` | "Get summary", "count by status", "average", "participation rate" service methods that loop OR objects and reduce | Single declarative pass; reused by GraphQL queries, dashboard widgets, MCP exposure |
| `x-openregister-calculations` | Virtual / derived fields (`daysOpen`, `statusBadge`, `quorum`, `isOverdue`) computed in PHP at read time | Available on every object without a service round-trip; cacheable; usable by aggregations and lifecycle guards |
| `x-openregister-notifications` | App-local NotificationService methods that watch object events and dispatch to NC notifications + email | Recipient resolution + notification-channel fan-out + email templates + thresholds + scheduled — all declarative |
| `x-openregister-relations` | App-local link tables, relation-filling service methods | Typed relations across the OR foundation; cross-app queries; respect RBAC; cascade rules |
| `x-openregister-widgets` | App-local dashboard-widget service code | Schema-derived widget definitions consumed by `CnDashboardPage`; one widget definition serves every consumer |

This list updates as OR adds extensions. The OR team owns it; PRs
against this ADR land alongside the new extension's release.

### How to apply this rule

1. **For every new feature/spec.** Before the spec's `design.md` is
   finalised, decide for every behaviour whether it is declarative
   (schema register) or imperative (service). If a fit exists in the
   table above and OR's extension is stable, the spec's `tasks.md`
   MUST land the behaviour as a register patch — not a service class.
   If no fit exists, write the service and reference the gap.
2. **For existing services**, migration is opportunistic. Don't
   re-architect existing apps just to satisfy this ADR. But a feature
   that *modifies* `MotionService.castVote()` is the right time to
   migrate the relevant code path to `x-openregister-lifecycle` and
   delete the now-unused service method.
3. **PHP guards remain a legitimate seam.** A lifecycle transition can
   declare `requires: OCA\App\Lifecycle\FooGuard` to call into PHP for
   non-trivial precondition checks (quorum, role, external state).
   The guard is short, focused, single-method, and called *by* the
   lifecycle engine — it doesn't replace it. Working example:
   `decidesk/lib/Settings/decidesk_register.json` Meeting schema's
   `MeetingTransitionGuard` reference.

### What apps SHOULD still write in PHP

Per ADR-003, apps SHOULD still write service code for:

- **External API integrations** (CalDAV, Peppol, ZGW, ORI, TenderNed,
  IMAP, vendor SaaS). The OR engine cannot reach outside systems; the
  adapter is yours to write.
- **Document/PDF/document-template generation** with app-specific
  templates (e.g. `MinutesGenerationService.generateDraft`, ALV PDF
  rendering). The schema engine has no opinion on rendered output.
- **NLP / domain-specific text processing** (e.g.
  `ActionItemExtractionService` extracting action items from minutes
  text). Domain heuristics belong in code.
- **Domain rule engines** that operate *above* schema metadata —
  e.g. `WorkflowService` that *selects* which lifecycle template
  applies for a given governance domain (legislative vs association
  vs corporate). The selector is in PHP; the lifecycle it selects is
  declarative.
- **Lifecycle guards** as called from `x-openregister-lifecycle.requires`.
- **Background jobs that orchestrate external systems** (mail polling,
  IMAP sync, third-party webhooks).
- **Background jobs that walk an object queue and apply a transition**
  (e.g. "every day, mark overdue ActionItems"). OpenRegister does not
  yet have a schema-extension for declarative scheduled processing on
  an object queue. Two patterns are correct here:
    1. **Use a derived field instead of persisting the state.** If the
       transition is purely a function of object state + time
       (e.g. `dueDate < now`), declare it as
       `x-openregister-calculations` (`isOverdue` boolean) and have
       consumers read the calculated field. No job needed; the value
       is fresh on every read. ActionItem already does this for
       `isOverdue` / `daysLate` / `daysOpen`.
    2. **Use OR's `ScheduledWorkflow` + n8n adapter.** When the work
       genuinely needs to run on a schedule (because consumers can't
       compute the answer themselves, or a side effect like a
       notification must fire on a cadence), define an n8n workflow
       and create a `ScheduledWorkflow` entity tying it to a
       register+schema+interval. The `ScheduledWorkflowJob` TimedJob
       evaluates schedules and dispatches via the n8n adapter. See
       `openregister/openspec/specs/workflow-operations/spec.md`. The
       workflow itself is imperative (n8n holds the logic), but it
       lives outside the app and is operator-configurable. This is
       also the path for cross-cutting periodic work like SLA
       evaluations, retention sweeps, and integration polling.

   Authoring a per-app `*Job` class that walks `findAll()` and calls
   `saveObject()` is **only correct when neither (1) nor (2) fits** —
   e.g. the job orchestrates an external system that n8n can't reach.
   That makes it an external-system orchestrator, falling back under
   the previous bullet.

### Anti-patterns

These have all been observed in the fleet (decidesk specifically) and
should be treated as review-blocking on net-new code:

- **Custom state-machine service** for an object whose schema could
  declare `x-openregister-lifecycle`. Examples in decidesk
  pre-migration: `MotionService.transition*`, `AgendaService.advanceBobPhase`,
  the original `MeetingTransitionGuard` registerService (already
  migrated 2026-05-02 in commits `905fa61` / `70af1f4`).
- **Aggregation service** that loops OR objects and computes
  counts/averages/participation rates. Use `x-openregister-aggregations`.
  Example: `ActionItemAnalyticsService.getSummary` (mid-migration via
  commit `e8b1812`).
- **Calculation service** that returns derived fields. Use
  `x-openregister-calculations` with `@self.created` / `@self.{field}`
  references. Example: `ActionItemService.getDaysOpen`,
  `getStatusBadge` — already migrated via `5496c40` and `a533e78`.
- **Custom notification service** that watches events and dispatches.
  Use `x-openregister-notifications` with declarative recipient
  resolvers. Example: `DecisionNotificationService.notifyOnPublish`.
- **Custom relation/link table** between OR objects (whether a real DB
  table or a service-side glue method). Use `x-openregister-relations`
  on the schema. ADR-022 already prohibits parallel link tables; this
  ADR makes the positive form explicit.

### Exceptions

A custom service is acceptable only when:

1. **OR's extension is missing or insufficient.** Open an issue on the
   `openregister` repo referencing this ADR; describe what the
   extension would need. Use a service in the meantime; remove it
   when the extension lands. Document the exception in the spec's
   `design.md` so the reviewer sees it.
2. **The behaviour spans schemas in a way the extension can't
   express** (e.g. a calculation that joins three schemas and applies
   a domain-specific rule the engine can't model). Justify in
   `design.md` and reference the schema-engine limitation.
3. **Profiled performance.** A declarative implementation that
   profiled measurably worse than the bespoke one — with numbers — is
   grounds for keeping the service. Rare; ask first.

Every exception is documented in the spec's `design.md` under a
"Declarative-vs-imperative decision" heading and surfaced to the
reviewer. "We didn't know OR had this extension" is not an exception.

### Enforcement

- **Spec generation** (`opsx-ff` and Specter `app-create` /
  `generate_spec_content`): when a spec mentions "lifecycle",
  "transition", "state machine", "aggregation", "summary", "count",
  "notification", "alert", "calculation", "derived field", "virtual
  field", or "scheduled", the generator MUST produce a
  declarative-vs-imperative decision in `design.md` and a register
  patch in `tasks.md` for the declarative side. The provisional
  default is declarative.

- **Builder** (Al Gorithm, hydra-builder): before authoring a new
  `lib/Service/*Service.php` whose method names suggest lifecycle
  (`transition*`, `setStatus*`, `advance*`), aggregation (`getSummary*`,
  `getStats*`, `count*`, `*Rate`), calculation (`compute*Field*`,
  `derive*`, `get*Display*`), or notification (`notifyOn*`,
  `dispatch*Notification*`) semantics, the builder MUST check whether
  the requirement is expressible via the extensions in the table
  above. If yes, the builder writes a register-file patch instead.
  This rule is mirrored in `images/builder/CLAUDE.md` alongside Rule 0.

- **Reviewer + Security reviewer**: a new Service class whose method
  names match lifecycle/aggregation/calculation/notification semantics
  is a review finding. Severity: **WARNING** on the first cycle of an
  existing app (during opportunistic migration); **BLOCKING** on
  net-new apps and on new schemas in any app.

- **Future**: a soft `hydra-gate-30-prefer-declarative` mechanical
  gate (regex-detection of suspect Service method names; warn-only)
  is a follow-up. Intentionally deferred until we have a
  false-positive baseline from manual review. Track as a Hydra issue
  when this ADR lands.

## Consequences

### Positive

- **Apps shrink.** Decidesk's MotionService, VotingService,
  AgendaService, QuorumService, ActionItemAnalyticsService,
  DecisionNotificationService — ~3,000 LOC of orchestration —
  collapse to schema metadata + thin guards. Pipelinq and procest
  never grow that mass in the first place.
- **Cross-app uniformity.** A "submitted → adopted" lifecycle works
  the same in decidesk (motion), procest (zaak), and pipelinq
  (complaint) — same audit format, same RBAC hooks, same CloudEvents,
  same MCP discovery, same GraphQL exposure.
- **Builder produces less code, less wrong.** The builder writes a
  JSON patch on `lib/Settings/{app}_register.json` instead of
  authoring + testing a new service class. Faster to generate, fewer
  attack surfaces, fewer review findings, smaller PRs.
- **The OR engine compounds.** Every improvement to the
  lifecycle/aggregation/notification engine (bulk-transition,
  priority-aware notifications, schema-derived widgets) lands across
  the whole fleet without per-app work.
- **Specter's intelligence brief becomes more valuable.** Market
  features mapped to `x-openregister-*` extensions in the brief
  short-circuit the design step entirely.

### Negative

- **OR is a bottleneck.** A new declarative pattern an app needs but
  OR can't yet express requires an OR change before the app can use
  the rule. Mitigation: exception (1) above + fast OR iteration on
  extension requests + the OR team prioritising long-tail extensions.
- **Authors need to know the extensions exist.** The onboarding curve
  for a new Conduction developer now includes the seven
  `x-openregister-*` extensions and the schemas they apply to.
  Mitigated by `decidesk/lib/Settings/decidesk_register.json` as the
  canonical example, the design-system tutorial paired with the
  decidesk migration cleanup (Stage B of the readiness plan), and
  explicit prompting in `app-create` / `opsx-ff`.
- **Migration discipline.** Without the soft gate, mechanical-pattern
  violations slip through review. Mitigated by the explicit reviewer
  instruction; revisit if false-positive rate from manual review is
  acceptable enough to harden into a gate.

### Migration

This ADR does not require apps to migrate existing services. Migration
is opportunistic:

- **Net-new apps**: declarative-first from day one. No legacy services.
- **Net-new schemas in existing apps**: declarative-first.
- **Net-new features modifying existing services**: migrate the touched
  code path (e.g. a feature touching `MotionService.castVote()`
  migrates the voting lifecycle to `x-openregister-lifecycle` as part
  of the feature).
- **Periodic cleanup PRs**: each app picks one or two services per
  release cycle; not blocking.

Decidesk is the leading-example reference. The
canonical-example checklist (Stage B of the manifest-readiness plan
tracked alongside this ADR) targets MotionService, VotingService,
QuorumService, ActionItemAnalyticsService, DecisionNotificationService,
and OverdueActionItemsJob as the five high-leverage migrations that
will leave decidesk as the clean canonical reference for the rest of
the fleet.

## See also

- **ADR-022** — apps consume OR abstractions. This ADR is the
  schema-engine dual to that principle. ADR-022's abstractions table
  is updated alongside this ADR to include the seven schema
  extensions.
- **ADR-024** — app manifest. The frontend declarative principle;
  this ADR is the backend declarative principle. Together they
  describe the no-code-glue app target shape.
- **ADR-019** — integration registry. The first concrete instance of
  declaratively-extended OR; this ADR generalises the same idea to
  schema-level behaviour.
- **ADR-001** — data layer (no custom Entity/Mapper). Schema-declarative
  metadata builds on top of this; ADR-031 only applies because all
  data already lives in OR.
- **ADR-003** — backend rules. Lists what apps SHOULD build (external
  integrations, document gen, domain rule engines) — exactly the
  surface that remains as PHP after declarative migration.
- **ADR-013** — container model strategy. The builder runs on Haiku,
  which makes the "follow the explicit rule, don't infer" property
  of this ADR especially load-bearing — Haiku copies the dominant
  pattern unless told otherwise.
- **`decidesk/lib/Settings/decidesk_register.json`** — working examples
  of `x-openregister-lifecycle` (Meeting, Motion, Amendment),
  `-aggregations` (ActionItem), `-calculations` (ActionItem), and
  `-notifications` (Meeting, Decision).
- **`images/builder/CLAUDE.md`** — the builder's container instruction
  sheet, updated alongside this ADR with the declarative-first rule.

## Alternatives considered

1. **Strengthen ADR-022 wording without a new ADR.** Rejected. ADR-022
   is the *principle* (consume OR abstractions). The schema extensions
   are a specific class of abstraction with their own contract,
   migration story, and enforcement points — they earn their own ADR.
   ADR-022's abstractions table gains a row referencing this ADR.

2. **Hard-fail mechanical gate from day one.** Rejected. False-positive
   rate is unknown — a service named `getSummaryReport` that returns
   a rendered PDF should NOT be flagged as "should have been
   x-openregister-aggregations". Start with reviewer judgment +
   warning-level findings; promote to gate when the false-positive
   rate is measured and acceptable.

3. **Auto-migrate existing services on the next pipeline run.**
   Rejected. The migration touches data shape (audit trail format,
   transition events) — automated migration risks silently breaking
   existing consumers. Opportunistic migration tied to feature work
   keeps the blast radius bounded.

### ADR-032-spec-sizing-and-chaining
# ADR-032: Spec sizing taxonomy and chained-spec routing

## Status
Proposed

## Date
2026-05-07

## Context

Hydra was built around the idea of "one OpenSpec change → one Hydra
cycle → one PR". That model works perfectly for the kind of work
ADR-024 (manifest) and ADR-031 (declarative business logic) optimised
the fleet for: small declarative JSON edits — add a route to
`src/manifest.json`, declare an aggregation on a schema, add a
calculation, register a notification.

It does *not* work for code refactors. The 2026-05-07 Stage A
empirical run on decidesk surfaced this concretely:

- Two ADR-031 migration specs were drafted (`quorum-declarative-migration`,
  `actionitem-analytics-declarative-migration`).
- Each was a "config + code" envelope: schema-register patches PLUS
  controller updates PLUS service-class deletions PLUS test rewrites
  PLUS frontend wire-shape verification.
- Both pipelines burned the full 200-turn Sonnet builder budget without
  producing a PR. The orchestrator detected "Claude session closed
  without running gh pr create" and emitted `build:fail`.
- Real builder work happened — test files were edited, phpunit ran
  (1 failure, 30 skipped), schema register patches were drafted —
  but the scope was too big for one cycle.

Reading the post-mortem, the failure mode is structural:

1. **Hydra's reviewer pipeline is judgment-heavy on code surfaces** —
   18 mechanical gates check PHP authoring discipline (SPDX, route auth,
   IDOR, semantic auth, etc.). Each is fast but they compound for
   multi-file refactors.
2. **The schema engine's reviewer surface is much smaller** — for a
   register patch, the relevant checks are: schema validation, ADR-031
   declarative-fit confirmation, integration test coverage. ~3 gates,
   cheap.
3. **A "mixed" spec exercises both surfaces in one cycle** and competes
   for the same 200-turn budget that was sized for one or the other.

The fix is a taxonomy + chaining discipline, not a budget bump.
Bumping max_turns just delays the same cliff.

## Decision

### Every change has a `kind`

Three kinds, declared in the proposal frontmatter:

| `kind:` | What it touches | Hydra route | Default budget | Reviewer scope |
|---|---|---|---|---|
| `config` | Only declarative JSON (`lib/Settings/{app}_register.json`, `src/manifest.json`, OpenAPI specs, schema files, register templates). Integration tests for the new config are allowed. | Hydra builder, default | 200 turns Sonnet | Schema/manifest validation + ADR-031 declarative-fit check + integration test coverage |
| `code` | PHP / Vue / TS / etc. May incidentally touch declarative JSON, but the centre of mass is code. | Hydra builder OR manual (decided per spec) | 200 turns (small) or 400 via `HYDRA_BUILDER_MAX_TURNS` (larger) | Full code review (all 18 gates) |
| `mixed` | Both declarative JSON edits AND non-trivial code edits in one envelope. | **Reject — split first** | n/a | n/a |

`mixed` is an anti-pattern. The two Stage A specs were `mixed` in
hindsight; that's why they failed. Anti-pattern detail in
"Enforcement" below.

### Multi-step migrations chain via `depends_on`

Hydra already supports per-spec `depends_on` in `hydra.json` (see
hydra/CLAUDE.md → hydra.json Schema → `depends_on` field; the
supervisor blocks a spec from building until each named dep is
closed). This ADR makes chaining the **default** pattern for any
migration that touches both declarative and code surfaces:

1. **Spec 1 (`kind: config`)** — declare the new schema metadata. Add
   the integration test that verifies the materialised values are
   correct. Engine-dependency spike (when applicable) lives here.
   Merges first; the new fields are now read-only-available on every
   object.
2. **Spec 2+ (`kind: code`)** — update consumers (controllers, guards,
   widgets) to read the new declarative fields. Each `depends_on`s the
   schema spec.
3. **Spec N (`kind: code`)** — delete the obsolete imperative
   implementation (the service class + its tests + DI wiring). May be
   bundled with spec 2 if small; spun out if not.

The chain is **explicit in the proposal**: each spec's frontmatter
lists its predecessors, and the proposal narrative names the chain
(e.g. "this is spec 2 of 3 in the quorum-migration chain; spec 1 is
`quorum-schema-declaration`, spec 3 is `quorum-service-deletion`").

### Why chaining works

Three properties chain-splitting buys that one big spec doesn't:

- **Engine dependencies surface early.** Spec 1 spikes the cross-schema
  aggregation in isolation. If the engine can't express it, the chain
  pauses on spec 1 — code work in spec 2+ is never wasted.
- **Reviewer scope per spec is tight.** A `config` spec runs through
  3-4 schema-relevant checks; a `code` spec runs through 18. Mixing
  them is what blew the budget — 18 + 4 + multi-file-orchestration is
  how Sonnet hits 200 turns.
- **Mid-chain merge is safe.** The schema declarations land first (no
  consumer change). Existing consumers ignore the new fields. New
  consumers can opt-in incrementally. This pattern is the same shape
  as Postgres expand-then-contract migrations.

### How to declare

In **proposal.md frontmatter**:

```yaml
---
kind: config
depends_on: []
chain:
  - quorum-schema-declaration   # this spec
  - quorum-guard-rewrite         # next in chain
  - quorum-service-deletion      # last in chain
---
```

In **hydra.json** (created/updated by orchestrator):

```jsonc
{
  "schema_version": 2,
  "spec_slug": "quorum-guard-rewrite",
  "kind": "code",
  "depends_on": ["quorum-schema-declaration"],
  ...
}
```

`depends_on` references **issue numbers** (or issue URLs) once the
chain has been planned-to-issues. Until issues exist, reference by
spec slug; the planner translates slug → issue at issue-creation time.

### When to NOT chain

Two cases where a single spec is correct:

1. **Pure config, no code changes downstream.** If declaring the
   metadata is the entire change (e.g. add a notification trigger,
   add an aggregation a dashboard already polls), no chain needed.
2. **Pure code, no declarative surface.** External integration glue
   (CalDAV, ZGW, ORI), document generation, NLP — already-imperative
   work that has no declarative analogue per ADR-031.

The chain pattern applies specifically to **migrations from imperative
to declarative**, where the natural shape is "declare → consume →
delete imperative".

### Thin-glue exception (mixed but small)

A `mixed` spec is permitted when the code change is genuinely thin
glue (≤20 LOC across ≤2 files) and is tightly coupled to the config
change. Document the coupling in design.md under
"Mixed-spec rationale". Still a yellow flag in review; the reviewer
asks "could this glue have been deferred to a chain spec 2?". Most of
the time the answer is yes, and the reviewer suggests the split.

### Enforcement

- **`opsx-ff`**: at proposal generation, asks the spec author "is this
  config-only, code-only, or both?" and emits `kind:` in the frontmatter.
  If `kind: mixed`, opsx-ff offers to split the proposal into a chain
  before any other artifact is written. Provisional default `kind` is
  inferred from the change description; the user confirms.
- **Builder pre-flight (`gate-32-spec-kind`, future)**: parses the
  spec's `tasks.md` for file extensions touched. If config + code
  mixed without a documented thin-glue exception → soft-fail (warn
  the builder; record `pattern_tags: ["spec-kind-mixed"]` on the
  cycle). Mechanical detection by file-extension classification:
  `*.json` (declarative) vs `*.php`/`*.vue`/`*.ts`/`*.js` (code).
- **Reviewer**: same parser; surface as WARNING. The reviewer's
  bounded-fix authority does NOT extend to splitting a spec —
  splitting is the spec author's job. The reviewer flags + escalates.
- **Hardening timeline**: gate-32 stays as a soft warning for 30 days
  after this ADR lands. Post-30-days, if the false-positive rate is
  acceptable (target: <10% on observed PRs), promote to BLOCKING.
  Track as a hydra issue.

### `kind: code` budget rules

For `kind: code` specs that genuinely need >200 turns, two paths:

1. **Split further.** Most "I need 400 turns" code specs are actually
   2-3 specs in disguise. Apply the chain pattern.
2. **Explicitly bump.** When splitting isn't viable (e.g. atomic
   refactor that genuinely can't decompose), set
   `HYDRA_BUILDER_MAX_TURNS=400` in the issue body or as a label
   `budget:large`. The supervisor reads this and dispatches with the
   bumped budget. Soft-cap remains at 800; beyond that the spec
   should be manual.

## Consequences

### Positive

- **Hydra builds finish.** Config specs at 30-80 turns each; chains
  for full migrations. Stage A's failure mode (200-turn timeout) is
  retired.
- **Engine dependencies surface early and isolated.** The
  cross-schema-aggregation spike that gated quorum + analytics now
  lives in a single ~30-turn spec. If it fails, the chain pauses
  cleanly.
- **Reviewer scope is right-sized per spec.** Config specs check
  what's relevant to schemas; code specs check what's relevant to
  code. No 18-gate review on a register-patch PR.
- **Spec authors think in atomic units.** Forces the
  expand-then-contract migration pattern by default — same hygiene
  Postgres / Liquibase / DB-migration teams have used for decades.
- **`opsx-ff` becomes a spec-shape coach.** "What you described is
  mixed; here's the chain I'd suggest" is a useful dialogue, not a
  blocker.

### Negative

- **More specs per logical change.** A 3-spec chain is more
  orchestration than 1 spec. Mitigated by chains being short (3-5
  specs typical) and the orchestrator handling `depends_on` blocking
  automatically.
- **Half-migrated states surface in code.** During chain execution,
  some consumers read the new declarative field while the imperative
  implementation still exists. Mitigated by the expand-contract
  pattern — both work simultaneously, switch happens atomically at
  the consumer level.
- **`opsx-ff` UX gets one more question.** The kind classification
  question adds 30s to spec generation. Acceptable in exchange for
  catching `mixed` early.
- **Spec library noise increases short-term.** Migration of N services
  → 3N specs. Mitigated by archive discipline (per `opsx-archive`)
  once a chain is fully merged.

### Migration

Existing in-flight specs that are `mixed` (per the 2026-05-07 audit:
PRs #146, #147 on decidesk):

1. Re-classify as `mixed`, mark the existing PR closed-as-superseded.
2. Split into a chain. Re-name the original change as the schema-only
   member; create new chain members for code.
3. Issues #148, #149 get closed with a comment naming the new chain
   member issues; ready-to-build labels move to the new schema-only
   issues.

Concretely for the two Stage A specs:

- **`quorum-declarative-migration`** → chain:
  - `quorum-schema-declaration` (config) — declare aggregations + calculations + integration test + engine spike
  - `quorum-guard-rewrite` (code, small) — `MeetingTransitionGuard` reads the new fields
  - `quorum-service-deletion` (code, small) — delete `QuorumService.php` + DI + tests
- **`actionitem-analytics-declarative-migration`** → chain:
  - `analytics-schema-declaration` (config) — declare aggregations on Meeting for actionItem completion + integration test
  - `analytics-controller-rewrite` (code, small) — `AnalyticsController` reads new fields
  - `analytics-getCompletionRates-deletion` (code, small) — delete the obsolete service method

Same shape will apply to the other three planned ADR-031 migrations
(VotingService, MotionService, DecisionNotificationService). Each
becomes a 2-3 spec chain.

## See also

- **ADR-013** — container model strategy. The 200-turn Sonnet budget
  this ADR works within is the same budget; we right-size the *scope*,
  not the budget.
- **ADR-020** — gate scope is the PR diff. Per-spec scope discipline
  inherits naturally — a config-only spec PR has no code-relevant
  gate failures because no code changed.
- **ADR-021** — reviewer bounded-fix scope by shape. A config spec's
  bounded-fix shape is "edit one JSON block". Tight.
- **ADR-024** — app manifest. The frontend declarative principle that
  this ADR's `kind: config` extends to backend schema metadata.
- **ADR-031** — schema-declarative business logic. The migration class
  that this ADR's chain pattern is purpose-built for.
- **hydra/CLAUDE.md → hydra.json Schema → `depends_on`**. The
  underlying mechanism this ADR formalises as the chain primitive.

## Alternatives considered

1. **Bump default builder budget to 400 turns.** Rejected. Stage A
   showed that "200 wasn't enough"; 400 wouldn't cover the same
   refactors with a comfortable margin and lets the same anti-pattern
   compound. The right intervention is scope, not budget.
2. **Split mixed specs only after the first failure.** Rejected.
   Already what Stage A did empirically; observation: half the team
   doesn't know what "right-sized" looks like, so the split-after
   pattern produces a flurry of `rebuild:queued` cycles instead of
   one clean chain. Codifying the taxonomy upstream prevents the
   first failure.
3. **Make Hydra exclusively config-only; route all code work to
   manual.** Rejected. Many `code` specs are clean small diffs
   Hydra handles fluently (e.g. "delete a method", "add a guard
   clause"). The cliff is at "mixed", not at "code".

## App-Specific ADRs (2)

These ADRs are specific to Shillinq.

### adr-000-data-model: ADR: Data Model — Shillinq
# ADR: Data Model — Shillinq

**Status:** accepted
**Entities:** 225

## Context

All data entities are OpenRegister schemas. This ADR is the single source of truth
for the app's data model. Individual specs REFERENCE these entities but do not redefine them.

OpenRegister built-in fields (NOT listed below, always available):
id, uuid, uri, version, createdAt, updatedAt, owner, organization,
register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

## Entities

### APTransaction
**Schema.org:** `schema:Order`
_Financial transaction representing an invoice, credit note, or debit note in accounts payable/receivable flow._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionNumber | string | Yes | Unique invoice or transaction identifier |
| transactionType | enum | Yes | Type of transaction |
| transactionDate | date | Yes | Date invoice or transaction issued |
| dueDate | date | Yes | Payment due date |
| amount | MonetaryAmount | Yes | Total transaction amount including tax |
| paymentTerms | string | No | Payment conditions (e.g., net 30, 2/10 net 30) |
| description | string | No | Invoice line items or transaction details |

**Relations:**
- → Payee (many-to-one)
- → Receipt (one-to-many)
- → Payment (one-to-many)
- → DunningNotice (one-to-many)

### Account
_Business account representing a separate organization or workspace that users can access and manage_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Account display name |
| accountNumber | string | Yes | Unique account number or GL code |
| accountType | string | Yes | Classification: assets, liabilities, equity, revenue, expenses |
| balance | number | Yes | Current account balance |
| currency | string | Yes | ISO 4217 currency code (e.g. EUR) |
| description | string | No | Detailed account description |
| iban | string | No | Dutch IBAN for bank/cash accounts |
| vatApplicable | boolean | No | Whether VAT applies to this account |
| isArchived | boolean | No | Soft-delete flag for inactive accounts |
| parentAccountNumber | string | No | Parent account for hierarchical GL structures |

**Relations:**
- → Organization (many-to-one)
- → User (many-to-many)
- → Team (one-to-many)

### AccountabilityReport
**Schema.org:** `schema:Report`
_An official accountability report submitted by an organization for a fiscal period covering financial position and transactions_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportNumber | string | Yes | Unique identifier for the accountability report |
| reportDate | datetime | Yes | Date the report was generated |
| submissionDate | datetime | No | Date the report was submitted to relevant authority |
| status | string | Yes | Status (draft, submitted, approved, rejected) |
| content | string | No | Full text content of the accountability report |
| approvalStatus | string | Yes | Approval status (pending, approved, rejected) |

**Relations:**
- → FiscalYear (many-to-one)
- → Organization (many-to-one)
- → Person (many-to-one)
- → DigitalDocument (one-to-many)

### Administration
**Schema.org:** `schema:DigitalDocument`
_Accounting administration unit for a specific business year of a corporation. Supports multi-administration management for tracking financial records per fiscal year._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationNumber | string | Yes | Unique identifier for this administration unit |
| businessYear | string | Yes | Business year (YYYY format) |
| accountingPeriod | string | Yes | Period type: monthly, quarterly, or annual |
| startDate | date | Yes | Start date of the accounting period |
| endDate | date | Yes | End date of the accounting period |
| accountantName | string | No | Responsible accountant or accounting firm name |
| submissionDate | date | No | Date administration was submitted (if applicable) |

**Relations:**
- → Corporation (many-to-one)

### AllocationRule
**Schema.org:** `schema:Thing`
_Recurring rule for automatically allocating overhead and shared costs between cost centers based on percentage, fixed amount, or calculation formula_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the allocation rule |
| ruleType | string | Yes | Type: percentage, fixed amount, or formula-based |
| percentage | number | No | Percentage to allocate (if percentage-based) |
| fixedAmount | number | No | Fixed amount to allocate per period |
| frequency | string | Yes | Frequency: monthly, quarterly, or yearly |
| isActive | boolean | Yes | Whether rule is currently active |
| startDate | datetime | Yes | Date rule becomes effective |
| endDate | datetime | No | Date rule expires |
| description | string | No |  |

**Relations:**
- → CostCenter (many-to-one)
- → CostCenter (many-to-one)

### ApprovalChain
**Schema.org:** `ApprovalChain`
_Configurable approval workflows that define the sequence of approvers for different document types_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| chainId | string | Yes | Unique approval chain identifier |
| name | string | Yes | Name of the approval chain |
| documentType | string | Yes | Type of document this applies to (PurchaseOrder, Document, ExpenseClaim, etc.) |
| description | string | No | Workflow description |
| status | string | No | active or inactive |
| approverSequence | array | Yes | Ordered list of approver roles or users |
| requiresSignature | boolean | No | Whether approval requires digital signature |

**Relations:**
- → ApprovalTask (one-to-many)

### ApprovalRequest
**Schema.org:** `schema:Event`
_Approval workflow management for purchase orders and documents_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| requestNumber | string | Yes | Unique approval request ID |
| description | text | Yes | What requires approval and business justification |
| startDate | date | Yes | Approval workflow initiation date |
| dueDate | date | No | Target approval deadline |
| requiredApproversCount | integer | Yes | Number of approvals required |
| currentApprovalCount | integer | No | Current approval count |
| approverEmails | string | No | Comma-separated approver contact list |

**Relations:**
- → PurchaseOrder (many-to-one)
- → Document (many-to-one)

### ApprovalRoute
**Schema.org:** `schema:Event`
_Workflow defining contract approval steps and authorized approvers_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of approval workflow |
| description | string | No | Description of approval process |
| approverSequence | array | No | Ordered list of approver names/roles/groups |
| priority | string | No | Workflow priority (Low, Medium, High) |
| estimatedDays | number | No | Estimated days to complete approvals |

### ApprovalTask
**Schema.org:** `schema:Action`
_Individual approval task assigned to a user within an approval workflow_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taskId | string | Yes | Unique approval task identifier |
| approvalChainId | string | Yes | Reference to the approval chain configuration |
| documentId | string | Yes | Reference to the document being approved |
| approvalRequestId | string | Yes | Reference to the approval request |
| stepNumber | number | Yes | Step number in the approval sequence |
| assignedTo | string | Yes | Person/User ID assigned this task |
| status | string | Yes | pending/approved/rejected/delegated |
| dueDate | datetime | No | When approval is due |
| completedDate | datetime | No | When approval was completed |
| approvalComment | string | No | Comments from the approver |

**Relations:**
- → ApprovalChain (many-to-one)
- → ApprovalRequest (many-to-one)
- → Document (many-to-one)
- → Person (many-to-one)

### AssessmentCriteria
**Schema.org:** `schema:Thing`
_Weighted criteria schema for property scoring and evaluation_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes |  |
| category | string | Yes | structure, condition, location, market, etc |
| description | string | Yes |  |
| weight | number | Yes | Weight percentage 0-100 |
| rubric | string | No | Scoring guide |
| applicability | string | Yes | required, optional, conditional |
| active | boolean | Yes |  |

**Relations:**
- → PropertyAssessment (many-to-many)

### Assignment
**Schema.org:** `schema:AggregateOffer`
_A specific work assignment or engagement of a freelancer with a client_
**Primary spec:** freelancers-zzp

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Assignment title |
| description | string | No | Assignment description |
| startDate | datetime | Yes | Assignment start date |
| endDate | datetime | No | Assignment end date |
| hourlyRate | number | No | Hourly rate for this assignment |
| status | string | Yes | Assignment status |

**Relations:**
- → Freelancer (many-to-one)
- → Organization (many-to-one)
- → TimeEntry (one-to-many)

### Auction
**Schema.org:** `schema:AuctionEvent`
_Auction format for competitive bidding with multiple formats and real-time bid tracking_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| auctionId | string | Yes | Unique auction identifier |
| auctionType | string | Yes | Type: english, dutch, sealedbid, reverse |
| startDate | datetime | Yes | Auction start time |
| endDate | datetime | Yes | Auction end time |
| status | string | Yes | Status: pending, active, closed, awarded |

**Relations:**
- → Lot (many-to-one)
- → Offer (one-to-many)

### AuditFinding
**Schema.org:** `schema:Report`
_Individual finding or observation from audit requiring management action or response_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| findingType | enum | Yes | Type: deficiency, observation, or finding |
| severity | enum | Yes | Priority: critical, high, medium, or low |
| description | text | Yes | Detailed finding description |
| remediation | text | No | Recommended remediation actions |
| dueDate | date | No | Target remediation completion date |

**Relations:**
- → Person (many-to-one)
- → ManagementLetter (many-to-one)

### AuditorStatement
**Schema.org:** `schema:Statement`
_An auditor statement registering and verifying grant compliance and authenticity for large subsidies_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| statementId | string | Yes | Unique statement identifier |
| verificationDate | datetime | Yes | Date of auditor verification |
| isVerified | boolean | Yes |  |
| findings | string | No | Audit findings and observations |
| verdict | string | No | Audit verdict: approved, rejected, conditional |

**Relations:**
- → Grant (many-to-one)
- → Person (many-to-one)
- → DigitalDocument (one-to-one)

### AwardDecision
**Schema.org:** `schema:Order`
_Award decision documenting bid evaluation outcome, selected supplier, and contract authorization_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Award decision identifier |
| description | string | No | Summary of award rationale |
| awardDate | date | Yes | Date the award was decided |
| awardedAmount | number | Yes | Contract value of awarded bid |
| currency | string | Yes | Currency code for contract value |
| justification | string | No | Evaluation summary and decision rationale |

**Relations:**
- → BidEvaluation (many-to-one)
- → SupplierBid (many-to-one)
- → Supplier (many-to-one)
- → Contract (one-to-one)

### AwardNotice
**Schema.org:** `schema:CreativeWork`
_Legal notice of award with publication deadline and standstill enforcement for compliance_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| noticeId | string | Yes | Unique award notice identifier |
| publicationDate | datetime | Yes | Date notice was published |
| legalDeadline | datetime | Yes | End of standstill period after publication |
| status | string | Yes | Status: draft, published, enforced, archived |
| archiveDate | datetime | No | Compliance archive date |

**Relations:**
- → AwardDecision (many-to-one)
- → Lot (many-to-many)

### BalanceSheet
**Schema.org:** `schema:Table`
_A financial statement showing assets, liabilities, and equity at a specific point in time_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportDate | datetime | Yes | Date of the balance sheet snapshot |
| totalAssets | number | No | Total assets in base currency |
| totalLiabilities | number | No | Total liabilities in base currency |
| totalEquity | number | No | Total equity in base currency |
| currency | string | Yes | Currency code for amounts |
| status | string | Yes | Status (draft, final, published) |

**Relations:**
- → FiscalYear (many-to-one)
- → Organization (many-to-one)
- → GeneralLedgerEntry (one-to-many)

### BankAccount
**Schema.org:** `schema:BankAccount`
_Schema.org BankAccount — standard vocabulary for bankaccount data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountName | string | Yes | Account display name |
| iban | string | Yes | IBAN number |
| bic | string | No | BIC/SWIFT code |
| bankName | string | No | Name of the bank |
| currency | string | Yes | Account currency |
| balance | number | No | Current balance |

### Bid
**Schema.org:** `schema:Offer`
_A supplier's response to a tender with proposed pricing and terms; includes sealed bid handling and multi-round bidding_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| bidNumber | string | Yes | Unique identifier for the bid |
| submissionDate | datetime | Yes | Date and time the bid was submitted |
| amount | number | No | Bid price or quote amount |
| currency | string | No | Currency code for the bid |
| status | string | Yes | Status: submitted, received, under review, evaluated, accepted, rejected, withdrawn |
| isSealed | boolean | No | Whether the bid is encrypted for sealed bid opening |
| evaluationScore | number | No | Numerical score assigned during evaluation |
| evaluationRank | number | No | Ranking relative to other bids (1=best) |
| notes | string | No | Evaluation comments or clarifications |

**Relations:**
- → Tender (many-to-one)
- → TenderLot (many-to-one)
- → Organization (many-to-one)
- → DigitalDocument (one-to-many)
- → BiddingRound (many-to-one)

### BidEvaluation
**Schema.org:** `schema:Event`
_Automated evaluation process for competitive bids with configurable scoring criteria and rules_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Evaluation process name or tender reference |
| description | string | No | Procurement description and requirements |
| startDate | date | Yes | Evaluation opening/start date |
| endDate | date | Yes | Evaluation closing/completion date |
| evaluationCriteria | json | Yes | Configurable criteria (price weighting, quality factors, technical specs) |
| scoringRules | json | No | Automated scoring formulas and calculation rules |
| minimumScore | number | No | Minimum threshold score to qualify for award |

**Relations:**
- → SupplierBid (one-to-many)
- → AwardDecision (one-to-one)

### BiddingRound
**Schema.org:** `schema:Thing`
_A round of bidding within a multi-round procurement process, supporting sequential RFQ, RFP, and reverse auction workflows_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| roundNumber | number | Yes | Sequential round number within the tender |
| roundType | string | No | Type: initial, clarification, final, auction, or negotiation |
| startDate | datetime | Yes | Start date of the bidding round |
| closingDate | datetime | Yes | Deadline for submissions in this round |
| status | string | Yes | Status: pending, open, closed, evaluated, completed |
| minBidReduction | number | No | Minimum bid reduction required for auction rounds |
| extensionEnabled | boolean | No | Whether extension of deadlines is allowed |

**Relations:**
- → Tender (many-to-one)
- → Bid (one-to-many)

### BlanketPurchaseOrder
**Schema.org:** `schema:Order`
_Master purchase order with authorized spend limit, scheduled release management, and consumption tracking for blanket purchasing arrangements_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| blanketPoNumber | string | Yes | Unique blanket PO identifier |
| validFrom | datetime | Yes | Blanket PO effective start date |
| validUntil | datetime | Yes | Blanket PO expiration date |
| totalAuthorizedAmount | number | Yes | Total authorized spend limit |
| consumedAmount | number | No | Amount spent against blanket PO to date |
| remainingAmount | number | No | Remaining authorized spend |
| releaseSchedule | array | No | Scheduled release dates and amounts |
| status | string | Yes | active, closed, cancelled |

**Relations:**
- → Organization (many-to-one)
- → ProcurementCatalog (many-to-one)
- → PurchaseOrder (one-to-many)
- → ApprovalRequest (many-to-one)

### Branch
**Schema.org:** `schema:LocalBusiness`
_Physical or organizational branch location for branch-wise tracking of payments, inventory, sales, and purchasing_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes |  |
| address | string | Yes |  |
| city | string | Yes |  |
| province | string | Yes |  |
| branchType | string | No | main office, warehouse, retail, etc |
| headcount | number | No |  |
| status | string | Yes | active, inactive, planned |
| establishedDate | datetime | No |  |

**Relations:**
- → Organization (many-to-one)
- → Person (many-to-one)

### Budget
_A financial plan allocating resources for a specific period, organization, and location_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| budgetName | string | Yes | Name or identifier of the budget |
| totalAmount | number | Yes | Total budgeted amount in the specified currency |
| startDate | datetime | Yes | Date when the budget becomes effective |
| endDate | datetime | Yes | Date when the budget expires |
| description | string | No | Detailed description or purpose of the budget |
| currency | string | Yes | Currency code (ISO 4217), defaults to EUR for Dutch organizations |
| budgetCategory | string | Yes | Category of the budget (e.g., operational expenses, capital expenses, revenue) |
| amountSpent | number | No | Current amount spent or committed against this budget |
| alertThreshold | number | No | Percentage (0-100) at which to trigger spending alerts |
| budgetType | string | No | Type of budget (fixed, flexible, rolling, zero-based) |
| fiscalYear | integer | Yes | Fiscal year this budget applies to (e.g., 2026) |
| costCenter | string | No | Cost center or department code for budget allocation |
| attachments | array | No | Supporting documents and justification files |

**Relations:**
- → Organization (many-to-one)
- → Location (many-to-one)
- → Person (many-to-one)
- → BudgetPeriod (many-to-one)
- → BudgetAllocation (one-to-many)
- → BudgetAmendment (one-to-many)
- → ExpenditureRequest (one-to-many)

### BudgetAllocation
_A subdivision of budget resources allocated to a specific department, funding source, or purpose_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| allocationNumber | string | Yes | Unique identifier for the allocation |
| amount | number | Yes | Allocated amount |
| status | string | Yes | Status: pending, approved, allocated, spent, closed |
| description | string | No | Details about the allocation |

**Relations:**
- → Budget (many-to-one)
- → FundingSource (many-to-one)
- → Organization (many-to-one)

### BudgetAmendment
_A proposed or executed change to an approved budget amount_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| amendmentNumber | string | Yes | Unique identifier for the amendment |
| originalAmount | number | Yes | Original budgeted amount |
| newAmount | number | Yes | Revised budget amount |
| reason | string | Yes | Reason for the amendment |
| status | string | Yes | Status: proposed, pending_approval, approved, rejected, executed |
| effectiveDate | datetime | No | When amendment takes effect |

**Relations:**
- → Budget (many-to-one)
- → ApprovalRequest (many-to-one)

### BudgetPeriod
_A defined time period for budget planning, such as fiscal year, calendar year, or quarter_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the period (e.g., 'FY2024', 'Q1 2024') |
| type | string | Yes | Period type: fiscal_year, calendar_year, quarter, month, or custom |
| startDate | datetime | Yes | Period start date |
| endDate | datetime | Yes | Period end date |
| fiscalYear | string | No | Associated fiscal year (e.g., '2024') |

**Relations:**
- → Budget (one-to-many)

### CallOffOrder
**Schema.org:** `schema:Order`
_An order placed against a blanket or framework agreement, with delivery scheduling and consumption tracking_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| callOffNumber | string | Yes | Unique call-off order number |
| orderDate | datetime | Yes | Date the call-off order was created |
| status | string | Yes | Status: draft, issued, accepted, in progress, partially delivered, delivered, closed |
| orderedQuantity | number | No | Total quantity ordered |
| consumedQuantity | number | No | Quantity already delivered or consumed |
| unitPrice | number | No | Unit price for items |
| totalAmount | number | No | Total order amount |
| currency | string | No | Currency code |
| deliverySchedule | array | No | Planned delivery dates and quantities |

**Relations:**
- → Order (many-to-one)
- → Organization (many-to-one)
- → Product (many-to-many)

### CashAccount
**Schema.org:** `schema:BankAccount`
_Track bank accounts, petty cash, and cash equivalents for liquidity management and multi-account consolidation_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountType | string | Yes | BankAccount, PettyCash, or CashEquivalent |
| accountCode | string | Yes | Internal GL account code |
| riskLevel | string | No | Low, Medium, High |

**Relations:**
- → Organization (many-to-one)

### CatalogItem
**Schema.org:** `schema:Product`
_Individual product or service in a procurement catalog with pricing, availability, lead time, and purchase price information_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| itemCode | string | Yes | Unique item code within catalog |
| itemName | string | Yes | Display name of the item |
| description | string | No | Detailed item description |
| basePrice | number | Yes | Base unit price |
| unit | string | Yes | Pricing unit: piece, kg, liter, hour, etc |
| minimumQuantity | number | No | Minimum order quantity |
| leadTime | number | No | Delivery lead time in days |
| status | string | Yes | active, discontinued |
| validFrom | datetime | No |  |
| validUntil | datetime | No |  |

**Relations:**
- → ProcurementCatalog (many-to-one)
- → Product (many-to-one)
- → PricingRule (one-to-many)

### ChargebackDispute
**Schema.org:** `schema:Service`
_A chargeback dispute tracking status, evidence, and resolution of payment disputes and chargebacks_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| disputeNumber | string | Yes | Unique dispute identifier |
| chargebackReference | string | Yes | Associated chargeback reference from payment processor |
| status | string | Yes | Status: filed, under-review, resolved, won, or lost |
| filedDate | datetime | Yes | Date the dispute was filed |
| resolutionDate | datetime | No | Date the dispute was resolved |
| disputeAmount | number | Yes | Amount in dispute |
| disputeReason | string | Yes | Reason for the chargeback |

**Relations:**
- → Payment (many-to-one)
- → Organization (many-to-one)
- → Document (one-to-many)
- → Person (many-to-one)

### ComplianceAssessment
**Schema.org:** `schema:QualitativeRating`
_Assessment of EU Directive 2014/24/EU compliance for procurement activities_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assessmentNumber | string | Yes | Unique assessment reference number |
| assessmentDate | datetime | Yes | Date of compliance assessment |
| complianceStatus | string | Yes | Status: compliant, non-compliant, partial, pending |
| riskLevel | string | Yes | Risk assessment: low, medium, high, critical |
| findings | array | No | List of compliance findings or violations |
| recommendedActions | string | No | Recommended corrective actions |

**Relations:**
- → PurchaseOrder (many-to-one)
- → Organization (many-to-one)
- → ComplianceRisk (one-to-many)

### ComplianceAudit
**Schema.org:** `schema:Event`
_A formal compliance audit documenting findings, risks, and remediation tracking with management letter outcomes_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| auditNumber | string | Yes | Unique audit number |
| auditType | string | Yes | Type of audit: internal, external, or regulatory |
| status | string | Yes | Audit status: planned, in-progress, completed, or draft |
| startDate | datetime | Yes | Audit start date |
| endDate | datetime | No | Audit completion date |
| scope | string | No | Audit scope and objectives |

**Relations:**
- → AuditFinding (one-to-many)
- → ManagementLetter (one-to-one)
- → Organization (many-to-one)
- → Document (one-to-many)

### ComplianceDocument
**Schema.org:** `schema:DigitalDocument`
_Audit evidence and compliance documentation (policies, procedures, attestations)_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| complianceArea | string | Yes | Compliance domain (e.g., accounting, GDPR, tax, labor) |
| category | enum | Yes | Type: policy, procedure, evidence, or attestation |
| required | boolean | Yes | Is this document mandatory |
| expiryDate | date | No | Review or validity expiration date |

**Relations:**
- → Person (many-to-one)
- → Organization (many-to-one)

### ComplianceReport
**Schema.org:** `schema:Report`
_Analytics report tracking obligation and payment compliance metrics, supporting 99% on-time settlement performance goal and PowerBI dashboards_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportPeriod | string | Yes | Reporting period (e.g., 2026-Q1 or 2026-01) |
| generatedDate | date | Yes | Date report was generated |
| complianceRate | number | Yes | Percentage of obligations settled on-time (0-100) |
| totalObligations | integer | Yes | Total obligations in period |
| onTimeObligations | integer | Yes | Obligations settled by due date |
| overdueObligations | integer | No | Obligations settled after due date |
| totalAmount | MonetaryAmount | No | Total financial value of all obligations |
| averagePaymentDays | number | No | Average days to payment after due date (negative = early) |
| powerBiUrl | string | No | URL to Power BI dashboard for this report |

**Relations:**
- → Obligation (one-to-many)
- → Payment (one-to-many)
- → SettlementDecision (one-to-many)

### ComplianceRisk
**Schema.org:** `schema:Report`
_Risk assessment for regulatory, operational, and compliance threats with mitigation tracking_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| riskName | string | Yes | Risk title |
| riskCategory | enum | Yes | Category: regulatory, operational, financial, or strategic |
| description | text | Yes | Risk description and context |
| probability | enum | Yes | Likelihood: remote, low, medium, high, or certain |
| impact | enum | Yes | Potential impact: negligible, minor, moderate, major, or critical |
| mitigations | text | No | Controls and mitigation strategies |

**Relations:**
- → Organization (many-to-one)
- → ComplianceDocument (one-to-many)

### ConsentRecord
**Schema.org:** `schema:Action`
_A record of regulatory consent (PSD2, GDPR, etc.) with renewal tracking and compliance management_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| consentNumber | string | Yes | Unique consent identifier |
| consentType | string | Yes | Type of consent: PSD2, GDPR, or other |
| status | string | Yes | Status: active, revoked, expired, or pending-renewal |
| grantedDate | datetime | Yes | Date consent was granted |
| expiryDate | datetime | No | Date consent expires |
| renewalDueDate | datetime | No | Date when renewal is due |
| scope | string | No | Scope and purpose of granted consent |

**Relations:**
- → Person (many-to-one)
- → Organization (many-to-one)
- → Document (one-to-many)

### ConsolidatedReport
**Schema.org:** `schema:Report`
_A consolidated financial report combining data from multiple organizations with automatic inter-company eliminations_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportNumber | string | Yes | Unique identifier for the consolidated report |
| reportDate | datetime | Yes | Date of the consolidated report |
| consolidationMethod | string | Yes | Method used for consolidation |
| status | string | Yes | Status (draft, finalized, published, archived) |
| eliminationsApplied | boolean | No | Whether inter-company eliminations have been applied |
| isPublished | boolean | No | Whether the consolidated report is published |

**Relations:**
- → ConsolidationGroup (many-to-one)
- → FiscalYear (many-to-one)
- → BalanceSheet (one-to-many)

### ConsolidationGroup
**Schema.org:** `schema:Organization`
_A group of organizations consolidated together for consolidated financial reporting across administrations_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the consolidation group |
| consolidationMethod | string | Yes | Method used for consolidation (full, proportional, equity) |
| status | string | Yes | Status of the consolidation group |
| parentOrganization | string | No | Parent organization identifier |
| eliminationRules | object | No | Consolidation elimination rules for inter-company transactions |

**Relations:**
- → Organization (one-to-many)
- → ConsolidatedReport (one-to-many)

### Contract
**Schema.org:** `schema:DigitalDocument`
_Legal contract document with spend tracking, approval routing, and full-text search capability_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| contractNumber | string | Yes | Unique contract reference number |
| description | string | Yes | Contract description and summary |
| contractValue | number | Yes | Total contract value in specified currency |
| currency | string | Yes | Currency code (e.g., EUR) |
| startDate | datetime | Yes | Contract start date |
| endDate | datetime | Yes | Contract end date |
| contractType | string | Yes | Type of contract (e.g., Service, Supply, Lease, Maintenance) |
| counterpartyName | string | Yes | Name of the supplier, vendor, or counterparty |
| counterpartyNumber | string | No | Supplier/customer registration or reference number |
| paymentTerms | string | Yes | Payment terms (e.g., Net 30, 2/10 Net 30) |
| invoiceFrequency | string | Yes | Billing frequency (e.g., monthly, quarterly, annual, one-time) |
| taxPercentage | number | Yes | Applicable VAT or tax percentage |
| contractDocument | file | No | Signed contract document or PDF |
| nextReviewDate | datetime | No | Date for next contract review or renewal consideration |
| vestigingsnummer | string | No | Dutch business establishment number (vestigingsnummer KvK) |
| renewalOption | boolean | No | Whether contract has automatic renewal or renewal option |
| bankAccount | string | No | Counterparty IBAN for payment processing |

**Relations:**
- → ContractParty (many-to-many)
- → ApprovalRoute (many-to-one)
- → ContractRedline (one-to-many)
- → ContractSpendRecord (one-to-many)

### ContractClause
**Schema.org:** `schema:Thing`
_Reusable clause with version control for contract assembly and updates_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Clause name or identifier |
| text | string | Yes | Full clause text and provisions |
| version | number | Yes | Clause version number |
| category | string | No | Category (Payment, Liability, Termination, IP, etc.) |
| status | string | Yes | Status (active, archived, deprecated) |
| createdDate | datetime | Yes | Date clause was created |

**Relations:**
- → ContractTemplate (many-to-one)

### ContractMilestone
**Schema.org:** `schema:Event`
_Milestone within contract lifecycle with KPI targets and performance monitoring_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the milestone |
| description | string | No | Description of milestone objectives |
| dueDate | datetime | Yes | Target completion date |
| status | string | Yes | Status (pending, in-progress, completed, at-risk, blocked) |
| kpiTarget | number | No | Target KPI or metric value |
| actualValue | number | No | Actual KPI value achieved |

**Relations:**
- → Contract (many-to-one)
- → ContractObligation (one-to-many)

### ContractModification
**Schema.org:** `schema:UpdateAction`
_Amendments, changes, and modifications to contracts with audit trail and approval_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of the modification or amendment |
| description | string | Yes | Details of what was modified |
| modificationDate | datetime | Yes | Date modification was made |
| type | string | Yes | Modification type (amendment, extension, material-change, termination-notice) |
| status | string | Yes | Status (draft, proposed, approved, rejected, executed) |
| reason | string | No | Business reason for modification |

**Relations:**
- → Contract (many-to-one)
- → Person (many-to-one)
- → DigitalDocument (many-to-one)

### ContractObligation
**Schema.org:** `schema:Action`
_Tracked obligations and deliverables within a contract with completion status_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the obligation or deliverable |
| description | string | No | Detailed description of deliverables and requirements |
| dueDate | datetime | Yes | Due date for the obligation |
| status | string | Yes | Status (pending, in-progress, completed, overdue) |
| priority | string | No | Priority (low, medium, high, critical) |
| completionDate | datetime | No | Actual completion date |

**Relations:**
- → Contract (many-to-one)
- → Person (many-to-one)
- → ContractMilestone (many-to-one)

### ContractParty
**Schema.org:** `schema:Organization`
_Organization party to a contract with banking and contact details_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Legal name of organization |
| kvkNumber | string | No | Dutch Chamber of Commerce registration number |
| vatID | string | No | VAT identification number |
| email | string | No | Organization email address |
| iban | string | No | International Bank Account Number for payments |
| role | string | No | Party role (Vendor, Service Provider, Client) |

### ContractPerformance
**Schema.org:** `schema:Thing`
_Performance metrics, KPIs, and analytics for contract monitoring and risk assessment_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| metricName | string | Yes | Name of the performance metric or KPI |
| metricValue | number | Yes | Current or actual metric value |
| targetValue | number | No | Target or baseline value |
| reportingDate | datetime | Yes | Date of the performance measurement |
| status | string | Yes | Performance status (on-track, at-risk, exceeded, failed) |
| notes | string | No | Context or analysis notes |

**Relations:**
- → Contract (many-to-one)
- → Report (many-to-one)

### ContractRedline
**Schema.org:** `schema:DigitalDocument`
_AI-powered and manual suggested changes to contract terms with risk severity_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| description | string | Yes | Description of suggested change or issue |
| originalText | string | No | Original contract text being flagged |
| suggestedText | string | No | Proposed replacement text |
| lineNumber | number | No | Line number in contract |
| aiGenerated | boolean | No | True if suggested by automated redlining system |
| severity | string | No | Risk level (Low, Medium, High, Critical) |

**Relations:**
- → Contract (many-to-one)

### ContractRenewal
**Schema.org:** `schema:Event`
_Renewal period management with proactive notification and renegotiation tracking_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| renewalDate | datetime | Yes | Date when renewal becomes effective |
| notificationDate | datetime | Yes | Date when renewal notification must be sent |
| negotiationDeadline | datetime | No | Deadline for renewal negotiations |
| status | string | Yes | Renewal status (pending, in-negotiation, approved, completed, cancelled) |
| automaticRenewal | boolean | No | Whether contract auto-renews without action |
| renewalTerms | string | No | Conditions or terms for renewal |

**Relations:**
- → Contract (many-to-one)
- → Organization (many-to-one)

### ContractSpendRecord
**Schema.org:** `schema:Order`
_Invoice and payment record for contract spend dashboard and financial tracking_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoiceNumber | string | Yes | Unique invoice identifier |
| invoiceDate | date | Yes | Date invoice was issued |
| amount | number | Yes | Invoice amount |
| currency | string | No | ISO 4217 currency code |
| paymentDate | date | No | Date payment was made |
| paymentTerms | string | No | Payment terms (e.g., Net 30) |
| description | string | No | Invoice line items and details |

**Relations:**
- → Contract (many-to-one)
- → ContractParty (many-to-one)

### ContractTemplate
**Schema.org:** `schema:CreativeWork`
_Reusable template for contract authoring with predefined structure and clause library_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the contract template |
| description | string | No | Purpose and use cases for this template |
| category | string | No | Contract type (Service, Purchase, Employment, NDA, etc.) |
| templateContent | string | Yes | Template structure and markup |
| status | string | Yes | Template status (active, archived, deprecated) |
| createdDate | datetime | Yes | Date template was created |

**Relations:**
- → ContractClause (one-to-many)
- → Organization (many-to-one)

### Corporation
**Schema.org:** `schema:Organization`
_A registered Dutch business entity (BV, NV, eenmanszaak, CV) with independent tax and legal obligations. Core entity for multi-entity management._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Official registered business name |
| tradeName | string | No | Trading name if different from legal name |
| kvkNumber | string | Yes | Dutch Chamber of Commerce (KvK) registration number |
| vatID | string | No | Dutch VAT number (BTW-nummer) |
| iban | string | No | Primary business bank account IBAN |
| businessType | string | Yes | Legal form: eenmanszaak, CV, BV, NV, CVOA, Vennootschap onder firma |
| foundationDate | date | Yes | Official business establishment date |
| dissolutionDate | date | No | Date business was closed (if applicable) |

**Relations:**
- → Shareholder (one-to-many)
- → Administration (one-to-many)
- → JointVenture (many-to-many)

### CostAllocation
**Schema.org:** `schema:Offer`
_Transaction allocating or distributing costs from one cost center to another, with version control for model changes and multi-dimensional analysis_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Description or name of the allocation |
| allocationDate | datetime | Yes | Effective date of the allocation |
| sourceAmount | number | Yes | Total amount to allocate |
| allocationPercentage | number | No | Percentage of source amount allocated |
| allocationAmount | number | No | Calculated allocated amount |
| period | string | Yes | Period type: monthly, quarterly, yearly |
| status | string | Yes | Status: draft, approved, or allocated |
| version | number | Yes | Version number for change tracking and rollback |
| description | string | No |  |

**Relations:**
- → CostCenter (many-to-one)
- → CostCenter (many-to-one)

### CostCenter
**Schema.org:** `schema:Organization`
_A cost center for tracking, allocating, and analyzing departmental or functional expenses across the organization_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Unique cost center identifier |
| name | string | Yes | Name of the cost center |
| description | string | No | Detailed description of responsibilities and scope |
| status | string | Yes | Current status: active or inactive |
| budget | number | No | Allocated annual or periodic budget |
| createdDate | datetime | Yes | Date when cost center was created |

**Relations:**
- → Person (many-to-one)
- → Organization (many-to-one)

### CostProject
**Schema.org:** `schema:Project`
_Project or cost object for tracking time, materials, and costs on a project basis with budget monitoring and multi-dimensional reporting_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Unique project cost code |
| name | string | Yes | Project name |
| description | string | No | Project description and scope |
| budget | number | No | Total project budget |
| totalCost | number | No | Total costs incurred to date |
| startDate | datetime | Yes | Project start date |
| endDate | datetime | No | Project completion or planned end date |
| status | string | Yes | Status: active, closed, or archived |

**Relations:**
- → Organization (many-to-one)
- → CostCenter (many-to-one)

### CreditNote
**Schema.org:** `schema:Invoice`
_A document issued to reduce customer debt due to returns or corrections_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| creditNoteNumber | string | Yes | Unique credit note identifier |
| creditDate | datetime | Yes | Date when credit note was issued |
| totalAmount | number | Yes | Credit amount |
| reason | string | Yes | Reason for credit (return, correction, discount) |
| status | string | Yes | Credit note status |
| notes | string | No | Additional notes |

**Relations:**
- → Invoice (many-to-one)
- → Organization (many-to-one)
- → InvoiceLine (one-to-many)

### CurrencyBalance
**Schema.org:** `schema:Thing`
_Multi-currency balance tracking per account for foreign currency management_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| balanceId | string | Yes | Unique balance record identifier |
| currency | string | Yes | Currency code (ISO 4217) |
| balance | number | Yes | Current balance amount |
| previousBalance | number | No | Previous balance for variance tracking |
| lastUpdated | datetime | Yes | Last update timestamp |

**Relations:**
- → BankAccount (many-to-one)

### DebitNote
**Schema.org:** `schema:Invoice`
_A document issued to increase vendor debt for account adjustments_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| debitNoteNumber | string | Yes | Unique debit note identifier |
| debitDate | datetime | Yes | Date when debit note was issued |
| totalAmount | number | Yes | Debit amount |
| reason | string | Yes | Reason for debit |
| status | string | Yes | Debit note status |
| notes | string | No | Additional notes |

**Relations:**
- → Payee (many-to-one)

### Deduction
**Schema.org:** `schema:PriceSpecification`
_Payroll deduction such as taxes, social security, or garnishments_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| deductionType | string | Yes | Type of deduction (tax, social_security, garnishment, insurance) |
| amount | number | Yes | Deduction amount |
| description | string | No | Deduction description |
| reason | string | No | Reason for deduction |

**Relations:**
- → Payroll (many-to-one)

### Delegation
**Schema.org:** `schema:Action`
_A delegation of mandate authority from one signing authority to another for a specified period_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| delegationNumber | string | Yes | Unique delegation identifier |
| reason | string | No | Reason for delegation (e.g., out-of-office, temporary increase, absence) |
| startDate | datetime | Yes | Start date of the delegation |
| endDate | datetime | Yes | End date of the delegation |
| status | string | Yes | Status of delegation (active/revoked/expired) |
| revokedDate | datetime | No | Date when delegation was revoked |
| revokeReason | string | No | Reason for early revocation |

**Relations:**
- → SigningAuthority (many-to-one)
- → SigningAuthority (many-to-one)
- → Mandate (many-to-one)
- → DelegationRule (many-to-one)

### DelegationRule
**Schema.org:** `schema:Action`
_Rules for delegating approval tasks during out-of-office periods and escalation scenarios_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ruleId | string | Yes | Unique delegation rule identifier |
| ruleType | string | Yes | outOfOffice/escalation/substitute |
| delegateFrom | string | Yes | Person/User ID delegating approvals |
| delegateTo | string | Yes | Person/User ID receiving delegated tasks |
| startDate | datetime | Yes | When delegation starts |
| endDate | datetime | No | When delegation ends |
| scope | string | No | allApprovals or specificChain |
| status | string | No | active or inactive |
| escalationPriority | number | No | Priority order for escalation chain (1=first, 2=fallback, etc.) |

**Relations:**
- → Person (many-to-one)
- → Person (many-to-one)

### DepreciationSchedule
**Schema.org:** `schema:Thing`
_A detailed schedule defining depreciation method, rate, and yearly calculations for a fixed asset with automated computation_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| scheduleNumber | string | Yes | Unique identifier for the depreciation schedule |
| name | string | Yes | Name or description of the depreciation schedule |
| startDate | datetime | Yes | Start date of the depreciation period |
| endDate | datetime | Yes | End date of the depreciation period |
| depreciationMethod | string | Yes | Method used: linear, declining-balance, units-of-production |
| annualRate | number | Yes | Annual depreciation rate as a percentage or amount |
| totalDepreciationAmount | number | No | Total depreciation amount over the schedule period |
| status | string | Yes | Current status: planned, active, completed |

**Relations:**
- → FixedAsset (many-to-one)

### DigitalDocument
**Schema.org:** `schema:DigitalDocument`
_Schema.org DigitalDocument — standard vocabulary for digitaldocument data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Document name/title |
| documentType | string | Yes | Document type (contract, tender, report, etc.) |
| description | string | No | Document description |
| encodingFormat | string | No | MIME type (application/pdf, etc.) |
| contentSize | string | No | File size |

### Dividend
**Schema.org:** `schema:MonetaryAmount`
_Dividend payment or distribution to shareholders_
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| amount | number | Yes | Dividend amount per share or total in EUR |
| paymentDate | datetime | Yes | Date the dividend was or will be paid |
| declarationDate | datetime | No | Date the dividend was declared |
| fiscalYear | string | No | Fiscal year for which dividend is paid |
| frequency | string | No | Annual, semi-annual, quarterly, one-time, etc. |
| status | string | Yes | Pending, paid, cancelled, etc. |

**Relations:**
- → Shareholder (many-to-one)
- → Entity (many-to-one)
- → Payment (many-to-one)

### Document
**Schema.org:** `schema:DigitalDocument`
_Managed document with version control for bookkeeping (invoices, contracts, receipts)_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Document title |
| documentType | string | Yes | Category (invoice, receipt, contract, amendment) |
| description | text | No | Document summary |
| encodingFormat | string | No | File format (PDF, DOCX, JPG) |
| contentSize | integer | No | File size in bytes |
| fileLocation | string | No | Storage path or repository URL |

**Relations:**
- → PurchaseOrder (many-to-one)
- → Person (many-to-one)

### DunningNotice
**Schema.org:** `schema:Event`
_Follow-up notice for overdue unpaid transactions, escalating through dunning levels toward legal action._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| noticeDate | date | Yes | Date when dunning notice was issued |
| dueDate | date | Yes | New payment deadline in the notice |
| reminderLevel | enum | Yes | Escalation level of dunning process |
| amount | MonetaryAmount | Yes | Outstanding amount due |
| eventStatus | enum | Yes | Status of the dunning notice |
| description | string | No | Custom message or legal terms included |

**Relations:**
- → APTransaction (many-to-one)
- → Payee (many-to-one)

### Entitlement
_Grant of access or permission to use specific features, resources, or data within the system_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Entitlement name or what is entitled |
| description | string | No | Detailed description of what is entitled |
| status | string | Yes | Entitlement status (active, pending, expired, revoked) |
| grantedAt | datetime | Yes | Date entitlement was granted |
| expiresAt | datetime | No | Date entitlement expires |

**Relations:**
- → User (many-to-one)

### Entity
**Schema.org:** `schema:Organization`
_A legal entity or business managed within a multi-entity system_
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Legal name of the entity |
| registrationNumber | string | Yes | Company registration number (KvK) |
| taxId | string | Yes | Tax identification number (VAT/BTW ID) |
| businessType | string | No | Business form (BV, NV, Eenmanszaak, etc.) |
| foundingDate | datetime | No | Date of establishment |
| country | string | No | Country of incorporation |
| status | string | Yes | Active, inactive, dissolved, etc. |

**Relations:**
- → Organization (many-to-one)
- → Person (one-to-many)

### EvaluationCriterion
**Schema.org:** `schema:Thing`
_Evaluation criteria with weights and scoring formulas documenting award methodology_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| criterionId | string | Yes | Unique criterion identifier |
| name | string | Yes | Criterion name (price, quality, delivery time, etc) |
| weight | number | Yes | Weight in total score 0-100 |
| maxScore | number | Yes | Maximum achievable score for this criterion |
| scoringFormula | string | No | Automated scoring formula or reference |
| sequenceNumber | number | No | Display order in evaluation |

### Event
**Schema.org:** `schema:Event`
_Schema.org Event — standard vocabulary for event data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Event/tender name |
| description | string | No | Description |
| startDate | datetime | Yes | Start/publication date |
| endDate | datetime | No | End/deadline date |
| eventStatus | string | Yes | Status (active, closed, cancelled) |
| maximumAttendeeCapacity | integer | No | Max participants/lots |

### ExemptionCertificate
**Schema.org:** `schema:DigitalDocument`
_Tax exemption credential (research, export, environmental, humanitarian). Stores certificate metadata, validity, and linked exemptions for workflow automation._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| certificateNumber | string | Yes | Official certificate ID from issuing authority |
| certificateType | enum | Yes | research, export, environmental, humanitarian, innovation, vat-reverse, other |
| issueDate | date | Yes | Certificate issuance date |
| expiryDate | date | No | Expiration date; null = perpetual |
| exemptionReason | string | Yes | Legal basis or reason code |
| documentURL | uri | No | Link to official document or scan |

**Relations:**
- → Organization (many-to-one)
- → TaxDeclaration (many-to-many)

### ExpenditureEscalation
**Schema.org:** `schema:Order`
_An expenditure request that exceeds the mandate ceiling and requires escalation to higher authority for approval_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| escalationNumber | string | Yes | Unique escalation identifier |
| totalAmount | number | Yes | Total expenditure amount |
| mandateLimit | number | Yes | The mandate ceiling that was exceeded |
| exceedingAmount | number | Yes | Amount by which expenditure exceeds mandate |
| reason | string | No | Justification for the expenditure above mandate |
| status | string | Yes | Status of escalation (pending/approved/rejected) |
| createdDate | datetime | Yes | Date the escalation was created |
| decisionDate | datetime | No | Date when escalation was approved or rejected |

**Relations:**
- → ApprovalRequest (many-to-one)
- → Mandate (many-to-one)
- → Person (many-to-one)

### ExpenditureRequest
_A request to spend funds from an allocated budget, requiring review and approval_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| requestNumber | string | Yes | Unique identifier for the request |
| amount | number | Yes | Requested expenditure amount |
| purpose | string | Yes | Purpose or description of the expenditure |
| status | string | Yes | Status: draft, submitted, approved, rejected, executed |
| requestDate | datetime | Yes | Date request was made |

**Relations:**
- → Budget (many-to-one)
- → ApprovalRequest (many-to-one)
- → Person (many-to-one)

### Expense
**Schema.org:** `schema:Invoice`
_Business expenditure with receipt documentation and reimbursement processing_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| expenseNumber | string | Yes | Unique expense identifier |
| expenseDate | datetime | Yes | Date when expense was incurred |
| amount | number | Yes | Expense amount |
| category | string | Yes | Expense category (travel, meals, supplies) |
| status | string | Yes | Expense status (submitted, approved, reimbursed) |
| approvalStatus | string | No | Approval workflow status |
| description | string | No | Expense description |

**Relations:**
- → Person (many-to-one)
- → Receipt (one-to-many)

### ExpenseCategory
**Schema.org:** `schema:Thing`
_A category or dimension for coding and tracking expenses, enabling multi-dimensional reporting by department, region, cost type, or other organizational structures_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Human-readable category name |
| code | string | Yes | Unique code used for automated coding and reporting |
| type | string | Yes | Category dimension: department, region, costType, project, costCenter, etc. |
| description | string | No | Description of this category |
| parentCode | string | No | Parent category code for hierarchical grouping |

**Relations:**
- → Organization (many-to-one)

### ExpenseClaim
**Schema.org:** `schema:Invoice`
_Expense claim submissions with receipt tracking, approval workflow, and reimbursement management_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| claimId | string | Yes | Unique expense claim identifier |
| submittedBy | string | Yes | Person/User ID who submitted the claim |
| totalAmount | number | Yes | Total amount claimed |
| currency | string | No | ISO 4217 currency code |
| status | string | No | draft/submitted/approved/rejected/reimbursed |
| description | string | No | Overall claim description and purpose |
| submittedDate | datetime | No | When the claim was submitted |
| approvalDueDate | datetime | No | Approval deadline |
| approvedDate | datetime | No | When the claim was approved |
| reimbursedDate | datetime | No | When reimbursement was made |
| reimbursementAmount | number | No | Final approved amount for reimbursement |
| attachments | array | No | File references for supporting receipts and documentation |

**Relations:**
- → Person (many-to-one)
- → ApprovalRequest (one-to-many)
- → Receipt (one-to-many)
- → Payment (many-to-one)

### ExpenseLineItem
**Schema.org:** `schema:Thing`
_A line item within an expense record with detailed coding for department allocation and cost center tracking_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lineNumber | string | Yes | Sequence number within the parent expense |
| amount | number | Yes | Amount for this line item |
| description | string | Yes | Description of the goods or services provided |
| department | string | No | Department code for cost allocation |
| costCenter | string | No | Cost center code for tracking and reporting |
| quantity | number | No | Quantity of items or units |

**Relations:**
- → Expense (many-to-one)
- → ExpenseCategory (many-to-one)

### ExpenseReport
**Schema.org:** `schema:Report`
_Spend and expense report by category with approval and budget tracking_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Report title |
| reportType | string | Yes | Report type: SPEND_ANALYSIS, EXPENSE_SUMMARY, BUDGET_VS_ACTUAL |
| period | string | Yes | Report period: MONTHLY, QUARTERLY, YEARLY |
| generatedAt | datetime | Yes | Report generation timestamp |
| totalAmount | number | Yes | Total spend amount |
| currency | string | Yes | Currency code (EUR) |
| expenseCategory | string | No | Primary expense category |
| approvalStatus | string | No | Approval status: DRAFT, SUBMITTED, APPROVED |
| budgetAmount | number | No | Budget amount for variance analysis |

**Relations:**
- → ProcurementOrder (many-to-many)
- → Supplier (many-to-many)

### FXExposure
**Schema.org:** `schema:MonetaryAmount`
_Track foreign exchange risk across currencies with current rates, valuations, and unrealized gains/losses_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| baseCurrency | string | Yes | EUR or company base currency |
| foreignCurrency | string | Yes | ISO 4217 code |
| exposureAmount | number | Yes | Amount in foreign currency |
| currentExchangeRate | number | Yes | Foreign/base rate |
| valuationDate | string | Yes | ISO 8601 rate snapshot date |
| unrealizedGainLoss | number | No | P&L in base currency |
| riskLevel | string | No | Low, Medium, High |

**Relations:**
- → CashAccount (many-to-one)
- → Organization (many-to-one)

### FinancialDecision
**Schema.org:** `schema:Report`
_Financial decision (approval, allocation, or payment authorization) auto-published to stakeholders_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| decisionType | string | Yes | Type: APPROVAL, ALLOCATION, DISBURSEMENT, or PAYMENT_AUTHORIZATION |
| amount | number | Yes | Financial amount in EUR |
| decisionDate | date | Yes | Date decision was made |
| approverName | string | Yes | Name of decision maker |
| approverRole | string | Yes | Role or title of decision maker |
| publicationDate | date | Yes | Date published to stakeholders |
| isAutoPublished | boolean | Yes | Whether automatically published without manual intervention |

**Relations:**
- → Organization (many-to-one)

### FinancialReport
**Schema.org:** `schema:Report`
_Exported financial statements (annual, management, or consolidated) generated for a fiscal year._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportType | string | Yes | Annual, Management, or Consolidated |
| reportFormat | string | Yes | Export format: PDF, Excel, XML, or JSON |
| reportStatus | string | No | Draft, Approved, or Published |
| generatedAt | dateTime | Yes | Timestamp of report generation |

**Relations:**
- → FiscalYear (many-to-one)

### FiscalYear
**Schema.org:** `schema:Event`
_An accounting period representing a fiscal year for financial reporting and regulatory compliance._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| year | integer | Yes | The fiscal year number (e.g., 2024) |
| startDate | date | Yes | The first day of the fiscal period |
| endDate | date | Yes | The last day of the fiscal period |
| isClosed | boolean | No | Whether the fiscal year is closed for amendments |
| closingDate | date | No | Date when the fiscal year was officially closed |

**Relations:**
- → FinancialReport (one-to-many)
- → JournalEntry (one-to-many)

### FixedAsset
**Schema.org:** `schema:Thing`
_A tangible business asset with long-term value subject to annual depreciation calculation and tracking_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assetNumber | string | Yes | Unique identifier for the fixed asset |
| name | string | Yes | Name of the fixed asset |
| assetType | string | Yes | Type of asset: equipment, vehicle, property, building, etc. |
| purchaseDate | datetime | Yes | Date when the asset was purchased |
| purchaseCost | number | Yes | Original acquisition cost of the asset |
| status | string | Yes | Current status: active, inactive, retired |
| location | string | No | Physical location of the asset |

**Relations:**
- → Organization (many-to-one)
- → DepreciationSchedule (one-to-many)

### FrameworkAgreement
**Schema.org:** `schema:Service`
_Framework agreement enabling mini-competition and direct award within procurement_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| agreementNumber | string | Yes | Unique framework agreement identifier |
| title | string | Yes | Framework agreement title |
| status | string | Yes | Status: active, expired, suspended, archived |
| awardDate | datetime | Yes | Date framework was awarded |
| expiryDate | datetime | Yes | Framework expiration date |
| minCompetitionThreshold | number | No | Minimum suppliers required for mini-competition |

**Relations:**
- → Supplier (many-to-many)
- → Contract (one-to-many)

### Freelancer
**Schema.org:** `schema:Person`
_A self-employed professional or contractor managing their own work and time_
**Primary spec:** freelancers-zzp

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| expertise | array | No | Professional expertise areas |
| hourlyRate | number | No | Default hourly billing rate |
| status | string | Yes | Freelancer status (active/inactive) |

**Relations:**
- → Person (many-to-one)
- → TimeEntry (one-to-many)
- → Assignment (one-to-many)

### FundAllocation
**Schema.org:** `schema:MonetaryAmount`
_Budget allocation and fund management for public sector spending with fiscal year tracking_
**Primary spec:** government-public-sector

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Fund or budget name |
| totalAmount | number | Yes | Total allocated amount in decimal format |
| currency | string | Yes | Currency code (EUR) |
| fiscalYear | integer | Yes | Fiscal year of allocation |
| availableAmount | number | Yes | Remaining available amount for allocation |
| allocationType | string | Yes | Type: operational, investment, grant, or subsidy |
| budgetCode | string | Yes | Government budget code reference |

**Relations:**
- → GovernmentEntity (many-to-one)
- → SpendingRecord (one-to-many)

### FundingSource
_A source of funds that can be allocated to budgets and expenditures_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the funding source |
| totalAmount | number | Yes | Total available funds |
| status | string | Yes | Status: active, inactive, depleted |
| description | string | No | Details about the funding source |

**Relations:**
- → BudgetAllocation (one-to-many)

### GeneralLedgerAccount
**Schema.org:** `schema:Product`
_A chart-of-accounts entry for tracking debits, credits, and account balances across asset, liability, equity, revenue, and expense categories._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountNumber | string | Yes | The unique account code (e.g., 1000, 4100) |
| accountName | string | Yes | The descriptive account name |
| accountType | string | Yes | Account classification: Asset, Liability, Equity, Revenue, or Expense |
| currency | string | Yes | ISO 4217 currency code for the account |
| currentBalance | object | No | Current balance as {value, currency} following MonetaryAmount schema |

**Relations:**
- → JournalEntry (one-to-many)

### GeneralLedgerEntry
**Schema.org:** `schema:Thing`
_An individual entry in the general ledger representing a financial transaction with debit and credit amounts_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| entryDate | datetime | Yes | Date of the GL entry |
| accountNumber | string | Yes | General ledger account code |
| accountName | string | Yes | Name of the GL account |
| debitAmount | number | No | Debit amount in base currency |
| creditAmount | number | No | Credit amount in base currency |
| description | string | Yes | Description of the transaction |
| reference | string | No | Reference document number or transaction ID |
| status | string | Yes | Status (draft, posted, reversed) |

**Relations:**
- → FiscalYear (many-to-one)
- → Organization (many-to-one)
- → APTransaction (many-to-one)

### GoodsReceipt
**Schema.org:** `schema:Thing`
_Receipt and verification of goods delivered at multiple locations with delivery confirmation_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| receiptNumber | string | Yes | Unique goods receipt identifier |
| receivedDate | datetime | Yes | Date goods were received |
| location | string | Yes | Physical receiving location or site |
| quantity | number | Yes | Quantity of items received |
| notes | string | No | Quality notes, damage, or discrepancies |
| signatureRequired | boolean | No | Whether signature is required for delivery |
| status | string | Yes | Receipt status (draft, received, verified, closed) |

**Relations:**
- → PurchaseOrder (many-to-one)
- → InventoryStock (many-to-many)
- → Organization (many-to-one)

### GovernmentEntity
**Schema.org:** `schema:Organization`
_Dutch government organization with GBA/BRP integration and CCH research access for public sector bookkeeping_
**Primary spec:** government-public-sector

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Official legal name of the government entity |
| kvkNumber | string | No | Dutch Chamber of Commerce registration number |
| bsnNumber | string | No | Citizen Service Number for GBA linking |
| brkNumber | string | No | Land Registry number for BRP linking |
| govLevel | string | Yes | Government level: municipality, province, national, or waterboard |
| cchAccessCode | string | No | Central Code Bank (CCH) research access identifier |
| email | string | No | Organization contact email |
| telephone | string | No | Organization contact telephone |

**Relations:**
- → FundAllocation (one-to-many)
- → SpendingRecord (one-to-many)
- → SubmissionDossier (one-to-many)

### Grant
**Schema.org:** `schema:Grant`
_A financial grant or subsidy awarded to an organization for specified purposes under a subsidy scheme_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| grantId | string | Yes | Unique grant identifier |
| name | string | Yes | Grant name |
| awardedAmount | number | Yes | Amount awarded |
| awardDate | datetime | Yes | Date grant was awarded |
| status | string | Yes | Grant status: active, completed, suspended, revoked |
| accountingStandard | string | No | Governmental accounting standard applied |
| isSISAEligible | boolean | No | Eligible for Single Information Single Audit |

**Relations:**
- → SubsidyScheme (many-to-one)
- → Organization (many-to-one)
- → GrantPortfolio (many-to-one)

### GrantPortfolio
**Schema.org:** `schema:Collection`
_A managed collection of grants for organizational tracking, compliance monitoring, and concentration risk analysis_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| portfolioId | string | Yes | Unique portfolio identifier |
| name | string | Yes | Portfolio name |
| description | string | No |  |
| totalGrantValue | number | No | Total value of all grants |
| complianceStatus | string | No | Compliance status: compliant, non-compliant, under-review |
| concentrationRiskLevel | string | No | Risk level: low, medium, high |
| lastAuditDate | datetime | No |  |

**Relations:**
- → Organization (many-to-one)
- → Grant (one-to-many)

### IntercompanyTransaction
**Schema.org:** `schema:FinancialProduct`
_Transaction between related entities for transfer pricing, loans, or intercompany netting_
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionDate | datetime | Yes | Date of the transaction |
| amount | number | Yes | Transaction amount in EUR |
| type | string | Yes | Service fee, goods transfer, loan, transfer pricing, netting, etc. |
| description | string | No | Transaction description and purpose |
| reference | string | No | Reference number or invoice number |
| interestRate | number | No | Interest rate if applicable |
| status | string | Yes | Pending, completed, settled, cancelled, etc. |

**Relations:**
- → Entity (many-to-one)
- → Entity (many-to-one)
- → APTransaction (many-to-one)

### InventoryItem
**Schema.org:** `schema:Product`
_Product tracked in inventory with stock levels and sourcing information_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Product name |
| sku | string | Yes | Stock keeping unit identifier |
| description | string | No | Detailed product description |
| category | string | Yes | Product category for spend management |
| unitPrice | number | Yes | Unit purchase price |
| currency | string | Yes | Currency code (EUR) |
| unitCode | string | No | Unit of measure (ST, KG, L, etc) |
| taxRate | number | No | Applicable VAT percentage |
| currentStock | number | Yes | Current quantity in stock |
| minimumStock | number | No | Minimum stock level for reordering |
| reorderQuantity | number | No | Standard quantity to order |
| storageLocation | string | No | Physical storage location code |

**Relations:**
- → Supplier (many-to-one)
- → ProcurementOrder (many-to-many)

### InventoryStock
**Schema.org:** `schema:Thing`
_Stock levels, inventory tracking, and reorder management by location_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| sku | string | Yes | Stock Keeping Unit identifier |
| quantity | number | Yes | Current stock quantity |
| reorderLevel | number | No | Minimum quantity threshold for reorder trigger |
| reorderQuantity | number | No | Standard reorder quantity |
| location | string | No | Physical storage location or warehouse |
| unitCost | number | No | Cost per unit |
| lastRestockDate | datetime | No | Date of last stock replenishment |
| status | string | Yes | Inventory status (active, inactive, discontinued) |

**Relations:**
- → Product (many-to-one)
- → Organization (many-to-one)

### InventoryValuation
**Schema.org:** `schema:Product`
_Valuation of on-hand inventory items using cost accounting methods such as FIFO or average cost for P&L and balance sheet reporting_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| quantity | number | Yes | Quantity of items currently in stock |
| unitCost | number | Yes | Cost per unit under the selected valuation method |
| totalValue | number | Yes | Total inventory value (quantity × unitCost) |
| valuationMethod | string | Yes | Costing method: FIFO, average, specific, or weighted average |
| date | datetime | Yes | Date of valuation or inventory count |
| warehouse | string | No | Warehouse or storage location identifier |
| status | string | Yes | Status: active, adjusted, or obsolete |

**Relations:**
- → Product (many-to-one)
- → CostCenter (many-to-one)

### Investment
**Schema.org:** `schema:FinancialProduct`
_Investment or capital contribution in an entity with terms and expected returns_
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| amount | number | Yes | Investment amount in EUR |
| investmentDate | datetime | Yes | Date the investment was made |
| investmentType | string | Yes | Equity, debt, convertible, preferred, etc. |
| expectedReturn | number | No | Expected return percentage or amount |
| maturityDate | datetime | No | Expected maturity or exit date |
| terms | string | No | Investment terms and conditions |

**Relations:**
- → Entity (many-to-one)
- → Person (many-to-one)

### Invoice
**Schema.org:** `schema:DigitalDocument`
_Financial document detailing goods/services provided and creating an obligation for payment_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoiceNumber | string | Yes | Unique invoice identifier (Dutch: factuurnummer) |
| invoiceDate | datetime | Yes | Date the invoice was issued (Dutch law requires this) |
| dueDate | datetime | Yes | Payment deadline date |
| grossAmount | number | Yes | Total amount including VAT |
| vatAmount | number | Yes | Value Added Tax amount |
| netAmount | number | Yes | Amount excluding VAT (gross - vat) |
| vatRate | number | Yes | VAT percentage (e.g., 21, 9, 6, 0 for Dutch standard rates) |
| currency | string | Yes | ISO 4217 currency code (e.g., EUR) |
| creditor | object | Yes | Issuing company (supplier/seller) |
| recipient | object | Yes | Receiving company (customer/debtor) |
| lineItems | array | Yes | Invoice line items with description, quantity, unit price, amount |
| paymentTerms | string | Yes | Payment conditions (e.g., net 30 days, prepayment) |
| documentFormat | string | Yes | File format (e.g., PDF, XML, UBL) |
| paymentMethod | string | No | Payment method (e.g., SEPA transfer, bank transfer, direct debit) |
| reference | string | No | Purchase order number or reference number |
| attachments | array | No | Supporting documents or file references (PDF, receipt, etc.) |

**Relations:**
- → Obligation (one-to-one)
- → Payment (one-to-many)

### InvoiceLine
**Schema.org:** `schema:InvoiceItem`
_A line item detailing goods or services on an invoice_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lineNumber | number | Yes | Sequential line number |
| description | string | Yes | Item description |
| quantity | number | Yes | Quantity of items |
| unitPrice | number | Yes | Price per unit |
| lineAmount | number | Yes | Total line amount before tax |
| tax | number | No | Tax on line item |
| unit | string | No | Unit of measurement |

**Relations:**
- → Invoice (many-to-one)
- → Product (many-to-one)

### JointVenture
**Schema.org:** `schema:Organization`
_Formal partnership or joint venture between multiple corporations with shared profits/losses. Enables joint venture management across the multi-entity structure._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Official legal name of the joint venture |
| kvkNumber | string | No | Chamber of Commerce registration number if formally registered |
| vatID | string | No | VAT number if applicable |
| startDate | date | Yes | Date joint venture was formed |
| endDate | date | No | Date joint venture was dissolved |
| managingPartner | string | No | Lead partner responsible for operations |
| profitDistributionMethod | string | Yes | Distribution method: equal, proportional to investment, or custom |

**Relations:**
- → Corporation (many-to-many)

### JournalEntry
_A balanced transaction record affecting two or more GL accounts (debits equal credits)._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| entryDate | datetime | Yes | Date of the journal entry |
| entryNumber | string | Yes | Unique sequential journal entry number |
| description | string | Yes | Transaction description |
| debitAmount | number | Yes | Debit amount in EUR |
| creditAmount | number | Yes | Credit amount in EUR |
| isBalanced | boolean | Yes | Whether debits equal credits |
| accountCode | string | Yes | General ledger account number |
| journalCode | string | Yes | Journal type (sales, bank, cash, general, etc.) |
| reference | string | No | External reference (invoice, check, or document number) |
| vatAmount | number | No | VAT/BTW amount (21% standard, 9% reduced, etc.) |
| departmentCode | string | No | Cost center or department code |
| memo | string | No | Additional notes or clarification |

**Relations:**
- → GeneralLedgerAccount (many-to-many)
- → FiscalYear (many-to-one)

### LiquidityForecast
**Schema.org:** `schema:Report`
_Daily/weekly/monthly cash flow projections for liquidity planning, including inflow/outflow/net position_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| period | string | Yes | Daily, Weekly, or Monthly |
| forecastDate | string | Yes | ISO 8601 generation date |
| projectionDays | integer | Yes | Days ahead to forecast |
| projectedInflow | number | Yes | Expected cash in |
| projectedOutflow | number | Yes | Expected cash out |
| netProjection | number | Yes | Inflow minus outflow |
| currency | string | Yes | ISO 4217 code |
| confidence | string | No | Low, Medium, High |

**Relations:**
- → CashAccount (many-to-one)
- → Organization (many-to-one)

### Location
**Schema.org:** `schema:Place`
_A physical or geographic location for multi-site budget allocation and tracking_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Location name |
| code | string | No | Location code or identifier |
| address | string | No | Physical address |
| region | string | No | Geographic region |

**Relations:**
- → Organization (many-to-one)
- → Budget (one-to-many)

### Lot
**Schema.org:** `schema:Product`
_Grouping of items in procurement process for evaluation and award at lot level_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lotNumber | string | Yes | Unique lot identifier |
| description | string | Yes | Description of lot contents and requirements |
| status | string | Yes | Status: draft, published, awarded, closed |
| estimatedValue | number | No | Estimated contract value in currency units |

**Relations:**
- → BidEvaluation (one-to-many)
- → AwardDecision (one-to-one)

### ManagementLetter
**Schema.org:** `schema:DigitalDocument`
_Auditor communication documenting findings and observations from annual audits_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| auditDate | date | Yes | Date of the audit |
| auditScope | string | Yes | Scope of audit (e.g., annual financial statements 2025) |
| auditorName | string | Yes | Auditing firm or auditor name |
| findings | text | No | Summary of audit findings |

**Relations:**
- → Organization (many-to-one)
- → AuditFinding (one-to-many)

### Mandate
**Schema.org:** `schema:DigitalDocument`
_Electronic authorization granting a person or organization the right to perform financial transactions on behalf of another_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| mandateNumber | string | Yes | Unique identifier for the mandate |
| mandateType | string | Yes | Type of mandate: SEPA Direct Debit, domestic transfer, signing authority, etc. |
| granteeId | string | Yes | ID of person/organization receiving authority |
| grantorId | string | Yes | ID of person/organization granting authority |
| validFrom | date | Yes | Effective date of mandate |
| validThrough | date | No | Expiration date of mandate |
| maximumAmount | decimal | No | Maximum transaction amount in base currency |
| currency | string | Yes | ISO 4217 currency code |
| scheme | string | Yes | Reference to MandateScheme |
| documentHash | string | No | Hash of supporting document for audit trail |

**Relations:**
- → MandateScheme (many-to-one)
- → MandateRequest (one-to-many)

### MandateAuditLog
**Schema.org:** `schema:Event`
_Audit log tracking all changes, delegations, approvals, and usage of a mandate for compliance and historical review_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| logEntryNumber | string | Yes | Unique log entry identifier |
| action | string | Yes | Action performed (created/modified/delegated/approved/revoked/archived/violated) |
| actionDate | datetime | Yes | Timestamp of the action |
| description | string | Yes | Human-readable description of the action |
| details | object | No | Additional metadata about the action |

**Relations:**
- → Mandate (many-to-one)
- → Person (many-to-one)

### MandateRequest
**Schema.org:** `schema:Order`
_Request to create, modify, or temporarily increase a mandate authorization_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| requestNumber | string | Yes | Unique request identifier |
| requestType | string | Yes | Type: new-mandate, increase, modify, revoke |
| relatedMandateId | string | No | Reference to existing Mandate if modifying |
| requestedAmount | decimal | No | Requested or new limit amount |
| currency | string | No | ISO 4217 currency code |
| requestedDuration | integer | No | Duration in days for temporary increases |
| reason | string | No | Business justification for request |
| submittedDate | date | Yes | Date request was submitted |
| requestStatus | string | Yes | Status: pending, approved, rejected, expired |

**Relations:**
- → Mandate (many-to-one)

### MandateScheme
**Schema.org:** `schema:Product`
_Classification and regulatory framework for different mandate types (SEPA, domestic, international)_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| schemeName | string | Yes | Name of mandate scheme: SEPA-DD, iDEAL, Domestic Transfer, etc. |
| schemeCode | string | Yes | Standardized code for the scheme |
| description | string | No | Purpose and use cases for this scheme |
| regulatoryFramework | string | No | Applicable regulation: PSD2, SEPA, national law |
| applicableCountries | string | No | Comma-separated ISO country codes |
| requiresManualApproval | boolean | Yes | Whether mandates under this scheme need approval |
| maxValidityPeriod | integer | No | Maximum validity duration in days |

### MandateViolation
**Schema.org:** `schema:Event`
_Record of a violation or breach of mandate rules, procedures, or authority limits_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| violationNumber | string | Yes | Unique violation identifier |
| violationType | string | Yes | Type of violation (exceededThreshold, unauthorizedApprover, expiredMandate, revokedAuthority) |
| description | string | Yes | Detailed description of the violation |
| severity | string | Yes | Severity level (critical/high/medium/low) |
| detectedDate | datetime | Yes | Date when violation was detected |
| status | string | Yes | Status of violation (reported/reviewed/resolved) |
| resolvedDate | datetime | No | Date when violation was resolved |
| resolution | string | No | Description of how the violation was resolved |

**Relations:**
- → Mandate (many-to-one)
- → Person (many-to-one)
- → AuditFinding (many-to-one)

### MarketplaceApp
**Schema.org:** `schema:SoftwareApplication`
_Individual application, plugin, or extension listed on marketplace with installation and rating capabilities_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| appId | string | Yes | Unique app identifier |
| name | string | Yes | Application name |
| version | string | Yes | Current application version |
| description | string | Yes | Application description and features |
| category | string | Yes | Category: billing, communication, integration, etc |
| status | string | Yes | Availability status |
| installationUrl | string | No | URL for app installation or documentation |
| ratingScore | number | No | Average user rating 0-5 |
| downloadCount | number | No | Total installations or downloads |

**Relations:**
- → MarketplaceIntegration (many-to-one)
- → Organization (many-to-one)
- → Person (many-to-one)

### MarketplaceIntegration
**Schema.org:** `schema:Service`
_Integration with external marketplaces providing unified catalog access and search across suppliers, apps, and platforms_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| integrationId | string | Yes | Unique integration identifier |
| name | string | Yes | Marketplace platform name |
| type | string | Yes | Integration type: supplier, app, extension, or external |
| url | string | Yes | Marketplace API or access URL |
| status | string | Yes | Active status |
| apiKey | string | No | Encrypted API authentication credential |
| lastSyncDate | datetime | No | Last successful catalog synchronization |
| catalogItemCount | number | No | Count of items in synchronized catalog |

**Relations:**
- → Organization (many-to-one)
- → MarketplaceApp (one-to-many)
- → Offer (one-to-many)

### MaverickSpendAlert
**Schema.org:** `schema:Event`
_Alert for unauthorized, off-contract, or non-compliant departmental spending requiring escalation_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| alertDate | date | Yes | Date alert was triggered |
| departmentName | string | Yes | Department responsible for spend |
| vendorName | string | Yes | Vendor/supplier involved |
| spendAmount | MonetaryAmount | Yes | Amount of unauthorized spend |
| severity | enum | Yes | low, medium, or high |
| alertReason | string | Yes | Why flagged (no PO, off-contract, policy violation, etc.) |
| budgetCode | string | No | Associated budget/cost center code |
| resolvedDate | date | No | Date alert was resolved/remediated |
| resolutionNotes | string | No | How violation was addressed |
| departmentAcknowledged | boolean | No | Department confirmed receipt of alert |

**Relations:**
- → ProcurementComplianceReport (many-to-one)

### MonetaryAmount
**Schema.org:** `schema:MonetaryAmount`
_Schema.org MonetaryAmount — standard vocabulary for monetaryamount data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| value | number | Yes | Numeric value |
| currency | string | Yes | ISO 4217 currency code |

### OAuthIntegration
**Schema.org:** `schema:Thing`
_OAuth 2.0 authentication configuration enabling secure partner integrations and platform access_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| integrationId | string | Yes | Unique OAuth integration identifier |
| name | string | Yes | Integration display name |
| clientId | string | Yes | OAuth client identifier |
| status | string | Yes | Active status |
| scope | string | Yes | OAuth scopes (space-separated) |
| redirectUri | string | Yes | Authorization callback URL |
| createdDate | datetime | Yes | Integration creation date |
| lastUsedDate | datetime | No | Last authentication attempt |
| expiresAt | datetime | No | Token or credential expiration date |

**Relations:**
- → Organization (many-to-one)
- → Person (many-to-one)

### Obligation
**Schema.org:** `schema:Order`
_A financial commitment that must be fulfilled by a specific due date, with tracking for AI task automation and compliance reporting_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| obligationNumber | string | Yes | Unique reference number for the obligation |
| obligationDate | date | Yes | Date the obligation was created |
| dueDate | date | Yes | Date by which the obligation must be settled |
| amount | MonetaryAmount | Yes | Financial amount owed |
| creditor | Organization | Yes | Organization to whom the obligation is owed |
| obligationType | string | No | Type of obligation (invoice, contract, standing order) |
| description | string | No | Details or reason for the obligation |
| settledOnTime | boolean | No | Whether obligation was settled by due date |

**Relations:**
- → Invoice (many-to-one)
- → Payment (one-to-many)
- → SettlementDecision (many-to-one)

### ObligationSettlement
**Schema.org:** `schema:Thing`
_A formal decision record to settle and finalize an obligation, including verification of completion and approval of final amounts_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| settlementNumber | string | Yes | Unique identifier for the settlement decision |
| settlementDate | datetime | Yes | Date when the settlement was finalized |
| settledAmount | number | Yes | Final amount settled |
| status | string | Yes | Current status: draft, approved, finalized |
| settlementType | string | No | Type of settlement: full, partial, amended |
| notes | string | No | Additional notes or remarks about the settlement |

**Relations:**
- → Obligation (many-to-one)
- → ApprovalRequest (many-to-one)

### ObligationTask
**Schema.org:** `schema:Task`
_An automated task for managing obligation lifecycle, including AI-generated deadline tracking and compliance monitoring_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taskNumber | string | Yes | Unique identifier for the task |
| title | string | Yes | Title of the task |
| description | string | No | Detailed description of the task |
| dueDate | datetime | Yes | Calculated or assigned due date with deadline tracking |
| priority | string | No | Priority level: low, medium, high |
| status | string | Yes | Current status: open, in-progress, completed |
| aiGenerated | boolean | No | Indicates if the task was automatically generated by AI |

**Relations:**
- → Obligation (many-to-one)
- → Person (many-to-one)

### Offer
**Schema.org:** `schema:Offer`
_Schema.org Offer — standard vocabulary for offer data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Offer/quote name |
| price | number | Yes | Offered price |
| priceCurrency | string | Yes | Currency |
| validFrom | datetime | No | Offer valid from |
| validThrough | datetime | No | Offer valid until |
| availability | string | No | Availability status |

### Order
**Schema.org:** `schema:Order`
_Schema.org Order — standard vocabulary for order data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| orderNumber | string | Yes | Purchase order number |
| orderDate | datetime | Yes | Date of order |
| orderStatus | string | Yes | Order status |
| totalPrice | number | Yes | Total order amount |
| currency | string | Yes | ISO 4217 currency code |
| deliveryDate | datetime | No | Expected delivery date |
| paymentTerms | string | No | Payment terms (e.g., NET30) |

### Organization
**Schema.org:** `schema:Organization`
_Schema.org Organization — standard vocabulary for organization data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Legal name of the organization |
| tradeName | string | No | Trade/brand name |
| kvkNumber | string | No | Dutch Chamber of Commerce number |
| vatID | string | No | VAT identification number |
| email | string | No | Primary email address |
| telephone | string | No | Primary phone number |
| url | string | No | Website URL |
| iban | string | No | IBAN bank account number |

### Payee
**Schema.org:** `schema:Organization`
_Vendor (accounts payable) or customer (accounts receivable) party in financial transactions._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Legal registered business name |
| tradeName | string | No | Trade name or DBA if different from legal name |
| vatID | string | Yes | Dutch VAT identification number |
| kvkNumber | string | No | KvK (Chamber of Commerce) registration number |
| email | string | Yes | Contact email address |
| telephone | string | No | Contact telephone number |
| iban | string | No | International Bank Account Number for transfers |
| bic | string | No | BIC/SWIFT code for international transactions |

**Relations:**
- → APTransaction (one-to-many)
- → DunningNotice (one-to-many)

### Payment
**Schema.org:** `schema:Order`
_Record of payment made against accounts payable or receivable transaction._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| paymentDate | date | Yes | Date when payment was made |
| amount | MonetaryAmount | Yes | Payment amount |
| paymentMethod | enum | Yes | Payment method used |
| reference | string | No | Bank transaction ID or payment reference number |
| paymentStatus | enum | Yes | Current payment status |
| description | string | No | Payment notes or reconciliation details |

**Relations:**
- → APTransaction (many-to-one)

### PaymentBatch
**Schema.org:** `schema:Payment`
_Batch grouping of multiple payments for mass processing, approval, and scheduled execution_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| batchNumber | string | Yes | Unique batch identifier |
| totalAmount | number | Yes | Sum of all payments in batch |
| totalPayments | number | Yes | Count of payments in batch |
| status | string | Yes | Status: pending, processing, completed, failed |
| approvalStatus | string | Yes | Approval status: pending, approved, rejected |
| scheduledDate | datetime | No | Scheduled execution date for batch |
| createdDate | datetime | Yes | Date batch was created |

**Relations:**
- → Organization (many-to-one)
- → Payment (one-to-many)

### PaymentFraudAssessment
**Schema.org:** `schema:Report`
_Fraud risk assessment using payment intelligence and behavioral pattern analysis_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assessmentId | string | Yes | Unique assessment identifier |
| fraudRiskScore | decimal | Yes | Fraud risk probability (0.0-1.0) |
| reportType | string | Yes | Always: payment-fraud-assessment |
| generatedAt | datetime | Yes | Assessment generation timestamp |
| riskFactors | array | No | List of detected risk indicators (JSON array) |
| riskLevel | string | Yes | Risk level: low, medium, high, critical |
| anomalyDetected | boolean | Yes | Behavioral anomaly detected |
| confidenceScore | decimal | Yes | Assessment confidence (0.0-1.0) |

**Relations:**
- → Transaction (many-to-one)
- → Organization (many-to-one)
- → BankAccount (many-to-one)

### PaymentRiskScore
**Schema.org:** `schema:Thing`
_Fraud risk assessment and intelligence scoring for payment transactions_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| riskLevel | string | Yes | low, medium, high, critical |
| score | number | Yes | 0-100, higher = more risk |
| riskFactors | array | No | velocity, amount, patterns, etc |
| fraudIndicators | array | No |  |
| assessmentDate | datetime | Yes |  |
| notes | string | No |  |

**Relations:**
- → Payment (many-to-one)
- → Person (many-to-one)

### Payroll
**Schema.org:** `schema:Invoice`
_Payroll record for wage, salary, and deduction processing_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| payrollNumber | string | Yes | Unique payroll identifier |
| payrollDate | datetime | Yes | Payroll payment date |
| period | string | Yes | Payroll period (e.g., Jan 2026) |
| grossAmount | number | Yes | Gross salary amount |
| netAmount | number | Yes | Net amount after deductions |
| totalAmount | number | Yes | Total payroll amount |
| status | string | Yes | Payroll status (draft, approved, processed) |

**Relations:**
- → Person (many-to-one)
- → Deduction (one-to-many)

### PeppolAccessPoint
**Schema.org:** `schema:Service`
_Peppol Access Point providing gateway services for e-invoicing and document exchange_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accessPointId | string | Yes | Unique access point identifier |
| name | string | Yes | Access point name or provider |
| endpoint | string | Yes | API endpoint URL for document submission |
| protocol | string | Yes | Communication protocol (AS4, AS2, SFTP, HTTP) |
| documentTypes | array | No | Supported document types (Invoice, Order, Despatch Advice, etc.) |
| supportContact | string | No | Support contact email or phone |
| status | string | Yes | Access point status (active, inactive, testing, deprecated) |

**Relations:**
- → Organization (many-to-one)
- → PeppolParticipant (many-to-one)

### PeppolParticipant
**Schema.org:** `schema:Thing`
_Peppol network participant identifier registration for e-invoicing and EDI communication_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| participantId | string | Yes | Unique Peppol participant identifier |
| scheme | string | Yes | Identifier scheme (GLN, VAT, DUNS, etc.) |
| organizationName | string | Yes | Legal organization name |
| country | string | No | Country code (ISO 3166-1 alpha-2) |
| registeredDate | datetime | No | Date of Peppol network registration |
| expiryDate | datetime | No | Peppol registration expiry date |
| status | string | Yes | Participant status (active, inactive, pending, revoked) |

**Relations:**
- → Organization (many-to-one)

### PerDiem
**Schema.org:** `schema:Offer`
_Daily allowance for employees on company travel, calculated based on country-specific rates, nights away, and configurable per diem policies_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| date | datetime | Yes | Date for which per diem is claimed |
| country | string | Yes | Country where travel occurred |
| nights | number | No | Number of nights away from home base |
| rate | number | Yes | Per diem rate applicable for the country/date |
| amount | number | Yes | Total per diem allowance amount |
| status | string | Yes | Status: draft, approved, or paid |
| approvedDate | datetime | No | Date when per diem was approved |
| description | string | No | Travel purpose or notes |

**Relations:**
- → Person (many-to-one)
- → CostCenter (many-to-one)

### PerformanceImprovementAction
**Schema.org:** `schema:Action`
_Action plan for addressing performance gaps and improving supplier performance against metrics and SLAs_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| actionId | string | Yes | Unique action identifier |
| description | string | Yes | Description of the improvement action |
| targetCompletionDate | datetime | Yes | Target completion date |
| owner | string | Yes | Person or role responsible for action |
| expectedImpact | string | No | Expected improvement or benefit |
| priority | string | Yes | Priority level (high, medium, low) |
| status | string | Yes | Status (planned, in_progress, completed, cancelled) |
| createdDate | datetime | No | Date action was created |

**Relations:**
- → Organization (many-to-one)
- → SupplierPerformanceScorecard (many-to-one)

### PerformanceScore
**Schema.org:** `schema:Rating`
_Individual KPI score recorded for a supplier within a scorecard evaluation period_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| scoreId | string | Yes | Unique score identifier |
| achievedValue | number | Yes | Actual measured value achieved |
| targetValue | number | No | Target value for comparison |
| scoredDate | datetime | Yes | Date when score was recorded |
| notes | string | No | Additional notes or observations |
| status | string | Yes | Score status (recorded, reviewed, approved) |

**Relations:**
- → SupplierPerformanceScorecard (many-to-one)
- → SupplierKPI (many-to-one)

### Permission
_Granular access permission for a specific resource and action_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Unique permission name |
| description | string | No | Detailed permission description |
| resource | string | Yes | Resource this permission applies to (e.g., users, documents, fields) |
| action | string | Yes | Action allowed (read, write, delete, approve) |
| isActive | boolean | Yes | Whether the permission is active |

**Relations:**
- → Role (many-to-many)

### Person
**Schema.org:** `schema:Person`
_Schema.org Person — standard vocabulary for person data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| givenName | string | Yes | First name |
| familyName | string | Yes | Last name |
| email | string | No | Email address |
| telephone | string | No | Phone number |
| jobTitle | string | No | Job title/role |

### PolicyRule
**Schema.org:** `schema:Thing`
_A spending policy rule that defines constraints, approval requirements, and limits for expense compliance enforcement_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the policy rule |
| description | string | No | Detailed description of what the rule enforces |
| thresholdAmount | number | No | Amount threshold that triggers the policy rule |
| ruleType | string | Yes | Type of rule: approval, limit, travel, delegation, etc. |
| isActive | boolean | Yes | Whether the rule is currently enforced |
| priority | number | No | Evaluation priority when multiple rules apply |

**Relations:**
- → Organization (many-to-one)
- → ExpenseCategory (many-to-one)
- → PolicyViolation (one-to-many)

### PolicyViolation
**Schema.org:** `schema:Thing`
_A detected violation or breach of a spending policy rule that requires attention and resolution_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| violationDate | datetime | Yes | Date when the violation was detected |
| severity | string | Yes | Severity level: low, medium, high, critical |
| description | string | Yes | Description of the specific policy violation |
| amount | number | No | The amount that exceeded or violated the policy threshold |
| status | string | Yes | Status: open, acknowledged, resolved, escalated |

**Relations:**
- → PolicyRule (many-to-one)
- → Expense (many-to-one)
- → Person (many-to-one)

### PricingRule
**Schema.org:** `schema:PriceSpecification`
_Volume discounts, tiered pricing, bundle discounts, and promotional pricing rules with validity periods and application priorities_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ruleCode | string | Yes | Unique pricing rule identifier |
| description | string | No | Rule description and conditions |
| ruleType | string | Yes | volumeDiscount, tierPricing, bundleDiscount, periodDiscount |
| minQuantity | number | No | Minimum quantity for rule application |
| maxQuantity | number | No | Maximum quantity for rule application |
| discountPercentage | number | No | Percentage discount (0-100) |
| discountAmount | number | No | Fixed discount amount in base currency |
| priority | number | No | Priority order for rule application |
| validFrom | datetime | No |  |
| validUntil | datetime | No |  |

**Relations:**
- → CatalogItem (many-to-one)

### ProcurementAuditLog
**Schema.org:** `schema:Action`
_Immutable audit trail recording all procurement actions, approvals, rejections, and changes for transparency, compliance, and decision accountability_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| auditId | string | Yes | Unique audit log entry identifier |
| entityType | string | Yes | Entity type: requisition, purchaseOrder, invoice, payment, approval |
| entityId | string | Yes | ID of the entity being audited |
| actionType | string | Yes | created, updated, approved, rejected, posted, received |
| timestamp | datetime | Yes | When the action occurred |
| reason | string | No | Reason or comment for the action |
| changes | object | No | Changed fields with old and new values |
| referenceDocuments | array | No | Related document identifiers |

**Relations:**
- → Person (many-to-one)
- → Organization (many-to-one)

### ProcurementCatalog
**Schema.org:** `schema:Catalog`
_Master catalog of products and services available for organizational procurement with support for multiple formats (cXML, CIF, internal)_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| catalogNumber | string | Yes | Unique catalog identifier |
| catalogName | string | Yes | Display name of the catalog |
| description | string | No | Catalog description and scope |
| catalogFormat | string | No | Format type: internal, cxml, cif |
| status | string | Yes | draft, active, archived |
| validFrom | datetime | No | Catalog effective start date |
| validUntil | datetime | No | Catalog expiration date |

**Relations:**
- → Organization (many-to-one)
- → CatalogItem (one-to-many)

### ProcurementCategory
**Schema.org:** `schema:Thing`
_Strategic procurement category with sourcing plans and market intelligence for supplier management and spend analysis_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Unique category code |
| name | string | Yes | Category name |
| sourcingStrategy | string | No | Strategic sourcing approach and policy |
| marketIntelligence | object | No | Market data, price trends, and competitive intelligence |
| status | string | Yes | Category status (active, inactive, archived) |

**Relations:**
- → Product (one-to-many)
- → Organization (many-to-one)

### ProcurementComplianceReport
**Schema.org:** `schema:Report`
_Organization-wide procurement compliance dashboard/aggregation per period_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportPeriod | string | Yes | Period identifier (e.g., 2026-Q1, monthly) |
| startDate | date | Yes | Period start date |
| endDate | date | Yes | Period end date |
| totalProcurementValue | MonetaryAmount | Yes | Sum of all orders in period |
| publicProcurementValue | MonetaryAmount | Yes | Value subject to public procurement rules |
| totalOrderCount | number | Yes | Total orders placed in period |
| complianceScore | number | Yes | Percentage compliance (0-100) |
| violationCount | number | No | Number of detected compliance violations |
| maverickSpendCount | number | No | Count of unauthorized/off-contract spend alerts |
| missingProofOfDelivery | number | No | Orders lacking delivery proof submission |
| expiredQualifications | number | No | Vendors with expired UEA declarations |

**Relations:**
- → MaverickSpendAlert (one-to-many)

### ProcurementOrder
**Schema.org:** `schema:Order`
_Procurement order with compliance tracking for Dutch public procurement rules (BBI, threshold checking)_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vendorName | string | Yes | Supplier/vendor name |
| vendorKvk | string | No | Dutch business registration number (KVK) |
| vendorVatID | string | No | EU VAT identification number |
| isPublicProcurement | boolean | Yes | Subject to public procurement rules (BBI threshold €15k) |
| procurementCategory | enum | Yes | supplies, services, works, or combined |
| estimatedValue | MonetaryAmount | Yes | Estimated order value for threshold compliance |
| deliveryDate | date | Yes | Expected delivery/completion date |
| paymentTerms | string | No | Payment conditions (e.g., net 30) |
| requiresProofOfDelivery | boolean | No | Portal submission of delivery proof required |

**Relations:**
- → ProofOfDelivery (one-to-many)
- → QualificationDeclaration (one-to-many)

### ProcurementProcedure
**Schema.org:** `ProcurementProcedure`
_Procurement procedure type defining governance rules and compliance requirements_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| procedureName | string | Yes | Name of the procurement procedure |
| procedureType | string | Yes | Procedure type: open, restricted, negotiated, below-threshold |
| estimatedValue | number | Yes | Estimated contract value in EUR |
| euThreshold | number | Yes | EU threshold value that determines procedure type |
| requiresEUCompliance | boolean | Yes | Whether EU Directive 2014/24/EU applies |
| status | string | Yes | Status: draft, active, completed, cancelled |

**Relations:**
- → PurchaseOrder (one-to-many)
- → Organization (many-to-one)

### ProcurementQuote
**Schema.org:** `schema:Offer`
_Supplier quote for goods or services with validity period_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Quote title or reference |
| quoteNumber | string | Yes | Unique quote identifier |
| quoteDate | date | Yes | Date quote was issued |
| validFrom | date | Yes | Quote validity start date |
| validThrough | date | Yes | Quote validity end date |
| totalPrice | number | Yes | Total quote amount |
| currency | string | Yes | Currency code (EUR) |
| deliveryTime | string | No | Estimated delivery timeframe |

**Relations:**
- → Supplier (many-to-one)
- → InventoryItem (many-to-many)

### Product
**Schema.org:** `schema:Product`
_Schema.org Product — standard vocabulary for product data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Product name |
| sku | string | No | Stock keeping unit |
| description | string | No | Product description |
| category | string | No | Product category |
| unitPrice | number | Yes | Unit price |
| currency | string | Yes | ISO 4217 currency code |
| unitCode | string | No | Unit of measure (UN/CEFACT) |
| taxRate | number | No | Applicable tax rate percentage |

### Project
**Schema.org:** `schema:Project`
_Project container for organizing tasks, milestones, and team collaboration with resource and timeline management_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| projectId | string | Yes | Unique project identifier |
| name | string | Yes | Project name |
| description | string | No | Project description and objectives |
| status | string | No | active/inactive/completed/onHold |
| owner | string | No | Person/User ID who owns the project |
| startDate | datetime | No | Project start date |
| endDate | datetime | No | Planned end date |
| budget | number | No | Project budget in base currency |

**Relations:**
- → ProjectTask (one-to-many)
- → Milestone (one-to-many)
- → Person (many-to-one)
- → Organization (many-to-one)

### ProjectTask
**Schema.org:** `schema:Action`
_Tasks within a project with hierarchy support, time estimation, and status tracking_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taskId | string | Yes | Unique task identifier |
| projectId | string | Yes | Parent project ID |
| title | string | Yes | Task title |
| description | string | No | Task description and acceptance criteria |
| parentTaskId | string | No | Parent task ID for nested subtasks |
| assignedTo | string | No | Person/User ID assigned to this task |
| status | string | No | new/inProgress/completed/blocked/onHold |
| priority | string | No | high/medium/low |
| estimatedHours | number | No | Estimated hours to complete |
| actualHours | number | No | Actual hours spent |
| dueDate | datetime | No | Task due date |
| completedDate | datetime | No | Actual completion date |

**Relations:**
- → Project (many-to-one)
- → ProjectTask (many-to-one)
- → Person (many-to-one)
- → TimeEntry (one-to-many)

### ProofOfDelivery
**Schema.org:** `schema:DigitalDocument`
_Portal submission documenting goods/services received per order with receiver verification_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| deliveryDate | date | Yes | Date goods/services were received |
| receivingDepartment | string | Yes | Organizational department that received delivery |
| goodsDescription | string | Yes | Description of what was delivered |
| quantity | number | No | Quantity of items delivered |
| unitOfMeasure | string | No | Unit (pieces, kg, hours, etc.) |
| conditionNotes | string | No | Assessment of delivered condition/quality |
| verifiedByName | string | Yes | Name of person verifying receipt |
| verifiedByJobTitle | string | No | Role/title of verifying person |
| submissionDate | date | Yes | Date proof submitted via portal |

**Relations:**
- → ProcurementOrder (many-to-one)

### Property
**Schema.org:** `schema:Place`
_Real estate property subject to assessment, valuation, and interactive mapping_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| address | string | Yes | Street address |
| city | string | Yes |  |
| province | string | Yes |  |
| latitude | number | Yes | Latitude for mapping |
| longitude | number | Yes | Longitude for mapping |
| propertyType | string | Yes | residential, commercial, industrial, or mixed |
| acquisitionValue | number | No |  |
| currentValue | number | No |  |

**Relations:**
- → Organization (many-to-one)
- → Person (many-to-one)
- → PropertyAssessment (one-to-many)
- → WOZAssessment (one-to-many)

### PropertyAssessment
**Schema.org:** `schema:Assessment`
_Assessment scoring a property against defined weighted criteria_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assessmentDate | datetime | Yes |  |
| totalScore | number | Yes | Score 0-100 |
| status | string | Yes | draft, in-progress, completed, rejected |
| completionDate | datetime | No |  |
| notes | string | No |  |

**Relations:**
- → Property (many-to-one)
- → Person (many-to-one)
- → AssessmentCriteria (many-to-many)

### PublicProcurement
**Schema.org:** `schema:Service`
_European public procurement announcement for TED/OJEU publication with tender documents and timelines_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| procurementId | string | Yes | Unique procurement identifier |
| title | string | Yes | Procurement announcement title |
| description | string | Yes | Detailed procurement description |
| status | string | Yes | Publication status |
| publicationDate | datetime | No | Actual TED/OJEU publication date |
| dueDate | datetime | Yes | Tender submission deadline |
| publishingAuthority | string | Yes | Organization publishing the procurement |
| tedReference | string | No | TED publication reference number |
| procurementType | string | Yes | Type: goods, services, or works |
| estimatedBudget | number | No | Estimated contract value |

**Relations:**
- → Organization (many-to-one)
- → Document (one-to-many)
- → PublicationAmendment (one-to-many)
- → DigitalDocument (many-to-one)

### PublicationAmendment
**Schema.org:** `schema:Thing`
_Material or minor changes to published procurement announcements requiring re-publication to TED/OJEU_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| amendmentId | string | Yes | Unique amendment identifier |
| publicationId | string | Yes | Reference to PublicProcurement being amended |
| changeType | string | Yes | Classification: material or minor change |
| description | string | Yes | Details of the amendment |
| amendmentDate | datetime | Yes | When amendment was flagged |
| status | string | Yes | Processing status |
| reason | string | No | Reason for amendment |

**Relations:**
- → PublicProcurement (many-to-one)
- → DigitalDocument (many-to-one)

### PublicationLog
**Schema.org:** `schema:Event`
_Audit trail recording publication events including creation, updates, downloads and external platform notifications_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| logId | string | Yes | Unique log entry identifier |
| publicationId | string | Yes | Reference to related publication entity |
| logType | string | Yes | Event type: created, published, amended, downloaded, notified, or error |
| timestamp | datetime | Yes | When event occurred |
| details | object | No | Additional event details as key-value pairs |
| ipAddress | string | No | Source IP address of action |
| userAgent | string | No | Client user agent string |
| description | string | No | Human-readable log entry description |

**Relations:**
- → DigitalDocument (many-to-one)
- → Person (many-to-one)
- → Organization (many-to-one)

### PublicationNotice
**Schema.org:** `schema:Thing`
_A notice published to external procurement channels (TenderNed, TED) including tender publication, award notices, corrigenda, and DPS notices_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| noticeId | string | Yes | Unique identifier for the publication notice |
| noticeType | string | Yes | Type: tender, award, corrigendum, dps_admission |
| publicationChannel | string | Yes | Channel where notice is published: TenderNed, TED, or both |
| externalNoticeId | string | No | ID assigned by external system (TenderNed or TED reference number) |
| status | string | Yes | Status: draft, submitted, published, failed, withdrawn |
| publishedDate | datetime | No | Date the notice was published |
| submissionDate | datetime | No | Date the notice was submitted for publication |
| isAboveThreshold | boolean | No | Whether this is an above-threshold EU notice |
| errorMessage | string | No | Error message if publication failed |

**Relations:**
- → Tender (many-to-one)
- → DigitalDocument (one-to-many)

### PurchaseOrder
**Schema.org:** `schema:Order`
_Purchase order with approval tracking for Dutch bookkeeping workflow_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| orderNumber | string | Yes | Unique purchase order number for identification and reference |
| orderDate | datetime | Yes | Date when the purchase order was created |
| totalPrice | number | Yes | Total price including tax and shipping |
| currency | string | Yes | Currency code (e.g., EUR, USD) |
| taxAmount | number | Yes | Total tax amount for the purchase order |
| paymentTerms | string | No | Payment terms (e.g., net 30, net 60) |
| deliveryDate | datetime | Yes | Expected delivery date |
| vendorName | string | Yes | Name of the vendor/supplier |
| vendorKvk | string | Yes | Dutch KvK (Chamber of Commerce) registration number |
| lineItems | array | Yes | Array of ordered items with quantity, unit price, and description |
| internalReference | string | No | Internal reference number or cost center code |
| deliveryAddress | object | Yes | Delivery address with street, city, postal code, and country |
| discountAmount | number | No | Discount amount applied to the order |
| shippingCost | number | No | Shipping or delivery cost |
| vendorEmail | string | No | Email address of the vendor contact |
| invoiceReference | string | No | Reference to the linked invoice number |
| departmentCode | string | No | Department or cost center code for cost allocation |
| description | string | No | General description or purpose of the purchase order |

**Relations:**
- → PurchaseOrderRevision (one-to-many)
- → ApprovalRequest (one-to-many)
- → Product (many-to-many)

### PurchaseOrderChange
**Schema.org:** `schema:Order`
_Purchase order amendment with full version tracking and change audit trail_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| changeNumber | string | Yes | Unique change order identifier |
| changeDate | date | Yes | Date change was requested |
| originalPoNumber | string | Yes | Original PO reference |
| versionNumber | integer | Yes | PO version (e.g., 1, 2, 3) |
| changedFields | text | Yes | JSON: {field: oldValue → newValue} for audit purposes |
| changeReason | text | Yes | Business reason for change |

**Relations:**
- → Organization (many-to-one)
- → Product (many-to-many)

### PurchaseOrderRevision
**Schema.org:** `schema:DigitalDocument`
_Tracks PO revisions and amendments with change history and version control_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| revisionNumber | integer | Yes | Sequential revision number |
| revisedAt | datetime | Yes | Revision timestamp |
| changeDescription | text | Yes | Detailed description of changes |
| amendmentReason | string | No | Reason for amendment (price, quantity, scope) |
| documentType | string | Yes | Document type (revision|amendment) |
| encodingFormat | string | No | File format (PDF, DOCX) |
| contentSize | integer | No | File size in bytes |

**Relations:**
- → PurchaseOrder (many-to-one)

### PurchaseRequisition
**Schema.org:** `schema:Order`
_A formal request for goods or services with multiple line items and custom fields, supporting multi-location and multi-entity procurement workflows_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| requisitionNumber | string | Yes | Unique requisition identifier |
| requisitionDate | datetime | Yes | Date requisition was created |
| status | string | Yes | draft, submitted, approved, rejected, ordered |
| purpose | string | No | Purpose or business justification |
| deliveryDate | datetime | No | Requested delivery date |
| customFields | object | No | Custom fields for procurement-specific data |
| totalAmount | number | No | Estimated total value |

**Relations:**
- → Person (many-to-one)
- → Organization (many-to-one)
- → ApprovalRequest (one-to-many)

### QualificationDeclaration
**Schema.org:** `schema:DigitalDocument`
_UEA (Uniforme Europese Aanbestedingsdocument) self-certification by vendor for procurement qualification_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vendorName | string | Yes | Declaring organization/vendor name |
| vendorKvk | string | Yes | Dutch KVK registration of vendor |
| declarationDate | date | Yes | Date of UEA self-declaration submission |
| validFrom | date | Yes | Declaration validity start date |
| validUntil | date | Yes | Declaration expiry date |
| declarationStatus | enum | Yes | submitted, accepted, rejected, or expired |
| excludedFromProcurement | boolean | No | Vendor exclusion grounds present (bankruptcy, criminal record, etc.) |
| professionalLicenses | string | No | Relevant professional certifications held |
| economicOperatorRegister | string | No | Registration in EPER or similar EU register |
| declarationNotes | string | No | Additional compliance statements |

### QualityManagementSystem
**Schema.org:** `Thing`
_A quality management system defining procedures, controls, and certifications for organizational quality assurance_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| qmsNumber | string | Yes | Unique QMS identifier |
| qmsName | string | Yes | Name or title of the QMS |
| version | string | No | Current version number |
| status | string | Yes | Status: active, inactive, or under-review |
| effectiveDate | datetime | Yes | Date the QMS became effective |
| scope | string | No | Scope of the quality management system |
| certifications | array | No | List of certifications (ISO 9001, etc.) |

**Relations:**
- → Organization (many-to-one)
- → Document (one-to-many)
- → ComplianceAudit (one-to-many)

### Quote
**Schema.org:** `schema:Offer`
_Supplier response to tender with pricing and terms_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| quoteNumber | string | Yes | Unique quote identifier |
| price | number | Yes | Total quoted price (in cents) |
| priceCurrency | string | Yes | Currency (EUR) |
| validFrom | date | Yes | Quote valid-from date |
| validThrough | date | Yes | Quote expiration date |
| paymentTerms | string | No | Payment terms (Net30, etc.) |

**Relations:**
- → Tender (many-to-one)
- → Supplier (many-to-one)

### RateCard
**Schema.org:** `schema:Thing`
_Supplier rate and pricing structure matching contract terms with volume discounts and payment terms_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| rateCardId | string | Yes | Unique rate card identifier |
| rateCardName | string | Yes | Name or title of the rate card |
| effectiveDate | datetime | Yes | Date rate card becomes effective |
| expiryDate | datetime | No | Date rate card expires |
| currency | string | Yes | Currency for pricing |
| rateType | string | Yes | Type of pricing: hourly, daily, fixedPrice, or volumeDiscount |
| rates | array | Yes | Array of rate entries with position/service and corresponding rates |
| paymentTerms | string | No | Payment terms and conditions |

**Relations:**
- → Supplier (many-to-one)
- → Contract (many-to-one)

### Receipt
**Schema.org:** `schema:DigitalDocument`
_Digital document storing scanned receipts, invoices, or proof of transaction for audit trail and digital archiving._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| documentType | enum | Yes | Type of document stored |
| fileName | string | Yes | Original filename as uploaded |
| encodingFormat | string | Yes | MIME type (e.g., application/pdf, image/jpeg) |
| contentSize | number | Yes | File size in bytes |
| uploadDate | datetime | Yes | Date and time document was uploaded |
| documentDate | date | No | Date on the receipt or document itself |
| description | string | No | Notes about the document or extraction notes |

**Relations:**
- → APTransaction (many-to-one)

### Report
**Schema.org:** `schema:Report`
_Schema.org Report — standard vocabulary for report data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Report title |
| reportType | string | Yes | Report type (financial, compliance, etc.) |
| period | string | No | Reporting period |
| generatedAt | datetime | No | When the report was generated |

### RequestForQuotation
**Schema.org:** `schema:Quotation`
_Request for quotation supporting RFx management with templated events, multi-round negotiations, and digital lockbox_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| rfqNumber | string | Yes | Unique RFQ identifier |
| title | string | Yes | RFQ title or description |
| deadline | datetime | Yes | Submission deadline for responses |
| round | number | Yes | Negotiation round number |
| status | string | Yes | Status: draft, published, closed, awarded, cancelled |
| lockboxEnabled | boolean | Yes | Enable digital lockbox to prevent bid viewing before deadline |
| estimatedValue | number | No | Estimated procurement value |
| createdDate | datetime | Yes | RFQ creation date |

**Relations:**
- → Organization (many-to-one)
- → Payee (many-to-many)
- → Offer (one-to-many)

### RevenueStream
**Schema.org:** `schema:Offer`
_A categorized source or type of revenue for tracking income by origin and supporting revenue management analysis._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| streamName | string | Yes | The name of the revenue source |
| category | string | Yes | Revenue classification (e.g., product sales, service fees, licensing) |
| currency | string | Yes | ISO 4217 currency code |
| annualTarget | object | No | Target revenue as {value, currency} following MonetaryAmount schema |
| isActive | boolean | No | Whether this revenue stream is currently active |

**Relations:**
- → JournalEntry (one-to-many)

### RiskCriteria
_Weighted assessment criteria for dynamic risk scoring and evaluation_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| criteriaName | string | Yes | Name of assessment criteria |
| criteriaType | string | Yes | Type: financial, operational, compliance, behavioral |
| weight | decimal | Yes | Weight in assessment (0.0-1.0, normalized across criteria set) |
| threshold | decimal | Yes | Threshold value for this criteria (e.g., days overdue) |
| description | string | No | Criteria definition and calculation method |
| riskLevel | string | No | Risk level if threshold breached: low, medium, high |
| active | boolean | Yes | Whether criteria is active in scoring |

### Role
_Collection of permissions defining access level and capabilities within the system_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Unique role name |
| description | string | No | Role description and purpose |
| isSystemRole | boolean | No | Whether this is a built-in system role |
| level | number | No | Role hierarchy level for permission evaluation |
| isActive | boolean | Yes | Whether the role is active |

**Relations:**
- → Permission (many-to-many)
- → User (many-to-many)

### SavingsOpportunity
**Schema.org:** `schema:Thing`
_A tracked initiative to reduce spending with projected and realized savings amounts for portfolio management_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of the savings opportunity or initiative |
| description | string | No | Detailed description of the savings initiative |
| projectedSavings | number | Yes | Expected annual savings amount in currency units |
| realizedSavings | number | No | Actual savings achieved to date |
| startDate | datetime | Yes | When the initiative started or is planned to start |
| completionDate | datetime | No | Expected or actual completion date |
| status | string | Yes | Status: pipeline, active, completed, cancelled |

**Relations:**
- → Organization (many-to-one)
- → ExpenseCategory (many-to-one)

### ScheduledPayment
**Schema.org:** `schema:Payment`
_Payment scheduled for future execution with support for recurring transactions_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| paymentReference | string | Yes | Unique payment reference or confirmation number |
| amount | number | Yes | Payment amount |
| currency | string | Yes | Currency code (ISO 4217) |
| scheduledDate | datetime | Yes | Date payment is scheduled for execution |
| frequency | string | No | Recurrence frequency: once, daily, weekly, monthly, yearly |
| recurringEndDate | datetime | No | End date for recurring payments |
| status | string | Yes | Status: pending, approved, executed, failed, cancelled |
| lastExecutionDate | datetime | No | Date of last payment execution |

**Relations:**
- → Payee (many-to-one)
- → BankAccount (many-to-one)
- → Payment (one-to-many)

### ServiceLevelAgreement
**Schema.org:** `schema:Service`
_Formal agreement defining service level targets, performance expectations, and remedies with a supplier_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| slaId | string | Yes | Unique SLA identifier |
| slaName | string | Yes | SLA name or title |
| description | string | No | Detailed SLA description |
| serviceMetric | string | Yes | Metric being measured (e.g., Response Time, Availability, Uptime) |
| targetLevel | string | Yes | Target service level (e.g., 99.5%, <4 hours) |
| acceptablePenalty | string | No | Consequence of non-compliance |
| effectiveDate | datetime | Yes | SLA effective date |
| expiryDate | datetime | No | SLA expiration date |
| status | string | Yes | Status (draft, active, expired, terminated) |

**Relations:**
- → Organization (many-to-one)

### SettlementDecision
**Schema.org:** `schema:DigitalDocument`
_Formal decision to finalize and mark one or more obligations as settled, issued by authorized personnel_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| decisionNumber | string | Yes | Unique decision identifier |
| decisionDate | date | Yes | Date decision was issued |
| issuedBy | Person | Yes | Authorized person who issued the decision |
| totalSettledAmount | MonetaryAmount | Yes | Total financial amount being settled |
| obligationCount | integer | No | Number of obligations included in settlement |
| decisionRationale | string | No | Reason or basis for settlement decision |
| documentUrl | string | No | Reference to decision document or file |

**Relations:**
- → Obligation (one-to-many)
- → ComplianceReport (many-to-one)

### Share
**Schema.org:** `schema:Product`
_Represents an ownership stake in a corporation. Tracks share quantity, type, nominal value, and acquisition date for investment tracking across multi-entity portfolio._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| shareNumber | string | Yes | Unique share class or certificate identifier |
| quantity | integer | Yes | Number of shares held |
| shareType | string | Yes | Share category: common, preferred, or founder shares |
| nominalValue | decimal | Yes | Nominal value per share in EUR |
| totalInvestmentAmount | decimal | Yes | Total investment in EUR (quantity × nominalValue) |
| acquisitionDate | date | Yes | Date shares were acquired or issued |
| votingRights | string | No | Voting rights status: full, limited, or none |

**Relations:**
- → Shareholder (many-to-one)
- → Corporation (many-to-one)

### Shareholder
**Schema.org:** `schema:Person`
_Person or organization holding ownership shares in one or more corporations. Tracks investors across the multi-entity portfolio._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| givenName | string | Yes | Given name (for individuals) |
| familyName | string | Yes | Family name (for individuals) |
| companyName | string | No | Organization name (for corporate shareholders) |
| email | string | No | Email address for shareholder contact |
| telephone | string | No | Telephone number for shareholder contact |
| shareholderType | string | Yes | Type: individual, organization, or foundation |
| residenceAddress | string | No | Residential or business address |

**Relations:**
- → Share (one-to-many)
- → Corporation (many-to-many)

### SigningAuthority
**Schema.org:** `schema:Person`
_Delegation of signing rights to a specific person with defined scope and limits_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| authorityNumber | string | Yes | Unique identifier for this signing authority |
| holderId | string | Yes | ID of person holding signing authority |
| signingScope | string | Yes | Types of documents/transactions: invoices, contracts, cheques, all |
| signingLimit | decimal | No | Maximum amount per transaction |
| currency | string | Yes | ISO 4217 currency code |
| validFrom | date | Yes | When this authority becomes effective |
| validThrough | date | No | When this authority expires |
| delegatedBy | string | Yes | ID of authorized representative or director |
| signatureMethod | string | No | Signature method: handwritten, digital, both |

**Relations:**
- → Mandate (many-to-one)

### SourcingEvent
**Schema.org:** `schema:Event`
_Sourcing event (RFQ, RFP, RFI) with supplier invitation and response tracking_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| eventId | string | Yes | Unique sourcing event identifier |
| eventType | string | Yes | Type of sourcing event: RFQ, RFP, or RFI |
| eventName | string | Yes | Title or name of the sourcing event |
| description | string | No | Detailed description of requirements and scope |
| releaseDate | datetime | Yes | Date the sourcing event is released to suppliers |
| deadline | datetime | Yes | Response submission deadline |
| status | string | Yes | Event status: draft, published, closed, or awarded |
| estimatedBudget | number | No | Estimated budget for the sourcing opportunity |

**Relations:**
- → Supplier (many-to-many)
- → PurchaseOrder (one-to-one)
- → Document (one-to-many)

### SpendCategory
**Schema.org:** `schema:Thing`
_Hierarchical category for organizing and analyzing supplier spending by type and business function_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| categoryId | string | Yes | Unique category identifier |
| name | string | Yes | Category name (e.g., IT Services, Maintenance, Staffing) |
| description | string | No | Category description |
| parentCategoryId | string | No | Parent category ID for hierarchical organization |
| level | number | No | Hierarchical level in category tree |
| status | string | Yes | Status (active, inactive, archived) |

### SpendTransaction
**Schema.org:** `schema:Order`
_Purchase order and transaction tracking for spend analytics_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| orderNumber | string | Yes | Purchase order number |
| orderDate | date | Yes | Date order was placed |
| invoiceNumber | string | No | Associated invoice number |
| totalPrice | number | Yes | Total transaction amount |
| currency | string | Yes | Currency code (EUR) |
| category | string | Yes | Spend category for analytics |
| deliveryDate | date | No | Actual or expected delivery date |
| deliveryOnTime | boolean | No | Whether delivered per SLA target |
| paymentStatus | string | Yes | Payment status (pending/paid/overdue) |

**Relations:**
- → Supplier (many-to-one)

### SpendingRecord
**Schema.org:** `schema:Order`
_Individual spending transaction for government transparency and audit compliance_
**Primary spec:** government-public-sector

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionId | string | Yes | Unique transaction identifier |
| transactionDate | date | Yes | Date of spending transaction |
| amount | number | Yes | Transaction amount in decimal format |
| currency | string | Yes | Currency code (EUR) |
| vendorName | string | Yes | Name of vendor or service provider |
| category | string | Yes | Spending category: personnel, operations, investment, or services |
| approvalStage | string | Yes | Current approval stage: draft, submitted, approved, or rejected |
| documentUri | string | No | Reference URI to supporting documentation |

**Relations:**
- → FundAllocation (many-to-one)
- → GovernmentEntity (many-to-one)
- → SubmissionDossier (many-to-one)

### StatementOfWork
**Schema.org:** `schema:CreativeWork`
_Detailed specification of deliverables, milestones, payment terms, and service scope for statement-of-work-based procurement and service ordering_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| sowNumber | string | Yes | Unique SOW identifier |
| sowDate | datetime | Yes | Date SOW was created |
| title | string | Yes | SOW title |
| description | string | No | Detailed description of work |
| scope | string | No | Work scope and boundaries |
| deliverables | array | No | Array of deliverable items with descriptions and due dates |
| milestones | array | No | Payment milestone objects with completion dates and invoice triggers |
| totalValue | number | Yes | Total SOW value |
| currency | string | Yes | Currency code |
| status | string | Yes | draft, active, completed, cancelled |

**Relations:**
- → Organization (many-to-one)
- → Person (many-to-one)
- → Contract (many-to-one)
- → PurchaseOrder (one-to-many)

### SubmissionDossier
**Schema.org:** `schema:DigitalDocument`
_Council submission dossier aggregating spending records and compliance documentation for public sector reporting_
**Primary spec:** government-public-sector

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Dossier title or reference name |
| dossierType | string | Yes | Type: annual report, quarterly report, audit submission, or grant report |
| submissionDate | date | Yes | Planned or actual submission date to council |
| completionPercentage | integer | Yes | Completion status as percentage (0-100) |
| contentSummary | string | No | Summary of dossier contents and key figures |

**Relations:**
- → GovernmentEntity (many-to-one)
- → SpendingRecord (one-to-many)

### Subscription
**Schema.org:** `schema:Offer`
_Recurring subscription arrangement with plan and quantity tracking for billing_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| subscriptionNumber | string | Yes | Unique subscription identifier |
| planName | string | Yes | Name of subscription plan |
| quantity | number | Yes | Quantity of units in subscription |
| startDate | datetime | Yes | Subscription start date |
| endDate | datetime | No | Subscription end date |
| amount | number | Yes | Recurring billing amount |
| frequency | string | Yes | Billing frequency (monthly, quarterly, yearly) |
| status | string | Yes | Subscription status |

**Relations:**
- → Organization (many-to-one)
- → Product (many-to-one)
- → Invoice (one-to-many)

### SubsidyApplication
**Schema.org:** `schema:Application`
_An application for a subsidy or grant under a specific subsidy scheme with supporting documentation_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| applicationId | string | Yes | Unique application identifier |
| requestedAmount | number | Yes | Requested grant amount |
| status | string | Yes | Application status: draft, submitted, under-review, approved, rejected |
| submissionDate | datetime | No |  |
| reviewDate | datetime | No |  |
| notes | string | No |  |

**Relations:**
- → SubsidyScheme (many-to-one)
- → Organization (many-to-one)
- → Document (one-to-many)

### SubsidyScheme
**Schema.org:** `schema:GovernmentService`
_A government subsidy program defining eligibility criteria, award conditions, and funding framework_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| schemeId | string | Yes | Unique scheme identifier |
| name | string | Yes | Subsidy scheme name |
| description | string | No |  |
| maxGrant | number | No | Maximum grant amount |
| minGrant | number | No | Minimum grant amount |
| isPublished | boolean | No | Published to public portal |
| publishedDate | datetime | No |  |
| governmentLevel | string | No | national, provincial, or municipal |

**Relations:**
- → Organization (many-to-one)
- → Grant (one-to-many)

### Supplier
**Schema.org:** `schema:Organization`
_Master data for suppliers participating in bid evaluations and framework agreements_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Official company legal name |
| tradeName | string | No | Commercial trading name |
| kvkNumber | string | Yes | Dutch Chamber of Commerce registration number |
| vatID | string | Yes | VAT identification number |
| email | string | Yes | Contact email address |
| telephone | string | No | Contact telephone number |
| url | string | No | Company website URL |
| iban | string | Yes | IBAN for payment processing |

**Relations:**
- → Person (one-to-many)

### SupplierBid
**Schema.org:** `schema:Offer`
_Supplier bid submitted for procurement evaluation with price, terms, and evaluation score_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Bid identifier or reference number |
| price | number | Yes | Bid amount offered |
| priceCurrency | string | Yes | Currency code (ISO 4217, e.g. EUR) |
| validFrom | date | Yes | Bid validity start date |
| validThrough | date | Yes | Bid validity expiration date |
| paymentTerms | string | No | Proposed payment terms (e.g., NET30) |
| deliverySchedule | string | No | Proposed delivery timeline or milestones |
| evaluationScore | number | No | Score assigned during automated evaluation |

**Relations:**
- → Supplier (many-to-one)
- → BidEvaluation (many-to-one)

### SupplierCertificate
**Schema.org:** `schema:Thing`
_Certification and compliance tracking for suppliers including ISO, safety, quality, and environmental certifications_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| certificateId | string | Yes | Unique certificate identifier |
| certificateType | string | Yes | Type of certification: ISO, safety, quality, environmental, etc. |
| certificationBody | string | No | Name of issuing certification organization |
| issuedDate | datetime | Yes | Date certificate was issued |
| expiryDate | datetime | No | Certificate expiration date |
| certificateNumber | string | No | Unique certificate number from issuing body |
| validationStatus | string | Yes | Current status: valid, expired, or revoked |

**Relations:**
- → Supplier (many-to-one)
- → Document (one-to-one)

### SupplierDocument
**Schema.org:** `schema:DigitalDocument`
_Certifications, licenses, insurance, and other supplier verification documents_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Document or certificate name |
| documentType | string | Yes | Classification of document |
| description | string | No | Document details and contents |
| certificationBody | string | No | Issuing organization |
| issuanceDate | date | Yes | Issue date |
| expiryDate | date | No | Expiration or renewal date |
| encodingFormat | string | No | MIME type (e.g. application/pdf) |
| contentSize | integer | No | File size in bytes |
| verificationStatus | string | Yes | Verification approval status |

**Relations:**
- → Supplier (many-to-one)

### SupplierKPI
**Schema.org:** `schema:Thing`
_Key Performance Indicator definition for measuring supplier performance across delivery, quality, cost, and responsiveness categories_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| kpiId | string | Yes | Unique KPI identifier |
| name | string | Yes | KPI name (e.g., On-Time Delivery Rate, Quality Score) |
| description | string | No | Detailed description of the KPI |
| unitOfMeasure | string | Yes | Unit of measurement (%, days, count, score) |
| targetValue | number | Yes | Target or benchmark value |
| weight | number | No | Importance weighting (0-1) in aggregate scoring |
| category | string | Yes | KPI category (delivery, quality, cost, responsiveness, compliance) |
| status | string | Yes | Status (active, inactive) |

### SupplierPerformanceReport
**Schema.org:** `schema:Report`
_Aggregated supplier performance reporting for period analysis_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportPeriod | string | Yes | Report period (YYYY-MM format) |
| reportType | string | Yes | Fixed value: supplier-performance |
| generatedAt | date | Yes | Report generation date |
| averageScore | number | Yes | Average performance score (0-10) |
| onTimeDeliveryPercent | number | Yes | On-time delivery percentage (0-100) |
| qualityScore | number | Yes | Period quality score (0-10) |
| totalSpend | number | Yes | Total spend in period |
| transactionCount | integer | Yes | Number of transactions in period |
| recommendations | text | No | Performance improvement recommendations |

**Relations:**
- → Supplier (many-to-one)

### SupplierPerformanceScore
**Schema.org:** `schema:Offer`
_Multi-dimensional performance metrics for supplier evaluation_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| scoringDate | date | Yes | Date score was calculated |
| overallScore | number | Yes | Overall performance score (0-10) |
| deliveryScore | number | Yes | On-time delivery score (0-10) |
| qualityScore | number | Yes | Product/service quality score (0-10) |
| responsivenessScore | number | Yes | Customer responsiveness score (0-10) |
| complianceScore | number | No | Contract/SLA compliance score (0-10) |
| scoringPeriod | string | Yes | Period covered (monthly/quarterly/annual) |

**Relations:**
- → Supplier (many-to-one)
- → SupplierSLA (many-to-one)

### SupplierPerformanceScorecard
**Schema.org:** `schema:AggregateRating`
_Comprehensive performance scorecard tracking supplier metrics against KPIs during a defined evaluation period_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| scorecardId | string | Yes | Unique scorecard identifier |
| period | string | Yes | Evaluation period identifier (e.g., Q1-2024) |
| overallScore | number | No | Aggregate performance score (0-100) |
| startDate | datetime | Yes | Evaluation period start date |
| endDate | datetime | No | Evaluation period end date |
| status | string | Yes | Scorecard status (draft, active, completed, archived) |

**Relations:**
- → Organization (many-to-one)
- → PerformanceScore (one-to-many)

### SupplierPortalAccount
**Schema.org:** `schema:Thing`
_Self-service portal account for supplier profile management, document submission, and order visibility_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountId | string | Yes | Unique portal account identifier |
| username | string | Yes | Portal login username |
| accountStatus | string | Yes | Account status: active, inactive, or pending |
| lastLogin | datetime | No | Timestamp of most recent login |
| accessLevel | string | Yes | Portal access level: basic or full |
| emailNotification | boolean | Yes | Enable email notifications |
| twoFactorEnabled | boolean | Yes | Two-factor authentication enabled |

**Relations:**
- → Supplier (one-to-one)
- → Person (one-to-one)

### SupplierPortalUser
**Schema.org:** `schema:Person`
_Self-service portal account for supplier staff with profile management and access control_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| givenName | string | Yes | First name |
| familyName | string | Yes | Last name |
| email | string | Yes | Login email and notification address |
| jobTitle | string | No | Job title at supplier |
| accessLevel | string | Yes | Portal permission level |
| lastLoginDate | datetime | No | Last successful portal login |
| profileCompleteness | integer | No | Supplier profile completion percentage (0-100) |
| preferredLanguage | string | Yes | Portal interface language |

**Relations:**
- → Supplier (many-to-one)

### SupplierQualification
**Schema.org:** `schema:Document`
_UEA self-declaration for supplier qualification in EU procurement_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| declarationNumber | string | Yes | Unique declaration reference number |
| declarationDate | datetime | Yes | Date of declaration submission |
| validUntil | datetime | Yes | Expiration date of qualification |
| declarationType | string | Yes | Type of declaration: UEA, ISO, other |
| status | string | Yes | Status: pending, approved, rejected, expired |

**Relations:**
- → Organization (many-to-one)
- → ComplianceDocument (one-to-many)

### SupplierRiskProfile
**Schema.org:** `schema:Organization`
_Supply chain risk profile with geographic positioning and compliance monitoring_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| riskScore | integer | Yes | Overall risk score (0-100) |
| geoLocation | string | Yes | Geographic coordinates (latitude,longitude) or address |
| country | string | Yes | ISO 3166 country code |
| complianceStatus | string | Yes | Compliance status: compliant, warning, non-compliant |
| paymentDefaultHistory | integer | No | Count of late/missed payments |
| lastAssessmentDate | date | No | Date of most recent risk assessment |
| creditLimit | decimal | No | Maximum credit exposure in EUR |
| geopoliticalRiskLevel | string | No | Geopolitical risk: low, medium, high |

**Relations:**
- → Organization (one-to-one)
- → Transaction (one-to-many)

### SupplierSLA
**Schema.org:** `schema:Offer`
_Service Level Agreement defining expected performance standards_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| slaNumber | string | Yes | Unique SLA identifier |
| description | string | Yes | SLA terms and conditions |
| deliveryTargetDays | integer | Yes | Target delivery time in days |
| qualityThresholdPercent | number | Yes | Minimum quality acceptance threshold (0-100%) |
| responseTimeHours | number | Yes | Target response time in hours |
| penaltyPercentage | number | No | Non-compliance penalty as % of invoice |
| validFrom | date | Yes | SLA effective date |
| validThrough | date | No | SLA expiration date |

**Relations:**
- → Supplier (many-to-one)

### SupplierSurvey
**Schema.org:** `schema:Survey`
_Assessment or feedback survey collecting quantitative and qualitative supplier performance data for evaluation and analysis_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| surveyId | string | Yes | Unique survey identifier |
| surveyName | string | Yes | Survey name or title |
| respondentScore | number | No | Quantitative score from respondent (0-100) |
| surveyDate | datetime | Yes | Date survey was completed |
| feedbackText | string | No | Qualitative feedback or comments |
| respondentName | string | No | Name of respondent |
| status | string | Yes | Status (draft, submitted, reviewed, approved) |

**Relations:**
- → Organization (many-to-one)
- → SupplierPerformanceScorecard (many-to-one)

### SupplyChainRisk
**Schema.org:** `schema:Thing`
_Supply chain risk monitoring including geopolitical and natural disaster impact assessment_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| riskType | string | Yes | geopolitical, natural-disaster, supplier-failure, regulatory, financial |
| severity | string | Yes | critical, high, medium, low |
| description | string | Yes |  |
| affectedCountries | array | No | ISO country codes |
| impactArea | string | No |  |
| geopoliticalFactors | object | No |  |
| naturalDisasterFactors | object | No |  |
| assessmentDate | datetime | Yes |  |
| nextReviewDate | datetime | No |  |
| status | string | Yes | identified, monitoring, escalated, resolved |

**Relations:**
- → Organization (many-to-one)

### TaxConfiguration
**Schema.org:** `schema:Thing`
_System-wide tax settings, rules, and thresholds for a specific jurisdiction and tax year_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| configId | string | Yes | Unique configuration identifier |
| taxYear | number | Yes | Tax year this configuration applies to |
| jurisdiction | string | Yes | Tax jurisdiction code (NL, UK, US, etc.) |
| effectiveDate | datetime | Yes | Date when this configuration becomes effective |
| description | string | No | Configuration description and compliance notes |

**Relations:**
- → Organization (many-to-one)
- → TaxRate (one-to-many)

### TaxDeclaration
**Schema.org:** `schema:Report`
_Primary tax declaration submission (VAT, BCF, exemptions). Aggregates tax lots and manages workflow from draft to submission._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| declarationType | enum | Yes | BCF, VAT-NL, ICP, or other Dutch tax form type |
| taxYear | integer | Yes | Calendar or fiscal year (e.g. 2025) |
| declarationStatus | enum | Yes | draft, approved, submitted, acknowledged, rejected |
| totalTaxAmount | MonetaryAmount | Yes | Net tax liability or credit |
| submissionDate | date | No | Actual submission timestamp to authorities |
| businessTaxID | string | Yes | Taxpayer BSN/KVK or VAT ID |

**Relations:**
- → Organization (many-to-one)
- → TaxLot (one-to-many)
- → ExemptionCertificate (many-to-many)

### TaxExemption
**Schema.org:** `schema:Offer`
_Reusable exemption rule or policy: qualifies transactions or amounts as exempt. Linked to certificates and applied during tax lot calculation._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| exemptionCode | string | Yes | Statutory code (e.g. 021 for research) |
| exemptionName | string | Yes | Display name (e.g. 'Research & Development Exemption') |
| applicableTaxTypes | array | Yes | List of tax categories this exemption applies to (VAT, profit, withholding, etc.) |
| effectiveFrom | date | Yes | Start of exemption period |
| effectiveUntil | date | No | End of exemption period; null = ongoing |

**Relations:**
- → Organization (many-to-one)
- → ExemptionCertificate (many-to-one)

### TaxLot
**Schema.org:** `schema:MonetaryAmount`
_Individual tax line item: single transaction or aggregate category contributing to declaration. Tracks category, amount, and justification._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lotNumber | string | Yes | Unique identifier within declaration (e.g. VAT-001) |
| taxCategory | string | Yes | VAT standard/reverse/zero rate, profit, withholding, excise, etc. |
| amount | decimal | Yes | Gross or net tax amount |
| currency | string | Yes | EUR or other currency code |
| transactionDate | date | Yes | Date of underlying transaction or period start |
| description | string | No | Narrative or reference (e.g. invoice number, period) |

**Relations:**
- → TaxDeclaration (many-to-one)
- → BankAccount (many-to-one)

### TaxRate
**Schema.org:** `schema:Thing`
_Individual tax rate rules for income, sales, VAT, capital gains, or other tax types with effective date management_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| rateId | string | Yes | Unique rate identifier |
| rateType | string | Yes | Type of tax: income, sales, vat, capital_gains, tds, gst, or other |
| percentage | number | Yes | Tax rate as percentage |
| effectiveDate | datetime | Yes | Date when this rate becomes effective |
| expiryDate | datetime | No | Date when this rate expires or is superseded |

**Relations:**
- → TaxConfiguration (many-to-one)
- → Product (many-to-one)

### TaxReturn
**Schema.org:** `schema:Thing`
_A formal tax return filing for income, VAT, or other tax obligations with workflow management and compliance tracking_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| returnId | string | Yes | Unique identifier for the tax return |
| filingPeriod | string | Yes | Period covered by this return (e.g., Q1 2026) |
| taxYear | number | Yes | Calendar year for tax reporting |
| totalIncome | number | No | Total income for the period |
| totalExpenses | number | No | Total deductible expenses |
| status | string | Yes | Current status: draft, submitted, approved, or rejected |
| filedDate | datetime | No | Date when the return was submitted |

**Relations:**
- → Organization (many-to-one)
- → TaxConfiguration (many-to-one)

### TaxableTransaction
**Schema.org:** `schema:Thing`
_Business transaction classified and tracked for tax reporting, audit trail, and automated tax calculation with receipt scanning support_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionId | string | Yes | Unique transaction identifier |
| amount | number | Yes | Transaction amount |
| transactionDate | datetime | Yes | Date of the transaction |
| taxCategory | string | Yes | Tax classification category for reporting |
| taxRate | number | No | Applied tax rate percentage |
| description | string | No | Transaction description for audit trail |

**Relations:**
- → TaxReturn (many-to-one)
- → Receipt (many-to-one)
- → Payment (many-to-one)

### Team
**Schema.org:** `schema:Organization`
_Group of users organized for collaboration with shared access and permissions_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Team name |
| description | string | No | Team description and purpose |
| isActive | boolean | Yes | Whether the team is active |
| createdAt | datetime | No | Team creation date |

**Relations:**
- → Account (many-to-one)
- → User (many-to-many)

### Tender
**Schema.org:** `schema:Order`
_Digital solicitation request for goods or services from multiple suppliers_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Tender title |
| description | string | Yes | Detailed description of the tender scope |
| closingDate | datetime | Yes | Deadline for submitting bids |
| publicationDate | datetime | Yes | Date when tender was published |
| totalBudget | number | Yes | Total budget allocated for the tender |
| budgetCurrency | string | Yes | Currency code (EUR) |
| minimumQuoteCount | integer | Yes | Minimum number of required quotes |
| referenceNumber | string | Yes | Unique tender reference number (aanbestedingsnummer) |
| procurementType | string | Yes | Procurement procedure type (open, restricted, negotiated) |
| contactPerson | string | Yes | Name of responsible contact |
| contactEmail | string | Yes | Email address for inquiries |
| deliveryLocation | string | Yes | Address where goods/services are delivered |
| documents | array | Yes | Tender specifications and requirements documents |
| estimatedDuration | string | No | Contract duration (e.g., 24 months) |
| category | string | No | Category of goods or services |
| paymentTerms | string | No | Payment conditions |
| consultationDeadline | datetime | No | Deadline for clarification questions |
| contractStartDate | datetime | No | Planned contract start date |

**Relations:**
- → Supplier (many-to-many)
- → TenderLineItem (one-to-many)
- → Quote (one-to-many)
- → TenderDocument (one-to-many)

### TenderAmendment
**Schema.org:** `schema:DigitalDocument`
_Amendment to published tender, flagged as material or non-material change_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Amendment title |
| changeDescription | string | Yes | Detailed description of what was changed |
| isMaterialChange | boolean | Yes | True if material change requiring republication |
| publicationDate | date | Yes | Amendment publication date |
| tedReferenceId | string | No | TED/OJEU amendment reference ID |
| newClosingDate | date | No | New submission deadline if extended |

**Relations:**
- → TenderNotice (many-to-one)

### TenderDocument
**Schema.org:** `schema:DigitalDocument`
_Specifications, terms, and attachments for tender process_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| documentType | enum | Yes | Document role |
| uploadedDate | date | No | Upload date |
| requiredForBidding | boolean | No | Mandatory review before submitting quote |

**Relations:**
- → Tender (many-to-one)

### TenderLineItem
**Schema.org:** `schema:Product`
_Individual product or service line in tender request_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| description | text | Yes | Item or service description |
| quantity | number | Yes | Quantity needed |
| unitCode | string | Yes | Unit (pcs, kg, hours, etc.) |
| unitPrice | number | No | Estimated unit price (cents) |
| category | string | No | Product/service category |
| specifications | text | No | Technical or quality requirements |

**Relations:**
- → Tender (many-to-one)

### TenderLot
**Schema.org:** `schema:Thing`
_A distinct portion of a tender that can be evaluated and awarded separately with independent budgets and evaluation criteria_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lotNumber | string | Yes | Unique lot number or identifier within the tender |
| title | string | Yes | Title or description of the lot |
| description | string | No | Detailed scope of work or goods included in this lot |
| budgetAmount | number | No | Budget allocated to this specific lot |
| currency | string | No | Currency code for budget |
| status | string | No | Status: draft, open, evaluation, awarded, closed |
| evaluationCriteria | array | No | Weighted evaluation criteria with scoring rules |
| minParticipants | number | No | Minimum number of suppliers required |
| maxParticipants | number | No | Maximum number of suppliers allowed |

**Relations:**
- → Tender (many-to-one)
- → Bid (one-to-many)
- → Product (many-to-many)

### TenderNotice
**Schema.org:** `schema:DigitalDocument`
_Tender or procurement notice published to TED/OJEU and market platforms for public competition_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of the tender |
| tenderType | string | Yes | Type: SERVICES, SUPPLIES, WORKS, or CONCESSION |
| publicationDate | date | Yes | Date published |
| tedReferenceId | string | No | TED/OJEU publication ID |
| estimatedValue | number | No | Estimated contract value in EUR |
| closingDate | date | Yes | Submission deadline |
| scope | string | Yes | Geographic scope: EUROPEAN, NATIONAL, or REGIONAL |

**Relations:**
- → Organization (many-to-one)

### TimeEntry
**Schema.org:** `TimeEntry`
_Time tracking entries for project tasks including manual entry and timer-based tracking_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| entryId | string | Yes | Unique time entry identifier |
| taskId | string | Yes | Project task this time is logged against |
| projectId | string | Yes | Project associated with this entry |
| userId | string | Yes | Person/User ID who logged the time |
| date | datetime | Yes | Date of the time entry |
| duration | number | Yes | Duration in hours |
| description | string | No | Details of work performed |
| entryType | string | No | manual or timer |
| billable | boolean | No | Whether this time is billable to client |

**Relations:**
- → ProjectTask (many-to-one)
- → Project (many-to-one)
- → Person (many-to-one)

### Timesheet
**Schema.org:** `schema:Report`
_Periodic summary of time entries for an employee, aggregating hours and utilization metrics by week or month_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| periodStart | datetime | Yes | Start date of the reporting period |
| periodEnd | datetime | Yes | End date of the reporting period |
| totalHours | number | Yes | Total hours logged in period |
| utilizationPercentage | number | No | Utilization rate as percentage of available hours |
| totalCost | number | No | Total cost based on hourly rates |
| status | string | Yes | Status: draft, submitted, or approved |
| submittedDate | datetime | No | Date when submitted for approval |
| approvedDate | datetime | No | Date when approved |

**Relations:**
- → Person (many-to-one)
- → TimeEntry (one-to-many)
- → ApprovalRequest (many-to-one)

### Transaction
**Schema.org:** `schema:Order`
_Financial transaction in the bookkeeping system (debit/credit entry)_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionNumber | string | Yes | Unique transaction reference |
| transactionType | string | Yes | Type: invoice, payment, transfer, credit |
| amount | decimal | Yes | Transaction amount |
| currency | string | Yes | ISO 4217 currency code |
| description | string | No | Transaction description/memo |
| transactionDate | date | Yes | Date of transaction |
| paymentTerms | string | No | Payment terms (e.g., net30) |
| orderStatus | string | Yes | Status: pending, completed, cancelled |

**Relations:**
- → Organization (many-to-one)
- → BankAccount (many-to-one)
- → PaymentFraudAssessment (one-to-many)

### TreasuryTask
**Schema.org:** `schema:Event`
_Unified AP/AR/spend task list for cash flow management with due dates and counterparty tracking_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taskType | string | Yes | AccountsPayable, AccountsReceivable, or CapitalExpenditure |
| amount | number | Yes | Transaction amount |
| currency | string | Yes | ISO 4217 code |
| dueDate | string | Yes | ISO 8601 date |
| counterpartyName | string | No | Vendor, customer, or counterparty |
| description | string | No | Task details and notes |

**Relations:**
- → CashAccount (many-to-one)
- → Organization (many-to-one)

### TrialBalance
**Schema.org:** `schema:Table`
_A report listing all general ledger accounts with debit or credit balances for verification and audit purposes_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportDate | datetime | Yes | Date of the trial balance |
| totalDebits | number | No | Total of all debit balances |
| totalCredits | number | No | Total of all credit balances |
| isBalanced | boolean | No | Whether debits equal credits |
| status | string | Yes | Status (draft, verified, final) |
| preparedBy | string | No | Name or identifier of person who prepared the trial balance |

**Relations:**
- → FiscalYear (many-to-one)
- → Organization (many-to-one)
- → GeneralLedgerEntry (one-to-many)

### User
**Schema.org:** `schema:Person`
_System account for authentication and access control with assigned permissions and team memberships_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| username | string | Yes | Unique username for login |
| email | string | Yes | Email address for the account |
| firstName | string | No | First name of the user |
| lastName | string | No | Last name of the user |
| isActive | boolean | Yes | Whether the account is active |
| twoFactorEnabled | boolean | No | Whether 2FA is enabled |
| createdAt | datetime | Yes | Account creation date |
| lastLogin | datetime | No | Date of last login |

**Relations:**
- → Person (many-to-one)
- → Team (many-to-many)
- → Role (many-to-many)
- → Account (many-to-many)
- → Entitlement (one-to-many)
- → UserPreference (one-to-many)

### UserPreference
_User-specific preferences for display settings, notifications, language, and other customization options_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| key | string | Yes | Preference key or identifier |
| value | string | Yes | Preference value |
| category | string | No | Category of preference (display, notification, language, accessibility) |
| updatedAt | datetime | No | Last update date |

**Relations:**
- → User (many-to-one)

### VATReturn
**Schema.org:** `schema:Thing`
_VAT-specific tax return showing collected VAT, paid VAT, and net amount due for MTD compliance and electronic filing_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vatReturnId | string | Yes | Unique VAT return identifier |
| reportingPeriod | string | Yes | VAT reporting period: monthly, quarterly, or annually |
| collectedVAT | number | Yes | VAT collected from customers |
| paidVAT | number | Yes | VAT paid on business purchases and expenses |
| netAmount | number | Yes | Net VAT payable (positive) or refundable (negative) |
| status | string | Yes | Status: draft, submitted, approved, or rejected |
| submissionDate | datetime | No | Date when VAT return was submitted to authorities |

**Relations:**
- → Organization (many-to-one)
- → TaxReturn (many-to-one)

### VendorBill
**Schema.org:** `schema:Invoice`
_Vendor invoice with approval workflow before payment processing_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| billNumber | string | Yes | Unique vendor bill identifier |
| invoiceDate | datetime | Yes | Date the invoice was issued |
| dueDate | datetime | Yes | Payment due date |
| totalAmount | number | Yes | Total invoice amount |
| currency | string | Yes | Currency code |
| status | string | Yes | Bill status: received, approved, rejected, or paid |
| approvalStatus | string | Yes | Approval workflow status: pending, approved, or rejected |
| poReference | string | No | Reference to linked purchase order |

**Relations:**
- → Supplier (many-to-one)
- → PurchaseOrder (many-to-one)
- → ApprovalRequest (one-to-one)
- → Payment (one-to-one)
- → Document (one-to-many)

### WOZAssessment
**Schema.org:** `schema:Assessment`
_Property tax valuation assessment (Waardering Onroerende Zaken) with automated model generation_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assessmentYear | string | Yes | Tax year |
| assessedValue | number | Yes |  |
| valuationMethod | string | No |  |
| assessmentDate | datetime | Yes |  |
| status | string | Yes | draft, finalized, appealed, approved |
| notificationSentDate | datetime | No | Date owner notification was sent |

**Relations:**
- → Property (many-to-one)

### XBRLInstance
**Schema.org:** `schema:DigitalDocument`
_Structured XBRL instance document for taxonomies (NTA7, SBR-NT). Contains facts, contexts, and dimensions for standardized digital reporting to Dutch authorities._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taxonomyVersion | string | Yes | e.g. NTA7-2025, SBR-NT-2025 |
| instanceID | string | Yes | Unique document identifier |
| reportingPeriod | string | Yes | ISO date range (e.g. 2025-01-01/2025-12-31) |
| factCount | integer | No | Number of XBRL facts in instance |
| encodingFormat | enum | Yes | application/xbrl+xml or application/xbrl+json |
| validationStatus | enum | Yes | valid, invalid, warned, unvalidated |

**Relations:**
- → TaxDeclaration (many-to-one)

### XBRLTaxonomy
**Schema.org:** `schema:CreativeWork`
_XBRL (eXtensible Business Reporting Language) taxonomy definitions for structured tax reporting, compliance, and regulatory filing_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taxonomyId | string | Yes | Unique taxonomy identifier |
| version | string | Yes | Taxonomy version number |
| effectiveDate | datetime | Yes | Date when taxonomy becomes effective |
| namespace | string | Yes | XML namespace URI for the taxonomy |
| elements | array | No | List of XBRL element definitions and mappings |

**Relations:**
- → TaxReturn (one-to-many)


### adr-001-bookkeeping-tier-roadmap: ADR-001: Bookkeeping tier roadmap — canonical 5-tier breakdown
# ADR-001: Bookkeeping tier roadmap — canonical 5-tier breakdown

**Status:** Accepted
**Date:** 2026-05-17

## Context

Shillinq pivoted from a customer-invoicing app to a full double-entry
bookkeeping engine for Dutch SMB (MKB), self-employed (ZZP), and
decentralised government (gemeenten, waterschappen, provincies). The
resulting surface area is large: 42 capability specs across 5
proposals in this single PR, with another tier (T5) explicitly
deferred. Rolling all of it as one change is not reviewable; rolling
it as 42 independent changes loses the dependency structure.

The chosen middle ground is a **tiered rollout**: each tier is one
OpenSpec change, every capability inside a tier is one spec file, and
the tiers chain via documented dependencies. Each of the five
proposals in this PR initially published its own variant of the
5-tier table — and they disagreed (T1 said T2=sub-ledgers, T2 said
T3=tax+sector, T3 said T4=reporting+analytics, T4-base said
T5=cross-cutting/specialised). Three proposals also referenced
phantom change slugs that were never created
(`add-shillinq-bookkeeping-subledgers`,
`add-shillinq-bookkeeping-period-close`,
`add-shillinq-bookkeeping-reporting`,
`add-shillinq-bookkeeping-multicurrency-and-tax`,
`add-shillinq-bookkeeping-subledgers-close-statements`).

This ADR fixes the breakdown in one place. Every proposal links here
instead of re-publishing its own table.

## Decision

There is **one canonical 5-tier breakdown** for the Shillinq
bookkeeping rollout, listed below. The 5 changes in this PR cover
**T1 through T4-specialized**. T5 is forward-looking and explicitly
empty in this PR; it is tracked separately and not implied by any
in-flight change in this envelope.

| Tier | Change slug | Scope | Capabilities |
|------|-------------|-------|--------------|
| **T1** | `add-shillinq-bookkeeping-foundation` | Foundation | `bookkeeping-chart-of-accounts`, `bookkeeping-general-ledger`, `bookkeeping-journal-entries` (3 specs) |
| **T2** | `add-shillinq-bookkeeping-compliance` | Sub-ledgers + period machinery | `bookkeeping-trial-balance`, `bookkeeping-period-close`, `bookkeeping-accounts-payable-core`, `bookkeeping-accounts-receivable-core`, `bookkeeping-financial-statements`, `bookkeeping-audit-trail` (consume from OR), `bookkeeping-document-attachment-integration` (consume from docudesk), `bookkeeping-bank-reconciliation` (8 specs) |
| **T3** | `add-shillinq-bookkeeping-operations` | Operations + NL regulatory core | `bookkeeping-vat-btw-filing`, `bookkeeping-bbv-compliance`, `bookkeeping-iv3-reporting`, `bookkeeping-bcf-vat-compensation`, `bookkeeping-kor-kleine-ondernemersregeling`, `bookkeeping-zzp-tax-regime`, `bookkeeping-schatkistbankieren`, `bookkeeping-subsidie-verantwoording`, `bookkeeping-archiefwet-retention`, `bookkeeping-consultancy-project-accounting` (10 specs) |
| **T4-base** | `add-shillinq-bookkeeping-advanced` | Advanced engine features | `bookkeeping-sbr-xbrl-reporting`, `bookkeeping-fixed-assets-depreciation`, `bookkeeping-multi-currency`, `bookkeeping-cost-centers-dimensions`, `bookkeeping-year-end-close`, `bookkeeping-bank-connectors`, `bookkeeping-reconciliation-reports` (7 specs) |
| **T4-specialized** | `add-shillinq-gov-sector-mkb-advanced` | NL gov sector variants + Vpb + MKB innovation + detachering | `bookkeeping-waterschappen-bbv-variant`, `bookkeeping-provincies-bbv-variant`, `bookkeeping-gr-consolidation`, `bookkeeping-rekenkamer-audit-pack`, `bookkeeping-cbs-bestanden-extended`, `bookkeeping-emu-reporting`, `bookkeeping-sisa-reporting`, `bookkeeping-market-government-separation`, `bookkeeping-vpb-corporate-tax`, `bookkeeping-innovatiebox-administratie`, `bookkeeping-investeringsaftrek`, `bookkeeping-wbso-sno-administratie`, `bookkeeping-r-d-subsidies-mkb`, `bookkeeping-detachering-payroll-administratie` (14 specs) |
| **T5** | _(future, not in this PR)_ | Cross-cutting + e-invoicing + treasury | UBL/Peppol BIS 3.0 outbound for AR, intercompany eliminations, advanced group consolidation, treasury cash forecasting, IFRS rebridge, multi-administration aggregation. **Explicitly OUT of this PR; tracked separately.** |

### Build order

T1 → T2 → T3 → T4-base / T4-specialized (the two T4 changes may
land in parallel; T4-specialized depends on selected T2/T3/T4-base
capabilities but not on the entirety of T4-base).

### Dependency annotations

Where a spec lists `Depends on:` in its header (or where a proposal
narrative cross-references a sibling), the `(T1)` / `(T2)` / etc.
annotations refer to **this table**. A reference like
"depends on `bookkeeping-trial-balance` (T2)" means the
`bookkeeping-trial-balance` capability lives in the T2 change
(`add-shillinq-bookkeeping-compliance`).

### VAT/BTW lands in T3, not T5

Earlier drafts of the T1 proposal deferred VAT/BTW posting automation
to T5. That was a drafting error: VAT/BTW filing ships in T3 as
`bookkeeping-vat-btw-filing` (under `add-shillinq-bookkeeping-operations`).
T1 has no VAT/BTW surface — neither in scope nor as a deferred
out-of-scope item — beyond the plain `vatApplicable` boolean on the
`Account` schema that downstream tiers consume.

## Consequences

### Positive

- **One source of truth.** Every proposal links here; there is no
  per-proposal table to drift.
- **Spec readers reason about tier ownership in one place.** A reader
  who sees "(T3)" next to a slug can look up exactly which change
  envelope owns it.
- **Phantom slugs are killed.** Future references to
  `add-shillinq-bookkeeping-subledgers`,
  `…-period-close`, `…-reporting`,
  `…-multicurrency-and-tax`, or
  `…-subledgers-close-statements` are review-blocking — those slugs
  do not exist and never will.

### Negative

- **One more file to maintain.** When a capability moves between
  tiers, this table updates. The cost is small (one edit per move,
  caught immediately by reviewer rather than slowly drifting across
  five proposals).

### Migration

This ADR supersedes any per-proposal "5-tier rollout" table. Those
tables MUST be removed from the five proposals and replaced with a
one-line link to this ADR. The replacement was done in the same PR
that introduces this ADR (see the proposals under
`openspec/changes/add-shillinq-bookkeeping-*` and
`openspec/changes/add-shillinq-gov-sector-mkb-advanced`).

## See also

- `adr-000-data-model.md` — the 225-entity catalogue every tier
  consumes.
- `hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md`
  — declarative-first principle every tier follows.
- `hydra/openspec/architecture/adr-032-spec-sizing-and-chaining.md` —
  `kind:` taxonomy and chain primitive that the tier breakdown
  rests on.
- `hydra/openspec/architecture/adr-024-app-manifest.md` — manifest
  shape every tier extends.
- `hydra/openspec/architecture/adr-022-apps-consume-openregister-abstractions.md`
  — RBAC / audit / retention consumption every tier inherits.


## App Architecture ADRs from Repo (2 files)

These ADR files live in shillinq/openspec/architecture/.

### ADR-000-data-model
# ADR: Data Model — Shillinq

**Status:** accepted
**Entities:** 225

## Context

All data entities are OpenRegister schemas. This ADR is the single source of truth
for the app's data model. Individual specs REFERENCE these entities but do not redefine them.

OpenRegister built-in fields (NOT listed below, always available):
id, uuid, uri, version, createdAt, updatedAt, owner, organization,
register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

## Entities

### APTransaction
**Schema.org:** `schema:Order`
_Financial transaction representing an invoice, credit note, or debit note in accounts payable/receivable flow._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionNumber | string | Yes | Unique invoice or transaction identifier |
| transactionType | enum | Yes | Type of transaction |
| transactionDate | date | Yes | Date invoice or transaction issued |
| dueDate | date | Yes | Payment due date |
| amount | MonetaryAmount | Yes | Total transaction amount including tax |
| paymentTerms | string | No | Payment conditions (e.g., net 30, 2/10 net 30) |
| description | string | No | Invoice line items or transaction details |

**Relations:**
- → Payee (many-to-one)
- → Receipt (one-to-many)
- → Payment (one-to-many)
- → DunningNotice (one-to-many)

### Account
**Schema.org:** `schema:DefinedTerm`
_Hierarchical chart-of-accounts entry conforming to the RGS (Referentie Grootboek Schema) standard. Canonical bookkeeping entity for T1–T5 tiers. Supersedes the earlier `GeneralLedgerAccount` entry (see reconciliation note below)._
**Primary spec:** bookkeeping-chart-of-accounts

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountNumber | string | Yes | RGS-style account code (e.g. 1000, 4100) |
| name | string | Yes | Human-readable account name |
| accountType | enum | Yes | One of assets, liabilities, equity, revenue, expenses |
| currency | string | Yes | ISO 4217 currency code (e.g. EUR); default EUR |
| parentAccountNumber | string | No | FK to parent Account.accountNumber for hierarchy |
| isClosingAccount | boolean | No | Designates this as the administration's single closing account |
| administrationId | string | Yes | FK to the Administration owning this account |
| lifecycleState | enum | Yes | One of active, blocked, archived |
| description | string | No | Operator-authored free-text description |
| vatApplicable | boolean | No | Whether VAT/BTW applies to transactions on this account |
| iban | string | No | Dutch IBAN for bank/cash accounts |

**Relations:**
- self → Account (many-to-one, via parentAccountNumber → accountNumber; hierarchy navigation)
- → GLLine (one-to-many, from T1 general-ledger change)
- → Administration (many-to-one)

> **Reconciliation note (add-shillinq-chart-of-accounts, 2026-05-18):** The earlier
> `GeneralLedgerAccount` entry (Schema.org `schema:Product`, primary spec
> financial-reporting-accountability) has been reconciled into this `Account` entry.
> `Account` is the canonical T1 chart-of-accounts schema registered in
> `lib/Settings/shillinq_register.json`. The `GeneralLedgerAccount` entry below is
> retained for historical reference but MUST NOT be used for new register declarations;
> downstream specs (T2 trial balance, T3 VAT, T4 multi-currency) MUST reference
> `Account.accountNumber` as the FK target. The Schema.org type is corrected to
> `schema:DefinedTerm` — a ledger account code is a coded financial classifier
> (DefinedTerm), not a product.

### AccountabilityReport
**Schema.org:** `schema:Report`
_An official accountability report submitted by an organization for a fiscal period covering financial position and transactions_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportNumber | string | Yes | Unique identifier for the accountability report |
| reportDate | datetime | Yes | Date the report was generated |
| submissionDate | datetime | No | Date the report was submitted to relevant authority |
| status | string | Yes | Status (draft, submitted, approved, rejected) |
| content | string | No | Full text content of the accountability report |
| approvalStatus | string | Yes | Approval status (pending, approved, rejected) |

**Relations:**
- → FiscalYear (many-to-one)
- → Organization (many-to-one)
- → Person (many-to-one)
- → DigitalDocument (one-to-many)

### Administration
**Schema.org:** `schema:DigitalDocument`
_Accounting administration unit for a specific business year of a corporation. Supports multi-administration management for tracking financial records per fiscal year._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| administrationNumber | string | Yes | Unique identifier for this administration unit |
| businessYear | string | Yes | Business year (YYYY format) |
| accountingPeriod | string | Yes | Period type: monthly, quarterly, or annual |
| startDate | date | Yes | Start date of the accounting period |
| endDate | date | Yes | End date of the accounting period |
| accountantName | string | No | Responsible accountant or accounting firm name |
| submissionDate | date | No | Date administration was submitted (if applicable) |

**Relations:**
- → Corporation (many-to-one)

### AllocationRule
**Schema.org:** `schema:Thing`
_Recurring rule for automatically allocating overhead and shared costs between cost centers based on percentage, fixed amount, or calculation formula_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the allocation rule |
| ruleType | string | Yes | Type: percentage, fixed amount, or formula-based |
| percentage | number | No | Percentage to allocate (if percentage-based) |
| fixedAmount | number | No | Fixed amount to allocate per period |
| frequency | string | Yes | Frequency: monthly, quarterly, or yearly |
| isActive | boolean | Yes | Whether rule is currently active |
| startDate | datetime | Yes | Date rule becomes effective |
| endDate | datetime | No | Date rule expires |
| description | string | No |  |

**Relations:**
- → CostCenter (many-to-one)
- → CostCenter (many-to-one)

### ApprovalChain
**Schema.org:** `ApprovalChain`
_Configurable approval workflows that define the sequence of approvers for different document types_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| chainId | string | Yes | Unique approval chain identifier |
| name | string | Yes | Name of the approval chain |
| documentType | string | Yes | Type of document this applies to (PurchaseOrder, Document, ExpenseClaim, etc.) |
| description | string | No | Workflow description |
| status | string | No | active or inactive |
| approverSequence | array | Yes | Ordered list of approver roles or users |
| requiresSignature | boolean | No | Whether approval requires digital signature |

**Relations:**
- → ApprovalTask (one-to-many)

### ApprovalRequest
**Schema.org:** `schema:Event`
_Approval workflow management for purchase orders and documents_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| requestNumber | string | Yes | Unique approval request ID |
| description | text | Yes | What requires approval and business justification |
| startDate | date | Yes | Approval workflow initiation date |
| dueDate | date | No | Target approval deadline |
| requiredApproversCount | integer | Yes | Number of approvals required |
| currentApprovalCount | integer | No | Current approval count |
| approverEmails | string | No | Comma-separated approver contact list |

**Relations:**
- → PurchaseOrder (many-to-one)
- → Document (many-to-one)

### ApprovalRoute
**Schema.org:** `schema:Event`
_Workflow defining contract approval steps and authorized approvers_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of approval workflow |
| description | string | No | Description of approval process |
| approverSequence | array | No | Ordered list of approver names/roles/groups |
| priority | string | No | Workflow priority (Low, Medium, High) |
| estimatedDays | number | No | Estimated days to complete approvals |

### ApprovalTask
**Schema.org:** `schema:Action`
_Individual approval task assigned to a user within an approval workflow_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taskId | string | Yes | Unique approval task identifier |
| approvalChainId | string | Yes | Reference to the approval chain configuration |
| documentId | string | Yes | Reference to the document being approved |
| approvalRequestId | string | Yes | Reference to the approval request |
| stepNumber | number | Yes | Step number in the approval sequence |
| assignedTo | string | Yes | Person/User ID assigned this task |
| status | string | Yes | pending/approved/rejected/delegated |
| dueDate | datetime | No | When approval is due |
| completedDate | datetime | No | When approval was completed |
| approvalComment | string | No | Comments from the approver |

**Relations:**
- → ApprovalChain (many-to-one)
- → ApprovalRequest (many-to-one)
- → Document (many-to-one)
- → Person (many-to-one)

### AssessmentCriteria
**Schema.org:** `schema:Thing`
_Weighted criteria schema for property scoring and evaluation_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes |  |
| category | string | Yes | structure, condition, location, market, etc |
| description | string | Yes |  |
| weight | number | Yes | Weight percentage 0-100 |
| rubric | string | No | Scoring guide |
| applicability | string | Yes | required, optional, conditional |
| active | boolean | Yes |  |

**Relations:**
- → PropertyAssessment (many-to-many)

### Assignment
**Schema.org:** `schema:AggregateOffer`
_A specific work assignment or engagement of a freelancer with a client_
**Primary spec:** freelancers-zzp

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Assignment title |
| description | string | No | Assignment description |
| startDate | datetime | Yes | Assignment start date |
| endDate | datetime | No | Assignment end date |
| hourlyRate | number | No | Hourly rate for this assignment |
| status | string | Yes | Assignment status |

**Relations:**
- → Freelancer (many-to-one)
- → Organization (many-to-one)
- → TimeEntry (one-to-many)

### Auction
**Schema.org:** `schema:AuctionEvent`
_Auction format for competitive bidding with multiple formats and real-time bid tracking_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| auctionId | string | Yes | Unique auction identifier |
| auctionType | string | Yes | Type: english, dutch, sealedbid, reverse |
| startDate | datetime | Yes | Auction start time |
| endDate | datetime | Yes | Auction end time |
| status | string | Yes | Status: pending, active, closed, awarded |

**Relations:**
- → Lot (many-to-one)
- → Offer (one-to-many)

### AuditFinding
**Schema.org:** `schema:Report`
_Individual finding or observation from audit requiring management action or response_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| findingType | enum | Yes | Type: deficiency, observation, or finding |
| severity | enum | Yes | Priority: critical, high, medium, or low |
| description | text | Yes | Detailed finding description |
| remediation | text | No | Recommended remediation actions |
| dueDate | date | No | Target remediation completion date |

**Relations:**
- → Person (many-to-one)
- → ManagementLetter (many-to-one)

### AuditorStatement
**Schema.org:** `schema:Statement`
_An auditor statement registering and verifying grant compliance and authenticity for large subsidies_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| statementId | string | Yes | Unique statement identifier |
| verificationDate | datetime | Yes | Date of auditor verification |
| isVerified | boolean | Yes |  |
| findings | string | No | Audit findings and observations |
| verdict | string | No | Audit verdict: approved, rejected, conditional |

**Relations:**
- → Grant (many-to-one)
- → Person (many-to-one)
- → DigitalDocument (one-to-one)

### AwardDecision
**Schema.org:** `schema:Order`
_Award decision documenting bid evaluation outcome, selected supplier, and contract authorization_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Award decision identifier |
| description | string | No | Summary of award rationale |
| awardDate | date | Yes | Date the award was decided |
| awardedAmount | number | Yes | Contract value of awarded bid |
| currency | string | Yes | Currency code for contract value |
| justification | string | No | Evaluation summary and decision rationale |

**Relations:**
- → BidEvaluation (many-to-one)
- → SupplierBid (many-to-one)
- → Supplier (many-to-one)
- → Contract (one-to-one)

### AwardNotice
**Schema.org:** `schema:CreativeWork`
_Legal notice of award with publication deadline and standstill enforcement for compliance_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| noticeId | string | Yes | Unique award notice identifier |
| publicationDate | datetime | Yes | Date notice was published |
| legalDeadline | datetime | Yes | End of standstill period after publication |
| status | string | Yes | Status: draft, published, enforced, archived |
| archiveDate | datetime | No | Compliance archive date |

**Relations:**
- → AwardDecision (many-to-one)
- → Lot (many-to-many)

### BalanceSheet
**Schema.org:** `schema:Table`
_A financial statement showing assets, liabilities, and equity at a specific point in time_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportDate | datetime | Yes | Date of the balance sheet snapshot |
| totalAssets | number | No | Total assets in base currency |
| totalLiabilities | number | No | Total liabilities in base currency |
| totalEquity | number | No | Total equity in base currency |
| currency | string | Yes | Currency code for amounts |
| status | string | Yes | Status (draft, final, published) |

**Relations:**
- → FiscalYear (many-to-one)
- → Organization (many-to-one)
- → GeneralLedgerEntry (one-to-many)

### BankAccount
**Schema.org:** `schema:BankAccount`
_Schema.org BankAccount — standard vocabulary for bankaccount data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountName | string | Yes | Account display name |
| iban | string | Yes | IBAN number |
| bic | string | No | BIC/SWIFT code |
| bankName | string | No | Name of the bank |
| currency | string | Yes | Account currency |
| balance | number | No | Current balance |

### Bid
**Schema.org:** `schema:Offer`
_A supplier's response to a tender with proposed pricing and terms; includes sealed bid handling and multi-round bidding_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| bidNumber | string | Yes | Unique identifier for the bid |
| submissionDate | datetime | Yes | Date and time the bid was submitted |
| amount | number | No | Bid price or quote amount |
| currency | string | No | Currency code for the bid |
| status | string | Yes | Status: submitted, received, under review, evaluated, accepted, rejected, withdrawn |
| isSealed | boolean | No | Whether the bid is encrypted for sealed bid opening |
| evaluationScore | number | No | Numerical score assigned during evaluation |
| evaluationRank | number | No | Ranking relative to other bids (1=best) |
| notes | string | No | Evaluation comments or clarifications |

**Relations:**
- → Tender (many-to-one)
- → TenderLot (many-to-one)
- → Organization (many-to-one)
- → DigitalDocument (one-to-many)
- → BiddingRound (many-to-one)

### BidEvaluation
**Schema.org:** `schema:Event`
_Automated evaluation process for competitive bids with configurable scoring criteria and rules_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Evaluation process name or tender reference |
| description | string | No | Procurement description and requirements |
| startDate | date | Yes | Evaluation opening/start date |
| endDate | date | Yes | Evaluation closing/completion date |
| evaluationCriteria | json | Yes | Configurable criteria (price weighting, quality factors, technical specs) |
| scoringRules | json | No | Automated scoring formulas and calculation rules |
| minimumScore | number | No | Minimum threshold score to qualify for award |

**Relations:**
- → SupplierBid (one-to-many)
- → AwardDecision (one-to-one)

### BiddingRound
**Schema.org:** `schema:Thing`
_A round of bidding within a multi-round procurement process, supporting sequential RFQ, RFP, and reverse auction workflows_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| roundNumber | number | Yes | Sequential round number within the tender |
| roundType | string | No | Type: initial, clarification, final, auction, or negotiation |
| startDate | datetime | Yes | Start date of the bidding round |
| closingDate | datetime | Yes | Deadline for submissions in this round |
| status | string | Yes | Status: pending, open, closed, evaluated, completed |
| minBidReduction | number | No | Minimum bid reduction required for auction rounds |
| extensionEnabled | boolean | No | Whether extension of deadlines is allowed |

**Relations:**
- → Tender (many-to-one)
- → Bid (one-to-many)

### BlanketPurchaseOrder
**Schema.org:** `schema:Order`
_Master purchase order with authorized spend limit, scheduled release management, and consumption tracking for blanket purchasing arrangements_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| blanketPoNumber | string | Yes | Unique blanket PO identifier |
| validFrom | datetime | Yes | Blanket PO effective start date |
| validUntil | datetime | Yes | Blanket PO expiration date |
| totalAuthorizedAmount | number | Yes | Total authorized spend limit |
| consumedAmount | number | No | Amount spent against blanket PO to date |
| remainingAmount | number | No | Remaining authorized spend |
| releaseSchedule | array | No | Scheduled release dates and amounts |
| status | string | Yes | active, closed, cancelled |

**Relations:**
- → Organization (many-to-one)
- → ProcurementCatalog (many-to-one)
- → PurchaseOrder (one-to-many)
- → ApprovalRequest (many-to-one)

### Branch
**Schema.org:** `schema:LocalBusiness`
_Physical or organizational branch location for branch-wise tracking of payments, inventory, sales, and purchasing_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes |  |
| address | string | Yes |  |
| city | string | Yes |  |
| province | string | Yes |  |
| branchType | string | No | main office, warehouse, retail, etc |
| headcount | number | No |  |
| status | string | Yes | active, inactive, planned |
| establishedDate | datetime | No |  |

**Relations:**
- → Organization (many-to-one)
- → Person (many-to-one)

### Budget
_A financial plan allocating resources for a specific period, organization, and location_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| budgetName | string | Yes | Name or identifier of the budget |
| totalAmount | number | Yes | Total budgeted amount in the specified currency |
| startDate | datetime | Yes | Date when the budget becomes effective |
| endDate | datetime | Yes | Date when the budget expires |
| description | string | No | Detailed description or purpose of the budget |
| currency | string | Yes | Currency code (ISO 4217), defaults to EUR for Dutch organizations |
| budgetCategory | string | Yes | Category of the budget (e.g., operational expenses, capital expenses, revenue) |
| amountSpent | number | No | Current amount spent or committed against this budget |
| alertThreshold | number | No | Percentage (0-100) at which to trigger spending alerts |
| budgetType | string | No | Type of budget (fixed, flexible, rolling, zero-based) |
| fiscalYear | integer | Yes | Fiscal year this budget applies to (e.g., 2026) |
| costCenter | string | No | Cost center or department code for budget allocation |
| attachments | array | No | Supporting documents and justification files |

**Relations:**
- → Organization (many-to-one)
- → Location (many-to-one)
- → Person (many-to-one)
- → BudgetPeriod (many-to-one)
- → BudgetAllocation (one-to-many)
- → BudgetAmendment (one-to-many)
- → ExpenditureRequest (one-to-many)

### BudgetAllocation
_A subdivision of budget resources allocated to a specific department, funding source, or purpose_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| allocationNumber | string | Yes | Unique identifier for the allocation |
| amount | number | Yes | Allocated amount |
| status | string | Yes | Status: pending, approved, allocated, spent, closed |
| description | string | No | Details about the allocation |

**Relations:**
- → Budget (many-to-one)
- → FundingSource (many-to-one)
- → Organization (many-to-one)

### BudgetAmendment
_A proposed or executed change to an approved budget amount_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| amendmentNumber | string | Yes | Unique identifier for the amendment |
| originalAmount | number | Yes | Original budgeted amount |
| newAmount | number | Yes | Revised budget amount |
| reason | string | Yes | Reason for the amendment |
| status | string | Yes | Status: proposed, pending_approval, approved, rejected, executed |
| effectiveDate | datetime | No | When amendment takes effect |

**Relations:**
- → Budget (many-to-one)
- → ApprovalRequest (many-to-one)

### BudgetPeriod
_A defined time period for budget planning, such as fiscal year, calendar year, or quarter_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the period (e.g., 'FY2024', 'Q1 2024') |
| type | string | Yes | Period type: fiscal_year, calendar_year, quarter, month, or custom |
| startDate | datetime | Yes | Period start date |
| endDate | datetime | Yes | Period end date |
| fiscalYear | string | No | Associated fiscal year (e.g., '2024') |

**Relations:**
- → Budget (one-to-many)

### CallOffOrder
**Schema.org:** `schema:Order`
_An order placed against a blanket or framework agreement, with delivery scheduling and consumption tracking_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| callOffNumber | string | Yes | Unique call-off order number |
| orderDate | datetime | Yes | Date the call-off order was created |
| status | string | Yes | Status: draft, issued, accepted, in progress, partially delivered, delivered, closed |
| orderedQuantity | number | No | Total quantity ordered |
| consumedQuantity | number | No | Quantity already delivered or consumed |
| unitPrice | number | No | Unit price for items |
| totalAmount | number | No | Total order amount |
| currency | string | No | Currency code |
| deliverySchedule | array | No | Planned delivery dates and quantities |

**Relations:**
- → Order (many-to-one)
- → Organization (many-to-one)
- → Product (many-to-many)

### CashAccount
**Schema.org:** `schema:BankAccount`
_Track bank accounts, petty cash, and cash equivalents for liquidity management and multi-account consolidation_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountType | string | Yes | BankAccount, PettyCash, or CashEquivalent |
| accountCode | string | Yes | Internal GL account code |
| riskLevel | string | No | Low, Medium, High |

**Relations:**
- → Organization (many-to-one)

### CatalogItem
**Schema.org:** `schema:Product`
_Individual product or service in a procurement catalog with pricing, availability, lead time, and purchase price information_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| itemCode | string | Yes | Unique item code within catalog |
| itemName | string | Yes | Display name of the item |
| description | string | No | Detailed item description |
| basePrice | number | Yes | Base unit price |
| unit | string | Yes | Pricing unit: piece, kg, liter, hour, etc |
| minimumQuantity | number | No | Minimum order quantity |
| leadTime | number | No | Delivery lead time in days |
| status | string | Yes | active, discontinued |
| validFrom | datetime | No |  |
| validUntil | datetime | No |  |

**Relations:**
- → ProcurementCatalog (many-to-one)
- → Product (many-to-one)
- → PricingRule (one-to-many)

### ChargebackDispute
**Schema.org:** `schema:Service`
_A chargeback dispute tracking status, evidence, and resolution of payment disputes and chargebacks_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| disputeNumber | string | Yes | Unique dispute identifier |
| chargebackReference | string | Yes | Associated chargeback reference from payment processor |
| status | string | Yes | Status: filed, under-review, resolved, won, or lost |
| filedDate | datetime | Yes | Date the dispute was filed |
| resolutionDate | datetime | No | Date the dispute was resolved |
| disputeAmount | number | Yes | Amount in dispute |
| disputeReason | string | Yes | Reason for the chargeback |

**Relations:**
- → Payment (many-to-one)
- → Organization (many-to-one)
- → Document (one-to-many)
- → Person (many-to-one)

### ComplianceAssessment
**Schema.org:** `schema:QualitativeRating`
_Assessment of EU Directive 2014/24/EU compliance for procurement activities_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assessmentNumber | string | Yes | Unique assessment reference number |
| assessmentDate | datetime | Yes | Date of compliance assessment |
| complianceStatus | string | Yes | Status: compliant, non-compliant, partial, pending |
| riskLevel | string | Yes | Risk assessment: low, medium, high, critical |
| findings | array | No | List of compliance findings or violations |
| recommendedActions | string | No | Recommended corrective actions |

**Relations:**
- → PurchaseOrder (many-to-one)
- → Organization (many-to-one)
- → ComplianceRisk (one-to-many)

### ComplianceAudit
**Schema.org:** `schema:Event`
_A formal compliance audit documenting findings, risks, and remediation tracking with management letter outcomes_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| auditNumber | string | Yes | Unique audit number |
| auditType | string | Yes | Type of audit: internal, external, or regulatory |
| status | string | Yes | Audit status: planned, in-progress, completed, or draft |
| startDate | datetime | Yes | Audit start date |
| endDate | datetime | No | Audit completion date |
| scope | string | No | Audit scope and objectives |

**Relations:**
- → AuditFinding (one-to-many)
- → ManagementLetter (one-to-one)
- → Organization (many-to-one)
- → Document (one-to-many)

### ComplianceDocument
**Schema.org:** `schema:DigitalDocument`
_Audit evidence and compliance documentation (policies, procedures, attestations)_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| complianceArea | string | Yes | Compliance domain (e.g., accounting, GDPR, tax, labor) |
| category | enum | Yes | Type: policy, procedure, evidence, or attestation |
| required | boolean | Yes | Is this document mandatory |
| expiryDate | date | No | Review or validity expiration date |

**Relations:**
- → Person (many-to-one)
- → Organization (many-to-one)

### ComplianceReport
**Schema.org:** `schema:Report`
_Analytics report tracking obligation and payment compliance metrics, supporting 99% on-time settlement performance goal and PowerBI dashboards_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportPeriod | string | Yes | Reporting period (e.g., 2026-Q1 or 2026-01) |
| generatedDate | date | Yes | Date report was generated |
| complianceRate | number | Yes | Percentage of obligations settled on-time (0-100) |
| totalObligations | integer | Yes | Total obligations in period |
| onTimeObligations | integer | Yes | Obligations settled by due date |
| overdueObligations | integer | No | Obligations settled after due date |
| totalAmount | MonetaryAmount | No | Total financial value of all obligations |
| averagePaymentDays | number | No | Average days to payment after due date (negative = early) |
| powerBiUrl | string | No | URL to Power BI dashboard for this report |

**Relations:**
- → Obligation (one-to-many)
- → Payment (one-to-many)
- → SettlementDecision (one-to-many)

### ComplianceRisk
**Schema.org:** `schema:Report`
_Risk assessment for regulatory, operational, and compliance threats with mitigation tracking_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| riskName | string | Yes | Risk title |
| riskCategory | enum | Yes | Category: regulatory, operational, financial, or strategic |
| description | text | Yes | Risk description and context |
| probability | enum | Yes | Likelihood: remote, low, medium, high, or certain |
| impact | enum | Yes | Potential impact: negligible, minor, moderate, major, or critical |
| mitigations | text | No | Controls and mitigation strategies |

**Relations:**
- → Organization (many-to-one)
- → ComplianceDocument (one-to-many)

### ConsentRecord
**Schema.org:** `schema:Action`
_A record of regulatory consent (PSD2, GDPR, etc.) with renewal tracking and compliance management_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| consentNumber | string | Yes | Unique consent identifier |
| consentType | string | Yes | Type of consent: PSD2, GDPR, or other |
| status | string | Yes | Status: active, revoked, expired, or pending-renewal |
| grantedDate | datetime | Yes | Date consent was granted |
| expiryDate | datetime | No | Date consent expires |
| renewalDueDate | datetime | No | Date when renewal is due |
| scope | string | No | Scope and purpose of granted consent |

**Relations:**
- → Person (many-to-one)
- → Organization (many-to-one)
- → Document (one-to-many)

### ConsolidatedReport
**Schema.org:** `schema:Report`
_A consolidated financial report combining data from multiple organizations with automatic inter-company eliminations_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportNumber | string | Yes | Unique identifier for the consolidated report |
| reportDate | datetime | Yes | Date of the consolidated report |
| consolidationMethod | string | Yes | Method used for consolidation |
| status | string | Yes | Status (draft, finalized, published, archived) |
| eliminationsApplied | boolean | No | Whether inter-company eliminations have been applied |
| isPublished | boolean | No | Whether the consolidated report is published |

**Relations:**
- → ConsolidationGroup (many-to-one)
- → FiscalYear (many-to-one)
- → BalanceSheet (one-to-many)

### ConsolidationGroup
**Schema.org:** `schema:Organization`
_A group of organizations consolidated together for consolidated financial reporting across administrations_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the consolidation group |
| consolidationMethod | string | Yes | Method used for consolidation (full, proportional, equity) |
| status | string | Yes | Status of the consolidation group |
| parentOrganization | string | No | Parent organization identifier |
| eliminationRules | object | No | Consolidation elimination rules for inter-company transactions |

**Relations:**
- → Organization (one-to-many)
- → ConsolidatedReport (one-to-many)

### Contract
**Schema.org:** `schema:DigitalDocument`
_Legal contract document with spend tracking, approval routing, and full-text search capability_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| contractNumber | string | Yes | Unique contract reference number |
| description | string | Yes | Contract description and summary |
| contractValue | number | Yes | Total contract value in specified currency |
| currency | string | Yes | Currency code (e.g., EUR) |
| startDate | datetime | Yes | Contract start date |
| endDate | datetime | Yes | Contract end date |
| contractType | string | Yes | Type of contract (e.g., Service, Supply, Lease, Maintenance) |
| counterpartyName | string | Yes | Name of the supplier, vendor, or counterparty |
| counterpartyNumber | string | No | Supplier/customer registration or reference number |
| paymentTerms | string | Yes | Payment terms (e.g., Net 30, 2/10 Net 30) |
| invoiceFrequency | string | Yes | Billing frequency (e.g., monthly, quarterly, annual, one-time) |
| taxPercentage | number | Yes | Applicable VAT or tax percentage |
| contractDocument | file | No | Signed contract document or PDF |
| nextReviewDate | datetime | No | Date for next contract review or renewal consideration |
| vestigingsnummer | string | No | Dutch business establishment number (vestigingsnummer KvK) |
| renewalOption | boolean | No | Whether contract has automatic renewal or renewal option |
| bankAccount | string | No | Counterparty IBAN for payment processing |

**Relations:**
- → ContractParty (many-to-many)
- → ApprovalRoute (many-to-one)
- → ContractRedline (one-to-many)
- → ContractSpendRecord (one-to-many)

### ContractClause
**Schema.org:** `schema:Thing`
_Reusable clause with version control for contract assembly and updates_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Clause name or identifier |
| text | string | Yes | Full clause text and provisions |
| version | number | Yes | Clause version number |
| category | string | No | Category (Payment, Liability, Termination, IP, etc.) |
| status | string | Yes | Status (active, archived, deprecated) |
| createdDate | datetime | Yes | Date clause was created |

**Relations:**
- → ContractTemplate (many-to-one)

### ContractMilestone
**Schema.org:** `schema:Event`
_Milestone within contract lifecycle with KPI targets and performance monitoring_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the milestone |
| description | string | No | Description of milestone objectives |
| dueDate | datetime | Yes | Target completion date |
| status | string | Yes | Status (pending, in-progress, completed, at-risk, blocked) |
| kpiTarget | number | No | Target KPI or metric value |
| actualValue | number | No | Actual KPI value achieved |

**Relations:**
- → Contract (many-to-one)
- → ContractObligation (one-to-many)

### ContractModification
**Schema.org:** `schema:UpdateAction`
_Amendments, changes, and modifications to contracts with audit trail and approval_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of the modification or amendment |
| description | string | Yes | Details of what was modified |
| modificationDate | datetime | Yes | Date modification was made |
| type | string | Yes | Modification type (amendment, extension, material-change, termination-notice) |
| status | string | Yes | Status (draft, proposed, approved, rejected, executed) |
| reason | string | No | Business reason for modification |

**Relations:**
- → Contract (many-to-one)
- → Person (many-to-one)
- → DigitalDocument (many-to-one)

### ContractObligation
**Schema.org:** `schema:Action`
_Tracked obligations and deliverables within a contract with completion status_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the obligation or deliverable |
| description | string | No | Detailed description of deliverables and requirements |
| dueDate | datetime | Yes | Due date for the obligation |
| status | string | Yes | Status (pending, in-progress, completed, overdue) |
| priority | string | No | Priority (low, medium, high, critical) |
| completionDate | datetime | No | Actual completion date |

**Relations:**
- → Contract (many-to-one)
- → Person (many-to-one)
- → ContractMilestone (many-to-one)

### ContractParty
**Schema.org:** `schema:Organization`
_Organization party to a contract with banking and contact details_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Legal name of organization |
| kvkNumber | string | No | Dutch Chamber of Commerce registration number |
| vatID | string | No | VAT identification number |
| email | string | No | Organization email address |
| iban | string | No | International Bank Account Number for payments |
| role | string | No | Party role (Vendor, Service Provider, Client) |

### ContractPerformance
**Schema.org:** `schema:Thing`
_Performance metrics, KPIs, and analytics for contract monitoring and risk assessment_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| metricName | string | Yes | Name of the performance metric or KPI |
| metricValue | number | Yes | Current or actual metric value |
| targetValue | number | No | Target or baseline value |
| reportingDate | datetime | Yes | Date of the performance measurement |
| status | string | Yes | Performance status (on-track, at-risk, exceeded, failed) |
| notes | string | No | Context or analysis notes |

**Relations:**
- → Contract (many-to-one)
- → Report (many-to-one)

### ContractRedline
**Schema.org:** `schema:DigitalDocument`
_AI-powered and manual suggested changes to contract terms with risk severity_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| description | string | Yes | Description of suggested change or issue |
| originalText | string | No | Original contract text being flagged |
| suggestedText | string | No | Proposed replacement text |
| lineNumber | number | No | Line number in contract |
| aiGenerated | boolean | No | True if suggested by automated redlining system |
| severity | string | No | Risk level (Low, Medium, High, Critical) |

**Relations:**
- → Contract (many-to-one)

### ContractRenewal
**Schema.org:** `schema:Event`
_Renewal period management with proactive notification and renegotiation tracking_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| renewalDate | datetime | Yes | Date when renewal becomes effective |
| notificationDate | datetime | Yes | Date when renewal notification must be sent |
| negotiationDeadline | datetime | No | Deadline for renewal negotiations |
| status | string | Yes | Renewal status (pending, in-negotiation, approved, completed, cancelled) |
| automaticRenewal | boolean | No | Whether contract auto-renews without action |
| renewalTerms | string | No | Conditions or terms for renewal |

**Relations:**
- → Contract (many-to-one)
- → Organization (many-to-one)

### ContractSpendRecord
**Schema.org:** `schema:Order`
_Invoice and payment record for contract spend dashboard and financial tracking_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoiceNumber | string | Yes | Unique invoice identifier |
| invoiceDate | date | Yes | Date invoice was issued |
| amount | number | Yes | Invoice amount |
| currency | string | No | ISO 4217 currency code |
| paymentDate | date | No | Date payment was made |
| paymentTerms | string | No | Payment terms (e.g., Net 30) |
| description | string | No | Invoice line items and details |

**Relations:**
- → Contract (many-to-one)
- → ContractParty (many-to-one)

### ContractTemplate
**Schema.org:** `schema:CreativeWork`
_Reusable template for contract authoring with predefined structure and clause library_
**Primary spec:** contract-lifecycle-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the contract template |
| description | string | No | Purpose and use cases for this template |
| category | string | No | Contract type (Service, Purchase, Employment, NDA, etc.) |
| templateContent | string | Yes | Template structure and markup |
| status | string | Yes | Template status (active, archived, deprecated) |
| createdDate | datetime | Yes | Date template was created |

**Relations:**
- → ContractClause (one-to-many)
- → Organization (many-to-one)

### Corporation
**Schema.org:** `schema:Organization`
_A registered Dutch business entity (BV, NV, eenmanszaak, CV) with independent tax and legal obligations. Core entity for multi-entity management._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Official registered business name |
| tradeName | string | No | Trading name if different from legal name |
| kvkNumber | string | Yes | Dutch Chamber of Commerce (KvK) registration number |
| vatID | string | No | Dutch VAT number (BTW-nummer) |
| iban | string | No | Primary business bank account IBAN |
| businessType | string | Yes | Legal form: eenmanszaak, CV, BV, NV, CVOA, Vennootschap onder firma |
| foundationDate | date | Yes | Official business establishment date |
| dissolutionDate | date | No | Date business was closed (if applicable) |

**Relations:**
- → Shareholder (one-to-many)
- → Administration (one-to-many)
- → JointVenture (many-to-many)

### CostAllocation
**Schema.org:** `schema:Offer`
_Transaction allocating or distributing costs from one cost center to another, with version control for model changes and multi-dimensional analysis_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Description or name of the allocation |
| allocationDate | datetime | Yes | Effective date of the allocation |
| sourceAmount | number | Yes | Total amount to allocate |
| allocationPercentage | number | No | Percentage of source amount allocated |
| allocationAmount | number | No | Calculated allocated amount |
| period | string | Yes | Period type: monthly, quarterly, yearly |
| status | string | Yes | Status: draft, approved, or allocated |
| version | number | Yes | Version number for change tracking and rollback |
| description | string | No |  |

**Relations:**
- → CostCenter (many-to-one)
- → CostCenter (many-to-one)

### CostCenter
**Schema.org:** `schema:Organization`
_A cost center for tracking, allocating, and analyzing departmental or functional expenses across the organization_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Unique cost center identifier |
| name | string | Yes | Name of the cost center |
| description | string | No | Detailed description of responsibilities and scope |
| status | string | Yes | Current status: active or inactive |
| budget | number | No | Allocated annual or periodic budget |
| createdDate | datetime | Yes | Date when cost center was created |

**Relations:**
- → Person (many-to-one)
- → Organization (many-to-one)

### CostProject
**Schema.org:** `schema:Project`
_Project or cost object for tracking time, materials, and costs on a project basis with budget monitoring and multi-dimensional reporting_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Unique project cost code |
| name | string | Yes | Project name |
| description | string | No | Project description and scope |
| budget | number | No | Total project budget |
| totalCost | number | No | Total costs incurred to date |
| startDate | datetime | Yes | Project start date |
| endDate | datetime | No | Project completion or planned end date |
| status | string | Yes | Status: active, closed, or archived |

**Relations:**
- → Organization (many-to-one)
- → CostCenter (many-to-one)

### CreditNote
**Schema.org:** `schema:Invoice`
_A document issued to reduce customer debt due to returns or corrections_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| creditNoteNumber | string | Yes | Unique credit note identifier |
| creditDate | datetime | Yes | Date when credit note was issued |
| totalAmount | number | Yes | Credit amount |
| reason | string | Yes | Reason for credit (return, correction, discount) |
| status | string | Yes | Credit note status |
| notes | string | No | Additional notes |

**Relations:**
- → Invoice (many-to-one)
- → Organization (many-to-one)
- → InvoiceLine (one-to-many)

### CurrencyBalance
**Schema.org:** `schema:Thing`
_Multi-currency balance tracking per account for foreign currency management_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| balanceId | string | Yes | Unique balance record identifier |
| currency | string | Yes | Currency code (ISO 4217) |
| balance | number | Yes | Current balance amount |
| previousBalance | number | No | Previous balance for variance tracking |
| lastUpdated | datetime | Yes | Last update timestamp |

**Relations:**
- → BankAccount (many-to-one)

### DebitNote
**Schema.org:** `schema:Invoice`
_A document issued to increase vendor debt for account adjustments_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| debitNoteNumber | string | Yes | Unique debit note identifier |
| debitDate | datetime | Yes | Date when debit note was issued |
| totalAmount | number | Yes | Debit amount |
| reason | string | Yes | Reason for debit |
| status | string | Yes | Debit note status |
| notes | string | No | Additional notes |

**Relations:**
- → Payee (many-to-one)

### Deduction
**Schema.org:** `schema:PriceSpecification`
_Payroll deduction such as taxes, social security, or garnishments_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| deductionType | string | Yes | Type of deduction (tax, social_security, garnishment, insurance) |
| amount | number | Yes | Deduction amount |
| description | string | No | Deduction description |
| reason | string | No | Reason for deduction |

**Relations:**
- → Payroll (many-to-one)

### Delegation
**Schema.org:** `schema:Action`
_A delegation of mandate authority from one signing authority to another for a specified period_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| delegationNumber | string | Yes | Unique delegation identifier |
| reason | string | No | Reason for delegation (e.g., out-of-office, temporary increase, absence) |
| startDate | datetime | Yes | Start date of the delegation |
| endDate | datetime | Yes | End date of the delegation |
| status | string | Yes | Status of delegation (active/revoked/expired) |
| revokedDate | datetime | No | Date when delegation was revoked |
| revokeReason | string | No | Reason for early revocation |

**Relations:**
- → SigningAuthority (many-to-one)
- → SigningAuthority (many-to-one)
- → Mandate (many-to-one)
- → DelegationRule (many-to-one)

### DelegationRule
**Schema.org:** `schema:Action`
_Rules for delegating approval tasks during out-of-office periods and escalation scenarios_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ruleId | string | Yes | Unique delegation rule identifier |
| ruleType | string | Yes | outOfOffice/escalation/substitute |
| delegateFrom | string | Yes | Person/User ID delegating approvals |
| delegateTo | string | Yes | Person/User ID receiving delegated tasks |
| startDate | datetime | Yes | When delegation starts |
| endDate | datetime | No | When delegation ends |
| scope | string | No | allApprovals or specificChain |
| status | string | No | active or inactive |
| escalationPriority | number | No | Priority order for escalation chain (1=first, 2=fallback, etc.) |

**Relations:**
- → Person (many-to-one)
- → Person (many-to-one)

### DepreciationSchedule
**Schema.org:** `schema:Thing`
_A detailed schedule defining depreciation method, rate, and yearly calculations for a fixed asset with automated computation_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| scheduleNumber | string | Yes | Unique identifier for the depreciation schedule |
| name | string | Yes | Name or description of the depreciation schedule |
| startDate | datetime | Yes | Start date of the depreciation period |
| endDate | datetime | Yes | End date of the depreciation period |
| depreciationMethod | string | Yes | Method used: linear, declining-balance, units-of-production |
| annualRate | number | Yes | Annual depreciation rate as a percentage or amount |
| totalDepreciationAmount | number | No | Total depreciation amount over the schedule period |
| status | string | Yes | Current status: planned, active, completed |

**Relations:**
- → FixedAsset (many-to-one)

### DigitalDocument
**Schema.org:** `schema:DigitalDocument`
_Schema.org DigitalDocument — standard vocabulary for digitaldocument data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Document name/title |
| documentType | string | Yes | Document type (contract, tender, report, etc.) |
| description | string | No | Document description |
| encodingFormat | string | No | MIME type (application/pdf, etc.) |
| contentSize | string | No | File size |

### Dividend
**Schema.org:** `schema:MonetaryAmount`
_Dividend payment or distribution to shareholders_
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| amount | number | Yes | Dividend amount per share or total in EUR |
| paymentDate | datetime | Yes | Date the dividend was or will be paid |
| declarationDate | datetime | No | Date the dividend was declared |
| fiscalYear | string | No | Fiscal year for which dividend is paid |
| frequency | string | No | Annual, semi-annual, quarterly, one-time, etc. |
| status | string | Yes | Pending, paid, cancelled, etc. |

**Relations:**
- → Shareholder (many-to-one)
- → Entity (many-to-one)
- → Payment (many-to-one)

### Document
**Schema.org:** `schema:DigitalDocument`
_Managed document with version control for bookkeeping (invoices, contracts, receipts)_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Document title |
| documentType | string | Yes | Category (invoice, receipt, contract, amendment) |
| description | text | No | Document summary |
| encodingFormat | string | No | File format (PDF, DOCX, JPG) |
| contentSize | integer | No | File size in bytes |
| fileLocation | string | No | Storage path or repository URL |

**Relations:**
- → PurchaseOrder (many-to-one)
- → Person (many-to-one)

### DunningNotice
**Schema.org:** `schema:Event`
_Follow-up notice for overdue unpaid transactions, escalating through dunning levels toward legal action._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| noticeDate | date | Yes | Date when dunning notice was issued |
| dueDate | date | Yes | New payment deadline in the notice |
| reminderLevel | enum | Yes | Escalation level of dunning process |
| amount | MonetaryAmount | Yes | Outstanding amount due |
| eventStatus | enum | Yes | Status of the dunning notice |
| description | string | No | Custom message or legal terms included |

**Relations:**
- → APTransaction (many-to-one)
- → Payee (many-to-one)

### Entitlement
_Grant of access or permission to use specific features, resources, or data within the system_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Entitlement name or what is entitled |
| description | string | No | Detailed description of what is entitled |
| status | string | Yes | Entitlement status (active, pending, expired, revoked) |
| grantedAt | datetime | Yes | Date entitlement was granted |
| expiresAt | datetime | No | Date entitlement expires |

**Relations:**
- → User (many-to-one)

### Entity
**Schema.org:** `schema:Organization`
_A legal entity or business managed within a multi-entity system_
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Legal name of the entity |
| registrationNumber | string | Yes | Company registration number (KvK) |
| taxId | string | Yes | Tax identification number (VAT/BTW ID) |
| businessType | string | No | Business form (BV, NV, Eenmanszaak, etc.) |
| foundingDate | datetime | No | Date of establishment |
| country | string | No | Country of incorporation |
| status | string | Yes | Active, inactive, dissolved, etc. |

**Relations:**
- → Organization (many-to-one)
- → Person (one-to-many)

### EvaluationCriterion
**Schema.org:** `schema:Thing`
_Evaluation criteria with weights and scoring formulas documenting award methodology_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| criterionId | string | Yes | Unique criterion identifier |
| name | string | Yes | Criterion name (price, quality, delivery time, etc) |
| weight | number | Yes | Weight in total score 0-100 |
| maxScore | number | Yes | Maximum achievable score for this criterion |
| scoringFormula | string | No | Automated scoring formula or reference |
| sequenceNumber | number | No | Display order in evaluation |

### Event
**Schema.org:** `schema:Event`
_Schema.org Event — standard vocabulary for event data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Event/tender name |
| description | string | No | Description |
| startDate | datetime | Yes | Start/publication date |
| endDate | datetime | No | End/deadline date |
| eventStatus | string | Yes | Status (active, closed, cancelled) |
| maximumAttendeeCapacity | integer | No | Max participants/lots |

### ExemptionCertificate
**Schema.org:** `schema:DigitalDocument`
_Tax exemption credential (research, export, environmental, humanitarian). Stores certificate metadata, validity, and linked exemptions for workflow automation._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| certificateNumber | string | Yes | Official certificate ID from issuing authority |
| certificateType | enum | Yes | research, export, environmental, humanitarian, innovation, vat-reverse, other |
| issueDate | date | Yes | Certificate issuance date |
| expiryDate | date | No | Expiration date; null = perpetual |
| exemptionReason | string | Yes | Legal basis or reason code |
| documentURL | uri | No | Link to official document or scan |

**Relations:**
- → Organization (many-to-one)
- → TaxDeclaration (many-to-many)

### ExpenditureEscalation
**Schema.org:** `schema:Order`
_An expenditure request that exceeds the mandate ceiling and requires escalation to higher authority for approval_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| escalationNumber | string | Yes | Unique escalation identifier |
| totalAmount | number | Yes | Total expenditure amount |
| mandateLimit | number | Yes | The mandate ceiling that was exceeded |
| exceedingAmount | number | Yes | Amount by which expenditure exceeds mandate |
| reason | string | No | Justification for the expenditure above mandate |
| status | string | Yes | Status of escalation (pending/approved/rejected) |
| createdDate | datetime | Yes | Date the escalation was created |
| decisionDate | datetime | No | Date when escalation was approved or rejected |

**Relations:**
- → ApprovalRequest (many-to-one)
- → Mandate (many-to-one)
- → Person (many-to-one)

### ExpenditureRequest
_A request to spend funds from an allocated budget, requiring review and approval_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| requestNumber | string | Yes | Unique identifier for the request |
| amount | number | Yes | Requested expenditure amount |
| purpose | string | Yes | Purpose or description of the expenditure |
| status | string | Yes | Status: draft, submitted, approved, rejected, executed |
| requestDate | datetime | Yes | Date request was made |

**Relations:**
- → Budget (many-to-one)
- → ApprovalRequest (many-to-one)
- → Person (many-to-one)

### Expense
**Schema.org:** `schema:Invoice`
_Business expenditure with receipt documentation and reimbursement processing_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| expenseNumber | string | Yes | Unique expense identifier |
| expenseDate | datetime | Yes | Date when expense was incurred |
| amount | number | Yes | Expense amount |
| category | string | Yes | Expense category (travel, meals, supplies) |
| status | string | Yes | Expense status (submitted, approved, reimbursed) |
| approvalStatus | string | No | Approval workflow status |
| description | string | No | Expense description |

**Relations:**
- → Person (many-to-one)
- → Receipt (one-to-many)

### ExpenseCategory
**Schema.org:** `schema:Thing`
_A category or dimension for coding and tracking expenses, enabling multi-dimensional reporting by department, region, cost type, or other organizational structures_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Human-readable category name |
| code | string | Yes | Unique code used for automated coding and reporting |
| type | string | Yes | Category dimension: department, region, costType, project, costCenter, etc. |
| description | string | No | Description of this category |
| parentCode | string | No | Parent category code for hierarchical grouping |

**Relations:**
- → Organization (many-to-one)

### ExpenseClaim
**Schema.org:** `schema:Invoice`
_Expense claim submissions with receipt tracking, approval workflow, and reimbursement management_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| claimId | string | Yes | Unique expense claim identifier |
| submittedBy | string | Yes | Person/User ID who submitted the claim |
| totalAmount | number | Yes | Total amount claimed |
| currency | string | No | ISO 4217 currency code |
| status | string | No | draft/submitted/approved/rejected/reimbursed |
| description | string | No | Overall claim description and purpose |
| submittedDate | datetime | No | When the claim was submitted |
| approvalDueDate | datetime | No | Approval deadline |
| approvedDate | datetime | No | When the claim was approved |
| reimbursedDate | datetime | No | When reimbursement was made |
| reimbursementAmount | number | No | Final approved amount for reimbursement |
| attachments | array | No | File references for supporting receipts and documentation |

**Relations:**
- → Person (many-to-one)
- → ApprovalRequest (one-to-many)
- → Receipt (one-to-many)
- → Payment (many-to-one)

### ExpenseLineItem
**Schema.org:** `schema:Thing`
_A line item within an expense record with detailed coding for department allocation and cost center tracking_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lineNumber | string | Yes | Sequence number within the parent expense |
| amount | number | Yes | Amount for this line item |
| description | string | Yes | Description of the goods or services provided |
| department | string | No | Department code for cost allocation |
| costCenter | string | No | Cost center code for tracking and reporting |
| quantity | number | No | Quantity of items or units |

**Relations:**
- → Expense (many-to-one)
- → ExpenseCategory (many-to-one)

### ExpenseReport
**Schema.org:** `schema:Report`
_Spend and expense report by category with approval and budget tracking_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Report title |
| reportType | string | Yes | Report type: SPEND_ANALYSIS, EXPENSE_SUMMARY, BUDGET_VS_ACTUAL |
| period | string | Yes | Report period: MONTHLY, QUARTERLY, YEARLY |
| generatedAt | datetime | Yes | Report generation timestamp |
| totalAmount | number | Yes | Total spend amount |
| currency | string | Yes | Currency code (EUR) |
| expenseCategory | string | No | Primary expense category |
| approvalStatus | string | No | Approval status: DRAFT, SUBMITTED, APPROVED |
| budgetAmount | number | No | Budget amount for variance analysis |

**Relations:**
- → ProcurementOrder (many-to-many)
- → Supplier (many-to-many)

### FXExposure
**Schema.org:** `schema:MonetaryAmount`
_Track foreign exchange risk across currencies with current rates, valuations, and unrealized gains/losses_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| baseCurrency | string | Yes | EUR or company base currency |
| foreignCurrency | string | Yes | ISO 4217 code |
| exposureAmount | number | Yes | Amount in foreign currency |
| currentExchangeRate | number | Yes | Foreign/base rate |
| valuationDate | string | Yes | ISO 8601 rate snapshot date |
| unrealizedGainLoss | number | No | P&L in base currency |
| riskLevel | string | No | Low, Medium, High |

**Relations:**
- → CashAccount (many-to-one)
- → Organization (many-to-one)

### FinancialDecision
**Schema.org:** `schema:Report`
_Financial decision (approval, allocation, or payment authorization) auto-published to stakeholders_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| decisionType | string | Yes | Type: APPROVAL, ALLOCATION, DISBURSEMENT, or PAYMENT_AUTHORIZATION |
| amount | number | Yes | Financial amount in EUR |
| decisionDate | date | Yes | Date decision was made |
| approverName | string | Yes | Name of decision maker |
| approverRole | string | Yes | Role or title of decision maker |
| publicationDate | date | Yes | Date published to stakeholders |
| isAutoPublished | boolean | Yes | Whether automatically published without manual intervention |

**Relations:**
- → Organization (many-to-one)

### FinancialReport
**Schema.org:** `schema:Report`
_Exported financial statements (annual, management, or consolidated) generated for a fiscal year._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportType | string | Yes | Annual, Management, or Consolidated |
| reportFormat | string | Yes | Export format: PDF, Excel, XML, or JSON |
| reportStatus | string | No | Draft, Approved, or Published |
| generatedAt | dateTime | Yes | Timestamp of report generation |

**Relations:**
- → FiscalYear (many-to-one)

### FiscalYear
**Schema.org:** `schema:Event`
_An accounting period representing a fiscal year for financial reporting and regulatory compliance._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| year | integer | Yes | The fiscal year number (e.g., 2024) |
| startDate | date | Yes | The first day of the fiscal period |
| endDate | date | Yes | The last day of the fiscal period |
| isClosed | boolean | No | Whether the fiscal year is closed for amendments |
| closingDate | date | No | Date when the fiscal year was officially closed |

**Relations:**
- → FinancialReport (one-to-many)
- → JournalEntry (one-to-many)

### FixedAsset
**Schema.org:** `schema:Thing`
_A tangible business asset with long-term value subject to annual depreciation calculation and tracking_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assetNumber | string | Yes | Unique identifier for the fixed asset |
| name | string | Yes | Name of the fixed asset |
| assetType | string | Yes | Type of asset: equipment, vehicle, property, building, etc. |
| purchaseDate | datetime | Yes | Date when the asset was purchased |
| purchaseCost | number | Yes | Original acquisition cost of the asset |
| status | string | Yes | Current status: active, inactive, retired |
| location | string | No | Physical location of the asset |

**Relations:**
- → Organization (many-to-one)
- → DepreciationSchedule (one-to-many)

### FrameworkAgreement
**Schema.org:** `schema:Service`
_Framework agreement enabling mini-competition and direct award within procurement_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| agreementNumber | string | Yes | Unique framework agreement identifier |
| title | string | Yes | Framework agreement title |
| status | string | Yes | Status: active, expired, suspended, archived |
| awardDate | datetime | Yes | Date framework was awarded |
| expiryDate | datetime | Yes | Framework expiration date |
| minCompetitionThreshold | number | No | Minimum suppliers required for mini-competition |

**Relations:**
- → Supplier (many-to-many)
- → Contract (one-to-many)

### Freelancer
**Schema.org:** `schema:Person`
_A self-employed professional or contractor managing their own work and time_
**Primary spec:** freelancers-zzp

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| expertise | array | No | Professional expertise areas |
| hourlyRate | number | No | Default hourly billing rate |
| status | string | Yes | Freelancer status (active/inactive) |

**Relations:**
- → Person (many-to-one)
- → TimeEntry (one-to-many)
- → Assignment (one-to-many)

### FundAllocation
**Schema.org:** `schema:MonetaryAmount`
_Budget allocation and fund management for public sector spending with fiscal year tracking_
**Primary spec:** government-public-sector

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Fund or budget name |
| totalAmount | number | Yes | Total allocated amount in decimal format |
| currency | string | Yes | Currency code (EUR) |
| fiscalYear | integer | Yes | Fiscal year of allocation |
| availableAmount | number | Yes | Remaining available amount for allocation |
| allocationType | string | Yes | Type: operational, investment, grant, or subsidy |
| budgetCode | string | Yes | Government budget code reference |

**Relations:**
- → GovernmentEntity (many-to-one)
- → SpendingRecord (one-to-many)

### FundingSource
_A source of funds that can be allocated to budgets and expenditures_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the funding source |
| totalAmount | number | Yes | Total available funds |
| status | string | Yes | Status: active, inactive, depleted |
| description | string | No | Details about the funding source |

**Relations:**
- → BudgetAllocation (one-to-many)

### GeneralLedgerAccount
**Schema.org:** `schema:Product` _(deprecated — use `Account` with `schema:DefinedTerm` instead)_
_**DEPRECATED.** Superseded by the `Account` entry (bookkeeping-chart-of-accounts, 2026-05-18). Retained here for historical reference only. New register declarations MUST use `Account`. The `currentBalance` field is not carried forward — balance is computed from GL lines by the general ledger tier._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountNumber | string | Yes | The unique account code (e.g., 1000, 4100) |
| accountName | string | Yes | The descriptive account name |
| accountType | string | Yes | Account classification: Asset, Liability, Equity, Revenue, or Expense |
| currency | string | Yes | ISO 4217 currency code for the account |
| currentBalance | object | No | Current balance as {value, currency} following MonetaryAmount schema |

**Relations:**
- → JournalEntry (one-to-many)

### GeneralLedgerEntry
**Schema.org:** `schema:Thing`
_An individual entry in the general ledger representing a financial transaction with debit and credit amounts_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| entryDate | datetime | Yes | Date of the GL entry |
| accountNumber | string | Yes | General ledger account code |
| accountName | string | Yes | Name of the GL account |
| debitAmount | number | No | Debit amount in base currency |
| creditAmount | number | No | Credit amount in base currency |
| description | string | Yes | Description of the transaction |
| reference | string | No | Reference document number or transaction ID |
| status | string | Yes | Status (draft, posted, reversed) |

**Relations:**
- → FiscalYear (many-to-one)
- → Organization (many-to-one)
- → APTransaction (many-to-one)

### GoodsReceipt
**Schema.org:** `schema:Thing`
_Receipt and verification of goods delivered at multiple locations with delivery confirmation_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| receiptNumber | string | Yes | Unique goods receipt identifier |
| receivedDate | datetime | Yes | Date goods were received |
| location | string | Yes | Physical receiving location or site |
| quantity | number | Yes | Quantity of items received |
| notes | string | No | Quality notes, damage, or discrepancies |
| signatureRequired | boolean | No | Whether signature is required for delivery |
| status | string | Yes | Receipt status (draft, received, verified, closed) |

**Relations:**
- → PurchaseOrder (many-to-one)
- → InventoryStock (many-to-many)
- → Organization (many-to-one)

### GovernmentEntity
**Schema.org:** `schema:Organization`
_Dutch government organization with GBA/BRP integration and CCH research access for public sector bookkeeping_
**Primary spec:** government-public-sector

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Official legal name of the government entity |
| kvkNumber | string | No | Dutch Chamber of Commerce registration number |
| bsnNumber | string | No | Citizen Service Number for GBA linking |
| brkNumber | string | No | Land Registry number for BRP linking |
| govLevel | string | Yes | Government level: municipality, province, national, or waterboard |
| cchAccessCode | string | No | Central Code Bank (CCH) research access identifier |
| email | string | No | Organization contact email |
| telephone | string | No | Organization contact telephone |

**Relations:**
- → FundAllocation (one-to-many)
- → SpendingRecord (one-to-many)
- → SubmissionDossier (one-to-many)

### Grant
**Schema.org:** `schema:Grant`
_A financial grant or subsidy awarded to an organization for specified purposes under a subsidy scheme_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| grantId | string | Yes | Unique grant identifier |
| name | string | Yes | Grant name |
| awardedAmount | number | Yes | Amount awarded |
| awardDate | datetime | Yes | Date grant was awarded |
| status | string | Yes | Grant status: active, completed, suspended, revoked |
| accountingStandard | string | No | Governmental accounting standard applied |
| isSISAEligible | boolean | No | Eligible for Single Information Single Audit |

**Relations:**
- → SubsidyScheme (many-to-one)
- → Organization (many-to-one)
- → GrantPortfolio (many-to-one)

### GrantPortfolio
**Schema.org:** `schema:Collection`
_A managed collection of grants for organizational tracking, compliance monitoring, and concentration risk analysis_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| portfolioId | string | Yes | Unique portfolio identifier |
| name | string | Yes | Portfolio name |
| description | string | No |  |
| totalGrantValue | number | No | Total value of all grants |
| complianceStatus | string | No | Compliance status: compliant, non-compliant, under-review |
| concentrationRiskLevel | string | No | Risk level: low, medium, high |
| lastAuditDate | datetime | No |  |

**Relations:**
- → Organization (many-to-one)
- → Grant (one-to-many)

### IntercompanyTransaction
**Schema.org:** `schema:FinancialProduct`
_Transaction between related entities for transfer pricing, loans, or intercompany netting_
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionDate | datetime | Yes | Date of the transaction |
| amount | number | Yes | Transaction amount in EUR |
| type | string | Yes | Service fee, goods transfer, loan, transfer pricing, netting, etc. |
| description | string | No | Transaction description and purpose |
| reference | string | No | Reference number or invoice number |
| interestRate | number | No | Interest rate if applicable |
| status | string | Yes | Pending, completed, settled, cancelled, etc. |

**Relations:**
- → Entity (many-to-one)
- → Entity (many-to-one)
- → APTransaction (many-to-one)

### InventoryItem
**Schema.org:** `schema:Product`
_Product tracked in inventory with stock levels and sourcing information_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Product name |
| sku | string | Yes | Stock keeping unit identifier |
| description | string | No | Detailed product description |
| category | string | Yes | Product category for spend management |
| unitPrice | number | Yes | Unit purchase price |
| currency | string | Yes | Currency code (EUR) |
| unitCode | string | No | Unit of measure (ST, KG, L, etc) |
| taxRate | number | No | Applicable VAT percentage |
| currentStock | number | Yes | Current quantity in stock |
| minimumStock | number | No | Minimum stock level for reordering |
| reorderQuantity | number | No | Standard quantity to order |
| storageLocation | string | No | Physical storage location code |

**Relations:**
- → Supplier (many-to-one)
- → ProcurementOrder (many-to-many)

### InventoryStock
**Schema.org:** `schema:Thing`
_Stock levels, inventory tracking, and reorder management by location_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| sku | string | Yes | Stock Keeping Unit identifier |
| quantity | number | Yes | Current stock quantity |
| reorderLevel | number | No | Minimum quantity threshold for reorder trigger |
| reorderQuantity | number | No | Standard reorder quantity |
| location | string | No | Physical storage location or warehouse |
| unitCost | number | No | Cost per unit |
| lastRestockDate | datetime | No | Date of last stock replenishment |
| status | string | Yes | Inventory status (active, inactive, discontinued) |

**Relations:**
- → Product (many-to-one)
- → Organization (many-to-one)

### InventoryValuation
**Schema.org:** `schema:Product`
_Valuation of on-hand inventory items using cost accounting methods such as FIFO or average cost for P&L and balance sheet reporting_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| quantity | number | Yes | Quantity of items currently in stock |
| unitCost | number | Yes | Cost per unit under the selected valuation method |
| totalValue | number | Yes | Total inventory value (quantity × unitCost) |
| valuationMethod | string | Yes | Costing method: FIFO, average, specific, or weighted average |
| date | datetime | Yes | Date of valuation or inventory count |
| warehouse | string | No | Warehouse or storage location identifier |
| status | string | Yes | Status: active, adjusted, or obsolete |

**Relations:**
- → Product (many-to-one)
- → CostCenter (many-to-one)

### Investment
**Schema.org:** `schema:FinancialProduct`
_Investment or capital contribution in an entity with terms and expected returns_
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| amount | number | Yes | Investment amount in EUR |
| investmentDate | datetime | Yes | Date the investment was made |
| investmentType | string | Yes | Equity, debt, convertible, preferred, etc. |
| expectedReturn | number | No | Expected return percentage or amount |
| maturityDate | datetime | No | Expected maturity or exit date |
| terms | string | No | Investment terms and conditions |

**Relations:**
- → Entity (many-to-one)
- → Person (many-to-one)

### Invoice
**Schema.org:** `schema:DigitalDocument`
_Financial document detailing goods/services provided and creating an obligation for payment_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| invoiceNumber | string | Yes | Unique invoice identifier (Dutch: factuurnummer) |
| invoiceDate | datetime | Yes | Date the invoice was issued (Dutch law requires this) |
| dueDate | datetime | Yes | Payment deadline date |
| grossAmount | number | Yes | Total amount including VAT |
| vatAmount | number | Yes | Value Added Tax amount |
| netAmount | number | Yes | Amount excluding VAT (gross - vat) |
| vatRate | number | Yes | VAT percentage (e.g., 21, 9, 6, 0 for Dutch standard rates) |
| currency | string | Yes | ISO 4217 currency code (e.g., EUR) |
| creditor | object | Yes | Issuing company (supplier/seller) |
| recipient | object | Yes | Receiving company (customer/debtor) |
| lineItems | array | Yes | Invoice line items with description, quantity, unit price, amount |
| paymentTerms | string | Yes | Payment conditions (e.g., net 30 days, prepayment) |
| documentFormat | string | Yes | File format (e.g., PDF, XML, UBL) |
| paymentMethod | string | No | Payment method (e.g., SEPA transfer, bank transfer, direct debit) |
| reference | string | No | Purchase order number or reference number |
| attachments | array | No | Supporting documents or file references (PDF, receipt, etc.) |

**Relations:**
- → Obligation (one-to-one)
- → Payment (one-to-many)

### InvoiceLine
**Schema.org:** `schema:InvoiceItem`
_A line item detailing goods or services on an invoice_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lineNumber | number | Yes | Sequential line number |
| description | string | Yes | Item description |
| quantity | number | Yes | Quantity of items |
| unitPrice | number | Yes | Price per unit |
| lineAmount | number | Yes | Total line amount before tax |
| tax | number | No | Tax on line item |
| unit | string | No | Unit of measurement |

**Relations:**
- → Invoice (many-to-one)
- → Product (many-to-one)

### JointVenture
**Schema.org:** `schema:Organization`
_Formal partnership or joint venture between multiple corporations with shared profits/losses. Enables joint venture management across the multi-entity structure._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Official legal name of the joint venture |
| kvkNumber | string | No | Chamber of Commerce registration number if formally registered |
| vatID | string | No | VAT number if applicable |
| startDate | date | Yes | Date joint venture was formed |
| endDate | date | No | Date joint venture was dissolved |
| managingPartner | string | No | Lead partner responsible for operations |
| profitDistributionMethod | string | Yes | Distribution method: equal, proportional to investment, or custom |

**Relations:**
- → Corporation (many-to-many)

### JournalEntry
_A balanced transaction record affecting two or more GL accounts (debits equal credits)._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| entryDate | datetime | Yes | Date of the journal entry |
| entryNumber | string | Yes | Unique sequential journal entry number |
| description | string | Yes | Transaction description |
| debitAmount | number | Yes | Debit amount in EUR |
| creditAmount | number | Yes | Credit amount in EUR |
| isBalanced | boolean | Yes | Whether debits equal credits |
| accountCode | string | Yes | General ledger account number |
| journalCode | string | Yes | Journal type (sales, bank, cash, general, etc.) |
| reference | string | No | External reference (invoice, check, or document number) |
| vatAmount | number | No | VAT/BTW amount (21% standard, 9% reduced, etc.) |
| departmentCode | string | No | Cost center or department code |
| memo | string | No | Additional notes or clarification |

**Relations:**
- → GeneralLedgerAccount (many-to-many)
- → FiscalYear (many-to-one)

### LiquidityForecast
**Schema.org:** `schema:Report`
_Daily/weekly/monthly cash flow projections for liquidity planning, including inflow/outflow/net position_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| period | string | Yes | Daily, Weekly, or Monthly |
| forecastDate | string | Yes | ISO 8601 generation date |
| projectionDays | integer | Yes | Days ahead to forecast |
| projectedInflow | number | Yes | Expected cash in |
| projectedOutflow | number | Yes | Expected cash out |
| netProjection | number | Yes | Inflow minus outflow |
| currency | string | Yes | ISO 4217 code |
| confidence | string | No | Low, Medium, High |

**Relations:**
- → CashAccount (many-to-one)
- → Organization (many-to-one)

### Location
**Schema.org:** `schema:Place`
_A physical or geographic location for multi-site budget allocation and tracking_
**Primary spec:** budget-planning-control

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Location name |
| code | string | No | Location code or identifier |
| address | string | No | Physical address |
| region | string | No | Geographic region |

**Relations:**
- → Organization (many-to-one)
- → Budget (one-to-many)

### Lot
**Schema.org:** `schema:Product`
_Grouping of items in procurement process for evaluation and award at lot level_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lotNumber | string | Yes | Unique lot identifier |
| description | string | Yes | Description of lot contents and requirements |
| status | string | Yes | Status: draft, published, awarded, closed |
| estimatedValue | number | No | Estimated contract value in currency units |

**Relations:**
- → BidEvaluation (one-to-many)
- → AwardDecision (one-to-one)

### ManagementLetter
**Schema.org:** `schema:DigitalDocument`
_Auditor communication documenting findings and observations from annual audits_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| auditDate | date | Yes | Date of the audit |
| auditScope | string | Yes | Scope of audit (e.g., annual financial statements 2025) |
| auditorName | string | Yes | Auditing firm or auditor name |
| findings | text | No | Summary of audit findings |

**Relations:**
- → Organization (many-to-one)
- → AuditFinding (one-to-many)

### Mandate
**Schema.org:** `schema:DigitalDocument`
_Electronic authorization granting a person or organization the right to perform financial transactions on behalf of another_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| mandateNumber | string | Yes | Unique identifier for the mandate |
| mandateType | string | Yes | Type of mandate: SEPA Direct Debit, domestic transfer, signing authority, etc. |
| granteeId | string | Yes | ID of person/organization receiving authority |
| grantorId | string | Yes | ID of person/organization granting authority |
| validFrom | date | Yes | Effective date of mandate |
| validThrough | date | No | Expiration date of mandate |
| maximumAmount | decimal | No | Maximum transaction amount in base currency |
| currency | string | Yes | ISO 4217 currency code |
| scheme | string | Yes | Reference to MandateScheme |
| documentHash | string | No | Hash of supporting document for audit trail |

**Relations:**
- → MandateScheme (many-to-one)
- → MandateRequest (one-to-many)

### MandateAuditLog
**Schema.org:** `schema:Event`
_Audit log tracking all changes, delegations, approvals, and usage of a mandate for compliance and historical review_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| logEntryNumber | string | Yes | Unique log entry identifier |
| action | string | Yes | Action performed (created/modified/delegated/approved/revoked/archived/violated) |
| actionDate | datetime | Yes | Timestamp of the action |
| description | string | Yes | Human-readable description of the action |
| details | object | No | Additional metadata about the action |

**Relations:**
- → Mandate (many-to-one)
- → Person (many-to-one)

### MandateRequest
**Schema.org:** `schema:Order`
_Request to create, modify, or temporarily increase a mandate authorization_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| requestNumber | string | Yes | Unique request identifier |
| requestType | string | Yes | Type: new-mandate, increase, modify, revoke |
| relatedMandateId | string | No | Reference to existing Mandate if modifying |
| requestedAmount | decimal | No | Requested or new limit amount |
| currency | string | No | ISO 4217 currency code |
| requestedDuration | integer | No | Duration in days for temporary increases |
| reason | string | No | Business justification for request |
| submittedDate | date | Yes | Date request was submitted |
| requestStatus | string | Yes | Status: pending, approved, rejected, expired |

**Relations:**
- → Mandate (many-to-one)

### MandateScheme
**Schema.org:** `schema:Product`
_Classification and regulatory framework for different mandate types (SEPA, domestic, international)_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| schemeName | string | Yes | Name of mandate scheme: SEPA-DD, iDEAL, Domestic Transfer, etc. |
| schemeCode | string | Yes | Standardized code for the scheme |
| description | string | No | Purpose and use cases for this scheme |
| regulatoryFramework | string | No | Applicable regulation: PSD2, SEPA, national law |
| applicableCountries | string | No | Comma-separated ISO country codes |
| requiresManualApproval | boolean | Yes | Whether mandates under this scheme need approval |
| maxValidityPeriod | integer | No | Maximum validity duration in days |

### MandateViolation
**Schema.org:** `schema:Event`
_Record of a violation or breach of mandate rules, procedures, or authority limits_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| violationNumber | string | Yes | Unique violation identifier |
| violationType | string | Yes | Type of violation (exceededThreshold, unauthorizedApprover, expiredMandate, revokedAuthority) |
| description | string | Yes | Detailed description of the violation |
| severity | string | Yes | Severity level (critical/high/medium/low) |
| detectedDate | datetime | Yes | Date when violation was detected |
| status | string | Yes | Status of violation (reported/reviewed/resolved) |
| resolvedDate | datetime | No | Date when violation was resolved |
| resolution | string | No | Description of how the violation was resolved |

**Relations:**
- → Mandate (many-to-one)
- → Person (many-to-one)
- → AuditFinding (many-to-one)

### MarketplaceApp
**Schema.org:** `schema:SoftwareApplication`
_Individual application, plugin, or extension listed on marketplace with installation and rating capabilities_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| appId | string | Yes | Unique app identifier |
| name | string | Yes | Application name |
| version | string | Yes | Current application version |
| description | string | Yes | Application description and features |
| category | string | Yes | Category: billing, communication, integration, etc |
| status | string | Yes | Availability status |
| installationUrl | string | No | URL for app installation or documentation |
| ratingScore | number | No | Average user rating 0-5 |
| downloadCount | number | No | Total installations or downloads |

**Relations:**
- → MarketplaceIntegration (many-to-one)
- → Organization (many-to-one)
- → Person (many-to-one)

### MarketplaceIntegration
**Schema.org:** `schema:Service`
_Integration with external marketplaces providing unified catalog access and search across suppliers, apps, and platforms_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| integrationId | string | Yes | Unique integration identifier |
| name | string | Yes | Marketplace platform name |
| type | string | Yes | Integration type: supplier, app, extension, or external |
| url | string | Yes | Marketplace API or access URL |
| status | string | Yes | Active status |
| apiKey | string | No | Encrypted API authentication credential |
| lastSyncDate | datetime | No | Last successful catalog synchronization |
| catalogItemCount | number | No | Count of items in synchronized catalog |

**Relations:**
- → Organization (many-to-one)
- → MarketplaceApp (one-to-many)
- → Offer (one-to-many)

### MaverickSpendAlert
**Schema.org:** `schema:Event`
_Alert for unauthorized, off-contract, or non-compliant departmental spending requiring escalation_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| alertDate | date | Yes | Date alert was triggered |
| departmentName | string | Yes | Department responsible for spend |
| vendorName | string | Yes | Vendor/supplier involved |
| spendAmount | MonetaryAmount | Yes | Amount of unauthorized spend |
| severity | enum | Yes | low, medium, or high |
| alertReason | string | Yes | Why flagged (no PO, off-contract, policy violation, etc.) |
| budgetCode | string | No | Associated budget/cost center code |
| resolvedDate | date | No | Date alert was resolved/remediated |
| resolutionNotes | string | No | How violation was addressed |
| departmentAcknowledged | boolean | No | Department confirmed receipt of alert |

**Relations:**
- → ProcurementComplianceReport (many-to-one)

### MonetaryAmount
**Schema.org:** `schema:MonetaryAmount`
_Schema.org MonetaryAmount — standard vocabulary for monetaryamount data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| value | number | Yes | Numeric value |
| currency | string | Yes | ISO 4217 currency code |

### OAuthIntegration
**Schema.org:** `schema:Thing`
_OAuth 2.0 authentication configuration enabling secure partner integrations and platform access_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| integrationId | string | Yes | Unique OAuth integration identifier |
| name | string | Yes | Integration display name |
| clientId | string | Yes | OAuth client identifier |
| status | string | Yes | Active status |
| scope | string | Yes | OAuth scopes (space-separated) |
| redirectUri | string | Yes | Authorization callback URL |
| createdDate | datetime | Yes | Integration creation date |
| lastUsedDate | datetime | No | Last authentication attempt |
| expiresAt | datetime | No | Token or credential expiration date |

**Relations:**
- → Organization (many-to-one)
- → Person (many-to-one)

### Obligation
**Schema.org:** `schema:Order`
_A financial commitment that must be fulfilled by a specific due date, with tracking for AI task automation and compliance reporting_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| obligationNumber | string | Yes | Unique reference number for the obligation |
| obligationDate | date | Yes | Date the obligation was created |
| dueDate | date | Yes | Date by which the obligation must be settled |
| amount | MonetaryAmount | Yes | Financial amount owed |
| creditor | Organization | Yes | Organization to whom the obligation is owed |
| obligationType | string | No | Type of obligation (invoice, contract, standing order) |
| description | string | No | Details or reason for the obligation |
| settledOnTime | boolean | No | Whether obligation was settled by due date |

**Relations:**
- → Invoice (many-to-one)
- → Payment (one-to-many)
- → SettlementDecision (many-to-one)

### ObligationSettlement
**Schema.org:** `schema:Thing`
_A formal decision record to settle and finalize an obligation, including verification of completion and approval of final amounts_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| settlementNumber | string | Yes | Unique identifier for the settlement decision |
| settlementDate | datetime | Yes | Date when the settlement was finalized |
| settledAmount | number | Yes | Final amount settled |
| status | string | Yes | Current status: draft, approved, finalized |
| settlementType | string | No | Type of settlement: full, partial, amended |
| notes | string | No | Additional notes or remarks about the settlement |

**Relations:**
- → Obligation (many-to-one)
- → ApprovalRequest (many-to-one)

### ObligationTask
**Schema.org:** `schema:Task`
_An automated task for managing obligation lifecycle, including AI-generated deadline tracking and compliance monitoring_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taskNumber | string | Yes | Unique identifier for the task |
| title | string | Yes | Title of the task |
| description | string | No | Detailed description of the task |
| dueDate | datetime | Yes | Calculated or assigned due date with deadline tracking |
| priority | string | No | Priority level: low, medium, high |
| status | string | Yes | Current status: open, in-progress, completed |
| aiGenerated | boolean | No | Indicates if the task was automatically generated by AI |

**Relations:**
- → Obligation (many-to-one)
- → Person (many-to-one)

### Offer
**Schema.org:** `schema:Offer`
_Schema.org Offer — standard vocabulary for offer data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Offer/quote name |
| price | number | Yes | Offered price |
| priceCurrency | string | Yes | Currency |
| validFrom | datetime | No | Offer valid from |
| validThrough | datetime | No | Offer valid until |
| availability | string | No | Availability status |

### Order
**Schema.org:** `schema:Order`
_Schema.org Order — standard vocabulary for order data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| orderNumber | string | Yes | Purchase order number |
| orderDate | datetime | Yes | Date of order |
| orderStatus | string | Yes | Order status |
| totalPrice | number | Yes | Total order amount |
| currency | string | Yes | ISO 4217 currency code |
| deliveryDate | datetime | No | Expected delivery date |
| paymentTerms | string | No | Payment terms (e.g., NET30) |

### Organization
**Schema.org:** `schema:Organization`
_Schema.org Organization — standard vocabulary for organization data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Legal name of the organization |
| tradeName | string | No | Trade/brand name |
| kvkNumber | string | No | Dutch Chamber of Commerce number |
| vatID | string | No | VAT identification number |
| email | string | No | Primary email address |
| telephone | string | No | Primary phone number |
| url | string | No | Website URL |
| iban | string | No | IBAN bank account number |

### Payee
**Schema.org:** `schema:Organization`
_Vendor (accounts payable) or customer (accounts receivable) party in financial transactions._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Legal registered business name |
| tradeName | string | No | Trade name or DBA if different from legal name |
| vatID | string | Yes | Dutch VAT identification number |
| kvkNumber | string | No | KvK (Chamber of Commerce) registration number |
| email | string | Yes | Contact email address |
| telephone | string | No | Contact telephone number |
| iban | string | No | International Bank Account Number for transfers |
| bic | string | No | BIC/SWIFT code for international transactions |

**Relations:**
- → APTransaction (one-to-many)
- → DunningNotice (one-to-many)

### Payment
**Schema.org:** `schema:Order`
_Record of payment made against accounts payable or receivable transaction._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| paymentDate | date | Yes | Date when payment was made |
| amount | MonetaryAmount | Yes | Payment amount |
| paymentMethod | enum | Yes | Payment method used |
| reference | string | No | Bank transaction ID or payment reference number |
| paymentStatus | enum | Yes | Current payment status |
| description | string | No | Payment notes or reconciliation details |

**Relations:**
- → APTransaction (many-to-one)

### PaymentBatch
**Schema.org:** `schema:Payment`
_Batch grouping of multiple payments for mass processing, approval, and scheduled execution_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| batchNumber | string | Yes | Unique batch identifier |
| totalAmount | number | Yes | Sum of all payments in batch |
| totalPayments | number | Yes | Count of payments in batch |
| status | string | Yes | Status: pending, processing, completed, failed |
| approvalStatus | string | Yes | Approval status: pending, approved, rejected |
| scheduledDate | datetime | No | Scheduled execution date for batch |
| createdDate | datetime | Yes | Date batch was created |

**Relations:**
- → Organization (many-to-one)
- → Payment (one-to-many)

### PaymentFraudAssessment
**Schema.org:** `schema:Report`
_Fraud risk assessment using payment intelligence and behavioral pattern analysis_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assessmentId | string | Yes | Unique assessment identifier |
| fraudRiskScore | decimal | Yes | Fraud risk probability (0.0-1.0) |
| reportType | string | Yes | Always: payment-fraud-assessment |
| generatedAt | datetime | Yes | Assessment generation timestamp |
| riskFactors | array | No | List of detected risk indicators (JSON array) |
| riskLevel | string | Yes | Risk level: low, medium, high, critical |
| anomalyDetected | boolean | Yes | Behavioral anomaly detected |
| confidenceScore | decimal | Yes | Assessment confidence (0.0-1.0) |

**Relations:**
- → Transaction (many-to-one)
- → Organization (many-to-one)
- → BankAccount (many-to-one)

### PaymentRiskScore
**Schema.org:** `schema:Thing`
_Fraud risk assessment and intelligence scoring for payment transactions_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| riskLevel | string | Yes | low, medium, high, critical |
| score | number | Yes | 0-100, higher = more risk |
| riskFactors | array | No | velocity, amount, patterns, etc |
| fraudIndicators | array | No |  |
| assessmentDate | datetime | Yes |  |
| notes | string | No |  |

**Relations:**
- → Payment (many-to-one)
- → Person (many-to-one)

### Payroll
**Schema.org:** `schema:Invoice`
_Payroll record for wage, salary, and deduction processing_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| payrollNumber | string | Yes | Unique payroll identifier |
| payrollDate | datetime | Yes | Payroll payment date |
| period | string | Yes | Payroll period (e.g., Jan 2026) |
| grossAmount | number | Yes | Gross salary amount |
| netAmount | number | Yes | Net amount after deductions |
| totalAmount | number | Yes | Total payroll amount |
| status | string | Yes | Payroll status (draft, approved, processed) |

**Relations:**
- → Person (many-to-one)
- → Deduction (one-to-many)

### PeppolAccessPoint
**Schema.org:** `schema:Service`
_Peppol Access Point providing gateway services for e-invoicing and document exchange_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accessPointId | string | Yes | Unique access point identifier |
| name | string | Yes | Access point name or provider |
| endpoint | string | Yes | API endpoint URL for document submission |
| protocol | string | Yes | Communication protocol (AS4, AS2, SFTP, HTTP) |
| documentTypes | array | No | Supported document types (Invoice, Order, Despatch Advice, etc.) |
| supportContact | string | No | Support contact email or phone |
| status | string | Yes | Access point status (active, inactive, testing, deprecated) |

**Relations:**
- → Organization (many-to-one)
- → PeppolParticipant (many-to-one)

### PeppolParticipant
**Schema.org:** `schema:Thing`
_Peppol network participant identifier registration for e-invoicing and EDI communication_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| participantId | string | Yes | Unique Peppol participant identifier |
| scheme | string | Yes | Identifier scheme (GLN, VAT, DUNS, etc.) |
| organizationName | string | Yes | Legal organization name |
| country | string | No | Country code (ISO 3166-1 alpha-2) |
| registeredDate | datetime | No | Date of Peppol network registration |
| expiryDate | datetime | No | Peppol registration expiry date |
| status | string | Yes | Participant status (active, inactive, pending, revoked) |

**Relations:**
- → Organization (many-to-one)

### PerDiem
**Schema.org:** `schema:Offer`
_Daily allowance for employees on company travel, calculated based on country-specific rates, nights away, and configurable per diem policies_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| date | datetime | Yes | Date for which per diem is claimed |
| country | string | Yes | Country where travel occurred |
| nights | number | No | Number of nights away from home base |
| rate | number | Yes | Per diem rate applicable for the country/date |
| amount | number | Yes | Total per diem allowance amount |
| status | string | Yes | Status: draft, approved, or paid |
| approvedDate | datetime | No | Date when per diem was approved |
| description | string | No | Travel purpose or notes |

**Relations:**
- → Person (many-to-one)
- → CostCenter (many-to-one)

### PerformanceImprovementAction
**Schema.org:** `schema:Action`
_Action plan for addressing performance gaps and improving supplier performance against metrics and SLAs_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| actionId | string | Yes | Unique action identifier |
| description | string | Yes | Description of the improvement action |
| targetCompletionDate | datetime | Yes | Target completion date |
| owner | string | Yes | Person or role responsible for action |
| expectedImpact | string | No | Expected improvement or benefit |
| priority | string | Yes | Priority level (high, medium, low) |
| status | string | Yes | Status (planned, in_progress, completed, cancelled) |
| createdDate | datetime | No | Date action was created |

**Relations:**
- → Organization (many-to-one)
- → SupplierPerformanceScorecard (many-to-one)

### PerformanceScore
**Schema.org:** `schema:Rating`
_Individual KPI score recorded for a supplier within a scorecard evaluation period_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| scoreId | string | Yes | Unique score identifier |
| achievedValue | number | Yes | Actual measured value achieved |
| targetValue | number | No | Target value for comparison |
| scoredDate | datetime | Yes | Date when score was recorded |
| notes | string | No | Additional notes or observations |
| status | string | Yes | Score status (recorded, reviewed, approved) |

**Relations:**
- → SupplierPerformanceScorecard (many-to-one)
- → SupplierKPI (many-to-one)

### Permission
_Granular access permission for a specific resource and action_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Unique permission name |
| description | string | No | Detailed permission description |
| resource | string | Yes | Resource this permission applies to (e.g., users, documents, fields) |
| action | string | Yes | Action allowed (read, write, delete, approve) |
| isActive | boolean | Yes | Whether the permission is active |

**Relations:**
- → Role (many-to-many)

### Person
**Schema.org:** `schema:Person`
_Schema.org Person — standard vocabulary for person data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| givenName | string | Yes | First name |
| familyName | string | Yes | Last name |
| email | string | No | Email address |
| telephone | string | No | Phone number |
| jobTitle | string | No | Job title/role |

### PolicyRule
**Schema.org:** `schema:Thing`
_A spending policy rule that defines constraints, approval requirements, and limits for expense compliance enforcement_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the policy rule |
| description | string | No | Detailed description of what the rule enforces |
| thresholdAmount | number | No | Amount threshold that triggers the policy rule |
| ruleType | string | Yes | Type of rule: approval, limit, travel, delegation, etc. |
| isActive | boolean | Yes | Whether the rule is currently enforced |
| priority | number | No | Evaluation priority when multiple rules apply |

**Relations:**
- → Organization (many-to-one)
- → ExpenseCategory (many-to-one)
- → PolicyViolation (one-to-many)

### PolicyViolation
**Schema.org:** `schema:Thing`
_A detected violation or breach of a spending policy rule that requires attention and resolution_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| violationDate | datetime | Yes | Date when the violation was detected |
| severity | string | Yes | Severity level: low, medium, high, critical |
| description | string | Yes | Description of the specific policy violation |
| amount | number | No | The amount that exceeded or violated the policy threshold |
| status | string | Yes | Status: open, acknowledged, resolved, escalated |

**Relations:**
- → PolicyRule (many-to-one)
- → Expense (many-to-one)
- → Person (many-to-one)

### PricingRule
**Schema.org:** `schema:PriceSpecification`
_Volume discounts, tiered pricing, bundle discounts, and promotional pricing rules with validity periods and application priorities_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| ruleCode | string | Yes | Unique pricing rule identifier |
| description | string | No | Rule description and conditions |
| ruleType | string | Yes | volumeDiscount, tierPricing, bundleDiscount, periodDiscount |
| minQuantity | number | No | Minimum quantity for rule application |
| maxQuantity | number | No | Maximum quantity for rule application |
| discountPercentage | number | No | Percentage discount (0-100) |
| discountAmount | number | No | Fixed discount amount in base currency |
| priority | number | No | Priority order for rule application |
| validFrom | datetime | No |  |
| validUntil | datetime | No |  |

**Relations:**
- → CatalogItem (many-to-one)

### ProcurementAuditLog
**Schema.org:** `schema:Action`
_Immutable audit trail recording all procurement actions, approvals, rejections, and changes for transparency, compliance, and decision accountability_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| auditId | string | Yes | Unique audit log entry identifier |
| entityType | string | Yes | Entity type: requisition, purchaseOrder, invoice, payment, approval |
| entityId | string | Yes | ID of the entity being audited |
| actionType | string | Yes | created, updated, approved, rejected, posted, received |
| timestamp | datetime | Yes | When the action occurred |
| reason | string | No | Reason or comment for the action |
| changes | object | No | Changed fields with old and new values |
| referenceDocuments | array | No | Related document identifiers |

**Relations:**
- → Person (many-to-one)
- → Organization (many-to-one)

### ProcurementCatalog
**Schema.org:** `schema:Catalog`
_Master catalog of products and services available for organizational procurement with support for multiple formats (cXML, CIF, internal)_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| catalogNumber | string | Yes | Unique catalog identifier |
| catalogName | string | Yes | Display name of the catalog |
| description | string | No | Catalog description and scope |
| catalogFormat | string | No | Format type: internal, cxml, cif |
| status | string | Yes | draft, active, archived |
| validFrom | datetime | No | Catalog effective start date |
| validUntil | datetime | No | Catalog expiration date |

**Relations:**
- → Organization (many-to-one)
- → CatalogItem (one-to-many)

### ProcurementCategory
**Schema.org:** `schema:Thing`
_Strategic procurement category with sourcing plans and market intelligence for supplier management and spend analysis_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Unique category code |
| name | string | Yes | Category name |
| sourcingStrategy | string | No | Strategic sourcing approach and policy |
| marketIntelligence | object | No | Market data, price trends, and competitive intelligence |
| status | string | Yes | Category status (active, inactive, archived) |

**Relations:**
- → Product (one-to-many)
- → Organization (many-to-one)

### ProcurementComplianceReport
**Schema.org:** `schema:Report`
_Organization-wide procurement compliance dashboard/aggregation per period_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportPeriod | string | Yes | Period identifier (e.g., 2026-Q1, monthly) |
| startDate | date | Yes | Period start date |
| endDate | date | Yes | Period end date |
| totalProcurementValue | MonetaryAmount | Yes | Sum of all orders in period |
| publicProcurementValue | MonetaryAmount | Yes | Value subject to public procurement rules |
| totalOrderCount | number | Yes | Total orders placed in period |
| complianceScore | number | Yes | Percentage compliance (0-100) |
| violationCount | number | No | Number of detected compliance violations |
| maverickSpendCount | number | No | Count of unauthorized/off-contract spend alerts |
| missingProofOfDelivery | number | No | Orders lacking delivery proof submission |
| expiredQualifications | number | No | Vendors with expired UEA declarations |

**Relations:**
- → MaverickSpendAlert (one-to-many)

### ProcurementOrder
**Schema.org:** `schema:Order`
_Procurement order with compliance tracking for Dutch public procurement rules (BBI, threshold checking)_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vendorName | string | Yes | Supplier/vendor name |
| vendorKvk | string | No | Dutch business registration number (KVK) |
| vendorVatID | string | No | EU VAT identification number |
| isPublicProcurement | boolean | Yes | Subject to public procurement rules (BBI threshold €15k) |
| procurementCategory | enum | Yes | supplies, services, works, or combined |
| estimatedValue | MonetaryAmount | Yes | Estimated order value for threshold compliance |
| deliveryDate | date | Yes | Expected delivery/completion date |
| paymentTerms | string | No | Payment conditions (e.g., net 30) |
| requiresProofOfDelivery | boolean | No | Portal submission of delivery proof required |

**Relations:**
- → ProofOfDelivery (one-to-many)
- → QualificationDeclaration (one-to-many)

### ProcurementProcedure
**Schema.org:** `ProcurementProcedure`
_Procurement procedure type defining governance rules and compliance requirements_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| procedureName | string | Yes | Name of the procurement procedure |
| procedureType | string | Yes | Procedure type: open, restricted, negotiated, below-threshold |
| estimatedValue | number | Yes | Estimated contract value in EUR |
| euThreshold | number | Yes | EU threshold value that determines procedure type |
| requiresEUCompliance | boolean | Yes | Whether EU Directive 2014/24/EU applies |
| status | string | Yes | Status: draft, active, completed, cancelled |

**Relations:**
- → PurchaseOrder (one-to-many)
- → Organization (many-to-one)

### ProcurementQuote
**Schema.org:** `schema:Offer`
_Supplier quote for goods or services with validity period_
**Primary spec:** procurement-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Quote title or reference |
| quoteNumber | string | Yes | Unique quote identifier |
| quoteDate | date | Yes | Date quote was issued |
| validFrom | date | Yes | Quote validity start date |
| validThrough | date | Yes | Quote validity end date |
| totalPrice | number | Yes | Total quote amount |
| currency | string | Yes | Currency code (EUR) |
| deliveryTime | string | No | Estimated delivery timeframe |

**Relations:**
- → Supplier (many-to-one)
- → InventoryItem (many-to-many)

### Product
**Schema.org:** `schema:Product`
_Schema.org Product — standard vocabulary for product data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Product name |
| sku | string | No | Stock keeping unit |
| description | string | No | Product description |
| category | string | No | Product category |
| unitPrice | number | Yes | Unit price |
| currency | string | Yes | ISO 4217 currency code |
| unitCode | string | No | Unit of measure (UN/CEFACT) |
| taxRate | number | No | Applicable tax rate percentage |

### Project
**Schema.org:** `schema:Project`
_Project container for organizing tasks, milestones, and team collaboration with resource and timeline management_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| projectId | string | Yes | Unique project identifier |
| name | string | Yes | Project name |
| description | string | No | Project description and objectives |
| status | string | No | active/inactive/completed/onHold |
| owner | string | No | Person/User ID who owns the project |
| startDate | datetime | No | Project start date |
| endDate | datetime | No | Planned end date |
| budget | number | No | Project budget in base currency |

**Relations:**
- → ProjectTask (one-to-many)
- → Milestone (one-to-many)
- → Person (many-to-one)
- → Organization (many-to-one)

### ProjectTask
**Schema.org:** `schema:Action`
_Tasks within a project with hierarchy support, time estimation, and status tracking_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taskId | string | Yes | Unique task identifier |
| projectId | string | Yes | Parent project ID |
| title | string | Yes | Task title |
| description | string | No | Task description and acceptance criteria |
| parentTaskId | string | No | Parent task ID for nested subtasks |
| assignedTo | string | No | Person/User ID assigned to this task |
| status | string | No | new/inProgress/completed/blocked/onHold |
| priority | string | No | high/medium/low |
| estimatedHours | number | No | Estimated hours to complete |
| actualHours | number | No | Actual hours spent |
| dueDate | datetime | No | Task due date |
| completedDate | datetime | No | Actual completion date |

**Relations:**
- → Project (many-to-one)
- → ProjectTask (many-to-one)
- → Person (many-to-one)
- → TimeEntry (one-to-many)

### ProofOfDelivery
**Schema.org:** `schema:DigitalDocument`
_Portal submission documenting goods/services received per order with receiver verification_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| deliveryDate | date | Yes | Date goods/services were received |
| receivingDepartment | string | Yes | Organizational department that received delivery |
| goodsDescription | string | Yes | Description of what was delivered |
| quantity | number | No | Quantity of items delivered |
| unitOfMeasure | string | No | Unit (pieces, kg, hours, etc.) |
| conditionNotes | string | No | Assessment of delivered condition/quality |
| verifiedByName | string | Yes | Name of person verifying receipt |
| verifiedByJobTitle | string | No | Role/title of verifying person |
| submissionDate | date | Yes | Date proof submitted via portal |

**Relations:**
- → ProcurementOrder (many-to-one)

### Property
**Schema.org:** `schema:Place`
_Real estate property subject to assessment, valuation, and interactive mapping_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| address | string | Yes | Street address |
| city | string | Yes |  |
| province | string | Yes |  |
| latitude | number | Yes | Latitude for mapping |
| longitude | number | Yes | Longitude for mapping |
| propertyType | string | Yes | residential, commercial, industrial, or mixed |
| acquisitionValue | number | No |  |
| currentValue | number | No |  |

**Relations:**
- → Organization (many-to-one)
- → Person (many-to-one)
- → PropertyAssessment (one-to-many)
- → WOZAssessment (one-to-many)

### PropertyAssessment
**Schema.org:** `schema:Assessment`
_Assessment scoring a property against defined weighted criteria_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assessmentDate | datetime | Yes |  |
| totalScore | number | Yes | Score 0-100 |
| status | string | Yes | draft, in-progress, completed, rejected |
| completionDate | datetime | No |  |
| notes | string | No |  |

**Relations:**
- → Property (many-to-one)
- → Person (many-to-one)
- → AssessmentCriteria (many-to-many)

### PublicProcurement
**Schema.org:** `schema:Service`
_European public procurement announcement for TED/OJEU publication with tender documents and timelines_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| procurementId | string | Yes | Unique procurement identifier |
| title | string | Yes | Procurement announcement title |
| description | string | Yes | Detailed procurement description |
| status | string | Yes | Publication status |
| publicationDate | datetime | No | Actual TED/OJEU publication date |
| dueDate | datetime | Yes | Tender submission deadline |
| publishingAuthority | string | Yes | Organization publishing the procurement |
| tedReference | string | No | TED publication reference number |
| procurementType | string | Yes | Type: goods, services, or works |
| estimatedBudget | number | No | Estimated contract value |

**Relations:**
- → Organization (many-to-one)
- → Document (one-to-many)
- → PublicationAmendment (one-to-many)
- → DigitalDocument (many-to-one)

### PublicationAmendment
**Schema.org:** `schema:Thing`
_Material or minor changes to published procurement announcements requiring re-publication to TED/OJEU_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| amendmentId | string | Yes | Unique amendment identifier |
| publicationId | string | Yes | Reference to PublicProcurement being amended |
| changeType | string | Yes | Classification: material or minor change |
| description | string | Yes | Details of the amendment |
| amendmentDate | datetime | Yes | When amendment was flagged |
| status | string | Yes | Processing status |
| reason | string | No | Reason for amendment |

**Relations:**
- → PublicProcurement (many-to-one)
- → DigitalDocument (many-to-one)

### PublicationLog
**Schema.org:** `schema:Event`
_Audit trail recording publication events including creation, updates, downloads and external platform notifications_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| logId | string | Yes | Unique log entry identifier |
| publicationId | string | Yes | Reference to related publication entity |
| logType | string | Yes | Event type: created, published, amended, downloaded, notified, or error |
| timestamp | datetime | Yes | When event occurred |
| details | object | No | Additional event details as key-value pairs |
| ipAddress | string | No | Source IP address of action |
| userAgent | string | No | Client user agent string |
| description | string | No | Human-readable log entry description |

**Relations:**
- → DigitalDocument (many-to-one)
- → Person (many-to-one)
- → Organization (many-to-one)

### PublicationNotice
**Schema.org:** `schema:Thing`
_A notice published to external procurement channels (TenderNed, TED) including tender publication, award notices, corrigenda, and DPS notices_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| noticeId | string | Yes | Unique identifier for the publication notice |
| noticeType | string | Yes | Type: tender, award, corrigendum, dps_admission |
| publicationChannel | string | Yes | Channel where notice is published: TenderNed, TED, or both |
| externalNoticeId | string | No | ID assigned by external system (TenderNed or TED reference number) |
| status | string | Yes | Status: draft, submitted, published, failed, withdrawn |
| publishedDate | datetime | No | Date the notice was published |
| submissionDate | datetime | No | Date the notice was submitted for publication |
| isAboveThreshold | boolean | No | Whether this is an above-threshold EU notice |
| errorMessage | string | No | Error message if publication failed |

**Relations:**
- → Tender (many-to-one)
- → DigitalDocument (one-to-many)

### PurchaseOrder
**Schema.org:** `schema:Order`
_Purchase order with approval tracking for Dutch bookkeeping workflow_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| orderNumber | string | Yes | Unique purchase order number for identification and reference |
| orderDate | datetime | Yes | Date when the purchase order was created |
| totalPrice | number | Yes | Total price including tax and shipping |
| currency | string | Yes | Currency code (e.g., EUR, USD) |
| taxAmount | number | Yes | Total tax amount for the purchase order |
| paymentTerms | string | No | Payment terms (e.g., net 30, net 60) |
| deliveryDate | datetime | Yes | Expected delivery date |
| vendorName | string | Yes | Name of the vendor/supplier |
| vendorKvk | string | Yes | Dutch KvK (Chamber of Commerce) registration number |
| lineItems | array | Yes | Array of ordered items with quantity, unit price, and description |
| internalReference | string | No | Internal reference number or cost center code |
| deliveryAddress | object | Yes | Delivery address with street, city, postal code, and country |
| discountAmount | number | No | Discount amount applied to the order |
| shippingCost | number | No | Shipping or delivery cost |
| vendorEmail | string | No | Email address of the vendor contact |
| invoiceReference | string | No | Reference to the linked invoice number |
| departmentCode | string | No | Department or cost center code for cost allocation |
| description | string | No | General description or purpose of the purchase order |

**Relations:**
- → PurchaseOrderRevision (one-to-many)
- → ApprovalRequest (one-to-many)
- → Product (many-to-many)

### PurchaseOrderChange
**Schema.org:** `schema:Order`
_Purchase order amendment with full version tracking and change audit trail_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| changeNumber | string | Yes | Unique change order identifier |
| changeDate | date | Yes | Date change was requested |
| originalPoNumber | string | Yes | Original PO reference |
| versionNumber | integer | Yes | PO version (e.g., 1, 2, 3) |
| changedFields | text | Yes | JSON: {field: oldValue → newValue} for audit purposes |
| changeReason | text | Yes | Business reason for change |

**Relations:**
- → Organization (many-to-one)
- → Product (many-to-many)

### PurchaseOrderRevision
**Schema.org:** `schema:DigitalDocument`
_Tracks PO revisions and amendments with change history and version control_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| revisionNumber | integer | Yes | Sequential revision number |
| revisedAt | datetime | Yes | Revision timestamp |
| changeDescription | text | Yes | Detailed description of changes |
| amendmentReason | string | No | Reason for amendment (price, quantity, scope) |
| documentType | string | Yes | Document type (revision|amendment) |
| encodingFormat | string | No | File format (PDF, DOCX) |
| contentSize | integer | No | File size in bytes |

**Relations:**
- → PurchaseOrder (many-to-one)

### PurchaseRequisition
**Schema.org:** `schema:Order`
_A formal request for goods or services with multiple line items and custom fields, supporting multi-location and multi-entity procurement workflows_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| requisitionNumber | string | Yes | Unique requisition identifier |
| requisitionDate | datetime | Yes | Date requisition was created |
| status | string | Yes | draft, submitted, approved, rejected, ordered |
| purpose | string | No | Purpose or business justification |
| deliveryDate | datetime | No | Requested delivery date |
| customFields | object | No | Custom fields for procurement-specific data |
| totalAmount | number | No | Estimated total value |

**Relations:**
- → Person (many-to-one)
- → Organization (many-to-one)
- → ApprovalRequest (one-to-many)

### QualificationDeclaration
**Schema.org:** `schema:DigitalDocument`
_UEA (Uniforme Europese Aanbestedingsdocument) self-certification by vendor for procurement qualification_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vendorName | string | Yes | Declaring organization/vendor name |
| vendorKvk | string | Yes | Dutch KVK registration of vendor |
| declarationDate | date | Yes | Date of UEA self-declaration submission |
| validFrom | date | Yes | Declaration validity start date |
| validUntil | date | Yes | Declaration expiry date |
| declarationStatus | enum | Yes | submitted, accepted, rejected, or expired |
| excludedFromProcurement | boolean | No | Vendor exclusion grounds present (bankruptcy, criminal record, etc.) |
| professionalLicenses | string | No | Relevant professional certifications held |
| economicOperatorRegister | string | No | Registration in EPER or similar EU register |
| declarationNotes | string | No | Additional compliance statements |

### QualityManagementSystem
**Schema.org:** `Thing`
_A quality management system defining procedures, controls, and certifications for organizational quality assurance_
**Primary spec:** compliance-audit

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| qmsNumber | string | Yes | Unique QMS identifier |
| qmsName | string | Yes | Name or title of the QMS |
| version | string | No | Current version number |
| status | string | Yes | Status: active, inactive, or under-review |
| effectiveDate | datetime | Yes | Date the QMS became effective |
| scope | string | No | Scope of the quality management system |
| certifications | array | No | List of certifications (ISO 9001, etc.) |

**Relations:**
- → Organization (many-to-one)
- → Document (one-to-many)
- → ComplianceAudit (one-to-many)

### Quote
**Schema.org:** `schema:Offer`
_Supplier response to tender with pricing and terms_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| quoteNumber | string | Yes | Unique quote identifier |
| price | number | Yes | Total quoted price (in cents) |
| priceCurrency | string | Yes | Currency (EUR) |
| validFrom | date | Yes | Quote valid-from date |
| validThrough | date | Yes | Quote expiration date |
| paymentTerms | string | No | Payment terms (Net30, etc.) |

**Relations:**
- → Tender (many-to-one)
- → Supplier (many-to-one)

### RateCard
**Schema.org:** `schema:Thing`
_Supplier rate and pricing structure matching contract terms with volume discounts and payment terms_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| rateCardId | string | Yes | Unique rate card identifier |
| rateCardName | string | Yes | Name or title of the rate card |
| effectiveDate | datetime | Yes | Date rate card becomes effective |
| expiryDate | datetime | No | Date rate card expires |
| currency | string | Yes | Currency for pricing |
| rateType | string | Yes | Type of pricing: hourly, daily, fixedPrice, or volumeDiscount |
| rates | array | Yes | Array of rate entries with position/service and corresponding rates |
| paymentTerms | string | No | Payment terms and conditions |

**Relations:**
- → Supplier (many-to-one)
- → Contract (many-to-one)

### Receipt
**Schema.org:** `schema:DigitalDocument`
_Digital document storing scanned receipts, invoices, or proof of transaction for audit trail and digital archiving._
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| documentType | enum | Yes | Type of document stored |
| fileName | string | Yes | Original filename as uploaded |
| encodingFormat | string | Yes | MIME type (e.g., application/pdf, image/jpeg) |
| contentSize | number | Yes | File size in bytes |
| uploadDate | datetime | Yes | Date and time document was uploaded |
| documentDate | date | No | Date on the receipt or document itself |
| description | string | No | Notes about the document or extraction notes |

**Relations:**
- → APTransaction (many-to-one)

### Report
**Schema.org:** `schema:Report`
_Schema.org Report — standard vocabulary for report data_

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Report title |
| reportType | string | Yes | Report type (financial, compliance, etc.) |
| period | string | No | Reporting period |
| generatedAt | datetime | No | When the report was generated |

### RequestForQuotation
**Schema.org:** `schema:Quotation`
_Request for quotation supporting RFx management with templated events, multi-round negotiations, and digital lockbox_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| rfqNumber | string | Yes | Unique RFQ identifier |
| title | string | Yes | RFQ title or description |
| deadline | datetime | Yes | Submission deadline for responses |
| round | number | Yes | Negotiation round number |
| status | string | Yes | Status: draft, published, closed, awarded, cancelled |
| lockboxEnabled | boolean | Yes | Enable digital lockbox to prevent bid viewing before deadline |
| estimatedValue | number | No | Estimated procurement value |
| createdDate | datetime | Yes | RFQ creation date |

**Relations:**
- → Organization (many-to-one)
- → Payee (many-to-many)
- → Offer (one-to-many)

### RevenueStream
**Schema.org:** `schema:Offer`
_A categorized source or type of revenue for tracking income by origin and supporting revenue management analysis._
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| streamName | string | Yes | The name of the revenue source |
| category | string | Yes | Revenue classification (e.g., product sales, service fees, licensing) |
| currency | string | Yes | ISO 4217 currency code |
| annualTarget | object | No | Target revenue as {value, currency} following MonetaryAmount schema |
| isActive | boolean | No | Whether this revenue stream is currently active |

**Relations:**
- → JournalEntry (one-to-many)

### RiskCriteria
_Weighted assessment criteria for dynamic risk scoring and evaluation_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| criteriaName | string | Yes | Name of assessment criteria |
| criteriaType | string | Yes | Type: financial, operational, compliance, behavioral |
| weight | decimal | Yes | Weight in assessment (0.0-1.0, normalized across criteria set) |
| threshold | decimal | Yes | Threshold value for this criteria (e.g., days overdue) |
| description | string | No | Criteria definition and calculation method |
| riskLevel | string | No | Risk level if threshold breached: low, medium, high |
| active | boolean | Yes | Whether criteria is active in scoring |

### Role
_Collection of permissions defining access level and capabilities within the system_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Unique role name |
| description | string | No | Role description and purpose |
| isSystemRole | boolean | No | Whether this is a built-in system role |
| level | number | No | Role hierarchy level for permission evaluation |
| isActive | boolean | Yes | Whether the role is active |

**Relations:**
- → Permission (many-to-many)
- → User (many-to-many)

### SavingsOpportunity
**Schema.org:** `schema:Thing`
_A tracked initiative to reduce spending with projected and realized savings amounts for portfolio management_
**Primary spec:** spend-analytics-reporting

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of the savings opportunity or initiative |
| description | string | No | Detailed description of the savings initiative |
| projectedSavings | number | Yes | Expected annual savings amount in currency units |
| realizedSavings | number | No | Actual savings achieved to date |
| startDate | datetime | Yes | When the initiative started or is planned to start |
| completionDate | datetime | No | Expected or actual completion date |
| status | string | Yes | Status: pipeline, active, completed, cancelled |

**Relations:**
- → Organization (many-to-one)
- → ExpenseCategory (many-to-one)

### ScheduledPayment
**Schema.org:** `schema:Payment`
_Payment scheduled for future execution with support for recurring transactions_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| paymentReference | string | Yes | Unique payment reference or confirmation number |
| amount | number | Yes | Payment amount |
| currency | string | Yes | Currency code (ISO 4217) |
| scheduledDate | datetime | Yes | Date payment is scheduled for execution |
| frequency | string | No | Recurrence frequency: once, daily, weekly, monthly, yearly |
| recurringEndDate | datetime | No | End date for recurring payments |
| status | string | Yes | Status: pending, approved, executed, failed, cancelled |
| lastExecutionDate | datetime | No | Date of last payment execution |

**Relations:**
- → Payee (many-to-one)
- → BankAccount (many-to-one)
- → Payment (one-to-many)

### ServiceLevelAgreement
**Schema.org:** `schema:Service`
_Formal agreement defining service level targets, performance expectations, and remedies with a supplier_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| slaId | string | Yes | Unique SLA identifier |
| slaName | string | Yes | SLA name or title |
| description | string | No | Detailed SLA description |
| serviceMetric | string | Yes | Metric being measured (e.g., Response Time, Availability, Uptime) |
| targetLevel | string | Yes | Target service level (e.g., 99.5%, <4 hours) |
| acceptablePenalty | string | No | Consequence of non-compliance |
| effectiveDate | datetime | Yes | SLA effective date |
| expiryDate | datetime | No | SLA expiration date |
| status | string | Yes | Status (draft, active, expired, terminated) |

**Relations:**
- → Organization (many-to-one)

### SettlementDecision
**Schema.org:** `schema:DigitalDocument`
_Formal decision to finalize and mark one or more obligations as settled, issued by authorized personnel_
**Primary spec:** obligation-financial-administration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| decisionNumber | string | Yes | Unique decision identifier |
| decisionDate | date | Yes | Date decision was issued |
| issuedBy | Person | Yes | Authorized person who issued the decision |
| totalSettledAmount | MonetaryAmount | Yes | Total financial amount being settled |
| obligationCount | integer | No | Number of obligations included in settlement |
| decisionRationale | string | No | Reason or basis for settlement decision |
| documentUrl | string | No | Reference to decision document or file |

**Relations:**
- → Obligation (one-to-many)
- → ComplianceReport (many-to-one)

### Share
**Schema.org:** `schema:Product`
_Represents an ownership stake in a corporation. Tracks share quantity, type, nominal value, and acquisition date for investment tracking across multi-entity portfolio._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| shareNumber | string | Yes | Unique share class or certificate identifier |
| quantity | integer | Yes | Number of shares held |
| shareType | string | Yes | Share category: common, preferred, or founder shares |
| nominalValue | decimal | Yes | Nominal value per share in EUR |
| totalInvestmentAmount | decimal | Yes | Total investment in EUR (quantity × nominalValue) |
| acquisitionDate | date | Yes | Date shares were acquired or issued |
| votingRights | string | No | Voting rights status: full, limited, or none |

**Relations:**
- → Shareholder (many-to-one)
- → Corporation (many-to-one)

### Shareholder
**Schema.org:** `schema:Person`
_Person or organization holding ownership shares in one or more corporations. Tracks investors across the multi-entity portfolio._
**Primary spec:** corporations-enterprise

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| givenName | string | Yes | Given name (for individuals) |
| familyName | string | Yes | Family name (for individuals) |
| companyName | string | No | Organization name (for corporate shareholders) |
| email | string | No | Email address for shareholder contact |
| telephone | string | No | Telephone number for shareholder contact |
| shareholderType | string | Yes | Type: individual, organization, or foundation |
| residenceAddress | string | No | Residential or business address |

**Relations:**
- → Share (one-to-many)
- → Corporation (many-to-many)

### SigningAuthority
**Schema.org:** `schema:Person`
_Delegation of signing rights to a specific person with defined scope and limits_
**Primary spec:** authorization-mandate-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| authorityNumber | string | Yes | Unique identifier for this signing authority |
| holderId | string | Yes | ID of person holding signing authority |
| signingScope | string | Yes | Types of documents/transactions: invoices, contracts, cheques, all |
| signingLimit | decimal | No | Maximum amount per transaction |
| currency | string | Yes | ISO 4217 currency code |
| validFrom | date | Yes | When this authority becomes effective |
| validThrough | date | No | When this authority expires |
| delegatedBy | string | Yes | ID of authorized representative or director |
| signatureMethod | string | No | Signature method: handwritten, digital, both |

**Relations:**
- → Mandate (many-to-one)

### SourcingEvent
**Schema.org:** `schema:Event`
_Sourcing event (RFQ, RFP, RFI) with supplier invitation and response tracking_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| eventId | string | Yes | Unique sourcing event identifier |
| eventType | string | Yes | Type of sourcing event: RFQ, RFP, or RFI |
| eventName | string | Yes | Title or name of the sourcing event |
| description | string | No | Detailed description of requirements and scope |
| releaseDate | datetime | Yes | Date the sourcing event is released to suppliers |
| deadline | datetime | Yes | Response submission deadline |
| status | string | Yes | Event status: draft, published, closed, or awarded |
| estimatedBudget | number | No | Estimated budget for the sourcing opportunity |

**Relations:**
- → Supplier (many-to-many)
- → PurchaseOrder (one-to-one)
- → Document (one-to-many)

### SpendCategory
**Schema.org:** `schema:Thing`
_Hierarchical category for organizing and analyzing supplier spending by type and business function_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| categoryId | string | Yes | Unique category identifier |
| name | string | Yes | Category name (e.g., IT Services, Maintenance, Staffing) |
| description | string | No | Category description |
| parentCategoryId | string | No | Parent category ID for hierarchical organization |
| level | number | No | Hierarchical level in category tree |
| status | string | Yes | Status (active, inactive, archived) |

### SpendTransaction
**Schema.org:** `schema:Order`
_Purchase order and transaction tracking for spend analytics_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| orderNumber | string | Yes | Purchase order number |
| orderDate | date | Yes | Date order was placed |
| invoiceNumber | string | No | Associated invoice number |
| totalPrice | number | Yes | Total transaction amount |
| currency | string | Yes | Currency code (EUR) |
| category | string | Yes | Spend category for analytics |
| deliveryDate | date | No | Actual or expected delivery date |
| deliveryOnTime | boolean | No | Whether delivered per SLA target |
| paymentStatus | string | Yes | Payment status (pending/paid/overdue) |

**Relations:**
- → Supplier (many-to-one)

### SpendingRecord
**Schema.org:** `schema:Order`
_Individual spending transaction for government transparency and audit compliance_
**Primary spec:** government-public-sector

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionId | string | Yes | Unique transaction identifier |
| transactionDate | date | Yes | Date of spending transaction |
| amount | number | Yes | Transaction amount in decimal format |
| currency | string | Yes | Currency code (EUR) |
| vendorName | string | Yes | Name of vendor or service provider |
| category | string | Yes | Spending category: personnel, operations, investment, or services |
| approvalStage | string | Yes | Current approval stage: draft, submitted, approved, or rejected |
| documentUri | string | No | Reference URI to supporting documentation |

**Relations:**
- → FundAllocation (many-to-one)
- → GovernmentEntity (many-to-one)
- → SubmissionDossier (many-to-one)

### StatementOfWork
**Schema.org:** `schema:CreativeWork`
_Detailed specification of deliverables, milestones, payment terms, and service scope for statement-of-work-based procurement and service ordering_
**Primary spec:** catalog-purchase-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| sowNumber | string | Yes | Unique SOW identifier |
| sowDate | datetime | Yes | Date SOW was created |
| title | string | Yes | SOW title |
| description | string | No | Detailed description of work |
| scope | string | No | Work scope and boundaries |
| deliverables | array | No | Array of deliverable items with descriptions and due dates |
| milestones | array | No | Payment milestone objects with completion dates and invoice triggers |
| totalValue | number | Yes | Total SOW value |
| currency | string | Yes | Currency code |
| status | string | Yes | draft, active, completed, cancelled |

**Relations:**
- → Organization (many-to-one)
- → Person (many-to-one)
- → Contract (many-to-one)
- → PurchaseOrder (one-to-many)

### SubmissionDossier
**Schema.org:** `schema:DigitalDocument`
_Council submission dossier aggregating spending records and compliance documentation for public sector reporting_
**Primary spec:** government-public-sector

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Dossier title or reference name |
| dossierType | string | Yes | Type: annual report, quarterly report, audit submission, or grant report |
| submissionDate | date | Yes | Planned or actual submission date to council |
| completionPercentage | integer | Yes | Completion status as percentage (0-100) |
| contentSummary | string | No | Summary of dossier contents and key figures |

**Relations:**
- → GovernmentEntity (many-to-one)
- → SpendingRecord (one-to-many)

### Subscription
**Schema.org:** `schema:Offer`
_Recurring subscription arrangement with plan and quantity tracking for billing_
**Primary spec:** accounts-payable-receivable

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| subscriptionNumber | string | Yes | Unique subscription identifier |
| planName | string | Yes | Name of subscription plan |
| quantity | number | Yes | Quantity of units in subscription |
| startDate | datetime | Yes | Subscription start date |
| endDate | datetime | No | Subscription end date |
| amount | number | Yes | Recurring billing amount |
| frequency | string | Yes | Billing frequency (monthly, quarterly, yearly) |
| status | string | Yes | Subscription status |

**Relations:**
- → Organization (many-to-one)
- → Product (many-to-one)
- → Invoice (one-to-many)

### SubsidyApplication
**Schema.org:** `schema:Application`
_An application for a subsidy or grant under a specific subsidy scheme with supporting documentation_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| applicationId | string | Yes | Unique application identifier |
| requestedAmount | number | Yes | Requested grant amount |
| status | string | Yes | Application status: draft, submitted, under-review, approved, rejected |
| submissionDate | datetime | No |  |
| reviewDate | datetime | No |  |
| notes | string | No |  |

**Relations:**
- → SubsidyScheme (many-to-one)
- → Organization (many-to-one)
- → Document (one-to-many)

### SubsidyScheme
**Schema.org:** `schema:GovernmentService`
_A government subsidy program defining eligibility criteria, award conditions, and funding framework_
**Primary spec:** grant-subsidy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| schemeId | string | Yes | Unique scheme identifier |
| name | string | Yes | Subsidy scheme name |
| description | string | No |  |
| maxGrant | number | No | Maximum grant amount |
| minGrant | number | No | Minimum grant amount |
| isPublished | boolean | No | Published to public portal |
| publishedDate | datetime | No |  |
| governmentLevel | string | No | national, provincial, or municipal |

**Relations:**
- → Organization (many-to-one)
- → Grant (one-to-many)

### Supplier
**Schema.org:** `schema:Organization`
_Master data for suppliers participating in bid evaluations and framework agreements_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| legalName | string | Yes | Official company legal name |
| tradeName | string | No | Commercial trading name |
| kvkNumber | string | Yes | Dutch Chamber of Commerce registration number |
| vatID | string | Yes | VAT identification number |
| email | string | Yes | Contact email address |
| telephone | string | No | Contact telephone number |
| url | string | No | Company website URL |
| iban | string | Yes | IBAN for payment processing |

**Relations:**
- → Person (one-to-many)

### SupplierBid
**Schema.org:** `schema:Offer`
_Supplier bid submitted for procurement evaluation with price, terms, and evaluation score_
**Primary spec:** evaluation-award

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Bid identifier or reference number |
| price | number | Yes | Bid amount offered |
| priceCurrency | string | Yes | Currency code (ISO 4217, e.g. EUR) |
| validFrom | date | Yes | Bid validity start date |
| validThrough | date | Yes | Bid validity expiration date |
| paymentTerms | string | No | Proposed payment terms (e.g., NET30) |
| deliverySchedule | string | No | Proposed delivery timeline or milestones |
| evaluationScore | number | No | Score assigned during automated evaluation |

**Relations:**
- → Supplier (many-to-one)
- → BidEvaluation (many-to-one)

### SupplierCertificate
**Schema.org:** `schema:Thing`
_Certification and compliance tracking for suppliers including ISO, safety, quality, and environmental certifications_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| certificateId | string | Yes | Unique certificate identifier |
| certificateType | string | Yes | Type of certification: ISO, safety, quality, environmental, etc. |
| certificationBody | string | No | Name of issuing certification organization |
| issuedDate | datetime | Yes | Date certificate was issued |
| expiryDate | datetime | No | Certificate expiration date |
| certificateNumber | string | No | Unique certificate number from issuing body |
| validationStatus | string | Yes | Current status: valid, expired, or revoked |

**Relations:**
- → Supplier (many-to-one)
- → Document (one-to-one)

### SupplierDocument
**Schema.org:** `schema:DigitalDocument`
_Certifications, licenses, insurance, and other supplier verification documents_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Document or certificate name |
| documentType | string | Yes | Classification of document |
| description | string | No | Document details and contents |
| certificationBody | string | No | Issuing organization |
| issuanceDate | date | Yes | Issue date |
| expiryDate | date | No | Expiration or renewal date |
| encodingFormat | string | No | MIME type (e.g. application/pdf) |
| contentSize | integer | No | File size in bytes |
| verificationStatus | string | Yes | Verification approval status |

**Relations:**
- → Supplier (many-to-one)

### SupplierKPI
**Schema.org:** `schema:Thing`
_Key Performance Indicator definition for measuring supplier performance across delivery, quality, cost, and responsiveness categories_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| kpiId | string | Yes | Unique KPI identifier |
| name | string | Yes | KPI name (e.g., On-Time Delivery Rate, Quality Score) |
| description | string | No | Detailed description of the KPI |
| unitOfMeasure | string | Yes | Unit of measurement (%, days, count, score) |
| targetValue | number | Yes | Target or benchmark value |
| weight | number | No | Importance weighting (0-1) in aggregate scoring |
| category | string | Yes | KPI category (delivery, quality, cost, responsiveness, compliance) |
| status | string | Yes | Status (active, inactive) |

### SupplierPerformanceReport
**Schema.org:** `schema:Report`
_Aggregated supplier performance reporting for period analysis_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportPeriod | string | Yes | Report period (YYYY-MM format) |
| reportType | string | Yes | Fixed value: supplier-performance |
| generatedAt | date | Yes | Report generation date |
| averageScore | number | Yes | Average performance score (0-10) |
| onTimeDeliveryPercent | number | Yes | On-time delivery percentage (0-100) |
| qualityScore | number | Yes | Period quality score (0-10) |
| totalSpend | number | Yes | Total spend in period |
| transactionCount | integer | Yes | Number of transactions in period |
| recommendations | text | No | Performance improvement recommendations |

**Relations:**
- → Supplier (many-to-one)

### SupplierPerformanceScore
**Schema.org:** `schema:Offer`
_Multi-dimensional performance metrics for supplier evaluation_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| scoringDate | date | Yes | Date score was calculated |
| overallScore | number | Yes | Overall performance score (0-10) |
| deliveryScore | number | Yes | On-time delivery score (0-10) |
| qualityScore | number | Yes | Product/service quality score (0-10) |
| responsivenessScore | number | Yes | Customer responsiveness score (0-10) |
| complianceScore | number | No | Contract/SLA compliance score (0-10) |
| scoringPeriod | string | Yes | Period covered (monthly/quarterly/annual) |

**Relations:**
- → Supplier (many-to-one)
- → SupplierSLA (many-to-one)

### SupplierPerformanceScorecard
**Schema.org:** `schema:AggregateRating`
_Comprehensive performance scorecard tracking supplier metrics against KPIs during a defined evaluation period_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| scorecardId | string | Yes | Unique scorecard identifier |
| period | string | Yes | Evaluation period identifier (e.g., Q1-2024) |
| overallScore | number | No | Aggregate performance score (0-100) |
| startDate | datetime | Yes | Evaluation period start date |
| endDate | datetime | No | Evaluation period end date |
| status | string | Yes | Scorecard status (draft, active, completed, archived) |

**Relations:**
- → Organization (many-to-one)
- → PerformanceScore (one-to-many)

### SupplierPortalAccount
**Schema.org:** `schema:Thing`
_Self-service portal account for supplier profile management, document submission, and order visibility_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| accountId | string | Yes | Unique portal account identifier |
| username | string | Yes | Portal login username |
| accountStatus | string | Yes | Account status: active, inactive, or pending |
| lastLogin | datetime | No | Timestamp of most recent login |
| accessLevel | string | Yes | Portal access level: basic or full |
| emailNotification | boolean | Yes | Enable email notifications |
| twoFactorEnabled | boolean | Yes | Two-factor authentication enabled |

**Relations:**
- → Supplier (one-to-one)
- → Person (one-to-one)

### SupplierPortalUser
**Schema.org:** `schema:Person`
_Self-service portal account for supplier staff with profile management and access control_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| givenName | string | Yes | First name |
| familyName | string | Yes | Last name |
| email | string | Yes | Login email and notification address |
| jobTitle | string | No | Job title at supplier |
| accessLevel | string | Yes | Portal permission level |
| lastLoginDate | datetime | No | Last successful portal login |
| profileCompleteness | integer | No | Supplier profile completion percentage (0-100) |
| preferredLanguage | string | Yes | Portal interface language |

**Relations:**
- → Supplier (many-to-one)

### SupplierQualification
**Schema.org:** `schema:Document`
_UEA self-declaration for supplier qualification in EU procurement_
**Primary spec:** procurement-compliance

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| declarationNumber | string | Yes | Unique declaration reference number |
| declarationDate | datetime | Yes | Date of declaration submission |
| validUntil | datetime | Yes | Expiration date of qualification |
| declarationType | string | Yes | Type of declaration: UEA, ISO, other |
| status | string | Yes | Status: pending, approved, rejected, expired |

**Relations:**
- → Organization (many-to-one)
- → ComplianceDocument (one-to-many)

### SupplierRiskProfile
**Schema.org:** `schema:Organization`
_Supply chain risk profile with geographic positioning and compliance monitoring_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| riskScore | integer | Yes | Overall risk score (0-100) |
| geoLocation | string | Yes | Geographic coordinates (latitude,longitude) or address |
| country | string | Yes | ISO 3166 country code |
| complianceStatus | string | Yes | Compliance status: compliant, warning, non-compliant |
| paymentDefaultHistory | integer | No | Count of late/missed payments |
| lastAssessmentDate | date | No | Date of most recent risk assessment |
| creditLimit | decimal | No | Maximum credit exposure in EUR |
| geopoliticalRiskLevel | string | No | Geopolitical risk: low, medium, high |

**Relations:**
- → Organization (one-to-one)
- → Transaction (one-to-many)

### SupplierSLA
**Schema.org:** `schema:Offer`
_Service Level Agreement defining expected performance standards_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| slaNumber | string | Yes | Unique SLA identifier |
| description | string | Yes | SLA terms and conditions |
| deliveryTargetDays | integer | Yes | Target delivery time in days |
| qualityThresholdPercent | number | Yes | Minimum quality acceptance threshold (0-100%) |
| responseTimeHours | number | Yes | Target response time in hours |
| penaltyPercentage | number | No | Non-compliance penalty as % of invoice |
| validFrom | date | Yes | SLA effective date |
| validThrough | date | No | SLA expiration date |

**Relations:**
- → Supplier (many-to-one)

### SupplierSurvey
**Schema.org:** `schema:Survey`
_Assessment or feedback survey collecting quantitative and qualitative supplier performance data for evaluation and analysis_
**Primary spec:** supplier-performance-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| surveyId | string | Yes | Unique survey identifier |
| surveyName | string | Yes | Survey name or title |
| respondentScore | number | No | Quantitative score from respondent (0-100) |
| surveyDate | datetime | Yes | Date survey was completed |
| feedbackText | string | No | Qualitative feedback or comments |
| respondentName | string | No | Name of respondent |
| status | string | Yes | Status (draft, submitted, reviewed, approved) |

**Relations:**
- → Organization (many-to-one)
- → SupplierPerformanceScorecard (many-to-one)

### SupplyChainRisk
**Schema.org:** `schema:Thing`
_Supply chain risk monitoring including geopolitical and natural disaster impact assessment_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| riskType | string | Yes | geopolitical, natural-disaster, supplier-failure, regulatory, financial |
| severity | string | Yes | critical, high, medium, low |
| description | string | Yes |  |
| affectedCountries | array | No | ISO country codes |
| impactArea | string | No |  |
| geopoliticalFactors | object | No |  |
| naturalDisasterFactors | object | No |  |
| assessmentDate | datetime | Yes |  |
| nextReviewDate | datetime | No |  |
| status | string | Yes | identified, monitoring, escalated, resolved |

**Relations:**
- → Organization (many-to-one)

### TaxConfiguration
**Schema.org:** `schema:Thing`
_System-wide tax settings, rules, and thresholds for a specific jurisdiction and tax year_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| configId | string | Yes | Unique configuration identifier |
| taxYear | number | Yes | Tax year this configuration applies to |
| jurisdiction | string | Yes | Tax jurisdiction code (NL, UK, US, etc.) |
| effectiveDate | datetime | Yes | Date when this configuration becomes effective |
| description | string | No | Configuration description and compliance notes |

**Relations:**
- → Organization (many-to-one)
- → TaxRate (one-to-many)

### TaxDeclaration
**Schema.org:** `schema:Report`
_Primary tax declaration submission (VAT, BCF, exemptions). Aggregates tax lots and manages workflow from draft to submission._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| declarationType | enum | Yes | BCF, VAT-NL, ICP, or other Dutch tax form type |
| taxYear | integer | Yes | Calendar or fiscal year (e.g. 2025) |
| declarationStatus | enum | Yes | draft, approved, submitted, acknowledged, rejected |
| totalTaxAmount | MonetaryAmount | Yes | Net tax liability or credit |
| submissionDate | date | No | Actual submission timestamp to authorities |
| businessTaxID | string | Yes | Taxpayer BSN/KVK or VAT ID |

**Relations:**
- → Organization (many-to-one)
- → TaxLot (one-to-many)
- → ExemptionCertificate (many-to-many)

### TaxExemption
**Schema.org:** `schema:Offer`
_Reusable exemption rule or policy: qualifies transactions or amounts as exempt. Linked to certificates and applied during tax lot calculation._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| exemptionCode | string | Yes | Statutory code (e.g. 021 for research) |
| exemptionName | string | Yes | Display name (e.g. 'Research & Development Exemption') |
| applicableTaxTypes | array | Yes | List of tax categories this exemption applies to (VAT, profit, withholding, etc.) |
| effectiveFrom | date | Yes | Start of exemption period |
| effectiveUntil | date | No | End of exemption period; null = ongoing |

**Relations:**
- → Organization (many-to-one)
- → ExemptionCertificate (many-to-one)

### TaxLot
**Schema.org:** `schema:MonetaryAmount`
_Individual tax line item: single transaction or aggregate category contributing to declaration. Tracks category, amount, and justification._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lotNumber | string | Yes | Unique identifier within declaration (e.g. VAT-001) |
| taxCategory | string | Yes | VAT standard/reverse/zero rate, profit, withholding, excise, etc. |
| amount | decimal | Yes | Gross or net tax amount |
| currency | string | Yes | EUR or other currency code |
| transactionDate | date | Yes | Date of underlying transaction or period start |
| description | string | No | Narrative or reference (e.g. invoice number, period) |

**Relations:**
- → TaxDeclaration (many-to-one)
- → BankAccount (many-to-one)

### TaxRate
**Schema.org:** `schema:Thing`
_Individual tax rate rules for income, sales, VAT, capital gains, or other tax types with effective date management_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| rateId | string | Yes | Unique rate identifier |
| rateType | string | Yes | Type of tax: income, sales, vat, capital_gains, tds, gst, or other |
| percentage | number | Yes | Tax rate as percentage |
| effectiveDate | datetime | Yes | Date when this rate becomes effective |
| expiryDate | datetime | No | Date when this rate expires or is superseded |

**Relations:**
- → TaxConfiguration (many-to-one)
- → Product (many-to-one)

### TaxReturn
**Schema.org:** `schema:Thing`
_A formal tax return filing for income, VAT, or other tax obligations with workflow management and compliance tracking_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| returnId | string | Yes | Unique identifier for the tax return |
| filingPeriod | string | Yes | Period covered by this return (e.g., Q1 2026) |
| taxYear | number | Yes | Calendar year for tax reporting |
| totalIncome | number | No | Total income for the period |
| totalExpenses | number | No | Total deductible expenses |
| status | string | Yes | Current status: draft, submitted, approved, or rejected |
| filedDate | datetime | No | Date when the return was submitted |

**Relations:**
- → Organization (many-to-one)
- → TaxConfiguration (many-to-one)

### TaxableTransaction
**Schema.org:** `schema:Thing`
_Business transaction classified and tracked for tax reporting, audit trail, and automated tax calculation with receipt scanning support_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionId | string | Yes | Unique transaction identifier |
| amount | number | Yes | Transaction amount |
| transactionDate | datetime | Yes | Date of the transaction |
| taxCategory | string | Yes | Tax classification category for reporting |
| taxRate | number | No | Applied tax rate percentage |
| description | string | No | Transaction description for audit trail |

**Relations:**
- → TaxReturn (many-to-one)
- → Receipt (many-to-one)
- → Payment (many-to-one)

### Team
**Schema.org:** `schema:Organization`
_Group of users organized for collaboration with shared access and permissions_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Team name |
| description | string | No | Team description and purpose |
| isActive | boolean | Yes | Whether the team is active |
| createdAt | datetime | No | Team creation date |

**Relations:**
- → Account (many-to-one)
- → User (many-to-many)

### Tender
**Schema.org:** `schema:Order`
_Digital solicitation request for goods or services from multiple suppliers_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Tender title |
| description | string | Yes | Detailed description of the tender scope |
| closingDate | datetime | Yes | Deadline for submitting bids |
| publicationDate | datetime | Yes | Date when tender was published |
| totalBudget | number | Yes | Total budget allocated for the tender |
| budgetCurrency | string | Yes | Currency code (EUR) |
| minimumQuoteCount | integer | Yes | Minimum number of required quotes |
| referenceNumber | string | Yes | Unique tender reference number (aanbestedingsnummer) |
| procurementType | string | Yes | Procurement procedure type (open, restricted, negotiated) |
| contactPerson | string | Yes | Name of responsible contact |
| contactEmail | string | Yes | Email address for inquiries |
| deliveryLocation | string | Yes | Address where goods/services are delivered |
| documents | array | Yes | Tender specifications and requirements documents |
| estimatedDuration | string | No | Contract duration (e.g., 24 months) |
| category | string | No | Category of goods or services |
| paymentTerms | string | No | Payment conditions |
| consultationDeadline | datetime | No | Deadline for clarification questions |
| contractStartDate | datetime | No | Planned contract start date |

**Relations:**
- → Supplier (many-to-many)
- → TenderLineItem (one-to-many)
- → Quote (one-to-many)
- → TenderDocument (one-to-many)

### TenderAmendment
**Schema.org:** `schema:DigitalDocument`
_Amendment to published tender, flagged as material or non-material change_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Amendment title |
| changeDescription | string | Yes | Detailed description of what was changed |
| isMaterialChange | boolean | Yes | True if material change requiring republication |
| publicationDate | date | Yes | Amendment publication date |
| tedReferenceId | string | No | TED/OJEU amendment reference ID |
| newClosingDate | date | No | New submission deadline if extended |

**Relations:**
- → TenderNotice (many-to-one)

### TenderDocument
**Schema.org:** `schema:DigitalDocument`
_Specifications, terms, and attachments for tender process_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| documentType | enum | Yes | Document role |
| uploadedDate | date | No | Upload date |
| requiredForBidding | boolean | No | Mandatory review before submitting quote |

**Relations:**
- → Tender (many-to-one)

### TenderLineItem
**Schema.org:** `schema:Product`
_Individual product or service line in tender request_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| description | text | Yes | Item or service description |
| quantity | number | Yes | Quantity needed |
| unitCode | string | Yes | Unit (pcs, kg, hours, etc.) |
| unitPrice | number | No | Estimated unit price (cents) |
| category | string | No | Product/service category |
| specifications | text | No | Technical or quality requirements |

**Relations:**
- → Tender (many-to-one)

### TenderLot
**Schema.org:** `schema:Thing`
_A distinct portion of a tender that can be evaluated and awarded separately with independent budgets and evaluation criteria_
**Primary spec:** tender-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lotNumber | string | Yes | Unique lot number or identifier within the tender |
| title | string | Yes | Title or description of the lot |
| description | string | No | Detailed scope of work or goods included in this lot |
| budgetAmount | number | No | Budget allocated to this specific lot |
| currency | string | No | Currency code for budget |
| status | string | No | Status: draft, open, evaluation, awarded, closed |
| evaluationCriteria | array | No | Weighted evaluation criteria with scoring rules |
| minParticipants | number | No | Minimum number of suppliers required |
| maxParticipants | number | No | Maximum number of suppliers allowed |

**Relations:**
- → Tender (many-to-one)
- → Bid (one-to-many)
- → Product (many-to-many)

### TenderNotice
**Schema.org:** `schema:DigitalDocument`
_Tender or procurement notice published to TED/OJEU and market platforms for public competition_
**Primary spec:** publication-platform-integration

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of the tender |
| tenderType | string | Yes | Type: SERVICES, SUPPLIES, WORKS, or CONCESSION |
| publicationDate | date | Yes | Date published |
| tedReferenceId | string | No | TED/OJEU publication ID |
| estimatedValue | number | No | Estimated contract value in EUR |
| closingDate | date | Yes | Submission deadline |
| scope | string | Yes | Geographic scope: EUROPEAN, NATIONAL, or REGIONAL |

**Relations:**
- → Organization (many-to-one)

### TimeEntry
**Schema.org:** `TimeEntry`
_Time tracking entries for project tasks including manual entry and timer-based tracking_
**Primary spec:** approval-workflow-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| entryId | string | Yes | Unique time entry identifier |
| taskId | string | Yes | Project task this time is logged against |
| projectId | string | Yes | Project associated with this entry |
| userId | string | Yes | Person/User ID who logged the time |
| date | datetime | Yes | Date of the time entry |
| duration | number | Yes | Duration in hours |
| description | string | No | Details of work performed |
| entryType | string | No | manual or timer |
| billable | boolean | No | Whether this time is billable to client |

**Relations:**
- → ProjectTask (many-to-one)
- → Project (many-to-one)
- → Person (many-to-one)

### Timesheet
**Schema.org:** `schema:Report`
_Periodic summary of time entries for an employee, aggregating hours and utilization metrics by week or month_
**Primary spec:** cost-accounting-allocation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| periodStart | datetime | Yes | Start date of the reporting period |
| periodEnd | datetime | Yes | End date of the reporting period |
| totalHours | number | Yes | Total hours logged in period |
| utilizationPercentage | number | No | Utilization rate as percentage of available hours |
| totalCost | number | No | Total cost based on hourly rates |
| status | string | Yes | Status: draft, submitted, or approved |
| submittedDate | datetime | No | Date when submitted for approval |
| approvedDate | datetime | No | Date when approved |

**Relations:**
- → Person (many-to-one)
- → TimeEntry (one-to-many)
- → ApprovalRequest (many-to-one)

### Transaction
**Schema.org:** `schema:Order`
_Financial transaction in the bookkeeping system (debit/credit entry)_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transactionNumber | string | Yes | Unique transaction reference |
| transactionType | string | Yes | Type: invoice, payment, transfer, credit |
| amount | decimal | Yes | Transaction amount |
| currency | string | Yes | ISO 4217 currency code |
| description | string | No | Transaction description/memo |
| transactionDate | date | Yes | Date of transaction |
| paymentTerms | string | No | Payment terms (e.g., net30) |
| orderStatus | string | Yes | Status: pending, completed, cancelled |

**Relations:**
- → Organization (many-to-one)
- → BankAccount (many-to-one)
- → PaymentFraudAssessment (one-to-many)

### TreasuryTask
**Schema.org:** `schema:Event`
_Unified AP/AR/spend task list for cash flow management with due dates and counterparty tracking_
**Primary spec:** treasury-cash-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taskType | string | Yes | AccountsPayable, AccountsReceivable, or CapitalExpenditure |
| amount | number | Yes | Transaction amount |
| currency | string | Yes | ISO 4217 code |
| dueDate | string | Yes | ISO 8601 date |
| counterpartyName | string | No | Vendor, customer, or counterparty |
| description | string | No | Task details and notes |

**Relations:**
- → CashAccount (many-to-one)
- → Organization (many-to-one)

### TrialBalance
**Schema.org:** `schema:Table`
_A report listing all general ledger accounts with debit or credit balances for verification and audit purposes_
**Primary spec:** financial-reporting-accountability

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportDate | datetime | Yes | Date of the trial balance |
| totalDebits | number | No | Total of all debit balances |
| totalCredits | number | No | Total of all credit balances |
| isBalanced | boolean | No | Whether debits equal credits |
| status | string | Yes | Status (draft, verified, final) |
| preparedBy | string | No | Name or identifier of person who prepared the trial balance |

**Relations:**
- → FiscalYear (many-to-one)
- → Organization (many-to-one)
- → GeneralLedgerEntry (one-to-many)

### User
**Schema.org:** `schema:Person`
_System account for authentication and access control with assigned permissions and team memberships_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| username | string | Yes | Unique username for login |
| email | string | Yes | Email address for the account |
| firstName | string | No | First name of the user |
| lastName | string | No | Last name of the user |
| isActive | boolean | Yes | Whether the account is active |
| twoFactorEnabled | boolean | No | Whether 2FA is enabled |
| createdAt | datetime | Yes | Account creation date |
| lastLogin | datetime | No | Date of last login |

**Relations:**
- → Person (many-to-one)
- → Team (many-to-many)
- → Role (many-to-many)
- → Account (many-to-many)
- → Entitlement (one-to-many)
- → UserPreference (one-to-many)

### UserPreference
_User-specific preferences for display settings, notifications, language, and other customization options_
**Primary spec:** access-control-authorisation

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| key | string | Yes | Preference key or identifier |
| value | string | Yes | Preference value |
| category | string | No | Category of preference (display, notification, language, accessibility) |
| updatedAt | datetime | No | Last update date |

**Relations:**
- → User (many-to-one)

### VATReturn
**Schema.org:** `schema:Thing`
_VAT-specific tax return showing collected VAT, paid VAT, and net amount due for MTD compliance and electronic filing_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| vatReturnId | string | Yes | Unique VAT return identifier |
| reportingPeriod | string | Yes | VAT reporting period: monthly, quarterly, or annually |
| collectedVAT | number | Yes | VAT collected from customers |
| paidVAT | number | Yes | VAT paid on business purchases and expenses |
| netAmount | number | Yes | Net VAT payable (positive) or refundable (negative) |
| status | string | Yes | Status: draft, submitted, approved, or rejected |
| submissionDate | datetime | No | Date when VAT return was submitted to authorities |

**Relations:**
- → Organization (many-to-one)
- → TaxReturn (many-to-one)

### VendorBill
**Schema.org:** `schema:Invoice`
_Vendor invoice with approval workflow before payment processing_
**Primary spec:** supplier-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| billNumber | string | Yes | Unique vendor bill identifier |
| invoiceDate | datetime | Yes | Date the invoice was issued |
| dueDate | datetime | Yes | Payment due date |
| totalAmount | number | Yes | Total invoice amount |
| currency | string | Yes | Currency code |
| status | string | Yes | Bill status: received, approved, rejected, or paid |
| approvalStatus | string | Yes | Approval workflow status: pending, approved, or rejected |
| poReference | string | No | Reference to linked purchase order |

**Relations:**
- → Supplier (many-to-one)
- → PurchaseOrder (many-to-one)
- → ApprovalRequest (one-to-one)
- → Payment (one-to-one)
- → Document (one-to-many)

### WOZAssessment
**Schema.org:** `schema:Assessment`
_Property tax valuation assessment (Waardering Onroerende Zaken) with automated model generation_
**Primary spec:** mid-market-mkb

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| assessmentYear | string | Yes | Tax year |
| assessedValue | number | Yes |  |
| valuationMethod | string | No |  |
| assessmentDate | datetime | Yes |  |
| status | string | Yes | draft, finalized, appealed, approved |
| notificationSentDate | datetime | No | Date owner notification was sent |

**Relations:**
- → Property (many-to-one)

### XBRLInstance
**Schema.org:** `schema:DigitalDocument`
_Structured XBRL instance document for taxonomies (NTA7, SBR-NT). Contains facts, contexts, and dimensions for standardized digital reporting to Dutch authorities._
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taxonomyVersion | string | Yes | e.g. NTA7-2025, SBR-NT-2025 |
| instanceID | string | Yes | Unique document identifier |
| reportingPeriod | string | Yes | ISO date range (e.g. 2025-01-01/2025-12-31) |
| factCount | integer | No | Number of XBRL facts in instance |
| encodingFormat | enum | Yes | application/xbrl+xml or application/xbrl+json |
| validationStatus | enum | Yes | valid, invalid, warned, unvalidated |

**Relations:**
- → TaxDeclaration (many-to-one)

### XBRLTaxonomy
**Schema.org:** `schema:CreativeWork`
_XBRL (eXtensible Business Reporting Language) taxonomy definitions for structured tax reporting, compliance, and regulatory filing_
**Primary spec:** tax-levy-management

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taxonomyId | string | Yes | Unique taxonomy identifier |
| version | string | Yes | Taxonomy version number |
| effectiveDate | datetime | Yes | Date when taxonomy becomes effective |
| namespace | string | Yes | XML namespace URI for the taxonomy |
| elements | array | No | List of XBRL element definitions and mappings |

**Relations:**
- → TaxReturn (one-to-many)

### ADR-001-bookkeeping-tier-roadmap
# ADR-001: Bookkeeping tier roadmap — canonical 5-tier breakdown

**Status:** Accepted
**Date:** 2026-05-17

## Context

Shillinq pivoted from a customer-invoicing app to a full double-entry
bookkeeping engine for Dutch SMB (MKB), self-employed (ZZP), and
decentralised government (gemeenten, waterschappen, provincies). The
resulting surface area is large: 42 capability specs across 5
proposals in this single PR, with another tier (T5) explicitly
deferred. Rolling all of it as one change is not reviewable; rolling
it as 42 independent changes loses the dependency structure.

The chosen middle ground is a **tiered rollout**: each tier is one
OpenSpec change, every capability inside a tier is one spec file, and
the tiers chain via documented dependencies. Each of the five
proposals in this PR initially published its own variant of the
5-tier table — and they disagreed (T1 said T2=sub-ledgers, T2 said
T3=tax+sector, T3 said T4=reporting+analytics, T4-base said
T5=cross-cutting/specialised). Three proposals also referenced
phantom change slugs that were never created
(`add-shillinq-bookkeeping-subledgers`,
`add-shillinq-bookkeeping-period-close`,
`add-shillinq-bookkeeping-reporting`,
`add-shillinq-bookkeeping-multicurrency-and-tax`,
`add-shillinq-bookkeeping-subledgers-close-statements`).

This ADR fixes the breakdown in one place. Every proposal links here
instead of re-publishing its own table.

## Decision

There is **one canonical 5-tier breakdown** for the Shillinq
bookkeeping rollout, listed below. The 5 changes in this PR cover
**T1 through T4-specialized**. T5 is forward-looking and explicitly
empty in this PR; it is tracked separately and not implied by any
in-flight change in this envelope.

| Tier | Change slug | Scope | Capabilities |
|------|-------------|-------|--------------|
| **T1** | `add-shillinq-bookkeeping-foundation` | Foundation | `bookkeeping-chart-of-accounts`, `bookkeeping-general-ledger`, `bookkeeping-journal-entries` (3 specs) |
| **T2** | `add-shillinq-bookkeeping-compliance` | Sub-ledgers + period machinery | `bookkeeping-trial-balance`, `bookkeeping-period-close`, `bookkeeping-accounts-payable-core`, `bookkeeping-accounts-receivable-core`, `bookkeeping-financial-statements`, `bookkeeping-audit-trail` (consume from OR), `bookkeeping-document-attachment-integration` (consume from docudesk), `bookkeeping-bank-reconciliation` (8 specs) |
| **T3** | `add-shillinq-bookkeeping-operations` | Operations + NL regulatory core | `bookkeeping-vat-btw-filing`, `bookkeeping-bbv-compliance`, `bookkeeping-iv3-reporting`, `bookkeeping-bcf-vat-compensation`, `bookkeeping-kor-kleine-ondernemersregeling`, `bookkeeping-zzp-tax-regime`, `bookkeeping-schatkistbankieren`, `bookkeeping-subsidie-verantwoording`, `bookkeeping-archiefwet-retention`, `bookkeeping-consultancy-project-accounting` (10 specs) |
| **T4-base** | `add-shillinq-bookkeeping-advanced` | Advanced engine features | `bookkeeping-sbr-xbrl-reporting`, `bookkeeping-fixed-assets-depreciation`, `bookkeeping-multi-currency`, `bookkeeping-cost-centers-dimensions`, `bookkeeping-year-end-close`, `bookkeeping-bank-connectors`, `bookkeeping-reconciliation-reports` (7 specs) |
| **T4-specialized** | `add-shillinq-gov-sector-mkb-advanced` | NL gov sector variants + Vpb + MKB innovation + detachering | `bookkeeping-waterschappen-bbv-variant`, `bookkeeping-provincies-bbv-variant`, `bookkeeping-gr-consolidation`, `bookkeeping-rekenkamer-audit-pack`, `bookkeeping-cbs-bestanden-extended`, `bookkeeping-emu-reporting`, `bookkeeping-sisa-reporting`, `bookkeeping-market-government-separation`, `bookkeeping-vpb-corporate-tax`, `bookkeeping-innovatiebox-administratie`, `bookkeeping-investeringsaftrek`, `bookkeeping-wbso-sno-administratie`, `bookkeeping-r-d-subsidies-mkb`, `bookkeeping-detachering-payroll-administratie` (14 specs) |
| **T5** | _(future, not in this PR)_ | Cross-cutting + e-invoicing + treasury | UBL/Peppol BIS 3.0 outbound for AR, intercompany eliminations, advanced group consolidation, treasury cash forecasting, IFRS rebridge, multi-administration aggregation. **Explicitly OUT of this PR; tracked separately.** |

### Build order

T1 → T2 → T3 → T4-base / T4-specialized (the two T4 changes may
land in parallel; T4-specialized depends on selected T2/T3/T4-base
capabilities but not on the entirety of T4-base).

### Dependency annotations

Where a spec lists `Depends on:` in its header (or where a proposal
narrative cross-references a sibling), the `(T1)` / `(T2)` / etc.
annotations refer to **this table**. A reference like
"depends on `bookkeeping-trial-balance` (T2)" means the
`bookkeeping-trial-balance` capability lives in the T2 change
(`add-shillinq-bookkeeping-compliance`).

### VAT/BTW lands in T3, not T5

Earlier drafts of the T1 proposal deferred VAT/BTW posting automation
to T5. That was a drafting error: VAT/BTW filing ships in T3 as
`bookkeeping-vat-btw-filing` (under `add-shillinq-bookkeeping-operations`).
T1 has no VAT/BTW surface — neither in scope nor as a deferred
out-of-scope item — beyond the plain `vatApplicable` boolean on the
`Account` schema that downstream tiers consume.

## Consequences

### Positive

- **One source of truth.** Every proposal links here; there is no
  per-proposal table to drift.
- **Spec readers reason about tier ownership in one place.** A reader
  who sees "(T3)" next to a slug can look up exactly which change
  envelope owns it.
- **Phantom slugs are killed.** Future references to
  `add-shillinq-bookkeeping-subledgers`,
  `…-period-close`, `…-reporting`,
  `…-multicurrency-and-tax`, or
  `…-subledgers-close-statements` are review-blocking — those slugs
  do not exist and never will.

### Negative

- **One more file to maintain.** When a capability moves between
  tiers, this table updates. The cost is small (one edit per move,
  caught immediately by reviewer rather than slowly drifting across
  five proposals).

### Migration

This ADR supersedes any per-proposal "5-tier rollout" table. Those
tables MUST be removed from the five proposals and replaced with a
one-line link to this ADR. The replacement was done in the same PR
that introduces this ADR (see the proposals under
`openspec/changes/add-shillinq-bookkeeping-*` and
`openspec/changes/add-shillinq-gov-sector-mkb-advanced`).

## See also

- `adr-000-data-model.md` — the 225-entity catalogue every tier
  consumes.
- `hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md`
  — declarative-first principle every tier follows.
- `hydra/openspec/architecture/adr-032-spec-sizing-and-chaining.md` —
  `kind:` taxonomy and chain primitive that the tier breakdown
  rests on.
- `hydra/openspec/architecture/adr-024-app-manifest.md` — manifest
  shape every tier extends.
- `hydra/openspec/architecture/adr-022-apps-consume-openregister-abstractions.md`
  — RBAC / audit / retention consumption every tier inherits.
