# Proposal: restore-archiefwet-retention-schemas

## Summary

Every shillinq install on the Zuiddrecht rehearsal (R2) logged "Shillinq: retention policy seeding failed" (16 times): `Schema slug "RetentionPolicy" is not carried by register "shillinq"`.

The history says the schemas were lost by accident, not removed:

- `6df26567` (2 June 2026, hydra-build #47) added `RetentionPolicy`, `RetentionSchedule` and `DocumentRetention` to `shillinq_register.json`, merged into development as `5078f97f` (PR #169, 7 June).
- `c0957515` (8 June, "Merge feature/bookings-calendar/bookings-resource-calendar-finish") is the first development commit that contains `5078f97f`, and its register has none of the three: the merge kept development's side of `shillinq_register.json`. Its message lists the conflicts it resolved, and the register is not among them. No commit on development removes the schemas on purpose.
- The code that uses them stayed: `SettingsService::seedRetentionPolicies()` with `lib/Settings/seeds/retention-policies.json`, and `RetentionGuard`, which the DocumentRetention lifecycle names. The change was archived on 14 June (`2026-06-14-bookkeeping-archiefwet-retention`, REQ-RET-001 to REQ-RET-013) as if it had shipped.

## What changes

- The three schemas come back as their own register fragment, `register.d/bookkeeping-archiefwet-retention.json` (ADR-037), declared on the `shillinq` register.
- Changed against the 2 June originals, so they import cleanly on today's OpenRegister: the retention dates use `x-openregister-calculations` (JSON-AST `dateAdd`, materialised) instead of the singular `x-openregister-calculation`, which OpenRegister drops; the lifecycle carries `initial` beside `initialState`, which OpenRegister's lifecycle validator requires; the `x-openregister-relations` blocks are left out (the foreign keys are described on the properties); `RetentionPolicy` gains the `slug` property the seeder matches on, so a repair run never seeds a policy twice; em-dashes are gone from the text.
- Register version 0.7.0 to 0.7.1, app version moved, register-versions lock recorded, en/nl catalogue entries for the new schema strings.
