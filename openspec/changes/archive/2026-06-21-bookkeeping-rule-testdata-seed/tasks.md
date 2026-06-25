# Tasks: bookkeeping-rule-testdata-seed

## 1. Seeder (REQ-RE-007)
- [x] `lib/Service/RuleTestDataSeeder.php` — idempotent sourceReference + balanced-lines backfill.
- [x] `lib/Command/RulesSeedTestDataCommand.php` + `info.xml` — `occ shillinq:rules:seed-testdata`.
- [x] Verified idempotent (128 already compliant on re-run).

## 2. Coverage wave (REQ-RE-008)
- [x] RuleEngine: en16931-br-cl-03 (ISO currency), en16931-br-dec-12/13/14 (≤2 decimals) + helpers.
- [x] Unit test for the new checks.
- [x] Re-audit: 18 enforced rules, 152/152 compliant.
