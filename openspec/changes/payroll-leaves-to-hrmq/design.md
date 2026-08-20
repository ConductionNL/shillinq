# Design: payroll-leaves-to-hrmq

## 0. Method

Every retire/keep verdict below was re-verified against `origin/development`
(2026-08-20) and against hrmq's checkout
(`/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/hrmq`,
branch `feat/hrmq-dashboard-steering`, working tree clean) — never assumed
from the triage brief. Live object counts are read from the shared dev
instance's OpenRegister API (`localhost:8080`, `shillinq 0.2.1-
unstable.20260818220149`, `openregister 0.2.17-unstable.38`) via
`GET /apps/openregister/api/objects?register=shillinq&schema=<id>&_limit=1`,
reading the `total` field — never `limit=` alone, which is a property filter
per this repo's own working-norm.

## 1. Full retire / keep / migrate table

| Item | Kind | Verdict | Notes |
|---|---|---|---|
| `Werkgever` | schema + page pair (`Werkgevers`/`WerkgeverDetail`) | RETIRE | 0 live rows |
| `Werknemer` | schema + page pair | RETIRE | 0 live rows |
| `LoonheffingTabel2026` | schema, no page (reference table) | RETIRE | 0 live rows; FK target of `LoonStrook` only |
| `LoonPeriode` | schema + page pair | RETIRE | 0 live rows |
| `LoonStrook` | schema + page pair | RETIRE | 0 live rows |
| `LHAfdracht` | schema + page pair | RETIRE | 0 live rows |
| `Loonjournaalpost` | schema + page pair | RETIRE | 0 live rows |
| `Employee` (detachering) | schema, no page | RETIRE | 3 live rows = 3 seed rows, exact match |
| `Payroll` (detachering) | schema, no page | RETIRE | 2 live rows = 2 seed rows, exact match |
| `Deduction` (detachering) | schema, no page | RETIRE | 4 live rows = 4 seed rows, exact match |
| `DeterminationLetter` (detachering) | schema, no page | RETIRE | 1 live row = 1 seed row, exact match — **missing from the triage brief, added here (proposal.md point 1)** |
| `SalarisFeed` | schema + page pair | RETIRE, gap-flagged | 0 live rows; **no hrmq equivalent exists — §4** |
| `OpdrachtgeversVerklaring` | schema + page pair | KEEP, re-home | 0 live rows; DBA/non-employee filing, §3 |
| `IB47Record` | schema + page pair (`IB47Jaarbatch`/`IB47RecordDetail`) | KEEP, re-home | 0 live rows; DBA/non-employee filing, §3 |
| `PayrollChartOfAccountsMapping.php` | PHP service | KEEP, unchanged | stateless, no schema dependency, §1a |
| `JournalEntry` schema + lifecycle | schema + existing pages | KEEP, unchanged | already the hrmq receiving target, §1b |
| `PaymentRun` schema + lifecycle | schema + existing pages | KEEP, unchanged | already the hrmq receiving target, §1b |
| `ExpenseSettlementClassifier`/`ReimbursementPolicy`/`PassThroughMarkupRule` | schemas + pages | KEEP, unchanged | fiscal tax-classification policy, unrelated to payroll math |
| `PayrollCalculator.php` | PHP service | RETIRE | 556-line gross-to-net engine, 19 unit tests |
| `PayrollService.php` | PHP service | RETIRE | 5 unit tests |
| `PayrollController.php` | PHP controller | RETIRE | 3 routes, 10 unit tests |
| `PayrollWebhookController.php` | PHP controller | RETIRE | 2 routes, 4 unit tests, §1c |
| `PayrollGuard.php` | PHP lifecycle guard | RETIRE | 7 unit tests |
| `PayrollApArHandoffService.php` | PHP service | RETIRE | 3 unit tests |
| `PayrollWkrHandoffService.php` | PHP service | RETIRE | 2 unit tests |
| `PayrollUpaHandoffService.php` | PHP service | RETIRE | 3 unit tests |
| `PayrollLivLkvHandoffService.php` | PHP service | RETIRE | 3 unit tests |
| `PayrollJaaropgaveService.php` | PHP service | RETIRE | 2 unit tests |
| `PayrollSbrConversionService.php` | PHP service | RETIRE | 3 unit tests |
| `lib/Standards/rules/payroll-tax.json` | rules JSON | RETIRE | no other consumer found |
| `lib/Validation/BsnValidator.php` | PHP utility | RETIRE | **already orphaned today** — grep finds zero callers outside its own test file; `Werknemer`/`Werkgever` retiring removes its only intended caller |

### 1a. `PayrollChartOfAccountsMapping` — why it survives unchanged

`lib/Service/PayrollChartOfAccountsMapping.php` is a `final`, stateless class
exposing 10 named GL account-number constants (RGS 3.5 ranges 4001–4099
loonkosten, 1610–1699 schulden) plus `all(): array` / `isKnown(string):
bool`. Its own docblock: *"auditors and the bookkeeping-chart-of-accounts app
reference it as the canonical contract."* It holds no reference to any
retiring schema. hrmq's incoming `JournalEntry` (§1b) lines carry
`accountNumber` strings (`lib/Service/PayrollGLPostService.php` in hrmq
builds the 4-line journal); this mapping is the canonical source those
account numbers should validate against on the shillinq side, so it keeps
its job — it just receives account numbers from hrmq's push instead of from
shillinq's own `PayrollCalculator`.

### 1b. The receiving path already exists — nothing new to build

`hrmq/openspec/specs/payroll-glpost-shillinq/spec.md` REQ-PGP-003 and
`.../payroll-sepa-netpay-shillinq/spec.md` REQ-PNP-004 are both explicit that
hrmq creates a **draft** shillinq `JournalEntry` / `PaymentRun` via
`OCA\OpenRegister\Service\ObjectService`, in-process (same PHP request, no
HTTP, no SEPA XML from hrmq), duck-typed absence handling
(`class_exists()` → `skipped-no-shillinq`, never an exception) — *"hrmq
SHALL NOT drive shillinq's post transition"* / *"hrmq SHALL NOT generate SEPA
XML and SHALL NOT drive any shillinq lifecycle transition."* Shillinq's own
`JournalEntry` (`lib/Settings/register.d/add-shillinq-bookkeeping-
foundation.json`) and `PaymentRun` (`lib/Settings/register.d/bookkeeping-
accounts-payable-core.json`) schemas already declare exactly the fields
hrmq's push writes (`journalNumber`, `entryDate`, `description`, `lines[]`,
`journalType`, `approvalState`, `administrationId`, `state` /
`runNumber`, `administrationId`, `executionDate`, `status`, `totalAmount`,
`currency`, `paymentLines`, `lifecycleState`), and both already have a
generic post/approve lifecycle exercised by over a dozen internal producers
(`SoftCloseExecutor`, `IntercompanyJournalService`,
`FixedAssetDisposalService`, `PaymentRunController`, etc. — none hrmq-
specific). A draft object hrmq writes is indistinguishable, from shillinq's
lifecycle engine's point of view, from a draft an operator authored by hand.
**This change requires zero new receiving code** — REQ-PLH-004 in the spec
delta exists to assert this explicitly (a negative requirement: don't build
a bespoke hrmq-ingestion listener where the generic lifecycle already
suffices), not to add a feature.

The house pattern for absence-handling on the *other* direction already
exists and should be the template for anything genuinely new this change's
implementer finds necessary: `lib/Service/HrmqCostRateAdapter.php` reads
hrmq's `Employee`/`EmploymentContract` register (register `hrmq`, not
`shillinq`) via `ObjectService`, resolves hrmq's cost-rate service by a
plain string FQCN (never `SomeClass::class`, so nothing resolves at compile
time), and returns an empty map — not an exception, not a zero — when hrmq
is absent, so the caller (`SubjectCostAggregator`) withholds its total
rather than silently underpricing. Its docblock cites the exact defect this
guards against: `openregister` once declared a hard constructor typehint on
an optional dependency and broke every `occ` invocation on a server without
it.

### 1c. `PayrollWebhookController` — why it is deleted, not re-homed

This controller is a public HMAC-verified receiver for **third-party
salarisbureau webhooks** (Nmbrs/SalaryBox), feeding shillinq's *own*
gross-to-net engine (`payrollWebhook#info` GET, `payrollWebhook#receive`
POST, `appinfo/routes.php` lines 402–406). It has nothing to do with hrmq's
push mechanism, which is in-process `ObjectService` (§1b), not HTTP. Once
the engine it feeds is deleted, this controller has no consumer — deleting
it is not a functionality loss, it is dead-code removal following its only
caller out the door. `integration-config-to-openconnector`'s own proposal.md
(sibling change, spec committed 2026-08-19) independently confirms this
scoping: *"`payrollWebhook#receive` is explicitly OUT of this change's
scope — it is queued for removal by the separate `payroll-leaves-to-hrmq`
change."*

