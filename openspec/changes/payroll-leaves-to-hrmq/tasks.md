# Tasks: payroll-leaves-to-hrmq

## 0. Pre-work — resolve blocking cross-repo items before touching code
- [ ] Resolve `design.md` §4 (`SalarisFeed`'s hrmq capability gap): get a
      product decision — drop with no replacement (ADR-081 `case.kosten`
      precedent) or wait for hrmq to grow a detachering-payroll ingestion
      mode. Do not delete `SalarisFeed`/`SalarisFeedDetail` until this is
      recorded (REQ-PLH-005). This does not block task groups 1–2 (the rest
      of the engine has confirmed hrmq mappings, `design.md` §2).
- [ ] Confirm `design.md` §2's `Werkgevers` mapping-gap row with hrmq's
      owner: is the employer/company context genuinely handled implicitly
      (single-tenant install), or is this an actual missing hrmq page?
- [ ] Re-run `design.md` §5's live OR-API row-count query
      (`GET /apps/openregister/api/objects?register=shillinq&schema=<X>&
      _limit=1`, reading `total`, never bare `limit=`) against every REAL
      (non-dev) shillinq deployment this change will ship to, before
      executing any delete. This change's own §5 measurement covers only
      the one shared dev instance measured 2026-08-20.

## 1. Delete the gross-to-net payroll engine (REQ-PLH-001)
- [ ] Delete `src/manifest.d/bookkeeping-payroll-engine-nl.json` entirely
      (12 pages: `Werkgevers`/`WerkgeverDetail`, `Werknemers`/
      `WerknemerDetail`, `Loonperiodes`/`LoonperiodeDetail`, `Loonstroken`/
      `LoonstrookDetail`, `LHAfdrachten`/`LHAfdrachtDetail`,
      `Loonjournaalposten`/`LoonjournaalpostDetail`).
- [ ] Delete `lib/Settings/register.d/bookkeeping-payroll-engine-nl.json`
      (schemas `Werkgever`, `Werknemer`, `LoonheffingTabel2026`,
      `LoonPeriode`, `LoonStrook`, `LHAfdracht`, `Loonjournaalpost`).
- [ ] Delete `lib/Service/PayrollCalculator.php`,
      `lib/Service/PayrollService.php`, `lib/Controller/
      PayrollController.php`, `lib/Lifecycle/PayrollGuard.php`,
      `lib/Validation/BsnValidator.php`.
- [ ] Remove the three `payroll#*` routes (`loonstrook`, `lhAfdracht`,
      `journaalpost`, `appinfo/routes.php` lines ~131–133).
- [ ] Delete the matching PHPUnit files: `tests/Unit/Controller/
      PayrollControllerTest.php`, `tests/Unit/Lifecycle/
      PayrollGuardTest.php`, `tests/Unit/Service/PayrollCalculatorTest.php`,
      `tests/Unit/Service/PayrollServiceTest.php`, `tests/Unit/Service/
      PayrollFragmentTest.php`, and any `BsnValidatorTest.php`.
- [ ] Remove `src/menu-layout.json`'s `"Loonadministratie": "Payroll"`
      relocation entry.

## 2. Delete the detachering-quartet duplicate + handoff services (REQ-PLH-002)
- [ ] Remove the `Employee`, `Payroll`, `Deduction`, `DeterminationLetter`
      schema declarations from `lib/Settings/register.d/bookkeeping-
      detachering-payroll-administratie.json`, leaving the file's `_meta`
      intact for whatever, if anything, still needs it (verify nothing else
      references this fragment before deleting it outright vs. emptying its
      `components.schemas`).
- [ ] Delete `lib/Service/PayrollApArHandoffService.php`,
      `PayrollWkrHandoffService.php`, `PayrollUpaHandoffService.php`,
      `PayrollLivLkvHandoffService.php`, `PayrollJaaropgaveService.php`,
      `PayrollSbrConversionService.php`, and
      `lib/Standards/rules/payroll-tax.json`.
- [ ] Delete `lib/Controller/PayrollWebhookController.php` and both
      `payrollWebhook#*` routes (`appinfo/routes.php` lines ~402–406)
      (REQ-PLH-008).
- [ ] Delete the matching PHPUnit files: `tests/Unit/Controller/
      PayrollWebhookControllerTest.php`, `tests/Unit/Service/
      PayrollDetacheringFragmentTest.php`, `PayrollApArHandoffService
      Test.php`, `PayrollWkrHandoffServiceTest.php`,
      `PayrollUpaHandoffServiceTest.php`, `PayrollLivLkvHandoffService
      Test.php`, `PayrollSbrConversionServiceTest.php`,
      `PayrollJaaropgaveServiceTest.php`.
- [ ] Per task-0's live-count re-verification: if any real deployment
      carries non-seed `Employee`/`Payroll`/`Deduction`/`DeterminationLetter`
      rows, do NOT delete until they are migrated or the deployment owner
      accepts the drop — this is a per-deployment gate, not a one-time check.
- [ ] Confirm `lib/Service/PayrollChartOfAccountsMapping.php` and its test
      (`PayrollChartOfAccountsMappingTest.php`) are untouched (REQ-PLH-003).

## 3. `SalarisFeed` — gated on task 0 (REQ-PLH-005)
- [ ] Once task 0's product decision is recorded: if "drop", delete
      `SalarisFeed`/`SalarisFeedDetail` from `src/manifest.d/add-shillinq-
      detachering-payroll-administratie.json` and the `SalarisFeed` schema
      from the matching register.d fragment. If "wait for hrmq capability",
      leave `SalarisFeed` in place and record the decision + the hrmq-side
      tracking reference in this change's follow-up notes; do not implement
      a partial deletion.

## 4. Re-home OpdrachtgeversVerklaring/IB47Record into DBA Compliance (REQ-PLH-006)
- [ ] Move (or add a `menu-layout.json` relocation for) the
      `OpdrachtgeversVerklaringen`/`OpdrachtgeversVerklaringDetail` and
      `IB47Jaarbatch`/`IB47RecordDetail` page pairs so they render as
      children of the existing `DBACompliance` top-level group
      (`src/manifest.d/dba-compliance-marker.json`'s group), keeping every
      existing page id and route unchanged.
- [ ] Confirm the pages no longer appear as children of the generic
      `Bookkeeping` group after the move.

## 5. Menu-layout cleanup after Payroll's removal (REQ-PLH-001, REQ-PLH-010)
- [ ] Remove `src/menu-layout.json`'s `"ExpenseSettlement": "Payroll"`
      relocation entry and replace it with a target that survives —
      `"ExpenseSettlement": "Bookkeeping"` (fiscal/GL-adjacent, per
      `design.md` §9) — since `ExpenseSettlementClassifier`/
      `ReimbursementPolicy`/`PassThroughMarkupRule` are KEEP and need a
      surviving relocation target once `Payroll`'s only other occupant
      (`Loonadministratie`) is deleted.
- [ ] Remove the now-empty `Payroll` top-level group placeholder from
      `src/manifest.json` (id `Payroll`, label "People & Projects") — this
      also incidentally resolves the stale-label defect `nav-six-clusters/
      design.md` §5 already flagged, as a side effect of this deletion, not
      a separate fix.
- [ ] Update `openspec/specs/dba-compliance-marker/spec.md` line 242's
      citation of `bookkeeping-payroll-engine-nl` (as an "optional"
      herclassificatie calculation dependency) to point at hrmq instead, or
      remove the optional reference if no direct equivalent exists.

## 6. Spec deltas
- [ ] Apply `specs/bookkeeping-detachering-payroll-administratie/spec.md`
      (REQ-DPA-003 removed, REQ-DPA-006 modified) to
      `openspec/specs/bookkeeping-detachering-payroll-administratie/
      spec.md` per this change's own sync step.
- [ ] Confirm no other `openspec/specs/*/spec.md` cites `Werkgever`,
      `Werknemer`, `LoonPeriode`, `LoonStrook`, `LHAfdracht`, or
      `Loonjournaalpost` by name (`grep -rln` across `openspec/specs/`)
      beyond the `dba-compliance-marker` reference already handled in
      task 5.

## 7. e2e coverage (REQ-PLH-001, REQ-PLH-006, REQ-PLH-004)
- [ ] Add `tests/e2e/payroll-leaves-to-hrmq.spec.ts` (new file — no existing
      Payroll e2e spec exists to rewrite, `design.md` §7) covering:
      `payroll-leaves-to-hrmq::payroll-nav-group-gone` (no `Payroll`/
      `Loonadministratie` top-level entry, no `/loonadministratie/**` route
      resolves) and `payroll-leaves-to-hrmq::dba-pages-reachable-at-new-home`
      (`OpdrachtgeversVerklaringen`/`IB47Jaarbatch` reachable from
      `DBACompliance`, same routes as before).
- [ ] Add a PHPUnit test proving REQ-PLH-004's scenario: a draft
      `JournalEntry` created via `ObjectService` with hrmq's exact field
      shape posts through the existing lifecycle unchanged — no new
      hrmq-specific code path.
- [ ] Tag new Playwright tests with `@e2e payroll-leaves-to-hrmq::
      <scenario-slug>` matching `spec.md`'s scenario ids exactly (gate-19 /
      `hydra-gate-e2e-coverage`).

## 8. Cross-repo tasks — handed back, not implemented here (REQ-PLH-009)
- [ ] File/flag: hrmq detachering-payroll capability decision (`design.md`
      §4/§8.1) — orchestrator to route to hrmq's product owner.
- [ ] File/flag: hrmq-side migration of the 10 seed-shaped detachering
      objects, if task 0's decision is "migrate, don't drop" (`design.md`
      §8.2).
