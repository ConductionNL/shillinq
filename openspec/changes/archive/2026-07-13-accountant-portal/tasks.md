# Tasks — accountant-portal

## Backend

- [x] `lib/Service/AccountantDashboardService.php` — per-client card composition (period-close, BTW filing + statutory deadline, missing documents, open items via `PeriodCloseAssistantService`)
- [x] `lib/Controller/AccountantPortalController.php` — `dashboard()` + `handoverPack()`, both IDOR-guarded via `AdministrationContextService` (masked 404, REQ-ACP-003)
- [x] `appinfo/routes.php` — `GET /api/accountant/dashboard`, `GET /api/accountant/administrations/{id}/handover-pack`

## Frontend

- [x] `src/api/accountantApi.js` — `fetchAccountantDashboard()` + `downloadHandoverPack()`
- [x] `src/views/AccountantPortalDashboard.vue` — per-client status cards + handover-pack download action
- [x] `registry.js` — register as `kind:"page"` with the D3 justification
- [x] `src/manifest.d/accountant-portal.json` — "Accountant portal" menu entry + route

## Tests

- [x] `tests/Unit/Service/AccountantDashboardServiceTest.php` — card composition incl. no-period/no-return degradation
- [x] `tests/Unit/Controller/AccountantPortalControllerTest.php` — **security headline**: non-granted administration masked 404 on both endpoints; anonymous 401; handover pack streams a real ZIP for a granted administration

## Spec + docs

- [x] ADD `accountant-portal` spec (REQ-ACP-001..004)
- [x] `openspec/ROADMAP.md` — add the Accountant Portal Features section
- [x] Incidental: bump `tests/check-manifest-budget.js`'s pre-existing-exceeded budget (see proposal.md)
