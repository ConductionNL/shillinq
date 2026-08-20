# Change: payroll-leaves-to-hrmq

## Why

Shillinq carries a complete second Dutch payroll (loonadministratie) engine
that duplicates ~85% of hrmq's payroll surface. Per company decision, hrmq
owns payslips/payroll; shillinq's copy exists only because both apps grew it
independently before that decision was made. This change retires the
duplicate, keeps what is genuinely GL-side (chart-of-accounts mapping,
inbound JournalEntry/PaymentRun receiving, non-employee/DBA tax filings), and
requires proof — a per-page mapping table, not an assertion — that nothing
reachable today becomes unreachable tomorrow.

This is the largest boundary change of the shillinq↔hrmq consolidation
programme: 14 retiring pages + 5 schema-only (no-page) retirements across 2
manifest fragments (44,029 bytes combined; a 3rd fragment needs only a
relocation-target edit), 12 schemas, 11 PHP classes + 1 rules JSON file, 5
routes.

## ⚠️ Reality check — six corrections against the triage brief

Re-verified against `origin/development` (2026-08-20) and against hrmq's
checkout (`feat/hrmq-dashboard-steering`, clean). All six change what this
proposal can safely claim; none changes the retire/keep verdict itself.

1. **A fourth detachering schema was missing from the retire list.**
   `DeterminationLetter` (in the same `bookkeeping-detachering-payroll-
   administratie.json` register fragment as `Employee`/`Payroll`/`Deduction`)
   is an "immutable archival payroll document (loonstrookje,
   werkgeversverklaring or salary certificate)" — a payslip PDF, not a DBA
   artifact. It duplicates hrmq's own `Payslip` document generation exactly
   as much as the other three. It has **no manifest page** (neither it nor
   `Employee`/`Payroll`/`Deduction` do — see point 2) and is added to the
   retire list here.

2. **`SalarisFeed`/`OpdrachtgeversVerklaringen`/`IB47Jaarbatch` are not, and
   have never been, inside the "Payroll" nav group.** `src/manifest.d/add-
   shillinq-detachering-payroll-administratie.json` declares its own
   top-level menu id `Bookkeeping`, and `src/menu-layout.json` carries no
   relocation entry for it. Only two fragments actually relocate into the
   `Payroll` group (`menu-layout.json` lines 32/44:
   `"Loonadministratie": "Payroll"` and `"ExpenseSettlement": "Payroll"`),
   confirming `nav-six-clusters/design.md`'s independently-measured "7
   children" count (6 `Loonadministratie` leaves + the dead
   `ExpenseSettlementClassifier`). The brief's framing — "re-homed OUT of the
   Payroll nav group" — is corrected here: these 3 pages need a *more
   specific* home (DBA Compliance / AP), not an exit from Payroll, because
   they were never in it.

3. **`SalarisFeed`'s retirement has no landing spot in hrmq today — this is
   a genuine capability gap, not a straightforward dedup.** Exhaustive grep
   of the hrmq checkout (`detachering|secondment|IB47|
   OpdrachtgeversVerklaring|SalarisFeed`, all file types) returns **zero
   matches**. hrmq's payroll model is `PayrollRun` → `Payslip` for direct
   employment only; it has no staffing/secondment/3rd-party-salarisbureau
   ingestion capability. `SalarisFeed` has 0 live and 0 seeded objects on the
   shared dev instance (see point 5) and its own spec's `mkb-detachering`
   feature flag (`openspec/specs/bookkeeping-detachering-payroll-
   administratie/spec.md` REQ-DPA-006) **was never implemented** — grep for
   `mkb-detachering` across the whole manifest returns nothing, so the page
   has always rendered unconditionally, not behind a flag. This does not
   change the retire verdict (see design.md §4 for why), but it means the
   retirement cannot claim "reachable in hrmq" — it is handed back
   cross-repo as an open capability question, not silently marked done.

