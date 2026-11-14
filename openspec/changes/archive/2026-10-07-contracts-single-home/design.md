# Design: contracts-single-home

## Context

Two facts drive this design and were re-verified against HEAD before writing
it (not carried over from the audit that requested this change):

1. `lib/Service/SettingsService.php:1507-1582` (`loadRegisterConfigData` +
   `deepMergeConfig`) globs `register.d/*.json`, sorts alphabetically, and
   deep-merges every fragment onto `shillinq_register.json` **before**
   OpenRegister's `ImportHandler` ever sees the data. Keyed objects
   (`components.schemas.*`) recurse and union; list arrays (`required`,
   `enum`, …) are `array_merge`-concatenated; scalars are last-write-wins.
   Both `contract-lifecycle-management.json` and
   `bookkeeping-ifrs15-revenue.json` declare a full `components.schemas.
   Contract` (type, required, properties, x-openregister-lifecycle, …), and
   `semantic-invoice-consume.json` layers a third, partial `Contract` block
   (`configuration.implements`, `handoffContract`, one property, one
   handoff, one notification rule) on top, alphabetically last. All three
   merge into one live schema.
2. `tests/validate-registers.js`'s only collision check is case-insensitive
   *slug divergence* (`checkSlugCaseCollisions`, e.g. two schemas whose slugs
   differ only by case) — it does not detect two fragments declaring the
   **identical** `components.schemas` key as separate full bodies, because
   both entries land under one `registry['Contract']` record with a single
   `slug` value and no case divergence to flag.

## D1 — What the merged schema actually looks like today (verified, not assumed)

Given the fragment load order (`bookkeeping-ifrs15-revenue.json` <
`contract-lifecycle-management.json` < `semantic-invoice-consume.json`
alphabetically) and `deepMergeConfig`'s union/concat/overwrite rules:

| Key | Result of the merge |
|---|---|
| `required` | `array_merge` of IFRS-15's 8 fields + CLM's 4 fields = 12 entries (1 duplicate: `contractNumber`), spanning two unrelated data models |
| `properties` | union of both property sets (23 CLM + 14 IFRS-15 + 1 semantic-invoice-consume = ~38 properties on one schema) |
| `x-openregister-lifecycle.states` | union: `draft/active/expiring/expired/renewed/terminated` (CLM) + `draft/signed/in-delivery/completed/cancelled` (IFRS-15) — `draft` is the only shared state |
| `x-openregister-lifecycle.transitions` | union: `activate/markExpiring/expire/terminate/renew` (CLM) + `sign/beginDelivery/complete/cancel` (IFRS-15) — 9 transitions on one field |
| `x-openregister-lifecycle.field` | `"status"` (only CLM sets this key; IFRS-15's own state field is `lifecycleState`, so the merged machine drives off `status` while IFRS-15's own `lifecycleState` property sits unused by the lifecycle engine) |
| `x-openregister-notifications` | union: CLM's 3 rules + `semantic-invoice-consume.json`'s `handoffReceived` rule (IFRS-15's `Contract` declares none of its own) |
| `configuration.implements` | `["https://openregister.app/ns#Contract"]` (from `semantic-invoice-consume.json`, applies to whichever merged body it lands on) |

This is not a hypothetical worst case — it is what `SettingsService::
loadConfiguration()` assembles and hands to OpenRegister's `ImportHandler` on
every install/upgrade today. The corroborating evidence already in the repo:
`semantic-invoice-consume.json`'s own demo seed object for `"schema":
"Contract"` sets `contractType`/`direction`/`counterpartyReference` (CLM)
**and** `customerId`/`signedAt`/`fixedConsideration`/`lifecycleState`
(IFRS-15) in the same object — it has to, to satisfy the concatenated
`required` list. Whoever authored that fragment discovered this collision
empirically and worked around it without naming it.

## D2 — Why the IFRS-15 side renames, not CLM

Direct precedent in this repo: `consolidate-order-subsidie-collisions`
(archived 2026-07-07) resolved the `Order` slug collision by renaming the
domain-specific booking order (`Order` → `BookingOrder`) and **reserving the
generic slug** for the abstract primitive that needed it fleet-wide
(`abstract-order-primitive`'s `Order`/`OrderPrimitive`). The same shape
applies here:

- CLM's `Contract` is the **generic, cross-domain** record (any contract
  type: purchase/sales/service/subscription/lease/employment) — the natural
  fleet-wide `ns#Contract` implementer per the 2026-08-19 binding decision
  and ADR-051's own vocabulary table (`title`, `counterparty`, `currency`,
  `totalAmount`, `startDate`, `source` — CLM's field set is already a
  superset-shaped match; IFRS-15's is not).
- IFRS-15's `Contract` is a **domain-specific specialisation** (revenue
  recognition under IFRS 15/ASC 606) with its own five-step model
  (`PerformanceObligation`/`TransactionPrice`/`PriceAllocation`/
  `RevenueRecognitionEvent`) hanging off it. Renaming it to
  `RevenueContract` matches the vocabulary IFRS-15 disclosure language
  itself uses ("revenue contract") and mirrors `nav-six-clusters`' own
  relabel of its index page to "Revenue Contracts" (§4 row 25) — the schema
  rename and the nav relabel now say the same thing for the same reason,
  instead of a relabeled page pointing at an ambiguously-named schema.

