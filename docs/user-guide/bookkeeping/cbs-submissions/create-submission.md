# Create a CBS Submission

This guide walks through generating, validating, and submitting a CBS IV3
submission for a single reporting period.

## Prerequisites

- An active administration with a chart of accounts and posted general-ledger
  entries for the reporting period.
- The organisation metadata configured on the administration record:
  - `legalName` (string)
  - `kvkNumber` (8 digits)
  - `taxIdentificationNumber` (matches `NL[0-9]{10}B[0-9]{2}`)
- Operator role on the administration (RBAC enforced by OpenRegister).

## Step 1 — Create a draft submission

Navigate to **Bookkeeping → CBS Submissions** and click **New submission**.
Fill in:

- **Administration** — pick the entity scope.
- **Reporting period** — start + end date (inclusive).
- **Organisation metadata** — pre-filled from the administration; verify and
  edit if needed.

Click **Generate**. The service:

1. Loads active accounts for the administration.
2. Loads GL lines for the period (`postedAt` within the period window).
3. Resolves the account-range → CBS-classification mapping (per-administration
   override or canonical RGS default).
4. Aggregates lines by classification (first matching range wins).
5. Persists a `CBSLine` per non-zero classification.
6. Generates the IV3-extended JSON envelope with a SHA-256 checksum.

The submission lands in **Draft** state.

## Step 2 — Validate

Click **Validate Submission** on the draft. The validator checks:

- **kvkNumber** matches the pattern `^[0-9]{8}$`.
- **taxIdentificationNumber** matches `^NL[0-9]{10}B[0-9]{2}$`.
- The submission has at least one CBS line.
- No two CBS lines reference the same account range but different
  classifications.
- The reporting period dates are set.

Validation errors are surfaced as a list; fix them at source (mapping
configuration, organisation metadata, or GL coverage) and re-generate.

## Step 3 — Submit to CBS

When validation passes, click **Submit to CBS**. The submission transitions to
**Submitted**; the IV3 JSON is dispatched via the configured OpenConnector
adapter (dormant by default — until the adapter is wired the submission is
recorded in the audit log but never sent to a third party).

Subsequent **Accepted** / **Rejected** transitions are driven by the CBS
acknowledgement (manual or via the connector callback).

## Audit trail

Every transition (draft → validated → submitted → accepted/rejected) is
recorded by the OpenRegister audit trail (`audit: true` on the submission +
line schemas). The audit pack export ships the trail alongside the IV3 JSON.

## Troubleshooting

- **"Submission has no lines"** — the period contains no posted GL entries,
  or the mapping skips every account number. Verify the mapping covers the
  posted accounts.
- **"Tax identification number invalid"** — the administration metadata is
  missing or malformed. Fix it on the administration record before
  re-generating.
- **Account-range collision** — two mapping entries cover overlapping ranges
  with different classifications. Edit the override JSON to remove the
  overlap.
