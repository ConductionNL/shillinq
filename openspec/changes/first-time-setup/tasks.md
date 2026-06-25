# Tasks — shillinq first-time setup

> Blocked on the central change (`nextcloud-vue` `cn-setup-wizard` + manifest `setup` schema). Phase A here is the SPEC only; implementation runs in Phase D.

## Phase 1: Manifest

- [ ] Add a `setup` block to `src/manifest.json`: `welcome`, `administration` (required), `region` (required), `rgs-template` (required), `seed`, `done`; `completionConfigKey: setup_completed_version`.
- [ ] Define the `region` choice options (gemeente / provincie / waterschap / zzp / mkb).

## Phase 2: Config + server-side contract

- [ ] Add a first-class `legal_region` app-config key to `SettingsService` `CONFIG_KEYS`.
- [ ] Add `lib/Controller/SetupController.php` — `status()` (GET) + `runAction()` (POST `/action/{actionId}`), admin-only, CSRF.
- [ ] `status()` reports `administration` / `region` / `rgs-template` (config keys set) and `seed` (chart of accounts exists for active administration).
- [ ] `runAction('seed')` → run `InitializeSettings` seeding for the chosen administration/region/template, server-side privileged; **REJECT with 422 while any required step is unmet** (server-side C2).
- [ ] Register routes in `appinfo/routes.php` with auth attributes.
- [ ] Create `lib/Settings/AdminSettings.php` if still missing (info.xml references it but the class does not yet exist).

## Phase 3: Verify

- [ ] Live: fresh enable → CnAppRoot gates on `administration`; set administration + region + RGS → app usable; run `seed` → chart of accounts + region-specific data seeded server-side (no RBAC error); POST `seed` before required steps → 422.
