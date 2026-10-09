# Proposal: adopt-connection-registry

## Why

Shillinq's External Connections page works out every connection's status on
its own. `ExternalAdaptersAdminController` holds a roster of fifteen adapter
families, asks each bound adapter whether it is dormant, looks up a source by
slug in integriq's register, and a custom Vue page renders the answer. No
other app can read that answer, and integriq cannot check any of it.

Integriq now owns a registry for exactly this (hydra
`openspec/changes/connection-registry`, merged as hydra#667 and
integriq#1996). An app declares its connections in one static file, integriq
keeps one row per connection and decides its status, and every app shows its
own rows on the same page. Dossiq adopted it first (dossiq#2715). Shillinq is
next (shillinq#1602).

## What changes

- New `lib/Settings/connections.json` with the fifteen family keys the roster
  already uses, in the roster's order.
- Twelve families are declared not available. Each has only a log-only
  adapter, and no screen or service in shillinq calls it.
- Three families are called today and answer through a log-only adapter:
  Mollie payments, deposit payments and treasury rates. Shillinq reports what
  it sees for them once a day with `ConnectionStatusReportedEvent`, so those
  rows read Simulated while the log-only adapter answers.
- The External Connections page becomes an `index` page over
  `integriq/app_connection`, preset to `app=shillinq` through its menu entry.
  It keeps its menu entry, its place in the settings foldout and its route.
- Add integration leaves for integriq's Connections overview with
  `app=shillinq&link=1`.
- The roster is removed: `ExternalAdaptersAdminController`, its route, the
  `ExternalAdaptersStatus.vue` page and the tests and e2e specs that only
  served them.

## Supersedes

This change supersedes the status-page half of
`openspec/changes/integration-config-to-openconnector`: REQ-ICO-002 (the
roster page), REQ-ICO-003 (the provisioning lookup) and REQ-ICO-007 (the
roster e2e specs). Integriq links a source to a declared connection now, so
shillinq no longer looks one up by slug.

It does not touch the configuration half. REQ-ICO-001
(`openconnector-sources.json`), REQ-ICO-005 (the unimplemented-integration
declaration) and REQ-ICO-006 (the non-goals) stay as they are. No adapter
interface and no log-only adapter changes.

## Depends on

- hydra `openspec/changes/connection-registry`, design D2 to D10.
- integriq's `app_connection` schema, declaration sync, both events and the
  Connections overview (integriq#1996).

Without integriq the menu entry is hidden, the page shows the
missing-dependency screen, and shillinq sends nothing.

## Out of scope

- Real adapters for any family.
- Moving adapter configuration into integriq sources.
- Changing the contract. Where shillinq's adapter model does not fit it, the
  design says how this change maps it and what amendment would help.

## Rollback

Revert this change. The roster controller, its route and the page come back.
Integriq keeps the rows it synced until shillinq's declaration is gone, then
removes the ones without a linked source.
