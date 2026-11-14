<?php

/**
 * Shillinq XBRL instance check
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sbr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sbr;

use DOMDocument;
use DOMElement;
use OCP\IL10N;

/**
 * What stands between an XBRL instance and validation, item by item.
 *
 * The instance is checked against the taxonomy record effective for its
 * period that lists its entry point: every concept that record requires for
 * the entry point has a fact, the period and the entity identifier are
 * there, and every summation the record declares adds up. What is required
 * and what adds up is data on the taxonomy record, not code, so a new
 * taxonomy version is a new record.
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-sbr-xbrl-reporting/spec.md#requirement-an-instance-is-validated-against-its-entry-point-before-submission-req-tdf-003
 */
class XbrlInstanceCheck {

	/**
	 * How far a total may differ from the sum of its parts (rounding).
	 */
	private const TOLERANCE = 0.005;

	/**
	 * The XBRL instance namespace.
	 */
	private const XBRLI = 'http://www.xbrl.org/2003/instance';

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Words each failing item.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-2.1
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The failing items of an instance; none when it may be validated.
	 *
	 * @param array<string, mixed>      $instance The XbrlInstance.
	 * @param array<string, mixed>|null $taxonomy The taxonomy effective for its period and entry point, or null.
	 *
	 * @return list<string> One sentence per failing item.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-2.1
	 */
	public function problems(array $instance, ?array $taxonomy): array {
		$problems   = [];
		$entryPoint = trim((string) ($instance['entryPoint'] ?? ''));
		$start      = trim((string) ($instance['reportingPeriodStart'] ?? ''));
		$end        = trim((string) ($instance['reportingPeriodEnd'] ?? ''));
		if ($start === '' || $end === '') {
			$problems[] = $this->l10n->t('The reporting period is missing.');
		}

		if ($taxonomy === null) {
			$problems[] = $this->l10n->t('No taxonomy is effective for this period that carries the entry point %s.', [$entryPoint]);
		}

		$document = self::document(xml: (string) ($instance['instanceXml'] ?? ''));
		if ($document === null) {
			$problems[] = $this->l10n->t('The instance has no valid XBRL document.');
			return $problems;
		}

		if (self::hasText(document: $document, localName: 'identifier') === false) {
			$problems[] = $this->l10n->t('The entity identifier is missing.');
		}

		if (self::hasPeriod(document: $document) === false) {
			$problems[] = $this->l10n->t('The instance names no period in its context.');
		}

		if ($taxonomy === null) {
			return $problems;
		}

		$facts = self::facts(document: $document);
		foreach (self::requiredConcepts(taxonomy: $taxonomy, entryPoint: $entryPoint) as $concept) {
			if (self::factValue(facts: $facts, concept: $concept) === null) {
				$problems[] = $this->l10n->t('The required concept %s is missing.', [$concept]);
			}
		}

		foreach (self::summations(taxonomy: $taxonomy, entryPoint: $entryPoint) as $summation) {
			$problem = $this->summationProblem(facts: $facts, total: $summation['total'], parts: $summation['parts']);
			if ($problem !== null) {
				$problems[] = $problem;
			}
		}

		return $problems;
	}//end problems()

	/**
	 * The concepts a taxonomy record requires for an entry point.
	 *
	 * @param array<string, mixed> $taxonomy   The taxonomy record.
	 * @param string               $entryPoint The entry point.
	 *
	 * @return list<string> The concept names.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-2.1
	 */
	public static function requiredConcepts(array $taxonomy, string $entryPoint): array {
		$concepts = (($taxonomy['requiredConcepts'] ?? [])[$entryPoint] ?? []);
		if (is_array($concepts) === false) {
			return [];
		}

		$names = [];
		foreach ($concepts as $concept) {
			if (is_string($concept) === true && trim($concept) !== '') {
				$names[] = trim($concept);
			}
		}

		return array_values(array_unique($names));
	}//end requiredConcepts()