**Instance-wide slug uniqueness caveat** (per `abstract-order-primitive`'s
own `issue #503` note): OpenRegister's schema-slug lookup is case-insensitive
and NOT scoped per-app or per-organisation — a live foreign schema already
occupied the bare `Order` slug on the shared dev instance. Before this change
ships, `RevenueContract` MUST be checked against the shared instance's live
schema list for a collision the same way (task in tasks.md — this is not
assumed clean).

## D3 — Rename + migration approach

Mirrors `SubsidieOrderConsolidationMigrator`
(`lib/Service/Migration/SubsidieOrderConsolidationMigrator.php`) and its
`consolidate-order-subsidie-collisions` design (D4 there):

1. **Schema-definition rename** (non-destructive, register.d only): change
   `components.schemas.Contract` → `components.schemas.RevenueContract` and
   `"slug": "Contract"` → `"slug": "RevenueContract"` in
   `bookkeeping-ifrs15-revenue.json`; every internal FK/description that
   currently reads "Contract" meaning the revenue contract is reworded to
   "RevenueContract" (the FK field *names* — `contractId`,
   `parentContractId` — are untouched; only the prose descriptions and any
   literal schema-slug references change).
2. **Migrator class** (`RevenueContractRenameMigrator`, unit-tested): a
   `mapObjectToRenamedSchema()` that re-points `@self.schema` from
   `"Contract"` to `"RevenueContract"` for every object whose `@self.schema
   === "Contract"` **and** whose shape matches the IFRS-15 field set
   (`customerId`/`fixedConsideration`/`lifecycleState` present) rather than
   the CLM field set (`contractType`/`status` present) — the discriminator
   needed because, until this migration runs, both kinds of object may be
   sitting under the same merged schema slug. An `assertCountsMatch()`
   count-abort guard (source count === target count after move, abort with
   source intact on any mismatch) — the same no-row-loss invariant as the
   precedent.
3. **Repair step**: registers the migrator behind the existing
   `InitializeSettings` repair flow, run once, idempotent (a second run finds
   zero `Contract`-slugged IFRS-15-shaped objects and no-ops).
4. **Live-run verification deferred to a live import**, exactly as the
   precedent's D4 — the buildable, unit-tested migrator core ships in this
   change; the actual live re-point is verified against a running instance
   and the object count (currently 0 in a clean dev env per the seed data:
   the register.d files themselves seed 4 CLM demo Contracts + 4 IFRS-15
   demo Contracts + 1 handoff-demo Contract — these are the *fragment seed
   objects*, not yet-imported live objects; whether any **operator-created**
   live objects exist on any real instance is a task to check via the OR API
   before this change ships, not assumed).