## 2. Per-page hrmq-mapping table (ADR-044 reachability evidence)

Read directly from hrmq's current manifest
(`/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/hrmq/
src/manifest.json`, 109 pages, single monolithic file — hrmq has not adopted
the `manifest.d/` fragment split) and register schemas
(`lib/Settings/register.d/hr-objects.json`, `hr-pension.json`).

| Retiring shillinq page/schema | hrmq equivalent | Match | Note |
|---|---|---|---|
| `Werkgevers`/`WerkgeverDetail` | — | ⚠️ **no page match** | hrmq models the employer implicitly (single-tenant installation context), not as a browsable roster. Flagged as an open question (§10) — needs hrmq-owner confirmation this is genuinely not lost function, not a gap the mapping table is papering over. |
| `Werknemers`/`WerknemerDetail` | `Employees`/`EmployeeDetail` (`/employees[/:id]`, group `EmployeesGroup` "Personeel") | ✅ | |
| `Loonperiodes`/`LoonperiodeDetail` | `PayrollRuns`/`PayrollRunDetail` (`/payroll-runs[/:id]`, group `PayrollGroup`) | ✅ | period is a `PayrollRun` attribute, not a separate roster in hrmq — same information, coarser grain |
| `Loonstroken`/`LoonstrookDetail` | `Payslips`/`PayslipDetail` (`/payslips[/:id]`) | ✅ | |
| `LHAfdrachten`/`LHAfdrachtDetail` | `LoonaangifteFilings`/…Detail (`/filings[/:id]`, "Aangiftes") | ✅ | |
| `Loonjournaalposten`/`LoonjournaalpostDetail` | `PayrollGLPosts`/…Detail (`/gl-posts[/:id]`, "Loonjournaalposten") | ✅ | exact terminology match; this is literally the object hrmq pushes into shillinq's own `JournalEntry` (§1b) |
| `LoonheffingTabel2026` (no page) | not independently verified | ⚠️ open question | reference/lookup data only, never user-facing in shillinq either; low risk, flagged not blocking (§10) |
| `Employee`/`Payroll`/`Deduction` (detachering, no page) | `Employees`, `PayrollRuns`/`Payslips`, embedded Payslip deduction lines | ✅ (Employee, Payroll) / ⚠️ field-level parity unverified (Deduction) | `Deduction`'s granular line-item shape (`deductionType`, statutory rate ref, `taxYear`) vs. hrmq's embedded Payslip deduction lines needs a field-level diff during implementation, not blocking the retire verdict — no page or route depends on it |
| `DeterminationLetter` (detachering, no page) | hrmq's Payslip PDF generation + `Jaaropgaven`/`JaaropgaafDetail` | ✅ | archival payslip/werkgeversverklaring documents — same document class hrmq already generates |
| `SalarisFeed`/`SalarisFeedDetail` | **none** | ❌ **no match — genuine gap, §4** | |
| `OpdrachtgeversVerklaringen`/`OpdrachtgeversVerklaringDetail` | N/A — **not retiring**, re-homed within shillinq (§3) | — | |
| `IB47Jaarbatch`/`IB47RecordDetail` | N/A — **not retiring**, re-homed within shillinq (§3) | — | |

