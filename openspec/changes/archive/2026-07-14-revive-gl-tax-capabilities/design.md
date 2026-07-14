# Design: revive-gl-tax-capabilities

## Step 1 — verification (do not trust the triage)

Every method below was re-verified against `origin/development`
(`0c2b0bf3`) with, per method: a repo-wide `->method(` grep, a
dynamic-dispatch grep (`call_user_func`, `$obj->$m()`, `ReflectionMethod`,
`->invoke(`), a `lib/Settings/register.d/**` grep for
`handler`/`guard`/`requires`/`save`/`Class::method` strings, a
`appinfo/routes.php` + `appinfo/info.xml` (background-job) grep, and a
`lib/AppInfo/Application.php` DI grep.

### Verdict table

| Class::method | Verdict | Caller evidence (file:line) |
|---|---|---|
| `DisposalJournalEmitter::emit` | **(a) genuinely dead — WIRE** | Only reference repo-wide is its own test: `tests/Unit/Service/DisposalJournalEmitterTest.php:65`. No `->emit(` on this class in `lib/`; not in `register.d` (the `"emit": "scheduled.autoEscalate"` hit in the triage is an event *name*, not a handler); not routed; no job. Its own docblock (`lib/Service/DisposalJournalEmitter.php:7`) names the intended trigger — the `FixedAsset` `dispose` lifecycle action — which **does not exist** (see D1). |
| `IntercompanyJournalService::reconcileVariance` | **(a) genuinely dead — WIRE** | `lib/Service/IntercompanyJournalService.php:128`. Only reference repo-wide: `tests/Unit/Service/IntercompanyJournalServiceTest.php:51`. The *whole class* is orphaned — `buildMirror` (:91), `isBalanced` (:145), `isTransitionAllowed` (:161), `statusAfterEdit` (:184) have zero callers too. The class docblock says "the controller layer persists the records" — **no such controller exists** (`grep -rn IntercompanyJournalService lib/` → 0 hits). `IntercompanyMatchingService` is a *different* class (the `bookkeeping-intercompany-elimination` register) and is reachable; this one (REQ-MA-004, `bookkeeping-multi-administratie`) is not. |
| `GRIRClearingService::reconcileGRIRSaldoForPeriod` | **(a) genuinely dead — WIRE (surface)** | `lib/Service/GRIRClearingService.php:693`. The class *is* reachable — `lib/Listener/GRIRClearingListener.php` calls `postGRIRForGoodsReceiptAccept`/`postGRIRForServiceReceiptAccept`/`settleGRIRForMatchedInvoice` (registered `lib/AppInfo/Application.php:229`) — but this third public method has no route, no controller, no CLI command. **Class-injected ≠ method-called**, exactly as the triage warned. Matches shillinq#424. |
| `OssPaymentReconciliation::reconcileDistribution` | **(a) genuinely dead — WIRE** | `lib/Service/OssPaymentReconciliation.php:121`. Only reference: `tests/Unit/Service/OssPaymentReconciliationTest.php:51`. Sibling `canMarkPaid` **is** referenced from `register.d` (`bookkeeping-btw-oss-eu.json:612` `OssReturn.pay`, `:774` `OssPayment.reconcile`) — but see D2: that reference is broken, so even `canMarkPaid` never runs. `matches()` (:70) is likewise uncalled; it becomes the bank-transaction leg of the new guard. |
| `AcmReportGenerator::reconcileOmzet` | **out of scope (reporting, not GL posting)** | `lib/Service/AcmReportGenerator.php:156` — zero callers confirmed. Its sibling `submit` **is** wired (`register.d/bookkeeping-market-government-separation.json:1620` `"handler"`). This is ACM regulatory *reporting*, not a GL/tax posting — it belongs to the disclosure/export cluster of #446 and is deliberately **left untouched**, not silently claimed. |

No method in this cluster is **(b) superseded** and none is **(c) a
consumer seam**: all four are internal, app-owned, side-effecting money
paths whose designs name a concrete in-app trigger. Nothing is left
**UNSURE**.

