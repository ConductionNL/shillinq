# Tasks — Member 01: config schemas + seed

Sourced from the giant's Phase 0 (Setup) and Phase 1 (Schema & Register
Declaration + Seed Data).

## Dependency verification

- [ ] Verify OpenRegister availability and stable `x-openregister-aggregations` extension
- [ ] Verify Shillinq T1 (Chart of Accounts, GL Transactions, Administration) is released
- [ ] Verify @conduction/nextcloud-vue ≥ 1.0.0-beta.66 is available

## BBVProgramme register

- [ ] Add `BBVProgramme` schema to `lib/Settings/shillinq_register.json`
- [ ] Define all properties per REQ-BBVW-001 schema (programmeName, programmeCode, description, fiscalYear, status)
- [ ] Add relation to Administration (many-to-one)
- [ ] Set register permissions (admin-write, public-read)

## BudgetBBVMapping register

- [ ] Add `BudgetBBVMapping` schema to `lib/Settings/shillinq_register.json`
- [ ] Define all properties per REQ-BBVW-002 schema (glAccountNumber, allocationPercentage, effectiveFrom, effectiveTo)
- [ ] Add FK relations to BBVProgramme, Account, Administration
- [ ] Set register permissions (admin-write, public-read)

## Seed data

- [ ] Create seed data for 5 demo programmes (fiscal year 2026: 1.1.1, 1.2.1, 2.3.2, 2.4.1, 3.1.0)
- [ ] Create seed data for demo mappings (GL 4100 → 50/30/20; GL 5000 → 25/75)
- [ ] Verify seed data idempotency (re-import does not create duplicates)

## Integration-test scaffold

- [ ] Add the integration-test scaffold that materialises programmes, mappings, and GL fixtures, reused by later members
