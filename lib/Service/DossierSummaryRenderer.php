<?php

/**
 * Accountantsdossier Summary Renderer
 *
 * Renders the PDF/A-oriented HTML summary of an Accountantsdossier (BADO):
 * the document head with its PDF/A metadata, the title block and one
 * section per bundled collection — Controleprotocol, ToleranceMatrix,
 * Materialiteit, AuditSamples, AuditFindings, VerklaringDraft, SiSaAssurance.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Renders the Accountantsdossier summary from the manifest and the bundle.
 *
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-16
 */
final class DossierSummaryRenderer {

	/**
	 * Stylesheet of the HTML summary.
	 *
	 * @var string
	 */
	private const SUMMARY_STYLE = '<style>body{font-family:Helvetica,Arial,sans-serif;color:#222;font-size:11pt;margin:24px;}'
		. 'h1{font-size:20pt;margin:0 0 12px;}h2{font-size:14pt;margin:24px 0 8px;}'
		. 'table{border-collapse:collapse;width:100%;margin:8px 0;font-size:10pt;}'
		. 'th,td{padding:6px 8px;border:1px solid #ccc;text-align:left;vertical-align:top;}'
		. 'th{background:#eee;}.num{text-align:right;}.muted{color:#666;font-size:9pt;}'
		. '.opinion{padding:8px;border:2px solid #333;background:#f8f8f8;font-size:13pt;font-weight:bold;}'
		. '</style>';

	/**
	 * Render the PDF/A-oriented HTML summary for the dossier.
	 *
	 * The HTML is structured so a downstream PDF/A renderer (mPDF / wkhtmltopdf
	 * with PDF/A-1b profile / Apache PDFBox) produces an ISO 19005-1:2005
	 * compliant binary. The metadata block carries `pdfaid:part="1"` and
	 * `pdfaid:conformance="B"` so the renderer can embed the XMP packet.
	 *
	 * @param array<string,mixed> $manifest The manifest payload.
	 * @param array<string,mixed> $bundle The collected bundle.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-16
	 */
	public function render(array $manifest, array $bundle): string {
		$protocol = ($bundle['auditProtocol'] ?? []);

		return $this->documentHead(manifest: $manifest)
			. $this->titleBlock(manifest: $manifest, protocol: $protocol)
			. $this->protocolSection(protocol: $protocol)
			. $this->toleranceSection(rows: $bundle['toleranceMatrix'])
			. $this->materialitySection(rows: $bundle['materialiteit'])
			. $this->samplesSection(samples: $bundle['auditSamples'])
			. $this->findingsSection(findings: $bundle['auditFindings'])
			. $this->declarationSection(declaration: $bundle['verklaringDraft'])
			. $this->sisaSection(rows: $bundle['sisaAssurance'])
			. '<p class="muted">Retentie: ' . AccountantsdossierExportService::RETENTION_YEARS . ' jaar (Archiefwet / Selectielijst Gemeenten 2020 21.1). '
			. 'Handtekening: PKIO RSA-SHA256 / PAdES B-LT (gedelegeerd aan externe signer).</p>'
			. '</body></html>';
	}//end render()

	/**
	 * The document head: PDF/A metadata (`pdfaid:part` / `pdfaid:conformance`
	 * so the renderer can embed the XMP packet) and the stylesheet.
	 *
	 * @param array<string,mixed> $manifest The manifest payload.
	 *
	 * @return string
	 */
	private function documentHead(array $manifest): string {
		$packageId = (string)$manifest['packageId'];

		return sprintf(
			'<!doctype html><html lang="nl"><head><meta charset="utf-8">'
			. '<title>Accountantsdossier %s</title>'
			. '<meta name="pdfaid:part" content="%s">'
			. '<meta name="pdfaid:conformance" content="B">'
			. '<meta name="xmp:CreatorTool" content="Shillinq BADO Dossier Exporter">'
			. '<meta name="xmpMM:DocumentID" content="urn:uuid:%s">'
			. '<meta name="dc:title" content="Accountantsdossier %s">'
			. '<meta name="generated-at" content="%s">'
			. '<meta name="sha256-anchor" content="%s">',
			htmlspecialchars($packageId, ENT_QUOTES),
			AccountantsdossierExportService::PDFA_PART,
			$this->packageUuid(packageId: $packageId),
			htmlspecialchars($packageId, ENT_QUOTES),
			htmlspecialchars((string)$manifest['generatedAt'], ENT_QUOTES),
			htmlspecialchars((string)$manifest['sha256'], ENT_QUOTES)
		) . self::SUMMARY_STYLE . '</head><body>';
	}//end documentHead()

