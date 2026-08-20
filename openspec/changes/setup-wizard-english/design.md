# Design: setup-wizard-english

## Context

- **ADR-007 (i18n)**: English is the source/primary language; `l10n/en.json`
  is identity-mapped (key == value); Dutch strings used as `t()` keys or
  manifest label/title values are a violation; sentence case; acronyms and
  proper nouns keep their casing.
- **ADR-042 (App First-Time Setup Wizard)**: `manifest.setup.steps[]` is
  rendered by the shared `CnSetupWizard` (`@conduction/nextcloud-vue`); shillinq
  is ADR-042's canonical "block-on-required" case — the wizard gates the whole
  app shell until `country` / `organisation` / `rgs-template` / `administration`
  are set (`lib/Controller/SetupController.php::status()`).
- **ADR-057 (i18n Locale Parity and Key Hygiene)**: parity MUST be a CI gate in
  both directions (D1); apps with a dead, unwired `check-l10n-parity.js` are
  named explicitly as the pattern to fix (D2); Dutch-as-key/label/title is a
  hard-fail mechanical gate (D3).

## How manifest strings are (not) yet translated — verified

I traced the actual render path in the installed `@conduction/nextcloud-vue`
component tree (checkout at `/home/rubenlinde/supp-audit/repos/nextcloud-vue`,
`1.0.0-beta.217`; shillinq's `package.json` pins `^2.3.0` — the exact wiring
should be re-confirmed against 2.3.0 at apply time, see Open Questions, but the
architecture below is stable across both):

- `CnAppRoot.vue` renders the gating instance
  (`phase === 'setup'`, ~line 170) and the optional/dismissible instance
  (~line 375) as `<CnSetupWizard :steps="manifest.setup.steps" ... />` —
  the **raw** manifest array, unmodified.
- `CnSetupWizard.vue` renders `step.title`, `step.body` and
  `option.label` **literally** — e.g. `<NcNoteCard :heading="step.title">{{
  step.body }}</NcNoteCard>`. There is no `t()`/`translate()` call anywhere
  near these three fields. Contrast with the component's OWN chrome strings
  (`dialogTitle`, `submitLabel`, `cancelLabel`, …), which all default through
  `t('nextcloud-vue', '...')`.
