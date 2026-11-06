# Change: contracts-single-home

## Why

Shillinq ships **two different, fully-defined schemas both named `Contract`**
in separate `register.d` fragments:

- `lib/Settings/register.d/contract-lifecycle-management.json` — the generic
  Contract Lifecycle Management (CLM) `Contract`: `contractNumber`, `title`,
  `contractType` (purchase/sales/service/subscription/lease/employment/other),
  `direction`, `counterpartyReference` (NC addressbook), `startDate`/`endDate`
  (null = indefinite), `renewalTerms`, `totalContractValue`, `status`
  lifecycle (`draft→active→expiring→expired`, `renewed`/`terminated` exits),
  `predecessorContract`/`successorContract`.
- `lib/Settings/register.d/bookkeeping-ifrs15-revenue.json` — the IFRS-15
  revenue-recognition `Contract`: `customerId`, `signedAt`,
  `fixedConsideration`/`variableConsideration`, `lifecycleState`
  (`draft→signed→in-delivery→completed`/`cancelled`), the five-step
  revenue-recognition header for `PerformanceObligation` /
  `TransactionPrice` / `PriceAllocation` / `RevenueRecognitionEvent`.

**This is not a cosmetic naming clash — it is a live, mechanically-verified
schema-merge defect.** `SettingsService::deepMergeConfig()`
(`lib/Service/SettingsService.php:1563-1582`) unions `register.d/*.json`
fragments in alphabetical file order; `components.schemas` is a keyed object,
so both `Contract` definitions recurse into **one merged schema**, and
`bookkeeping-ifrs-15-revenue.json` < `contract-lifecycle-management.json`
alphabetically, so CLM's fragment is layered on top. Concretely, in the
schema OpenRegister actually imports today:

- `required` is a **list array — `array_merge`-concatenated, not replaced**
  (`deepMergeConfig` line 1571-1572). The merged `Contract.required` is the
  union of CLM's `[contractNumber, title, contractType, status]` and
  IFRS-15's `[contractNumber, customerId, signedAt, startDate,
  fixedConsideration, currency, lifecycleState, administrationId]` — an
  operator filling in the CLM `/contracts` create form (which only collects
  the CLM fields) is required, by the merged schema, to also supply
  `customerId`, `signedAt`, `fixedConsideration`, `lifecycleState` and
  `administrationId`, fields that form never asks for.
- `x-openregister-lifecycle.states`/`.transitions` are keyed objects, so they
  **deep-merge into one 10-state, 9-transition machine** spanning both
  domains (CLM's `draft/active/expiring/expired/renewed/terminated` plus
  IFRS-15's `draft/signed/in-delivery/completed/cancelled`), driven by
  whichever `field` wins the leaf-scalar overwrite (CLM's `"field":
  "status"`, since CLM loads last).
- `x-openregister-notifications` similarly unions CLM's three rules with
  `semantic-invoice-consume.json`'s `handoffReceived` rule (that fragment
  loads last alphabetically and layers its own partial `Contract` block on
  top of the merge — see below).
- **Corroborating evidence already in the repo**: the ADR-051 adoption
  fragment `semantic-invoice-consume.json`'s own demo seed object for
  `"schema": "Contract"` (`contract-handoff-demo-2026`) sets fields from
  *both* domains at once — CLM's `contractType`/`direction`/
  `counterpartyReference` **and** IFRS-15's `customerId`/`signedAt`/
  `fixedConsideration`/`lifecycleState` — because the merged `required` list
  demands it. Whoever wrote that fragment already worked around this defect
  without naming it.
- **This is the same defect class the fleet has already caught once in this
  exact codebase.** `abstract-order-primitive`'s build found and reverted a
  colliding `Grant` schema for the identical reason ("`SettingsService::
  deepMergeConfig` recursively merges same-slug `components.schemas` entries
  across register.d fragments") before it shipped. The `Contract` collision
  was not caught, and `tests/validate-registers.js`'s slug-collision check
  only catches slugs that differ **by case** — it does not flag two fragments
  declaring the *identical* slug as separate full definitions, which is
  exactly this case.

**ADR-051 (`semantic-object-handoff`) needs exactly one unambiguous `Contract`
per the `ns#Contract` kind vocabulary**, and shillinq's own `semantic-invoice-
consume.json` already declares `configuration.implements:
["https://openregister.app/ns#Contract"]` for the `Contract` slug — riding,
today, on the ambiguous merged schema above. The nav layer independently
surfaced the user-visible symptom: two identically-titled "Contracts" pages
(`Contracts` / `RevenueContracts`); the in-flight `nav-six-clusters` change
already relabels them at §4 row 25 ("Procurement Contracts" /
"Revenue Contracts"), but that row's analysis was written on the (incomplete)
premise that the two pages "share a schema" like a legitimate role-lens
(cf. `Account`, row 4) — they do not share a schema on purpose; they collide
into one by accident.

**Binding fleet decision (2026-08-19):** shillinq owns the canonical
`Contract` — it declares the ADR-051 `ns#Contract` kind so downstream apps
discover contracts by kind. pipelinq's own `contract` schema
(`contract-renewal-tracking`, which also declares `implements:
["https://openregister.app/ns#Contract"]`) becomes an *optional* consuming
leaf — the reverse of the earlier cross-app audit recommendation. Only
shillinq-side work is scoped to this change; the pipelinq-side follow-up is
handed back to the orchestrator as a task list (see design.md §D6) — **no
pipelinq artifacts are authored here**.

