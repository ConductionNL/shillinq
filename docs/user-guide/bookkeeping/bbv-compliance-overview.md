---
sidebar_position: 0
title: BBV Compliance Overview
description: A high-level introduction to the Besluit Begroting en Verantwoording (BBV) framework — what it is, who it applies to, and how Shillinq's BBV module maps to the statutory book-keeping primitives.
---

# BBV Compliance Overview

The Besluit Begroting en Verantwoording (BBV) is the Dutch
ministerial decree that prescribes how decentrale overheden —
gemeenten, provincies and waterschappen — must structure their
budget (begroting), interim reports and annual accounts
(jaarrekening). Shillinq's BBV module implements the data model,
lifecycle guards, statutory catalogues and exports needed for an
administration to keep its books in conformity with the BBV.

This page is the orientation guide; the surrounding pages drill into
the day-to-day workflows.

## Goal

By the end of this page you will know which entities in Shillinq
implement which BBV concepts, when each becomes mandatory, and where
to find the workflow guide for each one.

## Prerequisites

- Shillinq is installed and enabled, and the active administration's
  `administrationType` is one of `gemeente`, `provincie` or
  `waterschap`. Non-BBV-tenants (e.g. SMB or ZZP administrations)
  bypass every BBV precondition — the module's navigation entries
  are hidden, and `BbvComplianceGuard` short-circuits to `true`.
