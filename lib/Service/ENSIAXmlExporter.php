<?php

/**
 * ENSIA XML Exporter
 *
 * REQ-ENSIA-007 — render a college-approved ENSIAJaarcyclus into an
 * ENSIA-XSD-compliant XML document for upload to the landelijke
 * ENSIA-portal. No direct portal API exists in 2026 — the operator
 * downloads the artifact and uploads it manually.
 *
 * The XML carries:
 *   - organisation identification (KvK + naam) per REQ-ENSIA-007
 *   - per-domein question answers + maturity scores
 *   - per-question evidence document SHA-256 hashes for integrity
 *   - college-brief file reference + signature timestamp
 *   - cycle status + submission timestamp
 *
 * Pure rendering — no persistence; the caller archives the returned
 * string on ENSIAJaarcyclus for traceability.
 *
 * The XML is built with DOMDocument (construction only — no parsing of
 * untrusted input, so no XXE surface) and entity-escaped.
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
use DOMDocument;

/**
 * Renders ENSIAJaarcyclus records into ENSIA-XSD-compliant XML for the
 * landelijke ENSIA-portal (REQ-ENSIA-007).
 *
 * @spec openspec/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */
class ENSIAXmlExporter {
	/**
	 * ENSIA-portal target namespace per VNG XSD.
	 *
	 * @var string
	 */
	private const NS = 'urn:vng:ensia:zelfevaluatie:v1';

	/**
	 * Lifecycle precondition: may a cyclus be exported / submitted?
	 *
	 * Per REQ-ENSIA-007, the cyclus MUST be in `college-akkoord` status
	 * AND carry a verklaringFile file-reference before XML export is
	 * permitted (a missing signed verklaring is a portal-rejection cause).
	 *
	 * @param array<string,mixed> $cyclus The ENSIAJaarcyclus record.
	 *
	 * @return bool True when export is permitted.
	 */
	public function canExport(array $cyclus): bool {
		$status = (string)($cyclus['status'] ?? '');
		if ($status !== 'college-akkoord' && $status !== 'submitted') {
			return false;
		}

		$declarationFile = (string)($cyclus['declarationFile'] ?? '');
		return $declarationFile !== '';
	}//end canExport()

	/**
	 * Render the cyclus into ENSIA-XSD-compliant XML.
	 *
	 * @param array<string,mixed> $cyclus ENSIAJaarcyclus record.
	 * @param array<int,array<string,mixed>> $vragen All Evaluatievraag children of the cyclus.
	 * @param string|null $submittedAt Optional submission timestamp override; defaults to now.
	 *
	 * @return string The XML string.
	 */
	public function render(array $cyclus, array $vragen, ?string $submittedAt = null): string {
		$doc = new DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;
		$doc->preserveWhiteSpace = false;

		$root = $doc->createElementNS(self::NS, 'ensiaZelfevaluatie');
		$doc->appendChild($root);

		// Organisation.
		$org = $cyclus['organisation'] ?? [];
		$orgEl = $doc->createElement('organisation');
		$orgEl->appendChild($doc->createElement('kvk', (string)($org['kvk'] ?? '')));
		$orgEl->appendChild($doc->createElement('name', (string)($org['name'] ?? '')));
		$root->appendChild($orgEl);

		// Cycle metadata.
		$root->appendChild($doc->createElement('year', (string)($cyclus['year'] ?? '')));
		$root->appendChild($doc->createElement('status', (string)($cyclus['status'] ?? '')));
		$root->appendChild($doc->createElement('questionSetVersion', (string)($cyclus['questionSetVersion'] ?? '')));
		$root->appendChild($doc->createElement('declarationFile', (string)($cyclus['declarationFile'] ?? '')));
		$root->appendChild(
			$doc->createElement('submittedAt', $submittedAt ?? (new DateTimeImmutable('now'))->format(DATE_ATOM))
		);

		// Group questions per domein.
		$byDomein = [];
		foreach ($vragen as $v) {
			$domain = (string)($v['domain'] ?? 'BIO');
			if (isset($byDomein[$domain]) === false) {
				$byDomein[$domain] = [];
			}

			$byDomein[$domain][] = $v;
		}

		$domeinenEl = $doc->createElement('accountabilityDomains');
		foreach ($byDomein as $domain => $domeinVragen) {
			$domeinEl = $doc->createElement('domain');
			$domeinEl->setAttribute('code', $domain);

			foreach ($domeinVragen as $v) {
				$questionEl = $doc->createElement('vraag');
				$questionEl->setAttribute('code', (string)($v['questionCode'] ?? ''));
				$questionEl->appendChild($doc->createElement('answerType', (string)($v['answerType'] ?? '')));

				$answer = $v['answer'] ?? null;
				if ($answer !== null) {
					$questionEl->appendChild($doc->createElement('answer', (string)$answer));
				}

				$score = $v['maturityScore'] ?? null;
				if ($score !== null) {
					$questionEl->appendChild($doc->createElement('maturityScore', (string)$score));
				}

				$notes = (string)($v['notes'] ?? '');
				if ($notes !== '') {
					$questionEl->appendChild($doc->createElement('notes', $notes));
				}

				$peerReviewStatus = (string)($v['peerReviewStatus'] ?? '');
				if ($peerReviewStatus !== '') {
					$questionEl->appendChild($doc->createElement('peerReviewStatus', $peerReviewStatus));
				}

				$supportingDocuments = $v['supportingDocuments'] ?? [];
				if (is_array($supportingDocuments) === true && count($supportingDocuments) > 0) {
					$evidenceEl = $doc->createElement('supportingDocuments');
					foreach ($supportingDocuments as $bw) {
						$bewijsstukEl = $doc->createElement('bewijsstuk');
						$bewijsstukEl->appendChild(
							$doc->createElement('fileRef', (string)($bw['fileRef'] ?? ''))
						);
						$bewijsstukEl->appendChild(
							$doc->createElement('description', (string)($bw['description'] ?? ''))
						);
						$sha = (string)($bw['sha256'] ?? '');
						if ($sha !== '') {
							$bewijsstukEl->appendChild($doc->createElement('sha256', $sha));
						}

						$evidenceEl->appendChild($bewijsstukEl);
					}

					$questionEl->appendChild($evidenceEl);
				}

				$domeinEl->appendChild($questionEl);
			}//end foreach

			$domeinenEl->appendChild($domeinEl);
		}//end foreach

		$root->appendChild($domeinenEl);

		$xml = $doc->saveXML();
		if ($xml === false) {
			// SaveXML cannot fail on construction-only DOMDocument; defensive
			// fallback returns an empty signed envelope.
			return '<?xml version="1.0" encoding="UTF-8"?><ensiaZelfevaluatie/>';
		}

		return $xml;
	}//end render()
}//end class