	/**
	 * The summations a taxonomy record declares for an entry point.
	 *
	 * @param array<string, mixed> $taxonomy   The taxonomy record.
	 * @param string               $entryPoint The entry point.
	 *
	 * @return list<array{total: string, parts: list<string>}> Each total with its parts.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-2.1
	 */
	public static function summations(array $taxonomy, string $entryPoint): array {
		$declared = ($taxonomy['summations'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		$out = [];
		foreach ($declared as $summation) {
			if (is_array($summation) === false || ($summation['entryPoint'] ?? '') !== $entryPoint) {
				continue;
			}

			$total = trim((string) ($summation['total'] ?? ''));
			$parts = array_values(array_filter((array) ($summation['parts'] ?? []), static fn (mixed $p): bool => is_string($p) === true && trim($p) !== ''));
			if ($total !== '' && $parts !== []) {
				$out[] = ['total' => $total, 'parts' => $parts];
			}
		}

		return $out;
	}//end summations()

	/**
	 * Why a total does not add up, or null when it does or is not reported.
	 *
	 * @param array<string, string> $facts The facts, by element name.
	 * @param string                $total The total concept.
	 * @param list<string>          $parts The part concepts.
	 *
	 * @return string|null The failing item.
	 */
	private function summationProblem(array $facts, string $total, array $parts): ?string {
		$reported = self::factValue(facts: $facts, concept: $total);
		if ($reported === null || is_numeric($reported) === false) {
			return null;
		}

		$sum = 0.0;
		foreach ($parts as $part) {
			$value = self::factValue(facts: $facts, concept: $part);
			if ($value !== null && is_numeric($value) === true) {
				$sum += (float) $value;
			}
		}

		if (abs((float) $reported - $sum) <= self::TOLERANCE) {
			return null;
		}

		return $this->l10n->t(
			'The total %1$s (%2$s) is not the sum of %3$s (%4$s).',
			[$total, $reported, implode(', ', $parts), self::number(value: $sum)]
		);
	}//end summationProblem()

	/**
	 * A parsed XBRL document, or null when the text is no XML.
	 *
	 * @param string $xml The instance text.
	 *
	 * @return DOMDocument|null The document.
	 */
	private static function document(string $xml): ?DOMDocument {
		if (trim($xml) === '') {
			return null;
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded   = $document->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ($loaded === false || $document->documentElement === null) {
			return null;
		}

		return $document;
	}//end document()

	/**
	 * Whether the document carries a non-empty xbrli element of this name.
	 *
	 * @param DOMDocument $document  The document.
	 * @param string      $localName The element name in the instance namespace.
	 *
	 * @return bool True when one has text.
	 */
	private static function hasText(DOMDocument $document, string $localName): bool {
		foreach ($document->getElementsByTagNameNS(self::XBRLI, $localName) as $element) {
			if (trim((string) $element->textContent) !== '') {
				return true;
			}
		}

		return false;
	}//end hasText()

	/**
	 * Whether a context names its period: an instant, or a start and an end date.
	 *
	 * @param DOMDocument $document The document.
	 *
	 * @return bool True when a period is named.
	 */
	private static function hasPeriod(DOMDocument $document): bool {
		return self::hasText(document: $document, localName: 'instant') === true
			|| (self::hasText(document: $document, localName: 'startDate') === true && self::hasText(document: $document, localName: 'endDate') === true);
	}//end hasPeriod()

	/**
	 * The facts of the document: every element outside the instance and link namespaces that carries a context.
	 *
	 * @param DOMDocument $document The document.
	 *
	 * @return array<string, string> The value by element name as written (prefix:local) and by local name.
	 */
	private static function facts(DOMDocument $document): array {
		$facts = [];
		foreach ($document->documentElement->childNodes as $node) {
			if ($node instanceof DOMElement === false || $node->hasAttribute('contextRef') === false) {
				continue;
			}

			$value = trim((string) $node->textContent);
			$facts[$node->tagName] ??= $value;
			$facts['*:' . $node->localName] ??= $value;
		}

		return $facts;
	}//end facts()

	/**
	 * The value of a concept's fact, or null when it has none.
	 *
	 * A concept written with a prefix (`jenv-bw2-i:Equity`) matches that
	 * element; one without (`Equity`) matches any prefix.
	 *
	 * @param array<string, string> $facts   The facts.
	 * @param string                $concept The concept.
	 *
	 * @return string|null The value.
	 */
	private static function factValue(array $facts, string $concept): ?string {
		$key   = $concept;
		if (str_contains($concept, ':') === false) {
			$key = '*:' . $concept;
		}

		$value = ($facts[$key] ?? null);
		if ($value === null || $value === '') {
			return null;
		}

		return $value;
	}//end factValue()

	/**
	 * A sum as text, without trailing zeros.
	 *
	 * @param float $value The sum.
	 *
	 * @return string The text.
	 */
	private static function number(float $value): string {
		return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
	}//end number()
}//end class
