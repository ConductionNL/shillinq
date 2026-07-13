---
kind: code
---

# Proposal: facturx-cii-conformance

## Summary

Corrects a false conformance claim introduced by
`add-invoice-pdf-export-with-ubl-peppol-support` (merged 2026-07-12):
`InvoicePdfGenerator::generateHybridPdf()` embeds the **NLCIUS UBL** XML
under the well-known Factur-X/ZUGFeRD attachment filename `factur-x.xml`
and asserts `pdfaid:conformance=B` (PDF/A-3B) in the document's XMP
metadata — but Factur-X/ZUGFeRD mandate **UN/CEFACT CII** syntax, not UBL,
and the hand-written PDF byte-writer emits neither an ICC `OutputIntent`
nor embedded fonts, both hard PDF/A requirements. A DE/FR trading partner
whose invoice-receiving software auto-detects `factur-x.xml` will attempt
to parse it as CII and fail, or (worse) silently misfile it. This change
renames the embedded attachment to a truthful, non-Factur-X filename,
removes the false PDF/A-3B conformance assertion, corrects the spec + docs
prose, and adds a requirement documenting that true Factur-X/ZUGFeRD (CII)
output is explicitly NOT provided.

## Motivation

`lib/Service/InvoicePdfGenerator.php` already conceded in its own
docblock (pre-existing, ~L22-26) that the artefact is a "best-effort
PDF/A-3-shaped document... not independently veraPDF/Schematron-validated"
— but the machine-readable XMP metadata written into every generated PDF
still asserts `pdfaid:part=3`/`pdfaid:conformance=B`, and the attachment
filename `factur-x.xml` is the exact signal DE/FR e-invoicing software uses
to auto-detect Factur-X/ZUGFeRD (CII syntax). Both are false conformance
claims baked into a machine-parsed artefact that gets transmitted over
Peppol to real trading partners (`EInvoiceService::sendInvoice()`). NL is
unaffected — NL e-invoicing is NLCIUS UBL over Peppol, which is exactly
what is produced and exactly what is claimed once this change lands — but
the artefact must stop claiming to be something it structurally cannot be
for non-NL recipients.

## Affected Projects

- [x] Project: `shillinq` — rename the embedded-attachment filename, drop
      the false PDF/A-3B XMP assertion, correct
      `openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md` +
      `docs/guides/create-invoices.md` + `openspec/ROADMAP.md` prose, add
      REQ-EINV-008 documenting the CII gap.

## Scope

### In Scope
- Rename `InvoicePdfGenerator::HYBRID_XML_FILENAME` from `factur-x.xml` to
  `ubl-invoice.xml` (a truthful, non-Factur-X-branded name for the UBL
  payload); update the embedded Filespec `/Desc` and PDF-object naming to
  match.
- Remove the `pdfaid:part`/`pdfaid:conformance` XMP assertion — the
  document does not emit an ICC `OutputIntent` (ISO 19005-3 hard
  requirement for PDF/A-3 conformance) and uses a non-embedded standard
  font (Helvetica, also a hard PDF/A requirement violation), so declaring
  `pdfaid:conformance=B` is a false machine-readable claim. Keep the `dc:title`
  XMP metadata and the `/AF` + `/EmbeddedFiles` Associated-Files mechanism
  (a legitimate ISO 32000-2 feature independent of full PDF/A conformance).
- Rewrite `InvoicePdfGenerator`'s class/method docblocks to describe the
  artefact accurately: a PDF with an embedded NLCIUS UBL XML via the
  Associated-Files mechanism, for NL/Peppol; explicitly not Factur-X/ZUGFeRD
  (CII) and not a conformance-validated PDF/A-3 document.
- Correct `openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md`:
  Purpose paragraph, REQ-EINV-000, REQ-EINV-002 (MODIFIED, full content),
  new REQ-EINV-008 (ADDED — Factur-X/ZUGFeRD CII output NOT provided).
  While touching this file: correct the stale header (`status: in-progress`
  / old change name still listed even though that change is already
  archived — a pre-existing gap encountered while making this edit).
- Correct `docs/guides/create-invoices.md` (the same false claim, user-facing)
  and the one-line `openspec/ROADMAP.md` roadmap entry.
- Update `tests/Unit/Service/InvoicePdfGeneratorTest.php` to assert the new
  filename and the ABSENCE of the false conformance claim.

### Out of Scope
- A real UN/CEFACT CrossIndustryInvoice (CII) generator behind a profile
  flag, and a genuinely PDF/A-3-conformant binary (embedded ICC profile +
  embedded fonts + full structural conformance). Both are tractable in
  principle but **not implementable safely without a real conformance
  validator** (KoSIT/Mustang for CII, veraPDF for PDF/A) which is not
  available in this environment — shipping either without the ability to
  validate it would risk repeating exactly the mistake this change fixes
  (an unverified conformance claim). Tracked as a follow-up; see Open
  Questions.

## Approach

Rename the constant value + PDF `/Desc` field, delete the `pdfaid:*` XMP
block, and rewrite prose (docblocks, spec, docs, roadmap) to describe the
artefact as it actually is: a human+machine-readable PDF for the NL/Peppol
UBL path, not a Factur-X/ZUGFeRD/PDF-A3-conformant hybrid. No behavioural
change to the NL send path — `generateHybridPdf()`'s signature, return
shape, and the `/AF`/`/EmbeddedFiles` embedding mechanism are unchanged;
only the filename string, the XMP conformance block, and prose change.

## New Dependencies

None.

## Impact

- `lib/Service/InvoicePdfGenerator.php`
- `tests/Unit/Service/InvoicePdfGeneratorTest.php`
- `openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md`
- `docs/guides/create-invoices.md`
- `openspec/ROADMAP.md`

## Cross-Project Dependencies

None.

## Risks

### Risk 1: Downstream consumers hardcode the old `factur-x.xml` filename

**Severity:** Low — **Mitigation:** grepped the entire repo (PHP, Vue, JS,
docs) for `factur-x`/`HYBRID_XML_FILENAME`; the only consumer is
`EInvoiceService::sendInvoice()`, which reads the returned array's
`embeddedXmlFilename` key generically (never hardcodes the literal string)
and passes the whole hybrid artefact opaquely to `storeArtefact()`/the
Peppol transmission port. No frontend or other service hardcodes the old
name.

## Rollback Strategy

Revert the filename constant, the XMP block removal, and the prose edits.
No data migration — the change touches only the generator's byte output
and documentation, not any persisted schema or record.

## Open Questions

Should a real UN/CEFACT CII generator (behind a profile flag, for DE/FR/IT
trading partners who hard-require Factur-X/ZUGFeRD) be built in a follow-up
change? Deliberately deferred — it needs a conformance validator in the
loop (KoSIT/Mustang) to ship safely, which is infrastructure this change
does not set up.