## Decisions

### D1 — The `FixedAsset` disposal transition is itself broken (fix it, or the wiring is a no-op)

`lib/Settings/register.d/bookkeeping-fixed-assets-depreciation.json`
declares:

```json
"x-openregister-lifecycle": { "transitions": { "dispose": { "action": { "description": "REQ-FA-008 — Materialises a balanced JournalEntry ..." } } } }
```

No `field`, no `states`, no `from`, no `to`. OpenRegister's
`TransitionEngine::transition()`
(`openregister/lib/Service/Lifecycle/TransitionEngine.php:135-174`) reads
`$annotation['field']` (→ `''`), `$spec['to']` (→ `''`) and `$spec['from']`
(→ `[]`), then does `in_array($currentValue, $from, true)` — which is always
`false`. **Every** `dispose` attempt throws
*"Transition dispose is not allowed from current state ''"*, and no
`ObjectTransitionedEvent` is ever dispatched for a `FixedAsset`. The
declared `action.type` (`emit-journal-entry-and-schedule`) is not an action
type OpenRegister implements either.

Decision: repair the annotation — `field: status`, the three real `states`
(`active` / `inactive` / `retired`, the schema's existing `status` enum —
we do **not** invent a `disposed` state), and explicit `from`/`to` on every
transition (`activate: inactive → active`, `dispose: active → retired`,
`transferInternal` / `splitTransfer: active → active`). Only then can a
listener observe the disposal.

### D2 — The OSS `canMarkPaid` guard tag never resolves (HTTP 500 on both money transitions)

`OssReturn.pay` and `OssPayment.reconcile` declare
`"requires": "OCA\\Shillinq\\Service\\OssPaymentReconciliation::canMarkPaid"`.
OpenRegister's `LifecycleGuardRegistry::resolve()` treats the **entire**
string (including `::canMarkPaid`) as one DI tag and requires the resolved
service to implement `LifecycleGuardInterface`. `OssPaymentReconciliation`
is a plain pure-logic class, `canMarkPaid` takes **two** arrays, and no tag
is registered → `resolve()` throws, uncaught, in
`LifecycleValidationListener` → HTTP 500. This is the shillinq#425 defect
class; #433 tracks the fleet-wide tail, but this specific pair sits *inside*
this cluster's money path and gates the trigger we are wiring, so it is
fixed here.

Decision: add `lib/Guard/OssPaymentGuard.php` (`bool canMarkPaid(array $object)`),
which resolves the counterpart record (payment → its `OssReturn`, or return
→ its `OssPayment`) via the real ObjectService API and delegates to the
existing, unmodified `OssPaymentReconciliation::canMarkPaid()`; register the
exact literal tag through the existing `RegisterRequiresGuardAdapter`
(`lib/AppInfo/Application.php`), the pattern shillinq#425 established.
Fail-closed: a missing counterpart denies the transition.

### D3 — Reverse the accumulated depreciation that was actually POSTED, not a recomputed one

`DisposalJournalEmitter::emit()` derives the book value from
`DepreciationCalculator::currentBookValue()` and then books
`accumDep = cost − bookValue`. If that recomputed figure differs by one cent
from what the yearly depreciation runs actually posted, the disposal leaves
a residual balance on the accumulated-depreciation account — a balanced
journal that still corrupts the ledger. The authoritative figure is
`DepreciationSchedule.accumulatedDepreciation` (the schedule rows carry
`glTransactionRef`, i.e. what really hit the GL).

Decision: `emit()` gains one **optional** parameter,
`?float $bookValue = null` (no existing caller — it had none). The new
`FixedAssetDisposalService` looks up the latest `DepreciationSchedule` for
the asset (`assetRef`/`assetNumber`, `periodEndDate <= disposalDate`,
`status` in `active|completed`) and passes
`purchaseCost − accumulatedDepreciation`. With no schedule rows the
parameter stays `null` and the calculator's straight-line value is used
exactly as before.

### D4 — Field-name normalisation, not a schema rewrite

