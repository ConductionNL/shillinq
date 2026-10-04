# Merge log R2, landing lane shillinq (sq-fees), 2026-09-27

- PR #1704 `feat/extracurricular-fee-to-shillinq` (head d27694b3f, no unpushed commits). origin/development tip 7781d5fbb = the branch's merge base: development has not moved since the branch was cut.
- Register fragments the PR changed: `register.d/ar-invoice-payment-links.json` (PaymentRequest 0.3.0 on dev -> 0.4.0 on branch), `register.d/school-contributions.json` (new; ARInvoice overlay 0.14.0, dev max across fragments 0.13.0). `shillinq_register.json` untouched (info.version 0.7.0 both sides). All strictly above development already.
- Baseline: full PHPUnit (phpunit-unit.xml, = composer test:all) on detached origin/development 7781d5fbb: EXIT=0, OK 5228 tests, 47521 assertions. Baseline failure set = empty. (Lane was interrupted by a rate limit after the baseline finished; resumed from here.)
- `git merge --no-edit origin/development` on the branch: "Already up to date" (EXIT 0). No conflicts, no merge commit, nothing resolved.
- Register version check after merge: PaymentRequest 0.4.0 > dev-max 0.3.0, ARInvoice 0.14.0 > dev-max 0.13.0, info.version untouched: OK. Duplicate id/uuid scan of changed fragments: none. `npm run check:registers` EXIT=0. `git grep '<<<<<<<'` hits only pre-existing files outside the PR diff (merge-hygiene workflow, docs, archived applier.json).
- Full PHPUnit on branch d27694b3f: EXIT=0, OK 5296 tests, 47880 assertions. Failure set empty = subset of baseline (empty). New failures: none.
- `gh pr merge 1704 --squash --admin` EXIT=0. PR state MERGED at 2026-09-27T14:34:44Z, merge commit 2a9d5876e. Branch not deleted. development tip after merge: 2a9d5876e; shillinq_register.json info.version 0.7.0, PaymentRequest 0.4.0, ARInvoice overlay 0.14.0.
