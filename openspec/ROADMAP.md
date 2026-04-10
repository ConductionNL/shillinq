# Roadmap

This document tracks the planned development of Shillinq.

Features are defined in [`openspec/specs/`](specs/). When a feature reaches `planned` status, it is listed here and an OpenSpec change is created with `/opsx:ff`.

## Status Overview

| Feature | Status | Priority | OpenSpec Change |
|---------|--------|----------|----------------|
| Dashboard with overview cards and quick actions | in-progress | must | [core](changes/core/) |
| OpenRegister schema definitions for all entities | in-progress | must | [core](changes/core/) |
| Sidebar navigation with collapsible menu sections | in-progress | must | [core](changes/core/) |
| Entity list views with sorting and pagination | in-progress | must | [core](changes/core/) |
| Entity detail views with tabbed sections | in-progress | must | [core](changes/core/) |
| Entity create/edit forms with validation | in-progress | must | [core](changes/core/) |
| Admin settings page with app configuration | in-progress | must | [core](changes/core/) |
| Seed data with example records for onboarding | in-progress | must | [core](changes/core/) |
| Global search across all entity types | in-progress | must | [core](changes/core/) |
| Faceted filtering on list views | in-progress | should | [core](changes/core/) |
| CSV import for bulk data loading | in-progress | should | [core](changes/core/) |
| CSV/Excel export of list views | in-progress | should | [core](changes/core/) |
| User preferences for display and notification settings | in-progress | should | [core](changes/core/) |
| Breadcrumb navigation for nested views | in-progress | should | [core](changes/core/) |
| Nextcloud notification integration | in-progress | should | [core](changes/core/) |

## Accounts Payable & Receivable Features

| Feature | Status | Priority | OpenSpec Change |
|---------|--------|----------|----------------|
| Invoice PDF export with embedded UBL 2.1 XML (PDF/A-3 / ZUGFeRD / Factur-X) | planned | must | [add-invoice-pdf-export-with-ubl-peppol-support](changes/add-invoice-pdf-export-with-ubl-peppol-support/) |
| Peppol BIS 3.0 outbound transmission | planned | must | [add-invoice-pdf-export-with-ubl-peppol-support](changes/add-invoice-pdf-export-with-ubl-peppol-support/) |
| Dutch KvK and BTW number validation for e-invoicing | planned | must | [add-invoice-pdf-export-with-ubl-peppol-support](changes/add-invoice-pdf-export-with-ubl-peppol-support/) |

## Contract Lifecycle Management Features

| Feature | Status | Priority | OpenSpec Change |
|---------|--------|----------|----------------|
| AI obligation task management with automated deadline tracking | planned | must | [contract-lifecycle-management](changes/contract-lifecycle-management/) |
| Contract Repository with Full-Text Search | planned | must | [contract-lifecycle-management](changes/contract-lifecycle-management/) |
| AI-powered automated contract redlining and task management | planned | must | [contract-lifecycle-management](changes/contract-lifecycle-management/) |
| Contract lifecycle management from award through execution to expiration | planned | must | [contract-lifecycle-management](changes/contract-lifecycle-management/) |
| Route contract for approval | planned | must | [contract-lifecycle-management](changes/contract-lifecycle-management/) |

## Scheduling Features

| Feature | Status | Priority | OpenSpec Change |
|---------|--------|----------|----------------|
| Cash management with liquidity forecasting and planning | planned | must | [scheduling](changes/scheduling/) |
| Delegation rules with calendar-based out-of-office and escalation management | planned | must | [scheduling](changes/scheduling/) |
| Publish award notice within legal deadline | planned | must | [scheduling](changes/scheduling/) |
| Digital lockbox technology preventing bid viewing before submission deadline | planned | must | [scheduling](changes/scheduling/) |
| Procurement planning with budget allocation linked to tender pipeline | planned | must | [scheduling](changes/scheduling/) |
| Set delivery schedule on call-off order | planned | must | [scheduling](changes/scheduling/) |
| Receive deadline alerts for objection responses | planned | must | [scheduling](changes/scheduling/) |
| Schedule invoice email for specific date | planned | must | [scheduling](changes/scheduling/) |
| Multi-year planning | planned | must | [scheduling](changes/scheduling/) |
| Decline renewal and schedule contract closure | planned | must | [scheduling](changes/scheduling/) |
| Automate reminder escalation workflow | planned | must | [scheduling](changes/scheduling/) |
| Automatic payment reminder emails with configurable escalation schedule | planned | must | [scheduling](changes/scheduling/) |
| Schedule payments strategically | planned | must | [scheduling](changes/scheduling/) |
| Schedule payment date | planned | must | [scheduling](changes/scheduling/) |

## Phases

### Phase 1 — Foundation

Core infrastructure: schemas, dashboard, CRUD patterns, navigation, import/export, search, seed data, notifications.

**OpenSpec change:** [core](changes/core/) — _in progress_

### Phase 2 — Enhancement

_Add features that improve the experience, extend functionality, and cover more use cases._

### Phase 3 — Polish

_Performance, accessibility improvements, full localization, and hardening for production._

---

## How This Works

1. Run `/opsx:app-explore` to define features in `openspec/specs/`
2. When a feature is `planned`, add it to the table above
3. Run `/opsx:ff {feature-name}` to create the implementation spec
4. Update the **OpenSpec Change** column with a link to the change directory
5. When all changes for a feature are done, mark the feature `done`
