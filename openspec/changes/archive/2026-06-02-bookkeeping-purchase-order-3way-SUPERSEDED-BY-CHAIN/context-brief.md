---
status: proposed
app: shillinq
spec: bookkeeping-purchase-order-3way
depends-on:
  - bookkeeping-accounts-payable-core
  - inventory-stock-tracking
  - purchaseq
target-users:
  - inkoper
  - magazijn-medewerker
  - crediteuren-administrateur
  - controller
  - leverancier
standards:
  - UBL-2.1
  - Peppol-BIS-Billing-3.0
  - SETU-Inkoop
  - EN-16931
  - ISO-20022
  - NEN-7510
---

# Purchase Order Cyclus met 3-way Match (PO + GRN + Factuur)

## Purpose

De inkoopcyclus is een van de meest fraudegevoelige en bewerkelijke processen in de boekhouding. Onderzoek van de ACFE (Association of Certified Fraud Examiners) wijst uit dat factuurfraude en duplicate-payments gemiddeld 5% van de jaaromzet kosten bij ondernemingen zonder gestructureerde inkoopcontrole. Tegelijkertijd besteden crediteuren-administrateurs 60-70% van hun tijd aan handmatige verificatie van facturen tegen bestellingen en leveringen.

De 3-way match is de gouden standaard om dit te ondervangen: pas wanneer een Purchase Order (PO, de bestelling), een Goods Receipt Note (GRN, de geconstateerde levering) en de leveranciersfactuur op alle materiële velden overeenkomen, mag betaling worden geautoriseerd. Implementatie in Nederlandse MKB-software is echter beperkt; de meeste pakketten kennen alleen PO-tegen-factuur (2-way) of laten GRN volledig buiten beschouwing. Voor productiebedrijven, groothandels, bouw en zorginstellingen met materiaalintensieve processen is dit een fundamenteel gemis.

Shillinq implementeert een volwaardige inkoopketen waarbij elke fase een gestructureerde entiteit is, met automatische matching op header- en regelniveau, configureerbare toleranties voor afronding en prijsschommelingen, exception-workflow voor afwijkingen, vendor-performance scoring op basis van levertijdbetrouwbaarheid en factureringsdiscipline, en volledige Peppol/UBL-integratie voor e-procurement met overheids- en B2B-leveranciers.

## Data Model

**PurchaseOrder** (entity): po_number (auto-genereerd, conform CBS-richtlijn), supplier_reference, requester (medewerker), cost_center, project_code, currency, payment_terms, delivery_address, expected_delivery_date, status (draft, approved, sent, partial_received, fully_received, invoiced, closed, cancelled), approval_chain[] (op basis van bestelbedrag), peppol_sent_at, peppol_message_id.

**PurchaseOrderLine** (entity): po_id, line_number, product_or_service_code, description, quantity_ordered, unit_of_measure (stuk, kg, uur, m³ conform UN/ECE Rec 20), unit_price, currency, line_total, vat_rate, vat_amount, expected_delivery_date, gl_account (kostenrekening of voorraadrekening), tolerance_override.

**GoodsReceiptNote** (entity): grn_number, po_id (multi-PO mogelijk bij verzamel-levering), received_at, received_by (magazijn-medewerker), delivery_note_reference (pakbon-nummer leverancier), carrier, lot_numbers[], serial_numbers[], temperature_log (voor gekoelde leveringen), quality_check_passed, photos[].

**GoodsReceiptLine** (entity): grn_id, po_line_id, quantity_received, quantity_accepted, quantity_rejected, rejection_reason (schade, verkeerd_product, expired, niet_besteld), inspector, batch_reference.

**SupplierInvoice** (entity): invoice_number, supplier, invoice_date, due_date, total_excl_vat, total_vat, total_incl_vat, currency, payment_reference, ubl_source_uri, peppol_received_at, ocr_confidence_score, status (received, matching, matched, exception, approved, paid).

**ThreeWayMatch** (entity): invoice_id, matched_po_ids[], matched_grn_ids[], match_status (auto_approved, within_tolerance, exception_price, exception_quantity, exception_missing_grn, exception_missing_po, fraud_alert), divergence_details (json), resolved_by, resolution_action, resolution_notes.

**ToleranceProfile** (entity): scope (global, supplier, category, gl_account), price_tolerance_amount (€10), price_tolerance_percentage (0.5%), quantity_tolerance_percentage (2%), date_tolerance_days (3 dagen vroeg/laat), currency_rounding_tolerance, exception_routing (rol/persoon).

**VendorPerformance** (entity): supplier_id, period, on_time_delivery_rate, quantity_accuracy_rate, price_accuracy_rate, invoice_accuracy_rate, dispute_count, average_resolution_days, overall_score (0-100), score_trend (improving, stable, declining), automated_review_eligible.

## Requirements

### REQ-001: Purchase order aanmaken met goedkeuringsketen

**GIVEN** een inkoper wil 200 kantoorstoelen bestellen voor €18.500 totaal
**WHEN** zij de PO aanmaakt met cost center "FAC-2026" en project_code leeg
**THEN** valideert het systeem het budget op het cost center, bepaalt op basis van het bedrag de vereiste goedkeuringsketen (>€10.000 = teamleider + facility-manager), verstuurt notificaties naar de goedkeurders, en blokkeert verzending naar de leverancier totdat alle goedkeuringen binnen zijn met tijdstempel en eventuele opmerkingen.

### REQ-002: Peppol-verzending van PO naar leverancier

**GIVEN** een goedgekeurde PO voor een Peppol-geregistreerde leverancier
**WHEN** de inkoper de PO verstuurt
**THEN** transformeert het systeem de PO naar UBL 2.1 Order-document conform Peppol BIS Ordering 3.0, verstuurt via het Peppol Access Point van shillinq, ontvangt een Message-Level Response, registreert peppol_message_id en peppol_sent_at op de PO, en faalt-gracefully naar PDF-emailverzending wanneer de leverancier niet via Peppol bereikbaar is met expliciete logging van de fallback-reden.

### REQ-003: Goods Receipt Note bij ontvangst

**GIVEN** een magazijn-medewerker ontvangt een pallet met 180 van de bestelde 200 stoelen
**WHEN** zij een GRN aanmaakt gekoppeld aan de PO
**THEN** toont het systeem de openstaande PO-regels, kan zij per regel de werkelijk ontvangen aantallen invoeren (180 geaccepteerd, 0 afgekeurd, 20 nog te leveren), kan zij pakbon-nummer en chauffeur registreren, foto's uploaden, en wordt de PO-status automatisch gezet op "partial_received".

### REQ-004: Automatische 3-way matching binnen tolerantie

**GIVEN** een Peppol-factuur arriveert voor €18.547 (PO was €18.500, GRN registreerde levering compleet)
**WHEN** de matching-engine draait
**THEN** vergelijkt het systeem PO-regels met GRN-regels met factuur-regels op artikel, hoeveelheid, prijs, BTW en levertijd, constateert dat het prijsverschil van €47 (0.25%) binnen de tolerance_profile valt (€10 absoluut OF 0.5% relatief, hier 0.5%=€92), markeert match_status "auto_approved" en routeert de factuur direct naar de betaalstapel zonder menselijke tussenkomst.

### REQ-005: Exception-workflow bij prijsafwijking

**GIVEN** een factuur arriveert voor €19.250 op een PO van €18.500 (€750 / 4% afwijking)
**WHEN** de matching-engine draait
**THEN** valt de afwijking buiten zowel de absolute (€10) als relatieve (0.5%) tolerantie, markeert match_status "exception_price", verzendt een notificatie naar de inkoper met side-by-side vergelijking PO/GRN/factuur, biedt drie acties (accepteren met motivatie, dispuut openen bij leverancier, factuur weigeren met geautomatiseerd UBL-CreditNote-verzoek) en blokkeert betaling totdat de exception is afgehandeld.

### REQ-006: Configureerbare toleranties per leverancier of categorie

**GIVEN** een controller stelt strengere toleranties in voor een nieuwe leverancier met vendor_score < 70
**WHEN** zij een tolerance_profile aanmaakt met scope "supplier=NieuweBV", price_tolerance_amount €0 en quantity_tolerance_percentage 0%
**THEN** geldt voor alle facturen van deze leverancier een zero-tolerance regime tot vendor_score boven 80 stijgt, kan zij het profile retro-actief toepassen op openstaande matches met expliciete bevestiging, en logt het systeem elke wijziging in het profile met before/after-snapshot.

### REQ-007: Verzamel-factuur tegen meerdere PO's

**GIVEN** een leverancier stuurt één maandfactuur voor 12 verschillende PO's
**WHEN** de OCR/UBL-engine de factuur verwerkt
**THEN** extraheert het systeem alle factuurregels, zoekt automatisch matchende PO's en GRN's op basis van artikelcode en datumbereik, presenteert een matching-voorstel waarin de crediteuren-administrateur per factuurregel de juiste PO/GRN kan bevestigen of corrigeren, en voert de 3-way match per regel uit (niet per factuur), zodat individuele regels kunnen worden auto-approved terwijl andere in exception gaan.

