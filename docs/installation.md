---
sidebar_position: 2
title: Installation
description: Install Shillinq from the Nextcloud app store, point it at your OpenRegister instance, and set up the chart of accounts.
---

# Installation

This guide walks you through installing Shillinq on a Nextcloud instance and getting the first register, chart of accounts, and approval chain in place.

## Prerequisites

### System

- Nextcloud 28 or higher (30+ recommended).
- PHP 8.1 or higher (8.2+ recommended).
- A database: PostgreSQL 12+, MariaDB 10.5+, or MySQL 8.0+.
- 2 GB RAM minimum, 4 GB+ recommended.
- 1 GB free disk space for the app and its document attachments.

### Required PHP extensions

```bash
# Core extensions
php-curl
php-gd
php-json
php-mbstring
php-xml
php-zip

# Database extensions (choose one)
php-pgsql    # For PostgreSQL (recommended)
php-mysql    # For MariaDB / MySQL
```

### Required Nextcloud apps

- **Open Register** (the data backbone). Install it first.

Optional but recommended:

- **DocuDesk** for invoice and contract document generation.
- **OpenConnector** if you sync counterparties from an external CRM.
- **Nextcloud Mail / Talk** if you want to send invoices or remind suppliers from the workspace.

## Installation methods

### Method 1: Nextcloud app store (recommended)

1. Log in as an administrator.
2. Open **Settings → Apps**.
3. Search for **Shillinq**.
4. Click **Install** on the Shillinq card.
5. Confirm the dependency on Open Register (it installs automatically if it is not yet present).
6. Shillinq appears in the top navigation within seconds.

### Method 2: Manual install (development)

For development environments or air-gapped deployments, clone the repository directly:

```bash
cd nextcloud/apps
git clone https://codeberg.org/Conduction/shillinq.git
cd shillinq
composer install --no-dev
npm install
npm run build
```

Then enable the app:

```bash
docker exec --user www-data nextcloud php occ app:enable shillinq
```

## Initial configuration

After install, the first three steps unlock the full workspace.

### Step 1: Connect a register

1. Open **Shillinq → Settings** from the top navigation.
2. Pick (or create) an Open Register register named `shillinq`. The default schema set ships with Shillinq.
3. Click **Save**. Shillinq verifies the schemas are reachable.

### Step 2: Set up the chart of accounts

1. Open **Settings → Chart of accounts**.
2. Import the **RGS** (Referentie Grootboekschema) starter, or upload your own CSV.
3. Confirm the default VAT rates (BTW 21%, 9%, 0%, vrijgesteld) are present.
4. Enter the opening balances per account.

The detailed walkthrough lives in the [chart-of-accounts guide](user-guide/admin/01-chart-of-accounts.md).

### Step 3: Configure supplier approval chains (optional)

If your organisation routes supplier bills through approval bands, define them now:

1. Open **Settings → Approval chains**.
2. Add the amount bands (for example: under €1,000, €1,000–€10,000, over €10,000).
3. Assign approvers per band.
4. Test the chain with a draft bill.

See the [approval-chains guide](user-guide/admin/02-approval-chains.md) for the full setup.

## Next steps

- Send your first invoice: [Send your first invoice](user-guide/user/02-send-invoice.md).
- Record a supplier bill: [Record a supplier bill](user-guide/user/03-record-bill.md).
- Browse the [Features](Features/index.md) overview to see what else Shillinq does.
- Open an issue at [codeberg.org/Conduction/shillinq/issues](https://codeberg.org/Conduction/shillinq/issues) if you run into trouble.

## Troubleshooting

- **App store does not show Shillinq**: confirm your Nextcloud version is 28+ and that the **Featured apps** category is enabled.
- **"Register not reachable" after Step 1**: confirm Open Register is enabled and the register slug matches. Run `docker exec --user www-data nextcloud php occ openregister:list` to see the registered slugs.
- **VAT rates missing**: re-import the RGS starter from Step 2, or run `docker exec --user www-data nextcloud php occ shillinq:seed:vat`.
- **Schemas not loading**: clear OPcache (`docker exec nextcloud apache2ctl graceful`) and reload.
