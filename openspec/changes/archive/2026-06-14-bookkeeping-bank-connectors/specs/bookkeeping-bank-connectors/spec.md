# Spec: bookkeeping-bank-connectors

**Status:** proposed
**Scope:** shillinq
**Tier:** T4 (advanced engine)
**Depends on:** bookkeeping-bank-reconciliation (T2)

## ADDED Requirements

### Requirement: REQ-BC-001: PSD2 AIS aggregator integrations SHALL be consumed from openconnector per ADR-022

PSD2 Account Information Service (AIS) connectivity to licensed EU aggregators
(Tink, Klarna Kosma, Plaid-EU, Yapily, etc.) MUST be consumed from openconnector
as configured `Source` records — shillinq MUST NOT embed an aggregator HTTP
client, MUST NOT implement OAuth/SCA flows, and MUST NOT manage aggregator API
versioning. Per ADR-022, when a sibling app provides the integration, the app
consumes it.

#### Scenario: Reviewer confirms no embedded aggregator client

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `Guzzle` / `Symfony\HttpClient` / `curl_init` usages
  in `lib/Service/Bank*` or `lib/Service/Psd2*` or `lib/Service/Aggregator*`
- **THEN** no such usages SHALL exist; aggregator calls MUST route through
  openconnector by source slug.

#### Scenario: Reviewer confirms no embedded OAuth flow

- **GIVEN** the shillinq codebase
- **WHEN** scanned for OAuth library imports (`league/oauth2-client` and similar)
- **THEN** no such imports SHALL exist in shillinq packages; the OAuth flow
  MUST live in openconnector.

### Requirement: REQ-BC-002: The `BankConnection` register SHALL declare a fixed minimum field set

A `BankConnection` register MUST be declared in `lib/Settings/shillinq_register.json`
representing the operator's authorisation with an aggregator to read one or
more bank accounts.

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

Aggregator credentials (client id / secret / oauth tokens) MUST NOT be stored
on the `BankConnection` record — they live in openconnector. shillinq carries
the consent reference only, which is non-credential metadata.

#### Scenario: Reviewer confirms no credentials in the schema

- **GIVEN** the `BankConnection` schema
- **WHEN** scanned for fields whose names match `*Secret*` / `*ClientId*`
  / `*ApiKey*` / `*Token*`
- **THEN** no such fields SHALL exist; credentials MUST live in openconnector.

### Requirement: REQ-BC-003: Aggregator credentials and shillinq-level connector settings SHALL live in Nextcloud AppConfig, not in a shillinq table

