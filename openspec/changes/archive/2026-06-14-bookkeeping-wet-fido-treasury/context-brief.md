---
status: draft
---
# bookkeeping-wet-fido-treasury — Wet Fido & Treasurystatuut Limieten

## Purpose

Implement compliance with the Wet Financiering Decentrale Overheden (Wet Fido) and the local Treasurystatuut for Dutch gemeenten, provincies, waterschappen, en gemeenschappelijke regelingen. Wet Fido governs how decentralised governments may finance themselves and invest surplus liquidity, with the explicit aim of preventing reckless treasury activity that historically produced large losses (Vestia, Amarantis-era derivatives crises). The law imposes two hard quantitative limits — the kasgeldlimiet on short-term debt and the rente-risiconorm on long-term debt — and a mandatory scheme — schatkistbankieren — for parking surplus cash with the central treasury.

This capability operationalises those statutory limits and the locally-adopted Treasurystatuut inside shillinq so that every short-term loan, long-term emission, derivative, or investment is automatically tested against both the legal ceilings and the organisation's own internal policy. The module produces the verplichte kwartaalrapportage to the toezichthouder and the begrotingsmemorie's treasury paragraph, and it enforces real-time guardrails on the treasury function so that breaches are detected before, not after, the period closes.

The Treasurystatuut, adopted by raad/staten/AB once per term, encodes the local risk appetite within the Wet Fido envelope: which counterparties are acceptable, which instruments may be used, which signing authorities apply, and which reporting cadence is required. shillinq treats the statuut as a versioned, machine-readable rule set that the treasury workflow consults on every transaction. Derivatives are restricted by RUDDO (Regeling uitzettingen en derivaten decentrale overheden) to a narrow set of hedging purposes, and the module enforces those restrictions by refusing to record speculative derivative entries.

## Data Model

Primary register: `bookkeeping-fido`. Schemas:

- **Treasurystatuut** — `version`, `organisationId`, `organisationType`, `adoptionDecision`, `adoptionDate`, `effectiveFrom`, `effectiveTo`, `status` (draft/adopted/superseded), `riskAppetite` (laag/midden/hoog within Wet Fido envelope), `signingMandates` (matrix of role × amount × instrument), `permittedInstruments` (lening/deposito/staatsobligatie/medeoverheidsobligatie/derivaat-hedging), `counterpartyAllowlist` (banks, medeoverheden, schatkist), `reportingCadence` (quarterly default, monthly possible).
- **KasgeldLimiet** — `auditYear`, `organisationId`, `baseBegroting` (vastgestelde begroting per 1 januari), `percentage` (8.5% gemeente, lower for some categorieën), `calculatedCeiling`, `currentExposure` (rolling 3-month average of net short-term debt), `headroom`, `status` (binnen-norm/overschrijding-1-kwartaal/overschrijding-2-kwartalen/sanering-verplicht). Recomputed daily.
- **RenteRisicoNorm** — `auditYear`, `organisationId`, `baseVasteSchuld` (uitstaande vaste schuld per 1 januari), `percentage` (20%), `calculatedCeiling`, `forwardLooking4Year` (per-jaar herfinanciering + renteherziening), `currentExposurePerYear`, `headroomPerYear`, `status`. Recomputed on every long-term loan event.
- **SchatkistbankierenSaldo** — `organisationId`, `drempelbedrag` (0.75% of begrotingstotaal, min €1m, max €1bn), `currentRekeningCourant`, `daysAboveDrempel`, `parkedAtSchatkist`, `lastSweepAt`. Automated daily sweep target.
- **Lening** — `id`, `counterparty`, `type` (kasgeld/onderhandse-lening/obligatie/MTN/EMTN), `principal`, `currency`, `rate` (fixed/floating + spread), `issueDate`, `maturityDate`, `repaymentSchedule` (lineair/annuïtair/bullet), `renteherzieningsmoment`, `signingMandate` (FK), `Treasurystatuut` (FK), `purpose`, `BBVPaspoort`.
- **Derivaat** — `id`, `type` (IRS/cap/floor/collar), `notional`, `hedgedExposure` (FK to Lening or KasgeldPositie), `inceptionDate`, `maturityDate`, `marketValue` (mark-to-market), `RUDDOJustification`, `counterpartyRating`. Refused on save unless RUDDOJustification passes the hedging-only test.
- **QuartaalrapportageFido** — `auditYear`, `kwartaal`, `kasgeldStatus` (snapshot), `renteRisicoStatus` (snapshot), `schatkistStatus`, `leningenMutaties`, `derivatenMutaties`, `narrative`, `signOff` (treasurer + concerncontroller), `submittedToToezichthouder`, `submissionReceipt`.
- **TreasuryParagraaf** — `auditYear`, `begrotingVersion`, `narrative` (BBV-verplicht), `kasgeldProjectie`, `renteRisicoProjectie`, `liquiditeitsplanning`, `schatkistVerwachting`. Generated for the programmabegroting.

