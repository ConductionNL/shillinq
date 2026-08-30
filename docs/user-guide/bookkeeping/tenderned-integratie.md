---
sidebar_position: 25
title: TenderNed integratie
description: Importeer en activeer Nederlandse aanbestedingen uit TenderNed als Shillinq-verplichtingen, beheer mijlpalen, leg bewijsstukken vast, en synchroniseer afronding terug naar het publieke dossier.
---

# TenderNed integratie

Shillinq integreert met **TenderNed** (het centrale aanbestedingsplatform van
Logius) zodat publieke opdrachtgevers en private leveranciers één
financiële verplichtingenadministratie kunnen voeren: van import van het
aanbestedingsdossier, via mijlpaalplanning en bewijsstukken, tot synchronisatie
van de eindoplevering terug naar het publieke dossier conform
**Aanbestedingswet 2012 artikel 2.135**.

## Doel

Aan het einde van deze handleiding kunt u:

- Een TenderNed-dossier importeren als concept-verplichting (REQ-001).
- Een gegund dossier laten promoveren tot actieve verplichting (REQ-002 +
  REQ-003: automatisch gegenereerd mijlpaalplan).
- Een mijlpaaloplevering registreren met verplicht bewijsstuk (REQ-004).
- De eindoplevering terugkoppelen naar TenderNed (REQ-006).
- De **Mijn Contracten**-view gebruiken als leverancier (REQ-008).
- De volledige onveranderlijke audit-trail (REQ-005) raadplegen.

## Vereisten

- Een Nextcloud-account met **Shillinq** geïnstalleerd en geactiveerd.
- Een actieve **administratie** gekoppeld aan de tenant.
- De OpenRegister `shillinq` register is bereikbaar (Instellingen →
  Shillinq).
- Het app-config `tenant_kvk` is ingesteld op het KvK-nummer van de
  organisatie. Dit nummer wordt gebruikt om te bepalen of de tenant de
  aanbestedende dienst of de gegunde leverancier is bij een dossier
  (REQ-002 / REQ-006).
- Optioneel: **OpenConnector** met de TenderNed-bron voor live polling
  van aanbestedingsstatussen (vereist voor de automatische REQ-002 promotie
  en voor REQ-006 status-sync; zonder OpenConnector kunt u dossiers
  handmatig importeren en verwerken).
- Optioneel: **DocuDesk** voor het opslaan van bewijsstukken (PDF, foto,
  scan) als file-referentie in de oplevering (REQ-004).

## 1. Een dossier importeren (REQ-001)

Open **Inkoop → TenderNed Aanbestedingen** en kies *Importeer*. Vul het
TenderNed-aanbestedingsnummer (`aanbestedingId`) in en bevestig. Shillinq
haalt het dossier op via de openconnector-bron en maakt:

1. Een `TenderNedAanbesteding`-record met de publieke metadata
   (titel, beschrijving, CPV-codes, aanbestedende dienst, contractwaarde,
   looptijd, gegunde leverancier).
2. Een gekoppelde `Verplichting` met:
   - `bron: tenderned`
   - `bronReferentie: <aanbestedingId>`
   - `status: concept` — de verplichting verbruikt nog géén budget tot
     activering.

> **Tip.** Een concept-verplichting blijft buiten de budget-impactweergave
> tot de contractmanager `kostenplaats` en `grootboekrekening` aanvult en
> de transitie **Activeren** uitvoert.

## 2. Het concept verrijken en activeren

Open **Inkoop → Verplichtingen** en klik op uw concept. Vul aan:

- `kostenplaats` — vereist voor budget-allocatie.
- `grootboekrekening` — vereist voor GL-koppeling.
- Optioneel: `opdrachttype` overrulen (`levering-in-fases`,
  `dienstverlening-doorlopend`, of `other`).

Klik **Activeren**. Shillinq genereert nu het initiële mijlpaalplan
(REQ-003) uit het opdrachttype-sjabloon:

| Opdrachttype                 | Sjabloon                                          |
|------------------------------|---------------------------------------------------|
| `levering-in-fases`          | 4 mijlpalen, elk 25%, op kwartaalbasis.          |
| `dienstverlening-doorlopend` | 12 mijlpalen, elk 8,33%, maandelijks.            |
| `other`                      | 2 mijlpalen, elk 50% (midden + einde looptijd).  |

De `Verplichting`-status wordt `active` en de
`shillinq.obligation.activated`-CloudEvent gaat uit zodat launchpad de
nieuwe budget-impact binnen 60 seconden toont (REQ-007).

## 3. Automatische promotie bij gunning (REQ-002)

Wanneer de OpenConnector-pollingjob detecteert dat een dossier `gegund`
wordt EN de winnende KvK overeenkomt met de tenant-KvK
(`gegundeLeverancier` begint met de `tenant_kvk` uit app-config), dan
materialiseert Shillinq zelfstandig:

1. Een actieve `Verplichting` (idempotent op `bronReferentie` — een
   tweede gebeurtenis maakt geen tweede record).
2. Een initieel mijlpaalplan (REQ-003).
3. De `TenderNedAanbesteding`-status wordt `in-uitvoering`.
4. De `shillinq.obligation.activated`-CloudEvent gaat uit
   (budget-impactweergave).

