<?php

/**
 * Shared data-access + formatting helpers for native report generators
 *
 * Every native (data) report generator pulls its rows from the same OpenRegister
 * register ('shillinq') via the ObjectService resolved lazily from the container,
 * and shares the same period/administration scoping, row normalisation and
 * money/CSV/XML formatting. This trait factors that out so each generator only
 * carries its report-specific shaping logic. The host class MUST expose
 * `$this->container` (ContainerInterface) and `$this->logger` (LoggerInterface).
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Generator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude The reporting capability has no canonical spec. This tag pointed at
 *       openspec/changes/reporting-compliance-consolidation (a change directory that
 *       exists neither under changes nor under changes/archive), and no canonical
 *       reporting capability exists under openspec/specs either. Tracked in #525.
 *       Deliberately NOT resolved by writing that spec — authoring the requirement
 *       a tag is checked against turns the gate green over an unspecified capability.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. No existing
 * target is honest either — bookkeeping-iv3-reporting REQ-IV3-004 and
 * bookkeeping-vat-btw-filing REQ-VBTW-004 forbid the PHP renderers in this
 * directory, so pointing there would report conformance to a rule this code
 * breaks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

/**
 * Common ObjectService access and value formatting for native generators.
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
trait ReportDataTrait {
	/**
	 * Load all objects of a schema (capped) as plain arrays, applying filters.
	 *
	 * Reads are scoped to the 'shillinq' register. A schema with no rows returns an
	 * empty array (callers emit a well-formed empty file rather than fatal).
	 *
	 * @param string $schema The OpenRegister schema name.
	 * @param array<string,mixed> $filters Optional findAll filters.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function loadAll(string $schema, array $filters = []): array {
		try {
			$rows = $this->container->get('OCA\OpenRegister\Service\ObjectService')
				->setRegister('shillinq')
				->setSchema($schema)
				->findAll(array_merge(['limit' => 10000], $filters));
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq report generator: could not load ' . $schema . ': ' . $e->getMessage());
			return [];
		}

		return $this->normaliseRows($rows);
	}//end loadAll()

	/**
	 * Normalise a list of ObjectService rows (entities or arrays) to plain arrays.
	 *
	 * @param mixed $rows Raw rows from findAll().
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function normaliseRows(mixed $rows): array {
		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$out[] = (array)$row->jsonSerialize();
			}
		}

		return $out;
	}//end normaliseRows()

	/**
	 * Index a list of Account rows by their accountNumber.
	 *
	 * @param array<int, array<string,mixed>> $accounts Account rows.
	 *
	 * @return array<string, array<string,mixed>>
	 */
	private function indexAccountsByNumber(array $accounts): array {
		$indexed = [];
		foreach ($accounts as $account) {
			$number = (string)($account['accountNumber'] ?? $account['code'] ?? '');
			if ($number !== '') {
				$indexed[$number] = $account;
			}
		}

		return $indexed;
	}//end indexAccountsByNumber()

	/**
	 * Build a GLLine filter set from the report context (administration + period).
	 *
	 * @param array<string,mixed> $context Report context.
	 *
	 * @return array<string,mixed>
	 */
	private function lineFilters(array $context): array {
		$filters = [];
		$administrationId = $this->contextString($context, 'administrationId');
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$period = $this->contextString($context, 'period');
		if ($period !== '') {
			$filters['periodId'] = $period;
		}

		if ($filters === []) {
			return [];
		}

		return ['filters' => $filters];
	}//end lineFilters()

	/**
	 * Build an administration-only filter set from the report context.
	 *
	 * @param array<string,mixed> $context Report context.
	 *
	 * @return array<string,mixed>
	 */
	private function administrationFilter(array $context): array {
		$administrationId = $this->contextString($context, 'administrationId');
		if ($administrationId === '') {
			return [];
		}

		return ['filters' => ['administrationId' => $administrationId]];
	}//end administrationFilter()

	/**
	 * Read a context value as a trimmed string ('' when absent).
	 *
	 * @param array<string,mixed> $context Report context.
	 * @param string $key Context key.
	 *
	 * @return string
	 */
	private function contextString(array $context, string $key): string {
		$value = ($context[$key] ?? null);
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_int($value) === true) {
			return (string)$value;
		}

		return '';
	}//end contextString()

	/**
	 * Coerce a stored amount (string|int|float) to float.
	 *
	 * @param mixed $value The raw amount.
	 *
	 * @return float
	 */
	private function toFloat(mixed $value): float {
		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_string($value) === true && is_numeric($value) === true) {
			return (float)$value;
		}

		return 0.0;
	}//end toFloat()

	/**
	 * Format a money amount with two decimals and a dot separator (no thousands).
	 *
	 * @param float $amount The amount.
	 *
	 * @return string
	 */
	private function money(float $amount): string {
		return number_format($amount, 2, '.', '');
	}//end money()

	/**
	 * Build a deterministic file name for a generated report.
	 *
	 * @param string $reportType Report-type id.
	 * @param array<string,mixed> $context Report context.
	 * @param string $extension File extension without dot.
	 *
	 * @return string
	 */
	private function fileName(string $reportType, array $context, string $extension): string {
		$period = $this->contextString($context, 'period');
		$slug = $reportType;
		if ($period !== '') {
			$slug .= '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $period);
		} else {
			$slug .= '-' . gmdate('Y-m-d');
		}

		return $slug . '.' . $extension;
	}//end fileName()
}//end trait