**Coverage**: of 14 retiring pages + 4 retiring no-page schemas (`SalarisFeed`
counted once as a page above; `LoonheffingTabel2026` has no page but is
listed for completeness) — **10 of 12 mapped items have a confirmed hrmq
equivalent, 1 has an unverified-but-plausible match requiring an
implementation-time field diff (`Deduction`), and 2 are open questions
requiring resolution before this change may retire them without a
functionality-loss risk** (`Werkgevers` — no hrmq page at all;
`SalarisFeed` — no hrmq capability at all). Both are named explicitly in
tasks.md as blocking pre-work, not silently accepted.

## 3. Re-homing `OpdrachtgeversVerklaring`/`IB47Record` into DBA Compliance

Both pages currently live under the generic `Bookkeeping` top-level group
(`src/manifest.d/add-shillinq-detachering-payroll-administratie.json`'s own
menu id, unrelocated by `menu-layout.json`) — **not** inside `Payroll`, per
proposal.md point 2's correction. `src/manifest.d/dba-compliance-marker.json`
already declares a `DBACompliance` top-level group with 6 children
(`DBAOpdrachten`, `DBAIntakes`, `DBAModelovereenkomsten`,
`DBAPortfolioRisicos`, `DBAEvidenceDossiers`, `DBARisicoflags`) built on its
own schema family (`DBAOpdracht`, `DBAIntake`, `DBAEvidenceDossier`,
`DBARisicoflag`) — no naming or schema overlap with `OpdrachtgeversVerklaring`
/`IB47Record`, so this is a placement change (menu-layout relocation +
possibly a manifest fragment move of the two page pairs into
`dba-compliance-marker.json` or a new relocation entry pointing the existing
fragment's group id at `DBACompliance`), not a schema merge. Rationale: both
concern Wet DBA (freelance-worker classification / non-employee tax
reporting), which is the DBA Compliance group's whole domain — a more
specific, discoverable home than the catch-all `Bookkeeping` group they sit
in today.

## 4. `SalarisFeed` — the one retirement with no hrmq landing spot

Exhaustive grep of the hrmq checkout for
`detachering|secondment|IB47|OpdrachtgeversVerklaring|SalarisFeed`
(all file types, node_modules excluded) returns **zero matches**. hrmq's
payroll domain model (`PayrollRun` → `Payslip`, `Employee` →
`EmploymentContract`) is built for direct employment; nothing in hrmq
represents "payroll data imported from a 3rd-party salarisbureau for a
detached/seconded/freelance worker."

This is not treated as a blocker to writing this change's spec — the
retire verdict from the triage brief is followed — but it **is** treated as
a blocker to *implementing* the deletion without a resolution, because
deleting a page with zero hrmq landing spot and non-zero (even if currently
unused) capability would be a real functionality loss, not a dedup. Two
resolutions, either acceptable, both requiring a decision this spec cannot
make on shillinq's side alone:

1. **Product decision**: shillinq's "detachering via 3rd-party salarisbureau
   feed" use case is out of scope / superseded entirely (nobody uses it — 0
   live and 0 seed objects support but do not prove this), and `SalarisFeed`
   is deleted with no replacement, same treatment as ADR-081's own
   `case.kosten` precedent (Decision 2 / Consequences): *"The `case.kosten`
   data is dropped, not migrated. On the dev instance it holds nothing but
   subsidy-disbursement entries… Any deployment that does hold entries MUST
   be checked before the deletion lands — this is the one migration risk in
   the change, and it is a check, not a conversion."* The same framing
   applies here.
