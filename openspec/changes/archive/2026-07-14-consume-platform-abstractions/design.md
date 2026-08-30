# Design: consume-platform-abstractions

## Architecture Overview

```
Item 1 — Flow + Talk leaves
  openregister/src/main.js  ──calls──▶ installIntegrationRegistry()
                                       registerBuiltinIntegrations()   (files/notes/tags/tasks/audit-trail/talk)
                                       registerLeafIntegrations()      (18 leaves incl. "flow")
                                       └─▶ window.OCA.OpenRegister.integrations  (global singleton)
  shillinq/src/main.js  ──MISSING the same three calls──▶  (registry never populated
                                                             on /apps/shillinq routes,
                                                             because NC only loads the
                                                             ACTIVE app's own JS bundle)
  FIX: shillinq/src/main.js calls the same three functions itself (decidesk/pipelinq
  precedent) → CnObjectSidebar's `useRegistry` (default true) then renders one tab
  per registered provider — including `flow` and `talk` — on every detail page that
  does not already override `sidebarProps.tabs` with a custom, curated tab list.

Item 2 — Missing-document worklist
  GET /api/objects/shillinq/SupplierInvoice?sourceDocumentUri_isnull=true
     (openregister/openspec/specs/zoeken-filteren/spec.md — backend-agnostic
      `_isnull` operator suffix, works against Postgres/Solr/Elasticsearch alike)
  shillinq manifest `type:"index"` page → `config.filter: {"sourceDocumentUri": null}`
     (already-used idiom: src/manifest.json `UnmatchedItems` page,
      `filter: {"resolutionStatus": null}`)

Item 4 — Public REST API
  Third party ──▶ openconnector Endpoint (targetType: register/schema) ──▶
      EndpointService::handleSchemaRequest() ──▶ OpenRegister register=shillinq
  Auth: openconnector Consumer (apiKey/JWT, rate limit, domain scope) — per
      openconnector/openspec/specs/consumer-management/spec.md REQ-CON-001/REQ-CON-RL-001
```

## Item-by-item

### 1. NC Flow + Talk leaves

**Already provided (verified against HEAD):**
- `openregister/lib/Service/Integration/Providers/FlowProvider.php:61` — `getId()` returns `'flow'`; spec `openregister/openspec/specs/integration-flow/spec.md` status `done`.
- `openregister/lib/Service/Integration/Providers/TalkProvider.php:73` — `getId()` returns `'talk'`; spec `openregister/openspec/specs/integration-talk/spec.md` status `done`.
- `openregister/src/main.js:17,64` already calls `ensureIntegrationRegistry()` → `registerBuiltinIntegrations(registry); registerLeafIntegrations(registry);` (`openregister/src/integrations/bootstrap.js:45-46`).
- The generic surface (`CnObjectSidebar` registry mode, default `useRegistry: true`) already exists in `@conduction/nextcloud-vue` (installed version `1.0.0-beta.194`, confirmed the three functions are present in the shipped CJS bundle).

**What was actually missing (corrected from the audit's framing):** the audit said "shillinq needs only manifest entries." That is imprecise. `shillinq/src/manifest.json` has zero `flow`/`talk` references, but the real gap is one level lower: `shillinq/src/main.js` never calls `installIntegrationRegistry()` / `registerBuiltinIntegrations()` / `registerLeafIntegrations()` — the exact three-line bootstrap `decidesk/src/main.js:51-53` and `pipelinq` already run. Nextcloud only loads the currently-active app's own JS bundle, so OpenRegister's own bootstrap (which populates the SAME global singleton) never executes on `/apps/shillinq/*` routes. Without shillinq calling these itself, the registry is empty there regardless of any manifest.json content — a per-object "manifest entry" naming `flow`/`talk` would have nothing to resolve against.

**What was added:** the same three-call bootstrap in `shillinq/src/main.js`, verbatim pattern from decidesk. This activates ALL registered leaves (not just flow/talk) on every detail page that does not already declare a custom `sidebarProps.tabs` override — which is the majority of shillinq's ~94 schema-driven detail pages. Pages that already curate a bespoke tab list (e.g. `PurchaseOrderDetail`'s `sidebarProps.tabs: [{id:"audit", …}]`) keep their curated set — `tabs` wins over registry mode by design (`CnObjectSidebar.isRegistryMode`), so this fix does not silently add tabs to pages that deliberately opted out of the generic surface. No such page was touched.

No manifest.json edit was made for this item — the fix is the bootstrap call, not a page-level entry. This is stated explicitly here because it corrects the audit's phrasing; the effect (Flow + Talk become visible) is the one the audit asked for.

### 2. Missing-document worklist

