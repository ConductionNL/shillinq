---
sidebar_position: 1
title: Integrations
description: How Shillinq talks to upstream and downstream systems. Per-system integration pages land via issue #78.
---

# Integrations

Each page below covers what the integration does, how to connect it,
what configuration each side needs, and which Shillinq features depend
on it.

## Available

* [Pipelinq — Configuring the Customer Bridge](./pipelinq-admin.md) —
  admin setup, testing, observability, troubleshooting.
* [Pipelinq — Integration Architecture](./pipelinq-architecture.md) —
  developer-facing architecture, adapter / cache / circuit-breaker /
  retry-queue walk-through, log + metrics contract.

## Planned

OpenRegister (canonical data backbone), OpenConnector (source sync),
DocuDesk (invoice and contract generation), Peppol / NLCIUS (e-invoicing
transport), SBR / Digipoort (Belastingdienst filing), CAMT.053
bank-statement import, Nextcloud Contacts (counterparties), OAuth /
Keycloak (SSO). Pages land via Codeberg issue shillinq#78 (pre-migration, not migrated to GitHub).