	/**
	 * The title and the bundle line (package, generation time, SHA-256 anchor,
	 * PDF/A profile).
	 *
	 * @param array<string,mixed> $manifest The manifest payload.
	 * @param array<string,mixed> $protocol The Controleprotocol.
	 *
	 * @return string
	 */
	private function titleBlock(array $manifest, array $protocol): string {
		return sprintf(
			'<h1>Accountantsdossier BADO — %s</h1>'
			. '<p class="muted">Bundle: %s &middot; Gegenereerd: %s (ISO 8601 UTC) &middot; '
			. 'SHA-256: <code>%s</code> &middot; PDF/A-%sB / ISO 19005-1:2005</p>',
			htmlspecialchars($this->organisationLabel(protocol: $protocol), ENT_QUOTES),
			htmlspecialchars((string)$manifest['packageId'], ENT_QUOTES),
			htmlspecialchars((string)$manifest['generatedAt'], ENT_QUOTES),
			htmlspecialchars((string)$manifest['sha256'], ENT_QUOTES),
			AccountantsdossierExportService::PDFA_PART
		);
	}//end titleBlock()

	/**
	 * The organisation as "<type> <id>".
	 *
	 * @param array<string,mixed> $protocol The Controleprotocol.
	 *
	 * @return string
	 */
	private function organisationLabel(array $protocol): string {
		return (string)($protocol['organisationType'] ?? '') . ' ' . (string)($protocol['organisationId'] ?? '');
	}//end organisationLabel()

