---
sidebar_position: 3
title: Manage Shillinq settings
description: Open Shillinq's admin settings, confirm the version, point at the OpenRegister register, and import schemas so every list view loads.
---

# Manage Shillinq settings

Shillinq's settings page tells the app where its data lives, which OpenRegister register the schemas are imported into, and the per-schema configuration that makes invoicing, bills, POs, contracts, banking, VAT, and reporting all line up. The page also reports the installed version so you can confirm the install matches what you expect.

## Goal

By the end you will have confirmed the Shillinq version, set the OpenRegister register, run (or re-run) the schema import, and verified the basic configuration so the rest of the admin sections (chart of accounts, approval chains, supplier records) can be filled in.

## Prerequisites

- Admin on the Nextcloud instance.
- The **OpenRegister** app installed and enabled, with at least one register available.

## Steps

1. Open **Settings → Administration → Shillinq**. The page opens to the **Version Information** section.

   ![Shillinq settings, version section](/screenshots/user-guide/admin/03-admin-settings-01.png)

2. Confirm the **Version**. The card shows the installed Shillinq version and whether it's the latest available, useful if you've just installed or upgraded and want to confirm Nextcloud loaded the right package.

   ![Version card](/screenshots/user-guide/admin/03-admin-settings-02.png)

3. Switch to the **Configuration** section. Set the **Register**, the OpenRegister register UUID the Shillinq schemas live in. On a fresh install this drops down to the auto-imported `shillinq` register; pick a different one if your deployment uses a custom register. Click **Save**.

   ![Register configuration](/screenshots/user-guide/admin/03-admin-settings-03.png)

4. Run / re-run the **schema import**. The settings page exposes a *Reload settings* action that calls `POST /api/settings/load`, it (re-)imports the Shillinq schemas (invoice, bill, purchase-order, contract, bank-account, journal-entry, vat-rate, …) into the configured register. Use this after upgrades or when the lists are showing empty *Add* dialogs.

   ![Schema import action](/screenshots/user-guide/admin/03-admin-settings-04.png)

5. Open the Shillinq app and verify: the dashboard renders without an error banner, the navigation lists the feature areas (as they roll out, invoices, bills, POs, contracts, banking, VAT, reporting), and each list's **Add** dialog opens with real form fields. If any *Add* dialog is empty, the schema for that area didn't import, go back to step 4 and re-run.

   ![Shillinq app loading cleanly](/screenshots/user-guide/admin/03-admin-settings-05.png)

## Verification

The **Version** card shows the installed version with "Up to date" or the available newer version. The **Configuration** section has a non-empty *Register* value. The Shillinq app loads its lists without an error banner, and *Add* dialogs across the app show real form fields.

## Common issues

| Symptom | Fix |
|---|---|
| *Register* dropdown is empty | OpenRegister isn't installed/enabled, or no register has been created yet, install OpenRegister, run the install repair step, then reload Shillinq. |
| Save action does nothing | The save endpoint (`POST /api/settings`) is returning a 4xx; check the Nextcloud log. |
| Schema import doesn't pick up new schemas after an upgrade | Click *Reload settings* explicitly, `InitializeSettings` only runs on the post-migration repair step, which doesn't always trigger on a custom_apps mount. |
| Add Item dialogs are empty across the app | Same root cause, the schemas aren't mapped; re-run the schema import here, everything else follows. |
| Version says "Up to date" but the dashboard layout is wrong | The webpack bundle is stale, graceful-restart Apache (or rebuild and reload). |
| Screenshots may be missing | App not yet installed in the test environment; rerun `npm run test:e2e:docs` once it is. |

## Reference

- [Open Shillinq for the first time](../user/01-first-launch.md), the user-facing check that the import worked.
- [Set up your chart of accounts](01-chart-of-accounts.md), the first big admin task once the register is mapped.
- [Configure supplier approval chains](02-approval-chains.md), the second one.
- [Shillinq architecture overview](../../Technical/architecture.md), the standards Shillinq aligns to and the ADRs that govern config.