- `CnAppRoot` DOES expose exactly this mechanism for other manifest-driven
  text: a `translate` **prop** ("Translate function from the consuming app,
  typically `(key) => t(appId, key)`. Provided to descendants as
  `cnTranslate`" — `CnAppRoot.md`), used for menu section labels
  (`translate(section.label)`, `CnAppRoot.vue:521`), the walkthrough copy, and
  the settings-dialog titles. It is simply **not applied** to
  `manifest.setup.steps` before either `<CnSetupWizard>` instantiation.

**Consequence**: today, whatever language `manifest.json`'s `setup.steps[]`
contains is what every user sees, in every locale — there is no
locale-switching happening at all. Rewriting the manifest to English source is
still correct and required (ADR-007 governs the SOURCE regardless of what
currently consumes it, and it's the necessary precondition for any future
translation), but on its own it flips the wizard from "always Dutch" to
"always English" — it does not, by itself, give an `nl`-locale Dutch civil
servant a Dutch wizard again. Closing that gap needs `CnAppRoot`/`CnSetupWizard`
to route `manifest.setup.steps` through the same `translate` prop
`CnAppNav` already uses for `menu[].label`. That is `@conduction/nextcloud-vue`
library code — a different repo, out of scope for this per-app change (see
Non-Goals in proposal.md and Open Questions below).

## Full wizard string inventory (`src/manifest.json` `.setup.steps[]`)

Every field below is Dutch source text today and is in scope for REQ-SWE-001.
Jurisdiction-specific legal-entity labels are marked *(keep as-is — ADR-007
proper-noun/acronym exception)*; the rest need an English source string, added
to `l10n/en.json` as `"<English>": "<English>"` with the current Dutch value
moved to `l10n/nl.json` as `"<English>": "<Dutch original>"`.

| Path | Current (Dutch) | Disposition |
|---|---|---|
| `steps[0].title` | `Welkom bij Shillinq` | → `Welcome to Shillinq` |
| `steps[0].body` | `Kies eerst het land (juridische regio) en het organisatietype, ...` | → English (same content) |
| `steps[1].title` | `Juridische regio (land)` | → `Legal region (country)` |
| `steps[1].body` | `In welk land is deze organisatie juridisch gevestigd? ...` | → English (same content) — **not in the originating audit list** |
| `steps[1].options[0].label` | `Nederland` | → `Netherlands` |
| `steps[1].options[1].label` | `België` | → `Belgium` |
| `steps[1].options[2].label` | `Duitsland` | → `Germany` |
| `steps[2].title` | `Organisatietype` | → `Organisation type` — **not in the originating audit list** |
| `steps[2].optionsByParent.nl[0].label` | `Gemeente` | → `Municipality` — **not in the originating audit list** |
| `steps[2].optionsByParent.nl[1].label` | `Provincie` | → `Province` |
| `steps[2].optionsByParent.nl[2].label` | `Waterschap` | → `Water authority` (matches the existing `Waterschapsbelastingen` → "Water authority taxes" page-title precedent already in this manifest) |
| `steps[2].optionsByParent.nl[3].label` | `ZZP` | *keep as-is* — Dutch sole-trader legal-form acronym, no natural English equivalent (parallel to `GmbH` below) |
| `steps[2].optionsByParent.nl[4].label` | `MKB` | *keep as-is* — Dutch SME legal-form acronym |
| `steps[2].optionsByParent.be[0].label` | `VZW` | *keep as-is* — Belgian non-profit legal form |
| `steps[2].optionsByParent.be[1].label` | `Besloten Vennootschap (BV)` | *keep `BV`*; the surrounding gloss may translate if the app decides consistency requires it — see Open Questions | 
| `steps[2].optionsByParent.be[2].label` | `Eenmanszaak` | *keep as-is*, matching `Einzelunternehmen`/`Verein` below staying untranslated for the same jurisdiction-term reason |
| `steps[2].optionsByParent.de[*].label` | `GmbH` / `Verein` / `Einzelunternehmen` | *keep as-is* (already the established pattern — not Dutch, not flagged by the audit, listed here only for completeness) |
| `steps[3].title` | `Rekeningschema (RGS)` | → `Chart of accounts (RGS)` — **not in the originating audit list** |
| `steps[3].body` | `Een rekeningschema (RGS – Referentie GrootboekSchema) is ...` | → English (same content) |
| `steps[3].options[0/1].label` | `MKB` / `ZZP` | *keep as-is* (same acronyms as above) |
| `steps[3].options[2].label` | `BBV (overheid)` | → `BBV (government)` — `BBV` (Besluit Begroting en Verantwoording) is a standing Dutch government-accounting standard, kept untranslated per the acronym exception; `overheid` is plain Dutch prose and translates |
| `steps[4].title` | `Administratie aanmaken` | → `Create administration` |
| `steps[4].body` | `Maak de standaardadministratie aan. ...` | → English (same content) |
| `steps[5].title` | `Rekeningschema en referentiedata laden` | → `Load chart of accounts and reference data` |
| `steps[5].body` | `Laad het gekozen rekeningschema ... Klik op 'Run' om te starten.` | → English (same content) |
| `steps[6].title` | `Klaar` | → `Done` |
| `steps[6].body` | `Controleer je keuzes hieronder en rond de installatie af.` | → English (same content) |

**Correction to the originating audit**: 8 of the 17 Dutch fields above
(`steps[1].body`, `steps[2].title`, all 5 `optionsByParent.nl[]` labels,
`steps[3].title`) were not in the audit's field list. This design targets the
full inventory above, verified by reading every field in `setup.steps[]`
directly (`python3 -c "import json; ..."` dump against HEAD).

## Full Dutch page-title / widget-title / menu-label inventory

The originating audit said "4 Dutch page titles... find all mechanically." A
mechanical scan of `pages[].title`, `pages[].config.widgets[].title`, and
`menu[].label` (recursive through `children[]`) found **14 distinct Dutch
strings across 16 occurrences** — roughly 3.5× the reported count:

| `pages[]` index | `id` | `title` (or widget/menu label) |
|---|---|---|
| 74 | `Bewaartermijnen` | `Bewaartermijnen` |
| 75 | `BewaartermijnenDetail` | `Bewaartermijn` |
| 76 | `BewaartermijnenDashboard` | `Bewaartermijnen — Dashboard` |
| 120 | `Aansluitingen` | `Aansluitingen` (also the `menu[]` label for this nav section) |
| 121 | `AansluitingDetail` | `Aansluiting` |
| 122 | `AansluitingResultaten` | `Aansluiting Resultaten` |
| 123 | `AansluitingResultDetail` | `Aansluiting Resultaat` |
| 126 | `Urenregistratie` | `Urenregistratie` |
| 134 | `SchatkistPositie` | `Schatkist-positie` (page title) + `Schatkist-positie` (`config.widgets[0].title`) |
| 154 | `OninbareAfschrijvingen` | `Oninbare Afschrijvingen` |
| 155 | `OninbareAfschrijvingDetail` | `Oninbare Afschrijving` |
| 175 | `TemporaryDifferences` | `Tijdelijke verschillen` |
| 176 | `TemporaryDifferenceDetail` | `Tijdelijk verschil` |
| 181 | `TaxRateReconciliations` | `ETR-aansluiting` (half-English) |
| 182 | `TaxRateReconciliationDetail` | `ETR-aansluiting` |

`id`/`route` values (e.g. `Aansluitingen`, `SchatkistPositie`) are internal
identifiers, not user-facing strings — **out of scope** (a rename would be a
structural/property change, explicitly excluded per Non-Goals). Only the
`title`/`label` DISPLAY strings are in scope for REQ-SWE-002. Suggested
English titles follow the same sentence-case + existing-precedent pattern as
the wizard table above (e.g. `Bewaartermijnen` → "Retention periods",
`Aansluitingen` → "Reconciliations" — note a page already titled
`Reconciliations` exists at a different `menu[]` entry; disambiguate at apply
time by reading both pages' `config.schema` to confirm they are genuinely
distinct features before merging or diverging the English names).

## `l10n/en.json` / `l10n/nl.json` parity gap (HEAD)

`en.json` 2794 keys, `nl.json` 2744 keys.

**53 en-only keys** (added without a Dutch translation) — full list, e.g.:
`"Administration ID"`, `"Allocation must be between 0 % and 100 %."`,
`"Applies To"`, `"Choose a CAMT.053 bank statement file"`, `"Creating…"`,
`"Data Type"`, `"Loading scorecards…"`, `"On Hand"`, `"Order"`, `"Owned By"`,
`"Product ID"`, `"Required"`, `"SKU"`, `"Sending…"`, `"Unit Price"`, and 38
more (see `l10n/en.json` minus `l10n/nl.json` keys at apply time — the set is
large enough that reproducing all 53 here would drift from HEAD by the time
this is implemented; `node tests/l10n/check-l10n.js` plus a one-line diff
script reproduces the exact list).

**3 nl-only keys** (present in `nl.json`, absent from `en.json` — orphaned):
- `"Analytical dimensions"` → nl value `"Analytische dimensies"`
- `"Sales"` → nl value `"Verkoop"`
- `"Valuation"` → nl value `"Voorraadwaardering"`

These read as English-shaped KEYS with Dutch VALUES that used to have an
`en.json` sibling and lost it (rename-without-cleanup, or the `en.json` side
was reverted). Apply-time work: grep `src`/`manifest.json` for whether these
keys are still referenced by any `t()` call or manifest label; if yes, restore
the `en.json` entry (`"Analytical dimensions": "Analytical dimensions"`, etc.);
if no living reference exists, delete the dead `nl.json` entries instead of
inventing an unused `en.json` counterpart.

## Fresh-context e2e: server state, not just browser state

This app's own `tests/e2e/global-setup.ts` already demonstrates the relevant
distinction: it persists a storage state that **pre-seeds a localStorage key**
(`cn-walkthrough-seen:shillinq`) precisely so the walkthrough tour does NOT
show on every spec run — because a plain "fresh browser context" is a CLIENT
guarantee, not a SERVER one.

`SetupController::status()` (`lib/Controller/SetupController.php`) gates the
wizard on **server-side app-config values**
(`legal_country`/`legal_region`/`rgs_template`/`administration_id`/
`setup_seed_done`), not on anything client-side. A CI instance that has
already run the setup wizard once (or was seeded pre-configured via
`tests/e2e/ci-seed.sh`) will report `completed: true` regardless of how fresh
the Playwright browser context is — **a fresh context alone does NOT
reliably reproduce the wizard**. The e2e test (REQ-SWE-005) MUST explicitly
reset the setup app-config keys server-side (e.g. `occ config:app:delete
shillinq legal_country` etc., or a test-only reset endpoint/fixture) before
asserting the wizard renders, and must NOT rely on browser-context freshness
as its only guarantee. This mirrors this repo's own documented lesson about
`WALKTHROUGH_SEEN_KEY` — the fix there was pre-seeding client state to SKIP a
first-open surface; here the test needs the opposite (reliably CLEARING
server state to TRIGGER one).

## Declarative-vs-imperative / Seed Data (ADR-001, ADR-031)

Not applicable. This change introduces no OpenRegister schema, no lifecycle,
aggregation, calculation, notification, or relation behaviour, and no new
seed-able object type — it edits string VALUES in `manifest.json` and
`l10n/*.json` plus test/CI tooling. No design decision under ADR-031 is
triggered.

## Open Questions

1. **Verify the render path against the actually-pinned `@conduction/nextcloud-vue ^2.3.0`**, not the `1.0.0-beta.217` checkout used for this design (different repo, may have moved). If `CnSetupWizard`/`CnAppRoot` in 2.3.0 already route `manifest.setup.steps` through `translate`, REQ-SWE-001's English-source rewrite is sufficient on its own for correct `nl`-locale rendering, and the "wizard renders in English for every locale" caveat in the proposal's Non-Goals no longer applies. If it does not, file a companion `nextcloud-vue` change extending `translate(section.label)`'s existing pattern to `manifest.setup.steps` before either `<CnSetupWizard>` instantiation in `CnAppRoot.vue` (~lines 170, 375).
2. **`Besloten Vennootschap (BV)`** — translate the descriptive gloss to `Private limited company (BV)` for consistency with the fully-English `nl` list, or leave the Belgian legal term as-is (matching the German list's precedent of staying fully untranslated)? Recommend the latter for jurisdiction-term consistency, but flagging as a judgment call for the implementer/reviewer.
3. **`Aansluitingen` vs `Reconciliations`** — confirm at apply time (via `config.schema`) whether these are the same feature under two names or genuinely distinct, before choosing the English title, to avoid two nav entries that read identically.
