# Proposal: bookkeeping-bank-connectors

`kind: config` per ADR-032 — the centre of mass is declarative schema
metadata + manifest entries + scheduled-workflow records. No PHP service
classes are authored.

## Summary

Introduce the **PSD2 bank connectors** capability for Shillinq as part of
the Tier 4 advanced bookkeeping engine (per `adr-001-bookkeeping-tier-roadmap.md`).
This change declares the `BankConnection` register with `pending → active → expiring → expired / revoked` lifecycle (per ADR-031);
PSD2 Account Information Service (AIS) feeds consumed from openconnector by
source slug (per ADR-022) — no aggregator HTTP clients, no OAuth flows,
no credentials in shillinq; transaction polling as an OpenRegister
`ScheduledWorkflow` materialising CAMT.053 attachments via docudesk;
consent expiry warning as a declarative time-based auto-transition (14 days
before `consentExpiresAt`); new-transaction notifications as
`x-openregister-notifications`.

This change conforms to the shared [`nextcloud-app`](../../specs/nextcloud-app/spec.md)
spec for app structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

## Motivation

PSD2 AIS (Account Information Service) feeds reduce reconciliation effort
from days to minutes; every modern bookkeeping product (Exact, AFAS,
Twinfield, Yuki) ships them. Without PSD2 connectivity, Shillinq forces
operators back to manual MT940 / CAMT.053 imports, which is a tier-defining
gap. Bank connector features (automatic import from 21,000+ financial
institutions, real-time transaction sync) are the top market-demand items
in this proposal (93 tender mentions combined). The split between credentials
(openconnector) and connection state (shillinq) keeps the data model
auditor-friendly: no risk of leaking secrets via register exports.

## Affected Projects

- [x] Project: shillinq — adds 1 new register/schema (`BankConnection`)
  to `lib/Settings/shillinq_register.json`, adds 1 manifest navigation
  entry, registers 1 scheduled workflow (transaction polling), declares
  notifications on `BankStatement`.
- [ ] Project: openregister — no source changes; this change consumes
  `x-openregister-lifecycle` (with time-based transitions),
  `x-openregister-notifications`, audit-trail-immutable, RBAC,
  `ScheduledWorkflow`.
- [ ] Project: openconnector — no source changes; this change *consumes*
  openconnector `Source` records for PSD2 aggregators (Tink, Klarna Kosma,
  Plaid-EU, Yapily). Credentials live in openconnector.
- [ ] Project: docudesk — CAMT.053 statements attached via docudesk
  attachment URI per ADR-022.

## Scope

### In Scope

- One new capability spec (`bookkeeping-bank-connectors`) — see the
  `specs/` folder.
- `BankConnection` register declaration with `pending → active → expiring → expired / revoked` lifecycle and time-based auto-transition
  (`active → expiring` 14 days before `consentExpiresAt`).
- PSD2 aggregator integration consumed from openconnector by source slug
  per ADR-022; no aggregator HTTP clients, no OAuth flows in shillinq.
- No credentials on `BankConnection` (REQ-BC-002 / REQ-BC-003); schema
  scanned for `*Secret*` / `*ClientId*` / `*ApiKey*` / `*Token*` field
  names rejects matches.
- Transaction polling as an OpenRegister `ScheduledWorkflow` materialising
  CAMT.053 attachments via docudesk (per ADR-031 path 2 + ADR-022).
- Consent renewal action routes through openconnector's Strong Customer
  Authentication (SCA) flow; operator clicks once, completes SCA in the
  bank's UI, returns.
- New-transaction notifications declared as `x-openregister-notifications`
  on `BankStatement`.
- Manifest navigation entry (Bookkeeping > Bank Connections) using
  `type: index` / `type: detail` renderers; detail page surfaces
  consent-renewal action and remaining-days countdown when `state = expiring`.

### Out of Scope

- **Implementation code** — this is a spec-only change.
- **Aggregator-credential management UI** — openconnector owns credentials;
  shillinq does not surface a credential UI.