2. **New hrmq capability**: hrmq grows a detachering/staffing-payroll
   ingestion mode before this page is deleted, and the mapping row becomes a
   real ✅.

This change's tasks.md hands this back to the orchestrator as a named
cross-repo pre-work item (task 0) and does not implement `SalarisFeed`'s
deletion until one of the two is chosen.

## 5. Data migration / dev-only-data verification

Per this repo's own working norm ("never trust a migration script's own
log — acceptance = live-row counts"), the verification method is a direct
OR API query, repeated at implementation time, not a one-time measurement
frozen into this document:

```
GET /apps/openregister/api/objects?register=shillinq&schema=<Schema>&_limit=1
→ read response.total (NEVER a bare `limit=` — that is a property filter,
  not a page-size limit, and silently returns total: 0 for unrelated reasons)
```

**Measured 2026-08-20 against the shared dev instance** (`localhost:8080`,
`shillinq 0.2.1-unstable.20260818220149`):

| Schema | Live `total` | Seed objects declared in the register fragment | Verdict |
|---|---:|---:|---|
| `Werkgever` | 0 | 1 (`wg-conduction-bv`) | seed never imported here, or removed — **not** proof the importer is broken, just that this instance has none either way |
| `Werknemer` | 0 | 3 (`wn-2024-0042`, `wn-2024-0043`, `wn-dga-0001`) | same caveat |
| `LoonheffingTabel2026` | 0 | 2 | same caveat |
| `LoonPeriode` | 0 | 1 (`lp-2026-05-…`) | same caveat |
| `LoonStrook` | 0 | 2 | same caveat |
| `LHAfdracht` | 0 | 1 | same caveat |
| `Loonjournaalpost` | 0 | 1 | same caveat |
| `Employee` | 3 | 3 | **exact match** — seed-created, no independent usage |
| `Payroll` | 2 | 2 | **exact match** |
| `Deduction` | 4 | 4 | **exact match** |
| `DeterminationLetter` | 1 | 1 | **exact match** |
| `SalarisFeed` | 0 | 0 (no `objects[]` block in that fragment) | genuinely empty |
| `OpdrachtgeversVerklaring` | 0 | 0 | not retiring — informational |
| `IB47Record` | 0 | 0 | not retiring — informational |

