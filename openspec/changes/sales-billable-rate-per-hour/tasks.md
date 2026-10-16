# Tasks: sales-billable-rate-per-hour

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 10. -->

## 1. Data

- [ ] 1.1 Add `billingRoleId` to `ProjectAssignment` and point `rateCardId`'s description at `RateCardTemplate` in `lib/Settings/register.d/` (a new fragment `sales-billable-rate-per-hour.json`), with the Het Anker seed of design.md (REQ-BRPH-001, REQ-BRPH-004). Verify: `npm run check:registers`, `npm run check:seeds`; bump `appinfo/info.xml` `<version>` if a repair step reads it (gate 110).

## 2. Resolver

- [ ] 2.1 Add `lib/Service/BillableRateService.php`: assignment lookup, effective `RateCardVersion`, tier match over `RateSchedule` per design D3, the `no-rate` answer with its four reasons, and a `RateRecord` write per resolution (REQ-BRPH-001, REQ-BRPH-002). Verify: PHPUnit for each tier, the mid-month change, each no-rate reason, a cross-administration card that never resolves, and the RateRecord fields.
- [ ] 2.2 Remove `RateCardResolver::fallbackRate()`; keep the `RateCard` read only for an existing `BillableInvoice` that names a `RateCard` id (REQ-BRPH-002). Verify: PHPUnit that an unknown card returns no rate, not 10000 cents.
- [ ] 2.3 Fill `ProjectAssignment.recognisedRate` from `BillableRateService` on assignment create and update and on `RateCardVersion` activation (REQ-BRPH-004). Verify: PHPUnit with a real `ObjectUpdatedEvent` (never a faked event) for the role change of the scenario.

## 3. Billing

- [ ] 3.1 Make `InvoiceGenerationService::loadTimeEntries()` rate each hour through `BillableRateService`, refuse a draft holding an unrated hour with 422 naming person and date, and write `rateApplied` per line (REQ-BRPH-002, REQ-BRPH-005). Verify: PHPUnit for the EUR 1,320 two-rate invoice and the refused draft; controller test through the real middleware that the status is 422.
- [ ] 3.2 Let the `BillableHoursSource` rows of `sales-time-and-expense-billing` carry the resolution (rate, tier, value or no-rate reason), and hide the rate card select on `InvoiceGenerator.vue` for the time and material model (REQ-BRPH-005). Verify: Vitest for the payload and the hidden select.

## 4. Project page

- [ ] 4.1 Add `GET /api/v1/projects/{id}/billable-hours?month=YYYY-MM` (`#[NoAdminRequired]` with the administration check on the project first) returning rows and totals per design D5 (REQ-BRPH-003). Verify: controller PHPUnit calling the method, including a project of another administration refused and the hours-source-unavailable answer.
- [ ] 4.2 Add the `project-billable-hours` custom widget to `ProjectDetail` in `src/manifest.json` (layout cell after `project-hours`) and its Vue component under `src/components/project/`, with month picker, billed state, totals and the not-connected empty state (REQ-BRPH-003). Verify: Vitest for totals; `npm run check:manifest`.
- [ ] 4.3 Add `billingRoleId` to the Hours per assignment list and its edit form (REQ-BRPH-004). Verify: Vitest or manifest check that the column renders.

## 5. Tests and docs

- [ ] 5.1 Playwright: open Renovatie Kade 12, read the October totals (12.5 rated hours, EUR 1,510, 1 hour without a rate), then draft an invoice for Alice and Bram and read EUR 1,320 (REQ-BRPH-003, REQ-BRPH-005). Tag each test with `@e2e` references to the scenarios.
- [ ] 5.2 Update the rate card and project user guide pages in `docs/` with how a rate is chosen (tier table) and what "no rate" means; English strings with Dutch translations in `l10n/`. Verify: `npm run test:l10n`.

Quality reminders (not tracked as tasks): `composer check:strict` once before push, `npm run lint` and `npm run format`, `@spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-N` on every new method.
