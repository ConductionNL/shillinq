---
sidebar_position: 3
title: NL Loonadministratie — Audit & Compliance Checklist
description: Nederlandstalige audit- en compliance-checklist voor de Shillinq NL-loonadministratie (Wet LA 1964, BW 626, cumulatieven-consistentie, versionering, retentie, DGA-controle).
---

# Audit & Compliance Checklist (NL)

Deze checklist verifieert dat een Shillinq NL-loonadministratie voldoet aan
de wettelijke kaders. Doorloop hem per kalenderjaar (begin januari, bij
overdracht aan accountant en bij Belastingdienst-controle).

## 1. Wet op de loonadministratie 1964 — Volledige loonberekening reproduceerbaar

- [ ] Per werknemer per loonperiode is een `LoonStrook` opgeslagen
      (geen ontbrekende perioden tussen `inDienstSinds` en `uitDienstPer`)
- [ ] Elke `LoonStrook.brutoComponenten` bevat alle uitbetaalde delen
      (basissalaris, vakantietoeslag, ploegentoeslag, overuren,
      thuiswerkvergoeding, kilometervergoeding, fooi)
- [ ] `fiscaalLoon`, `premieloon_SV`, `loonheffing`, `inhoudingenSV`,
      `premiesSVWerkgever`, `zvw`, `pensioen`, `nettoBetaald` zijn op de
      cent berekend en niet handmatig overschreven
- [ ] De gebruikte `LoonheffingTabel2026` per `LoonPeriode` is via
      `loonheffingstabelId` op de strook gestempeld (auditor moet de
      bracket-rijen achteraf kunnen terugzien)

## 2. Loonstrook art. 626 BW

Per loonstrook moet vermeld zijn (controleer in de PDF-render of in de
ruwe `LoonStrook`):

- [ ] Naam, adres, BSN van de werknemer (BSN volledig in de PDF; in de
      app versluierd weergegeven)
