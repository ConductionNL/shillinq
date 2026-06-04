# Proposal: bookkeeping-programmabegroting

`kind: capability` per ADR-032 — the centre of mass is the **Programmabegroting** (budget documentation),
**Programma** (locally-chosen political structure) and **Taakveld** (BBV-mandated technical indeling) 
registers with unified lifecycle, sluitend-criterium enforcement, begrotingswijziging workflow, and 
exports (iv3-aanlevering to CBS, EMU-saldo to Wet Hof).

## Summary

Implement the **BBV-verplichte programmabegroting** (municipal/provincial budget code) as a first-class,
machine-readable artefact inside shillinq. The programmabegroting is the central political and financial
authorisation document that the gemeenteraad / provinciale staten / algemeen bestuur adopts for the 
upcoming begrotingsjaar; it operationalises the entire begrotingsproces (opstellen, behandelen, 
vaststellen, wijzigen) and produces both the political narrative document (programmabegroting voor de raad)
and the technical machine-readable artefact (iv3-aanlevering aan CBS, EMU-saldo voor Wet Hof).

This capability enforces the **sluitend-criterium** (structural and real balance) that determines the 
toezichtregime (repressief vs. preventief); implements the begrotingswijziging-workflow with verplichte
raadsbesluit; integrates upstream with `bookkeeping-bbv-compliance` (taakvelden, classificaties) and 
`bookkeeping-budget-forecast` (cijfers, projecties); and publishes the machine-readable JSON on 
OpenCatalogi for hergebruik.

## Motivation

The programmabegroting is the master financial authorisation document under BBV (Besluit begroting en 
verantwoording provincies en gemeenten). Every Dutch municipality, province, and waterboard must adopt 
it annually. Shillinq must operationalise the complete begrotingsproces and enforce the seven 
verplichte paragrafen (lokale heffingen, weerstandsvermogen, onderhoud kapitaalgoederen, financiering, 
bedrijfsvoering, verbonden partijen, grondbeleid), the meerjarenraming (4-year outlook), and the 
sluitend-criterium that the provinciale financieel toezichthouder uses to determine repressief vs. 
preventief toezicht.

The capability carries the original Shillinq budget-planning mission forward under the declarative T2 
envelope. Per the legacy competitor-features research (app_slug=shillinq), programmabegroting + 
meerjarenraming + taakvelden indeling is a top-tier customer-asked feature.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec (`bookkeeping-programmabegroting`); declares 9 new 
  registers (`Programmabegroting`, `Programma`, `Indicator`, `Taakveld`, `Investering`, `Reserve`, 
  `Voorziening`, `Paragraaf`, `Meerjarenraming`, `Begrotingswijziging`) with unified lifecycle, sluitend 
  validation, begrotingswijziging workflow, and export integrations; adds manifest navigation entries.
- [ ] Project: openregister — consumes existing `ScheduledWorkflow` for meerjarenraming recalculation and 
  sluitend-criterium evaluation (no new extension).
- [ ] Project: bookkeeping-bbv-compliance — no source changes; shillinq consumes the `BBVTaakveldCatalogus` 
  lookup.
- [ ] Project: bookkeeping-budget-forecast — no source changes; shillinq consumes forecast-projecties.

## Scope

### In Scope

- One new capability spec (`bookkeeping-programmabegroting`) — see the `specs/` folder.
- The `Programmabegroting` register with version, organisationId, organisationType, begrotingsjaar, 
  meerjarenHorizon, status (draft/in-behandeling/vastgesteld/superseded), vaststellingsBesluit link, 
  sluitendStructureel and sluitendReëel flags.
- The `Programma` register (locally-chosen political structure) with nummer, naam, portefeuillehouder, 
  doelstellingen, indicatoren, baten/lasten.
- The `Indicator` register capturing beleidsindicatoren per programma per jaar with eenheid, nulwaarde, 
  streefwaarde, realisatie, bron.
- The `Taakveld` register (BBV-mandated indeling) mirroring the taakveldcatalogus with baten/lasten 
  per taakveld per programma.
- The `Investering` (capital investment) register with bruto, dekking-type, afschrijvingstermijn, and 
  per-year kapitaallastenSchedule.
- The `Reserve` and `Voorziening` (provisions) registers per BBV Article 44.
- The `Paragraaf` register for the seven verplichte paragrafen with narrative and kerncijfers.
- The `Meerjarenraming` (4-year outlook) register with baten/lasten struktureel/incidenteel and 
  sluitend-flags per jaar.
- The `Begrotingswijziging` (budget amendment) register with delta-based mutaties workflow and raadsbesluit 
  linkage.
- Unified lifecycle: draft → in-behandeling → vastgesteld / superseded with raadsbesluit requirement for 
  vaststelling.
- Sluitend-criterium enforcement: automatic evaluation of struktureel (recurring lasten ≤ recurring baten) 
  and reëel (after nominale-ontwikkeling correction) balance; flags set on Programmabegroting.
- Toezichtregime determination (repressief/preventief/artikel-12) based on sluitend-flags and 4-year history.
- Begrotingswijziging workflow: immutable vastgestelde begroting + event-sourced wijzigingen; each wijziging 
  requires own raadsbesluit.
