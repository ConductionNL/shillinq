---
sidebar_position: 1
title: BADO Controleprotocol — Gebruikershandleiding
description: Nederlandstalige handleiding voor het Shillinq BADO-controleprotocol (Tier-3 audit-protocol, materialiteit, tolerantiematrix, audit-samples, bevindingen, verklaring, accountantsdossier).
---

# BADO Controleprotocol — Gebruikershandleiding

Deze handleiding beschrijft hoe je in Shillinq een volledig BADO-conform
controleprotocol opzet, uitvoert en afsluit met een PDF/A-getekend
accountantsdossier — van eerste concept tot AFM- en provinciaal-toezicht-klaar
bundle. De rekenregels volgen het Besluit Accountantscontrole Decentrale
Overheden (BADO, Stb. 2006, 21), de Notitie Materialiteit en Tolerantie
(BBV/Commissie BBV), Kadernota Rechtmatigheid, de SiSa-bijlage van het
Ministerie van BZK en NV COS 700-serie.

## 1. Een controleprotocol opstellen (status `draft`)

Je begint met **Audit Protocols → Nieuw concept**. Verplichte velden:

- `version` — protocolversie (bv. `2026.1`)
- `auditYear` — het auditjaar dat het protocol regelt
- `organisationType` — `gemeente`, `provincie`, `waterschap`, of `GR`
- `organisationId` — link naar de organisatie
- `effectiveFrom` / `effectiveTo` — geldigheidsperiode (meestal kalenderjaar)
- `materialityBase` — `lasten`, `baten`, of `balanstotaal`
- `materialityAmount` — voorlopige materialiteit (kan nog wijzigen tot aan
  vaststelling)

Optioneel:

- `referenceFramework` — actieve BBV-versie + Wet Fido + SiSa-regeling

> **Tip.** Materialiteit kies je conform de Notitie Materialiteit en Tolerantie:
> doorgaans 1% van de totale lasten, te narrowen op programma-niveau.

## 2. Standaard-tolerantiematrix vooraf invullen

Zodra het concept bestaat opent Shillinq **Tolerance Matrices → Pre-populate
defaults** een lijst met de zes BADO-statutaire ceilings per programma /
taakveld / SiSa-regeling:

| Veld | Default | BADO-bovengrens |
|------|---------|-----------------|
| `getrouwheidApprovalCeiling` | 1% materialiteit | 1% |
| `getrouwheidQualificationCeiling` | 3% materialiteit | 3% |
| `rechtmatigheidApprovalCeiling` | 1% materialiteit | 1% |
| `rechtmatigheidQualificationCeiling` | 3% materialiteit | 3% |
| `uncertaintyCeiling` | 3% materialiteit | 3% |

Een protocol mag een ceiling **strenger** maken (lager dan default), nooit
**ruimer** — Shillinq weigert ceilings die de BADO-bovengrens overschrijden.

> **Voorbeeld (gemeente Hoorn, 2026).** Materialiteit € 1 mln; tolerantiematrix
> Sociaal Domein laat default staan → goedkeurend tot € 10k per axis,
> beperking tot € 30k per axis.

## 3. Materialiteit berekenen vanuit de begroting

Open **Materialiteit → Bereken uit begroting**. Velden:

- `scope` — `overall`, `programma`, `taakveld` of `sisa`
- `base` — totaal lasten / baten / balanstotaal in EUR
- `percentage` — toegepast percentage (≤ 1,0 per Notitie Materialiteit)
- `calculatedAmount` — automatisch: `base × percentage`
- `rationale` — toelichting op de keuze van basis + percentage

Materialiteit is **bewerkbaar** zolang het protocol in `draft` of `in-review`
staat; bij vaststelling vriest Shillinq de materialiteit (`status=frozen`) en
volgen begrotingswijzigingen niet meer.

## 4. Indienen voor besluit (`draft` → `in-review`)

Klik **Indienen voor besluit**. Shillinq blokkeert deze overgang als
verplichte velden ontbreken (`version`, `auditYear`, `organisationId`,
`organisationType`, `materialityBase`, `effectiveFrom`, `effectiveTo`). Bij
overgang zet Shillinq de tolerantiematrix en materialiteit op slot — CFO
en accountant kunnen het concept doorgronden, niet wijzigen.

## 5. Raadsbesluit koppelen + vaststellen (`in-review` → `adopted`)

Vul **adoptionDecision** in:

- `besluitnummer` — bv. `2026/07`
- `datum` — datum raadsbesluit
- `decisionType` — `raadsbesluit`, `statenbesluit`, of `AB-besluit`