Contractmanagers ontvangen automatisch een Nextcloud-notificatie zodat zij
de kostenplaats en grootboekrekening alsnog kunnen aanvullen indien die
nog niet zijn afgeleid.

## 4. Mijlpalen voltooien met bewijsstuk (REQ-004)

Voor elke mijlpaal maakt u een `OpdrachtUitvoering`-record aan via
**Inkoop → Verplichtingen → \<uw verplichting\> → Mijlpalen → Oplevering
registreren**. Vereist veld: **Bewijsstuk** (één of meer file-referenties
uit DocuDesk).

Pogen om de oplevering te markeren als **Voltooid** zonder geldige
`bewijsstukken` wordt geblokkeerd door de `OpdrachtUitvoeringGuard` met:

> "Voeg minimaal één bewijsstuk toe voordat u de oplevering als voltooid
> markeert."

Zodra ten minste één bewijsstuk met een niet-leeg `documentId` is
gekoppeld, slaagt de transitie.

## 5. Eindoplevering & terugkoppeling naar TenderNed (REQ-006)

Voor de **laatste** mijlpaal kiest u `opleveringsType: eindoplevering`,
voegt bewijsstukken toe en zet `goedgekeurd: true`. Bij voltooiing:

- De `shillinq.milestone.completed`-CloudEvent gaat uit (audit-trail +
  budget-utilisatie).
- Als de tenant de **aanbestedende dienst** is (tenant-KvK komt overeen
  met `aanbestedendeDienst` op het dossier), stuurt Shillinq via
  OpenConnector een statusupdate (`status: afgerond`) naar het publieke
  TenderNed-dossier (Aanbestedingswet 2012 art. 2.135).
- Leveranciers (`inschrijvers`) kunnen **niet** de terugkoppeling
  triggeren — de `TenderNedStatusSync` weigert de uitgaande call.
- Een falende sync geeft een waarschuwing (`...niet verzonden — probeer
  over 5 minuten opnieuw`) maar blokkeert de mijlpaalvoltooiing niet.

## 6. Mijn Contracten (REQ-008, leveranciersrol)

Bent u de gegunde leverancier, gebruik dan **Inkoop → Mijn Contracten**.
Deze view filtert automatisch op `bron: tenderned` en toont de
contracten waar uw KvK aan gekoppeld is. Per contract ziet u
contractwaarde, looptijd, status, en — read-only — de mijlpaalplanning
en het cashflow-prognose-overzicht (REQ-008).

> De rij-niveau RBAC op `Verplichting.x-openregister-rbac` zorgt dat
> leveranciers alleen hun eigen contracten zien; de manifest-filter
> versmalt de view aanvullend.

## 7. Audit-trail (REQ-005)

Elke create, update en lifecycle-transitie op `TenderNedAanbesteding`,
`Verplichting` en `OpdrachtUitvoering` wordt opgeslagen in de
onveranderlijke audit-trail van OpenRegister (ADR-022 — geen app-lokale
audit-tabel). Voor elk record kunt u via de detailpagina onderaan op
**Audit-trail** klikken om de complete keten op te halen:
import → verrijking → activering → mijlpaalvoltooiingen →
status-terugkoppeling. ENSIA-auditors kunnen de keten in onder 10
seconden traceren met de `bronReferentie` als rode draad.

Geen rol heeft `delete`-permissie op deze drie schema's; verwijderingen
zijn structureel uitgesloten zodat de audit-trail compleet blijft.

## Veelvoorkomende meldingen

| Melding                                                                                                  | Wat te doen                                                                  |
|----------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------|
| **Activering geweigerd: kostenplaats en grootboekrekening ontbreken**                                    | Vul de twee velden op de verplichting en probeer opnieuw.                    |
| **Voeg minimaal één bewijsstuk toe voordat u de oplevering als voltooid markeert.**                      | Upload het bewijsstuk via DocuDesk en koppel het aan de oplevering.          |
| **Mijlpaaldatum valt buiten de contractlooptijd**                                                        | Pas de mijlpaaldatum aan of verleng `looptijdEind` op de verplichting.       |
| **Statusupdate naar TenderNed niet verzonden — probeer over 5 minuten opnieuw**                          | De openconnector kan het publieke dossier niet bereiken; OpenConnector logs. |
| **Tenant is niet de aanbestedende dienst — terugkoppeling geweigerd**                                    | Alleen de aanbestedende dienst mag de eindstatus naar TenderNed sturen.      |

## Referentie

- Spec: `openspec/changes/bookkeeping-tenderned-integratie/specs/bookkeeping-tenderned-integratie/spec.md`
- Schema: `lib/Settings/register.d/20-bookkeeping-tenderned-integratie.json`
- Manifest-fragment: `src/manifest.d/20-tenderned-integratie.json`
- Guards: `lib/Lifecycle/TenderNedAanbestedingGuard.php`,
  `lib/Lifecycle/VerplichtingGuard.php`,
  `lib/Lifecycle/OpdrachtUitvoeringGuard.php`
- Cross-app CloudEvent-emitter: `lib/Service/BudgetImpactEmitter.php`
- Outbound sync: `lib/Integration/TenderNedStatusSync.php`
- Aanbestedingswet 2012 — [overheid.nl](https://wetten.overheid.nl/BWBR0032203)
- TenderNed — [tenderned.nl](https://www.tenderned.nl)