**Reading this table honestly**: this proves the *one shared dev instance
measured* carries no independently-created rows for any retiring schema —
either zero (never imported, or removed) or exactly the seed count (seed-
created, never touched since). It does **not** prove any other shillinq
deployment is equally clean. `tasks.md` §2 makes re-running this exact query
against every real deployment before executing any delete a hard
prerequisite, not optional due diligence — this document's numbers are
dated 2026-08-20 evidence, not a standing guarantee.

For the four detachering schemas with non-zero rows, migration is: export
the 10 seed-shaped objects, confirm with the DetermineLetter/Employee/
Payroll/Deduction owner whether they map to hrmq's `Employee`/
`EmploymentContract`/`PayrollRun`/`Payslip` shapes closely enough for a
straight `ObjectService`-level copy (same pattern hrmq already uses to push
the other direction, §1b), or are dropped as seed-only test fixtures with
no real-world counterpart (the more likely outcome, given the exact seed
match). For the seven core payroll schemas showing 0 here: dropped, not
migrated, per the ADR-081 `case.kosten` precedent (§4) — contingent on the
same live-count check passing on every real deployment before the delete
ships.

## 6. Spec reconciliation — `bookkeeping-detachering-payroll-administratie`

`openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md`
(status: done) documents `OpdrachtgeversVerklaring` (REQ-DPA-001),
`IB47Record` (REQ-DPA-002), a `SalarisFeed`-shaped salarisbureau import
(REQ-DPA-003, worded generically as "openconnector source rows... 
materializing as loonkosten journal entries" — matches `SalarisFeed`'s
actual role even though the schema name changed), two docudesk template
references (REQ-DPA-004/005), and a `mkb-detachering` feature flag
(REQ-DPA-006) that was **never implemented** — grep for `mkb-detachering`
across the entire manifest returns zero matches, so these three pages have
always rendered unconditionally. This spec never documents `Employee`/
`Payroll`/`Deduction`/`DeterminationLetter` at all, despite them living in
the identically-named register fragment file today. The spec delta in
`specs/bookkeeping-detachering-payroll-administratie/spec.md`:

