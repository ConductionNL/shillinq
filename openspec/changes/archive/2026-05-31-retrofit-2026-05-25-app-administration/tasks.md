# Tasks — retrofit-2026-05-25-app-administration

> Reverse-spec: code already exists. Every task is `[x]`; the work is
> capturing observed behavior and annotating the covered methods with
> `@spec` references (ADR-003). No runtime code is modified.

## Tasks

- [x] Task 1: Capture settings read/write behavior — annotate
  `SettingsController::index`, `SettingsController::create`,
  `SettingsService::getSettings`, `SettingsService::updateSettings`, and
  the frontend `settings.js::fetchSettings` / `settings.js::saveSettings`
  and `Settings.vue::save` against REQ-Admin-001.
- [x] Task 2: Capture forced configuration re-import — annotate
  `SettingsController::load` and `SettingsService::loadConfiguration`
  against REQ-Admin-002.
- [x] Task 3: Capture the public health endpoint — annotate
  `HealthController::index` against REQ-Admin-003.
- [x] Task 4: Capture the admin-only metrics endpoint — annotate
  `MetricsController::index` against REQ-Admin-004.
- [x] Task 5: Capture the generic OpenRegister object store — annotate
  `object.js::configure`, `object.js::registerObjectType`,
  `object.js::fetchObjects`, and `AdminRoot.vue::created` against
  REQ-Admin-005.

## Verification

`openspec validate` exits clean on the change folder. Gate-16
spec-coverage reports 0 uncovered methods after annotation.
