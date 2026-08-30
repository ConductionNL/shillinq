# Spec: bookkeeping-kor-kleine-ondernemersregeling

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + fiscal operations)
**Depends on:**
- `../add-shillinq-accounts-receivable-core/specs/bookkeeping-accounts-receivable-core/spec.md` (AR invoicing + KOR-variant factuur)
- `../add-shillinq-accounts-payable-core/specs/bookkeeping-accounts-payable-core/spec.md` (voorbelasting-aftrek)
- `../add-shillinq-vat-btw-filing/specs/bookkeeping-vat-btw-filing/spec.md` (VAT filing suspension)
- `../add-shillinq-zzp-tax-regime/specs/bookkeeping-zzp-tax-regime/spec.md` (tax-scenario advisory)

## ADDED Requirements

### REQ-KOR-001: KOR opt-in workflow with mandatory scenario-analysis and three-year lock-in confirmation

The system MUST provide an aanmeldstroom (registration flow) that:

1. **Historical omzet review** — Display omzet from the last two complete fiscal years.
2. **Current-year prognose** — Calculate a linear trend-based prognose for the current year based on
   year-to-date invoices (or allow manual input).
3. **Scenario-analysis with fiscal comparison** — Show three scenarios side-by-side:
   - **Current regime (regulier BTW)** — Annual btw-filing cost, voorbelasting aftrek, netto fiscal impact.
   - **KOR regime** — No filing, no voorbelasting, netto fiscal impact over the three-year lock-in.
   - **Edge cases** — If the ondernemer is close to the drempel (>80%), highlight the overschrijding risk.
4. **Three-year lock-in confirmation** — A MANDATORY checkbox:
   ```
   [ ] Ik begrijp dat ik tot [DD-MM-YYYY lockInEindDatum] geen wijziging kan aanvragen.
      Ik accepteer dat KOR fiscaal onomkeerbaar is voor drie jaar.
   ```
   The aanmelding MUST NOT proceed without this checkbox being explicitly checked.
5. **Pre-filled aanvraag generator** — Generate a summary document pre-filled with onderneming data
   (bedrijfsnaam, KvK-nummer, omzetprognose) that the ondernemer can download and submit to
   mijnbelastingdienst.nl/zakelijk. Shillinq does NOT submit on behalf of the ondernemer.

#### Scenario: ZZP-fotograaf with borderline omzet

- **GIVEN** a ZZP-fotograaf with omzet 2024: EUR 19.200, 2025: EUR 16.800, and H1-2026: EUR 9.300.
- **AND** voorbelasting last year: EUR 980 (apparatuur, software, autokosten).
- **WHEN** she initiates the KOR-aanmelding flow in June 2026.
- **THEN** the system MUST:
  - Show 2024 & 2025 omzet.
  - Prognose 2026: EUR 18.600 (linear trend from H1).
  - Scenario 1 (regulier): EUR 980 voorbelasting + EUR ~3.885 annual btw = EUR 2.905 netto cost/benefit.
  - Scenario 2 (KOR): EUR 0 voorbelasting (forgone) + EUR 0 btw = EUR 0, but LOCKED IN for 3 years.
  - Warning: "Your 2024 omzet (EUR 19.200) was close to the drempel. If your 2026 omzet tracks
    like 2024 (EUR 19.2k), you risk overschrijding in Q4. Consider a buffer."
  - Display the three-year lock-in checkbox and require explicit confirmation.
  - Generate a pre-filled aanvraag summary for download.

#### Scenario: Reviewer confirms scenario-analysis is MANDATORY, not optional

- **GIVEN** the Shillinq aanmeldstroom.
- **WHEN** a user tries to submit a KOR-aanmelding without checking the lock-in checkbox.
- **THEN** the system MUST reject with: "Bevestiging drie-jaars lock-in is verplicht."

### REQ-KOR-002: Realtime drempel-bewaking with monthly prognose

The system MUST recalculate the KOR drempel-benutting immediately after each AR invoice is posted that
qualifies for KOR:

1. **Lopende omzet = SUM of all KOR-eligible invoices in the current calendar year** where:
   - `ARInvoice.vrijstellingsGrondslag == "KOR_ART25_OB"` (KOR-eligible).
   - Delivery date (`leveringsDatum`) is in the current calendar year.
   - Status is NOT `draft` (issued or later).
   - Excluded: vrijgestelde prestaties (art. 11 OB), intracommunautaire leveringen, onroerend goed omzet.

2. **Drempel-benutting = lopende omzet / EUR 20.000**, displayed as a percentage.

3. **Monthly trend and end-of-year prognose:**
   - Compute the average omzet per calendar month (year-to-date).
   - Project the trend linearly to end-of-year: `prognose = (currentMonthAverage * remainingMonths) + lopende`.
   - Display on the dashboard: "Based on your current pace, you'll have EUR XX.XXX at year-end
     (YYY% of drempel)."

4. **Three excluded categories explicitly marked:**
   - Invoices with `vrijstellingsGrondslag != "KOR_ART25_OB"` are labeled "(niet meegeteld in KOR)".
   - Intracommunautaire levering (identified by customer country or invoice flag) marked "(0% VAT, niet KOR)".
   - Onroerend goed omzet (property rental, art. 11-1-b OB) marked "(exempt, niet KOR)".

#### Scenario: Kapper ziet drempel real-time meebewegen

- **GIVEN** a ZZP-kapper Maria with lopende omzet EUR 16.420 in August.
- **WHEN** she posts a factuur of EUR 200 on August 12, 2026 (leveringsDatum 2026-08-12).
- **THEN** the system MUST immediately recalculate:
  - Lopende omzet: EUR 16.620 (16.420 + 200).
  - Drempel-benutting: 83.1%.
  - Monthly average (8 months / EUR ~2.050 per month): Prognose EUR 24.600 (→ 123% by year-end).
  - Dashboard update (within 1 second of post): "Je hebt 83,1% van je drempel bereikt. Op dit tempo
    zou je in november overschrijd bereiken."

### REQ-KOR-003: Drempel-schijven (80% / 90% / 100%) with escalating alert-intensity

The system MUST monitor three alert thresholds and dispatch alerts per the schema below:

| Benutting | Alert Level | Trigger | Kanaal | Ernst | Aanbeveling |
|---|---|---|---|---|---|
| 80% ≤ benutting < 90% | VROEG | Once when crossing 80% | Email | Informatief | "Monitor je omzet" |
| 90% ≤ benutting < 100% | KRITIEK | Once when crossing 90% | Email, in-app, dashboard | Kritiek | "Overweeg opt-out of factureer strategisch" |
| benutting ≥ 100% | OVERSCHRIJDING | Immediately (REQ-KOR-004 revocatie-trigger) | Sync revocatie + email | Acuut | "Je KOR is per [date] beëindigd" |

Each `KORThresholdAlert` record MUST store:
- `registrationId` — FK to `KORRegistration`.
- `trigger` — `DREMPEL_80PCT`, `DREMPEL_90PCT`, or `DREMPEL_100PCT`.
- `uitgeloostOp` — Timestamp when alert was triggered.
- `omzetOpMoment` — Exact omzet value when threshold was crossed.
- `drempelBenutting` — Percentage at moment of trigger.
- `prognoseEindeJaar` — Projected year-end omzet.
- `ernst` — Severity: `VROEG`, `KRITIEK`, `OVERSCHRIJDING`.
- `aanbeveling` — Recommended action text.
- `kanaal` — Array: `["EMAIL", "IN_APP", "DASHBOARD"]` (depends on ernst).
- `bevestigdDoor` — FK to user who acknowledged (if in-app alert).
- `actieOndernomen` — Free-text log of any action taken (opt-out initiated, invoice deferred, etc.).

#### Scenario: Escalation from 90% to 100% triggers synchronous revocatie

- **GIVEN** Maria's drempel-benutting is 90.6% (EUR 18.120 / 20.000).
- **AND** an alert KRITIEK was dispatched on 2026-08-12 at 14:23.
- **WHEN** Maria posts another invoice for EUR 240 on 2026-09-04 (leveringsDatum 2026-09-04).
- **THEN** the system MUST:
  - Recalculate: lopende omzet EUR 20.360 (→ 101.8% benutting).
  - Trigger `DREMPEL_100PCT` alert immediately.
  - **SYNCHRONOUSLY call REQ-KOR-004 revocatie-flow** with `revocatieDatum = 2026-09-04`
    (the delivery date of the trigger invoice, NOT today, NOT month-end, NOT year-end).
  - Mark `KORRegistration.status = GEEINDIGD_OVERSCHRIJDING`.
  - Generate a `KORRevocation` record with `type = OVERSCHRIJDING`,
    `triggerFactuurId = fact-2026-0240`, `omzetOpMoment = 20.360`.
  - **Dispatch alert OVERSCHRIJDING** (synchronous in-app + email): "Je KOR is per 4 september 2026 beëindigd
    wegens drempel-overschrijding. Vanaf deze datum factureer je met 21% btw. De volgende kwartaalopgave
    verplicht suppletie."

### REQ-KOR-004: Automatische revocatie bij overschrijding (mid-year, not year-end)

When `KORAnnualTurnover.drempelBenutting > 1.0` (i.e., omzet > EUR 20.000), the system MUST:

1. **Create a `KORRevocation` record** with:
   - `type = OVERSCHRIJDING`.
   - `revocatieDatum = leveringsDatum` of the invoice that caused the overschrijding (NOT postingDatum,
     NOT end-of-month, NOT end-of-year).
   - `triggerFactuurId = ARInvoice.id` that crossed the threshold.
   - `omzetOpMoment = exact omzet` at the moment of overschrijding.

2. **Transition `KORRegistration.status`** from `ACTIEF` to `GEEINDIGD_OVERSCHRIJDING`.

3. **Automatically re-mark all invoices posted AFTER `revocatieDatum`** in the current year:
   - Change `vrijstellingsGrondslag` from `"KOR_ART25_OB"` to `"REGULIER_21PCT_VAT"` or equivalent.
   - Add `btwTarief = 21%` and recalculate `btwBedrag`.
   - Update `vermeldingOpFactuur` to standard VAT vermelding (NOT KOR-artikel 25).

4. **Calculate suppletie-bedrag:**
   - Identify all KOR-facturen (with `leveringsDatum` between `ingangsDatum` and `revocatieDatum - 1 day`).
   - For each such invoice: `btwBedrag_if_regulier = bedrag * 0.21 / 1.21` (NL VAT 21%).
   - Sum these up: `btwSuppletieBedrag = Σ(btwBedrag_if_regulier)`.
   - Store in `KORRevocation.btwSuppletieBedrag`.

5. **Calculate herrekenrange:**
   - `herrekeningRange.van = revocatieDatum`.
   - `herrekeningRange.tot = 2026-12-31` (or current fiscal year end).
   - This range is the period where VAT MUST be recalculated (was KOR, now regulier).

6. **Set blokkade-heraanmelding:**
   - `blokkadeHeraanmelding = (revocatieDatum + 3 years)` as a calendar date, e.g., `2029-09-04`.
   - The ondernemer CANNOT re-apply for KOR until after this date (per Belastingdienst rules).

7. **Record belastingdienst-notificatie intent** (not automatic submission, but flag):
   - `belastingdienstNotificatie.verzonden = true` (set immediately).
   - `belastingdienstNotificatie.verzondenOp = <timestamp>`.
   - `belastingdienstNotificatie.bevestigingsnummer = null` (assigned later if/when Shillinq submits via webservice).
   - Note: The actual submission to Belastingdienst is the ondernemer's responsibility (via
     mijnbelastingdienst.nl quarterly filing).

#### Scenario: Webshop overschrijdt in September

- **GIVEN** a webshop with KOR status ACTIEF, lopende omzet EUR 19.840 on 2026-09-03.
- **WHEN** a customer order for EUR 412 is posted on 2026-09-04 (leveringsDatum 2026-09-04).
- **THEN** the system MUST:
  - Detect overschrijding: EUR 19.840 + EUR 412 = EUR 20.252 (100.26% benutting).
  - Create `KORRevocation` record: `type=OVERSCHRIJDING`, `revocatieDatum=2026-09-04`,
    `triggerFactuurId=<order-id>`, `omzetOpMoment=20.252`, `btwSuppletieBedrag=<calculated>`.
  - Immediately re-mark this invoice (and ANY other invoices with `leveringsDatum ≥ 2026-09-04`)
    with `vrijstellingsGrondslag = REGULIER_21PCT_VAT`, `btwTarief=21%`.
  - Set `KORRegistration.status = GEEINDIGD_OVERSCHRIJDING`.
  - Set `blokkadeHeraanmelding = 2029-09-04`.
  - Display in-app notification: "Je KOR is per 4 september 2026 beëindigd. Vanaf nu factureer je
    met 21% btw. Je mag weer voorbelasting aftrekken vanaf deze datum. Suppletie: EUR XXXX voor
    de periode 4 september – 31 december."

### REQ-KOR-005: KOR-factuur met verplichte artikel 25-vermelding

All invoices issued during an ACTIEF KOR period MUST carry:

1. **No BTW fields:**
   - `btwTarief = null` (NOT "0%", NOT "exempt").
   - `btwBedrag = 0` (calculated as 0, not omitted).

2. **Mandatory `vermeldingOpFactuur` text** (verbatim per Wet OB artikel 35a lid 1 onder n):
   ```
   Vrijgesteld van btw op grond van artikel 25 Wet op de omzetbelasting 1968
   (Kleine Ondernemersregeling).
   ```
   This text MUST appear on every KOR-factuur, in Dutch, EXACTLY as above (or exact translation
   in other languages per i18n ADR-007). It CANNOT be replaced by:
   - "0% VAT" (incorrect — 0% is a tariff, KOR is an exemption).
   - "No VAT" (imprecise).
   - "Exempt" (insufficient — must reference artikel 25).