- **REMOVES** REQ-DPA-003 (salarisbureau import materializing journal
  entries) — this is `SalarisFeed`'s actual behaviour, retiring per §4.
- **MODIFIES** REQ-DPA-006 to drop the fictional feature-flag claim and
  state the DBA Compliance re-home instead.
- **Leaves REQ-DPA-001/002/004/005 in force** (their subject —
  `OpdrachtgeversVerklaring`/`IB47Record` — keeps existing, just re-homed),
  correcting only the file-path claim inside this design doc's own
  reasoning, not the spec text itself (the requirements never named a
  fragment file, so nothing in the requirement text is actually wrong —
  only this design document's earlier assumption that they lived in the
  same-named file needed correcting, per proposal.md point 4).
- Does **not** attempt to retroactively document `Employee`/`Payroll`/
  `Deduction`/`DeterminationLetter`, since they are retiring, not
  surviving — no spec text is needed for a capability this change removes
  and hrmq already specs on its own side.

There is no `openspec/specs/bookkeeping-payroll-engine-nl/` capability spec
to write a REMOVED delta against — the archived
`2026-06-14-bookkeeping-payroll-engine-nl` change used 16 granular
`req-pay-*.md` files (`req-pay-000-werkgever-setup.md` through
`req-pay-015-30-procent-regeling.md`) that were never synced into
`openspec/specs/` as a single capability spec, unlike every other archived
change in this repo. This is a pre-existing openspec-process gap, not
something this change can retroactively fix by inventing a capability spec
that never existed — the new `payroll-leaves-to-hrmq` capability spec
(`specs/payroll-leaves-to-hrmq/spec.md`) documents the retirement instead.
One live reference survives it: `openspec/specs/dba-compliance-marker/
spec.md` line 242 cites `bookkeeping-payroll-engine-nl` by name as an
"optional" calculation dependency for a herclassificatie scenario — this
citation becomes stale once the engine is deleted; tasks.md §5 updates it to
point at hrmq instead.

## 7. Byte budget and e2e coverage

**Manifest bytes freed**: `bookkeeping-payroll-engine-nl.json` (17,418 bytes)
deleted entirely. `add-shillinq-detachering-payroll-administratie.json`
(10,563 bytes) loses its `SalarisFeed`/`SalarisFeedDetail` pair and keeps
`OpdrachtgeversVerklaringen`/`IB47Jaarbatch` (re-homed, not deleted — no net
byte change from the move itself, only from the `SalarisFeed` removal,
roughly proportional: 2 of 6 pages ≈ 3,500 bytes). `src/menu-layout.json`
loses the `"Loonadministratie": "Payroll"` / `"ExpenseSettlement": "Payroll"`
relocation entries and gains one relocation moving `ExpenseSettlement`'s
target from `Payroll` to `Bookkeeping` (§9) plus one relocating the DBA pair's
group into `DBACompliance` — net negligible bytes. `src/manifest.json` loses
the now-empty `Payroll` top-level group placeholder (id `Payroll`, label
"People & Projects" — this also retires the stale-label bug `nav-six-
clusters/design.md` §5 already flagged, as a side effect, not a separate fix).
**Estimated total freed: ~20,900 bytes** (17,418 + ~3,500 minus placeholder
removal), against a 1,126,300-byte budget with 2,927 bytes of current
headroom (measured via `node tests/check-manifest-budget.js`,
`manifest.json=460786B manifest.d/=662587B total=1123373B`) — implementer
MUST re-run `check:manifest-budget` after the edit and report the exact
delta in tasks.md's validation step, not rely on this estimate.