Cross-register joins: `Programma` (programmabegroting), `Begroting` (bbv-compliance), `Grootboekpost` (general-ledger). All FKs OpenRegister UUID v7.

## Requirements

- **REQ-001** The system SHALL maintain exactly one adopted Treasurystatuut per organisation at any moment, and SHALL refuse any treasury transaction whose instrument, counterparty, or signing mandate is not permitted by the adopted statuut.
- **REQ-002** The system SHALL compute the KasgeldLimiet daily as `percentage × vastgesteldeBegroting`, where the percentage is the statutory value per organisation type (8.5% gemeente, 7% provincie, 23% waterschap, varying for GR), and SHALL recompute current exposure as the rolling 3-month average of net short-term debt per the Wet Fido definition.
- **REQ-003** The system SHALL raise an `overschrijding-1-kwartaal` alert when the kasgeldlimiet is exceeded in any single quarter, an `overschrijding-2-kwartalen` alert when exceeded in two consecutive quarters, and a `sanering-verplicht` blocker after three consecutive quarters, mirroring the statutory enforcement ladder that triggers mandatory consolidation to long-term debt.
- **REQ-004** The system SHALL compute the RenteRisicoNorm as `20% × uitstaande vaste schuld per 1 januari`, project the per-year herfinanciering plus renteherziening volumes for the next four years, and refuse to record a new lening whose forward-looking exposure would breach the norm in any of those four years.
- **REQ-005** The system SHALL sweep cash above the drempelbedrag to schatkistbankieren on every banking day, calculate the drempelbedrag as `max(0.75% × begrotingstotaal, €1,000,000)` capped at €1bn, and record the daily rekening-courant saldo plus the parked amount for audit purposes.
- **REQ-006** The system SHALL refuse to persist a Derivaat record unless the RUDDOJustification demonstrates a direct hedging relationship to an existing Lening or KasgeldPositie, the notional does not exceed the hedged exposure, and the counterparty rating meets the RUDDO-minimum (single-A long-term per the recognised agencies).
- **REQ-007** The system SHALL generate the verplichte QuartaalrapportageFido within 10 working days after each kwartaal end, requiring co-sign by treasurer and concerncontroller, and SHALL transmit the rapportage to the toezichthouder (provincie voor gemeente; ministerie BZK voor provincie/waterschap) with a digital receipt.
- **REQ-008** The system SHALL produce the TreasuryParagraaf as part of the programmabegroting and the jaarrekening, populated automatically from current limits, projected liquiditeit, and the statuut, leaving only narrative annotation to the controller.
- **REQ-009** The system SHALL validate every Lening against the Treasurystatuut's signingMandates (role × amount × instrument matrix) before accepting the record, and SHALL reject any attempt to record a lening without a captured signing mandate reference.
- **REQ-010** The system SHALL expose a read-only Treasury Dashboard widget for the management cockpit showing live kasgeld-headroom, rente-risico-headroom per future year, schatkist-saldo, and any open alerts, with drill-down to the underlying transactions.

### Behaviour examples

**GIVEN** a gemeente has a vastgestelde begroting van €200M per 1-1-2026 with kasgeldlimiet 8.5% (=€17M) **WHEN** the treasurer attempts to draw a kasgeldlening of €5M that would bring the rolling 3-month gemiddelde net short-term debt to €18M **THEN** the system records the lening but immediately raises an `overschrijding-1-kwartaal` alert and writes a quarterly entry that will appear in the next QuartaalrapportageFido to the provincie.

