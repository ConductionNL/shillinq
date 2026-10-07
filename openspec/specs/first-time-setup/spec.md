# first-time-setup Specification

## Purpose
The setup wizard a new shillinq install walks through before first use. Region and administration are required steps that gate the rest, example data is seeded server-side by a privileged call, each example dataset card loads itself, and the setup status endpoint reports every step the manifest declares, required or optional, so the wizard and the server agree on what is done.

## Requirements

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

### Requirement: Each example data card loads itself

The `demo-data` setup step MUST be a cards choice step with `loadAction: load-demo-data`. The setup wizard MUST NOT carry a separate run-action step that loads the picked dataset.

#### Scenario: The operator loads a dataset from its card

- GIVEN the setup wizard shows the example data cards
- WHEN the operator presses Load on a card
- THEN the wizard posts `{ "dataset": <card value> }` to `/api/setup/action/load-demo-data`
- AND the server loads that dataset
- AND the server records the dataset as the pick only after the load succeeds
- @e2e exclude the card and its spinner are CnSetupWizard UI, tested in nextcloud-vue; the posted body is covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: An unknown dataset is refused

- GIVEN a dataset id that no card offers
- WHEN it is posted to `/api/setup/action/load-demo-data`
- THEN the server answers 400 with `success: false`
- AND nothing is loaded or stored
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts

#### Scenario: A call without a body keeps working

- GIVEN a dataset was stored through `/api/setup/config`
- WHEN `/api/setup/action/load-demo-data` is called without a body
- THEN the stored dataset is loaded
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts

#### Scenario: A failed load leaves the step open

- GIVEN the load of the posted dataset fails
- WHEN the server answers
- THEN the answer carries `success: false`
- AND no pick or decision is stored
- @e2e exclude needs a load that fails on a live instance; covered by tests/Unit/Controller/SetupControllerTest.php

### Requirement: Setup status reports every manifest step

`GET /api/setup/status` MUST report a `done` state for every step id in `manifest.setup.steps`.

#### Scenario: The status ids match the manifest

- GIVEN the Shillinq manifest
- WHEN an administrator reads `/api/setup/status`
- THEN `steps` holds an entry for every manifest step id
- AND the retired load step is not reported
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts
