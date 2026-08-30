# Design: facturx-cii-conformance

## Architecture Overview

Pure correctness fix inside `InvoicePdfGenerator`'s existing hybrid-PDF
byte-writer (`generateHybridPdf()` → `buildPdfA3Bytes()`, renamed
`buildHybridPdfBytes()`). No new components; no change to the caller
(`EInvoiceService::sendInvoice()`) or the PDF's structural embedding
mechanism (`/AF` + `/EmbeddedFiles`, `AFRelationship=Alternative`) — only
the embedded filename string, the XMP conformance metadata, and prose
describing the artefact.

## Two independent false claims, two independent fixes

1. **Factur-X/ZUGFeRD claim (filename)** — `factur-x.xml` is the
   community-standard signal DE/FR e-invoicing software uses to
   auto-detect a Factur-X/ZUGFeRD (UN/CEFACT CII) hybrid PDF. The embedded
   content is NLCIUS **UBL**, a different XML syntax entirely — a CII
   parser fed UBL will fail or, worse, silently ingest the wrong fields.
   Fix: rename the attachment to `ubl-invoice.xml`.
2. **PDF/A-3B conformance claim (XMP)** — `pdfaid:part=3` /
   `pdfaid:conformance=B` asserts full PDF/A-3, Level B conformance. ISO
   19005-3 hard-requires (among other things) an ICC `OutputIntent`
   dictionary and embedding of every font used. This generator emits
   neither: no `OutputIntent` object exists in `buildPdfA3Bytes()`'s
   9-object layout, and the `/F1` font object references the standard
   Helvetica font by name (`/BaseFont /Helvetica`) with no `FontFile`/
   `FontFile2`/`FontFile3` — i.e. not embedded. Fix: delete the
   `pdfaid:*` XMP block entirely rather than attempt a partial/incorrect
   remediation (see Trade-offs).

Both defects are independent of each other and independent of the
`/AF`/`/EmbeddedFiles` Associated-Files mechanism itself, which is a valid
ISO 32000-2 feature usable in any PDF (not exclusively a PDF/A-3 or
Factur-X feature) and is left unchanged.

## Goals / Non-Goals

**Goals:** the generated PDF's filename and metadata must not claim
conformance the byte-writer does not deliver; the spec + docs must
describe what the generator actually produces.

**Non-Goals:** making the PDF genuinely PDF/A-3-conformant (would require
embedding a real ICC profile + embedding Helvetica or switching to an
embedded font + broader structural conformance checks); building a real
UN/CEFACT CII generator. Both are out of scope — see proposal.md.

## Decisions

### D1 — Rename the filename, don't try to reshape the payload into CII

**Alternative considered:** keep `factur-x.xml` but note in the `/Desc`
field that the payload is UBL not CII. **Rejected** — Factur-X detection in
the wild is filename-driven, not `/Desc`-driven; a human reading the PDF's
metadata inspector might see the caveat, but the automated ingestion
pipelines that matter (DE/FR AP-to-AP software) will not. The filename
itself must not lie.

### D2 — Delete the PDF/A-3B XMP claim rather than attempt a partial fix

**Alternative considered:** downgrade to a lower/weaker PDF/A-3
conformance level, or add the ICC `OutputIntent` only (leaving the
unembedded-font issue). **Rejected** — any `pdfaid:conformance` value is a
level-specific conformance claim; since the generator meets none of them
(the font-embedding gap alone disqualifies every PDF/A level, not just
Level B), the only honest fix is not to assert a `pdfaid:part`/
`pdfaid:conformance` pair at all. The document remains a syntactically
valid PDF 1.7 with a legitimately embedded Associated File — just not a
conformance-validated PDF/A-3.

### D3 — Keep `generateHybridPdf()`'s public signature and return shape unchanged

**Alternative considered:** rename the method / return keys to remove
"hybrid" or "PDF/A-3" language from the public surface too. **Rejected** —
"hybrid" (human+machine-readable in one file) remains an accurate
description of what the method produces; the return shape
(`{filename, pdf, mimeType, embeddedXmlFilename}`) is already
type-generic (no field is named `facturX` or `pdfA3`). Only the
constant's *value* and the private byte-writer's internal naming/comments
change — zero blast radius on `EInvoiceService` or any other caller.

## Risks / Trade-offs

- [Risk] Reverting an incorrect metadata claim after it has already shipped
  in production PDFs could look like "we downgraded compliance" to an
  uninformed reader of the diff → [Mitigation] proposal.md + this
  design.md + the new REQ-EINV-008 state plainly that the OLD claim was
  never true; this change makes the artefact's self-description match
  reality, it does not remove a real capability.
- [Trade-off] Not attempting genuine PDF/A-3 conformance or real CII output
  now (see proposal.md Out of Scope) leaves the NL Peppol path exactly as
  capable as before (UBL, correctly labelled) but DE/FR trading partners
  who strictly require Factur-X still cannot be served by this generator
  — same functional gap as today, now honestly disclosed instead of
  falsely claimed closed.

## Migration Plan

No data migration. Purely a byte-output + documentation change deployed
via the normal merge; any PDF already generated and stored under the old
filename/claim is historical and out of scope for retroactive correction
(the artefact is regenerated fresh on every send, never mutated in place).

## Declarative-vs-imperative decision (ADR-031)

Not applicable — this change touches only the existing imperative
`InvoicePdfGenerator` document-generation service (already justified as an
allowed imperative surface under ADR-031 — "document generation... are
allowed imperative surfaces", per this same spec's Notes section) and
prose/spec files. No OpenRegister schema, lifecycle, or aggregation is
introduced or modified.

## Seed Data

Not applicable — no new data schema is introduced; `InvoicePdfGenerator`
is a stateless byte-writer with no persisted seed objects.