3. **Invoices AFTER revocatieDatum (REQ-KOR-004)** MUST revert to standard VAT vermelding:
   ```
   VAT 21% charged: EUR X.XX [or equivalent national text]
   ```

4. **The AR invoice-render system** MUST enforce this at print/export time:
   - Template checks: if `ARInvoice.vrijstellingsGrondslag == "KOR_ART25_OB"`, render the KOR-vermelding.
   - If `vrijstellingsGrondslag != "KOR_ART25_OB"`, render standard VAT lines.
   - No manual override is permitted; the vermelding is system-enforced, not user-edited.

#### Scenario: Factuur-template enforces vermelding

- **GIVEN** a KOR-invoice for a ZZP-kapper, amount EUR 32.50, issued 2026-08-12.
- **WHEN** the invoice is rendered to PDF or sent via email.
- **THEN** the PDF MUST show:
  ```
  Knipbeurt + wassen                              €32,50
  
  Subtotaal                                       €32,50
  VAT (0%)                                        €0,00
  TOTAAL                                          €32,50
  
  Vrijgesteld van btw op grond van artikel 25 Wet op de omzetbelasting 1968
  (Kleine Ondernemersregeling).
  ```
  The vermelding MUST be present and unmodifiable by the user.

### REQ-KOR-006: Voorbelasting-aftrek blokkade during ACTIEF KOR

When a `KORRegistration` transitions to `status = ACTIEF` (at `ingangsDatum`), the system MUST:

1. **Listen to the `kor.registration.activated` event** and notify the AP system
   (bookkeeping-accounts-payable-core).

2. **Force all NEW invoices** posted during the ACTIEF period to have:
   - `APInvoice.voorbelastingAftrekBaar = false`.
   - `btwBedrag = 0` (not claimable, even if the invoice has a standard VAT line).
   - The grosso amount (including VAT) is booked as a cost (no VAT recovery).

3. **Do NOT retroactively block** existing invoices posted before KOR activation.

4. **Upon revocatie (REQ-KOR-004)**, when `status` transitions from `ACTIEF` to `GEEINDIGD_*`:
   - Re-enable voorbelasting-aftrek for all **new** invoices posted after `revocatieDatum`.
   - Apply herzieningsregels (art. 13 Uitvoeringsbeschikking OB):
     - For fixed assets (investeringsgoederen) purchased DURING KOR with remaining useful life > remaining
       years: calculate proportional voorbelasting recovery (10 years for real estate, 5 years for equipment).
     - Example: A laptop bought in June 2026 for EUR 800 (incl VAT EUR 139) during KOR, with a 5-year
       useful life. If KOR ends September 2026 (3 months used of 60 months), the ondernemer can recover
       57/60 of the EUR 139 voorbelasting ≈ EUR 132.

5. **The blocking is AUTOMATIC, not user-toggleable:**
   - No "bypass" button.
   - No "override voorbelasting" checkbox.
   - The system enforces this at the ledger level.

#### Scenario: Kapper buys shampoo during KOR, cannot claim VAT

- **GIVEN** a KER-actief ondernemer (status = ACTIEF).
- **WHEN** she books an inkoopfactuur for EUR 150 shampoo (incl. VAT EUR 26) on 2026-08-15.
- **THEN** the system MUST:
  - Set `voorbelastingAftrekBaar = false`.
  - Book EUR 150 (gross) to "Supplies" cost account.
  - Display a tag: "(Geen voorbelasting aftrek vanwege KOR)".
  - If the invoice was manually entered with a VAT-tariff, the system MUST override and set `btwTarief = null`.

#### Scenario: After revocatie, she can reclaim VAT

- **GIVEN** KOR revocatie on 2026-09-04 (new status = GEEINDIGD_OVERSCHRIJDING).
- **WHEN** she books a new inkoopfactuur for EUR 150 shampoo (incl. VAT EUR 26) on 2026-09-10.
- **THEN** the system MUST:
  - Set `voorbelastingAftrekBaar = true`.
  - Book EUR 129.75 (net) + EUR 26 voorbelasting separately.
  - Display tag: "(Voorbelasting aftrek geldig na KOR-revocatie)"

### REQ-KOR-007: Drie-jaars lock-in handhaving en opt-out workflow na afloop

