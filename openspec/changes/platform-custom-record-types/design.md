# Design: platform-custom-record-types

Read at shillinq development `83d19fc8d` and nextcloud-vue development
`3a636a1c4` on 2026-09-28.

## Context

- **Shipped schemas.** Shillinq imports its register from `lib/Settings/shillinq_register.json` plus every fragment in `lib/Settings/register.d/`; the union of their `components.schemas` slugs is the set the app ships.
- **Custom schemas.** Open Register's runtime schema API (archived there as `2026-06-14-openregister-runtime-schema-api`) lets an administrator add a schema to a register. A schema added to the `shillinq` register that is not in the shipped set is a custom record type.
- **Pages.** `src/manifest.json` pages are static. nextcloud-vue `CnPageRenderer.vue:1140` runs `resolveRouteSentinels` over page config, so `"schema": "@route.schema"` resolves from the URL.
- **Settings.** The admin settings page is rendered by `lib/Settings/AdminSettings.php` (ADR-004: admin settings are not router pages).

## Goals / Non-Goals

**Goals**
- An administrator's own record types are usable in shillinq without a release.

**Non-Goals**
- Schema editing, ledger integration, custom fields on shipped schemas.

## Decisions

### D1. Custom means not shipped

`lib/Service/CustomRecordTypeService.php`: `shippedSlugs()` reads the slugs
from the shipped register files once per request; `customTypes()` returns
the schemas of the `shillinq` register whose slug is not shipped;
`shownTypes()` intersects them with the app config key
`custom_record_types_shown` (a JSON list of slugs).

### D2. Two endpoints

`GET /api/custom-record-types` (any shillinq user) returns the shown types
with title, description and icon. `PUT /api/custom-record-types`
(admin only, `#[AuthorizedAdminSetting]`) stores the shown list and refuses
a slug that is shipped or does not exist.

### D3. Three pages

- `CustomRecordTypes` (`/records`), a custom page with a card per shown type, in the menu as "Eigen gegevens" (hidden when the list is empty).
- `CustomRecords` (`/records/:schema`), an index page with `register: shillinq`, `schema: "@route.schema"` and columns from the schema.
- `CustomRecordDetail` (`/records/:schema/:id`), a detail page with the same sentinel.

Both generic pages first ask the read endpoint whether the slug is shown and
render an empty state otherwise. Open Register still applies its own
authorisation to every object read and write.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Which types show | Imperative service plus app config | Compares the live register with the shipped files. |
| List and detail | Declarative manifest with route sentinels | Existing renderer capability. |

## Seed Data

None shipped; the Playwright test creates a schema `Parkeerplaats` with
`nummer` and `huurder` in the shillinq register through Open Register's
API, shows it, and adds one record.

## Risks / Trade-offs

- [A later shillinq release ships a schema with the same slug] → it becomes shipped and drops out of the custom list; the settings section says so.

## Migration Plan

None.

## Open Questions

None.
