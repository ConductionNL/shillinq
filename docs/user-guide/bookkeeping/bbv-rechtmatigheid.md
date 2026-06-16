---
sidebar_position: 7
title: BBV Rechtmatigheidsverantwoording
description: How the rechtmatigheidsverklaring is built — tolerance configuration, M&O findings, posting-level stamps and the aggregated rapportage at jaarrekening.
---

# BBV Rechtmatigheidsverantwoording

From boekjaar 2024 the college van B&W (gemeente) of GS (provincie)
of het DB (waterschap) signs a rechtmatigheidsverklaring at the
jaarrekening, declaring whether and to what extent the
administration spent within statutory bounds. Shillinq builds
that verklaring bottom-up from per-posting stamps and a
tolerance configuration; this page walks through the workflow.

## Goal

By the end of this guide you can:

- configure the tolerance per programma per begrotingscategorie,
- understand how postings get stamped `compliant`,
  `afwijking_within_tolerance` or `afwijking_outside_tolerance`,
- register Misbruik & Oneigenlijk gebruik (M&O) findings, and
- read the resulting rechtmatigheidsverklaring concept.

## Prerequisites

- The active administration is BBV-eligible and has a published
  Programmaplan + Meerjarenraming for the boekjaar.
- A `RechtmatigheidsToleranceConfig` (or whatever equivalent
  exists in this version of Shillinq — see Section 1) is in
  place for the boekjaar.

## Open the workspace

1. Open **Shillinq → Overheid → BBV → Rechtmatigheid**.
2. The route is `/bbv/rechtmatigheid`.
3. The view splits in three: **Tolerance**, **Postings** and
   **M&O bevindingen**.

## Section 1 — Tolerance configuration

The Tolerance tab carries one row per `(programma, categorie)`
with the council's vastgestelde norms. The Kadernota
Rechtmatigheid 2024 prescribes:

- A **goedkeuringstolerantie** as percentage of the totale
  lasten of the administration (typically 1% strikt; 3%
  goedkeurend met beperking; >3% afkeurend).
- A **rapporteringstolerantie** per fout-categorie waar
  beneden geen rapportage hoeft (typically lager dan de
  goedkeuringstolerantie).

The form lets you set both as `cents` per categorie (in plaats
van percentage) zodat de tolerantie integer-cent blijft en
geen drijvende-komma-fouten introduceert.

## Section 2 — Posting-level stamps (REQ-BBV-009) {#stamps}

Elke journaalpost krijgt automatisch een `rechtmatigheidStatus`
stempel bij het posten. De engine evalueert de regel tegen de
relevante norms:

```
delta = sum(GLLine.bedrag for GLLine in post if account.bbvClassificatie == 'exploitatie')
if delta exceeds programma-budget by more than goedkeuringstolerantie:
    rechtmatigheidStatus = 'afwijking_outside_tolerance'
elif delta exceeds programma-budget by more than rapporteringstolerantie:
    rechtmatigheidStatus = 'afwijking_within_tolerance'
else:
    rechtmatigheidStatus = 'compliant'
```

De default is `compliant`. Een posting kan handmatig worden
gestempeld door een controller met de `rechtmatigheidStatus`
edit-permissie; de audit-trail logt de wijziging met motivatie.

## Section 3 — M&O bevindingen

De Misbruik & Oneigenlijk gebruik-tab is een ledger van
operationele bevindingen die niet rechtstreeks in een
journaalpost terugkomen (bijvoorbeeld onterecht verstrekte
subsidies, niet-naleving van een aanbestedingsregel). Elke
bevinding heeft:

- `categorie` — fraude / fouten / oneigenlijk gebruik.
- `omvang` — geschat bedrag in cents.
- `bron` — link naar het audit-finding-document (Docudesk).
- `correctievoorstel` — vrije tekst.

De bevindingen rollen op in de rechtmatigheidsverklaring
naast de aggregaten van Section 2.

## Section 4 — Rechtmatigheidsverklaring concept

De **Verklaring** action aggregeert alle vorige drie:

1. Som van `afwijking_outside_tolerance` postings per programma.
2. Som van `afwijking_within_tolerance` postings per programma.
3. Som van M&O bevindingen per categorie.
4. Vergelijking tegen de goedkeuringstolerantie totale lasten.

Het resultaat is een concept-verklaring in PDF/A-3 dat samen
met de jaarrekening wordt vastgesteld. Decidesk publiceert het
concept als agendapunt voor de college-/B&W-/DB-vergadering;
het raadsbesluit wordt teruggesynchroniseerd naar het
Administration-record.

## Section 5 — Troubleshooting

### Alle postings staan op `compliant` maar het concept laat overschrijdingen zien

Dat is correct: de status-stempel kijkt naar de individuele
post; de verklaring aggregeert per programma over het hele jaar.
Een cumulatieve overschrijding ontstaat ook als elke individuele
post binnen de tolerantie zit. Lees Section 2 vs Section 4 als
twee verschillende lagen.

### De tolerantie-formuliervelden vragen om cents maar de raadsnorm is in procenten

Vermenigvuldig de procent-norm met de totale geraamde lasten
(in cents) en deel door 100. Bewust integer-cent gehouden om
afronding kruis-jaar consistent te maken.

### Een M&O bevinding verschijnt niet in de verklaring

Status van de bevinding moet `vastgesteld` zijn — bevindingen
in `concept` of `in onderzoek` worden uitgesloten van het
rapportage-aggregaat.

## What you have now

Een per-administratie configureerbare tolerantie, per-posting
stempels, een M&O-ledger en een concept-rechtmatigheidsverklaring
ready voor college- of bestuursvaststelling.

## See also

- [Paragrafen](./bbv-paragrafen.md) — de paragraaf
  Bedrijfsvoering verwijst naar deze verklaring.
- [Meerjarenraming](./bbv-meerjarenraming.md) — de
  budgetafwijkingen worden hier tegen vergeleken.
- [BBV-overzicht](./bbv-compliance-overview.md) — de positie
  van rechtmatigheid binnen het BBV-pakket.
