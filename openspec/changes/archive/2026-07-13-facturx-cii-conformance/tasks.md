# Tasks — facturx-cii-conformance

## InvoicePdfGenerator fix

- [x] Rename `HYBRID_XML_FILENAME` from `factur-x.xml` to `ubl-invoice.xml`
- [x] Remove the `pdfaid:part`/`pdfaid:conformance` XMP block (no ICC OutputIntent or embedded fonts are emitted)
- [x] Rename `buildPdfA3Bytes()` to `buildHybridPdfBytes()` and rewrite its docblock to describe the artefact accurately
- [x] Rewrite the class-level + `generateHybridPdf()` docblocks: NLCIUS UBL hybrid for NL/Peppol, explicitly NOT Factur-X/ZUGFeRD, explicitly NOT a conformance-validated PDF/A-3

## Tests

- [x] Update `InvoicePdfGeneratorTest` to assert the new filename and the absence of `pdfaid:*` metadata

## Spec + docs correction

- [x] MODIFY REQ-EINV-002 in the spec delta (filename + no false conformance claim)
- [x] ADD REQ-EINV-008 (Factur-X/ZUGFeRD CII output explicitly not provided)
- [x] Correct the canonical spec's Purpose paragraph + REQ-EINV-000 prose (done now, ahead of archive, since it's free-form prose the delta-sync mechanism does not touch)
- [x] Correct `docs/guides/create-invoices.md`'s hybrid-PDF paragraph
- [x] Correct the `openspec/ROADMAP.md` one-line roadmap entry
