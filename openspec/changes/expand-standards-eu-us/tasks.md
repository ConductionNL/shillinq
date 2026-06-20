# Tasks: expand-standards-eu-us

## 1. Category A — extend the precedence catalogue (REQ-ASP-001)
- [x] Extend the `StandardsPolicy.frameworks[].key` enum with the EU national GAAPs, EU-endorsed IFRS, US GASB/FASAB and US special-purpose bases.
- [x] Extend the `StandardsPolicyEditor` FRAMEWORKS catalogue (labels + docs links) to match.

## 2. Category B — additive compliance catalogue, static code (REQ-ASP-004)
- [x] Add versioned static `lib/Standards/ComplianceCatalogue.php` (VERSION/asOf + entries; `applicableTo()`/`byType()`); read-only, not OpenRegister.
- [x] Unit-test the catalogue (`tests/Unit/Standards/ComplianceCatalogueTest.php`).

## 2b. Operative rule corpus — static code (REQ-ASP-005 / REQ-ASP-006)
- [x] Add `lib/Standards/RuleCatalogue.php` + per-domain JSON under `lib/Standards/rules/` (schema in `rules/SCHEMA.md`); query helpers (byDomain/byFramework/byJurisdiction/machineCheckable/countByDomain).
- [x] Wave 1: 700+ sourced rules (invoicing/EN 16931, VAT, retention, ledger-integrity, IFRS, US GAAP, national GAAP, reporting).
- [x] Unit-test the corpus (`tests/Unit/Standards/RuleCatalogueTest.php`).
- [x] Encode REQ-ASP-006: no menu/pages for reference data; only the apply/order screen is UI.
- [ ] Later waves: deepen IFRS/US-GAAP/national disclosure rules; more SAF-T/e-invoicing countries.

## 3. Docs
- [x] New `docs/standards/eu-national-gaap.md` (Directive 2013/34, EU-endorsed IFRS, HGB, PCG, OIC, PGC).
- [x] Extend `public-sector.md` with US GASB + FASAB.
- [x] Extend `us-gaap.md` with the special-purpose/OCBOA section.
- [x] New `digital-compliance.md` (EN 16931, Peppol, ViDA, per-country, SAF-T, VAT).
- [x] New `output-formats.md` (ESEF/XBRL, SEC, SSARS).
- [x] Reframe `index.md` around the three categories; exclude IFRS for SMEs with rationale.

## Out of scope (follow-up)
- [ ] Read-only compliance-status view per administration (derive from `ComplianceCatalogue::applicableTo()` + administration jurisdictions).
- [ ] Category C output-format tooling (XBRL tagging, SEC filing) — documented only.