- [ ] Naam en loonheffingsnummer van de werkgever
- [ ] Periode en betaaldatum
- [ ] Brutoloon (specificatie van elke component)
- [ ] Inhoudingen loonheffing en sociale premies (per regel)
- [ ] Nettoloon
- [ ] Cumulatieven JTD (`fiscaalloon_ytd`, vakantiegeld_reservering_ytd`)

## 3. Cumulatieven-consistentie

- [ ] Per werknemer: som van alle perioden `fiscaalLoon` == de
      cumulatieven `fiscaalloon_ytd` op de laatste strook van het jaar
- [ ] Geen "gat" tussen perioden (bv. ontbrekende september-strook door
      handmatig verwijderen)
- [ ] `PayrollJaaropgaveService::bouwJaaropgave` rapporteert
      `cumulatievenConsistent=true`; anders blokkeert
      `persistJaaropgave` de archivering — corrigeer de afwijkende
      strook eerst

## 4. Tax-table versionering (immutability)

- [ ] Geen `LoonheffingTabel2026` records gemuteerd na `geldigVan` — alle
      correctietabellen zijn nieuwe records met latere `geldigVan`
- [ ] OR audit-trail toont historische berekeningen tegen de oorspronkelijk
      gebruikte tabel-versie
- [ ] `versienummer` (bv. `2025-W47`) gevuld op elke tabel-rij; bron
      vermeld in `bron` veld

## 5. SV-premies en franchises 2026

- [ ] `premieloon_SV` gecapt op €74.480/jaar (pro-rata per periodetype)
- [ ] `zvw.afgedragen_wg` gecapt op €71.628/jaar
- [ ] AWF-laag/hoog tarief in lijn met werkgever-contractmix
      (LAAG = overwegend onbepaalde-tijd)
- [ ] WHK-tarief per `Werknemer.sectorcode` overeenkomt met UWV-publicatie
      Werkhervattingskas 2026

## 6. ZVW werkgever-afdracht

- [ ] `werkgever.zvwTarief` is `LAAG` (5,32%) of `HOOG` (6,57%); niet
      ingevuld is verboden
- [ ] Geen `ingehouden_wn` op ZVW — die loopt buiten de werkgever om
      (zorgverzekering werknemer)

## 7. Vakantietoeslag (BW 7:634)

- [ ] `vakantiegeldPct` van elke werknemer ≥ 8% (WML-minimum)
- [ ] Opbouw maandelijks zichtbaar in `vakantieDagenReservering.opgebouwdEuro`
- [ ] Uitbetaling in de afgesproken maand
      (`werkgever.vakantiegeldUitbetalingMaand`); LH op bijzondere tabel;
      cumulatief reset naar nul na uitbetaling

## 8. Pensioen (Pensioenwet 2007)

- [ ] Werknemer + werkgever aandeel berekend tegen de afgesproken
      `pensioenPremiePctWerkgever` / `pensioenPremiePctWerknemer`
- [ ] UPA-submission via `PayrollUpaHandoffService` per
      pensioenuitvoerder maandelijks
- [ ] Werknemer-aandeel ingehouden op netto; werkgever-aandeel in GL
      4020 (Pensioenpremie WG)

## 9. DGA-gebruikelijk-loon (Wet LB art. 12a)

- [ ] Voor elke `is_dga=true` werknemer: `jaarloonBruto ≥ €56.000` (norm
      2026), of een gevulde `gebruikelijkLoonUitzondering` met bewijs
- [ ] Dashboard-waarschuwingen actief; geen blocking — accountant
      eindverantwoordelijk

## 10. Document-retentie (Wet LA 1964 art. 7)

- [ ] Loonstroken bewaard **7 jaar** in openregister
- [ ] Jaaropgaven bewaard **5 jaar** in openregister
- [ ] BSN-bevattende velden vallen onder de OR RBAC + audit-trail
- [ ] Verwijderingsverzoeken (AVG art. 17) worden geweigerd zolang de
      bewaarplicht loopt

## 11. LH-afdracht (REQ-PAY-011)

- [ ] Per `LoonPeriode` één `LHAfdracht` met
      `status=VOORBEREID|GEVERIFIEERD|VERZONDEN|VERWERKT`
- [ ] `totaalLoonheffing + totaalPremiesSV + totaalZVW +
      totaalEindheffingenWKR == totaalAfdracht`
- [ ] `vervaldagAfdracht` = laatste dag van de volgende maand
- [ ] `sbrInstanceRef` gestempeld via `PayrollSbrConversionService`
      voordat de SBR-app verzendt
- [ ] AP-transacties aangemaakt via `PayrollApArHandoffService`
      (Belastingdienst en UWV)

## 12. GL-boeking (REQ-PAY-012)

- [ ] Per gesloten `LoonPeriode` één `Loonjournaalpost`
- [ ] `balanced=true` (anders weigert `persistLoonjournaalpost`)
- [ ] Alle 9 regels gebruiken de canonieke RGS 3.5 rekeningen
      (`PayrollChartOfAccountsMapping`)

## 13. Privacy / AVG

- [ ] BSN versluiterd weergegeven in elke human-facing surface
      (`PayrollService::maskBsn`)
- [ ] Logging bevat **geen** raw BSN, fiscaal-partner BSN, ZVW-data
- [ ] Audit-trail (OR) toont **wie**, **wanneer**, **welk veld**;
      geen toegang voor ongeautoriseerde rollen

## 14. Toegang & autorisatie

- [ ] Payroll-endpoints alleen voor authenticated users in de NC
      administratie
- [ ] `administration_id` query-param valide tegen
      `/^[A-Za-z0-9_.\-]{1,64}$/`; cross-administratie reads blokkeren
      door OR-multitenancy (handmatig getest)
- [ ] Geen `#[PublicPage]` op payroll-routes (handmatig gecontroleerd
      in `appinfo/routes.php`)

## 15. Hydra quality gates (Shillinq)

- [ ] `scripts/run-hydra-gates.sh` rapporteert "ALL 16 GATES GREEN"
- [ ] PHPCS + PHPMD + Psalm + PHPStan groen voor `lib/`
- [ ] PHPUnit groen voor `tests/Unit/Service/Payroll*`

## 16. Audit-trail spot-checks

- [ ] Pak een willekeurige `LoonStrook` uit Q1 2026 en verifieer:
      - alle gebruikte tabellen, premies, percentages zijn te herleiden
        uit de stamhouder-versies
      - de cumulatieven matchen de som van de voorgaande loonstroken
      - de bijbehorende `Loonjournaalpost` is gebalanceerd en
        gepersisteerd
      - de LH-afdracht over die maand is `VERWERKT` met
        `sbrInstanceRef` en bijbehorende AP-transacties

## Standards-referentie

- Wet op de loonbelasting 1964 (Wet LB) — art. 12a, art. 13, art. 31
- Wet op de loonadministratie 1964 (Wet LA) — bewaarplicht
- Wet financiering sociale verzekeringen (Wfsv) 2021 — franchises 2026
- Zorgverzekeringswet art. 41 — ZVW-tarieven 2026
- Pensioenwet 2007 — wettelijk minimum
- BW art. 7:610–634 — arbeidsovereenkomst, loon, vakantie
- WML 2026 — minimumloon en vakantiebijslag
- Belastingdienst Handboek Loonheffingen 2026
- LH-tabellen 2026 versie 2025-W47
- SBR Nederland Loonaangifte-taxonomie LA-XX-2026
- UWV Werkhervattingskas-premies 2026
- AVG (GDPR) — BSN, gevoelige werknemersdata
