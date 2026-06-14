# Specs — Single Audit voor EU-fondsen (ERDF, ESF+, JTF, RRF)

## REQ-EUF-001: EU-project opzetten met fonds-specifieke configuratie

**GIVEN** een projectleider start een nieuw ERDF-project "Kansen voor West III - Smart Industry Hub" met CCI-nummer 2021NL16RFPR007, programma "Kansen voor West", prioriteit-as 1, specifiek doel "ondernemerschap KMO"

**WHEN** zij het project aanmaakt en het fonds (ERDF) + interventieveld (02.5 "Ondernemersondersteuning") kiest

**THEN**
- Systeem laadt toepasselijke eligibility-rules conform Verordening 2021/1058 art 7 (ERDF-specifieke cost-categories + vat-treatment)
- Configureert automatisch vereiste sub-grootboek-structuur per project (dedicated rekeningnummers, EU-vlag op alle transacties)
- Koppelt juiste managing_authority (Stadsregio Rotterdam-Den Haag)
- Past juiste co-funding-rate toe (max 40% EU voor meer-ontwikkelde regio's Noord-Holland)
- Blokkeert boekingen buiten subsidiabele periode (project start_date – end_date)
- Initialiseert budget_tracking met total_eligible_budget + eu_co_funding_amount

**Compliance**: Verordening 2021/1058 art 7, 2021/1060 art 22

---

## REQ-EUF-002: Gescheiden EU-fondsen-administratie

**GIVEN** een controller boekt een factuur voor consultancy (€12.500 excl. BTW) ten behoeve van ERDF-project "ERDF-2026-007"

**WHEN** zij de boeking koppelt aan project_code en cost_category "externe_dienstverlening" + VAT terugvorderbaar

**THEN**
- Systeem boekt transactie zowel in reguliere GL (4100 Consultancy-kosten / 1100 Consultancy-crediteur) als in gesegregeerde EU-administratie
- Markeert transactie als niet-subsidiabel wanneer BTW terugvorderbaar (VAT logic per eligibility-rule)
- Vlagt automatisch: gross_amount = €12.500 (excl. BTW), eu_co_funding_amount = €5.000 (40%), declared_amount = €12.500
- Boekt separate tussenrekening-mutatie om reguliere GL + EU-administratie reconcilieerbaar te houden
- Beide administraties blijven continu sluitend en reconcilieerbaar

**Compliance**: Verordening 2021/1060 art 61 (separate accounting), art 67 (cost eligibility)

---

## REQ-EUF-003: Realisatie-rapportage per kwartaal aan managementautoriteit

**GIVEN** ERDF-project dient kwartaal-realisatie in conform art 73 CPR over periode 1 oktober – 31 december 2026

**WHEN** projectleider genereert kwartaalrapportage

**THEN**
- Systeem verzamelt alle EuExpenditure met status "gedeclareerd" of "ingediend_bij_MA" over periode
- Splitst per cost_category (personeel 3 records, kapitaal 1 record, externe_dienstverlening 5 records)
- Voegt SCO-berekeningen toe (flat-rate 15% voor indirecte_kosten waar van toepassing)
- Levert outputs-indicators-rapport (RCO-indicatoren per interventieveld)
- Levert results-indicators-rapport (RCR-indicatoren per specifiek doel)
- Exporteert XBRL/Excel in door MA vereiste formaat + digitale handtekening (projectleider)
- Rapport tabel bevat: cost_category, gross_amount, vat_treatment, declared_amount, eu_co_funding_amount

**Compliance**: Verordening 2021/1060 art 73, Europese Commissie SFC2021 format

---

## REQ-EUF-004: Bewijsstukken-management met integriteit-garanties

**GIVEN** EU-uitgave voor personeel (€18.000 salaris over 3 maanden) wordt geboekt

**WHEN** controller uploadt bewijsstukken (contract + salaris-specificatie + urenstaten)

**THEN**
- Systeem valideert dat ALLE verplichte document-types voor cost_category="personeel" aanwezig zijn
- Berekent SHA-256 hash voor elk document bij upload
- Bewaart origineel in docudesk met retention_until_date = 31-12 van jaar+5 na programma-sluiting
- Stuurt hash + URI naar Shillinq SupportingDocument record (certified_true_copy_status = true)
- Weigert declaratie totdat alle verplichte stukken aanwezig zijn (blocking validation on declaration_period change)
- Bij audit, auditor kan bewijsstuk + hash downloaden voor integriteits-check

**Compliance**: Verordening 2021/1060 art 46, Annex XII (eligibility evidence), CGR Archiefwet 1995

---

## REQ-EUF-005: Aanbestedings-compliance toetsing

**GIVEN** uitgave van €185.000 voor leveringen (software licenties) wordt opgevoerd ERDF-project

**WHEN** boeking wordt gevalideerd op aanbestedings-drempel

**THEN**
- Systeem detecteert dat drempel voor decentrale overheid (€221.000 voor 2026) NIET overschreden
- Maar systeembewustzijn (waarschuwing) dat projectleider aanbestedingsdossier kan inleveren indien gewenst
- Mocht drempel voor centrale overheid (€143.000) overschreden worden: systeem blokkeert declaratie zonder aanbestedingsdossier

**Scenario 2:**
**GIVEN** uitgave van €285.000 voor leveringen (IT-systeem) voor gemeente Amsterdam (decentrale overheid)

**WHEN** boeking wordt gevalideerd

**THEN**
- Systeem detecteert overschrijding van €221.000 drempel
- Vraagt om upload complete aanbestedingsdossier (vooraankondiging, bestek, gunningscriteria, ontvangen inschrijvingen, gunningsbesluit, overeenkomst, publicatie in TenderNed)
- Valideert aanwezigheid van single-programming-document-referentie in overeenkomst
- Blokkeert declaratie (declaration_period cannot change to ingediend_bij_MA) totdat aanbestedingsdossier compleet

**Compliance**: Verordening 2021/1060 art 65 (public procurement), Aanbestedingswet 2012, drempelbedragen EC 2026

---

## REQ-EUF-006: Mid-term audit en jaarlijks accounts-package

**GIVEN** Auditdienst Rijk start jaarlijkse audit conform art 78 CPR voor programma-jaar 1 juli 2025 – 30 juni 2026

**WHEN** auditor vraagt toegang tot accounts-package

**THEN**
- Systeem genereert complete export per programma-jaar met:
  - Gecertificeerde-uitgaven-tabel (alle EuExpenditure met status "betaald_door_EC")
  - Management-declaration (ondertekend door certificeringsautoriteit)
  - Jaarlijkse samenvatting van controles en audits (interne + externe)
  - Uitsplitsing naar prioriteit-as / specifiek doel (per art 73 CPR)
  - Totalen per cost-category + SCO-variant
- Biedt audit-portaal waarin auditor doorklikbaar elk uitgave-item kan reviewen
- Auditor ziet complete audit-trail (boeking → declaratie → certificering → betaling)
- Auditor kan alle bewijsstukken downloaden met SHA-256 hash voor integriteitscheck

**Compliance**: Verordening 2021/1060 art 74 (accounts-package), art 78 (audit)

---

## REQ-EUF-007: Onregelmatigheden-melding via IMS

**GIVEN** interne controle detecteert dubbelfinanciering: dezelfde factuur €15.400 is gedeclareerd onder ERDF project "ERDF-2026-007" EN ESF+ project "ESF+-2026-042"

**WHEN** controller maakt irregularity-report aan

**THEN**
- Systeem valideert dat bedrag €15.400 OLAF-drempel van €10.000 overschrijdt
- Vereist classificatie: nature = "dubbelfinanciering"
- Genereert IMS-bericht conform Anti-Fraud Information System schema (OLAF)
- Vereist terugvorderings-plan: recovery_amount = €15.400, betalingsregeling met mijlpalen
- Blokkeert verdere declaraties op betrokken projecten (ERDF-2026-007 + ESF+-2026-042) totdat correctie verwerkt
- Registreert melddatum aan Europese Commissie (ims_submitted_at)
- Audit-trail log: irregularity detected → IMS-report generated → correctie-booking

**Compliance**: Verordening 2021/1060 art 86 (irregularities), Anti-Fraud-strategie EC, IMS-schema

---

## REQ-EUF-008: Financiële correctie en terugvordering

**GIVEN** DG REGIO legt 5% flat-rate financiële correctie op vanwege aanbestedings-tekortkoming op €840.000 contract

**WHEN** certificeringsautoriteit voert correctie van €42.000 door

**THEN**
- Systeem boekt negatieve EuExpenditure (gross_amount = −€42.000)
- Linkt aan oorspronkelijk audit-bevinding-document (SupportingDocument + audit-trail evidence_uri)
- Initieert terugvorderings-administratie tegen begunstigde (amount €42.000, betalingsregeling)
- Vermindert beschikbare budget voor toekomstige declaraties met €42.000
- Verwerkt correctie in volgende accounts-package naar EC (met justification: "5% financial correction per DG REGIO finding dated [date]")
- Audit-trail captures: original expenditure → DG REGIO audit finding → correction booking → recovery tracking

**Compliance**: Verordening 2021/1060 art 85 (financial corrections)

---

## REQ-EUF-009: Mid-term en final-audit bewijsstukken-reconstructie

**GIVEN** EU-auditor DG REGIO vraagt 5 jaar na project-sluiting (juli 2031) om sample-controle van 12 uitgaven uit juli 2026 – juni 2027

**WHEN** auditor vraagt bewijsstukken + audit-trail

**THEN**
- Systeem kan alle 12 gevraagde transacties reconstrueren ondanks project-closure
- Levert per uitgave: complete audit-trail (boeking → declaratie → certificering → betaling + audit-bevindingen)
- Levert alle bewijsstukken met onveranderde SHA-256 hashes (integriteitscheck)
- Levert oorspronkelijke aanbestedingsdossiers
- Faciliteert read-only auditor-account met:
  - MFA-login
  - Session-logging (IP, timestamp, actions)
  - Expiration date (audit completion + 30 days)
  - Download-only access (no editing)

**Compliance**: Verordening 2021/1060 art 46 (record-keeping), IAS 805 (single financial statements audit)

---

## REQ-EUF-010: Zichtbaarheids- en communicatie-naleving (Annex IX CPR)

**GIVEN** ERDF-project "Smart Industry Hub" ontvangt €650.000 EU-bijdrage (>€500k drempel)

**WHEN** projectleider rapporteert zichtbaarheidsverplichtingen

**THEN**
- Systeem registreert:
  - Geplaatste billboards/posters met datum, locatie, foto, GPS-coördinaten
  - EU-embleem aanwezigheid op alle communicatie-uitingen (website screenshots, persberichten, publicaties)
  - Placebijdrage-disclaimer ("Mede gefinancierd door de Europese Unie") op alle stukken
  - Mediabewijs (pers-coverage, online mentions, event-fotos met EU-embleem)
- Levert bij audits complete visibility-dossier met:
  - Inventaris billboards / posters (foto + GPS)
  - Website-screenshots (URL + datum)
  - Persberichten / publicaties (scan + datum)
  - Event-verslagen (foto's met EU-embleem)
- Auditor controleert Annex IX CPR compliance via dossier

**Compliance**: Verordening 2021/1060 Annex IX (visibility & communication), art 49 (transparency)

---

## REQ-EUF-011: Cost-eligibility validation per fonds en prioriteit

**GIVEN** projectleider werkt aan ESF+ project ("ESF+ Arbeidsmarkt") en wil kosten boeken voor gender-mainstreaming consultancy (€8.000)

**WHEN** systeem valideert cost_category "externe_dienstverlening" voor ESF+ fund

**THEN**
- Systeem leest eligibility-rule voor ESF+ (Verordening 2021/1057 art 5)
- Valideert dat cost_category "externe_dienstverlening" eligible is
- Valideert dat cost voor gender-mainstreaming is eligible per ESF+ specificaties
- Vlag VAT-treatment: terugvorderbaar of niet per ESF+ regels
- Boeken succesvol; boeking krijgt vlag "eligibility_confirmed"

**Scenario 2 (blocking):**
**GIVEN** boeking voor politieke campagne (€15.000) onder ESF+

**WHEN** systeem valideert cost

**THEN**
- Systeem leest eligibility-rule voor ESF+
- Politieke campagne is niet-eligible per art 5(2) (excluded activities)
- Systeem BLOKKEERT boeking met error: "Political campaign is not eligible under ESF+ art 5(2)"
- Controller kan override alleen met goedkeuring manager + audit-trail notitie

**Compliance**: Verordening 2021/1057 art 5 (ESF+ eligibility), 2021/1058 art 7 (ERDF eligibility), 2021/1056 art 12 (JTF eligibility), 2021/241 art 4 (RRF eligibility)

