<?php

/**
 * ENSIA College-Verklaring Generator
 *
 * REQ-ENSIA-006 — produce a Word (DOCX) document on the VNG-template shape
 * with auto-filled organisatie data, evaluation date, per-domein samenvatting,
 * top findings, and blank handtekeningvelden for ondertekenaars.
 *
 * DOCX is a ZIP-archive of OOXML parts. We assemble a minimal Office-Open-XML
 * package (Document.xml + content-types + rels) carrying paragraphs the
 * college can review and sign. The generated artifact is openable in
 * LibreOffice / MS Word for printing and signing.
 *
 * Pure rendering — caller archives the resulting bytes on
 * ENSIAJaarcyclus.verklaringFile via docudesk (the real implementation
 * route).
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
 * @spec openspec/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use RuntimeException;
use ZipArchive;

/**
 * Generates VNG-template college-verklaring DOCX documents (REQ-ENSIA-006).
 *
 * @spec openspec/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Pre-existing debt (issue
 *     #506): changing this signature would ripple to callers; deferred.
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * Pre-existing debt (issue #506): early-return refactor and variable
 * renames deferred pending a dedicated pass.
 */
class ENSIAVerklaringGenerator {
	/**
	 * Number of top findings to list on the verklaring.
	 *
	 * @var int
	 */
	private const TOP_FINDINGS_LIMIT = 5;

	/**
	 * Render the verklaring as DOCX bytes.
	 *
	 * @param array<string,mixed> $cyclus ENSIAJaarcyclus record.
	 * @param array<int,array<string,mixed>> $vragen All Evaluatievraag children.
	 * @param array<int,array<string,mixed>> $bevindingen All Bevinding children
	 *                                                    ordered by impact desc.
	 *
	 * @return string Binary DOCX content.
	 *
	 * @throws RuntimeException When ZipArchive cannot open a temp file.
	 */
	public function render(array $cyclus, array $vragen, array $bevindingen): string {
		$documentXml = $this->buildDocumentXml(cyclus: $cyclus, vragen: $vragen, bevindingen: $bevindingen);
		$contentTypes = $this->buildContentTypesXml();
		$rels = $this->buildRelsXml();
		$documentRels = $this->buildDocumentRelsXml();

		$tmpFile = tempnam(sys_get_temp_dir(), 'ensia-verklaring-');
		if ($tmpFile === false) {
			throw new RuntimeException('Failed to allocate DOCX temp file');
		}

		$zip = new ZipArchive();
		$opened = $zip->open($tmpFile, ZipArchive::OVERWRITE | ZipArchive::CREATE);
		if ($opened !== true) {
			unlink($tmpFile);
			throw new RuntimeException('Failed to open DOCX ZIP for writing');
		}

		$zip->addFromString('[Content_Types].xml', $contentTypes);
		$zip->addFromString('_rels/.rels', $rels);
		$zip->addFromString('word/_rels/document.xml.rels', $documentRels);
		$zip->addFromString('word/document.xml', $documentXml);
		$zip->close();

		$content = (string)file_get_contents($tmpFile);
		unlink($tmpFile);

		return $content;
	}//end render()