**Existing test coverage retiring**: zero Playwright specs exist for the
Payroll nav group today (`tests/e2e/` grep for Payroll/Werkgever/Werknemer/
LoonPeriode/LoonStrook/LHAfdracht/Loonjournaalpost/PayrollController: no
matches) — there is no existing e2e suite to delete, only ~80 PHPUnit tests
across 14 files (`tests/Unit/Controller/PayrollControllerTest.php` (10),
`PayrollWebhookControllerTest.php` (4), `Lifecycle/PayrollGuardTest.php` (7),
`Service/PayrollCalculatorTest.php` (19), `PayrollServiceTest.php` (5),
`PayrollFragmentTest.php` (9), `PayrollDetacheringFragmentTest.php` (7),
`PayrollChartOfAccountsMappingTest.php` (3, KEEP — the class survives),
`PayrollApArHandoffServiceTest.php` (3), `PayrollWkrHandoffServiceTest.php`
(2), `PayrollUpaHandoffServiceTest.php` (3), `PayrollLivLkvHandoffService
Test.php` (3), `PayrollSbrConversionServiceTest.php` (3),
`PayrollJaaropgaveServiceTest.php` (2)).

**New Playwright coverage required** (gate-19 / `hydra-gate-e2e-coverage`,
tags matching `spec.md`'s scenario ids exactly, per `integration-config-to-
openconnector`'s own precedent):

1. `payroll-leaves-to-hrmq::payroll-nav-group-gone` — an authenticated user's
   effective manifest/nav contains no `Payroll`/`Loonadministratie` top-level
   entry and no `/loonadministratie/**` route resolves.
2. `payroll-leaves-to-hrmq::dba-pages-reachable-at-new-home` —
   `OpdrachtgeversVerklaringen` and `IB47Jaarbatch` are reachable from the
   `DBACompliance` nav group and their existing routes still resolve
   (unchanged routes, per ADR-044's "no route rename" discipline — see
   `nav-six-clusters` for why this repo treats a route rename as its own,
   separate risk class from a menu-placement change).
3. Inbound JournalEntry/PaymentRun receiving path: **no new e2e needed** —
   this path is not new (§1b) and is already exercised by every other
   internal JournalEntry/PaymentRun producer's existing coverage (e.g.
   `tests/e2e/bookkeeping-foundation.spec.ts`'s JournalEntry lifecycle
   coverage — cited, not duplicated). REQ-PLH-004's scenario asserts this
   via a targeted PHPUnit test creating a draft JournalEntry through
   `ObjectService` directly (simulating hrmq's exact call shape) and
   confirming it posts through the existing lifecycle unchanged — a
   backend-only proof, `@e2e exclude`.

## 8. Cross-repo tasks handed back to the orchestrator

Not implemented by this change's own tasks:

1. **hrmq detachering capability decision** (§4) — blocking pre-work for
   `SalarisFeed`'s deletion specifically, not the rest of this change.
2. **hrmq-side migration of the 10 seed-shaped detachering objects**, if the
   product decision in #1 is "migrate, don't drop" — building an
   `Employee`/`EmploymentContract`/`PayrollRun`/`Payslip` import path on
   hrmq's side is hrmq-repo work.
3. **Re-verification of live object counts on every real (non-dev) shillinq
   deployment** before any delete ships — this change's own measurement
   covers exactly one shared dev instance.
4. **`Werkgevers` mapping gap** (§2) — confirm with hrmq's owner whether the
   employer/company context is genuinely handled implicitly (single-tenant)
   or whether this is an actual missing hrmq page.
5. **`dba-compliance-marker` spec.md update** to note the two newly-added
   DBA pages, if that spec's own maintainers want the mapping table
   reflected there too (optional — not required for this change's own
   `--strict` validation, since the requirement lives in this change's own
   spec delta).

## 9. Sequencing with `nav-six-clusters`

`nav-six-clusters` (sibling draft change, untracked in this repo as of
2026-08-20, not yet landed) explicitly deferred Payroll's fate:
*"payroll administration is a likely future hand-off to the fleet's
dedicated HR app; Wave 2 is the right place to decide whether shillinq keeps
this content or re-homes it entirely"* (`design.md` §7), and in the
meantime provisionally relocated the 6 `Loonadministratie` leaves +
`ExpenseSettlementClassifier` into its own `Bookkeeping` cluster (§2's
mapping table row `Payroll (labelled "People & Projects") | 7 | 1
Bookkeeping`) as a stopgap. **This change is that Wave 2 decision.**

Two lands-first scenarios:

- **This change lands first (recommended)**: `nav-six-clusters`'s
  implementer re-runs its own design.md §0 measurement against a manifest
  that no longer has a `Payroll` group at all — its cluster-mapping table's
  `Payroll` row simply disappears (6 fewer leaves to relocate), and its
  `ExpenseSettlement`/`ExpenseSettlementClassifier` row needs the same
  Bookkeeping target this change already sets (§9 below), so the two
  changes converge rather than conflict.
- **`nav-six-clusters` lands first**: its relocation of the 6
  `Loonadministratie` leaves into its `Bookkeeping` cluster must be
  re-diffed by this change's implementer against the post-nav-six-clusters
  manifest shape — the page ids and schemas retiring are unchanged, only
  their menu-layout relocation target moves from `Payroll` to
  `Bookkeeping`; this change's deletions apply to whichever group currently
  holds them.

Either order, this change's own edit to `ExpenseSettlement`'s relocation
target (from `Payroll` to `Bookkeeping`, since `Payroll` disappears once its
only other occupant — `Loonadministratie` — is deleted) is required
regardless of which change lands first, because `ExpenseSettlementClassifier`
survives (KEEP) while its current relocation target does not.

## 10. Open questions

1. **`Werkgevers` mapping gap** (§2, §8.4) — is hrmq's implicit single-
   tenant employer model genuinely equivalent, or a real missing page?
2. **`SalarisFeed` product decision** (§4, §8.1) — drop with no replacement
   (ADR-081 `case.kosten` precedent) or wait for a new hrmq capability?
3. **`LoonheffingTabel2026` reference data** — does hrmq maintain its own
   copy of the 2026 wage-tax table for its own gross-to-net calculation?
   Unverified; low risk (never user-facing in shillinq either), but should
   be confirmed rather than assumed.
4. **`Deduction` field-level parity** — does hrmq's embedded Payslip
   deduction-line shape (`deductionType`, `amount`, `taxYear`, statutory
   rate reference) actually carry everything the 4 seeded `Deduction`
   objects represent? No page or route depends on the answer, but the
   migration-vs-drop decision (§5, same bucket as #2) benefits from knowing.
5. **Should ADR-044 be amended to name a cross-app-retirement carve-out
   explicitly?** Its literal text ("Removing a leaf and its only route in
   the same change is forbidden") is written for same-app navigation
   refactors. This change treats "reachable in hrmq" as satisfying the
   *spirit* of Decision 5 for capability genuinely owned elsewhere per
   company decision — consistent with how the task brief that commissioned
   this change already frames the constraint, and with ADR-081's own
   `case.kosten` drop-not-migrate precedent for the zero-live-row schemas.
   Whether the ADR itself should be amended to say so explicitly (hydra-repo
   scope, mirroring how `nav-six-clusters` handed its own ADR-097 amendment
   back to the orchestrator rather than authoring it here) is not resolved
   by this document.