**GIVEN** a provincie's Treasurystatuut permits derivaten alleen voor hedging-doeleinden **WHEN** a treasurer attempts to record an IRS without linking it to an underlying Lening **THEN** the system refuses the save with a validation error citing RUDDO Article 2 and the local Treasurystatuut, and offers a list of unhedged Leningen as candidate linkages.

**GIVEN** a waterschap's rekening-courant saldo exceeds the drempelbedrag of €1.5M for the third consecutive banking day **WHEN** the daily sweep job runs **THEN** the system sweeps the excess to schatkistbankieren, records the SchatkistbankierenSaldo entry, and credits the rekening-courant entry in the grootboek with proper BBV-classificatie.

## Standards & Sources

- **Wet financiering decentrale overheden (Wet Fido)**, Stb. 2000/569 with amendments through Stb. 2024/121
- **Uitvoeringsregeling Financiering decentrale overheden** (Uitvoeringsregeling Fido)
- **Regeling uitzettingen en derivaten decentrale overheden (RUDDO)**, Stcrt. 2009/9657 with amendments
- **Wet schatkistbankieren decentrale overheden**, Stb. 2013/530
- **Regeling schatkistbankieren decentrale overheden**
- **Modelstatuut Treasury** (VNG / IPO / UvW), latest 2024 edition
- **Besluit begroting en verantwoording provincies en gemeenten** (BBV) — paragraaf financiering (BBV Article 13)
- **Notitie Rente** (Commissie BBV)
- **Notitie Treasury** (Commissie BBV)
- **Handreiking Treasury** (BZK / Min Fin)
- **EMU-saldo en EMU-schuld definities** (CBS / Wet Hof) — feeds the same exposure dataset

## Cross-app integration

- **Depends on** `bookkeeping-schatkistbankieren` — supplies the operational rekening-courant connection to Agentschap van de Generale Thesaurie.
- **Depends on** `bookkeeping-programmabegroting` — supplies the vastgestelde begroting that anchors all limietberekeningen.
- **Depends on** `bookkeeping-bbv-compliance` — supplies the BBV-paspoort metadata for treasury-grootboekposten.
- **Feeds** `bookkeeping-jaarrekening-publication` — TreasuryParagraaf is embedded in the published jaarrekening.
- **Feeds** `bookkeeping-bado-controleprotocol` — quarterly fido-rapportages are auditable artefacts within the BADO controleprotocol scope.
- **OpenConnector events** — `fido.kasgeld.breach`, `fido.renterisico.breach`, `fido.schatkist.sweep`, `fido.statuut.adopted`, `fido.lening.recorded`.
- **launchpad** — Treasury Dashboard widget reads via OR GraphQL only; no install-time dep on shillinq.

## Target users

- **Concerntreasurer / treasury-functionaris** — primary user, executes leningen, derivaten, sweeps; consumes the live dashboard. Owns the day-to-day liquiditeitspositie and is the first to see the kasgeldlimiet-headroom shrink.
- **Concerncontroller / financieel directeur** — co-signs quarterly fido-rapportages, owns the Treasurystatuut. Reports up to college / GS / DB on financiële weerbaarheid en treasury-risico's.
- **College van B&W / GS / DB** — adopts the Treasurystatuut voorstel; verzendt de quarterly rapportage; vertegenwoordigt de organisatie als ondertekenende partij bij leningovereenkomsten.
- **Raad/Staten/AB** — adopts the statuut via formal besluit; receives the quarterly fido-rapportage en kan kaderstellende moties indienen op risico-appetijt.
- **Toezichthouder (provincie voor gemeente; BZK voor provincie/waterschap)** — receives the quarterly rapportage, intervenes on persistent overschrijdingen, kan ingrijpen via aanwijzingen of (in extremis) artikel-12-status overwegen.
- **Externe accountant** — uses the fido-dataset to test compliance during the BADO-controle. Fido-overschrijdingen leiden tot rechtmatigheidsbevindingen die de tolerantietabel kunnen overschrijden.
- **Agentschap van de Generale Thesaurie** — counterparty for schatkistbankieren sweeps. Levert rente-vergoeding op geparkeerd saldo en kan kasleningen verstrekken aan medeoverheden.
- **Bankrelatie (huisbankier)** — counterparty voor de rekening-courant en voor onderhandse leningen onder de Treasurystatuut-toegestane kring.
- **CBS / DNB / Min Fin** — gebruiken aggregaat-fido-data voor EMU-schuld-rapportage en macro-economisch toezicht onder Wet Hof.

