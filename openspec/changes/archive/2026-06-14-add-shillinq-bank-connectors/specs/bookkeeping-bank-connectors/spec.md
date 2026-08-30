# Spec: bookkeeping-bank-connectors

**Status:** proposed
**Scope:** shillinq
**Tier:** T4 (advanced engine)
**Depends on:** bookkeeping-bank-reconciliation (T3)

## ADDED Requirements

### Requirement: REQ-BC-001: PSD2 AIS aggregator integrations SHALL be consumed from openconnector per ADR-022

PSD2 Account Information Service (AIS) connectivity to licensed
EU aggregators (Tink, Klarna Kosma, Plaid-EU, Yapily, etc.) MUST
be consumed from openconnector as configured `Source` records —
shillinq MUST NOT embed an aggregator HTTP client, MUST NOT
implement OAuth/SCA flows, and MUST NOT manage aggregator API
versioning. Per ADR-022, when a sibling app provides the
integration, the app consumes it.

#### Scenario: Reviewer confirms no embedded aggregator client

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `Guzzle` / `Symfony\HttpClient` / `curl_init`
  usages in `lib/Service/Bank*` or `lib/Service/Psd2*` or
  `lib/Service/Aggregator*`
- **THEN** no such usages SHALL exist; aggregator calls MUST
  route through openconnector by source slug.

#### Scenario: Reviewer confirms no embedded OAuth flow

- **GIVEN** the shillinq codebase
- **WHEN** scanned for OAuth library imports (`league/oauth2-client`
  and similar)
- **THEN** no such imports SHALL exist in shillinq packages; the
  OAuth flow MUST live in openconnector.

### Requirement: REQ-BC-002: The `BankConnection` register SHALL declare a fixed minimum field set

A `BankConnection` register MUST be declared in
`lib/Settings/shillinq_register.json` representing the operator's
authorisation with an aggregator to read one or more bank
accounts.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `connectionNumber` | string | Yes | Operator-readable reference |
| `aggregator` | enum | Yes | One of `tink`, `klarna-kosma`, `plaid-eu`, `yapily`, `manual` |
| `aggregatorSourceSlug` | string | Yes | FK to the openconnector `Source` slug that holds the aggregator credentials |
| `bankBic` | string | Yes | BIC of the bank the connection covers |
| `bankCountry` | string (ISO 3166-1) | Yes | Country code |
| `bankAccountIban` | string | Yes | IBAN of the linked account |
| `consentReference` | string | Yes | Aggregator-issued consent identifier (returned from the SCA flow) |
| `consentGrantedAt` | date-time | Yes | When the consent was granted |
| `consentExpiresAt` | date-time | Yes | When the consent expires (PSD2 mandates SCA renewal every 90 days) |
| `lastSyncAt` | date-time | No | When the most recent successful pull completed |
| `lifecycleState` | enum | Yes | One of `pending`, `active`, `expiring`, `expired`, `revoked` |
| `administrationId` | string | Yes | FK to the administration |

Aggregator credentials (client id / secret / oauth tokens) MUST
NOT be stored on the `BankConnection` record — they live in
openconnector. shillinq carries the consent reference only, which
is non-credential metadata.

#### Scenario: Reviewer confirms no credentials in the schema

- **GIVEN** the `BankConnection` schema
- **WHEN** scanned for fields whose names match
  `*Secret*` / `*ClientId*` / `*ApiKey*` / `*Token*`
- **THEN** no such fields SHALL exist; credentials MUST live in
  openconnector.

### Requirement: REQ-BC-003: Aggregator credentials and shillinq-level connector settings SHALL live in NC AppConfig, not in a shillinq table