5. **Seed objects in the register fragments themselves are edited directly**
   (not migrated at runtime) — `bookkeeping-ifrs15-revenue.json`'s four
   `"schema": "Contract"` seed objects become `"schema": "RevenueContract"`
   in the same edit that renames the schema definition; `semantic-invoice-
   consume.json`'s `contract-handoff-demo-2026` object stays `"schema":
   "Contract"` (it is a CLM contract by design — that is the ADR-051 handoff
   target) but drops the four IFRS-15-only fields it currently carries
   (`customerId`, `signedAt`, `fixedConsideration`, `lifecycleState`) since
   the merge that forced them onto it no longer exists.

## D4 — Consumer-update inventory (every file this rename touches)

Verified by grep against HEAD; each line is a concrete edit, not a guess:

**register.d/**
- `bookkeeping-ifrs15-revenue.json` — schema key + slug + title (1 def),
  4 seed objects' `@self.schema`, and the FK-description prose in
  `PerformanceObligation.contractId`, `TransactionPrice.contractId`,
  `PriceAllocation.contractId`, `RevenueRecognitionEvent.contractId`,
  `ContractAsset.contractId`, `ContractLiability.contractId`,
  `ContractModification.parentContractId`,
  `VariableConsiderationAdjustment.contractId`,
  `ContractCostAsset.contractId`, `RevenueWaterfall.contractId` (10 fields
  across 9 schemas — field *names* unchanged, only prose).
- `semantic-invoice-consume.json` — no schema-key change (still targets
  CLM's `Contract`); its demo seed object
  (`contract-handoff-demo-2026`) drops `customerId`/`signedAt`/
  `fixedConsideration`/`lifecycleState`.
- `contract-lifecycle-management.json` — no change (CLM's `Contract` is
  already correct); gains the confirmation that its
  `x-openregister-lifecycle`/`required`/`x-openregister-notifications` no
  longer merge with a second schema body once IFRS-15's is renamed.

**src/manifest.d/**
- `bookkeeping-ifrs15-revenue.json` — 6 `page.config.schema` values
  (`RevenueContracts` index, `ContractDetail`, plus every `relatedLists`/
  `sidebarProps` widget `props.schema` that names `"Contract"`) become
  `"RevenueContract"`. Menu ids/labels/routes are untouched
  (`nav-six-clusters`' scope).

**tests/**
- `tests/Unit/Service/Ifrs15RevenueFragmentTest.php` — literal `'Contract'`
  slug assertions at (verified) lines 60, 144, 274 → `'RevenueContract'`.
- `tests/Unit/Service/RevenueCutoffServiceTest.php` — 1 schema-slug
  reference.
- `tests/Integration/Ifrs15RevenueIntegrationTest.php` — 3 schema-slug
  references.
- `tests/validate-registers.js` — new same-slug-full-definition collision
  check (D5 below); confirm the fixed register now reports exactly one full
  `Contract` definition and one full `RevenueContract` definition.
- New/extended `tests/e2e/contracts-single-home.spec.ts` (D7 below).

**Explicitly NOT touched** (verified no reference to the schema slug, only
to unrelated identically-spelled words or to FK field names that don't
change): `docs/user-guide/bookkeeping/contracts-and-pos.md`,
`docs/user-guide/bookkeeping/contract-balances.md` (prose only, spot-check
in tasks.md rather than a mechanical rename), `nav-six-clusters` artifacts
(sibling change, not edited by this one).

## D5 — Same-slug collision gate (`tests/validate-registers.js`)

The existing `checkSlugCaseCollisions` catches "two different slugs that
collide case-insensitively." It does not catch "two fragments declare the
identical `components.schemas` key with a full body" — the actual defect
class this change fixes, and the one `abstract-order-primitive` also hit
once (the `Grant` near-miss, caught by hand, not by this validator). New
check: for every `components.schemas` key, if **two or more source files**
each declare a body containing both `type` and `required` (i.e. a full
schema definition, not a partial augmentation fragment like `semantic-
invoice-consume.json`'s `configuration`/`properties`-only blocks), fail with
the file list — mirroring the existing check's `console.error` format so the
message tells the operator exactly which two files collide and how to
un-collide them (rename one, or convert one to a partial augmentation).

## D6 — pipelinq task list (handed to the orchestrator; no pipelinq artifacts here)

Investigated pipelinq's `contract-renewal-tracking` spec
(`openspec/specs/contract-renewal-tracking/spec.md`) and its register
fragment (`lib/Settings/register.d/96-contract-renewal.json`) — both already
implemented. Findings:

1. **pipelinq's `contract` schema already declares its own
   `configuration.implements: ["https://openregister.app/ns#Contract"]`**
   (`96-contract-renewal.json` line ~29). Once shillinq's `Contract`
   unambiguously declares the same kind (this change), OR's
   `SemanticTypeResolver` has two installed implementers of `ns#Contract`
   and falls back to its deterministic tie-break + WARN (per ADR-051 — this
   is tolerated, not a hard error, but is exactly the ambiguity the fleet
   decision is meant to resolve).
2. **pipelinq's `contract` is a genuinely different bounded context**: it
   tracks CRM recurring-revenue/renewal/MRR-ARR-churn against a `client` +
   product-catalog `lineItems`, with its own renewal-lead automation and
   nightly renewal-window job — none of which has an equivalent in
   shillinq's CLM. It is **not** simply a duplicate to delete.
3. **pipelinq's contract-to-invoicing handoff already targets `ns#Invoice`
   directly** (`Requirement: Contract-to-Invoicing Handoff Emit`,
   "Send to invoicing" → shillinq's `abstract-order-primitive` Invoice),
   bypassing the `ns#Contract` kind entirely on the way out. It does not
   currently *consume* shillinq's Contract kind at all — only ADR-048-style
   Vendor references exist elsewhere in pipelinq.

**Recommended task list for the pipelinq-side orchestrator run** (evidence
above, decision left to that change):
- Evaluate dropping pipelinq's own `implements: ["https://openregister.app/
  ns#Contract"]` marker on `contract` now that shillinq is the fleet's
  canonical, ADR-051-decided owner — this removes the tie-break ambiguity
  without touching pipelinq's CRM-specific schema, fields, or automation,
  which stay pipelinq-local (not a shillinq concern).
- If pipelinq drops the marker, `contract` becomes exactly the "optional
  leaf" shape the binding decision describes: it keeps working standalone
  (renewal/MRR features untouched) and, if shillinq is installed, MAY
  additionally reference shillinq's canonical `Contract` via an ADR-048
  semantic reference (read-only cross-link) for display purposes — this is
  new work, not automatic, and needs its own spec if wanted.
- Separately evaluate whether the existing `Contract-to-Invoicing Handoff
  Emit` requirement should route through the `ns#Contract` kind (matching
  ADR-051's Quote→Contract→Invoice chain vocabulary) instead of jumping
  straight to `ns#Invoice` — out of this change's scope, flagged as a
  design question for whoever owns that requirement next.
- No pipelinq schema, register fragment, or spec file is touched by this
  change.

## D7 — gate-19 e2e plan

Most of ADR-051's own kind-declaration scenarios are legitimately `@e2e
exclude`d in the pipelinq precedent spec (`Scenario: Semantic kind
declaration present`) on the grounds that a static register-fragment
declaration has no browser-observable behaviour beyond re-reading the same
JSON through a longer pipe — enforced mechanically by import + hydra
gate-54 (`relation-dialect`) instead. The same reasoning applies to this
change's kind-declaration requirement. What **is** e2e-testable and
currently has zero coverage:

- CLM `Contracts` index (`/contracts`) and detail routes render post-fix
  (today: **no e2e file exists** for `contract-lifecycle-management` at
  all — this is a pre-existing gap this change closes as a side effect of
  needing to prove the un-merge didn't break rendering).
- The renamed IFRS-15 `RevenueContracts` index (`/ifrs-15/contracts`) and
  `ContractDetail` route still render post-rename — extend the existing
  `tests/e2e/bookkeeping-ifrs15-revenue.spec.ts` route-mount smoke rather
  than duplicating it.
- A kind-discovery assertion is included **only if** OR exposes a cheap,
  read-only endpoint that lists implementers of a semantic kind (verify at
  implementation time); if not, this scenario is `@e2e exclude`d with that
  reason, consistent with D-precedent above.

## D8 — Open questions

1. Whether `RevenueContract` collides instance-wide with a live foreign
   schema slug on the shared dev instance (per the `abstract-order-primitive`
   `Order`/`OrderPrimitive` precedent) — must be checked before shipping,
   not assumed clean (task in tasks.md).
2. Whether any **operator-created** (not fragment-seeded) live `Contract`
   objects exist that match the IFRS-15 shape, via the OR API — determines
   whether the migrator's live run has real rows to move (task in
   tasks.md, per the caller's instruction to make this a spec task rather
   than a live check performed while authoring this spec).
3. Whether `nav-six-clusters` lands before or after this change — the two
   are independent (label vs. schema layer) and can land in either order,
   but `nav-six-clusters`' §4 row 25 prose should be updated to drop the
   "share a schema, like Account" framing once this change ships, since
   that premise no longer holds. Flagged for whoever merges `nav-six-
   clusters`, not fixed here (different change, different file).
4. Whether shillinq's `Contract.configuration.handoffContract` binding in
   `semantic-invoice-consume.json` needs re-verification once the merge
   ambiguity is gone — expected to be a no-op (it already names `Contract`
   correctly), but should be exercised by the new e2e/integration coverage
   rather than assumed.