4. **`openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md`
   is stale against its own register fragment.** It documents
   `OpdrachtgeversVerklaring` and `IB47Record` (REQ-DPA-001/002) — but those
   two schemas are actually declared in the *differently-named*
   `add-shillinq-detachering-payroll-administratie.json` fragment. The
   same-named `bookkeeping-detachering-payroll-administratie.json` fragment
   instead declares `Employee`/`Payroll`/`Deduction`/`DeterminationLetter`
   (all `REQ-PAY-*`-tagged — the same requirement prefix as the archived
   `bookkeeping-payroll-engine-nl` change's 16 `req-pay-*.md` files), which
   this spec.md never mentions at all. The spec and the code it is supposed
   to describe parted ways after the archive. This change's spec delta
   (below) corrects the record for the parts that survive; see design.md §6
   for the full reconciliation.

5. **hrmq's push contract is same-instance `ObjectService`, not HTTP — and
   shillinq already has the receiving schemas.** Read
   `hrmq/openspec/specs/payroll-glpost-shillinq/spec.md` and
   `.../payroll-sepa-netpay-shillinq/spec.md` in full. Both are explicit
   (REQ-PGP-003 / REQ-PNP-004): *"hrmq SHALL NOT drive shillinq's post
   transition"* — hrmq writes a **draft** `JournalEntry` / `PaymentRun` via
   `OCA\OpenRegister\Service\ObjectService`, in-process, duck-typed
   (`class_exists()`-guarded, `skipped-no-shillinq` when absent — never an
   HTTP call, never a dependency shillinq or hrmq declares on each other).
   Shillinq's `JournalEntry` (`add-shillinq-bookkeeping-foundation.json`) and
   `PaymentRun` (`bookkeeping-accounts-payable-core.json`) schemas already
   match hrmq's push shape field-for-field
   (`journalNumber`/`entryDate`/`lines[]`/`state` and
   `runNumber`/`paymentLines`/`status` respectively) and already have a
   generic post/approve lifecycle consumed by over a dozen internal
   producers. **Nothing new needs to be built to receive hrmq's pushes — the
   receiving path is the existing generic JournalEntry/PaymentRun lifecycle,
   already exercised by other producers.** This is the same house pattern
   `lib/Service/HrmqCostRateAdapter.php` already uses in the other direction
   (shillinq reading hrmq's `Employee`/`EmploymentContract` register, lazy
   `class_exists()`, string-only FQCN, empty-map-not-exception on absence,
   `@spec openspec/specs/subject-cost-aggregation/spec.md`) — cited directly
   as the pattern any new absence-handling code in this change must follow.
   Live query (`localhost:8080`, 2026-08-20,
   `/apps/openregister/api/objects?register=shillinq&schema=X&_limit=1`,
   reading `total`) confirms zero live rows for every gross-to-net schema
   (`Werkgever`/`Werknemer`/`LoonheffingTabel2026`/`LoonPeriode`/
   `LoonStrook`/`LHAfdracht`/`Loonjournaalpost`/`SalarisFeed`/
   `OpdrachtgeversVerklaring`/`IB47Record`: `total: 0` each). The detachering
   quartet shows non-zero — `Employee: 3, Payroll: 2, Deduction: 4,
   DeterminationLetter: 1` — matching **exactly** the 10-object `objects[]`
   seed block already present in `bookkeeping-detachering-payroll-
   administratie.json`. This is seed-created dev data, not independently
   created usage; see design.md §5 for why this is evidence, not proof, and
   what re-verification the migration task still requires before any delete.

6. **`ExpenseSettlementClassifier` (staying — tax-classification half of
   expense settlement) is already dead code**, unrelated to this change: its
   page is declared `type: "custom"` with no backing Vue component (the
   fragment's own `_note` records it was originally mis-declared as an
   unregistered `"bulk-form"` type). Flagged as a pre-existing defect this
   change's edits to the same fragment will pass through, not fixed here —
   fixing it is a UI feature the payroll boundary change does not need (see
   "scope-debt" project convention: would this still be needed if the
   feature were finished? Yes — so it is separate work).

## What Changes

Full retire/keep/migrate verdict and the per-page hrmq-mapping table are in
`design.md` §1–§4. Summary:

- **RETIRE** (12 schemas, 11 PHP classes + 1 rules JSON file, 5 routes, 14
  retiring pages across 2 manifest fragments + 5 schema-only retirements
  with no page): the entire gross-to-net
  engine (`Werkgever`, `Werknemer`, `LoonheffingTabel2026`, `LoonPeriode`,
  `LoonStrook`, `LHAfdracht`, `Loonjournaalpost` +
  `PayrollCalculator`/`PayrollService`/`PayrollController`/`PayrollGuard` +
  the four `Payroll{Wkr,Upa,LivLkv,ApAr}HandoffService` classes +
  `PayrollJaaropgaveService` + `PayrollSbrConversionService` +
  `lib/Standards/rules/payroll-tax.json` + the already-orphaned
  `BsnValidator`), the detachering quartet (`Employee`, `Payroll`,
  `Deduction`, `DeterminationLetter` — point 1 above), `SalarisFeed` (point 3
  — cross-repo gap flagged, not silently dropped), and
  `PayrollWebhookController` (both routes — its only consumer, the retiring
  engine, is gone; confirmed independently by
  `integration-config-to-openconnector/proposal.md`'s own text: *"
  `payrollWebhook#receive` is explicitly OUT of this change's scope — it is
  queued for removal by the separate `payroll-leaves-to-hrmq` change"*).
- **KEEP, unchanged**: `PayrollChartOfAccountsMapping` (stateless GL
  account-number dictionary, zero dependency on any retiring schema,
  referenced by the bookkeeping-chart-of-accounts app integration); the
  `JournalEntry`/`PaymentRun` schemas and their existing generic
  post/approve lifecycle (the inbound path hrmq's drafts already land on).
- **KEEP, re-homed**: `OpdrachtgeversVerklaringen`/`OpdrachtgeversVerklaring
  Detail` and `IB47Jaarbatch`/`IB47RecordDetail` (non-employee/DBA tax
  filings) move from the generic `Bookkeeping` group into `DBACompliance`
  (existing top-level group, same DBA-modelovereenkomst domain, per
  design.md §3).
- **ADDED** (spec-only): a per-page mapping table proving every retired
  page's capability is reachable in hrmq, backed by the READ of hrmq's own
  current manifest (not assumed) — `design.md` §2.
- **ADDED** (spec-only): a migration/dev-only-data verification task that
  queries live OR object counts (never a migration script's own log) as its
  acceptance evidence — `design.md` §5.
- **Non-goal, explicitly named**: re-establishing salarisbureau (Nmbrs/
  SalaryBox) webhook ingestion anywhere — hrmq or otherwise — is **not**
  this change's scope and is **not** `integration-config-to-openconnector`'s
  scope extension either (its `ADAPTERS` registry does not cover payroll
  bureaus). If wanted, it is a new, not-yet-scoped change, contingent on
  hrmq's payroll engine growing a webhook-ingestion mode it does not have
  today (hrmq currently only pulls via `occ hrmq:glpost:run` /
  `hrmq:netpay:run`).

## Impact

- **Affected specs**: new capability `payroll-leaves-to-hrmq`
  (`specs/payroll-leaves-to-hrmq/spec.md`); `bookkeeping-detachering-
  payroll-administratie` MODIFIED (`specs/bookkeeping-detachering-payroll-
  administratie/spec.md` — REQ-DPA-003 removed with `SalarisFeed`,
  REQ-DPA-001/002/004/005/006 corrected to point at their actual owning
  fragment and pages, per point 4 above).
- **Affected code**: 11 PHP classes + 1 rules JSON file deleted (§ above), 5 routes removed from
  `appinfo/routes.php` (`payroll#loonstrook`, `payroll#lhAfdracht`,
  `payroll#journaalpost`, `payrollWebhook#info`, `payrollWebhook#receive`),
  3 manifest fragments edited (`bookkeeping-payroll-engine-nl.json` deleted
  entirely, `add-shillinq-detachering-payroll-administratie.json` reduced to
  the 2 surviving pairs re-homed under `DBACompliance`, `src/manifest.json`
  + `src/menu-layout.json` edited to remove the now-empty `Payroll` /
  `Loonadministratie` / `ExpenseSettlement` relocation wiring), ~80 PHPUnit
  tests removed (14 files, `tests/Unit/**`), new Playwright coverage
  (design.md §7).
- **Byte budget**: frees ~27,981 bytes of manifest (17,418 +
  10,563 − ~600 bytes of surviving re-homed page config) against a
  1,126,300-byte budget with 2,927 bytes of current headroom — see
  design.md §7 for the exact accounting.
- **Cross-repo, explicitly flagged** (design.md §8, handed back to the
  orchestrator, not implemented by this change's own tasks): (a) hrmq has no
  detachering/staffing-payroll capability — `SalarisFeed`'s retirement needs
  either a product decision that this use case is out of scope, or a new
  hrmq capability, before the page can be deleted with a clear conscience;
  (b) whatever live (non-seed) rows exist on any OTHER shillinq instance for
  the detachering quartet or the gross-to-net schemas need migration into
  hrmq's `Employee`/`EmploymentContract`/`PayrollRun`/`Payslip` registers —
  this change's own tasks only verify the one shared dev instance measured
  in point 5; (c) `openspec/specs/bookkeeping-payroll-engine-nl` has no
  living capability spec to formally retire (point 4/design.md §6) — a
  hydra/openspec-process question about whether the archived
  `req-pay-*.md` granularity should have synced and never did, out of this
  change's scope to resolve.
- **Sequencing with `nav-six-clusters`** (sibling draft change, untracked,
  not yet landed): its `design.md` §7 explicitly deferred Payroll's fate to
  "Wave 2" and provisionally relocated the 6 `Loonadministratie` leaves +
  `ExpenseSettlementClassifier` into its `Bookkeeping` cluster as a stopgap.
  This change **is** that Wave 2 decision — see design.md §9 for the
  required coordination (this change should land first; if `nav-six-
  clusters` lands first instead, its Bookkeeping-cluster relocation of the
  now-deleted payroll leaves must be re-diffed against this change's
  deletions, not merged as-is).
