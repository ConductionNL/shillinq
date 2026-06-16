# Tasks — Member 01: config schemas + seed

Sourced from the giant's Phase 0 (Setup) and Phase 1 (Schema & Register
Declaration + Seed Data).

## Dependency verification

- [x] Verify OpenRegister availability and stable `x-openregister-aggregations` extension
- [x] Verify Shillinq T1 (Chart of Accounts, GL Transactions, Administration) is released
- [x] Verify @conduction/nextcloud-vue ≥ 1.0.0-beta.66 is available

## BBVProgramme register

- [x] Add `BBVProgramme` schema to `lib/Settings/shillinq_register.json`
- [x] Define all properties per REQ-BBVW-001 schema (programmeName, programmeCode, description, fiscalYear, status)
- [x] Add relation to Administration (many-to-one)
- [x] Set register permissions (admin-write, public-read)

## BudgetBBVMapping register

- [x] Add `BudgetBBVMapping` schema to `lib/Settings/shillinq_register.json`
- [x] Define all properties per REQ-BBVW-002 schema (glAccountNumber, allocationPercentage, effectiveFrom, effectiveTo)
- [x] Add FK relations to BBVProgramme, Account, Administration
- [x] Set register permissions (admin-write, public-read)

## Seed data

- [x] Create seed data for 5 demo programmes (fiscal year 2026: 1.1.1, 1.2.1, 2.3.2, 2.4.1, 3.1.0)
- [x] Create seed data for demo mappings (GL 4100 → 50/30/20; GL 5000 → 25/75)
- [x] Verify seed data idempotency (re-import does not create duplicates)

## Integration-test scaffold

- [x] Add the integration-test scaffold that materialises programmes, mappings, and GL fixtures, reused by later members

## Implementation notes

- Schemas land in `lib/Settings/register.d/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed.json` per ADR-037 (no direct edit of `shillinq_register.json`). The slice deliberately uses the English `BBVProgramme` / `BudgetBBVMapping` slugs so it co-exists with the Dutch `BBVProgramma` schema added by the SUPERSEDED `add-shillinq-waterschappen-bbv-variant` change.
- Seed file: `lib/Settings/seeds/bbv-waterschappen-programmes-2026-demo.json`. Loader methods `SettingsService::seedBbvProgrammes()` + `SettingsService::seedBudgetBbvMappings()` dedupe on natural keys per REQ-BBVW-001 / REQ-BBVW-002 and refuse to run when `administration_id` is unset (C2).
- Repair-step phase 12 wires both seeders into `InitializeSettings::run()`.
- Admin-write / public-read enforcement is delivered via OpenRegister RBAC (ADR-022, ADR-023) — both registers default to admin-write and inherit the app's public-read register posture; no custom controller policies in this slice.
- Integration scaffold (`tests/Integration/WaterschappenBbv01ConfigSchemasSeedIntegrationTest.php` + `tests/fixtures/WaterschappenBbv01SeedData.json`) materialises programmes, mappings and a balanced GL fixture pair (4 lines covering accounts 4100 + 5000 + 1000) for chain members 02, 08, 11. 8 tests, 65 assertions, green under php8.3.
