# Tasks: bookkeeping-rule-corpus

## 1. Corpus infrastructure (REQ-BKR-001 / REQ-BKR-002)
- [x] `lib/Standards/RuleCatalogue.php` — versioned static accessor over `lib/Standards/rules/*.json`; loader skips malformed rules; query helpers.
- [x] `lib/Standards/rules/SCHEMA.md` — the per-rule field contract.
- [x] `tests/Unit/Standards/RuleCatalogueTest.php` — version, well-formed + unique ids, domain/framework/jurisdiction filters, machine-checkable subset.

## 2. Rule content — Waves 1 & 2 (REQ-BKR-003 / REQ-BKR-004)
- [x] Wave 1 (713): invoicing/EN 16931, vat, retention, ledger-integrity, IFRS, US GAAP, national-reporting (NL/DE/FR).
- [x] Wave 2 (+584): sustainability (ESRS/IFRS S1-S2), public-sector (IPSAS/GASB/BBV), payroll/wage-tax, banking/AML, deeper IFRS/US-GAAP, national-extra (IT/ES/BE + more jurisdictions).
- [x] ~1,300 rules total; all sourced; unverifiable citations flagged `(verify)`.

## 3. Behaviour, not navigation (REQ-BKR-005)
- [x] Corpus is static code consumed by `RuleCatalogue`; no menu/page added (manifest + registry untouched).
- [x] Docs: `docs/standards/rule-corpus.md`.

## Out of scope (follow-up waves)
- [ ] Per-rule validators in the posting engine (fed by `machineCheckable`).
- [ ] Deeper disclosure-checklist coverage + more jurisdictions.
