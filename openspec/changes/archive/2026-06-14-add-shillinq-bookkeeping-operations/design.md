# Design — Bookkeeping Operations + NL Compliance Core (T3)

## Context

T1 (`add-shillinq-bookkeeping-foundation`) gave Shillinq a balanced double-entry GL. T2 (`add-shillinq-bookkeeping-subledgers-close-statements` — parallel) gives it sub-ledgers, periods, period close, AP/AR, and basic financial statements. **T3 is where Shillinq stops being a generic bookkeeping engine and starts being a Dutch-compliant bookkeeping engine for SMB, ZZP, and decentralised government.**

The change groups 10 capabilities that, together, cover the operator-facing regulatory surface a Dutch operator hits in their first month with the product:

1. BTW filing (every operator, every month or quarter)
2. BBV (every municipality)
3. IV3 reporting (every municipality, quarterly to CBS)
4. BCF (most municipalities, claiming back recoverable VAT)
5. KOR (every small SMB approaching €20k revenue)
6. ZZP tax regime (every freelancer)
7. Schatkistbankieren (every municipality banking with the Treasury)
8. Subsidie-verantwoording (every grant recipient)
9. Archiefwet retention (every operator, every record)
10. Consultancy project accounting (Conduction's own customer profile)

Each is a separate regulatory regime with its own data shape and its own lifecycle, but they share T1+T2's substrate. The design philosophy is: **each compliance regime ships as a register + lifecycle + workflow declaration, never as a service class**. The spec-only envelope keeps T3 reviewable as a unit; implementation lands later through `opsx-apply`.

## Goals

- Express every T3 capability as **declarative metadata** — schemas + `x-openregister-lifecycle` rules + manifest entries + `ScheduledWorkflow` declarations — per ADR-031.
- Consume every existing OR abstraction (lifecycle, audit, RBAC, approval, retention, scheduled workflow, aggregations, calculations, widgets, mappings) — per ADR-022.
- Consume every existing sibling-app abstraction (OpenConnector for external HTTP, docudesk for source-document storage) — per ADR-022.
- Make every spec **regulatory-citation reviewable** — a compliance officer or accountant reading the spec should be able to confirm the model maps to the cited Wet / Besluit / RJ / IFRS reference without code-diving.
- Keep T3 narrow enough that T4 (reporting) and T5 (cross-cutting) can attach without reshaping T3's schemas.

## Non-Goals

- No bespoke PHP `*Service` classes for state machines, aggregations, notifications, or calculations. ADR-031 anti-pattern list applies.
- No XBRL/SBR Nederlandse Taxonomie generation engine — T4's job. T3 ships only the SBR submission *trigger* + per-aangifte payload shape.
- No FX revaluation, no IFRS overlay, no group consolidation — T5.
- No bespoke Vue components beyond the manifest-driven generic pages. KOR threshold-warning + urencriterium tracker land as `x-openregister-widgets` consumed by `CnDashboardPage`.
- No app-local audit table. Every state transition is audited by OR's audit-trail-immutable.
- No app-local approval table. Every approval routing consumes OR's approval-workflow extension per ADR-022.
- No app-local retention sweep. Records expire via OR's lifecycle retention enforcement per the Archiefwet spec.

## Decisions

### D1 — Declarative-first, per ADR-031 (re-affirmed for the compliance domain)

Every T3 behaviour expressible as schema metadata MUST be declared in `lib/Settings/shillinq_register.json`, not authored as PHP. The compliance domain is **dense with state machines** (BTW-aangifte draft→submitted→accepted, KOR opt-in lifecycle, Subsidie aanvraag→teruggevorderd, Project offerte→archived). Every one of these is a textbook fit for `x-openregister-lifecycle`.

| Behaviour | Declarative form |
|---|---|
| BTW-aangifte lifecycle (`draft → submitted → accepted → corrected`) | `x-openregister-lifecycle` on `VatReturn` |
| BTW correction lifecycle (suppletie) | `x-openregister-lifecycle` on `VatCorrection` |
| KOR regime lifecycle (`outside → opted-in → threshold-warning → threshold-exceeded → opted-out`) | `x-openregister-lifecycle` on `KorRegime` |
| BCF claim lifecycle (`draft → submitted → accepted → settled`) | `x-openregister-lifecycle` on `BcfClaim` |
| Subsidie lifecycle (`aanvraag → verleend → vastgesteld → uitbetaald → teruggevorderd`) | `x-openregister-lifecycle` on `Subsidie` |
| Project lifecycle (`offerte → active → on-hold → closed → archived`) | `x-openregister-lifecycle` on `Project` |
| IV3 export lifecycle (`generated → submitted → accepted`) | `x-openregister-lifecycle` on `Iv3Export` |
| Schatkist position daily aggregation | `x-openregister-aggregations` over `BankTransaction` filtered by schatkist-flagged accounts |
| BCF claim aggregation (compensable VAT YTD) | `x-openregister-aggregations` filtered by `BbvAccountMapping.bcfCompensable` |
| KOR omzetdrempel YTD aggregation | `x-openregister-aggregations` over `Invoice` (T2) — see Risk 3 |
| Urencriterium YTD aggregation | `x-openregister-aggregations` over `BillableHour` |
| RJ 270 percentage-of-completion calculation | `x-openregister-calculations` on `Project` |
| Retention enforcement on every regulated record | `x-openregister-lifecycle.retention` per ADR-022; rules loaded from `selectielijst-gemeenten-2020.json` seed |
| BBV-taakveld mapping on every GL posting | `x-openregister-mappings` between `Account.accountNumber` and `BbvAccountMapping.taakveld` |
| External submission (SBR, IV3, BCF) | OR `ScheduledWorkflow` + n8n adapter, consuming OpenConnector source |
| KOR threshold-warning widget | `x-openregister-widgets` |
| Urencriterium tracking widget | `x-openregister-widgets` |
| Notifications on threshold-warning, approval-due, retention-due | `x-openregister-notifications` |

**Alternative considered**: Author `lib/Service/VatFilingService.php`, `lib/Service/BcfClaimService.php`, `lib/Service/KorRegimeService.php`, etc. — one service per regime, ~300 LOC each, ~3000 LOC total. Rejected per ADR-031. That is precisely the decidesk MotionService / VotingService / QuorumService anti-pattern. T3 ships clean.

### D2 — External submission flows live in OpenConnector, not shillinq

SBR/Digipoort (BTW + IV3), CBS (IV3), DigiKoppeling (BCF), and any future Belastingdienst HTTP surfaces are **OpenConnector sources**. shillinq declares an `x-openregister-lifecycle` action on the relevant aggregate (e.g. `VatReturn.submit`) that invokes an OR `ScheduledWorkflow` (or event-driven workflow) which in turn calls the OpenConnector source. shillinq never authors a PHP HTTP client for these surfaces.

This is ADR-022 applied to external-system abstractions: OpenConnector *is* the abstraction, and per ADR-031 §"Background jobs that orchestrate external systems", the workflow lives outside shillinq and is operator-configurable.

**Alternative considered**: Author per-regime SOAP/REST clients in `lib/Service/*Client.php`. Rejected — that is the exact pattern ADR-019 (integration registry) was built to retire. Each integration becomes a registered source consumed by every app, including the T3 capabilities.

### D3 — BBV taakveld mapping is a register, not an enum

BBV mandates posting every transaction against a *taakveld* (e.g. `0.1 Bestuur`, `1.2 Openbare orde en veiligheid`, `7.1 Volksgezondheid`). The mapping from RGS account → taakveld is **operator-editable** (one municipality may post `4250 Subsidies cultuur` to taakveld `5.3 Cultuurpresentatie`, another to `5.6 Media`). A hard enum would force every municipality to fork shillinq. A register makes the mapping per-administration override-able with a seed default.

The `BbvAccountMapping` register carries:

- `accountNumber` (FK to `Account` per T1)
- `taakveld` (enum from `bbv-taakvelden-2024.json` seed)
- `programmaCode` (operator-defined)
- `paragraafCode` (optional)
- `bcfCompensable` (boolean, drives BCF claim aggregation per T3 spec 4)
- `iv3Bucket` (enum from IV3-bestand specificaties)
- `autorisatieniveau` (enum)

The seed file `rgs-to-bbv-mapping.json` ships sensible defaults; the operator overrides per-administration. Per ADR-022, no parallel link table.

**Alternative considered**: Embed taakveld as a field on `Account` itself. Rejected — accounts are administration-scoped already, and the BBV mapping is conceptually *a relationship*, not a property of the account. Splitting keeps `Account` general-purpose (works for non-municipal admins too) and BBV-specific logic isolated.

### D4 — Selectielijst Gemeenten retention is operator-editable seed, consumed by OR's lifecycle

The Archiefwet 1995 + actuele Selectielijst Gemeenten 2020 define retention periods per record-type (e.g. financial records: 7 years; subsidy-grant records: until 10 years after settlement; meeting minutes: indefinite). Per ADR-022, **retention is OR's abstraction, not shillinq's** — every shillinq schema declares `x-openregister-lifecycle.retention: { rule: "selectielijst:5.1.2" }`, and OR's retention engine reads the rule definition from a registered rule set.

shillinq ships `selectielijst-gemeenten-2020.json` as seed data into the OR `RetentionRule` register. Each rule has:

- `selectielijstCode` (e.g. `5.1.2`)
- `description`
- `retentionYears` (or `retentionTrigger` for relative — "10 years after `settledOn`")
- `disposition` (enum: `destroy`, `archive`, `anonymise`, `keep_indefinite`)
- `legalBasis` (citation: Archiefwet 1995 art. X, Selectielijst §Y)

Each T3 schema (and every T1+T2 schema once T3 retrofits them in a follow-up) declares its retention via a single rule reference. The enforcement (purge, archive, anonymise) is entirely OR's.

**Alternative considered**: shillinq authors a `RetentionEnforcementJob` walking every record. Rejected — that's the ADR-031 anti-pattern documented under "Background jobs that walk an object queue". OR's lifecycle retention enforcement is the declarative path.

### D5 — KOR + urencriterium thresholds as seed + aggregation, alarms as notifications

KOR's €20.000 omzetdrempel and ZZP's 1225-uren-per-jaar criterium are both **threshold-driven alarms** built on YTD aggregations. The shape:

- `kor-thresholds-2026.json` seed declares the current law's threshold (€20.000 since 2020; will move with future law).
- `urencriterium-thresholds.json` seed declares 1225 hours per calendar year (current law since 2001; will move with future law).
- `KorRegime.ytdRevenue` is an `x-openregister-calculations` field aggregating from `Invoice` (T2) within the current calendar year.
- `UrenRegistratie.ytdHours` is an `x-openregister-calculations` field aggregating `BillableHour` for the same operator within the current calendar year.
- A threshold-crossing transition emits an `x-openregister-notifications` event at 80% (warning) and 100% (alarm); the lifecycle of `KorRegime` advances to `threshold-warning` and `threshold-exceeded` states automatically.

If the aggregation engine can express cross-period sums in `requires`, the entire flow is declarative. If not (Risk 3), a single-method PHP guard `KorThresholdGuard::currentYtdRevenue($adminId)` is called from the lifecycle precondition — ADR-031 exception path, ~30 LOC, no state.

**Alternative considered**: Daily cron job recomputing YTD per administration. Rejected — that's an ADR-031 anti-pattern *unless* the calculation can't fit in a derived field. Derived field is correct here.

### D6 — Project accounting (RJ 270 / IFRS 15) as calculation + aggregation, not service

Percentage-of-completion revenue recognition under RJ 270 / IFRS 15:

`recognisedRevenue = totalContractValue × (costsIncurredToDate / totalEstimatedCosts)` (cost-to-cost method, the most common)

This is a **derived field** on `Project` — `x-openregister-calculations` with two aggregation references (`costsIncurredToDate` summing `GLLine` postings on cost accounts tagged to the project; `totalEstimatedCosts` an operator-set field on the project). The recognition posting itself is a `JournalEntry` materialised by an OR scheduled workflow at month-end. No `ProjectRevenueRecognitionService`.

`utilization = billableHoursThisPeriod / capacityHoursThisPeriod` is another derived field. `project P&L` is a per-project `x-openregister-aggregations` filtering `GLLine` by project FK.

**Alternative considered**: Author a `RevenueRecognitionService` with `recogniseMonthEnd()`, `computeWipBalance()`, `computeProjectPl()`. Rejected — every method maps cleanly to an OR extension; the service would be ~400 LOC of orchestration that the schema engine collapses to ~80 LOC of metadata.

### D7 — Schatkistbankieren as schema flag + aggregation, not parallel ledger

Wet HOF mandates municipalities bank with the Treasury beyond a drempelbedrag. The implementation is *not* a parallel ledger — schatkist deposits and withdrawals post to the GL like any other bank transaction. The distinguishing markers:

- `Account.isSchatkistAccount: boolean` flag on the relevant T1 `Account` records (Treasury deposit account, working capital account).
- `SchatkistPosition` register holds the **daily aggregated position** (one record per administration per business day), a derived view via `x-openregister-aggregations` over the flagged accounts' `GLLine` postings.
- The drempelbedrag is a seed value in `schatkist-thresholds.json` (currently 0.75% of begroting for small munis, 0.5% for large — citation: Wet HOF art. 2 + ministerial regeling).
- Threshold-crossing notifications fire via `x-openregister-notifications`.

The daily liquidity report is a `type: dashboard` manifest page binding to the aggregation. No bespoke `SchatkistService`. No parallel ledger.

**Alternative considered**: Author `LiquidityService` with daily position recompute. Rejected — pure aggregation, fits declarative.

### D8 — Subsidie lifecycle and terugvordering as state-machine with optional sub-state

Awb 4.2 + ASV-model define a subsidie lifecycle:

`aanvraag → verleend → (eventueel) ingetrokken / gewijzigd → vastgesteld → uitbetaald → (eventueel) teruggevorderd → (eventueel) afbetalingsregeling`

The terugvordering sub-state is the trickiest — a settlement plan (afbetalingsregeling) needs its own payment schedule. T3 models this as:

- Main `Subsidie` lifecycle covers the canonical path.
- A `terugvordering` sub-state has a child relation to `BillableHour`-style `RepaymentInstallment` records (a small register tracking each instalment).
- Per ADR-022, no parallel state machine — the sub-state is just an additional lifecycle state on `Subsidie` with an `x-openregister-relations` reference to the instalment register.

The lifecycle states map to Awb article references in the seed `asv-model-lifecycle.json`; the operator sees the state names translated to plain Dutch in the UI.

### D9 — Specs sized per ADR-032 — `kind: config`

Per ADR-032, the change envelope's `kind:` is **config**. Every declared behaviour is metadata: schema fields, lifecycle blocks, seed data, manifest entries, scheduled workflow declarations. The only PHP that *might* land is the conditional aggregation guards (D5, Risk 3). Even those are single-method, no-state, ≤30 LOC each — solidly within the thin-glue exception of ADR-032.

Spec count: 10. Each is a self-contained capability. The intended `opsx-apply` shape is one cycle per spec (or one combined cycle if the implementing operator prefers — the registers don't conflict because each owns its own schemas). Hydra dispatch can proceed per-spec with `depends_on` chaining (see D10).

### D10 — Inter-spec dependency chain (per ADR-032 `depends_on`)

```
T1: chart-of-accounts → general-ledger → journal-entries
T2: trial-balance, period-close, accounts-payable, accounts-receivable, bookkeeping-financial-statements
T3 specs and their dependencies:
  bookkeeping-vat-btw-filing        depends on: T1.general-ledger, T2.period-close
  bbv-compliance        depends on: T1.chart-of-accounts, T1.general-ledger
  iv3-reporting         depends on: T3.bbv-compliance, T2.period-close
  bcf-vat-compensation  depends on: T3.bookkeeping-vat-btw-filing, T3.bbv-compliance
  kor                   depends on: T3.bookkeeping-vat-btw-filing
  zzp-tax-regime        depends on: T1.general-ledger
  schatkistbankieren    depends on: T2.bank-reconciliation (within T2.accounts-receivable spec), T1.general-ledger
  subsidie-verantwoording depends on: T1.general-ledger
  archiefwet-retention  depends on: ALL (consumes OR's retention abstraction; declared globally)
  consultancy-project-accounting depends on: T1.general-ledger, T2.accounts-receivable
```

The dependency graph is shallow — no spec depends on more than two T3 siblings. Hydra's `depends_on` enforcement (per hydra/CLAUDE.md) serialises the implementing PRs accordingly.

## Reuse Analysis

| Capability needed | What already exists | T3 reuse strategy |
|---|---|---|
| State machine for every aangifte / claim / subsidy / project / KOR-regime | `x-openregister-lifecycle` (ADR-031) | Every state-bearing T3 schema declares a lifecycle block. No app-local state machines. |
| Audit trail on every state transition | OR audit-trail-immutable (ADR-022) | Consumed automatically. Every aangifte submission / KOR regime switch / subsidie vaststelling / project lifecycle change is auditable end-to-end without app config. |
| Approval routing on submission gates (BTW aangifte boven drempel; subsidie-verlening; project-closure) | OR approval-workflow (ADR-022) | Consumed via `x-openregister-lifecycle.requires`. No app-local approval table. |
| RBAC per regime (BTW-aangifteverantwoordelijke, financieel administrateur, subsidie-coordinator, project-administrateur) | OR authorization (ADR-022) | Per-schema role definitions in the register file. T3 declares 8 named roles spanning the 10 specs; mappings in `design.md`'s RBAC subsection. |
| Recurring external submission (SBR-quarterly, IV3-quarterly, BCF-quarterly) | OR `ScheduledWorkflow` + n8n adapter (per ADR-031) | Each is a workflow declaration in the register; the workflow invokes the OpenConnector source. No app-local cron job. |
| External HTTP for SBR / Digipoort / CBS / DigiKoppeling | OpenConnector sources (per ADR-019) | T3 references symbolic source names; the source registrations land in a separate OpenConnector change. |
| Retention enforcement per Selectielijst | OR `x-openregister-lifecycle.retention` (per ADR-022) | shillinq seeds the rule set and declares the rule reference on every schema. Enforcement is OR's. |
| Threshold-warning alarms (KOR, urencriterium, schatkist drempel) | OR `x-openregister-notifications` + `x-openregister-calculations` (per ADR-031) | Calculated YTD field + lifecycle state transition + notification on the transition. No app-local cron. |
| Source-document storage (BTW aangifte PDFs, BCF claim files, subsidie beschikkingen) | docudesk (per ADR-022) | Referenced by URI from the relevant register records. No file storage in shillinq. |
| BBV-taakveld mapping | `x-openregister-mappings` + `BbvAccountMapping` register | shillinq seeds a default mapping; operator overrides per-administration. No app-local join code. |
| RGS account hierarchy (T1) | Already in `Account` register from T1 | Reused as-is. T3 adds `isSchatkistAccount` (D7), `bcfCompensable` (D3 via mapping), `bbvTaakveld` (D3 via mapping). |
| Period-stamped postings (T1) | `GLLine.periodId` from T1 | Reused as-is for IV3 quarterly aggregation, KOR YTD aggregation, urencriterium YTD aggregation. |
| Trial-balance + period-close (T2) | `bookkeeping-trial-balance`, `bookkeeping-period-close` from T2 | T3 consumes for IV3 export, BCF claim aggregation, KOR threshold (uses T2's revenue aggregation). |
| AP / AR (T2) | T2's `Invoice`, `Payment` registers | T3 reuses for project accounting (CPA), KOR omzet, subsidie uitbetaling. |
| Financial statements (T2) | T2's statement aggregations | T3 reuses for IV3 XML generation (IV3 buckets aggregate the statement). |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | T3 adds ~12 menu entries; visibility driven by administration-type seed (`Belastingen` for everyone, `Overheid` for gemeente/provincie/waterschap, `Projecten` for consultancy-flagged admins). |
| Widgets on dashboard | `x-openregister-widgets` + `CnDashboardPage` | KOR threshold-warning + urencriterium tracker + schatkist position; no bespoke Vue. |

**Net new code in T3 implementation**: ~14 schema declarations + ~12 manifest pages + ~9 seed JSON files + ~5 `ScheduledWorkflow` declarations. Possibly 2 short PHP lifecycle guards (~30 LOC each) if Risk 3 confirms the cross-period aggregations can't run inside the declarative engine.

## Declarative-vs-imperative decision (per ADR-031)

Per ADR-031 enforcement, each T3 behaviour was classified before this spec was finalised:

| Behaviour | Decision | Why |
|---|---|---|
| BTW-aangifte state machine | Declarative (`x-openregister-lifecycle`) | Pure state machine, textbook fit |
| BTW-aangifte payload aggregation (sum-by-rate from GL) | Declarative (`x-openregister-aggregations`) | Periodic aggregation over GL with rate-tag projection |
| SBR/Digipoort submission | OR `ScheduledWorkflow` + OpenConnector source | ADR-031 §"Background jobs that orchestrate external systems"; the workflow itself is imperative (n8n) but lives outside shillinq |
| BBV taakveld mapping enforcement | Declarative (`x-openregister-mappings` + `BbvAccountMapping` register) | Per-administration override via register |
| IV3 export XML generation | OR Mapping (transformation spec, declarative) → file artifact via OR's file engine | XML structure is a fixed CBS schema; declarative mapping fits |
| BCF claim aggregation (compensable VAT YTD) | Declarative (`x-openregister-aggregations`) | Standard projection-filter aggregation |
| KOR omzetdrempel YTD aggregation | Declarative if engine supports period-filter; otherwise thin PHP guard (~30 LOC) per ADR-031 exception | Resolution in `opsx-ff` discovery |
| KOR regime auto-switch on threshold crossing | Declarative (`x-openregister-lifecycle` triggered by aggregation calculation reaching threshold) | Chain of `calculation → lifecycle.requires → state transition → notification` is fully declarative |
| Urencriterium running-total | Same as KOR — declarative if supported; thin guard otherwise | Same resolution path |
| ZZP deduction computation | Declarative (`x-openregister-calculations`) | Pure derivation from urencriterium status + GL revenue |
| Schatkist daily position aggregation | Declarative (`x-openregister-aggregations`) | Period-bounded aggregation over flagged accounts |
| Subsidie lifecycle (5 main states + optional sub-state) | Declarative (`x-openregister-lifecycle`) | Textbook state machine |
| Terugvordering settlement plan | Declarative (sub-state + relation to `RepaymentInstallment` register) | Per ADR-022, no parallel state machine; relation handles the per-instalment tracking |
| Archiefwet retention enforcement | Consumed from OR's `x-openregister-lifecycle.retention` | ADR-022; rules seeded, enforcement is OR's |
| Project lifecycle | Declarative (`x-openregister-lifecycle`) | Textbook state machine |
| Project P&L | Declarative (`x-openregister-aggregations`) | Filter GL by project FK; aggregate |
| RJ 270 / IFRS 15 percentage-of-completion | Declarative (`x-openregister-calculations`) | Pure formula on existing fields |
| WIP balance | Declarative (`x-openregister-aggregations` scheduled per period) | Snapshot at period-end via scheduled workflow |
| Utilization | Declarative (`x-openregister-calculations`) | Pure derived field |
| Threshold-warning + approval-due notifications | Declarative (`x-openregister-notifications`) | Standard notification declaration |
| Dashboard widgets (KOR, urencriterium, schatkist, project) | Declarative (`x-openregister-widgets`) | Schema-derived widgets |

No service class authored in this T3 envelope. The two conditional PHP guards (KOR + urencriterium) are explicit ADR-031 exceptions — single method, no state, ≤30 LOC each — and are referenced *by* the lifecycle engine. They are NOT new services.

## RBAC role inventory

T3 introduces or formalises 8 named roles. All are scoped per administration via OR's RBAC (per ADR-022) — no global super-roles.

| Role | Scope | Granted on |
|---|---|---|
| `vat-administrator` | per administration | create/read/update `VatReturn`, `IcpStatement`, `VatCorrection`; trigger `submit` transition (with approval) |
| `bbv-controller` | per administration (gemeente) | create/read/update `BbvAccountMapping`; read `Iv3Export`; trigger `verify` transition |
| `bcf-administrator` | per administration (gemeente) | create/read/update `BcfClaim`; trigger `submit` transition (with approval) |
| `treasury-officer` | per administration (gemeente) | read `SchatkistPosition`; trigger `transferToTreasury` workflow |
| `subsidie-coordinator` | per administration | create/read/update `Subsidie`; trigger `verleen`/`vaststel`/`uitbetaal`/`terugvorder` transitions (each with approval) |
| `project-administrator` | per project | read/update `Project`; create `BillableHour`; trigger `close` transition |
| `archivist` | per administration | read-only on everything; configures retention rule overrides; triggers `archive`/`destroy` transitions via OR's lifecycle |
| `zzp-administrator` | per administration (self) | read/update `UrenRegistratie`, `ZzpDeduction`; trigger `ibAangifteExport` |

These roles are declared in each schema's `x-openregister-rbac` block (or the equivalent OR convention); no app-local RBAC code.

## Seed Data

T3 ships 9 new seed files under `lib/Settings/seeds/`:

| File | Purpose | Approximate row count | Citation |
|---|---|---|---|
| `btw-tariffs-2026.json` | Current BTW rates (21%, 9%, 0%, vrijgesteld, verlegd) with their RGS account hints | ~10 | Wet OB 1968 + Belastingdienst tariefoverzicht |
| `bbv-taakvelden-2024.json` | Complete BBV taakveld catalogue | ~50 | Besluit BBV bijlage IV |
| `rgs-to-bbv-mapping.json` | Default mapping from RGS 3.5 account to BBV taakveld + BCF-compensable flag | ~150 | Commissie BBV handreiking + IV3 specificaties |
| `selectielijst-gemeenten-2020.json` | Selectielijst-2020 retention rules per record-type | ~30 | Archiefwet 1995 art. 5 + Selectielijst-2020 publicatie |
| `kor-thresholds-2026.json` | KOR omzetdrempel (€20.000) + warning percentage (80%) | 1 | Wet OB 1968 art. 25 lid 1 |
| `urencriterium-thresholds.json` | 1225-uren-per-jaar (full) + 800-uren (starters opvolgers) | 2 | Wet IB 2001 art. 3.6 |
| `asv-model-lifecycle.json` | ASV-model subsidie lifecycle states + their Awb article references | 7 | Awb 4.2 + VNG ASV-model 2022 |
| `rj-270-stages.json` | Percentage-of-completion stage definitions + transition triggers | 4 | RJ 270 §3 + IFRS 15 §B14-B19 |
| `rate-card-templates.json` | Default rate-card structure (junior / medior / senior / partner) | 4 | Conduction internal default; operator overrides |

Each seed file's top of file carries:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per feedback_spdx-in-docblock.md.
- An `_meta` block (e.g. `{ "_meta": { "source": "Wet OB 1968", "version": "2026-01-01", "imported": "<iso-timestamp>" } }`) so future revisions can identify which records were template-sourced versus operator-authored.

All seed data is loaded via `ConfigurationService::importFromApp()` in the repair step. Per-administration override is allowed for every seed except `urencriterium-thresholds.json` (statutory) and `btw-tariffs-2026.json` (statutory — operator can add rates not override the canonical Belastingdienst rates).

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| BBV / IV3 specifications revise mid-implementation | Versioned seed filenames (`bbv-taakvelden-2024.json` → `bbv-taakvelden-2026.json`); coexistence trivial; operator-editable. |
| OR engine can't aggregate across periods declaratively | ADR-031 §"PHP guards remain a legitimate seam" — thin single-method guards for KOR + urencriterium. Documented exception. |
| SBR/Digipoort certificate custody | Out of shillinq scope — operator-managed via OpenConnector source config. Security review confirms no PKI material in shillinq's `secrets/`. |
| Selectielijst retention conflicts with operator's local archiefverordening | Per-administration override on `RetentionRule` records; operator's local rule wins. Each override carries an audit citation of the local archiefverordening. |
| Subsidie terugvordering edge case (afbetalingsregeling) | Sub-state on `Subsidie` + `RepaymentInstallment` register. Reviewed with subsidie-administrateur persona. |
| Consultancy multi-rate boundary (rate-card revision mid-project) | RJ 270 §3.2.4 governs: hours logged at rate-as-of-performance-date. `RateCard` carries `effectiveFrom` / `effectiveTo`; `BillableHour.recognisedRate` snapshots at write time. |
| Project lifecycle vs subsidie lifecycle confusion | Two separate registers, two separate lifecycles, two separate notifications. Cross-reference via FK only — no shared state. |
| KOR opt-out mid-year on threshold crossing has VAT impact | The KOR lifecycle's `threshold-exceeded → opted-out` transition emits a notification and a `JournalEntry` template that the operator + accountant review. Spec requires the journal entry MUST NOT auto-post — operator approval required. |
| ZZP urencriterium false positive (ziekte / zwangerschapsverlof) | The `UrenRegistratie` schema carries an `excludedReason` enum (`sick`, `parental-leave`, `vacation`, `non-billable-admin`) per Wet IB 2001 — those hours don't count toward the 1225, but the operator can mark them. Documented in the spec. |
| Tier-cascade — T3 specs assume T2's shapes stable | T2 is being written in parallel; T3's `opsx-apply` cycle gates on T2 specs at `Status: approved`. Small adjustment surface (FK references). |
| Archiefwet retention triggers may anonymise audit trail | OR's retention engine MUST preserve audit-trail-immutable hashes even when source records are anonymised — this is an OR contract, not a shillinq concern. T3 documents the expectation in the retention spec. |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the 14+ new schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with ~12 new menu entries + index/detail page pairs (additive). Visibility predicates filter by administration type (`gemeente`/`zzp`/`mkb`/`consultancy`).
3. The repair step extends to import the 9 new seed files into their target registers.
4. `ScheduledWorkflow` entries are created for SBR-quarterly, IV3-quarterly, BCF-quarterly, and the daily schatkist-position aggregation.
5. The `BbvAccountMapping` register is seeded with the default `rgs-to-bbv-mapping.json` for newly-installed gemeente administrations only (other administration types skip).
6. The `RetentionRule` register is seeded with `selectielijst-gemeenten-2020.json` for every administration.

Down-direction: registers are non-destructive — disabling the seed import + reverting the manifest leaves stranded but queryable records. No destructive rollback at the spec-acceptance gate.

## Open Questions

1. **Cross-period aggregation shape** — see Risk 3. Resolved in `opsx-ff` discovery before implementing cycle.
2. **BBV-2026 vs BBV-2024 mapping** — track Commissie BBV publication calendar; versioned seed allows coexistence.
3. **SBR/Digipoort PKI custody** — confirmed operator-managed via OpenConnector source config (no PKI material in shillinq).
4. **IV3 monthly vs quarterly** — currently quarterly per CBS; operator-configurable cron in the `ScheduledWorkflow`.
5. **Subsidie afbetalingsregeling shape** — sub-state on `Subsidie` with `RepaymentInstallment` FK. Confirm with subsidie-administrateur persona during spec review.
6. **Multi-rate project boundary** — RJ 270 §3.2.4 applied; `BillableHour.recognisedRate` snapshots at write. Confirm with project-administrator persona during spec review.
7. **KOR opt-out auto-posting** — spec forbids; operator approval gates. Confirm with accountant persona.