Klik **Vaststellen**. Shillinq controleert beide velden niet leeg te zijn en
markeert het protocol als `adopted` + `adoptionDate`. Vanaf dat moment kunnen
audit-werkzaamheden beginnen. De stack publiceert `audit.protocol.adopted`
naar OpenConnector zodat `bookkeeping-bbv-compliance` en
`bookkeeping-rekenkamer-audit-pack` het auditjaar locken.

## 6. Steekproef trekken (AuditSample)

Open **Audit Samples & Findings → Extract sample**. Velden:

- `population` — bv. *"invoices > €10k in Sociaal Domein, 2026-01-01 to
  2026-12-31"*
- `selectionMethod` — `monetary-unit-sampling` (MUS), `random`, of
  `risk-based`
- `sampleSize` — aantal transacties
- `reproducibleSeed` — UUID/hash zodat de auditor dezelfde steekproef opnieuw
  kan trekken (Wet Fido + NV COS 530)
- `extractedAt`, `extractedBy` — tijdstempel + auditor-identiteit

> **Belangrijk.** Sla de `reproducibleSeed` op — bij een latere SiSa-controle
> kan de toezichthouder dezelfde steekproef regenereren zonder nieuwe random.

## 7. Bevindingen vastleggen (AuditFinding)

Per uitzondering uit de steekproef:

- `sample` — link naar de AuditSample
- `transaction` — link naar de grootboekpost
- `findingType` — `rechtmatigheid`, `getrouwheid`, of `onzekerheid`
- `amount` — uitzonderingsbedrag in EUR
- `narrative` — beschrijving van de uitzondering
- `topic` — programma / taakveld waaronder de bevinding aggregeert
- `controllerResponse` — reactie van de controller / portefeuillehouder
- `auditorConclusion` — beoordeling van de auditor

**Severity wordt automatisch afgeleid** door Shillinq tegen de
tolerantiematrix:

- `< approval ceiling` → **acceptabel**
- `>= approval, < qualification` → **te corrigeren**
- `>= qualification` → **materieel**

## 8. Escalatie + vier-ogen-workflow

Bevindingen volgen `open → agreed → resolved` (bij overeenstemming) of
`open → disputed → resolved` (bij escalatie).

- **Akkoord:** de controller reageert (`controllerResponse`); auditor accepteert
  (`auditorConclusion = "accepted"`); status flipt naar `agreed`.
- **Escalatie:** auditor concludeert `"escalation required"`; status flipt
  naar `disputed`. Shillinq maakt automatisch een **escalation task** aan
  voor de externe audit-manager of provinciaal toezichthouder.
- **Afsluiting:** beide assen (rechtmatigheid + getrouwheid) krijgen een
  classificatie + bedrag; status flipt naar `resolved`.

Zonder beide reacties + beide assen weigert Shillinq de transitie
(vier-ogen-principe, NV COS 230).

## 9. Aggregatie en oordeel beoordelen

Open **Audit Verklaringen → Aggregation dashboard**. Shillinq berekent
**server-zijdig** (de client-zijdige severity wordt nooit vertrouwd) per
topic:

- Aantal bevindingen per severity (acceptabel / te corrigeren / materieel)
- Uitzonderingsbedrag per axis (rechtmatigheid / getrouwheid)
- Verdict (`acceptable` / `qualified` / `adverse`)

Het **voorgestelde oordeel** volgt de BADO-beslisboom (NV COS 700):

1. Pervasieve scope-beperking → **oordeelonthouding**
2. Materiële + pervasieve afwijking → **afkeurend**
3. Materiële afwijking → **met beperking**
4. Geen materiële afwijking + geen onzekerheid boven ceiling → **goedkeurend**

De auditor kan het oordeel overrulen met een expliciete rationale; dat
maakt een aanvullende escalation-task aan.

## 10. SiSa-bijlage IIA per regeling

Voor elke SiSa-regeling in scope leg je een **SiSaAssurance** aan:

- `regelingCode` — bv. `G2`, `G3`
- `verantwoordingsplichtige` — gemeente / UWV / etc.
- `specifiekeUitkering` — naam van de uitkering
- `assuranceLevel` — `financial-statement` (volle BADO-scope) of
  `sisa-specific` (alleen regeling-procedures)
- `findings` — array van AuditFinding-id's per regeling

Shillinq laat **geen verklaring ondertekenen** zolang er nog SiSa-regelingen
in scope zijn zonder ≥ 1 SiSaAssurance-child (REQ-008 validation rule 6).

