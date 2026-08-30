---
sidebar_position: 2
title: BADO Controleprotocol — Developer Guide
description: Technical reference for the Shillinq BADO controleprotocol stack (7 schemas, lifecycle state machines, OpenConnector events, aggregation queries, PDF/A export service, integration points, testing strategy).
---

# BADO Controleprotocol — Developer Guide

This document is the technical companion to the
`bookkeeping-bado-controleprotocol` OpenSpec change. It explains the schema
layout, lifecycle state machines, the ADR-031 exception-path services, the
aggregation + opinion engine, the PDF/A export and the integration points
with `openregister`, `OpenConnector`, `docudesk` and the SiSa-bijlage
toolchain. Audience: backend developers, integrators, SREs.

## 1. Architecture

The BADO stack is **declarative-first**: the seven schemas, their lifecycle,
RBAC, events, aggregations and worked-example seeds live in
`lib/Settings/register.d/bookkeeping-bado-controleprotocol.json` — an OpenAPI
3.1 fragment OpenRegister compiles into per-app register state.

The cross-schema decision logic that the declarative engine cannot yet
express (multi-record aggregation, the BADO decision tree, fail-closed
lifecycle preconditions) lives in three PHP classes that are wired in via
ADR-031 (`requires:` callouts):

| Class | Role |
|-------|------|
| `BadoControleprotocolCalculator` | Pure-logic helpers. No I/O. Tolerance-ceiling validation, severity classification, four-eye completeness, finding aggregation, opinion derivation. Money arithmetic in integer cents to dodge IEEE-754 drift. |
| `BadoControleprotocolService` | Wires the calculator to live OR data via `ContainerInterface → ObjectService::findAll`. Hosts the five lifecycle preconditions referenced from `register.d/*.json` (`canSubmitForReview`, `canAdopt`, `hasControllerResponse`, `isFourEyeComplete`, `canSignVerklaring`). Fail-closed on any exception. |
| `AccountantsdossierExportService` | Builds the PDF/A-oriented HTML summary + the SHA-256-anchored manifest + the ZIP package. Delegates the PKIO signature to a configured signer URI. |

One read-only HTTP controller exposes the aggregation + export:

- `GET /api/bado/controleprotocol/aggregation?protocol_id={id}` — returns
  the server-computed per-topic aggregation + proposed opinion.
- `GET /api/bado/controleprotocol/accountantsdossier?protocol_id={id}` —
  builds and persists the bundle ZIP and returns the envelope (sha256,
  zipPath, signaturePending, retentionYears).

Both endpoints carry `#[NoAdminRequired]` (any authenticated user) and rely
on OpenRegister's register-level RBAC + multitenancy to scope reads.

## 2. Schemas

The seven schemas land in the `shillinq` register. All references are
nullable strings (FK by id).

### Controleprotocol

Top-level audit protocol; one per (organisation, audit year). Lifecycle:
`draft → in-review → adopted → superseded`. Required fields: `version`,
`auditYear`, `organisationId`, `organisationType`, `materialityBase`,
`effectiveFrom`, `effectiveTo`. Adoption requires a populated
`adoptionDecision.besluitnummer` + `datum`.

### ToleranceMatrix

Per-topic BADO ceilings, max 1% (approval) / 3% (qualification). The
fragment carries `maximum: 1` / `maximum: 3` on the JSON-Schema property so
OR-side validation rejects a row before the service even sees it; the
service's `validateCeilings()` is the second line of defence + the source
of the worked-example fixtures in the calculator unit tests.

### Materialiteit

Per-protocol materialiteit. Lifecycle: `draft → frozen` on protocol
adoption. `calculatedAmount = base × percentage`. The exporter reads the
`overall` scope row by default; per-programma rows feed the aggregation
when present.

### AuditSample

Per-protocol sample. Carries the `reproducibleSeed` so a regulator can
regenerate the same selection (NV COS 530, monetary-unit-sampling).
`selectionMethod` is one of `monetary-unit-sampling`, `random`,
`risk-based`.

### AuditFinding

