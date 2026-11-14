# Design: public-sector-council-and-auditor-statements

Read at shillinq development @655363ab0 on 2026-09-29.

## Decisions

### D1. Lawfulness paragraph

`lib/Service/PublicSector/LawfulnessParagraph.php::compute(string $paragraphId)` reads the year's posted expenses including reserve mutations (the `ReserveMutation` postings of change public-sector-reserves-and-interest land on the same GL), the `Tolerantiegrens` percentages, and the open and resolved `Rechtmatigheidsbevinding` amounts, all in cents, and writes the totals and `within_tolerance` onto `Rechtmatigheidsparagraaf`. It runs from a `compute` action on the paragraph page and again before `vaststellen_college`, whose guard `RechtmatigheidGuard::canVaststellenParagraaf` already checks the rest.

### D2. Audit samples

`lib/Service/PublicSector/AuditSampleDrawer.php::draw(string $protocolId, array $population, int $size, ?int $seed)` selects GL lines by a seeded generator (`mt_srand` with the stored `reproducibleSeed`, so a second draw with the same seed returns the same lines) and writes `AuditSample` with the selected line ids. The protocol detail page gets a draw action and a panel over `/api/bado/controleprotocol/aggregation`.

### D3. ENSIA

Actions on the `ENSIAJaarcyclus` transitions, declared by FQCN: `naarPeerReview` runs `ENSIABevindingGenerator::generate` and stores each `Bevinding`; `naarCollegeAkkoord` runs `ENSIAVerklaringGenerator::render` and stores the file in `declarationFile`; `indienen` checks `ENSIAXmlExporter::canExport` and stores the XML. `GET /api/v1/public-sector/ensia/{id}/xml` downloads it.