	/**
	 * Build the [Content_Types].xml OOXML part registry.
	 *
	 * @return string XML.
	 */
	private function buildContentTypesXml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/word/document.xml" '
			. 'ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
			. '</Types>';

	}//end buildContentTypesXml()

	/**
	 * Build the root relationships XML pointing at the document part.
	 *
	 * @return string XML.
	 */
	private function buildRelsXml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" '
			. 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" '
			. 'Target="word/document.xml"/>'
			. '</Relationships>';

	}//end buildRelsXml()

	/**
	 * Build the (empty) document-level relationships XML.
	 *
	 * @return string XML.
	 */
	private function buildDocumentRelsXml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>';

	}//end buildDocumentRelsXml()

	/**
	 * Build the OOXML word/document.xml body.
	 *
	 * @param array<string,mixed> $cyclus Cyclus record.
	 * @param array<int,array<string,mixed>> $vragen Vragen array.
	 * @param array<int,array<string,mixed>> $bevindingen Bevindingen ordered desc.
	 *
	 * @return string XML.
	 */
	private function buildDocumentXml(array $cyclus, array $vragen, array $bevindingen): string {
		$org = $cyclus['organisation'] ?? [];
		$orgName = (string)($org['name'] ?? '');
		$orgKvk = (string)($org['kvk'] ?? '');
		$year = (string)($cyclus['year'] ?? '');

		$paras = [];
		$paras[] = $this->para(text: 'College-verklaring ENSIA ' . $year, bold: true);
		$paras[] = $this->para(text: 'Organisatie: ' . $orgName . ' (KvK ' . $orgKvk . ')');
		$paras[] = $this->para(text: 'Verslagjaar: ' . $year);
		$paras[] = $this->para(text: 'Datum opmaak: ' . (new DateTimeImmutable('now'))->format('Y-m-d'));
		$paras[] = $this->para(text: '');

		// Per-domein summary.
		$paras[] = $this->para(text: 'Samenvatting per verantwoordingsdomein:', bold: true);
		foreach ($this->summariseByDomein(vragen: $vragen) as $line) {
			$paras[] = $this->para(text: $line);
		}

		$paras[] = $this->para(text: '');

		// Top findings.
		$paras[] = $this->para(text: 'Top ' . self::TOP_FINDINGS_LIMIT . ' bevindingen + mitigatieplan:', bold: true);
		$top = array_slice($bevindingen, 0, self::TOP_FINDINGS_LIMIT);
		if (count($top) === 0) {
			$paras[] = $this->para(text: 'Geen openstaande bevindingen.');
		} else {
			$i = 1;
			foreach ($top as $b) {
				$type = (string)($b['type'] ?? 'tekortkoming');
				$description = (string)($b['description'] ?? '');
				$mitigationAction = (string)($b['mitigationAction'] ?? 'nader te bepalen');
				$paras[] = $this->para(
					text: sprintf('%d. [%s] %s — mitigatie: %s', $i, $type, $description, $mitigationAction)
				);
				$i++;
			}
		}

		$paras[] = $this->para(text: '');
		$paras[] = $this->para(text: 'Handtekeningvelden:', bold: true);
		$paras[] = $this->para(text: 'Burgemeester: ____________________________  Datum: __________');
		$paras[] = $this->para(text: 'Wethouder:     ____________________________  Datum: __________');
		$paras[] = $this->para(text: 'Secretaris:    ____________________________  Datum: __________');

		$body = implode('', $paras);

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body>'
			. $body
			. '</w:body>'
			. '</w:document>';

	}//end buildDocumentXml()

	/**
	 * Render one OOXML paragraph carrying the given text.
	 *
	 * @param string $text The paragraph text.
	 * @param bool $bold Render text bold.
	 *
	 * @return string OOXML.
	 */
	private function para(string $text, bool $bold = false): string {
		$escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
		$runProps = '';
		if ($bold === true) {
			$runProps = '<w:rPr><w:b/></w:rPr>';
		}

		return '<w:p><w:r>' . $runProps . '<w:t xml:space="preserve">' . $escaped . '</w:t></w:r></w:p>';
	}//end para()

	/**
	 * Build per-domein summary lines: how many questions met maturity ≥ 3.
	 *
	 * @param array<int,array<string,mixed>> $vragen Evaluatievraag children.
	 *
	 * @return array<int,string> Summary lines.
	 */
	private function summariseByDomein(array $vragen): array {
		$counts = [];
		foreach ($vragen as $v) {
			$domain = (string)($v['domain'] ?? 'BIO');
			if (isset($counts[$domain]) === false) {
				$counts[$domain] = ['total' => 0, 'metNorm' => 0];
			}

			$counts[$domain]['total']++;

			$score = $v['maturityScore'] ?? null;
			$norm = $v['normniveau'] ?? null;
			if (is_int($score) === true && is_int($norm) === true && $score >= $norm) {
				$counts[$domain]['metNorm']++;
			} elseif ($score === null && (string)($v['answer'] ?? '') === 'ja') {
				// Ja-nee-nvt: count 'ja' as norm-met.
				$counts[$domain]['metNorm']++;
			}
		}

		$lines = [];
		foreach ($counts as $domain => $c) {
			$lines[] = sprintf(
				'- %s: %d van %d vragen voldoen aan VNG-norm.',
				$domain,
				$c['metNorm'],
				$c['total']
			);
		}

		return $lines;
	}//end summariseByDomein()
}//end class
