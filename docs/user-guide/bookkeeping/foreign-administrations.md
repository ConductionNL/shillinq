---
sidebar_position: 40
title: Foreign administrations
description: Keep a company's books under another country's rules next to your Dutch companies, starting with Belgium.
---

# Foreign administrations

Every administration in Shillinq has a **jurisdiction**: the country whose rules its books are kept under. A Dutch group with a Belgian subsidiary keeps both companies in the same environment, each under its own rules. Belgium is the first country Shillinq ships rules for.

## What the jurisdiction decides

| | Dutch administration (NL) | Belgian administration (BE) |
|---|---|---|
| Rule checks on posting and invoicing | Dutch, EU and global rules | Belgian, EU and global rules; no Dutch-only rule |
| Chart of accounts template | RGS (MKB, ZZP, BBV) | Belgian minimum chart of accounts (MAR/PCMN), classes 1 to 7 |
| VAT rates | 0, 9 and 21 percent | 0, 6, 12 and 21 percent |
| Dutch VAT return, its SBR submission, ICP return | Available | Refused, with a message naming the jurisdiction |

An administration that names no jurisdiction is Dutch. Administrations that existed before jurisdictions were introduced are given NL automatically on upgrade.

## Creating a Belgian administration

1. In the setup wizard, choose **Belgium** as the legal region and the organisation type.
2. At the chart of accounts step the wizard suggests **Belgian minimum chart (MAR/PCMN)**. Keep it.
3. Create the administration and load the chart of accounts and reference data.

Shillinq then seeds the Belgian chart (for example 400000 Handelsdebiteuren, 440000 Leveranciers, 451000 Te betalen btw and 700000 Omzet; each account's description also gives the French name) and the Belgian VAT rates, and sets the administration's jurisdiction to BE.

The pack holds the legal minimum. Extend the chart like any other chart of accounts.

## The jurisdiction is fixed once you post

You can change an administration's jurisdiction until its first transaction is posted. After that the change is refused with the reason that postings exist: those postings were booked under the old rules.

## What is not included yet

- Belgian filings (Intervat, the periodic VAT return grid). A Belgian administration does not get a Dutch return instead.
- Payroll under another country's rules.
- Countries other than Belgium.
