# Design — Member 10: i18n

## Scope

This `kind: code` member extracts BBV UI strings into translation keys
and supplies English + Dutch catalogues. It changes no business logic.

## Decisions carried from the giant (REQ-BBVW-009)

Translation keys with their Dutch values, e.g.:

| Key | English | Dutch |
|---|---|---|
| `BBV Compliance Dashboard` | BBV Compliance Dashboard | BBV-conformiteitsoverzicht |
| `Budget Mapping` | Budget Mapping | Budgetopbrengstoewijzing |
| `Programme Code` | Programme Code | Programmacode |
| `Allocation Percentage` | Allocation Percentage | Toewijzingspercentage |
| `Compliance Status` | Compliance Status | Nalevingsstatus |
| `On-Track` / `At-Risk` / `Non-Compliant` / `Unconfigured` | … | Op schema / Risico / Niet-conform / Niet geconfigureerd |

## Reuse

| Surface | Mechanism |
|---|---|
| Vue strings | `this.t('shillinq', 'key')` (ADR-007) |
| PHP strings | `$this->l10n->t('key')` |
| Catalogues | `l10n/en.json` (source), `l10n/nl.json` |

English is the source-of-truth (ADR-025); Dutch is a required
translation. Dutch uses sentence case, not title case.

## Security (ADR-005)

No security surface — string extraction only. Translation values must
not interpolate untrusted input into markup.
