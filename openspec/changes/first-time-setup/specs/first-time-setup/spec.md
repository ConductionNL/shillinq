# first-time-setup Specification

**Status:** proposed
**Scope:** shillinq
**Tier:** V1
**Depends on:** the abstract setup wizard (hydra ADR-04x, `@conduction/nextcloud-vue` `CnSetupWizard` + manifest `setup` block + CnAppRoot `setup` phase). Written FIRST as a requirements source for that central change.

## Purpose

Give shillinq a guided, gating first-time setup: the operator MUST choose the active Administration, the legal **region/administrationType**, and the RGS chart-of-accounts template before the app is usable, after which an admin can seed the chart of accounts and region-specific reference data from the UI. Region/administration drive every region-keyed object (BBV taakvelden, Selectielijst retention, scheduled workflows), and the existing C2 constraint already blocks seeding without `administration_id`; the wizard makes these required choices explicit and gating, and runs the seed server-side with system privileges (the browser cannot, due to OpenRegister RBAC).

## ADDED Requirements

### Requirement: REQ-SETUP-SHI-001 — Region And Administration Are Required, Gating Steps

shillinq SHALL declare a `setup` block whose `administration` (writes `administration_id`), `region` (writes `legal_region` / `administration_type`) and `rgs-template` (writes `rgs_template`) steps are `required: true`, so the abstract `CnSetupWizard` gates the app until all three are set.

#### Scenario: App is gated until region and administration are chosen

- **GIVEN** shillinq is enabled with no `administration_id` / `legal_region` / `rgs_template`
- **WHEN** an admin opens the app
- **THEN** `CnSetupWizard` SHALL gate the shell and SHALL NOT allow the `seed` step until all three required steps report done
- **AND** the app's normal navigation SHALL NOT be reachable

#### Scenario: Region choice is explicit and checkable

- **GIVEN** the `region` step
- **WHEN** the admin selects gemeente / provincie / waterschap / zzp / mkb
- **THEN** shillinq SHALL persist the choice to the `legal_region` app-config key
- **AND** setup status SHALL report `region.done` true

### Requirement: REQ-SETUP-SHI-002 — Seeding Is Server-Side, Privileged, And C2-Gated

shillinq SHALL run chart-of-accounts and region-specific seeding via `POST /apps/shillinq/api/setup/action/seed` (admin-only, CSRF) **server-side with system privileges**, and SHALL reject the action with HTTP 422 while any required step (`administration` / `region` / `rgs-template`) is unmet, enforcing the C2 "no tenant data without administration_id" constraint at the server, not only in the UI.

#### Scenario: Seed runs only after required choices

- **GIVEN** `administration_id`, `legal_region` and `rgs_template` are all set
- **WHEN** the wizard POSTs `setup/action/seed`
- **THEN** the server SHALL seed the chart of accounts + region-specific BBV/Selectielijst data + scheduled workflows for the active administration
- **AND** the call SHALL NOT fail with an OpenRegister RBAC create-permission error

#### Scenario: Seed is rejected before required choices

- **GIVEN** `administration_id` is not set
- **WHEN** any caller POSTs `setup/action/seed`
- **THEN** the server SHALL respond 422 and seed nothing

### Requirement: REQ-SETUP-SHI-003 — Setup Status Reports Required And Optional Steps

shillinq SHALL expose `GET /apps/shillinq/api/setup/status` returning `{ version, completed, steps }` where each required step's `done` reflects its config key being set and `seed.done` reflects a chart of accounts existing for the active administration; `completed` SHALL be true only when every required step is done.

#### Scenario: Completion flag set after required steps

- **GIVEN** all required steps report done
- **WHEN** the wizard re-queries status
- **THEN** shillinq SHALL write `setup_completed_version` to app config and `completed` SHALL be true
- **AND** the wizard SHALL stop gating the app