- The Iv3-informatievoorschrift 2025 stam-data has been imported by
  the post-migration repair step (`BbvSeedService`). This seeds the
  Taakveld, EconomischeCategorie, BeleidsIndicator and BbvAccountMapping
  catalogues — see [the seed task list](./bbv-meerjarenraming.md#seed-data).

## Section 1 — Key terms

The BBV defines a small vocabulary that pervades the rest of the
module:

- **Taakveld** — a statutorily-fixed activity classification (e.g.
  `0.10 Mutaties reserves`, `2.1 Verkeer en vervoer`). Catalogues
  differ per overheidslaag: 53 gemeente-taakvelden, 14 provinciale
  taakvelden, 10–12 waterschap-taakvelden. Stored in the `Taakveld`
  register; the gemeente catalogue is bundled as a representative
  seed, the full annual catalogue is imported via openconnector.
- **Economische categorie** — Iv3 cost-type classification (e.g.
  `1.1 Salarissen en sociale lasten`, `8.1 Belastingopbrengsten`).
  Required next to `taakveld` on every exploitatie posting. Stored
  in the `EconomischeCategorie` register.
- **Programma** — the council-approved programme indeling, one row
  per programmanummer per boekjaar. Carries the doelstellingen
  (wat, wanneer, kpi) and beleidsindicatoren that drive the
  programmaplan narrative.
- **Paragraaf** — one of the seven obligatory paragrafen in a
  jaarrekening (Lokale heffingen, Weerstandsvermogen, Onderhoud
  kapitaalgoederen, Financiering, Bedrijfsvoering, Verbonden
  partijen, Grondbeleid). Stored in the `Paragraaf` register; the
  paragraaf-completeness guard fires on `Jaarrekening.publish`.
- **Meerjarenraming** — multi-year budget over four boekjaren (T,
  T+1, T+2, T+3) per programma × taakveld × economische_categorie.
  Stored in the `MeerjarenBudget` register; the sluitend-check
  fires on `Programma.publish`.
- **Reserve** — eigen vermogen earmark, either `algemeen` or
  `bestemming`. All mutations must book on taakveld `0.10`.
- **Voorziening** — verplichting earmark; categorised under BBV
  art. 44 (a/b/c/d) with an onderbouwingsdocument link.
- **Materiële Vaste Activa (MVA)** — capitalised investments with
  a depreciation schedule. Categories: `economisch-nut`,
  `economisch-nut-heffing`, `maatschappelijk-nut`.
- **Rechtmatigheidsverantwoording** — the statement (from boekjaar
  2024) by which the college of B&W or GS declares whether and to
  what extent the administration spent within statutory bounds.
- **Iv3-aanlevering** — the quarterly + annual CBS submission of
  aggregated baten/lasten per taakveld + economische_categorie.
- **SiSa-bijlage** — annual rapportage of doelsubsidies routed
  through the jaarrekening as a single-information / single-audit
  bijlage.

## Section 2 — Where each lives in Shillinq

| BBV concept | Shillinq entity | Where it lives | Day-to-day guide |
| ----------- | --------------- | -------------- | ---------------- |
| Taakveld | `Taakveld` register | Reference data (seeded) | This page |
| Economische categorie | `EconomischeCategorie` register | Reference data (seeded) | This page |
| RGS-decentraal mapping | `BbvAccountMapping` register | Reference data + per-tenant overrides | [Programmaplan](./bbv-programmaplan.md) |
| Programma | `Programma` register | Programmaplan workspace | [Programmaplan](./bbv-programmaplan.md) |
| Beleidsindicator | `BeleidsIndicator` register | Nested under Programma | [Programmaplan](./bbv-programmaplan.md) |
| Meerjarenraming | `MeerjarenBudget` register | Meerjarenraming workspace | [Meerjarenraming](./bbv-meerjarenraming.md) |
| Paragraaf | `Paragraaf` register | Paragrafen workspace | [Paragrafen](./bbv-paragrafen.md) |
| Reserve | `Reserve` register | Reserves & Voorzieningen workspace | [Reserves & Voorzieningen](./bbv-reserves-voorzieningen.md) |
| Voorziening | `Voorziening` register | Reserves & Voorzieningen workspace | [Reserves & Voorzieningen](./bbv-reserves-voorzieningen.md) |
| MVA | `MaterieleVasteActiva` register | MVA-register workspace | [MVA-administratie](./bbv-mva-administratie.md) |
| Rechtmatigheid | `JournalEntry.rechtmatigheidStatus` | Per-transaction stamp | [Rechtmatigheid](./bbv-rechtmatigheid.md) |
| Iv3-aanlevering | `Iv3Export` (sibling spec) | Iv3-aanlevering workspace | [BBV-compliance dashboard](./bbv-compliance-dashboard.md) |
| SiSa-bijlage | `Subsidie` register + export | SiSa-bijlage workspace | (planned) |

## Section 3 — Lifecycle guards in one sentence each

- **RGS-decentraal mapping verplicht (REQ-BBV-001)** — every BBV
  account must carry an `rgsDecentraalCode` before postings against
  it can be made.
- **Taakveld + economische categorie verplicht (REQ-BBV-002)** —
  every exploitatie posting must carry both fields, and the
  taakveld must be in the account's allowed set.
- **Meerjarenraming sluitend (REQ-BBV-003)** — every programma's
  four-jaars saldo must be ≥ 0 before the programma can be
  published; an explicit `raadsbesluit_nummer` overrides.
- **Reserves & voorzieningen routing (REQ-BBV-004)** — reserve
  mutations must book on taakveld `0.10`; voorziening mutations
  must book on the gekoppelde taakveld.
- **MVA-activering (REQ-BBV-005)** — `maatschappelijk-nut`
  investments above the activeringsgrens may not be booked
  directly to the exploitatierekening.
- **Paragraaf-completeness (REQ-BBV-007)** — a Jaarrekening cannot
  be published while any of the seven paragrafen is missing.
- **Rechtmatigheidsverantwoording (REQ-BBV-009)** — postings
  outside the tolerance window get stamped
  `afwijking_outside_tolerance` and roll up into the
  rechtmatigheidsverklaring.

The guards live in `lib/Guard/BbvComplianceGuard.php` and are
referenced from the schema lifecycle declarations per ADR-031
("PHP guards remain a legitimate seam").

## Section 4 — Troubleshooting

### The BBV navigation entries are hidden

The navigation entries are gated on
`administrationType ∈ {gemeente, provincie, waterschap}`. Open the
active administration's profile and confirm the type. If the type
is wrong, edit it on the Administration record — a settings change
is not required.

### A posting is rejected with REQ-BBV-001

The account you are posting to has no `rgsDecentraalCode`. Use the
[BBV-mapping approval](./bbv-programmaplan.md#rgs-mapping) workflow
to assign one — the `RgsAccountMapper` service suggests candidates
with a confidence score so you do not need to look them up by
hand.

### Historic postings are accepted without mapping

The guard is forward-only by `postingDate ≥ install_date` per
REQ-BBV-003. Historic data imported by migration is intentionally
exempt — the audit-trail records that the mapping was applied
after-the-fact, but does not block the import.

## What you have now

A mental model of how BBV concepts map to Shillinq entities, which
guard fires when, and where to go next for each workflow. Continue
with the dedicated workflow guides linked from the table above.

## See also

- [BBV Compliance Dashboard](./bbv-compliance-dashboard.md) —
  real-time KPI cards and exception alerts.
- [Programmaplan](./bbv-programmaplan.md) — CRUD on Programma,
  doelstellingen and beleidsindicatoren.
- [Meerjarenraming](./bbv-meerjarenraming.md) — multi-year
  budgeting and the sluitend-validation.
- [Paragrafen](./bbv-paragrafen.md) — the seven obligatory
  paragrafen and the completeness check.