- **MT940 import** — pre-existing manual import path stays operational
  (T2 `bookkeeping-bank-reconciliation`); PSD2 is the new automated path.
- **Frontend Vue components** beyond `CnIndexPage` / `CnDetailPage` generic
  rendering.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-bank-connectors`** — declares the `BankConnection` register
with the five-state lifecycle (including time-based auto-transition for
expiry warning), forbids aggregator HTTP clients and credentials in shillinq,
declares transaction polling as a scheduled workflow, and declares
new-transaction notifications.

The spec follows the conduction-schema format (RFC 2119, `### REQ-{NNN}: <name>`,
`#### Scenario:` with exactly 4 hashtags, GIVEN/WHEN/THEN). Each requirement
is prefixed `REQ-BC-*` for traceability.

## New Dependencies

None. This change consumes existing OpenRegister abstractions, operator-configured
openconnector PSD2 source records, and the already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 1 schema (`BankConnection`)
  with `x-openregister-lifecycle` (time-based transition) and
  `x-openregister-notifications` on `BankStatement`.
- `src/manifest.json` — adds 1 navigation entry (Bank Connections)
  with consent-renewal action and remaining-days countdown.
- 1 new `ScheduledWorkflow` record (transaction polling).
- No new PHP services. No new Vue components. No new controllers. No
  new TimedJobs.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle` (including
  time-based transitions), `x-openregister-notifications`,
  audit-trail-immutable, RBAC (`treasurer` role for connection management),
  `ScheduledWorkflow`.
- **openconnector** — operators must have configured at least one PSD2
  aggregator `Source` record (Tink, Klarna Kosma, Plaid-EU, Yapily or similar).
  shillinq references the source by slug; credentials and consent-flow state
  live in openconnector's `Source` registry.
- **docudesk** — CAMT.053 attachments referenced by docudesk attachment URI.
- **T2 `bookkeeping-bank-reconciliation`** — bank-connector transactions feed
  the existing T2 reconciliation surface; no duplicate matching logic.

## Risks

### Risk 1: Bank-connector credentials accidentally land in shillinq

**Severity**: High (security)
**Mitigation**: REQ-BC-002 forbids credentials in shillinq schemas;
REQ-BC-003 forbids HTTP clients; reviewer gates on grep for these patterns
(`*Secret*` / `*ClientId*` / `*ApiKey*` / `*Token*` field names; Guzzle /
Symfony HttpClient / curl_init usages in `lib/Service/Bank*` / `lib/Service/Psd2*`
/ `lib/Service/Aggregator*`); openconnector owns credentials. The split makes
credential rotation an openconnector operation (no shillinq deploy needed).

### Risk 2: PSD2 SCA renewal every 90 days is operator-disruptive

**Severity**: Medium
**Mitigation**: REQ-BC-005 declares a 14-day advance-warning lifecycle
auto-transition (`active → expiring`) and a notification to the configured
recipient. The renewal action itself routes through openconnector's SCA flow
(operator clicks once, completes SCA in the bank's UI, returns). The operator
UX is bounded; no shillinq re-implementation of SCA.

### Risk 3: openconnector PSD2 source slug not yet production-ready

**Severity**: Low–Medium
**Mitigation**: Mature openconnector source registry; implementing cycle verifies
source availability before manifest validator runs. If missing, openconnector
issue filed; spec stays shape-neutral (names slug, not protocol).

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder.
After implementation (separate cycle), rollback follows the standard pattern:
revert the implementing PR. `BankConnection` records are non-destructive;
pre-existing T2 manual MT940 imports remain operational.

## Open Questions

1. **PSD2 aggregator selection per administration** — single global default vs
   per-administration override? REQ-BC-002 supports per-connection aggregator
   selection; the operator UX for the default is settled in the implementing
   cycle's UX review.
2. **Notification recipient resolution** — administrator role members vs
   explicit operator? REQ-BC-005 routes to the configured recipient; default
   fallback path settled in implementing cycle.