1. **Lock-in enforcement:**
   - A `KORRegistration` with `status = ACTIEF` CANNOT transition out of ACTIEF before
     `lockInEindDatum`, EXCEPT in the following cases:
     - `ondernemingDeceased() == true` (ondernemer is deceased, documented via
       onderneming-lifecycle event).
     - `ondernemingDissolved() == true` (KvK uitschrijving or dissolution announcement).
     - `ondernemingBankrupt() == true` (faillissement announced by Belastingdienst).
     - **Overschrijding (REQ-KOR-004)** — automatic forced revocatie (allowed exception).
   - Any opt-out attempt before `lockInEindDatum` (outside the above exceptions) MUST be rejected with:
     ```
     "Je KOR loopt nog tot [DD-MM-YYYY lockInEindDatum]. Eerste opzegmogelijkheid: [DD-MM-YYYY vroegsteOpzegDatum]
      voor ingang per 1-1-[year after lockInEindDatum]."
     ```

2. **Opt-out workflow (AFTER lock-in ends):**
   - Accessible from `vroegsteOpzegDatum` (3 months before `lockInEindDatum`).
   - The ondernemer can submit an opt-out request: "Ik wil uit de KOR uittreden."
   - Opt-out becomes effective on the **next calendar year boundary** (1-1) after submission.
   - Example: If `lockInEindDatum = 2028-12-31` and `vroegsteOpzegDatum = 2028-10-01`, the ondernemer
     can opt out on 2028-10-15, effective 2029-01-01.
   - Upon approval, create a new `KORRevocation` record with `type = VRIJWILLIG_NA_LOCKOUT`,
     `revocatieDatum = 2029-01-01`.

3. **Alternatives offered (not enforced):**
   - "Pre-invoicing strategy" — If the ondernemer is approaching the drempel but wants to stay in KOR,
     she can invoice in December for January deliveries (move revenue forward by one day).
   - "Voluntary early revocatie" — The ondernemer can choose to voluntarily end KOR early by invoking
     REQ-KOR-004 (revocatie) on demand. This is NOT recommended (lost KOR status for remainder of the
     year) but technically allowed. The system MUST surface: "Revocation is final and irreversible
     this calendar year. Are you sure?"

#### Scenario: ZZP'er wants to opt out early (rejected)

- **GIVEN** a ZZP'er with `KORRegistration.lockInEindDatum = 2028-12-31`,
  `vroegsteOpzegDatum = 2028-10-01`.
- **WHEN** she tries to opt out in June 2027.
- **THEN** the system MUST reject:
  ```
  "Je KOR loopt nog tot 31-12-2028. Eerste opzegmogelijkheid: 1 oktober 2028 voor ingang per 1-1-2029."
  ```
  Offer alternatives: pre-invoice, or stay in KOR.

#### Scenario: ZZP'er opts out after lock-in (approved)

- **GIVEN** the same ZZP'er, now on 2028-10-15 (after `vroegsteOpzegDatum`).
- **WHEN** she requests opt-out.
- **THEN** the system MUST:
  - Accept the opt-out request.
  - Display: "Je opt-out wordt effectief per 1 januari 2029. Tot 31 december 2028 blijf je in KOR."
  - Create a `KORRevocation` record: `type = VRIJWILLIG_NA_LOCKOUT`, `revocatieDatum = 2029-01-01`.
  - Transition `KORRegistration.status` to `GEEINDIGD_VRIJWILLIG` (effective 2029-01-01).

### REQ-KOR-008: KOR-EU registratie en EX-nummer beheer

For KOR-EU regime (per Wet implementatie Richtlijn (EU) 2020/285, effective 1-1-2025):

1. **KOR-EU aanmelding alternative path:**
   - Separate registration route in the aanmeldstroom.
   - Ondernemer specifies: lidstaten where he has customers, expected annual omzet per lidstaat.
   - System shows per-lidstaat KOR-drempels (BE €25.000, DE €22.000, FR €85.000, NL €20.000, etc.).
   - Combined EU drempel: EUR 100.000 total.

2. **EX-nummer assignment:**
   - Ondernemer must request EX-nummer from Belastingdienst KOR-EU portal (mijnbelastingdienst.nl/korus).
   - Shillinq stores the assigned EX-nummer (e.g., `EX-NL-2026-019234`) in `KOREUTurnover.exNummer`.
   - Auto-assignment via Belastingdienst webservice is out of scope (future SBR integration).

3. **Per-lidstaat drempel monitoring:**
   - Same drempel-monitoring logic as KOR-NL (REQ-KOR-002), but per EU member state.
   - `KOREUTurnover.perLidstaat` structure:
     ```json
     {
       "BE": {"omzet": 12400.00, "drempelBE": 25000, "benutting": 0.496},
       "DE": {"omzet": 8200.00, "drempelDE": 22000, "benutting": 0.372},
       "NL": {"omzet": 18100.00, "drempelNL": 20000, "benutting": 0.905}
     }
     ```
   - Each lidstaat has its own drempel; exceeding ONE lidstaat's drempel revokes KOR-EU for that
     lidstaat (but not others, per EU rules).