Per-transaction finding. Lifecycle: `open → agreed → resolved` or
`open → disputed → resolved`. Severity is **not** trusted from the client;
`BadoControleprotocolService::computeAggregation` reclassifies every
agreed/resolved finding through `BadoControleprotocolCalculator::classifySeverity`
against the topic's `ToleranceMatrix` row + the frozen materialiteit.

### VerklaringDraft

Per-protocol verklaring. `proposedOpinion` is the mechanically-derived
opinion (decision tree); `opinionRationale` is the auditor's BADO-cited
justification. `canSignVerklaring()` refuses signing while any finding is
open/disputed OR any SiSa-regeling in scope lacks a SiSaAssurance child.

### SiSaAssurance

Per-regeling SiSa-bijlage IIA row. `findings` is an array of AuditFinding
ids classified under the regeling. `assuranceLevel` is `financial-statement`
(full BADO scope) or `sisa-specific` (regeling-level procedures only).

## 3. Lifecycle state machines

### Controleprotocol

```
draft ──[submit]──> in-review ──[adopt]──> adopted ──[supersede]──> superseded
```

- `submit` (`draft → in-review`): `requires: BadoControleprotocolService::canSubmitForReview`
  — all header fields must be populated; on transition the
  ToleranceMatrix + Materialiteit lock.
- `adopt` (`in-review → adopted`): `requires: BadoControleprotocolService::canAdopt`
  — `adoptionDecision.besluitnummer` + `datum` must be set; on transition
  the system emits `audit.protocol.adopted` via OpenConnector.
- `supersede` (`adopted → superseded`): purely declarative — a new
  protocol for the same (organisation, auditYear) supersedes the old one.

### AuditFinding

```
open ──[agree]──> agreed ──[resolveAgreed]──> resolved
  │
  └──[dispute]──> disputed ──[resolveDisputed]──> resolved
```

- `agree`: `requires: BadoControleprotocolService::hasControllerResponse` —
  controller-response field non-empty.
- `resolveAgreed` / `resolveDisputed`: `requires: BadoControleprotocolService::isFourEyeComplete`
  — both axes (`rechtmatigheid`, `getrouwheid`) carry severity AND both
  `controllerResponse` + `auditorConclusion` are populated.

### Materialiteit

```
draft ──[freeze]──> frozen
```

Freeze fires on Controleprotocol adoption; declarative `requires:` clause.

## 4. OpenConnector events

| Event | Trigger | Payload |
|-------|---------|---------|
| `audit.protocol.adopted` | `Controleprotocol.lifecycle.adopt` | `organisation_id`, `audit_year`, `effective_from`, `effective_to` |
| `audit.finding.materieel.detected` | `AuditFinding.field.severity=materieel` | `finding_id`, `transaction_id`, `amount`, `topic` |
| `audit.verklaring.signed` | `VerklaringDraft.lifecycle.sign` | `protocol_id`, `opinion`, `audit_year` |

Subscribers: `bookkeeping-bbv-compliance` (audit-year lock),
`bookkeeping-rekenkamer-audit-pack` (link to onderzoeken),
`bookkeeping-jaarrekening-publication` (publication readiness flag).

## 5. Aggregation + opinion derivation

`BadoControleprotocolService::computeAggregation($protocolId)` returns
```json
{
  "protocolId": "...",
  "materialityAmount": 1000000.0,
  "topics": [
    {
      "topic": "Sociaal Domein",
      "acceptabelCount": 1,
      "teCorrigerenCount": 1,
      "materieelCount": 0,
      "rechtmatigheidCents": 1600000,
      "getrouwheidCents": 0,
      "rechtmatigheidAmount": 16000.0,
      "getrouwheidAmount": 0.0,
      "verdict": "qualified"
    }
  ],
  "proposedOpinion": "met-beperking"
}
```

Pipeline:

1. Load `Materialiteit` rows; pick `scope=overall` (fallback: first row).
2. Load `ToleranceMatrix` rows; key by `topic`.
3. Load `AuditSample` rows; collect ids → `sampleIds`.
4. Load all `AuditFinding`; filter to `sample ∈ sampleIds` and
   `status ∈ {agreed, resolved}`.
