# Rule catalogue — file schema

Each `*.json` file in this directory is **one domain** of bookkeeping rules,
loaded and merged by `OCA\Shillinq\Standards\RuleCatalogue`. Rules are versioned
static reference data (laws/standards), not OpenRegister config — see
`docs/standards/`.

## File shape

```json
{
  "domain": "invoicing",
  "version": "2026-06",
  "rules": [
    {
      "id": "en16931-br-01",
      "domain": "invoicing",
      "jurisdiction": "EU",
      "framework": "en-16931",
      "source": "EN 16931 BR-01",
      "statement": "An Invoice shall have a Specification identifier (BT-24).",
      "severity": "mandatory",
      "machineCheckable": true,
      "effectiveDate": null,
      "sourceUrl": "https://ec.europa.eu/digital-building-blocks/sites/spaces/DIGITAL/pages/467108974/"
    }
  ]
}
```

## Field contract (every rule)

| field | type | notes |
|---|---|---|
| `id` | string | globally unique, kebab-case, framework-prefixed (e.g. `vatdir-art226-3`, `ias2-09-lcnrv`) |
| `domain` | string | `invoicing` `vat` `retention` `ledger-integrity` `chart-of-accounts` `reporting` `recognition` `measurement` `presentation` `disclosure` `tax` |
| `jurisdiction` | string | ISO alpha-2, `EU`, `US`, or `global` (IFRS) |
| `framework` | string | the standard/law key, e.g. `en-16931` `vat-2006-112` `gobd` `hgb` `pcg` `rj` `ifrs-15` `ias-2` `asc-606` `irc` |
| `source` | string | human citation: `EN 16931 BR-CO-10`, `VAT Directive art. 226(6)`, `IFRS 15.31`, `§147 AO` |
| `statement` | string | the rule in one sentence (imperative where possible) |
| `severity` | string | `mandatory` `conditional` `recommended` |
| `machineCheckable` | boolean | can a bookkeeping engine validate/enforce it automatically |
| `effectiveDate` | string\|null | ISO date if dated, else null |
| `sourceUrl` | string | authoritative URL |

## Rules for authors

- **Never fabricate** a citation. If a rule's exact reference isn't verified, set
  `source` to the best-known level and append `(verify)` in the statement.
- `id` must be unique across **all** files in this directory.
- Keep statements atomic — one obligation per rule.