4. **Kwartaalopgaaf (Q1/Q2/Q3/Q4):**
   - Per kwartaal, the system MUST prepare a `kwartaalopgaaf` (quarterly declaration) containing:
     - Total EU omzet.
     - Omzet breakdown per lidstaat.
     - Cumulative omzet for the year to date.
     - Indication of any per-lidstaat drempel breaches.
   - Status tracking:
     - `OPEN` — Not yet prepared.
     - `DRAFT` — Data prepared, awaiting ondernemer review.
     - `INGEDIEND` — Submitted to Belastingdienst (manual upload via mijnbelastingdienst.nl).
   - NO automatic submission to Belastingdienst; ondernemer responsible for filing.

5. **KOR-EU factuur vermelding:**
   - All invoices under KOR-EU MUST carry (English, per EU standard):
     ```
     Exempt from VAT pursuant to special scheme for small enterprises
     (Article 284 VAT Directive 2006/112/EC).
     ```
   - This is the English translation; Dutch variant:
     ```
     Vrijgesteld van btw op grond van de speciale regeling voor kleine ondernemingen
     (artikel 284 VAT-richtlijn 2006/112/EG).
     ```

#### Scenario: Small cross-border webshop with KOR-EU

- **GIVEN** a webshop selling handmade goods to Belgium, Germany, and Netherlands.
  - Expected omzet 2026: NL €18.100, BE €12.400, DE €8.200 (total €38.700, well below EU €100k).
  - Applies for KOR-EU on 1-2-2026, receives `EX-NL-2026-019234`.
- **WHEN** mid-year, she has invoiced €10.000 NL, €6.500 BE, €4.200 DE.
- **THEN** the system MUST:
  - Show drempel-benutting: NL 50% (10k/20k), BE 26% (6.5k/25k), DE 19% (4.2k/22k), EU 11% (20.7k/100k).
  - Prepare Q2 kwartaalopgaaf (1 Apr – 30 Jun): total €20.700 YTD, breakdown by lidstaat.
  - Q2 status: `DRAFT` (awaiting ondernemer review + manual filing to Belastingdienst).
  - If in Q4 NL omzet reaches €21.000 (exceed NL drempel of €20.000):
    - KOR-EU is revoked for NL (same revocatie logic as REQ-KOR-004).
    - KOR-EU remains ACTIEF for BE and DE (if they stay under their drempels).

### REQ-KOR-009: Jaarlijkse omzetopgaaf en eindafrekening

At end of each calendar year (31-12), the system MUST:

1. **Finalize annual omzet:**
   - Lock `KORAnnualTurnover.lopendeOmzet` to the definitive year-end total.
   - Calculate final `drempelBenutting = lopendeOmzet / 20000` (or per-lidstaat for KOR-EU).

