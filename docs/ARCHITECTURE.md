# Shillinq — Architecture

Complete open-source business administration suite for freelancers, sole proprietors, SMBs, and corporations. Combines bookkeeping, invoicing, procurement, and contract management into one self-hosted solution on Nextcloud.Named after the shilling — one of the oldest and most widely used coins in European history, from the Roman solidus to the British shilling to the East African shilling still in use today.Shillinq covers:- Bookkeeping & general ledger (double-entry accounting)- Accounts payable & receivable- Sales invoicing & e-invoicing (UBL/Peppol)- Purchase orders & procurement workflows- Supplier management & approval chains- Contract lifecycle management (creation, renewal, obligations)- Bank reconciliation & payment matching- VAT/tax reporting & compliance- Financial statements (P&L, balance sheet, cash flow)- Budget planning & forecasting- Multi-currency support- Dutch government compliance (BBV, IV3, SiSa, DigiInkoop)

## Standards

- **Aanbestedingswet**: Aanbestedingswet 2012 (aanbestedingsrecht)
- **BBV**: Besluit Begroting en Verantwoording provincies en gemeenten (government-accounting)
- **BW Boek 2 Titel 9**: Burgerlijk Wetboek Boek 2 Titel 9 - Jaarrekening (handelsrecht)
- **DCAT**: Data Catalog Vocabulary (Openbaar en toegankelijk)
- **DCAT-AP-NL**: Het Nederlandse applicatieprofiel op DCAT-AP ()
- **DigiInkoop**: DigiInkoop e-Procurement (e-procurement)
- **Fiscale bewaarplicht**: Fiscale bewaarplicht (AWR art. 52) (belastingrecht)
- **Handelsregisterwet**: Handelsregisterwet 2007 (handelsrecht)
- **ISO 20022**: ISO 20022 Financial Messaging (payments)
- **IV3**: Informatie voor Derden (government-accounting)
- **NLCIUS**: Semantisch Model e-Factuur (e-invoicing)
- **Peppol BIS**: Pan-European Public Procurement Online Business Interoperability Specifications (e-invoicing)
- **RGS**: Referentie Grootboekschema (accounting)
- **SBR**: Standard Business Reporting (financial-reporting)
- **SEPA**: Single Euro Payments Area (payments)
- **SiSa**: Single Information Single Audit (government-accounting)
- **TOOI**: Thesauri en Ontologie&#xEB;n voor Overheidsinformatie (Uitwisselingsfundament)
- **UBL**: Universal Business Language (e-invoicing)
- **Wet OB**: Wet op de Omzetbelasting 1968 (belastingrecht)
- **Wet VPB**: Wet op de Vennootschapsbelasting 1969 (belastingrecht)
- **Wwft**: Wet ter voorkoming van witwassen en financieren van terrorisme (financieel-toezicht)
- **XBRL**: eXtensible Business Reporting Language Dimensions (financial-reporting)

## Feature Domains

- Ai
- Analytics
- Collaboration
- Core
- Document Management
- Governance
- Integration
- Media
- Participation
- Scheduling
- Security

## Architectural Decision Records

- **Architecture — Shillinq** — see `openspec/architecture/`
- **Data Storage — Shillinq** — see `openspec/architecture/`
- **Deployment — Shillinq** — see `openspec/architecture/`
- **Integration — Shillinq** — see `openspec/architecture/`
- **Security — Shillinq** — see `openspec/architecture/`

## Data Layer

All data stored via OpenRegister (JSON schema validated objects). No custom database tables.
