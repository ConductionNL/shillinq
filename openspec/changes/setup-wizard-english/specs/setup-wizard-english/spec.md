# setup-wizard-english Specification (delta)

---
status: proposed
---

## Purpose

The required first-time setup wizard (ADR-042) and a mechanically-verified set
of page titles/widget titles/menu labels ship Dutch source text, violating
ADR-007 (English source strings) and ADR-057 (Dutch-as-key/label/title is a
hard fail; locale parity is a CI gate). This capability makes shillinq's
manifest-declared UI strings English source with Dutch preserved as a real
`l10n/nl.json` translation, closes the `l10n/en.json` ↔ `l10n/nl.json` parity
gap in both directions, wires the existing-but-unwired parity checker into CI
alongside a Dutch-token gate so the defect class cannot silently recur, and
proves it end-to-end with a Playwright test that walks the wizard against a
genuinely reset instance.

## ADDED Requirements

### Requirement: Setup wizard strings are English source with Dutch preserved in l10n (REQ-SWE-001)

Every `src/manifest.json` `.setup.steps[]` `title`, `body`, and
`options[]`/`optionsByParent[*][]` `label` value MUST be English source text.
For each field that changes, the English string MUST be added to
`l10n/en.json` as an identity entry (`"<English>": "<English>"`) and the
CURRENT Dutch value MUST be preserved as that same key's `l10n/nl.json`
translation — no Dutch content may be discarded. Jurisdiction-specific legal
entity-type labels with no natural English equivalent (`ZZP`, `MKB`, `VZW`,
`GmbH`, `Verein`, `Einzelunternehmen`, and the `BV`/`BBV` acronyms) are kept as
their official term per ADR-007's proper-noun/acronym exception; only the
surrounding descriptive prose around them translates.

#### Scenario: Every wizard step renders English source text

- GIVEN `src/manifest.json` `.setup.steps[]` at HEAD
- WHEN each step's `title`, `body`, and any `options[]`/`optionsByParent[*][]` `label` is read
- THEN none of those values contains Dutch prose (verified by the ADR-057 Dutch-token gate, REQ-SWE-004)
- AND jurisdiction-specific legal-entity acronyms (`ZZP`, `MKB`, `VZW`, `GmbH`, `BV`, `BBV`, …) are still present, unglossed into invented English
- @e2e tests/e2e/setup-wizard-english.spec.ts

#### Scenario: No Dutch content is lost in the rewrite

- GIVEN a wizard field's pre-change Dutch value
- WHEN the manifest field is rewritten to its English source string
- THEN `l10n/nl.json` contains that exact key (the new English string) mapped to the original Dutch value
- AND `l10n/en.json` contains the same key mapped to itself
- @e2e exclude verified by `node tests/l10n/check-l10n-parity.js` (content-equality is a diff check, not a browser flow)

### Requirement: Dutch page titles, widget titles, and menu labels become English source (REQ-SWE-002)

Every `pages[].title`, `pages[].config.widgets[].title`, and `menu[].label`
(recursively through `children[]`) value that is Dutch source text MUST become
English source text, with the same `l10n/en.json`/`l10n/nl.json` identity +
translation treatment as REQ-SWE-001. This includes, at minimum, the 14
mechanically-verified strings in design.md's inventory table (`Bewaartermijnen`
family, `Aansluitingen` family, `Urenregistratie`, `Schatkist-positie` ×2,
`Oninbare Afschrijvingen` family, `Tijdelijke verschillen` family,
`ETR-aansluiting` ×2) — a superset of the 4 originally reported. Internal
`id`/`route` identifiers are NOT in scope (structural, not user-facing; a
rename is a property change, excluded by proposal.md Non-Goals).

#### Scenario: No Dutch title/label remains in the effective manifest

- GIVEN the merged effective manifest (`src/manifest.json` + `src/manifest.d/*.json` fragments)
- WHEN every `pages[].title`, `pages[].config.widgets[].title`, and recursive `menu[].label` is scanned for Dutch tokens
- THEN zero matches remain
- AND every `id`/`route` value is unchanged from before this change
- @e2e tests/e2e/setup-wizard-english.spec.ts

### Requirement: `l10n/en.json` and `l10n/nl.json` reach full bidirectional key parity (REQ-SWE-003)

