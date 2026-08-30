---
sidebar_position: 8
title: Digital compliance (e-invoicing, SAF-T, VAT)
description: The additive bookkeeping-compliance layer — EN 16931, Peppol, ViDA, per-country e-invoicing mandates, SAF-T, and EU VAT.
---

# Digital compliance — e-invoicing, SAF-T & VAT

Modern bookkeeping compliance is no longer only *which GAAP* you report under. It
is increasingly defined by **structured e-invoicing, real-time digital reporting,
and tax-data exports**. These are a **different kind of obligation** from a basis
of accounting:

> A basis of accounting is a **choice** — when several apply, one wins (that's the
> [Standards policy](./index.md#how-shillinq-uses-this-the-standards-policy)).
> A digital-compliance obligation is **additive** — every one that applies to your
> jurisdiction must be met. So these are not ranked; they are tracked per
> jurisdiction with a status and an effective date.

:::caution Verified June 2026 — fast-moving
Mandates and dates here move quarterly. The decisive EU event is **ViDA**, adopted
11 March 2025. Drive country dates from a maintained source rather than hardcoding;
confirm against the European Commission eInvoicing country pages before relying.
:::

## EN 16931 — the European e-invoice standard

| Standard | Issuer | What it governs | Status | Source |
|---|---|---|---|---|
| **EN 16931** | CEN (TC 434) | The semantic data model + syntax bindings (UBL, CII) defining a *compliant* structured invoice — the legal anchor for all EU e-invoicing | Live & foundational; revised version approved Oct 2025 extending the B2G model to B2B for ViDA | [EC eInvoicing — EN 16931 registry](https://ec.europa.eu/digital-building-blocks/sites/spaces/DIGITAL/pages/467108974/Registry+of+supporting+artefacts+to+implement+EN16931) |

Every EU mandate below is a **profile (CIUS)** of EN 16931.

## Peppol

| Standard | Issuer | What it governs | Status | Source |
|---|---|---|---|---|
| **Peppol BIS Billing 3.0** | OpenPeppol | The 4-corner delivery network + a CIUS of EN 16931 (UBL 2.1) for cross-border e-invoicing/e-procurement | Live; the de-facto EU delivery rail (EU PINT Billing published Oct 2025) | [docs.peppol.eu — BIS Billing 3.0](https://docs.peppol.eu/poacc/billing/3.0/bis/) |

## ViDA — VAT in the Digital Age

| Package | Issuer | What it mandates | Status & timeline | Source |
|---|---|---|---|---|
| **ViDA** | EU (Dir/Reg amending 2006/112/EC) | Structured e-invoicing + real-time **Digital Reporting Requirements (DRR)** for intra-EU trade | **Adopted 11 Mar 2025**, in force Apr 2025. Member states may mandate domestic e-invoicing now; **mandatory intra-EU e-invoicing + real-time reporting from 1 Jul 2030**; legacy domestic regimes align by **1 Jan 2035** | [Sovos — ViDA timeline](https://sovos.com/blog/vat/vida-timeline/) (cross-check EUR-Lex OJ 25 Mar 2025) |

## Per-country e-invoicing mandates (status)

These are the **live / upcoming binding obligations** an EU seller faces. Track
each with a status flag — they are not interchangeable.

| Country | Regime | Status (2026) | Source |
|---|---|---|---|
| 🇮🇹 Italy | SdI / FatturaPA | **Live since 2019** (derogation to 31 Dec 2027) | [EC eInvoicing — Italy](https://ec.europa.eu/digital-building-blocks/sites/spaces/DIGITAL/pages/467108890/eInvoicing+in+Italy) |
| 🇵🇱 Poland | KSeF | Mandatory large taxpayers **1 Feb 2026**, most others **1 Apr 2026** | [Fiskaly — EU 2026 roadmap](https://www.fiskaly.com/blog/e-invoicing-mandates-in-europe-2026) |
| 🇪🇸 Spain | Verifactu | Certified software mandatory **1 Jul 2026**; B2B (Crea y Crece) pending | [Fiskaly](https://www.fiskaly.com/blog/e-invoicing-mandates-in-europe-2026) |
| 🇫🇷 France | Réforme facturation | Receive + large/mid issue from **1 Sep 2026**, SMEs **1 Sep 2027** | [Fiskaly](https://www.fiskaly.com/blog/e-invoicing-mandates-in-europe-2026) |
| 🇩🇪 Germany | B2B e-invoicing | Receive since Jan 2025; **issue mandatory Jan 2027** (>€800k) / Jan 2028 (rest) | [Fiskaly](https://www.fiskaly.com/blog/e-invoicing-mandates-in-europe-2026) |
| 🇧🇪 Belgium | B2B via Peppol | Mandatory from **Jan 2026** | [Fiskaly](https://www.fiskaly.com/blog/e-invoicing-mandates-in-europe-2026) |

## SAF-T — Standard Audit File for Tax

| Standard | Issuer | What it governs | Status | Source |
|---|---|---|---|---|
| **SAF-T** | OECD | Harmonised XML schema exporting ledgers/transactions to tax authorities for audit — the **bookkeeping-export** side of compliance | Mandatory/required in PT, PL, FR, ES, NO, LT, LU, AT, RO; PT annual SAF-T from FY2025; PL JPK_KR_PD/JPK_ST_KR first filings due 31 Mar 2026 | [VATupdate — SAF-T overview](https://www.vatupdate.com/2025/10/15/updated-overview-of-countries-implementing-oecds-saf-t/) |

## EU VAT

| Framework | Issuer | What it governs | Source |
|---|---|---|---|
| **VAT Directive 2006/112/EC** (+ OSS / IOSS) | EU Council | The core VAT legal framework: rates, place-of-supply, invoicing content, returns; OSS/IOSS single-registration for cross-border/e-commerce | [EUR-Lex 2006/112/EC](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32006L0112) |

## United States (for contrast)

The US has **no federal e-invoicing mandate**. A Peppol-equivalent network,
**DBNAlliance**, is live but **voluntary**. The binding US obligations remain
[US GAAP](./us-gaap.md) (or a [tax/cash basis](./us-gaap.md#special-purpose-frameworks-ocboa))
plus state **sales-tax** rules — there is no SAF-T/ViDA equivalent to track yet.

| Standard | Issuer | Status | Source |
|---|---|---|---|
| DBNAlliance (Peppol-style exchange) | DBNAlliance | Live but **voluntary** | [EDICOM — US DBNAlliance](https://edicomgroup.com/blog/the-united-states-begins-an-electronic-invoicing-pilot-project) |

## How Shillinq models this

These obligations are **facts about the world**, not a per-tenant choice — EN
16931, ViDA's dates, "Poland KSeF from 2026" are identical for everyone and change
only when regulation changes. So they are **not** stored as OpenRegister config;
they ship as a **versioned static catalogue in code** (`ComplianceCatalogue`,
stamped with a `VERSION`/`asOf`) that updates with releases. Business logic reads
it **additively** — meet every obligation that applies — and it is the
machine-readable source for turning compliance rules into specs.

The only genuinely per-tenant input is **which jurisdictions an administration
operates in**, which is derived from data the app already holds (administration /
customer countries) and passed to `ComplianceCatalogue::applicableTo(country)` —
no separate per-tenant schema is needed.

> Contrast with the [Standards policy](./index.md#how-shillinq-uses-this-the-standards-policy):
> that *is* a per-tenant choice (which basis of accounting, in what order) → an
> OpenRegister object you edit and rank. Compliance obligations are a given → static
> versioned code.

## Sources

- [EC eInvoicing — EN 16931 registry](https://ec.europa.eu/digital-building-blocks/sites/spaces/DIGITAL/pages/467108974/Registry+of+supporting+artefacts+to+implement+EN16931)
- [Peppol BIS Billing 3.0](https://docs.peppol.eu/poacc/billing/3.0/bis/)
- [Sovos — ViDA timeline](https://sovos.com/blog/vat/vida-timeline/)
- [Fiskaly — EU e-invoicing 2026 roadmap](https://www.fiskaly.com/blog/e-invoicing-mandates-in-europe-2026)
- [VATupdate — SAF-T overview](https://www.vatupdate.com/2025/10/15/updated-overview-of-countries-implementing-oecds-saf-t/)
- [EUR-Lex — VAT Directive 2006/112/EC](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32006L0112)