### REQ-008: Vendor-performance scoring en automated-review

**GIVEN** een leverancier heeft 12 maanden lang 96% on-time-delivery, 99% quantity-accuracy en geen disputen
**WHEN** de maandelijkse vendor-scoring draait
**THEN** verhoogt het systeem de overall_score naar boven 90, markeert automated_review_eligible op true, verruimt automatisch de tolerantie-profielen voor deze leverancier conform vooraf geconfigureerd staffel, en notificeert de controller met een overzicht van leveranciers die in of uit de auto-review-status zijn gemuteerd.

### REQ-009: Boeking en grootboek-integratie bij match

**GIVEN** een 3-way match wordt goedgekeurd voor een voorraad-PO
**WHEN** de boeking wordt geïnitieerd
**THEN** boekt het systeem op het moment van GRN een GR/IR-boeking (Voorraad / GR-IR-clearing conform IFRS-leverings-principe), en bij factuurmatch een boeking (GR-IR-clearing / Crediteur + BTW), valideert dat het GR-IR-saldo op nul uitkomt na de match, en signaleert reststanden voor periodieke clearing-analyse.

### REQ-010: Audit-trail en compliance-export

**GIVEN** een external-auditor onderzoekt het inkoopproces tijdens de jaarrekeningcontrole
**WHEN** zij een sample van 25 facturen reviewt
**THEN** kan zij per factuur de complete audit-trail oproepen (PO-aanvraag, goedkeuringsketen met tijdstempels, Peppol-verzending, GRN met magazijn-handtekening en foto's, factuur-ontvangst, matching-resultaat, exception-afhandeling, betalingsboeking), exporteert het systeem dit als gestructureerd auditpakket (zip met PDF + JSON + bewijsstukken), en is elke handeling onveranderlijk gelogd conform NV COS 230 documentatie-eisen.

## Standards & References

- **UBL 2.1**: Universal Business Language voor Order, OrderResponse, DespatchAdvice, Invoice, CreditNote documenten
- **Peppol BIS Billing 3.0 & Ordering 3.0**: Pan-European Public Procurement OnLine specificaties voor cross-border e-procurement
- **EN 16931**: Europese norm voor elektronische facturatie (kern-element-model)
- **SETU Inkoop**: Stichting Elektronische Transacties UWV inkoopstandaard voor flexibele arbeid
- **UN/ECE Rec 20**: Codes for Units of Measure used in International Trade
- **ISO 20022**: betaalberichten (pain.001 voor SEPA Credit Transfer)
- **NV COS 230**: Documentatie-eisen voor controle-opdrachten (NBA)
- **Wta artikel 26 lid 1c**: Vereisten administratie-organisatie en interne beheersing

## Cross-app dependencies

- **shillinq:bookkeeping-accounts-payable-core**: ontvangt de gematchte facturen en initieert betaalbestand-generatie (SEPA pain.001)
- **shillinq:inventory-stock-tracking**: muteert voorraadstanden op basis van GRN's; reserveert verwachte ontvangsten op basis van openstaande PO's
- **purchaseq** (separate app): biedt de inkoop-aanvraag- en goedkeuringsworkflow upstream van de PO; shillinq consumeert de goedgekeurde aanvragen als PO-input
- **openconnector**: bemiddelt Peppol-verkeer naar Access Point providers en doet UBL/OCR-extractie van inkomende facturen
- **docudesk**: bewaart originele leveranciersfacturen, pakbonnen en GRN-foto's met retentie conform BW2 art 2:10 (7 jaar)
- **opencatalogi**: levert de product/dienst-catalogus die wordt gebruikt voor artikelmatching tussen PO en factuur

## Target users

- **Inkoper**: PO-creatie, leveranciersrelatie, exception-resolutie bij prijsdisputen
- **Magazijn-medewerker**: GRN-registratie via mobiele interface met barcode/QR-scan en foto-upload
- **Crediteuren-administrateur**: review van exceptions, handmatige matching bij ongestructureerde facturen
- **Controller**: configuratie van tolerantie-profielen, vendor-performance review, fraudepatroon-analyse
- **External auditor**: audit-trail review bij jaarrekeningcontrole
- **Leverancier**: ontvanger van Peppol-PO's, indiener van Peppol-facturen en CreditNotes via eigen ERP
