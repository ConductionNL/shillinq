# Tasks — Member 04: manifest + routes

Sourced from the giant's Phase 2 (Dashboard Integration) and Phase 3
(Routing) — navigation + route skeleton only.

## Dashboard navigation + route

- [x] Add dashboard entry to `src/manifest.json` (title "BBV Compliance Dashboard", icon, order after main dashboard)
- [x] Register dashboard route in `appinfo/routes.php`: `GET /index.php/apps/shillinq/bbv-dashboard`
- [x] Create thin `DashboardController::index()` returning the widget-data envelope
- [x] Declare the dashboard route auth attribute (`#[NoAdminRequired]`)

## Budget mapping navigation + routes

- [x] Add Budget Mapping entry to `src/manifest.json` (title "Budget Mapping", index + detail pages)
- [x] Register `GET /index.php/apps/shillinq/budget-mappings` → index route
- [x] Register `GET /index.php/apps/shillinq/budget-mappings/:id` → detail route
- [x] Declare auth attributes on both mapping routes (`#[NoAdminRequired]`)

## Verification

- [x] Verify all 3 routes are reachable and declared only in `appinfo/routes.php` (ADR-016)
- [x] Verify each controller method has an explicit auth attribute (hydra-gate-route-auth)
