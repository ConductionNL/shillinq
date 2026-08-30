---
sidebar_position: 10
title: Output & filing formats
description: Reporting layers on top of a chosen GAAP — ESEF & US-GAAP XBRL, SEC Reg S-X/S-K, and AICPA SSARS engagement modes.
---

# Output & filing formats

These are **not bases of accounting** — they are layers on *top* of a chosen GAAP
that govern how statements are **tagged, filed or produced**. They don't compete
in the [Standards policy](./index.md); they apply once you've chosen a basis.

> Relevant mainly for **listed / SEC** clients and for the **engagement** under
> which financials are issued. Documented here for completeness; deeper tooling is
> deferred until there's a public-company / assurance use case.

## Digital filing taxonomies (XBRL)

| Format | Region | What it governs | Source |
|---|---|---|---|
| **ESEF** (European Single Electronic Format) | EU | iXBRL/xHTML tagging of listed issuers' IFRS consolidated statements; built on the IFRS XBRL taxonomy. 2025 taxonomy mandatory for FY starting on/after 1 Jan 2026 | [ESMA — ESEF](https://www.esma.europa.eu/issuer-disclosure/electronic-reporting) |
| **US-GAAP Financial Reporting Taxonomy** | US | Inline-XBRL tagging required of SEC filers (EDGAR); the US counterpart to ESEF | [XBRL US — 2026 US-GAAP taxonomy](https://xbrl.us/xbrl-taxonomy/2026-us-gaap/) |

## SEC reporting overlay (US public companies)

| Framework | Issuer | What it governs | Source |
|---|---|---|---|
| **Regulation S-X** | SEC | Form & content of financial statements for SEC registrants (17 CFR Part 210) | [sec.gov](https://www.sec.gov/) |
| **Regulation S-K** | SEC | Narrative/non-financial disclosure requirements | [sec.gov](https://www.sec.gov/) |

> SEC Reg S-X/S-K is mid-reform in 2026 (proposed optional semiannual reporting +
> S-X simplification) — confirm specifics before relying.

## Engagement modes (how SMB financials are issued)

| Standard | Issuer | What it governs | Source |
|---|---|---|---|
| **AICPA SSARS** (AR-C 70 / 80 / 90) | AICPA | The three service levels under which non-public financials are produced: **Preparation**, **Compilation**, **Review** — driving report wording & disclaimers | [AICPA — SSARS](https://www.aicpa-cima.com/resources/download/aicpa-ssarss-currently-effective) |

## Sources

- [ESMA — ESEF](https://www.esma.europa.eu/issuer-disclosure/electronic-reporting)
- [XBRL US — 2026 US-GAAP taxonomy](https://xbrl.us/xbrl-taxonomy/2026-us-gaap/)
- [SEC](https://www.sec.gov/)
- [AICPA — SSARS](https://www.aicpa-cima.com/resources/download/aicpa-ssarss-currently-effective)