2. **Generate a year-end report** for the ondernemer:
   - Title: "KOR Jaarlijkse Omzet Verantwoording [YYYY]"
   - Sections:
     - "Omzet per maand" (tabular breakdown of invoices by month).
     - "Totale lopende omzet: EUR X.XXX"
     - "Drempel: EUR 20.000"
     - "Drempel-benutting: YYY%"
     - "Conclusie: KOR-drempel NOT exceeded" (or "EXCEEDED if overschrijding).
     - "Vergelijking met vorige jaren": 3-year trend (YYYY-2, YYYY-1, YYYY).
     - Recommendation: "Consider whether KOR remains optimal for next year based on 3-year trend."

3. **For KOR-EU, prepare jaarlijkse eindopgaaf:**
   - Cumulative omzet per lidstaat for the full year.
   - Confirmation of Q1/Q2/Q3/Q4 kwartaalopgaaf submissions.
   - Mark status: `VOORBEREIDING_EINDOPGAAF` (awaiting ondernemer to finalize and file).

4. **Preserve data for audit trail:**
   - All historical `KORAnnualTurnover`, `KORThresholdAlert`, `KORRevocation` records are archived
     and immutable per ADR-031 audit-trail contract.

#### Scenario: Year-end report confirms drempel not breached

- **GIVEN** a ZZP'er with KOR-registration, omzet 2026: EUR 19.850 (under EUR 20.000 drempel).
- **WHEN** 31-12-2026 arrives.
- **THEN** the system MUST:
  - Finalize `KORAnnualTurnover.lopendeOmzet = 19850`, `drempelBenutting = 0.9925 (99.25%)`.
  - Generate report showing monthly omzet, final total, and 3-year trend.
  - Recommendation: "You stayed under the drempel all three years. KOR continues to be optimal
    for your tax position. Your lock-in ends on 31-12-2028; you may opt out from 1-10-2028."
  - Archive all records per audit-trail.

### REQ-KOR-010: Drempelbeoordeling vooraf en branche-specifieke uitsluitingen

BEFORE allowing a KOR-aanmelding to proceed, the system MUST perform a branche-driven compatibility check:

1. **Detect branche/sector** from the ondernemer's primaire activiteit (KvK activiteitscode or self-reported).

2. **Check for full-exemption conflicts:**
   - If the ondernemer's prestaties are fully covered by existing VAT exemptions (art. 11 OB),
     KOR offers NO benefit. Flag and advise:
     ```
     "Je prestaties zijn al volledig btw-vrijgesteld op grond van [artikel-referentie].
      KOR biedt geen fiscale voordeel en zal je voorbelastingaftrek juist blokkeren.
      We raden aan af te zien van KOR-aanmelding."
     ```

3. **Mixed-use detection:**
   - If the ondernemer combines vrijgestelde + belaste prestaties, calculate the KOR-benefit
     ONLY on the belaste portion.
   - Example: Yoga instructor with 80% lessons (art. 11-1-o, vrijgesteld) + 20% merchandise (belast):
     - Only the 20% (merchandise) benefits from KOR.
     - KOR drempel for this mixed-use ondernemer: EUR 20.000 * 0.20 = EUR 4.000 effective drempel
       on belaste omzet alone.
     - BUT, voorbelasting-blokkade applies to ALL purchases (not proportional).
     - Net effect: KOR may NOT be beneficial if merchandise omzet < EUR 4.000.
   - System advises: "Your merchandise turnover (EUR X.XXX) is below the adjusted KOR drempel.
     Consider declining KOR to recover VAT on all purchases."

4. **Intracommunautaire prestaties check:**
   - If the ondernemer has significant intracommunautaire supplies (leveringen naar andere EU-landen
     under normale VAT, not KOR-EU), KOR is incompatible with OSS-regime.
   - Advise: "Your cross-border supplies conflict with KOR. Consider OSS-regime instead."

5. **Fiscale-eenheid check:**
   - If the ondernemer's KvK-number is part of a fiscale eenheid (multiple entities consolidated for
     VAT), the ondernemer CANNOT apply for KOR individually.
   - Block aanmelding: "Je bent onderdeel van een fiscale eenheid. Alleen de fiscale eenheid als geheel
     kan KOR aanvragen. Raadpleeg je belastingadviseur."

#### Scenario: Onroerend goed verhuurder probeert KOR

- **GIVEN** an ondernemer renting residential property (art. 11-1-b OB, fully exempt).
- **WHEN** he initiates KOR-aanmelding.
- **THEN** the system MUST:
  - Detect: "Primaire activiteit: Verhuur woonruimte (art. 11-1-b OB, volledig vrijgesteld)."
  - Display warning:
    ```
    "Jouw inkomstenstromen zijn al volledig btw-vrijgesteld. KOR biedt geen btw-voordeel
     maar zal je voorbelastingaftrek voor onderhoudskosten blokkeren. Je verliest EUR X.XXX
     per jaar in teruggang voorbelasting. We raden sterk af om KOR aan te nemen."
    ```
  - Offer: Cancel aanmelding, or "I understand and proceed anyway" (with stronger confirmation).

### REQ-KOR-011: Transitie regulier ↔ KOR

#### Sub-REQ-KOR-011a: Regulier → KOR (at ingangsDatum)

When `KORRegistration.ingangsDatum` arrives (typically 1-1 of next year), the system MUST:

1. **Calculate voorraad-correctie** per herzieningsregels (art. 13 Uitvoeringsbeschikking OB):
   - For all fixed assets (investeringsgoederen) acquired in the 5-year window prior to ingangsDatum
     (10 years for real estate) that are still in use:
   - Proportional adjustment: 
     ```
     correctie = asset_value * (years_remaining_of_useful_life / total_useful_life)
     ```
   - Example: A laptop bought 3 years ago (5-year life remaining), marked to VAT on purchase:
     - Remaining life at transition: 2 years.
     - Correctie: laptop_value * (2 / 5) of the original voorbelasting.
     - This amount must be ADDED BACK to taxable income (suppress the voorbelasting benefit).

2. **Prepare a suppletie-aangifte** (correction statement) for the transition month.
   - Include all voorraad-correctie adjustments.
   - Reference artikel 13 Uitvoeringsbeschikking OB.

3. **Mark the transition in the audit trail** with: "Transitioned from Regulier VAT to KOR effective
   [ingangsDatum]."

#### Sub-REQ-KOR-011b: KOR → Regulier (at revocatieDatum)

When `KORRegistration` is revoked (REQ-KOR-004, REQ-KOR-007), the system MUST:

1. **Re-enable voorbelasting-aftrek** for all new invoices posted after `revocatieDatum`.

