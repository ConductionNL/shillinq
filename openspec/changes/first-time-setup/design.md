# Design — shillinq first-time setup

## Steps (manifest `setup.steps[]`)

| id | type | required | what it does |
|----|------|----------|--------------|
| `welcome` | `info` | no | intro |
| `administration` | `config-fields` | **yes** | pick/create the active Administration → writes `administration_id` (HARD C2 gate) |
| `region` | `choice` | **yes** | legal region / administrationType: gemeente / provincie / waterschap / zzp / mkb → writes `legal_region` (+ `administration_type`) |
| `rgs-template` | `choice` | **yes** | chart-of-accounts template: mkb / zzp / bbv |
| `seed` | `run-action` (`action: seed`) | no | seed chart of accounts + region-specific BBV/Selectielijst + scheduled workflows |
| `done` | `summary` | no | health recap + links |

Three REQUIRED steps gate the shell (CnAppRoot `setup` phase). This is the strongest validation case for the central `required` flag: until `administration_id` + `legal_region` + `rgs_template` are set, the app is gated and `seed` is disabled.

## Why region must be an explicit choice

Today region is inferred from `Administration.administrationType` during seeding, and the C2 constraint blocks seeding when `administration_id` is unset. The wizard promotes both to explicit, checkable steps so: (a) the operator chooses region before any region-keyed data is created, and (b) the gate is visible rather than a silent no-op.

## Server-side contract

- `GET /apps/shillinq/api/setup/status` → `{ version, completed, steps }`. Required-step done-states: `administration.done` = `administration_id` set; `region.done` = `legal_region` set; `rgs-template.done` = `rgs_template` set. `seed.done` = chart of accounts exists for the active administration.
- `POST /apps/shillinq/api/setup/action/{actionId}` (admin-only, CSRF). `seed` runs the `InitializeSettings` seeding for the chosen administration/region/template, server-side privileged. `seed` is REJECTED (422) while any required step is unmet (enforces C2 server-side, not just in the UI).

## Reuse / not rebuild

- `administration` + `rgs-template` reuse the existing admin Settings fields (`SettingsService` `CONFIG_KEYS`: `register`, `rgs_template`, `administration_id`).
- `seed` reuses `lib/Repair/InitializeSettings.php` seeding helpers (chart of accounts, BBV mappings, Selectielijst, scheduled workflows).
- Wizard chrome / gating / admin entry come from the central `CnSetupWizard`.

## Requirements this surfaces for the central feature

- Multiple `required` steps that ALL gate (ordered).
- `choice` step bound to an app-config key with static options.
- Server-side enforcement that a `run-action` can be REJECTED until prerequisite required steps are done (not just disabled in the UI).