## What changes

1. **CLM's `Contract` becomes the fleet's sole, canonical `Contract`** and
   unambiguously declares the ADR-051 kind
   `https://openregister.app/ns#Contract` (already present via
   `semantic-invoice-consume.json`; this change removes the ambiguity it was
   silently riding on rather than adding a new declaration).
2. **The IFRS-15 revenue `Contract` schema is renamed to `RevenueContract`**
   — slug, `components.schemas` key, title, every FK/description that says
   "Contract" meaning the revenue contract, seed objects, tests, and the
   manifest page/route/nav wiring that targets it by schema name (route ids
   and menu labels are untouched — that is `nav-six-clusters`' job — only the
   `schema` field values change). Ships as a non-destructive rename with a
   count-abort data migration for any live `RevenueContract` (formerly
   `Contract`, IFRS-15 register) objects, mirroring the
   `consolidate-order-subsidie-collisions` / `SubsidieOrderConsolidationMigrator`
   precedent (generic slug stays put; the domain-specific schema gets the
   new name).
3. **Every consuming fragment is updated** — register.d (FK field
   descriptions across `PerformanceObligation`, `TransactionPrice`,
   `PriceAllocation`, `RevenueRecognitionEvent`, `ContractAsset`,
   `ContractLiability`, `ContractModification`,
   `VariableConsiderationAdjustment`, `ContractCostAsset`,
   `RevenueWaterfall`; the `semantic-invoice-consume.json` demo seed object
   is cleaned to CLM-only fields now that the merge no longer forces
   IFRS-15 fields onto it), manifest.d (`bookkeeping-ifrs15-revenue.json`
   page `schema` values), tests (`Ifrs15RevenueFragmentTest`,
   `Ifrs15RevenueIntegrationTest`, `RevenueCutoffServiceTest`), and docs.
4. **`tests/validate-registers.js` gets a new check**: two or more `register.d`
   fragments declaring a **full** schema body (`type` + `required` both
   present) for the identical `components.schemas` key is a hard failure —
   closing the blind spot the case-insensitive check does not cover.
5. **gate-19 e2e**: the CLM `Contracts` index + detail currently has **zero**
   e2e coverage (only `tests/e2e/bookkeeping-ifrs15-revenue.spec.ts` exists,
   covering the IFRS-15 side). This change adds coverage for both index +
   detail routes post-rename, plus a kind-discovery assertion if the platform
   exposes one cheaply (see design.md §D7 for why most of ADR-051's own
   scenarios are `@e2e exclude`d as static-declaration assertions).
6. **pipelinq task list** (cross-repo, handed to the orchestrator — no
   pipelinq artifacts authored here): see design.md §D6.

## Impact

- **Schemas**: `Contract` (CLM) unchanged in shape, gains an unambiguous
  `implements` marker; the IFRS-15 `Contract` → `RevenueContract` (rename,
  same shape, migration for live objects).
- **Nav / bytes**: none. Route ids, menu labels, and manifest byte weight are
  unaffected — only `schema` field *values* inside existing page configs
  change from `"Contract"` to `"RevenueContract"` for the six IFRS-15 pages.
  `nav-six-clusters`' label relabel (§4 row 25) is unaffected and not
  duplicated.
- **Consumers**: 10 register.d fragments' FK descriptions, 1 manifest.d
  fragment (6 pages), `semantic-invoice-consume.json`'s demo seed, ~4 PHP
  test files, 1 e2e spec (new or extended), `tests/validate-registers.js`.
- **Non-goals**: no CLM feature work, no IFRS-15 revenue-recognition logic
  changes, no renewal-notification feature work, no pipelinq-repo changes.