5. For each finding: `classifySeverity(finding, toleranceRow, materialityAmount)`
   → `acceptabel | te-corrigeren | materieel`.
6. Aggregate per topic: count by severity + sum cents per axis.
7. Verdict: `materieelCount > 0 → adverse`, else
   `teCorrigerenCount > 0 → qualified`, else `acceptable`.
8. Opinion (`deriveOpinion`): BADO decision tree — pervasive scope
   limitation → `oordeelonthouding`, materieel pervasive →
   `afkeurend`, materieel local → `met-beperking`, otherwise →
   `goedkeurend`.

Money math is performed in integer cents (`toCents()`); a percentage of
materialiteit is computed as `cents × pct / 100`.

## 6. Accountantsdossier export (PDF/A bundle)

`AccountantsdossierExportService::exportDossier($protocolId)` returns:

```json
{
  "packageId": "accountantsdossier-cp-2026-hoorn-7a3e9b…",
  "protocolId": "cp-2026-hoorn",
  "generatedAt": "2026-12-22T09:01:33Z",
  "generatedBy": "j.bakker",
  "sha256": "f3d4…",
  "zipPath": "/tmp/accountantsdossier-cp-2026-hoorn-7a3e9b….zip",
  "attachmentCount": 12,
  "retentionYears": 7,
  "signaturePending": true,
  "signerUri": null,
  "signedAt": null,
  "thumbprint": null
}
```

Package layout (byte-identical for the same protocol state):

```
accountantsdossier-{protocolId}/
  ├── manifest.json     # metadata, sha256(ledger.json), ISO 8601 timestamp,
  │                       retention, signature envelope (delegated)
  ├── ledger.json       # full BADO record dump, keyed by schema
  ├── summary.pdf.html  # PDF/A-1b oriented HTML summary
  └── attachments/
      ├── controleprotocol/controleprotocol.json
      ├── tolerance-matrix/row-NNNN.json
      ├── materialiteit/row-NNNN.json
      ├── audit-samples/sample-NNNN.json
      ├── audit-findings/finding-NNNN.json
      ├── verklaring-draft/verklaring-draft.json
      └── sisa-assurance/row-NNNN.json
```

- Determinism: each collection is sorted on a stable key (`topic` / `scope`
  / `extractedAt` / `id` / `regelingCode`) before encoding so two runs of
  the same protocol state yield byte-identical `manifest.json` +
  `ledger.json`. SHA-256 over `ledger.json` is the tamper anchor.
- PDF/A: `summary.pdf.html` advertises `pdfaid:part="1"` +
  `pdfaid:conformance="B"` in meta tags + an XMP `DocumentID`. Downstream
  renderers (mPDF with PDF/A-1b profile, wkhtmltopdf 0.12.6 with
  `--enable-pdf-a`, Apache PDFBox 3.x) consume these to produce
  ISO 19005-1:2005 conformant binaries.
- PKIO signature: delegated via app config key `bado_dossier_signer_uri`.
  When unset the bundle is still complete + the envelope flags
  `signaturePending=true`; production wires the docudesk + qualified-
  certificate adapter (PAdES B-LT per ETSI EN 319 142-1).
- Retention: 7 years (Archiefwet + Selectielijst Gemeenten 2020, 21.1).

## 7. Integration points

| Integrand | How it integrates |
|-----------|-------------------|
| `openregister` | Schema declaration, lifecycle, RBAC, aggregation declarations. Reads via `ContainerInterface->get('OCA\OpenRegister\Service\ObjectService')->setRegister()->setSchema()->findAll()`. |
| `OpenConnector` | Three events on the controleprotocol + finding + verklaring lifecycle. Subscribers register via OpenConnector's standard event manifest. |
| `docudesk` | PKIO-signing adapter (when configured). Receives the ZIP path + SHA-256; returns a signed PDF/A + thumbprint. |
| `bookkeeping-bbv-compliance` | Subscribes to `audit.protocol.adopted` to lock the audit year. |
| `bookkeeping-rekenkamer-audit-pack` | Subscribes to `audit.finding.materieel.detected` for escalation. |
| `bookkeeping-jaarrekening-publication` | Subscribes to `audit.verklaring.signed` to mark publication-ready. |
| SiSa-bijlage IIA toolchain | Reads `SiSaAssurance` rows via the OR public API; the per-regeling roll-up is part of the accountantsdossier bundle. |
| AFM toezicht | Receives the signed bundle out-of-band; verifies PKIO signature + SHA-256 anchor. |