Every key present in `l10n/en.json` MUST have a non-empty, real (not
identical-by-accident-of-being-untranslated-cognate) entry in `l10n/nl.json`,
and every key present in `l10n/nl.json` MUST have a corresponding
`l10n/en.json` entry. The 53 keys currently only in `en.json` (e.g. `"Loading
scorecards…"`, `"On Hand"`, `"Applies To"`) each get a real Dutch translation.
The 3 keys currently only in `nl.json` (`"Analytical dimensions"`, `"Sales"`,
`"Valuation"`) are each resolved: if a living `t()`/manifest reference exists,
add the matching `en.json` identity entry; if the key is dead (no reference
anywhere in `src/`, `manifest.json`, or `manifest.d/`), remove it from
`nl.json` rather than inventing an unused `en.json` counterpart.

#### Scenario: Zero keys exist in only one locale file

- GIVEN `l10n/en.json` and `l10n/nl.json` at the end of this change
- WHEN their key sets are diffed in both directions
- THEN the symmetric difference is empty
- @e2e exclude verified by `node tests/l10n/check-l10n-parity.js` (a JSON diff, not a browser flow)

### Requirement: The parity and Dutch-key gates run in CI and fail on any gap (REQ-SWE-004)

`.github/workflows/l10n.yml` MUST run `check-l10n-parity.js` (or the wired
equivalent) as a required step, failing the job on any `l10n/en.json` ↔
`l10n/nl.json` key gap in either direction (ADR-057 D1/D2 — this repo already
ships the checker; it is currently invoked nowhere in CI). The suite (either
`check-l10n.js`, `check-l10n-parity.js`, or a new companion script) MUST ALSO
scan `t('shillinq', '...')`/`n(...)` literal call arguments AND
`manifest.json`/`manifest.d/*.json` `title`/`body`/`label` values for a
curated Dutch-token stopword match (ADR-057 D3) and fail the build on any
match, so this defect class — Dutch shipped as the SOURCE string — cannot
silently recur.

#### Scenario: CI fails when a Dutch-only key gap is introduced

- GIVEN a PR that adds a key to `l10n/en.json` without a matching `l10n/nl.json` translation
- WHEN the `l10n` CI job runs
- THEN it fails, naming the missing key and locale
- @e2e exclude CI workflow behaviour, verified by running the wired job locally (`node tests/l10n/check-l10n-parity.js`), not a browser flow

#### Scenario: CI fails when a Dutch string is added as manifest source text

- GIVEN a PR that adds a Dutch-token string as a `manifest.json`/`manifest.d/*.json` `title`, `body`, or `label` value
- WHEN the `l10n` CI job runs
- THEN it fails, naming the offending file/path and matched token
- @e2e exclude CI workflow behaviour; unit-level, not a browser flow

### Requirement: An e2e test proves the wizard renders in English on a first-run instance (REQ-SWE-005)

A Playwright test MUST reset shillinq's setup app-config keys
(`legal_country`, `legal_region`, `rgs_template`, `administration_id`,
`setup_seed_done`, `setup_completed_version`) server-side — NOT rely on
browser-context freshness alone, since `SetupController::status()` gates on
server state (design.md "Fresh-context e2e") — then load the app as an admin,
observe the gating `CnSetupWizard` render, step through every step (`welcome`
→ `country` → `organisation` → `rgs-template` → `administration` → `seed` →
`done`), and assert the rendered `title`/`body`/option-label text for each
visible step matches the new English source strings and contains no residual
Dutch token. The test MUST be able to fail: run once against the pre-change
Dutch manifest to confirm it catches the violation (negative control) before
relying on it as a regression guard.

#### Scenario: The wizard shows English copy on a genuinely first-run instance

- GIVEN a shillinq instance with all setup app-config keys server-side reset (not merely a fresh browser context)
- WHEN an admin opens the app
- THEN `CnSetupWizard` gates the shell and renders English `title`/`body` text for every step, and English `label` text for every visible choice option
- AND no step surface contains a Dutch token
- @e2e tests/e2e/setup-wizard-english.spec.ts

#### Scenario: The test fails against the pre-change Dutch manifest (negative control)

- GIVEN the same test run against the ORIGINAL Dutch-source `manifest.json` (e.g. via `git stash` / a throwaway checkout)
- WHEN the assertion step runs
- THEN the test FAILS, proving the assertions are not vacuously true
- @e2e exclude control run performed once at authoring time, not part of the standing CI suite
