# Design: ledger-multi-administration

Read at shillinq development `79f438f33` and nextcloud-vue development on
2026-09-27.

## Context

- **Switch.** `src/components/AdministratieSwitcher.vue` posts `/api/administrations/switch` and reloads. `AdministrationController::switch()` (`lib/Controller/AdministrationController.php`, from line 117) validates membership through `AdministrationContextService::resolveSwitchTarget()` and returns the target; it stores nothing.
- **Context.** `AdministrationContextService::buildContext()` (from line 165) lists the user's `AdministrationMembership` rows and sets `activeAdministrationId` to the first one (line ~181). `BudgetGrid.vue:284`, `SegmentPnLDashboard.vue`, `PurchaseOrderForm.vue`, `GoodsReceiptNoteForm.vue` and `SpendAnalyticsPanel.vue` read `activeAdministrationId` from the context, so they follow whatever it returns.
- **Lists.** Generic index pages in `src/manifest.json` (for example `GeneralLedger` at line 4169) have no `administrationId` filter. Some configs carry `defaultFilters` (line 1974), with static values only. nextcloud-vue's `CnPageRenderer` resolves `@route.<param>` sentinels in page config (`CnPageRenderer.vue:1132`) and has no other runtime value.
- **Templates.** `SettingsService::seedRgsTemplate()` (`lib/Service/SettingsService.php:175`) seeds accounts from shipped files (MKB, ZZP, BBV) during the setup wizard. There is no office template.
- **Schemas.** `Administration`, `AdministrationMembership`, `AdministrationBackupRun`, `AdministrationMigration`, `IntercompanyJournalEntry` live in `lib/Settings/register.d/bookkeeping-multi-administratie.json`. Cost centres are `AnalyticalDimension` records with a `dimensionType` (`register.d/bookkeeping-cost-centers-dimensions.json`). There is no daybook schema.

## Goals / Non-Goals

**Goals**
- The chosen administration survives a reload and a new session.
- Lists of administration-owned records show the active administration only.
- An office saves an administration as a template and starts clients from it.
- A template change reaches the administrations that follow it.

**Non-Goals**
- Daybooks, deleting accounts through a sync, cross-administration reporting.

## Decisions

### D1. The active administration is a user preference

`switch()` writes `IConfig::setUserValue($uid, 'shillinq',
'activeAdministrationId', $id)` after the membership check.
`buildContext()` reads it and returns it when it is still among the user's
memberships, else the first membership. No new schema.

Alternative considered: a session value. Rejected: the competitors keep the
choice across sessions, and a session value is lost on every re-login.

### D2. Lists scope through a context sentinel

Index pages over schemas that carry `administrationId` get
`defaultFilters: {"administrationId": "@context.activeAdministrationId"}`.
The sentinel needs `CnPageRenderer` to resolve a host-provided context
object, which is the nextcloud-vue dependency named in the proposal. Until
it lands, `src/views/AdministrationScopedIndex.vue` wraps `CnIndexPage` and
passes the filter from the context endpoint; the manifest pages switch to
the sentinel when nextcloud-vue ships it. The list of pages is generated
from the schemas that declare `administrationId`, not typed by hand.

### D3. A template is a snapshot record

New schema `AdministrationTemplate`: `name`, `sourceAdministrationId`,
`accounts` (array of account records without ids or balances),
`dimensions` (cost centres and other `AnalyticalDimension` records),
`version`, `lifecycleState`. "Save as template" on the administration
detail page writes one from the current administration. The setup wizard's
template step lists office templates beside the shipped RGS variants, and
`SettingsService` seeds from either.

### D4. Followers receive template changes

`Administration` gains `followsTemplateId` and `followedTemplateVersion`.
When a template is saved with a higher `version`, an object-event listener
queues a background job (ADR-069, `lib/BackgroundJob/`) that adds new
accounts and dimensions to every follower and updates changed names,
types and descriptions. An account a follower edited itself carries
`deviatesFromTemplate: true` and is skipped. The job records per follower
what it added, updated and skipped on an `AdministrationMigration` record,
which already exists for this kind of history.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Active administration | Imperative, in the existing controller and service | A user preference is not an object. |
| List scoping | Declarative: manifest `defaultFilters` with a context sentinel | Page configuration. |
| Template snapshot and seeding | Imperative, in `SettingsService` | Copying across administrations needs code; it extends the existing seeder. |
| Follower sync | Imperative, a queued job triggered by the template's object event (ADR-078) | Bulk work over many administrations; not a derived field. |

## Seed Data

Accountantskantoor De Boer keeps three clients:

- Template "Kantoor De Boer MKB", version 1, from Bakkerij Jansen: RGS MKB accounts plus 4150 Uitzendkrachten and 8050 Omzet catering, cost centres Winkel and Bakkerij.
- Bakkerij Jansen and Kapsalon Mooi follow it; Kapsalon Mooi renamed 8050 to "Omzet producten" and marks it deviating.
- Version 2 adds 4160 Scholingskosten: both followers receive it, Kapsalon Mooi keeps its 8050 name.

## Risks / Trade-offs

- [A template carrying a client's own account names leaks between clients] → only the chart and dimensions are copied, never postings, customers or balances.
- [The wrapper and the sentinel diverge] → the wrapper is removed in the same PR that switches the pages to the sentinel.

## Migration Plan

No data migration. Users land on their first membership once, then on the
last one they chose.

## Open Questions

None.