**Already provided:** `openregister/openspec/specs/zoeken-filteren/spec.md` REQ (line 52-76) — `SearchQueryHandler::cleanQuery()` normalizes the `_isnull` operator suffix into `WHERE <field> IS NULL`, backend-agnostic (Postgres/Solr/Elasticsearch). shillinq already uses the equivalent manifest idiom: `src/manifest.json`'s `UnmatchedItems` page declares `"filter": {"resolutionStatus": null}`.

**Verification of the audit's caveat** ("can OR's query language express 'has no attached file'?"): yes, via `_isnull`/`filter: {field: null}` on the `sourceDocumentUri` field — a plain scalar URI property already present on both `SupplierInvoice` and `Receipt` (added by the already-shipped `receipt-extraction-consume` change). "Aged" is expressed as default sort order (oldest invoice/receipt date first), not a hard date cutoff — OR's simple `filter` object has no relative-date binding (`$now`-style placeholders are an aggregation-only capability per `zoeken-filteren/spec.md:573`, not available on a plain list-page filter).

**What was added:** two `type:"index"` pages (`SupplierInvoicesMissingDocument`, `ReceiptsMissingDocument`) in a new `src/manifest.d/consume-platform-abstractions-missing-document-worklist.json` fragment, plus two `Bookkeeping` menu children. No new schema fields (`sourceDocumentUri` already exists), no PHP, no worklist service.

**Not the literal `saved-search-views` mechanism:** that capability (`/api/views`, favoriting, default-view auto-apply) is implemented entirely inside OpenRegister's OWN `SearchSideBar.vue` / `viewsStore` and is not exported as a reusable nc-vue component a leaf app can embed (confirmed: no `api/views` or `viewsStore` reference anywhere in `@conduction/nextcloud-vue`'s `src/`). A shillinq user would need to open OpenRegister's own object browser to use a persisted `/api/views` saved view. Using shillinq's own established `type:"index"` + declarative `filter` idiom achieves the same "missing-document worklist" outcome inside shillinq's own navigation, with zero PHP, and is the same mechanism the audit itself named as the fallback ("if not, the leaf is one OR filter operator, not a shillinq worklist").

### 3. Inventory quarantine status — REFUSED, filed as shillinq#443

