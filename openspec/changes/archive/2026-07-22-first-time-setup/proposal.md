# Proposal: first-time-setup

kind: feature — adopts the **abstract first-time setup wizard** (hydra ADR-04x, `@conduction/nextcloud-vue` `CnSetupWizard` + manifest `setup` block) for shillinq. Written FIRST as the per-app requirements source for the central nc-vue change. shillinq is the **canonical block-on-required** case.

## Summary

shillinq is unusable — and unsafe to seed — until the operator makes a few **required choices**, because its chart-of-accounts, BBV/Selectielijst rules and scheduled workflows are all keyed to the administration and its **legal region/type**:

- **administration_id** (the active Administration) — a HARD constraint today (`InitializeSettings` C2): if it is not set, NO tenant-specific data is seeded, to avoid contaminating real tenant data on later reconfiguration.
- **legal region / administrationType** (gemeente / provincie / waterschap / zzp / mkb) — drives which BBV taakvelden, Selectielijst retention rules and compliance reference data apply.
- **RGS chart-of-accounts template** (mkb / zzp / bbv) — selected before seeding the chart of accounts.

Today these are scattered across an admin Settings page + per-administration seeding that only runs on install repair, with no guided flow and the same browser-side OpenRegister RBAC limitation on seeding that procest hit.

This change declares shillinq's setup as a manifest `setup` block whose **region/administration/RGS steps are REQUIRED** (they gate the app via the CnAppRoot `setup` phase), followed by an optional server-side **seed chart-of-accounts** action.

**What changes (shillinq side):**

1. **`manifest.setup`** — steps: `welcome` (info), `administration` (choice/config-fields, REQUIRED — sets `administration_id`), `region` (choice, REQUIRED — legal region/administrationType), `rgs-template` (choice, REQUIRED — mkb/zzp/bbv), `seed` (run-action — chart of accounts + type-specific reference data), `done` (summary + health).
2. **`SetupController`** — `GET /apps/shillinq/api/setup/status` + `POST /apps/shillinq/api/setup/action/{actionId}` (admin-only). The `seed` action runs `InitializeSettings`'s seeding (chart of accounts, BBV/Selectielijst, scheduled workflows) **server-side, privileged**, gated by the now-set `administration_id`.
3. A first-class **`legal_region`** app-config key (today region is only inferred from `administrationType` during seeding — the wizard makes it an explicit, checkable choice).

Depends on / requirements source for `hydra/openspec/changes/manifest-setup-wizard` + `nextcloud-vue` `cn-setup-wizard`.
