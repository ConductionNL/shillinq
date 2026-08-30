---
sidebar_position: 4
title: BBV Paragrafen
description: The seven obligatory paragrafen of a BBV jaarrekening — template-driven editor, auto-populated fields, and the completeness gate that fires on publication.
---

# BBV Paragrafen

BBV art. 9 prescribes seven paragrafen that every gemeente,
provincie and waterschap jaarrekening must contain:

1. Lokale heffingen
2. Weerstandsvermogen en risicobeheersing
3. Onderhoud kapitaalgoederen
4. Financiering
5. Bedrijfsvoering
6. Verbonden partijen
7. Grondbeleid

Shillinq stores each as one `Paragraaf` record per
`(administrationId, type, boekjaar)`. The Paragrafen workspace
gives every paragraaf a template-driven editor, auto-populates
the quantitative fields from the rest of the BBV-administratie
and surfaces a completeness score per paragraaf.

## Goal

By the end of this guide you can:

- open each of the seven paragrafen,
- understand which fields are auto-populated and which require
  a narrative,
- complete the paragraaf to 100% so the jaarrekening can be
  published, and
- diagnose the completeness gate when a publication is blocked.

## Prerequisites

- A Programma + meerjarenraming has been published for the
  boekjaar in question.
- The Reserves & Voorzieningen workspace contains all current
  reserves and voorzieningen — see
  [Reserves & Voorzieningen](./bbv-reserves-voorzieningen.md).
- The MVA-register is up to date — see
  [MVA-administratie](./bbv-mva-administratie.md).

## Open the paragrafen workspace

1. Open **Shillinq → Overheid → BBV → Paragrafen**.
2. The route is `/bbv/paragrafen`.
3. The index renders one card per paragraaf type, with a
   completeness percentage (0-100) and a green/amber/red badge.

## Section 1 — Index view (the cards)

Each card surfaces:

- **Type** — one of the seven paragraaf names.
- **Voltooiing** — percentage of obligatory fields filled.
- **Auto-populated** — count of fields filled by the engine
  (e.g. `weerstandsratio`, `algemene reserve saldo`,
  `MVA boekwaarde ultimo`).
- **Status** — `draft` / `concept` / `vastgesteld`.
- **Laatst bewerkt** — timestamp + user.

The badge thresholds are:

| Status | Rule |
| ------ | ---- |
| Green | voltooiing = 100% |
| Amber | 60% ≤ voltooiing < 100% |
| Red | voltooiing < 60% |

## Section 2 — Template-driven editor

Each paragraaf type has its own template; the editor mounts the
template fields in order and inlines the auto-populated
quantitative fields. A few examples:

### Weerstandsvermogen

- Auto: `algemeneReserveSaldo`, `bestemmingsreservesSaldo`,
  `risicoTotaal`, `weerstandsratio = (algemene + bestemming) / risico`.
- Narrative: assessment of the ratio against the council's
  vastgestelde minimum, explanation of structural vs.
  incidentele risico's.

### Financiering

- Auto: `renteOmslag`, `langeFinancieringssaldo`,
  `korteFinancieringssaldo`, `kasgeldlimiet`,
  `renterisiconormBezetting`.
- Narrative: rente-visie, treasurer's strategy.

### Onderhoud kapitaalgoederen

- Auto: `mvaBoekwaardeUltimo` per `mvaCategorie`,
  `voorzieningenOnderhoud`.
- Narrative: per-category onderhoudstoestand assessment,
  beleidskaders en bijbehorende uitvoeringsplannen.

### Grondbeleid

- Auto: `nietInExploitatieGenomenGronden`,
  `bouwgrondInExploitatie`, `voorzieningenGrondexploitatie`.
- Narrative: visie en de keuze tussen actieve en faciliterende
  grondpolitiek; deze paragraaf wordt voor T4 verrijkt met de
  outputs van de sibling grondexploitatie spec.

The remaining four paragrafen (Lokale heffingen, Bedrijfsvoering,
Verbonden partijen, plus the optional integraliteitsverklaringen)
follow the same auto + narrative pattern.

## Section 3 — Completeness gate (REQ-BBV-007)

When a controller invokes **Vaststellen** on a Jaarrekening the
`BbvComplianceGuard::paragrafen_compleet_ok` precondition runs:

```
required = [
    'lokale-heffingen',
    'weerstandsvermogen',
    'onderhoud-kapitaalgoederen',
    'financiering',
    'bedrijfsvoering',
    'verbonden-partijen',
    'grondbeleid',
]
for each type in required:
    if no Paragraaf with (administrationId, type, boekjaar):
        fail with BBVConstraintError("REQ-BBV-007: paragraaf {type} ontbreekt (BBV art. 9)")
```

The error lists every missing paragraaf; you do not need to fix
them one at a time.

## Section 4 — Troubleshooting

### Weerstandsratio shows N/A

The ratio requires both a positive `risicoTotaal` and at least
one reserve. Confirm the **Risicobeheersing** workspace contains
identified risico's, and that the algemene reserve has a
non-zero `saldoEindJaar`.

### Auto-populated field shows yesterday's data

Auto-population batches nightly. Use the **Herberekenen** action
on the paragraaf card to force a recompute; expect a few
seconds for a typical administration.

### Vaststellen fails after I added the missing paragraaf

The completeness gate only runs against `vastgesteld` paragrafen.
Newly-added paragrafen sit in `draft`; vaststel each one
individually before re-trying the Jaarrekening vaststelling.

## What you have now

Seven complete and vastgestelde paragrafen, with auto-populated
quantitative fields wired to the underlying registers. Continue
with the Reserves & Voorzieningen, MVA-administratie or
Rechtmatigheid workflows depending on which paragraaf still
needs more narrative.

## See also

- [Reserves & Voorzieningen](./bbv-reserves-voorzieningen.md) —
  drives the weerstandsvermogen and grondbeleid quantitatives.
- [MVA-administratie](./bbv-mva-administratie.md) — drives the
  onderhoud-kapitaalgoederen quantitatives.
- [Rechtmatigheidsverantwoording](./bbv-rechtmatigheid.md) — the
  separate verklaring that ships alongside the paragrafen.
