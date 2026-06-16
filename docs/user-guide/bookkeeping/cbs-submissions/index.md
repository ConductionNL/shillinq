# CBS IV3 Submissions

## Overview

CBS (Centraal Bureau voor de Statistiek) is the Dutch statistics authority.
Organisations subject to the statistical reporting obligation must submit a
periodic IV3-extended file aggregating their general-ledger activity into a
fixed classification of revenue, operating costs, depreciation, interest,
taxes, other income, and other expenses.

The CBS Submissions module in Shillinq automates the aggregation, validation,
and IV3 envelope generation. The lifecycle is:

1. **Create** — pick an administration + reporting period + organisation
   metadata (legal name, KVK number, tax identification number).
2. **Generate** — the service reads the active accounts and posted general-ledger
   lines for the period, applies the configured account-range → CBS-classification
   mapping (per administration; defaults to the canonical RGS 4xxx–9xxx ranges),
   accumulates absolute integer-cent amounts per classification, and persists a
   `CBSLine` for each non-zero classification.
3. **Validate** — structural checks (KVK 8 digits, tax-id `NL[0-9]{10}B[0-9]{2}`,
   reporting period present, no duplicate account ranges across classifications).
4. **Submit** — the IV3-extended JSON envelope is generated with format
   `iv3-extended` v1.0, generation timestamp, organisation metadata, line
   payload, and a SHA-256 checksum over the canonical payload.

## Who must submit

- Companies above the CBS statistical thresholds (typically ≥10 FTE or
  ≥EUR 700k turnover for sectoral surveys; ≥250 FTE for the structural
  business statistics);
- Government entities and water-boards subject to the IV3-extended quarterly
  obligation;
- Sectoral-specific obligations (financial sector, healthcare, education) may
  require additional fields beyond the IV3 envelope.

Check your obligation via the CBS portal before configuring the schedule.

## Configuration

The default mapping covers RGS 4xxx–9xxx ranges. Operators override it per
administration via app config:

```
cbs_account_mapping_<administrationId> = '[{"start":"3000","end":"3999","classification":"CustomRevenue","lineNumber":"0900"}, ...]'
```

A malformed override silently falls back to the canonical default and emits a
warning in the Nextcloud log; an empty override is equivalent to "no override".

## Pages

- **CBS Submissions index** — list of submissions per administration, with
  status badge (Draft / Validated / Submitted / Accepted / Rejected) and
  reporting period.
- **Submission detail** — header (organisation, period, status), aggregated
  lines, attached IV3 JSON, audit trail.

## Compliance notes

- Aggregation is integer-cent (REQ-CBS-002) — no float-rounding drift.
- The OpenRegister audit trail records every transition (`audit: true` on the
  submission + line schemas).
- RBAC + multitenancy is enforced by OpenRegister's `ObjectService` — a
  submission is only visible to operators of the owning administration.

## Verification

Run the unit suite to confirm the aggregation + IV3 envelope logic:

```
composer test -- --filter CBSExportServiceTest
```
