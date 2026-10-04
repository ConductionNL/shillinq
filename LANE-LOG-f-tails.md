# Lane f-tails, part 1 (2026-09-28): shillinq arinvoice-field-backfill-and-bt10
- Cut stacked on origin/fix/billing-inherited-defects (#1745 open at start). #1745 merged meanwhile; merged origin/development in (f780a1d, one conflict in DunningRunService taken from ours, dev side == 1745 branch). PR diff now only this change.
- Commits edbbf04bc..f780a1dce. Red first: BackfillArInvoiceProvenanceTest (10), ArInvoiceUblMapperTest (2), DunningLetterComposerTest (4).
- DunningRunService 1299 -> 1259 class lines.
- check:strict EXIT 0 (5409 OK) after composer install from lockfile (stale vendor made psalm fail on a missing stub). Post-merge full phpunit 5409 pass. npm lint 0, test:l10n 0, schema-l10n = baseline. Gates diff exit 0 after version bump (gate 110).
- PR https://github.com/ConductionNL/shillinq/pull/1750 (not merged). opsx-verify headless clean (added BT-10 doc). DONE. CI not read (just started).