The shillinq-level configuration for bank connectors (e.g. "default aggregator
for new administrations", "notification recipient for consent renewals") MUST
be stored in Nextcloud's `IAppConfig` via the existing shillinq
`SettingsController` / `SettingsService` (per shillinq config.yaml `design` rule).
No custom `shillinq_bank_*` database table.

Aggregator-side credentials (OAuth client id/secret, refresh tokens) MUST live
in openconnector's `Source` registry. Per ADR-022 the credentials cross app
boundaries through the source slug reference only.

#### Scenario: Reviewer confirms no credentials table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes naming `bank_credential`
  / `aggregator_credential` / `consent`
- **THEN** no such classes SHALL exist; both credentials and consent records
  live outside shillinq's data layer.

### Requirement: REQ-BC-004: Transaction import SHALL run as an OpenRegister scheduled workflow that materialises CAMT.053 from aggregator JSON

Per ADR-031 §"Background jobs" path 2, periodic transaction pulls MUST run as
an OpenRegister `ScheduledWorkflow` tied to the `BankConnection` register on
an operator-configurable cadence (default: 4× daily). The workflow MUST call
openconnector by source slug, normalise the aggregator-specific JSON into a
CAMT.053 XML attachment, attach via docudesk, and create `BankStatement`
records in shillinq. The workflow SHALL NOT live in a `BankPollingJob extends TimedJob`.

#### Scenario: Reviewer confirms no TimedJob for polling

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Job/Bank*Job.php` files extending `TimedJob`
  or implementing `IJob`
- **THEN** no such classes SHALL exist; polling MUST be a `ScheduledWorkflow`.

### Requirement: REQ-BC-005: Bank connection lifecycle SHALL implement a five-state machine with declarative time-based expiry warning

The `BankConnection` lifecycle is declared via `x-openregister-lifecycle` on
the register schema with five states and automatic time-based transition:

| State | Triggers | Action | Target |
|---|---|---|---|
| `pending` | Connection created, awaiting first SCA | Monitor | `active` (when SCA completes and consent is returned) |
| `active` | Consent valid and operational | Poll transactions on schedule | `expiring` (14 days before `consentExpiresAt`) |
| `expiring` | Automatic time-based transition fires 14 days before expiry | Emit notification to configured recipient | `expired` (on `consentExpiresAt` deadline) |
| `expired` | Consent deadline passed; no longer valid | Block polling; show renewal prompt to operator | `active` (when operator completes SCA renewal via openconnector) |
| `revoked` | Operator or aggregator revoked consent | Block polling; show re-connect prompt | `pending` (if operator initiates new SCA flow) |

The `active → expiring` transition is **time-based**: fires automatically when
current date + 14 days >= `consentExpiresAt`. The transition emits a notification
via `x-openregister-notifications` to the configured renewal recipient.

#### Scenario: Time-based transition to expiring fires at 14-day mark

- **GIVEN** a `BankConnection` in `active` state with `consentExpiresAt = 2026-06-20`
- **WHEN** the current date advances to 2026-06-06 (14 days before deadline)
- **THEN** the lifecycle auto-transitions to `expiring` and emits a notification
  to the configured recipient.

### Requirement: REQ-BC-006: Consent-renewal action SHALL hand off to openconnector SCA endpoint

When an operator clicks the consent-renewal action on a connection in `expiring`
or `expired` state, the UI redirects to openconnector's SCA endpoint, which
initiates the aggregator's Strong Customer Authentication flow. After the operator
completes SCA in the bank's UI, the bank redirects back to openconnector.
openconnector updates the `Source` record's consent state, fires a CloudEvent,
and shillinq's `BankConnection` lifecycle auto-transitions back to `active`.
No SCA logic, OAuth flows, or bank-specific UI re-implementation in shillinq.

#### Scenario: Operator renews expiring connection via openconnector SCA

- **GIVEN** a `BankConnection` in `expiring` state with a consent-renewal action
- **WHEN** the operator clicks the renewal button
- **THEN** the browser redirects to openconnector's SCA endpoint, which handles
  bank authentication; on return, `BankConnection` transitions to `active`.

### Requirement: REQ-BC-007: Manifest navigation entry SHALL declare Bank Connections index and detail pages

The `src/manifest.json` entry for Bank Connections MUST declare:

- Navigation menu entry under `Bookkeeping > Bank Connections`
- `type: index` page renderer binding to `BankConnection` register/schema
- `type: detail` page renderer with:
  - Connection status display (lifecycle state, remaining days to renewal if `expiring`)
  - Consent-renewal action button (links to openconnector SCA when state is `expiring` or `expired`)
  - Last sync timestamp display
  - Connected account details (IBAN, BIC, country)
  - Linked `BankStatement` records (via relation to previous T2 reconciliation records)

#### Scenario: Detail page shows consent-renewal action when expiring

- **GIVEN** a `BankConnection` in `expiring` state viewed in the detail page
- **WHEN** rendered
- **THEN** the page displays "Consent expires in X days" and shows a
  consent-renewal action button.

### Requirement: REQ-BC-008: New-transaction notifications SHALL be declared via x-openregister-notifications on BankStatement

New `BankStatement` records created by the scheduled workflow polling MUST emit
notifications via `x-openregister-notifications`. The notification SHALL:

- Fire on `BankStatement` creation
- Resolve recipients (default: configured renewal recipient role, or explicitly
  configured operator via `IAppConfig`)
- Fan out across channels (in-app, email, n8n workflow) per OpenRegister
  notification infrastructure
- Include: statement period, transaction count, attachment URI

#### Scenario: Notification fires when new statement arrives

- **GIVEN** a completed transaction poll that creates a new `BankStatement`
- **WHEN** the record is saved to OpenRegister
- **THEN** a notification is emitted to the configured recipient (e.g. treasurer
  role members) with statement summary and link to attachment.

## Out of Scope (explicit defers)

- **Credential management UI for openconnector aggregators** — operator configures
  PSD2 aggregator sources in openconnector directly; shillinq does not expose
  credential or OAuth management. This is openconnector's domain.
- **Multi-currency support for imported transactions** — currency normalization
  handled by the n8n workflow and docudesk attachment layer (T4 multi-currency
  is a sibling spec).
- **Duplicate transaction detection** — T2 `bookkeeping-bank-reconciliation`
  provides the reconciliation surface; this spec does not implement duplicate
  logic.
- **Manual transaction import override** — T2 manual MT940 path remains fully
  operational alongside PSD2 automated feed.