The shipped `FixedAsset` schema declares `purchaseCost`,
`capitalizationAccountNumber`, `accumulatedDepreciationAccountNumber`,
`retirementDate`, `salvageProceeds`; the emitter reads `acquisitionCost`,
`assetAccountNumber`, `accumulatedDepAccountNumber`, `disposalDate`,
`disposalProceeds`. The demo seed (`lib/Settings/seeds/fixed-assets-demo.json`)
writes **both** spellings. Passing a raw record to `emit()` would therefore
have produced an *empty* journal (cost 0, proceeds 0, zero lines) — balanced,
silent and worthless.

Decision: `FixedAssetDisposalService::normaliseAsset()` accepts either
spelling (mirroring `GRIRClearingService::postGRIRForServiceReceiptAccept()`,
which already normalises `SvcReceipt` into the GRN shape) rather than
rewriting a schema that live records already use. Two genuinely missing
properties are added additively to the schema: `administrationId` (tenant
scope — the seeder already writes it) and `disposalAccountingTreatment`
(the `dispose` action's own description requires it).

### D5 — ADR-031: declarative first, exception path only where the DSL cannot express it

- **Declarative** (register.d): the repaired `FixedAsset` lifecycle; the
  `IntercompanyJournalEntry.eliminate` `requires` guard; the OSS `requires`
  tags (now resolvable).
- **Exception path** (PHP listeners): the three postings themselves. OR's
  declarative lifecycle DSL has no action type that can (a) read a sibling
  schema (`DepreciationSchedule`, the counter-side `IntercompanyJournalEntry`,
  the `OssReturn`), (b) do integer-cent arithmetic, and (c) write a
  multi-row `GLTransaction` + `GLLine` set. Cross-schema aggregation on the
  exception path is the same justification `IntercompanyToleranceGuard` and
  `GRIRClearingListener` already carry.

### D6 — Fail-soft listeners, fail-closed guards

The three new listeners mirror `GRIRClearingListener` /
`DeliveryDispatchListener`: a downstream failure is logged, never bubbled
into the triggering transition (a GL posting failure must not roll back the
operator's disposal). The two new guards are fail-closed: any exception, or
a missing counterpart record, denies the transition
(`RegisterRequiresGuardAdapter` already enforces this).

### D7 — GL balance is asserted before persistence

`FixedAssetDisposalService` calls the emitter's own
`linesBalance()` and refuses to persist an unbalanced set
(`RuntimeException`), exactly as `GRIRClearingService::postBalancedTransaction()`
does. A posting that fires but posts an unbalanced entry is the same defect
in a different hat; every new test asserts `sum(debit) === sum(credit)` **and**
the per-account amounts.

## Seed data

No new seed files. The existing `lib/Settings/seeds/fixed-assets-demo.json`
(4 `FixedAsset` + 4 `DepreciationSchedule` records, seeded by
`SettingsService::seedFixedAssetsDemo()` from `Repair/InitializeSettings`)
is exactly the fixture the disposal path needs and is used as the shape
reference for the unit tests. `administrationId` is already written onto
each seeded asset by the seeder; the schema property added in D4 makes that
declaration honest. No `EuVatRate` / `OssReturn` / `IntercompanyJournalEntry`
seed changes: the OSS worked-example return and the intercompany examples
already ship in their register fragments.

## Risks

- **Turning on lifecycle enforcement for `FixedAsset`.** Adding `field` +
  `states` makes OR's `LifecycleValidationListener` validate every `status`
  change on a `FixedAsset` save. Verified there is no code path in `lib/`
  that writes `FixedAsset.status` (`grep -rn "FixedAsset" lib/ --include=*.php`
  → only the BBV report generator, the scheduled-workflow registration and
  the demo seeder, none of which transition status), so the only writers are
  the API/UI, which now get a *working* transition instead of a 500.
- **`GLLine.subLedgerType`.** Extending the enum with `fixed-asset` is
  additive (list-concat, the same mechanism `inventory-cogs-posting` uses for
  `inventory`); existing lines are unaffected.
