# Tasks — Member 04: manifest + routes

Sourced from the giant's Phase 2 (Dashboard Integration) and Phase 3
(Routing) — navigation + route skeleton only.

## Dashboard navigation + route

- [ ] Add dashboard entry to `src/manifest.json` (title "BBV Compliance Dashboard", icon, order after main dashboard)
- [ ] Register dashboard route in `appinfo/routes.php`: `GET /index.php/apps/shillinq/bbv-dashboard`
- [ ] Create thin `DashboardController::index()` returning the widget-data envelope
- [ ] Declare the dashboard route auth attribute (`#[NoAdminRequired]`)

## Budget mapping navigation + routes

- [ ] Add Budget Mapping entry to `src/manifest.json` (title "Budget Mapping", index + detail pages)
- [ ] Register `GET /index.php/apps/shillinq/budget-mappings` → index route
- [ ] Register `GET /index.php/apps/shillinq/budget-mappings/:id` → detail route
- [ ] Declare auth attributes on both mapping routes (`#[NoAdminRequired]`)

## Verification

- [ ] Verify all 3 routes are reachable and declared only in `appinfo/routes.php` (ADR-016)
- [ ] Verify each controller method has an explicit auth attribute (hydra-gate-route-auth)
