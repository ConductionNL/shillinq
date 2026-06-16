<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

# Journey: Gemeente-controller brings a new BBV-administratie online

**Persona:** Gemeente-controller, eindverantwoordelijk voor
begroting, jaarrekening en de rechtmatigheidsverklaring richting
de gemeenteraad.
**Spec:** `bookkeeping-bbv-compliance`
(REQ-BBV-001..009)

## Goal

In één werkdag een nieuwe BBV-administratie inrichten, het
programmaplan en de meerjarenraming auteuren, de sluitend-check
passeren, de zeven paragrafen vullen en uiteindelijk de
jaarrekening exporteren — zonder de declaratieve guards te
hoeven herschrijven.

## Steps

1. **Maak de administratie aan.** Open **Shillinq → Administratie
   → Nieuw** en kies `administrationType = gemeente`. Vul de
   wettelijke gemeentecode in (zodat de iv3-export later de
   juiste header krijgt). Bevestig.
   _Resultaat:_ De repair-step `InitializeBbvAdministration`
   bootstraps automatisch (i) een `Algemene reserve` met saldo 0,
   en (ii) taakveld `0.10 Mutaties reserves` voor overheidslaag
   `gemeente`. De BBV-navigatie verschijnt onder Overheid → BBV.

2. **Controleer de stamdata.** Open **BBV → Taakveld-register** en
   verifieer de 53 gemeente-taakvelden uit
   `bbv-taakvelden-gemeente-2025.json`. Check ook
   **EconomischeCategorie-register** (~150 codes) en
   **BeleidsIndicator-register** (39 codes). Als één van de
   schermen leeg blijft, draai `occ maintenance:repair` —
   de seeds worden door `BbvSeedService` idempotent geïmporteerd.

3. **Map de bestaande rekeningen.** Open **BBV → Programmaplan**
   en scroll naar het **BBV-mapping approval** panel. De
   `RgsAccountMapper` heeft per Account een kandidaat-RGS-decentraal
   code voorgesteld met een confidence-score. Behandel ze in
   afnemende volgorde:
   - 100 (exact `referentienummer`) — bevestig in batch.
   - 95 (exact `rgsDecentraalCode`) — bevestig na visuele
     check.
   - 80 (exact `rgsCode`) — bevestig per regel.
   - 70-80 (fuzzy naam) — bevestig of corrigeer handmatig.

4. **Maak de Programma's.** Voor elke programmanummer (typisch 1
   t/m 9 voor een middelgrote gemeente):
   1. **+ Programma toevoegen**.
   2. Vul nummer, naam, portefeuillehouder.
   3. Voeg de taakvelden toe die onder het programma vallen.
   4. Voeg minimaal één doelstelling toe (wat, wanneer, kpi).
   5. Kies beleidsindicatoren uit de 39 gefixeerde set.
   6. Bewaar als `draft`.

5. **Vul de meerjarenraming.** Open **BBV → Meerjarenraming** en
   maak per Programma × Taakveld × Economische Categorie de
   bedragen aan voor T, T+1, T+2 en T+3 (in cents). Gebruik
   **Bulkbewerking → Inflatie toepassen** voor een snelle
   prijscompensatie op T+1..T+3.

6. **Publiceer.** Terug naar Programmaplan, kies een Programma en
   klik **Publiceren**. De `BbvComplianceGuard::meerjarenraming_sluitend_ok`
   precondition draait per horizon (T..T+3). Als één jaar
   `saldo < 0`:
   - **Optie A:** corrigeer de meerjarenraming.
   - **Optie B:** vul `raadsbesluit_nummer` + `raadsbesluit_datum`
     in en publiceer met override.

7. **Vul de zeven paragrafen.** Open **BBV → Paragrafen** en
   loop de cards af:
   - **Weerstandsvermogen** — auto-velden uit Reserves +
     risicobeheersing; voeg een narratief toe.
   - **Onderhoud kapitaalgoederen** — auto-velden uit MVA +
     voorzieningen categorie d.
   - **Financiering** — auto-velden uit treasury;
     rente-omslag en kasgeldlimiet zijn al ingevuld.
   - **Bedrijfsvoering**, **Verbonden partijen**,
     **Grondbeleid**, **Lokale heffingen** — narratief +
     specifieke velden per template.

8. **Zet rechtmatigheid op groen.** Open **BBV → Rechtmatigheid**
   en check (i) de tolerance-config, (ii) de posting-stempels
   (alle `compliant` of `afwijking_within_tolerance`), en
   (iii) de M&O-bevindingen. Genereer het concept-verklaring.

9. **Vaststel de jaarrekening.** Vanuit Decidesk wordt de
   jaarrekening (Programmaplan + Paragrafen + concept-verklaring
   + iv3-export) als agendapunt aan de gemeenteraad voorgelegd.
   Bij vaststelling vuurt de
   `BbvComplianceGuard::paragrafen_compleet_ok` precondition;
   ontbrekende paragrafen worden expliciet genoemd.

10. **Iv3-aanlevering naar CBS.** Open **BBV → Iv3-aanlevering**.
    De kwartaal-export draait scheduled (1 maand na het kwartaal)
    via de `Iv3 quarterly CBS` ScheduledWorkflow. Voor een
    handmatige push gebruik de **Export → Genereer XBRL**
    actie, controleer de taxonomy-validatie en submit via Kredo.

## Verification

- Het Programmaplan toont elk programma met `versie = begroting`.
- De Meerjarenraming index toont `saldo ≥ 0` voor T..T+3.
- De zeven Paragraaf-cards staan op 100% (groen badge).
- Het BBV-compliance-dashboard toont geen rode programma's.
- `occ maintenance:repair --dry-run` toont
  `InitializeBbvAdministration` als no-op (idempotent).
- De iv3-export passeert XBRL-taxonomy-validatie.

## Pitfalls

- **Reservemutatie geweigerd?** Re-post op taakveld 0.10 — de
  programma-uitgave en de resultaatbestemming zijn twee
  aparte journaalposten (REQ-BBV-004).
- **MVA-investering boven activeringsgrens direct in de
  exploitatie?** Maak eerst de `MaterieleVasteActiva` aan en
  rebook tegen het activa-account; de maandelijkse
  afschrijving doet de rest (REQ-BBV-005).
- **Paragraaf-completeness blokkeert vaststelling?** Vergeet
  niet de paragraaf zelf op `vastgesteld` te zetten — een
  paragraaf in `draft` telt niet mee voor de gate.
