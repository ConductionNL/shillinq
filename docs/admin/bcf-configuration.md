---
sidebar_position: 10
title: BCF Configuration
description: Administrator playbook for the Shillinq BCF VAT compensation feature — RBAC, quarterly schedule, DigiKoppeling source, settlement webhook, and rollback procedures.
---

# BCF Configuration

This guide is for administrators rolling out the **BCF (Btw-compensatiefonds /
VAT Compensation Fund)** claim feature for one or more public-sector
administrations. End-user instructions live in
[`docs/user-guide/bookkeeping/bcf-vat-compensation.md`](../user-guide/bookkeeping/bcf-vat-compensation.md).

The feature is **declarative-by-default per ADR-031** — the schema fragment
`lib/Settings/register.d/bookkeeping-bcf-vat-compensation.json` declares the
data model, lifecycle, aggregation, approval-chain, and settlement webhook;
the repair step `InitializeSettings.php` registers the quarterly
`ScheduledWorkflow`; and the pure-logic engine
(`BcfClaimService` + `BcfClaimGuard` + `BcfCompensationCalculator`) computes
the compensable total server-side. No DigiKoppeling client ships in Shillinq
itself.

## Prerequisites

- Shillinq is installed and the **OpenRegister** + **OpenConnector**
  apps are enabled on the Nextcloud instance.
- The instance is reachable from the OpenConnector outbound network
  (DigiKoppeling endpoints are TLS-only and certificate-pinned).
- Your administration's `administrationType` is `gemeente`,
  `provincie`, or `waterschap`.

## RBAC setup

The feature ships three claim-specific roles. Wire them up before
operators start drafting claims.

| Role | Permissions |
| --- | --- |
| `bcf-viewer` | Read-only on the BCF-claims index and detail pages. Cannot create, edit, or transition. Suitable for auditors and stakeholders. |
| `bcf-operator` | All `bcf-viewer` permissions, plus create/edit `draft` claims, attach files, edit operator notes, submit a draft for approval. Cannot approve their own submissions. |
| `bcf-administrator` | All `bcf-operator` permissions, plus approve/reject submissions, perform manual `accepted → settled` fallback transitions, and edit administration-wide BCF settings. |

Wire roles up from **Nextcloud → Settings → Users → Groups**. Each role
maps to a group; assign the group to a user to grant the role. The
implicit fourth role is **global admin**, which can do everything.

A typical small gemeente has 1-2 `bcf-administrator` users (the
controller and a backup) and 2-5 `bcf-operator` users (the finance
team).

The role membership is enforced server-side via
`AdministrationContextService::canAccess()` — cross-administration
calls are masked as 404 (ADR-005 IDOR-safe) so a `bcf-operator` for
administration A cannot see or modify claims for administration B.

## Quarterly schedule

The repair step `InitializeSettings::registerBcfQuarterlyDigikoppelingWorkflow()`
registers the workflow on every install/upgrade:

| Field | Default |
| --- | --- |
| Slug | `shillinq-bcf-quarterly-digikoppeling-submission` |
| Engine | `openconnector` |
| Workflow id | `digikoppeling-bcf` |
| Interval | 7776000s (90 days — quarterly) |
| Cron equivalent | `0 0 1 */3 *` (first day of each quarter) |
| Target schema | `BcfClaim` |
| `administrationType` filter | `gemeente`, `provincie`, `waterschap` |

Adjust interval or target from the **OpenRegister →
ScheduledWorkflows** admin UI when Belastingdienst deadlines shift.
Registration is idempotent: the repair step matches by slug, so
re-runs never duplicate the workflow.

### How a quarterly run works

1. The cron fires on the first day of the quarter at 09:00.
2. The workflow walks every `BcfClaim` in state `submitted` for the
   previous closed quarter.
3. For each, it invokes the OpenConnector `digikoppeling-bcf` source
   with the claim payload (`administrationId`, `claimQuarter`,
   `totalCompensableAmount`, `breakdown`, `attachmentUri`).
