# Proposal: Core Platform

## Summary

Establish the foundational infrastructure for Shillinq: OpenRegister schema definitions for all core business-administration entities, a financial dashboard, sidebar navigation, entity CRUD views (list, detail, create/edit), admin settings, seed data for onboarding, and platform-level capabilities (full-text search, faceted filtering, CSV import/export, Nextcloud notifications).

This change covers Phase 1 — Foundation of the Shillinq roadmap.

## Problem

Shillinq is a greenfield Nextcloud app. Before any domain-specific feature (e-invoicing, bank reconciliation, contract lifecycle, etc.) can be built, the platform requires:

1. **Entity schemas** — without OpenRegister schema definitions there is no data layer, no validation, and no REST API surface.
2. **Navigation & CRUD patterns** — without list/detail/form views there is no user interface to build upon.
3. **Seed data** — without realistic example objects, new installations require hours of manual data entry before the app is usable for demos or onboarding.
4. **Admin configuration** — without a settings page, the app cannot be connected to an OpenRegister register.

## Stakeholders

| Stakeholder | Role | Goal | Pain point |
|---|---|---|---|
| **Boekhouder** | Bookkeeper / accountant | Post journal entries, maintain chart of accounts, close fiscal year | Switching between multiple tools for different entity types |
| **Directeur / Eigenaar** | Director / business owner | Dashboard overview of financial position at a glance | No single view of open invoices, budgets, and cash flow |
| **Crediteurenadministrateur** | AP admin | Process purchase invoices, track supplier payments | Manual re-entry of supplier data across systems |
| **Debiteurenadministrateur** | AR admin | Create and send sales invoices, follow up on payments | Hard to see which invoices are overdue |
| **Controller** | Financial controller | Approve postings, generate reports, monitor budget vs actual | Lack of audit trail on financial mutations |
| **IT Beheerder** | System / Nextcloud administrator | Configure registers, manage schemas, control access | Complex setup without guided configuration |

## Proposed Features

### Must (Phase 1)

1. **OpenRegister schemas for all core entities** — Organization, FiscalYear, Account, JournalEntry, SalesInvoice, PurchaseInvoice, PurchaseOrder, Contract, BankStatement, Budget, FinancialReport, BBVAccount, SpendReport, AITask — with schema.org vocabulary, Dutch-language field names, and explicit types.
2. **Dashboard** — KPI cards (open verkoopfacturen, openstaand bedrag, budget resterend, te verwerken bankafschriften) + status-distribution chart + recent-mutations list.
3. **Sidebar navigation** — collapsible menu sections per domain (Debiteuren, Crediteuren, Boekhouding, Inkoop, Contracten, Rapportages) using `NcAppNavigation`.
4. **Entity list views** — `CnIndexPage` for every schema with sortable columns, pagination, and a search bar (`useListView`).
5. **Entity detail views** — `CnDetailPage` with `CnDetailCard` sections and `CnObjectSidebar` (files, audit trail, notes).
6. **Entity create/edit forms** — `CnFormDialog` auto-generated from schema; `CnAdvancedFormDialog` for power users.
7. **Admin settings page** — `CnVersionInfoCard` + `CnRegisterMapping` + `CnSettingsSection` for register configuration.
8. **Seed data** — 3–5 realistic Dutch-language example objects per schema, importable via OpenRegister.

### Should (Phase 1)

9. **Global search** — cross-entity full-text search using OpenRegister `IndexService` + `CnFilterBar`.
10. **Faceted filtering** — per-schema facets (status, type, datum) using `CnFacetSidebar`.
11. **CSV import** — bulk entity loading via `CnMassImportDialog`.
12. **CSV/Excel export** — list-view export via `CnMassExportDialog`.
13. **User preferences** — date/number locale, default fiscal year, notification frequency via `NcAppSettingsDialog`.
14. **Nextcloud notifications** — trigger `NotificationService` on invoice due, contract expiry, budget overshoot.

## Out of Scope (deferred)

- PDF/UBL invoice generation (→ add-invoice-pdf-export-with-ubl-peppol-support)
- Bank reconciliation matching engine (→ separate change)
- Contract AI obligation extraction (→ contract-lifecycle-management)
- Peppol outbound transmission (→ add-invoice-pdf-export-with-ubl-peppol-support)
- BBV/IV3/SiSa reporting exports (→ bbv-reporting change)
- Budget vs actual variance engine (→ separate change)

## Acceptance Criteria (high-level)

- All schemas importable via `ConfigurationService::importFromApp()` in the repair step.
- Dashboard loads within 2 s on a cold cache with 1 000 objects per schema.
- Every entity has a working list, detail, create, and edit flow in the browser.
- Seed data installs automatically on first activation and produces a realistic demo environment.
- Admin settings page lets an IT admin link any Nextcloud-hosted OpenRegister register without touching config files.
- All user-visible strings are translatable (Dutch + English minimum).