- [ ] File/flag: `Werkgevers` mapping-gap confirmation with hrmq's owner
      (`design.md` §8.4).
- [ ] File/flag (optional): `dba-compliance-marker` spec.md maintainers
      note on the two newly-added DBA pages (`design.md` §8.5).
- [ ] Note for `nav-six-clusters`'s implementer (whichever change lands
      second, `design.md` §9): re-diff the `Payroll`/`ExpenseSettlement`
      relocation-target rows against whichever manifest shape landed first.

## 9. Validation
- [ ] `node tests/check-manifest-budget.js` — PASS, report the exact byte
      delta against `design.md` §7's ~20,900-byte estimate.
- [ ] `npm run check:nav-reachability` — PASS, confirm no new baseline
      exception was needed for a page this change deletes or relocates
      (REQ-PLH-007).
- [ ] `npx playwright test tests/e2e/payroll-leaves-to-hrmq.spec.ts` — PASS.
- [ ] Run the PHPUnit suite for `PayrollChartOfAccountsMappingTest.php` —
      still green, unchanged (REQ-PLH-003).
- [ ] `grep -rln "PayrollCalculator\|PayrollService\|PayrollController\|
      PayrollGuard\|PayrollWebhookController\|PayrollApArHandoffService\|
      PayrollWkrHandoffService\|PayrollUpaHandoffService\|
      PayrollLivLkvHandoffService\|PayrollJaaropgaveService\|
      PayrollSbrConversionService\|BsnValidator" lib/ tests/` — zero matches
      outside `PayrollChartOfAccountsMapping` (which is a different class
      name and must still match).
- [ ] `openspec validate payroll-leaves-to-hrmq --strict` — PASS.