## Implementation notes

The Wet Fido capability is intentionally a real-time guardrail, not a periodic check. Every treasury-mutation hits the live limiet-engine before persistence, so a treasurer cannot draw a kasgeldlening that would breach the limiet without an explicit override-with-rationale recorded in the audit trail. The 3-month rolling average for kasgeld is computed on every transaction so the controller sees the trajectory toward the ceiling, not just a quarterly snapshot. The rente-risiconorm projection is recomputed on every long-term-lening event because adding or repaying a vaste lening changes the herfinancieringsprofiel for the next four years.

Schatkistbankieren is operationalised as a scheduled daily job that runs after end-of-day bankafschrift-verwerking, computes the headroom above drempelbedrag, and triggers the sweep via the OpenConnector koppeling met het Agentschap. The sweep is logged in the grootboek with the correct BBV-classificatie (uitzetting > 1 jaar of <= 1 jaar afhankelijk van looptijd-keuze), and the rente-vergoeding van het Agentschap wordt automatisch geboekt op de juiste rente-baten-taakveld.

Derivaten zijn standaard gedeactiveerd in the UI; alleen wanneer de Treasurystatuut expliciet derivaten als instrument toestaat en wanneer de gebruiker een treasury-functionaris-rol heeft met derivaten-mandaat, wordt het Derivaat-formulier zichtbaar. RUDDO-validatie is een hard refusal, niet een waarschuwing: een derivaat zonder hedging-relatie wordt nooit opgeslagen.

De kasgeldlimiet-berekening volgt nauwgezet de Wet Fido-definitie van "vlottende schuld": opgenomen kasgeldleningen, opnamen rekening-courant, schuld in rekening-courant met openbare lichamen, en overige korte schuld < 1 jaar. Daarvan worden de korte vorderingen afgetrokken voor de netto-positie. De rolling-3-maands-gemiddelde wordt per dag herrekend zodat de treasurer een gladde curve ziet in plaats van kwartaalsprongen. De staffel-percentages per organisatie-type worden bijgehouden in een aparte FidoNormcatalogus tabel zodat tussentijdse wetswijzigingen kunnen worden ingevoerd zonder code-deploy.

De rente-risiconorm-berekening combineert twee componenten: het renteherzieningsrisico (aandeel van de portefeuille met rente-herziening in een gegeven jaar) en het herfinancieringsrisico (aandeel met aflopende looptijd in een gegeven jaar). Beide samen mogen per jaar de 20%-norm op de stand-per-1-januari niet overschrijden. De forward-looking 4-jaars-projectie maakt het mogelijk om vandaag een lening te weigeren waarvan de herfinanciering pas over drie jaar zou knellen, wat consistent is met de geest van de norm (spreiding van rente-risico over de tijd).

De Treasurystatuut-signingMandates-matrix is bewust expliciet gemodelleerd in plaats van impliciet via roles, omdat treasury-mandaten organisatiespecifiek zijn (bijvoorbeeld: treasurer mag leningen tot €10M zelfstandig sluiten, daarboven met co-sign door directeur, daarboven met college-besluit). De matrix wordt door de raad/staten/AB vastgesteld als bijlage bij de statuut en wordt door de system afgedwongen op elke leningstransactie. Wijziging van de mandaten vereist een statuutswijziging met nieuw raadsbesluit; het is niet mogelijk om de matrix administratief aan te passen.

De koppeling met het Agentschap van de Generale Thesaurie loopt via een dedicated OpenConnector-source met certificaat-gebaseerde authenticatie. De daily sweep is idempotent: als hij twee keer per dag draait blijft het netto effect hetzelfde. De OpenConnector-source registreert ook de inkomende rente-vergoeding en eventuele kasleningen vanuit het Agentschap (medeoverheden kunnen ook korte leningen aangaan met het Agentschap onder specifieke voorwaarden).
