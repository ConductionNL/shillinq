# Merge log R3, landing lane shillinq (sq-fees), 2026-09-28

Rules: MERGE-RULES.md + MERGE-RULES-R2.md. Order: #1724 -> #1728 -> #1729 (stacked).

- Heads at start: #1724 018cdde04, #1728 5f3d418fc, #1729 8c71acfa5; local = origin for all three, no unpushed commits. origin/development tip a1436adf0.
- Baseline: full PHPUnit (phpunit-unit.xml) on detached origin/development a1436adf0: EXIT=0, OK 5296 tests, 47880 assertions. Baseline failure set = empty.

## PR #1724 `feat/voluntary-contribution-reminder`
- `git merge --no-edit origin/development` (a1436adf0) EXIT=0, no conflicts; dev side brought docs/openspec/parity only. Merge commit bdb118f23 (message amended to `merge development into feat/voluntary-contribution-reminder` before push).
- Register: changed fragment `register.d/school-contributions.json` ARInvoice 0.15.0 > dev max 0.14.0: OK. shillinq_register.json untouched. Dup id/uuid scan of the changed fragment: none. `git grep '<<<<<<<'` on changed files: none. `npm run check:registers` EXIT=0 (dead aggregations 137 = baseline).
- Full PHPUnit on bdb118f23: EXIT=0, OK 5320 tests, 48050 assertions. Failure set empty = subset of baseline. New failures: none.
- Pushed; `git ls-remote` = local bdb118f23.
- Merged: squash 83baa4974 (`gh pr merge 1724 --squash --admin` EXIT=0, state MERGED 2026-09-28T07:59:58Z). Branch not deleted. development tip 83baa4974.

## PR #1728 `fix/arinvoice-lines-and-portal-amounts` (stacked on #1724)
- `git merge --no-edit origin/development` (83baa4974), merge base 88fb62a17: EXIT=1, 8 conflicted files: l10n/en.js, l10n/en.json, l10n/nl.js, l10n/nl.json, lib/Portal/PortalContributionProvider.php, lib/Service/Payment/PortalPaymentSessionService.php, tests/Unit/Portal/PortalContributionProviderTest.php, tests/Unit/Service/SchoolContributionsFragmentTest.php.
- Why: the base is the pre-#1724 point, so #1724's squash and the branch's own copy of #1724's commits both touch the same regions. Proven safe to keep the branch side: 018cdde04 (#1724 head) is an ancestor of the branch, and `git diff 018cdde04 origin/development -- <conflicted files>` is empty (dev's side of every conflict is exactly #1724's head; outside openspec dev differs from 018cdde04 in no file). Resolved `git checkout --ours` on all 8. Staged tree vs branch HEAD differs only in the 14 openspec docs dev brought. No `<<<<<<<` left.
- App version: dev 0.5.2-unstable.20260912205611, branch 0.5.3-unstable.20260928080000 (highest timestamp; #1729 carries the same): kept the branch's.
- Register: changed fragment `register.d/ar-invoice-payment-links.json` PaymentRequest 0.5.0 > dev max 0.4.0: OK; school-contributions.json no longer differs from dev (landed with #1724). Dup id/uuid scan: none.
- Checks: `php -l` on the 4 conflicted PHP files: no syntax errors. JSON parse l10n/en.json, nl.json: ok. `npm run l10n:build` EXIT=0, no diff. `check:l10n-js` EXIT=0, `check:schema-l10n` EXIT=0 (12217 uncovered = baseline), `check:registers` EXIT=0.
- Merge commit 7835e4734 `merge development into fix/arinvoice-lines-and-portal-amounts`.
- Full PHPUnit on 7835e4734: EXIT=1, 5338 tests, 48222 assertions, 1 failure: `TrialBalancePerformanceTest::testComputeOf10kAccountsCompletesUnderTwoSeconds` (compute took 8.93s vs a 2.0s wall-clock budget). The run took 15:40 against the baseline's 6:06, load average 53-68 from other lanes' suites. The PR touches no TrialBalance/Calculator/DuckObject file. Rerun of that class alone on the same tree: OK (1 test, 3 assertions). Classed as a load flake, not a new failure; effective failure set empty = subset of baseline.
- Pushed; `git ls-remote` = local 7835e4734.
- Merged: squash ed9996bb4 (`gh pr merge 1728 --squash --admin` EXIT=0, state MERGED 2026-09-28T08:21:08Z). Branch not deleted. development tip ed9996bb4.

## PR #1729 `feat/portal-pay-row-action-keys` (stacked on #1728)
- CI read (once, before landing, head 8c71acfa5): 54 checks, 46 pass, 8 skipping, 0 fail. Nothing introduced by the PR to fix.
- `git merge --no-edit origin/development` (ed9996bb4), merge base 88fb62a17: EXIT=1, 7 conflicted files: docs/api/portal-payments.md (add/add), l10n/en.js, l10n/en.json, l10n/nl.js, l10n/nl.json, lib/Portal/PortalContributionProvider.php, tests/Unit/Portal/PortalContributionProviderTest.php.
- Same shape as #1728: 5f3d418fc (#1728 head) is an ancestor of the branch and `git diff 5f3d418fc origin/development -- <conflicted files>` is empty (outside openspec dev differs from 5f3d418fc in no file). Resolved `git checkout --ours` on all 7. Staged tree vs branch HEAD differs only in dev's openspec docs; diff vs dev = the PR's own 20 files. No `<<<<<<<` left.
- App version: dev and branch both 0.5.3-unstable.20260928080000 (highest seen; the PR adds no repair/migration step): unchanged. Register: the PR changes no fragment and not shillinq_register.json.
- Checks: `php -l` on the 2 conflicted PHP files: no syntax errors. JSON parse l10n/en.json, nl.json: ok. `npm run l10n:build` EXIT=0, no diff. `check:l10n-js` EXIT=0, `check:schema-l10n` EXIT=0 (12217 = baseline), `check:registers` EXIT=0.
- Merge commit b07bf1245 `merge development into feat/portal-pay-row-action-keys`.
- Session paused mid-suite (run killed at 67%); on resume the clone's `vendor/` and `node_modules/` were gone (removed while paused, not by this lane). dev still ed9996bb4 and contained in the branch. `composer install` from composer.lock EXIT=0. node_modules not reinstalled (npm checks had already run above).
- Full PHPUnit on b07bf1245: EXIT=0, OK 5341 tests, 48252 assertions. Failure set empty = subset of baseline. New failures: none.
- Pushed; `git ls-remote` = local b07bf1245.
- Merged: squash c74c4c216 (`gh pr merge 1729 --squash --admin` EXIT=0, state MERGED 2026-09-28T11:45:20Z). Branch not deleted. development tip c74c4c216.

## Result
development tip c74c4c216; app version 0.5.3-unstable.20260928080000; ARInvoice overlay 0.15.0 (school-contributions.json), PaymentRequest 0.5.0 (ar-invoice-payment-links.json), shillinq_register.json info.version unchanged.