4. On success the source stamps `submittedOn` on the claim.
5. On a transient failure (network, certificate) the source retries
   with exponential backoff (its own concern, not Shillinq's).
6. On a permanent failure the operator is notified and the claim
   stays in `submitted` state until the next scheduled run.

## DigiKoppeling source configuration

The `digikoppeling-bcf` OpenConnector source is owned by the
OpenConnector team — Shillinq references it symbolically. The
expected contract is:

- **Input**: a `BcfClaim` object payload (per
  `lib/Settings/register.d/bookkeeping-bcf-vat-compensation.json`).
- **Auth**: TLS client certificate pinned to your administration's
  DigiKoppeling identity. Renew before the certificate expires —
  expired certificates surface in the workflow output as a permanent
  failure.
- **Output (success)**: HTTP 2xx; the source confirms reception. The
  source must asynchronously deliver the settlement webhook.
- **Output (settlement webhook)**: a CloudEvent of type
  `nl.conduction.bcf-claim-settled` posted to OpenRegister's generic
  webhook handler. Payload:

```json
{
  "type": "nl.conduction.bcf-claim-settled",
  "objectId": "<BcfClaim.id>",
  "data": {
    "state": "settled",
    "settledAmount": 120000,
    "settledDate": "2026-02-15"
  }
}
```

Verify the source is registered in OpenConnector before going live:
**OpenConnector → Sources → digikoppeling-bcf**. If the source is
absent, the workflow logs `digikoppeling-bcf source not found` on every
run.

## Settlement webhook routing

The webhook routing is declared on the `BcfClaim` schema fragment
under `x-openregister-webhooks.bcf-claim-settled`. OR's generic
webhook handler:

1. Receives the inbound CloudEvent (auth verified by OR — this app
   ships no webhook controller).
2. Resolves the `BcfClaim` by `objectId` (administration scope
   enforced via the audit-trail context).
3. Applies the bound `settle` transition.
4. Updates `state ← data.state`, `settledOn ← data.settledDate`,
   `settledAmount ← data.settledAmount`.
5. Records `webhook.received` + `webhook.applied` in the audit trail
   with the event id, timestamp, and payload hash.

A lost webhook does **not** block. The operator can manually
transition `accepted → settled` from the detail page (logged as
`source: operator` in the audit trail), and the claim reconciles when
the webhook lands late.

## Audit trail export

The audit trail is immutable per ADR-022 and the Archiefwet retention
policy. Export it for court or auditor review:

1. Sign in as `bcf-administrator` (or global admin).
2. Open the claim detail page.
3. From the **Audit Trail** sidebar tab click **Exporteer audit-trail
   (CSV)**.
4. The export contains every state change with: timestamp, actor (user
   id and display name; or `webhook:digikoppeling-bcf`), action,
   before-state, after-state, payload hash, and any associated
   approval/rejection comment.

For administration-wide exports use **OpenRegister → Audit Trails**
with `objectTypes=BcfClaim` and the administration scope.

## Rollback procedures

If you need to disable the feature:

1. Disable the scheduled workflow: **OpenRegister → ScheduledWorkflows
   → shillinq-bcf-quarterly-digikoppeling-submission → Disable**.
   In-flight claims stop submitting; no new payloads reach
   Belastingdienst.
2. Hide the **Overheid → BCF-claims** menu entry: remove
   `bcfCompensable` administrations from the visibility predicate, or
   uninstall Shillinq.
3. Existing `BcfClaim` records remain queryable — registers are
   non-destructive. Operators can export them as PDF/CSV for archival.
4. To re-enable, re-enable the workflow and surface the menu entry
   again. Submitted but unsent claims will be sent on the next
   scheduled run.

To remove the feature entirely (uninstall pathway):

1. Revert the implementing PR.
2. Run the repair step in down-direction: deletes the
   `ScheduledWorkflow` and reverts the `BbvAccountMapping` extension.
3. `BcfClaim` records remain — registers are non-destructive.
4. Export claims for archival before downgrade.

## Operational checklist

Before going live on a fresh installation:

- [ ] OpenRegister + OpenConnector apps enabled.
- [ ] `digikoppeling-bcf` source registered in OpenConnector.
- [ ] DigiKoppeling TLS client certificate uploaded and pinned.
- [ ] `bcf-administrator`, `bcf-operator`, `bcf-viewer` groups exist
      and at least one user is assigned to each.
- [ ] BBV mapping is complete for the administration (every
      compensable RGS account is flagged `bcfCompensable: true`).
- [ ] T2 period-close is run for every quarter you intend to claim.
- [ ] The scheduled workflow appears in **OpenRegister →
      ScheduledWorkflows** with the slug
      `shillinq-bcf-quarterly-digikoppeling-submission` and is
      **enabled**.
- [ ] A dry-run claim is created in `draft`, approved, and submitted
      so the integration is verified end-to-end before a real claim
      window opens.

## Where this fits

- Capability spec: `openspec/changes/bookkeeping-bcf-vat-compensation/specs.md`
- Schema fragment: `lib/Settings/register.d/bookkeeping-bcf-vat-compensation.json`
- Repair step: `lib/Repair/InitializeSettings.php`
- Server engine: `lib/Service/BcfClaimService.php` + `lib/Lifecycle/BcfClaimGuard.php` + `lib/Service/BcfCompensationCalculator.php`
- HTTP API: `GET /apps/shillinq/api/bcf/compensation` (`BcfClaimController::compensation`)
- User guide: `docs/user-guide/bookkeeping/bcf-vat-compensation.md`