The shillinq-level configuration for bank connectors (e.g.
"default aggregator for new administrations", "notification
recipient for consent renewals") MUST be stored in Nextcloud's
`IAppConfig` via the existing shillinq `SettingsController` /
`SettingsService` (per shillinq config.yaml `design` rule). No
custom `shillinq_bank_*` database table.

Aggregator-side credentials (OAuth client id/secret, refresh
tokens) MUST live in openconnector's `Source` registry. Per
ADR-022 the credentials cross app boundaries through the source
slug reference only.

#### Scenario: Reviewer confirms no credentials table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming
  `bank_credential` / `aggregator_credential` / `consent`
- **THEN** no such classes SHALL exist; both credentials and
  consent records live outside shillinq's data layer.

### Requirement: REQ-BC-004: Transaction import SHALL run as an OpenRegister scheduled workflow that materialises CAMT.053 from aggregator JSON

Per ADR-031 §"Background jobs" path 2, periodic transaction pulls
MUST run as an OpenRegister `ScheduledWorkflow` tied to the
`BankConnection` register on an operator-configurable cadence
(default: 4× daily). The workflow MUST call openconnector by
source slug, normalise the aggregator-specific JSON into a
CAMT.053 XML document, and create a `BankStatement` record (per
T3 `bookkeeping-bank-reconciliation`) referencing the generated
CAMT.053 as a docudesk attachment per ADR-022. shillinq MUST NOT
author a `BankPollJob extends TimedJob` PHP class.

#### Scenario: Reviewer confirms no per-app TimedJob

- **GIVEN** the shillinq codebase
- **WHEN** scanned for classes extending `OCP\BackgroundJob\TimedJob`
  in `lib/BackgroundJob/` whose name matches
  `*Bank*` / `*Psd2*` / `*Aggregator*`
- **THEN** no such classes SHALL exist; transaction pulls MUST be
  driven by a `ScheduledWorkflow` record.

#### Scenario: A successful pull generates a CAMT.053 statement

- **GIVEN** an `active` `BankConnection` for IBAN
  `NL01RABO0123456789`
- **WHEN** the schedule fires and the aggregator returns 5 new
  transactions
- **THEN** one `BankStatement` record MUST be created in T3's
  register with `statementFormat: camt.053.001.08`, the generated
  XML attached via docudesk, and the transactions surfaced to T3's
  reconciliation queue.

### Requirement: REQ-BC-005: New-transaction push notifications SHALL fan out through OpenRegister's notifications abstraction

Notifications on new transactions MUST be declared as
`x-openregister-notifications` on the `BankStatement` register
(per ADR-031), with recipient resolution via the configured
notification policy. shillinq MUST NOT author a
`BankNotificationService` that subscribes to events and dispatches
to NC notifications + email.

#### Scenario: New-transaction notification fires to the configured recipient

- **GIVEN** an administration with a configured notification policy
  routing bank events to user `treasurer`
- **WHEN** a new `BankStatement` is created via the workflow
- **THEN** a NC notification MUST appear in `treasurer`'s feed
  with no per-app PHP dispatching the event.

### Requirement: REQ-BC-006: Consent renewal SHALL be a declarative lifecycle workflow with reminders before expiry

PSD2 SCA mandates consent renewal every 90 days. The
`BankConnection` schema MUST declare an `x-openregister-lifecycle`
that auto-transitions `active → expiring` 14 days before
`consentExpiresAt`, fires a notification (per REQ-BC-005), and
auto-transitions `expiring → expired` on `consentExpiresAt`. The
operator-initiated `expiring/expired → active` transition MUST
re-trigger the openconnector SCA flow via a source action and
update `consentReference` + `consentExpiresAt` on success.

#### Scenario: A connection auto-transitions to `expiring` 14 days before consent expiry

- **GIVEN** a `BankConnection` in state `active` with
  `consentExpiresAt: 2026-09-30`
- **WHEN** the scheduled lifecycle evaluator runs on `2026-09-16`
- **THEN** the connection state MUST be `expiring`; **AND** a
  notification MUST be sent per REQ-BC-005.

#### Scenario: Reauthorisation routes through openconnector

- **GIVEN** a `BankConnection` in state `expired`
- **WHEN** the operator triggers the renewal action from the
  detail page
- **THEN** the openconnector source's `reauthorise` action MUST
  be invoked by slug, the operator MUST complete SCA in the
  bank's UI, the new `consentReference` MUST be persisted, and
  the connection state MUST transition back to `active`.

### Requirement: REQ-BC-007: Bank connections SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry (`Bookkeeping >
Bank Connections`) with a `type: index` page binding to the
`BankConnection` register and a `type: detail` page for
individual connections. Both pages MUST be rendered by the
generic `@conduction/nextcloud-vue` `CnIndexPage` /
`CnDetailPage` components — no bespoke Vue files (per ADR-024
Tier-4).

#### Scenario: The detail page surfaces the renewal action when consent expiry approaches

- **GIVEN** a `BankConnection` in state `expiring`
- **WHEN** the operator opens its detail page
- **THEN** the `Renew consent` action MUST be visible; **AND** the
  remaining-days countdown MUST be visible in the header.
