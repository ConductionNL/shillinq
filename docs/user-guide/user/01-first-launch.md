---
sidebar_position: 1
title: Open Shillinq for the first time
description: Open Shillinq, find your way around the dashboard, and confirm the OpenRegister back end is connected.
---

# Open Shillinq for the first time

A first look at Shillinq, where the app lives, what the dashboard tells you, and how to tell it is wired up to OpenRegister so the books, invoices, bills, purchase orders, and contracts can load.

## Goal

By the end you will have opened the Shillinq app, recognised the dashboard widgets, and confirmed that the OpenRegister back end is connected.

## Prerequisites

- A Nextcloud account on an instance where the **Shillinq** app is installed and enabled.
- The **OpenRegister** app installed and enabled, Shillinq stores everything (invoices, bills, purchase orders, contracts, journal entries) in OpenRegister, so it is a hard dependency.
- The Shillinq register and its schemas imported. An admin runs this once from **Settings → Administration → Shillinq** (see [Manage Shillinq settings](../admin/03-admin-settings.md)).

## Steps

1. Open the Nextcloud app menu in the top bar and pick **Shillinq**. You land on the dashboard.

   ![Shillinq dashboard](/screenshots/user-guide/user/01-first-launch-01.png)

2. Read the dashboard tiles, *Open items*, *Due this week*, *Completed*, *Team members*. On a fresh install they read sample numbers; they will switch to real counts once the schemas are imported and the first invoices, bills, and contracts flow through.

   ![Dashboard stat tiles](/screenshots/user-guide/user/01-first-launch-02.png)

3. Open the navigation. Shillinq's navigation is driven by the app manifest, **Dashboard** at the top, **Settings** at the bottom. As feature areas land (sales invoicing, accounts payable, procurement, contracts, banking, VAT, reporting) they will appear here as additional menu entries.

   ![Shillinq navigation](/screenshots/user-guide/user/01-first-launch-03.png)

4. Confirm the OpenRegister link. Go to **Settings** (the gear in the navigation footer). The page lists the version block, the register configuration, and a save endpoint. If the register field is empty, the link isn't set yet, fix that next (see [Manage Shillinq settings](../admin/03-admin-settings.md)).

   ![Settings page with OpenRegister link](/screenshots/user-guide/user/01-first-launch-04.png)

## Verification

You are set up correctly when: the Shillinq dashboard renders without an error banner, the navigation lists **Dashboard** and **Settings**, the settings page shows a non-empty **Register** value, and OpenRegister is reachable.

## Common issues

| Symptom | Fix |
|---|---|
| "OpenRegister is not installed or enabled" banner | Install and enable the OpenRegister app, then reload Shillinq. |
| Dashboard tiles still show sample counts | The first invoices/bills/etc. haven't flowed through yet, or the schemas aren't mapped, see the settings page. |
| Shillinq is missing from the app menu | The app is not enabled for your account, ask an administrator to enable it (and check it is not restricted to a group you are not in). |
| Screenshots may be missing | App not yet installed in the test environment; rerun `npm run test:e2e:docs` once it is. |

## Reference

- [Send your first invoice](02-send-invoice.md), the most common first action once the app loads.
- [Manage Shillinq settings](../admin/03-admin-settings.md), register configuration, version info, where Shillinq finds its data.
- [Shillinq architecture overview](../../Technical/architecture.md), standards, feature domains, ADRs.