2. **Apply herzieningsregels** for assets still in use that were purchased DURING KOR:
   - These purchases had NO voorbelasting claimed (blocked by REQ-KOR-006).
   - Upon transition back to Regulier, the ondernemer can now reclaim voorbelasting for the
     **remaining useful life** of the asset.
   - Example: A laptop bought 6 months before revocatie (EUR 800 incl VAT EUR 139), with 5-year life:
     - Remaining life after revocatie: 4.5 years out of 5.
     - Recoverable voorbelasting: EUR 139 * (4.5 / 5) ≈ EUR 125.

3. **Prepare a retrospective voorbelasting-credit aangifte** for the first VAT-filing period after revocatie.
   - Include all retrospective voorbelasting-aftrek claims per herzieningsregels.

4. **Notify the klant(e) if necessary:**
   - If invoices were issued under KOR (no VAT) and are now subject to re-issuance with VAT,
     send a courtesy notice: "Your KOR has ended; future invoices will include 21% VAT.
     Previously issued KOR-invoices remain valid and do not require correction."

#### Scenario: ZZP'er buys equipment, KOR starts, then revocatie happens

- **GIVEN** a ZZP-fotografer buys professional camera on 15-12-2025 (EUR 800 incl VAT EUR 139)
  while still under Regulier VAT.
- **AND** KOR ingangsDatum = 1-1-2026. Voorraad-correctie: EUR 139 * (4.75 / 5) ≈ EUR 132
  (remaining useful life 4.75 years of 5-year life).
- **AND** on 4-9-2026, she overschrijdt the KOR drempel (revocatieDatum 4-9-2026).
- **THEN** the system MUST:
  - At 1-1-2026 transition: Record suppletie-adjustment (subtract EUR 132 voorbelasting benefit from her
    KOR tax position).
  - At 4-9-2026 revocatie: Allow her to reclaim voorbelasting on the camera purchase based on remaining life:
    EUR 139 * (3.917 / 5) ≈ EUR 109 (remaining life 3.917 years after 9-month KOR period).
  - Reflect this in her next VAT filing (Q3 2026).

## Verification

`openspec validate` MUST exit clean on the change folder. Peer reviews include:

1. **Fiscal advisor review (Register Belastingadviseur):**
   - Confirm compliance with Wet OB 1968 (art. 25, 25a–25d), Uitvoeringsbeschikking OB (art. 13, 31a),
     and Belastingdienst Handboek Ondernemen.
   - Confirm drempel-exactness, revocatie-datum rules, herzieningsregels, voorbelasting-logic.

2. **Bookkeeper-persona review:**
   - `/test-persona-janwillem` (SMB owner) confirms the end-to-end KOR workflow matches Dutch
     practice (aanmelding → drempel-monitoring → alert-escalation → revocatie → filing).
   - `/test-persona-sem` (digital native) confirms the UI/UX of the aanmeldstroom and scenario-analysis
     is clear and trustworthy.

3. **Architecture review:**
   - Confirm ADR-031 compliance (all state managed via lifecycle, no app-local dunning-like tables).
   - Confirm cross-app integration points (AR / AP / VAT filing / ZZP tax-regime) are event-driven,
     not polling-based.
   - Confirm manifest entries (4 pages: Aanmelding, Dashboard, Drempel Monitor, Opzegging).

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships in this artifact. Implementation cycle (separate `opsx-apply`)
is responsible for:

- **Unit tests:** Drempel-recalc logic, prognose-trend, revocatie-bedrag calculation, herzieningsregels.
- **Integration tests:** Event-driven voorbelasting-blocking (AP integration), filing-suspension (VAT-filing integration).
- **Browser tests (Playwright):** Aanmeldstroom scenario-analysis, drempel-dashboard realtime updates, alert-dispatch.
- **Fiscal audit:** Recalc of suppletie-bedrag vs. manual calculation; revocatie-datum exactness.

`composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. Implementation cycle authors:
- `docs/user-guide/bookkeeping/kor.md` (ondernemer-facing guide: when KOR makes sense, opt-in flow, drempel-monitoring, overschrijding handling).
- `docs/user-guide/bookkeeping/kor-eu.md` (cross-border KOR-EU guide: lidstaat-drempels, EX-nummer, kwartaalopgaaf).
- Screenshots of aanmeldstroom, dashboard, and alert-escalation in `docs/images/`.

## i18n (company-wide ADR-007)

Spec-only change — no strings ship here. Implementation cycle adds Dutch (`nl_NL`) and English (`en_US`)
translations for all UX labels, alert texts, and fiscal advisories per ADR-025.

**Critical:** All fiscal language (e.g., "artikel 25 Wet op de omzetbelasting 1968", "herzieningsregels")
MUST be kept in exact Dutch legal wording — no colloquial translation.
