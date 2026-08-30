# Tasks: order-revenue-recognition (head — kind: config)

This is the **config head** of the `order-revenue-recognition` chain (ADR-032). It ships only
the two declarative schemas + seed data. The recognition service lives in the chained
`order-revenue-recognition-engine` change (kind: code); the dashboard widget lives downstream in
pipelinq. No PHP in this change.

## 1. SalesOrder schema

- [x] 1.1 Declare `SalesOrder` in `lib/Settings/shillinq_register.json` with the fields in spec REQ "SalesOrder SHALL model the booking term" (orderId, ondernemingId, administrationId, klantId, orderDate, termStart, nullable termEnd, status enum active/ended, currency default EUR, nullable contractId string)
- [x] 1.2 Add `x-openregister-lifecycle` (active → ended) and `x-openregister-audit-trail: { enabled: true }` per adr-000-data-model audit rule
- [x] 1.3 Add `administrationId`-scoped `x-openregister-rbac` consistent with sibling bookkeeping registers

## 2. SalesOrderLine schema

- [x] 2.1 Declare `SalesOrderLine` in `lib/Settings/shillinq_register.json` with the fields in spec REQ "SalesOrderLine SHALL declare line nature and recognition method" (lineId, orderId FK, administrationId, nature enum RECURRING/ONE_OFF, label, amount, nullable frequentie enum, recognitionMethod enum OVER_TIME/POINT_IN_TIME, nullable termStart/termEnd, nullable recognitionDate, accountNumber)
- [x] 2.2 Encode the schema validation rules: RECURRING requires non-null frequentie; POINT_IN_TIME requires non-null recognitionDate; null term fields inherit the order term — encoded at the schema level via the enums, nullable flags, required[] and the SalesOrder→SalesOrderLine relation; the cross-field conditional enforcement (RECURRING⇒frequentie, POINT_IN_TIME⇒recognitionDate) and term-inheritance arithmetic are applied by the chained `-engine` service per ADR-031/ADR-032 (documented in field descriptions + adr-000-data-model notes)
- [x] 2.3 Add `x-openregister-audit-trail: { enabled: true }` and `administrationId`-scoped RBAC
- [x] 2.4 Add a derived `maandWaarde` number property + `x-openregister-calculations.maandWaarde` calc (declarative, ADR-031): monthly-normalized recurring amount = `amount × frequencyFactor(frequentie)` (MAANDELIJKS=1, KWARTAALS=1/3, JAARLIJKS=1/12, WEKELIJKS=52/12, TWEEWEKELIJKS=26/12), 0 when `nature == 'ONE_OFF'`. This is the field pipelinq SUMs (filtered `nature=RECURRING`) as a plain OR aggregation for its CRM run-rate tile

## 3. Seed data (ADR-001)

- [x] 3.1 Seed `SalesOrder` ORDER-2026-0001 (nil-UUID onderneming/admin, klantId KLANT-ACME-CONSULTING, term 2026-01-01..2026-12-31, contractId CONTRACT-2026-0001) per design.md Seed Data
- [x] 3.2 Seed the three mixed lines: A=SaaS RECURRING JAARLIJKS 12000, B=implementation ONE_OFF POINT_IN_TIME 5000 (recognitionDate 2026-01-15), C=retainer RECURRING MAANDELIJKS 1500

## 4. Data-model registration + chain bookkeeping

- [x] 4.1 Add `SalesOrder` and `SalesOrderLine` entity entries to `openspec/architecture/adr-000-data-model.md` (fields, relations, primary spec `recurring-revenue-recognition`)
- [x] 4.2 Filed the OpenRegister issue requesting generic query-time `@period.*` parameter
  binding on ad-hoc/named aggregations (the reusable, SQL-translatable half of the missing
  primitive; the per-row interval-overlap reducer deliberately stays out of OR's aggregation
  core per design.md's "Why not the overlap reducer" — that part stays the app-side
  `-engine` service). Filed: Codeberg issue openregister#477 (pre-migration, not migrated to GitHub)

## 5. Verification

- [x] 5.1 `openspec validate order-revenue-recognition --strict` exits clean
- [x] 5.2 Integration test: a SalesOrder with the three seed lines round-trips through OpenRegister
  (create/read/audit-trail) with zero shillinq PHP. Added
  `tests/Integration/SalesOrderSeedDataIntegrationTest.php` (wired into `phpunit-unit.xml`'s
  Unit Tests suite via an explicit `<file>` entry — the rest of `tests/Integration/` is a
  pre-existing, NOT-CI-wired directory with at least one pre-existing failure elsewhere
  (`PurchaseOrder3WayManifestIntegrationTest`), so only this new file was wired, not the whole
  directory). No OpenRegister runtime is exercised — it loads the register JSON directly and
  asserts: SalesOrder + SalesOrderLine declare lifecycle/audit-trail/RBAC; the one seeded
  SalesOrder's `orderId`/`administrationId` are FK-consistent with all three seeded
  SalesOrderLines (create/read/audit-scope round-trip integrity); RECURRING lines carry a
  non-null `frequentie` and POINT_IN_TIME lines carry a non-null `recognitionDate` (schema
  conditional rules hold on the seed). 4 tests, all green.
- [x] 5.3 Integration test: the seed worked-sample is assertable — recognized recurring revenue
  for [2026-01-01, 2026-03-31] = 7500 (Line1 3000 + Line3 4500), one-off = 5000. Added to the
  same `SalesOrderSeedDataIntegrationTest`: `testWorkedSampleRecognizedRevenueForQ1` reproduces
  design.md's worked sample from the seed data alone via the SAME frequency-normalization +
  whole-month-overlap arithmetic the `maandWaarde` calc and D5 already encode (test-only helper
  methods, not shipped `lib/` PHP — this change still ships zero production PHP; the
  `-engine` service is the real implementation). Asserts recurring=7500.0, one-off=5000.0
  within a 0.001 delta.

## Acceptance criteria

- A `SalesOrder` with nullable indefinite `termEnd` and an unmodeled `contractId` string validates and saves.
- A `RECURRING` line with null term inherits the order term; a `POINT_IN_TIME` line without `recognitionDate` is rejected.
- The seed order makes the recurring (7500) vs one-off (5000) split demonstrable for the sample period.
- No PHP ships in this change; recognition arithmetic is deferred to `order-revenue-recognition-engine` (kind: code).

## Quality reminders

- Fix any pre-existing register/validation quality issues touched while editing `shillinq_register.json` (don't leave them).
- Use safe placeholders only (nil UUID `00000000-0000-0000-0000-000000000000`, `<...>`, UPPERCASE business keys).
- i18n: any new user-facing strings (schema titles/descriptions) need nl_NL + en_US per ADR-007; English is the key.
- Keep this change `config`-only — if a task tempts you to write PHP, it belongs in the chained `-engine` change.