## 8. HTTP API

### `GET /api/bado/controleprotocol/aggregation`

| Query parameter | Required | Description |
|-----------------|----------|-------------|
| `protocol_id` | yes | Controleprotocol id (slug or UUID; `^[A-Za-z0-9_.\-]{1,64}$`). |

Returns the aggregation envelope (see §5). 400 on missing/malformed
`protocol_id`, 401 on unauthenticated, 500 on aggregation failure (log only,
no stack trace to client).

### `GET /api/bado/controleprotocol/accountantsdossier`

| Query parameter | Required | Description |
|-----------------|----------|-------------|
| `protocol_id` | yes | Controleprotocol id. |

Returns the export envelope (see §6). 400 on missing/malformed
`protocol_id`, 401 on unauthenticated, 404 when the protocol cannot be
resolved (cross-tenant or non-existent), 500 on unexpected failure.

## 9. Configuration keys

| Key | Default | Description |
|-----|---------|-------------|
| `register` | `shillinq` | OpenRegister register slug holding the BADO schemas. |
| `bado_dossier_signer_uri` | (unset) | URI of the configured PKIO signer adapter. When unset the bundle ships with `signaturePending=true`. |

## 10. Testing strategy

Three layers:

1. **Pure-logic unit tests** (`BadoControleprotocolCalculatorTest`,
   `BadoControleprotocolFragmentTest`) — assert tolerance-ceiling
   validation, severity classification, opinion decision tree, fragment
   integrity (every `requires:` resolves to a real public method on the
   service).
2. **Hermetic integration tests** (`BadoControleprotocolEndToEndTest`,
   `BadoSisaBijlageIIATest`, `BadoFindingEscalationTest`) — wire the
   production service + calculator + exporter through `InMemoryObjectService`
   and exercise the full lifecycle, the SiSa-bijlage IIA roll-up and the
   four-eye escalation workflow. No NC bootstrap, no OR runtime, no PKI.
3. **Live-instance smoke tests** — out-of-scope for this PR; the
   accountantsdossier export is exercised via the Playwright e2e
   harness once the docudesk adapter is wired.

Run them locally:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
  php vendor/bin/phpunit --no-configuration \
  --bootstrap=tests/bootstrap-stubs.php \
  tests/Unit/Service/Bado*Test.php
```

The bootstrap uses the in-tree OCP stubs (`tests/bootstrap-stubs.php`) so
the tests stay hermetic + portable.

## 11. Compliance references

- BADO — Besluit Accountantscontrole Decentrale Overheden (Stb. 2006, 21),
  Article 5 lid 2 (statutory ceilings).
- Gemeentewet art. 213 — accountantscontrole jaarrekening.
- BBV — Besluit begroting en verantwoording provincies en gemeenten.
- Notitie Materialiteit en Tolerantie (Commissie BBV) —
  materialiteit ≤ 1% lasten / baten / balanstotaal.
- Kadernota Rechtmatigheid — rechtmatigheidsoordeel + tolerantie.
- SiSa-bijlage IIA — Ministerie van BZK, jaarlijkse update.
- NV COS 700 / NV COS 705 — auditor opinion + qualification framework.
- NV COS 230 — vier-ogen-principe.
- NV COS 530 — monetary-unit-sampling.
- ISO 19005-1:2005 — PDF/A-1 archival format.
- ETSI EN 319 142-1 — PAdES B-LT long-term signature profile.
- Archiefwet + Selectielijst Gemeenten 2020 (21.1) — 7-jaar retentie.
- AFM Toezicht — vergunningsnummer-eis op `signOff`.
