# Notes for the upcoming nav-restructure change (`Aansluitingen` vs `Reconciliations`)

Written during `setup-wizard-english` apply, resolving design.md Open
Question 3 ("`Aansluitingen` vs `Reconciliations`") and orchestrator
decision 2 ("translate the title only... verify from the feature's actual
content... record any overlap for the upcoming nav change").

## What was checked

Both nav entries live side by side under the `Bookkeeping` menu section in
`src/manifest.json`, and both are genuinely live features:

| id | route | schema | What it actually is |
|---|---|---|---|
| `Reconciliations` | `/bookkeeping/reconciliations` | `BankReconciliation` | Bank-statement reconciliation sessions: one row per bank-account+period, with opening/closing balance, variance, and a sign-off workflow (`ReconciliationDetail` → `ReconciliationReport`, REQ-REC-001/006). |
| `Aansluitingen` | `/aansluitingen` | `Aansluiting` / `AansluitingResult` | A generic tie-out FRAMEWORK: declared tie-out definitions (source A vs source B, expected relationship, tolerance, e.g. BTW/AR/AP cross-checks), each with per-period *computed* results on a second index (`AansluitingResultaten`/`AansluitingResultDetail`, REQ-AANS-008). Its own `_note` in the manifest literally glosses `Aansluiting` as "(tie-out)". |

These are **not** the same feature under two names — `Reconciliations` is
bank-statement-specific; `Aansluitingen` is a schema-driven generic
tie-out engine that can express BTW/AR/AP (and, per its `reconciliationType`
column, potentially other) cross-checks, with its own two-tier
definition/result model that `Reconciliations` does not have.

## Decision made here

Translating `Aansluitingen` → "Reconciliations" (the literal dictionary
translation, and the orchestrator's suggested default) would have created
**two identically-titled, adjacent nav entries** for genuinely different
features — worse than either the current Dutch or a disambiguated English
name. Per this change's Non-Goals ("do NOT merge or restructure any
features"), no merge was performed; instead the English titles were chosen
to name what the feature actually is, matching its own manifest `_note`:

- `Aansluitingen` (page + menu label) → **"Tie-outs"**
- `AansluitingDetail` → **"Tie-out"**
- `AansluitingResultaten` → **"Tie-out results"**
- `AansluitingResultDetail` → **"Tie-out result"**

`Reconciliations` (bank statements) is untouched — it was already English.

## Input for the nav-restructure change

When the future nav change reorganises `Bookkeeping` into fewer clusters,
consider whether `Tie-outs` (BTW/AR/AP cross-check framework) and
`Reconciliations` (bank statements) should land in the same cluster (both
are "make sure two numbers agree" workflows) or stay visually separated —
they are complementary, not duplicate, so either grouping is defensible,
but **do not rename `Tie-outs` back to "Reconciliations"** without also
resolving the adjacency with the existing `Reconciliations` entry, or the
two-identically-named-entries problem this note exists to avoid recurs.