	/**
	 * Section 1: the Controleprotocol (version, audit year, organisation,
	 * effective period, adoption decision).
	 *
	 * @param array<string,mixed> $protocol The Controleprotocol.
	 *
	 * @return string
	 */
	private function protocolSection(array $protocol): string {
		$adoptionDecision = (array)($protocol['adoptionDecision'] ?? []);

		return sprintf(
			'<h2>1. Controleprotocol</h2>'
			. '<table><tr><th>Versie</th><td>%s</td><th>Auditjaar</th><td>%d</td></tr>'
			. '<tr><th>Organisatie</th><td>%s</td><th>Effectief</th><td>%s &mdash; %s</td></tr>'
			. '<tr><th>Adoptiebesluit</th><td colspan="3">%s (%s) op %s</td></tr></table>',
			htmlspecialchars((string)($protocol['version'] ?? ''), ENT_QUOTES),
			(int)($protocol['auditYear'] ?? 0),
			htmlspecialchars($this->organisationLabel(protocol: $protocol), ENT_QUOTES),
			htmlspecialchars((string)($protocol['effectiveFrom'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($protocol['effectiveTo'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($adoptionDecision['decisionType'] ?? '—'), ENT_QUOTES),
			htmlspecialchars((string)($adoptionDecision['decisionNumber'] ?? '—'), ENT_QUOTES),
			htmlspecialchars((string)($adoptionDecision['date'] ?? '—'), ENT_QUOTES)
		);
	}//end protocolSection()

	/**
	 * Section 2: the ToleranceMatrix table.
	 *
	 * @param array<int,array<string,mixed>> $rows ToleranceMatrix rows.
	 *
	 * @return string
	 */
	private function toleranceSection(array $rows): string {
		return '<h2>2. ToleranceMatrix</h2><table><thead><tr><th>Topic</th>'
			. '<th class="num">Goedkeurend % (rechtm.)</th><th class="num">Beperking % (rechtm.)</th>'
			. '<th class="num">Goedkeurend % (getr.)</th><th class="num">Beperking % (getr.)</th>'
			. '<th class="num">Onzekerheid %</th></tr></thead><tbody>'
			. $this->renderToleranceRows(rows: $rows)
			. '</tbody></table>';
	}//end toleranceSection()

	/**
	 * Section 3: the Materialiteit table.
	 *
	 * @param array<int,array<string,mixed>> $rows Materialiteit rows.
	 *
	 * @return string
	 */
	private function materialitySection(array $rows): string {
		return '<h2>3. Materialiteit</h2><table><thead><tr><th>Scope</th><th>Basis</th>'
			. '<th class="num">Basisbedrag (€)</th><th class="num">Percentage %</th>'
			. '<th class="num">Materialiteit (€)</th><th>Status</th></tr></thead><tbody>'
			. $this->renderMaterialityRows(rows: $rows)
			. '</tbody></table>';
	}//end materialitySection()

	/**
	 * Section 4: the AuditSamples table.
	 *
	 * @param array<int,array<string,mixed>> $samples AuditSample rows.
	 *
	 * @return string
	 */
	private function samplesSection(array $samples): string {
		return '<h2>4. AuditSamples</h2><table><thead><tr><th>Populatie</th><th>Methode</th>'
			. '<th class="num">Steekproefgrootte</th><th>Seed</th><th>Geëxtraheerd op</th></tr></thead>'
			. '<tbody>'
			. $this->renderSampleRows(samples: $samples)
			. '</tbody></table>';
	}//end samplesSection()

	/**
	 * Section 5: the AuditFindings table.
	 *
	 * @param array<int,array<string,mixed>> $findings AuditFinding rows.
	 *
	 * @return string
	 */
	private function findingsSection(array $findings): string {
		return '<h2>5. AuditFindings</h2><table><thead><tr><th>Transactie</th><th>Type</th>'
			. '<th>Topic</th><th class="num">Bedrag (€)</th><th>Severity</th><th>Status</th>'
			. '<th>Controller</th><th>Auditor</th></tr></thead><tbody>'
			. $this->renderFindingRows(findings: $findings)
			. '</tbody></table>';
	}//end findingsSection()

	/**
	 * Section 6: the VerklaringDraft — proposed opinion, rationale and
	 * sign-off ("—" and blanks when there is no draft).
	 *
	 * @param array<string,mixed>|null $declaration The VerklaringDraft, or null.
	 *
	 * @return string
	 */
	private function declarationSection(array|null $declaration): string {
		$signOff = (array)($declaration['signOff'] ?? []);

		return sprintf(
			'<h2>6. VerklaringDraft</h2><div class="opinion">Oordeel: %s</div>'
			. '<p>%s</p>'
			. '<p class="muted">Sign-off: %s &middot; AFM-vergunning %s &middot; %s te %s</p>',
			htmlspecialchars((string)($declaration['proposedOpinion'] ?? '—'), ENT_QUOTES),
			htmlspecialchars((string)($declaration['opinionRationale'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($signOff['auditor'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($signOff['afmPermitNumber'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($signOff['date'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($signOff['place'] ?? ''), ENT_QUOTES)
		);
	}//end declarationSection()

	/**
	 * Section 7: the SiSaAssurance table.
	 *
	 * @param array<int,array<string,mixed>> $rows SiSaAssurance rows.
	 *
	 * @return string
	 */
	private function sisaSection(array $rows): string {
		return '<h2>7. SiSaAssurance</h2><table><thead><tr><th>Regeling</th>'
			. '<th>Verantwoordingsplichtige</th><th>Specifieke uitkering</th>'
			. '<th>Niveau</th><th class="num">Findings</th></tr></thead><tbody>'
			. $this->renderSisaRows(rows: $rows)
			. '</tbody></table>';
	}//end sisaSection()

	/**
	 * Render the ToleranceMatrix rows as `<tr>` HTML.
	 *
	 * @param array<int,array<string,mixed>> $rows ToleranceMatrix rows.
	 *
	 * @return string
	 */
	private function renderToleranceRows(array $rows): string {
		$html = '';
		foreach ($rows as $row) {
			$html .= sprintf(
				'<tr><td>%s</td><td class="num">%s</td><td class="num">%s</td>'
				. '<td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
				htmlspecialchars((string)($row['topic'] ?? ''), ENT_QUOTES),
				$this->fmtNumber(value: $row['lawfulnessApprovalCeiling'] ?? 0),
				$this->fmtNumber(value: $row['lawfulnessQualificationCeiling'] ?? 0),
				$this->fmtNumber(value: $row['faithfulnessApprovalCeiling'] ?? 0),
				$this->fmtNumber(value: $row['faithfulnessQualificationCeiling'] ?? 0),
				$this->fmtNumber(value: $row['uncertaintyCeiling'] ?? 0)
			);
		}

		return $html;
	}//end renderToleranceRows()

	/**
	 * Render the Materialiteit rows as `<tr>` HTML.
	 *
	 * @param array<int,array<string,mixed>> $rows Materialiteit rows.
	 *
	 * @return string
	 */
	private function renderMaterialityRows(array $rows): string {
		$html = '';
		foreach ($rows as $row) {
			$html .= sprintf(
				'<tr><td>%s</td><td>%s</td><td class="num">%s</td>'
				. '<td class="num">%s</td><td class="num">%s</td><td>%s</td></tr>',
				htmlspecialchars((string)($row['scope'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($row['baseLabel'] ?? ($row['base'] ?? '')), ENT_QUOTES),
				$this->fmtMoney(value: (float)($row['base'] ?? 0)),
				$this->fmtNumber(value: $row['percentage'] ?? 0),
				$this->fmtMoney(value: (float)($row['calculatedAmount'] ?? 0)),
				htmlspecialchars((string)($row['status'] ?? ''), ENT_QUOTES)
			);
		}

		return $html;
	}//end renderMaterialityRows()

	/**
	 * Render the AuditSample rows as `<tr>` HTML.
	 *
	 * @param array<int,array<string,mixed>> $samples AuditSample records.
	 *
	 * @return string
	 */
	private function renderSampleRows(array $samples): string {
		$html = '';
		foreach ($samples as $sample) {
			$html .= sprintf(
				'<tr><td>%s</td><td>%s</td><td class="num">%d</td><td><code>%s</code></td><td>%s</td></tr>',
				htmlspecialchars((string)($sample['population'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($sample['selectionMethod'] ?? ''), ENT_QUOTES),
				(int)($sample['sampleSize'] ?? 0),
				htmlspecialchars((string)($sample['reproducibleSeed'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($sample['extractedAt'] ?? ''), ENT_QUOTES)
			);
		}

		return $html;
	}//end renderSampleRows()

	/**
	 * Render the AuditFinding rows as `<tr>` HTML.
	 *
	 * @param array<int,array<string,mixed>> $findings AuditFinding records.
	 *
	 * @return string
	 */
	private function renderFindingRows(array $findings): string {
		$html = '';
		foreach ($findings as $finding) {
			$html .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td class="num">%s</td>'
				. '<td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				htmlspecialchars((string)($finding['transaction'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($finding['findingType'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($finding['topic'] ?? ''), ENT_QUOTES),
				$this->fmtMoney(value: (float)($finding['amount'] ?? 0)),
				htmlspecialchars((string)($finding['severity'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($finding['status'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($finding['controllerResponse'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($finding['auditorConclusion'] ?? ''), ENT_QUOTES)
			);
		}

		return $html;
	}//end renderFindingRows()

	/**
	 * Render the SiSaAssurance rows as `<tr>` HTML.
	 *
	 * @param array<int,array<string,mixed>> $rows SiSaAssurance rows.
	 *
	 * @return string
	 */
	private function renderSisaRows(array $rows): string {
		$html = '';
		foreach ($rows as $row) {
			$count = count((array)($row['findings'] ?? []));
			$html .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="num">%d</td></tr>',
				htmlspecialchars((string)($row['schemeCode'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($row['accountableParty'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($row['specificBenefit'] ?? ''), ENT_QUOTES),
				htmlspecialchars((string)($row['assuranceLevel'] ?? ''), ENT_QUOTES),
				$count
			);
		}

		return $html;
	}//end renderSisaRows()

	/**
	 * Format an EUR amount with Dutch locale.
	 *
	 * @param float $value The amount.
	 *
	 * @return string
	 */
	private function fmtMoney(float $value): string {
		return number_format($value, 2, ',', '.');
	}//end fmtMoney()

	/**
	 * Format a generic number (up to 2 decimals) with Dutch locale.
	 *
	 * @param mixed $value The number.
	 *
	 * @return string
	 */
	private function fmtNumber(mixed $value): string {
		return number_format((float)$value, 2, ',', '.');
	}//end fmtNumber()

	/**
	 * Stable UUID-ish identifier for the XMP DocumentID block.
	 *
	 * @param string $packageId The package id.
	 *
	 * @return string
	 */
	private function packageUuid(string $packageId): string {
		$hash = md5($packageId);
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hash, 0, 8),
			substr($hash, 8, 4),
			substr($hash, 12, 4),
			substr($hash, 16, 4),
			substr($hash, 20, 12)
		);
	}//end packageUuid()
}//end class