## 11. Verklaring ondertekenen + accountantsdossier exporteren

Open de VerklaringDraft. Velden:

- `proposedOpinion` — automatisch afgeleid; manueel override mogelijk
- `opinionRationale` — verplichte tekstuele onderbouwing met BADO-citaten
- `signOff` — auditor-identiteit, AFM-vergunningsnummer, datum, plaats

Klik **Ondertekenen**. Shillinq controleert:

- Geen open of disputed bevindingen meer (status `agreed` of `resolved`)
- Alle in-scope SiSa-regelingen gedekt

Daarna klik je **Exporteer accountantsdossier**. Shillinq bundelt:

1. Controleprotocol-header + adoptionDecision
2. Volledige tolerantiematrix
3. Materialiteit-rij(en)
4. Alle AuditSamples (incl. seed)
5. Alle AuditFindings (incl. controller-response + auditor-conclusion)
6. VerklaringDraft (oordeel + rationale + sign-off)
7. Alle SiSaAssurance-rijen

Het resultaat:

- **ZIP-bundle** met `manifest.json`, `ledger.json` en `summary.pdf.html`
- **SHA-256-anker** over `ledger.json` (detecteert latere wijziging)
- **ISO 8601 UTC-tijdstempel** in de manifest
- **PDF/A-1B-conform** HTML-summary (ISO 19005-1:2005) — downstream
  renderer (mPDF, wkhtmltopdf met PDF/A-profile, Apache PDFBox) levert
  het PDF-binaire bestand
- **PKIO-handtekening** gedelegeerd aan de geconfigureerde signer
  (docudesk + qualified-certificate); zonder signer markeert Shillinq
  `signaturePending=true` zodat de operator hem out-of-band laat zetten
- **Retentie 7 jaar** — Archiefwet + Selectielijst Gemeenten 2020, 21.1

## 12. Wat de toezichthouder ontvangt

De AFM of provinciale toezichthouder downloadt de ZIP-bundle, valideert de
PKIO-handtekening en kan vervolgens stap-voor-stap door de zeven secties
heen — geen externe bestanden meer aanvragen. Bij twijfel kunnen ze de
SHA-256 over `ledger.json` opnieuw berekenen; mismatch = bundle is gewijzigd
na ondertekening en verliest forensische waarde.

## 13. Worked example: gemeente Hoorn, auditjaar 2026

| Stap | Waarde |
|------|--------|
| Controleprotocol versie | 2026.1 |
| Materialiteit | 1% van € 100 mln lasten = € 1 mln |
| Tolerantiematrix | BADO-statutair (Sociaal Domein, Bedrijfsvoering) |
| Steekproef | MUS, 60 transacties, seed `5f2a-7b91-d8c0-…` |
| Bevinding 1 | Sociaal Domein, € 1.000, te-late machtiging → acceptabel |
| Bevinding 2 | Sociaal Domein, € 15.000, te-corrigeren post → te-corrigeren |
| Bevinding 3 | Bedrijfsvoering, € 35.000, materiële boekingsfout → materieel |
| Verdict Sociaal Domein | qualified |
| Verdict Bedrijfsvoering | adverse |
| Voorgesteld oordeel | met beperking |
| SiSa-bijlage | leeg (geen regelingen in scope) |
| Bundle SHA-256 | 64-char anchor in `manifest.json` |
| Retentie | 7 jaar (Selectielijst 21.1) |

## 14. Veelgestelde vragen

**Wat als Shillinq materialiteit te hoog/laag uitrekent?**
Pas `percentage` of `base` aan in `draft`; na vaststelling is wijzigen niet
meer mogelijk — superseden met een nieuw protocol voor hetzelfde auditjaar
en organisatie.

**Mag een tolerantiematrix-row hoger dan 3% kwalificatie-ceiling?**
Nee. Shillinq weigert de save (`validateCeilings()` retourneert de field-
namen die de BADO-bovengrens overschrijden). Strenger (< default) is wel
toegestaan.

**Wat gebeurt er met een dispuut dat niet binnen het auditjaar oplost?**
Het blijft `disputed` tot beide assen + beide reacties compleet zijn. De
VerklaringDraft kan dan **niet** worden ondertekend; de controller publiceert
in plaats daarvan een tussenbalans + escalatie-melding (NV COS 705).

**Hoe sla ik het bundel op?**
De ZIP gaat naar `sys_get_temp_dir()`; production-installs koppelen
docudesk-archive zodat het bundel direct in een WORM-bucket landt (7-jaar
retentie). Het `manifest.json` is leidend voor de archivaris.
