# Tasks — facturx-cii-conformance

## InvoicePdfGenerator fix

- [ ] Rename `HYBRID_XML_FILENAME` from `factur-x.xml` to `ubl-invoice.xml`
- [ ] Remove the `pdfaid:part`/`pdfaid:conformance` XMP block (no ICC OutputIntent or embedded fonts are emitted)
- [ ] Rename `buildPdfA3Bytes()` to `buildHybridPdfBytes()` and rewrite its docblock to describe the artefact accurately
- [ ] Rewrite the class-level + `generateHybridPdf()` docblocks: NLCIUS UBL hybrid for NL/Peppol, explicitly NOT Factur-X/ZUGFeRD, explicitly NOT a conformance-validated PDF/A-3

## Tests

- [ ] Update `InvoicePdfGeneratorTest` to assert the new filename and the absence of `pdfaid:*` metadata

## Spec + docs correction

- [ ] MODIFY REQ-EINV-002 in the spec delta (filename + no false conformance claim)
- [ ] ADD REQ-EINV-008 (Factur-X/ZUGFeRD CII output explicitly not provided)
- [ ] Correct the canonical spec's Purpose paragraph + REQ-EINV-000 prose on archive
- [ ] Correct `docs/guides/create-invoices.md`'s hybrid-PDF paragraph
- [ ] Correct the `openspec/ROADMAP.md` one-line roadmap entry