Verified against HEAD and found **false**:
- No PHP anywhere mirrors `Product.status` onto `InventoryStock.status` — `StockReservationGuard::createInventoryStock()` (`lib/Lifecycle/StockReservationGuard.php:421-441`), `InventoryMobileScannerService` (`:718-728`), and `InventoryScanService` all default/hardcode `status: 'active'` without reading `Product.status`. REQ-IST-011 (`openspec/specs/inventory-stock-tracking/spec.md:220-238`) describes a mirror that documents intent, not implemented behaviour.
- `InventoryStock.status` (`lib/Settings/register.d/inventory-stock-tracking.json:108`) only has `active`/`discontinued` — no `damaged`/`quarantined` value exists on this schema. The quarantine state machine that DOES exist is on the sibling `InventoryLot.lotStatus` (`lib/Settings/register.d/inventory-lot-batch-expiry.json:196-233`: `active`/`quarantined`/`expired`/`exhausted`, "Not pickable" on quarantined). The audit named the wrong schema.
- Even granting a schema-level fix on the right field: `SalesDispatchStockIssueService::inventoryStockRows()` filters only by `administrationId` + `sku` and never inspects `status`/`lotStatus`; the reservation path in `StockReservationGuard` never inspects it either. A `x-openregister-lifecycle` transition guard on InventoryStock/InventoryLot's OWN state field would not intercept a sale, because `SalesDispatchStockIssueService` never transitions that object — it only reads rows and writes a separate `StockMove`. Blocking a sale genuinely requires an explicit status check inside the dispatch service — real PHP.
- Even a guard-based declarative approach is dead today for this schema family: OR's `TransitionEngine` only enforces guards resolved via a transition's `requires` tag through `LifecycleGuardRegistry`; the `"validations"`/`"actions"` blocks already present in these JSON files are not read by OR at all, and the three stock-related guard classes are explicitly excluded from the 17 registered via `RegisterRequiresGuardAdapter` in `lib/AppInfo/Application.php:754-937` (comment cites already-open shillinq#433, "intentionally not fixed here").

Per this change's own discipline ("if any item turns out to require real PHP, STOP that item and report it"), item 3 is not implemented here. Filed as shillinq#443 (Codeberg, pre-migration, not migrated to GitHub) with the full trace above so a future change can scope the real (small) feature: decide InventoryStock vs InventoryLot as the quarantine axis, add the explicit check in `SalesDispatchStockIssueService`, and resolve shillinq#433 if a declarative guard is still wanted alongside the PHP check.

### 4. Public REST API exposure — mechanism confirmed, config REFUSED as a boundary crossing; docs added

**Already provided:** `openconnector/lib/Service/EndpointService.php:364` → `handleSchemaRequest()` (`:1052`) is real, generic, and needs zero new PHP to serve `register=shillinq` — confirmed against HEAD. `Endpoint` and `Consumer` are plain OpenRegister objects in openconnector's own self-registered register (`openconnector/lib/Settings/openconnector_register.json`), not bespoke entities (`lib/Db/Endpoint.php` was removed in favour of a generic `ObjectEntity`).

**What was refused, and why (three independent reasons):**
1. `Endpoint` is only declaratively importable as an openconnector **"Configuration bundle"** (`openconnector/configurations/*/`, processed by `ConfigurationService` + `EndpointHandler::import()`, slug→numeric-ID resolution). That folder lives in the **openconnector repository** — shillinq has no config surface openconnector's importer reads from a sibling app. Per the house rule for this item ("If the Endpoint/Consumer must be created in the openconnector repo rather than shillinq's config, say so and STOP"), this is exactly that case.
2. `Consumer` has **no** import handler at all (`ConfigurationService`'s registry: `endpoint | synchronization | mapping | job | source | rule` — no `consumer`). Creating one requires an imperative `POST /apps/openregister/api/objects/openconnector/consumer` call, not a config file — automating it from shillinq would mean writing new PHP (a Repair step), explicitly out of scope for a config-only change.
3. **The `apiKey` auth path does not actually consult `Consumer.authorizationType` at runtime** — verified by reading `AuthorizationService.php` and `EndpointService::processAuthenticationRule()`: apiKey auth is enforced by a separate `rule` object with its own hardcoded key→user map, never touching the Consumer record. Only the JWT path genuinely ties back to a Consumer. Creating a Consumer with `authorizationType: "apiKey"` today would configure a control that looks real but enforces nothing — worse than not building it.

Filed as openconnector#159 (Codeberg, pre-migration, not migrated to GitHub): wire `apiKey`/`basic`/`oauth2` to the Consumer record, add a `ConsumerHandler` to the Configuration-bundle mechanism, verify `domains`/`ips` enforcement, and (lower priority) an OAS generator for the gateway's own paths.

**What was added:** `docs/api/third-party-rest-access.md` — documents the real path/auth shape (`/apps/openconnector/api/endpoint/{path}` vs. OpenRegister's own `/apps/openregister/api/objects/{register}/{schema}` OAS, which describes different paths and an NC-group OAuth2/Basic scheme that a non-Nextcloud third party cannot use), states the JWT-vs-apiKey caveat plainly, and includes an illustrative (not committed, not functional) Configuration-bundle JSON shape for when the openconnector-side gaps are resolved. This satisfies the audit's explicit documentation ask without shipping a broken or boundary-crossing config.

## Declarative vs. imperative (ADR-031)

| Item | Mechanism | PHP added |
|---|---|---|
| 1. Flow + Talk | JS bootstrap call (3 lines, library-exposed function, no business logic) + zero manifest edits | None |
| 2. Missing-document worklist | `type:"index"` manifest page + `filter: {field: null}` (existing OR REST operator) | None |
| 3. Inventory quarantine | **Refused** — genuinely needs a PHP status check in `SalesDispatchStockIssueService`; not built here | N/A (not built) |
| 4. Public REST API | **Refused** — config surface lives in the openconnector repo, not shillinq's; docs added instead | None (not built) |

No new PHP service classes, controllers, or lifecycle guard implementations are added by this change. The only source file touched outside `src/manifest.d/*.json` / `docs/` is `src/main.js` (JS bootstrap wiring), which contains no business logic — it is a call to library-exported registration functions, identical to the pattern already shipped in decidesk and pipelinq.

## Seed Data

No new OpenRegister objects are seeded by this change:
- Item 1 activates existing PHP-side leaf registrations; no new objects.
- Item 2's worklist pages are live filtered views over existing `SupplierInvoice`/`Receipt` objects — no seed rows needed for the pages to function (any existing invoice/receipt lacking `sourceDocumentUri` shows up immediately). For local verification, two `receipt-extraction-consume` seed rows already ship (per that change's design.md) but they carry a non-null `sourceDocumentUri`, so they do NOT match the new worklist filter (correct — those are extraction drafts WITH a source document; the worklist wants the ones WITHOUT one). Manual verification uses the dev instance's pre-existing supplier invoices that predate `receipt-extraction-consume` and therefore have no `sourceDocumentUri`.
- Item 4: no seed data — refused (see §4). The illustrative Configuration-bundle JSON in `docs/api/third-party-rest-access.md` uses placeholder values only (`YOUR_CONSUMER_NAME_HERE`, `YOUR_JWT_PUBLIC_KEY_HERE`) and is not committed as an active config file.
