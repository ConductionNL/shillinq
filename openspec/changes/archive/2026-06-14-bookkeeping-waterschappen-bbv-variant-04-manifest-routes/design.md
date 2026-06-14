# Design — Member 04: manifest + routes

## Scope

This `kind: code` member adds the manifest navigation entries and the
`appinfo/routes.php` registrations + thin controllers that later UI
members bind to. It renders no widget or form — just the skeleton.

## Decisions carried from the giant

- **D4** — the dashboard is a `CnDashboardPage` mounted via the
  manifest; this member only declares its navigation entry + route.
- Routes are registered **only** in `appinfo/routes.php` (ADR-016) —
  no `Application.php` route hacks, no in-router admin pages
  (hydra-gate-gate-admin-router).

## Reuse

| Capability | Existing | Strategy |
|---|---|---|
| Navigation | `src/manifest.json` (ADR-024) | add 2 entries |
| Routing | `appinfo/routes.php` | register 3 GET routes |
| Controller base | NC `Controller` | thin `DashboardController` |

## Route auth (ADR-005 / hydra-gate-route-auth)

| Route | Auth attribute |
|---|---|
| `GET /bbv-dashboard` | `#[NoAdminRequired]` (logged-in finance officer) |
| `GET /budget-mappings` | `#[NoAdminRequired]` |
| `GET /budget-mappings/:id` | `#[NoAdminRequired]` |

Mutating mapping writes go through OpenRegister's object endpoints
(admin-write per member 01 permissions); this member registers only the
read/page routes. Every controller method declares an explicit auth
attribute — no implicit-default endpoints.

## Security (ADR-005)

Page controllers return view data only; all data mutation is mediated
by OpenRegister with its RBAC + validation (members 01/03). No
per-object IDOR surface is introduced here.
