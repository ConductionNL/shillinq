# Tasks: expand-standards-eu-us

## 1. Category A — extend the precedence catalogue (REQ-ASP-001)
- [x] Extend the `StandardsPolicy.frameworks[].key` enum with the EU national GAAPs, EU-endorsed IFRS, US GASB/FASAB and US special-purpose bases.
- [x] Extend the `StandardsPolicyEditor` FRAMEWORKS catalogue (labels + docs links) to match.

## 2. Category B — additive compliance model (REQ-ASP-004)
- [x] Add register fragment `add-shillinq-compliance-obligation.json` declaring `ComplianceObligation` ({ jurisdiction, type, standard, status, effectiveDate }).

## 3. Docs
- [x] New `docs/standards/eu-national-gaap.md` (Directive 2013/34, EU-endorsed IFRS, HGB, PCG, OIC, PGC).
- [x] Extend `public-sector.md` with US GASB + FASAB.
- [x] Extend `us-gaap.md` with the special-purpose/OCBOA section.
- [x] New `digital-compliance.md` (EN 16931, Peppol, ViDA, per-country, SAF-T, VAT).
- [x] New `output-formats.md` (ESEF/XBRL, SEC, SSARS).
- [x] Reframe `index.md` around the three categories; exclude IFRS for SMEs with rationale.

## Out of scope (follow-up)
- [ ] Admin UI for `ComplianceObligation` (a Category-B settings surface).
- [ ] Category C output-format tooling (XBRL tagging, SEC filing) — documented only.