- Budget-overrun detection: prevent grootboekposten exceeding authorized lasten per programma without 
  vastgestelde wijziging.
- Export integrations: iv3-aanlevering to CBS (taakveld-aggregated, quarterly for realisatie/yearly for 
  begroting); EMU-saldo to Wet Hof; JSON export to OpenCatalogi.
- Audit trail: all transitions, wijzigingen, and sluitend-evaluations logged.

### Out of Scope

- Detailed paragraaf authoring UI (spec declares fields; UI iteration post-MVP).
- Multi-language paragraaf support (Dutch-only in T2; multi-language deferred to T5).
- Real-time sluitend-criterium updates (batch evaluated during begroting lifecycle transitions).
- Peppol BIS e-invoicing outbound for begroting documents (document distribution out of scope).
- Macro-economic modelling beyond EMU-saldo (stress-testing deferred to BI tools).
- Revenue forecasting automation (forecast figures supplied by `bookkeeping-budget-forecast`, not 
  computed here).

## Dependencies

- **Depends on:** `bookkeeping-bbv-compliance` (BBV taakveldcatalogus lookup), `bookkeeping-budget-forecast` 
  (forecast projecties).
- **Feeds:** `bookkeeping-wet-fido-treasury` (vastgestelde begroting is the basis for kasgeldlimiet and 
  rente-risiconorm).
- **Feeds:** `bookkeeping-jaarrekening-publication` (realisatiecijfers compared against begroting).
- **Feeds:** `bookkeeping-sisa-reporting` (programma-structuur cross-walked naar SiSa-regelingen).

## Standards & Sources

- **BBV** (Besluit begroting en verantwoording provincies en gemeenten), Stb. 2003/27 through Stb. 2024/198
- **Regeling vaststelling iv3-informatievoorschrift** (Min BZK)
- **Wet houdbare overheidsfinanciën (Wet Hof)**, Stb. 2013/530
- **Notitie Programma's en programmabegroting** (Commissie BBV)
- **Notitie Taakvelden** (Commissie BBV, 2024 update)
- **Beoordelingskader financieel toezicht** (IPO voor gemeenten; BZK voor provincies/waterschappen)
- **iv3-handleiding CBS**, latest editie

## Risks & Trade-offs

| Risk | Mitigation |
|---|---|
| BBV taakveldcatalogus not yet integrated | Spec declares taakveld fields; `bookkeeping-bbv-compliance` integration confirmed in opsx-ff discovery |
| Forecast figures not reliable | Meerjarenraming consumes `bookkeeping-budget-forecast` projecties; flagged as external dependency |
| Sluitend-criterium changes mid-year (BBV updates) | Spec references Commissie BBV notities; updates tracked as change requests post-vaststelling |
| EMU-saldo calculation deviates from Wet Hof | Spec captures definitions; validation against DNB guidance in implementation cycle |
| Paragraaf narrative becomes stale | Audit trail captures versioning; narratieve maintenance falls to operational process (raad review cycle) |
| Administraties have different organisationType (gemeente/provincie/waterschap) | Schema declares organisationType; toezichtregime determined per type per BBV rules |

## Open Questions

1. **OR's ScheduledWorkflow stability:** Does OR's `ScheduledWorkflow` primitive exist and support 
   recurring evaluation of sluitend-criterium and meerjarenraming recalculation? (Resolution in opsx-ff.)
2. **CBS iv3-koppelvlak:** Is the CBS iv3-portaal API documented and accessible for automated 
   aanlevering? (Resolution in T2 discovery / potential OpenConnector integration.)
3. **Toezichthouder export:** Does the provinciale financieel toezichthouder consume OpenCatalogi JSON 
   exports or require a separate XML/API feed? (Resolution per organisationType regional guidance.)
4. **Nomiale-ontwikkeling data:** Where does the jaarlijkse loon- en prijsindexatie (nominale 
   ontwikkeling) data for reëel-sluitend calculation come from? (Assumption: user-configured annually 
   per administration.)

## Rollback

This change is spec-only. Reverting removes the nine register declarations from `lib/Settings/shillinq_register.json` 
and the manifest entries, with zero runtime data migration.

## Implementation Notes

The programmabegroting capability deliberately decouples the two views over the begroting — 
**programma-georiënteerd** (politiek, lokaal) versus **taakveld-georiënteerd** (BBV-verplicht, 
vergelijkbaar) — by modelling beide as parallel views over dezelfde grondgegevens. De Taakveld-children 
van een Programma zijn the canonical brondata; the programma-aggregatie wordt automatisch berekend uit 
taakveld-sums. This prevents rounding drift and guarantees that the iv3-aanlevering exactly aligns 
with what the raad has adopted.

De begrotingswijziging-workflow uses event-sourcing: every wijziging is an independent delta-document 
with its own raadsbesluit; the current stand of the begroting is always `vastgestelde basis + Σ(vastgestelde 
wijzigingen)`. The audit-trail remains intact even when wijzigingen are reversed (terugdraaiing is itself 
a wijziging with negative delta).

De sluitend-beoordeling distinguishes struktureel (recurring lasten covered by recurring baten) and 
reëel (corrected for nominale development) because the provinciale toezichthouder weights both separately. 
Both flags are independently persisted so the raad sees which constraint binds.
