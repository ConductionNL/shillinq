---
kind: code
---

# Proposal: setup-wizard-english

## Why

The **required** first-time setup wizard (`src/manifest.json` `.setup.steps[]`,
rendered by `CnSetupWizard` per ADR-042) is Dutch source text end to end — every
`title`/`body` and most `options[].label` across all 7 steps. ADR-007 requires
English source strings for every user-facing string (`l10n/en.json` is the
identity-mapped source; Dutch belongs in `l10n/nl.json` as a translation).
ADR-057 D3 makes Dutch-as-source a **hard fail**. This is the exact defect class
ADR-057 was written to close, on the ONE surface every admin is forced through
before the app is usable at all.

Mechanical verification (this change) found the defect is **larger than the
originating audit reported**, in two directions:

1. **The wizard's Dutch footprint is bigger than the four fields first flagged.**
   A full field-by-field read of `setup.steps[]` (§ design.md "Full wizard
   string inventory") found Dutch text in **17 fields across all 7 steps** —
   including `steps[1].body`, `steps[2].title` + every `optionsByParent.nl[]`
   label, and `steps[3].title` — none of which were in the original 8-field
   list. `steps[0].title` alone reads `"Welkom bij Shillinq"`.

2. **The "4 Dutch page titles" figure undercounts by ~3.5×.** A mechanical scan
   of `pages[].title`, `pages[].config.widgets[].title` and `menu[].label`
   (recursive) found **14 distinct Dutch strings across 16 occurrences**, not
   4 — `Bewaartermijnen`/`Bewaartermijn`/`Bewaartermijnen — Dashboard`,
   `Aansluitingen`/`Aansluiting`/`Aansluiting Resultaten`/`Aansluiting
   Resultaat` (a full nav section, id `Aansluitingen`, plus its menu label),
   `Oninbare Afschrijvingen`/`Oninbare Afschrijving`, `Tijdelijke
   verschillen`/`Tijdelijk verschil`, `ETR-aansluiting` (×2, half-English),
   `Urenregistratie` and `Schatkist-positie` (×2 — page title + widget title)
   were all confirmed Dutch at HEAD. See design.md for the full list; this
   change targets the mechanically-verified set, not the original estimate.

Separately, `l10n/en.json` (2794 keys) and `l10n/nl.json` (2744 keys) have
drifted: **53 keys exist only in `en.json`** (added without their Dutch
translation — e.g. `"Loading scorecards…"`, `"On Hand"`, `"Applies To"`) and
**3 keys exist only in `nl.json`** (`"Analytical dimensions"`, `"Sales"`,
`"Valuation"` — orphaned, stale, or renamed-away-from entries). `npm run
test:l10n` (`tests/l10n/check-l10n.js`) only checks that keys USED in `t()`
calls exist in `en.json` — it has no visibility into `nl.json` at all, and
does not scan `manifest.json`. The repo already ships a `check-l10n-parity.js`
that checks exactly the missing direction (en → every required locale, mirrors
ADR-057 D1) — but per `.github/workflows/l10n.yml`, **it is not wired into
CI**. This is the exact "dead safety net" pattern ADR-057 names against
docudesk/procest/openbuild/decidesk: the tool exists, nobody calls it.

**Critical mechanism finding** (design.md "How manifest strings are (not) yet
translated"): the installed `@conduction/nextcloud-vue` `CnSetupWizard` /
`CnAppRoot` render `step.title` / `step.body` / `options[].label` **literally,
with no `t()`/`translate()` wrapping** — unlike `CnAppNav`'s menu section
labels, which DO go through `CnAppRoot`'s `translate` prop
(`translate(section.label)`). Today the wizard renders in whatever language
`manifest.json` happens to contain, for every locale, always. Rewriting the
manifest to English source is still the correct and required fix (ADR-007
applies to the SOURCE regardless of what currently consumes it, and English
source is a strict improvement over Dutch-as-source either way) — but it does
not, by itself, restore a translated wizard for `nl`-locale users until
`CnAppRoot`/`CnSetupWizard` also route `manifest.setup.steps` through the
app's `translate` function. That fix lives in `@conduction/nextcloud-vue`, a
different repo — out of scope here; flagged as a tracked follow-up (see
Non-Goals).

## What Changes

- **ADDED** `REQ-SWE-001` — every `setup.steps[]` `title`/`body` and
  `options[]/optionsByParent[].label` MUST be English source text, with the
  identity mapping added to `l10n/en.json` and the existing Dutch preserved as
  the `l10n/nl.json` translation of the same key. Jurisdiction-specific legal
  entity-type labels (`GmbH`, `VZW`, `BV`, `MKB`, `ZZP`, `BBV`) are kept as
  their official term (ADR-007's acronym/proper-noun exception), not
  invented-English-glossed.
- **ADDED** `REQ-SWE-002` — the 14 Dutch page titles / widget titles / menu
  labels mechanically found at HEAD MUST become English source with the same
  en/nl l10n treatment.
- **ADDED** `REQ-SWE-003` — `l10n/en.json` and `l10n/nl.json` MUST reach full
  bidirectional key parity: every en-only key gets a real Dutch translation,
  every nl-only key is either matched to an existing/renamed en key or
  removed as dead.
- **ADDED** `REQ-SWE-004` — the locale-parity gate (`check-l10n-parity.js`)
  MUST run in CI (`.github/workflows/l10n.yml`) and fail the build on any
  gap in either direction; `check-l10n.js` (or a companion check) MUST also
  fail on a Dutch-token match in `t()`/`n()` calls AND in manifest
  `title`/`body`/`label` values (ADR-057 D3), so this defect class cannot
  silently recur.
- **ADDED** `REQ-SWE-005` — a Playwright e2e test walks the setup wizard
  against a genuinely first-run instance (server-side setup app-config reset,
  not just a fresh browser context — see design.md on why the two are not the
  same guarantee here) and asserts English copy on every step.

## Impact

- **Affected spec**: new capability `setup-wizard-english` (this app has no
  existing i18n-specific spec; `first-time-setup`
  (`openspec/specs/first-time-setup/spec.md` if present, else the archived
  change) covers the wizard's *functional* contract — gating, server-side
  actions — and is unaffected; this change is purely about the *language* of
  its declared strings).
- **Affected code**: `src/manifest.json` (`.setup.steps[]`, ~14 `pages[]`
  entries + widget titles, `menu[]` labels), `l10n/en.json`, `l10n/nl.json`,
  `tests/l10n/check-l10n.js` and/or `tests/l10n/check-l10n-parity.js`,
  `.github/workflows/l10n.yml`, new `tests/e2e/setup-wizard-english.spec.ts`.
- **Non-goals**:
  - **Property and enum renames are explicitly OUT of scope.** PRs #534
    (`rename 137 more Dutch property names, with migration`) and #560
    (`translate enum values to English, with the Dutch kept in l10n`) already
    did this class of work fleet-wide in shillinq, with data migrations. This
    change touches only `manifest.json` `title`/`body`/`label` DISPLAY strings
    and `l10n/*.json` — no schema property names, no enum values, no stored
    data, no migration.
  - **Fixing `CnAppRoot`/`CnSetupWizard` to actually translate
    `manifest.setup.steps` at render time is OUT of scope** — that is
    `@conduction/nextcloud-vue` library code, a different repo. This change
    makes the manifest ADR-007-compliant (English source, Dutch in `nl.json`)
    so that fix can consume it the moment it lands; until then the wizard
    renders in English for every locale (a regression from "always wrong
    Dutch-as-source" to "correct source, not yet locale-routed" — not a
    regression from a working translated state, since none exists today). A
    follow-up `nextcloud-vue` change is recommended (see design.md Open
    Questions) but is not created by this change.
