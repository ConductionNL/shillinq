<?php

/**
 * SiSa Appendix
 *
 * Fills the SiSa appendix (Single information Single audit) of an
 * administration's specific grants for one year. A specific grant is a
 * Subsidie received (direction incoming) that carries the regulation code of
 * the specific grant (sisaRegulationCode, for example D8). For each one:
 *
 * - spending and income are summed from the posted ledger: every
 *   profit-and-loss line tagged with the grant (GLLine.subsidieId) on a
 *   transaction posted in the year. Debit lines are spending, credit lines
 *   income;
 * - output indicators are what was entered as SisaIndicator records of kind
 *   output, kept as entered; one without a value is marked missing.
 *
 * Nothing is written: the appendix is read from the books every time it is
 * asked for. Sending it to BZK stays manual (the bzk-sisa connection is not
 * available), so the export is a CSV in the template's column order
 * (REQ-SISA-012, REQ-SISA-013).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-sisa-reporting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PublicSector;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\SisaAppendixInterface;
use OCA\Shillinq\Service\LedgerActuals;
use OCA\Shillinq\Support\RegisterSlug;
use OCP\IAppConfig;

/**
 * The SiSa appendix of one year.
 *
 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-sisa-reporting/spec.md
 */
class SisaAppendix implements SisaAppendixInterface {

	/**
	 * The export's columns, in the order of the BZK appendix template: the
	 * regulation's code and name, the recipient's grant, the indicator's code
	 * and description, its value and unit.
	 */
	public const CSV_COLUMNS = [
		'regulationCode'   => 'Code',
		'schemeName'       => 'Specifieke uitkering',
		'subsidyNumber'    => 'Beschikking',
		'indicatorCode'    => 'Indicator',
		'description'      => 'Omschrijving',
		'value'            => 'Waarde',
		'unit'             => 'Eenheid',
	];

	/**
	 * The indicator code of the computed spending and income, when the grant
	 * has no entered indicator of that kind naming the regulation's own code.
	 */
	private const COMPUTED = [
		'spending' => 'Besteding (jaar T)',
		'income'   => 'Baten (jaar T)',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     App config for the register slug.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param LedgerActuals          $ledger        Posting months of transactions.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LedgerActuals $ledger,
	) {
	}//end __construct()

	/**
	 * The SiSa appendix rows of an administration's specific grants for a year.
	 *
	 * @param string $administrationId The administration.
	 * @param string $fiscalYear       The year, for example "2026".
	 *
	 * @return list<array<string,mixed>> One row per indicator.
	 *
	 * @throws InvalidArgumentException When the administration or the year is missing.
	 *
	 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-sisa-reporting/spec.md#requirement-indicators-are-computed-per-specific-grant-req-sisa-012
	 */
	public function forYear(string $administrationId, string $fiscalYear): array {
		if ($administrationId === '' || preg_match('/^\d{4}$/', $fiscalYear) !== 1) {
			throw new InvalidArgumentException('A SiSa appendix needs an administration and a year such as 2026.');
		}

		$grants = array_values(
			array_filter(
				$this->rows(schema: 'Subsidie', filters: ['administrationId' => $administrationId, 'direction' => 'incoming']),
				static fn (array $grant): bool => trim((string)($grant['sisaRegulationCode'] ?? '')) !== ''
			)
		);
		if ($grants === []) {
			return [];
		}

		$inYear = $this->transactionsInYear(administrationId: $administrationId, fiscalYear: $fiscalYear);
		$rows = [];
		foreach ($grants as $grant) {
			array_push($rows, ...$this->grantRows(grant: $grant, fiscalYear: (int)$fiscalYear, inYear: $inYear));
		}

		return $rows;

	}//end forYear()

	/**
	 * The appendix as CSV, one row per indicator, in the column order of the BZK template.
	 *
	 * @param list<array<string,mixed>> $rows The rows forYear() returned.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-sisa-reporting/spec.md#requirement-the-appendix-is-exported-for-the-annual-accounts-req-sisa-013
	 */
	public function toCsv(array $rows): string {
		$lines = [self::csvLine(cells: array_values(self::CSV_COLUMNS))];
		foreach ($rows as $row) {
			$cells = [];
			foreach (array_keys(self::CSV_COLUMNS) as $key) {
				$cells[] = self::cell(key: $key, value: ($row[$key] ?? null));
			}

			$lines[] = self::csvLine(cells: $cells);
		}

		return implode("\r\n", $lines) . "\r\n";

	}//end toCsv()

	/**
	 * The rows of one grant: computed spending and income, then its output indicators.
	 *
	 * @param array<string,mixed> $grant      The specific grant.
	 * @param int                 $fiscalYear The year.
	 * @param array<string,true>  $inYear     References of the transactions posted in the year.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function grantRows(array $grant, int $fiscalYear, array $inYear): array {
		$grantId = (string)($grant['id'] ?? '');
		$totals = ['spending' => 0, 'income' => 0];
		foreach ($this->rows(schema: 'GLLine', filters: ['subsidieId' => $grantId]) as $line) {
			if (isset($inYear[(string)($line['transactionId'] ?? '')]) === false || (string)($line['accountClass'] ?? 'pnl') === 'balance') {
				continue;
			}

			$kind = 'spending';
			if ((string)($line['side'] ?? 'debit') === 'credit') {
				$kind = 'income';
			}

			$totals[$kind] += (int)round(((float)($line['amount'] ?? 0)) * 100);
		}

		$entered = $this->rows(schema: 'SisaIndicator', filters: ['grantId' => $grantId, 'fiscalYear' => $fiscalYear]);
		$rows = [];
		foreach ($totals as $kind => $cents) {
			$code = self::COMPUTED[$kind];
			$description = self::COMPUTED[$kind];
			foreach ($entered as $indicator) {
				if ((string)($indicator['kind'] ?? '') === $kind) {
					$code = (string)($indicator['indicatorCode'] ?? $code);
					$description = (string)($indicator['description'] ?? $description);
				}
			}

			$rows[] = self::row(
				grant: $grant,
				indicator: ['indicatorCode' => $code, 'description' => $description, 'unit' => 'EUR'],
				kind: $kind,
				value: round($cents / 100, 2),
				source: 'books'
			);
		}

		foreach ($entered as $indicator) {
			if ((string)($indicator['kind'] ?? '') !== 'output') {
				continue;
			}

			$value = null;
			if (is_numeric($indicator['value'] ?? null) === true) {
				$value = (float)$indicator['value'];
			}

			$rows[] = self::row(grant: $grant, indicator: $indicator, kind: 'output', value: $value, source: 'entered');
		}

		return $rows;

	}//end grantRows()

	/**
	 * One appendix row.
	 *
	 * @param array<string,mixed> $grant     The grant.
	 * @param array<string,mixed> $indicator The indicator's code, description and unit.
	 * @param string              $kind      spending, income or output.
	 * @param float|null          $value     The value, null when it is missing.
	 * @param string              $source    books or entered.
	 *
	 * @return array<string,mixed>
	 */
	private static function row(array $grant, array $indicator, string $kind, ?float $value, string $source): array {
		return [
			'grantId'        => (string)($grant['id'] ?? ''),
			'regulationCode' => trim((string)$grant['sisaRegulationCode']),
			'schemeName'     => (string)($grant['schemeName'] ?? ''),
			'subsidyNumber'  => (string)($grant['subsidyNumber'] ?? ''),
			'indicatorCode'  => (string)($indicator['indicatorCode'] ?? ''),
			'description'    => (string)($indicator['description'] ?? ''),
			'kind'           => $kind,
			'value'          => $value,
			'unit'           => (string)($indicator['unit'] ?? ''),
			'source'         => $source,
			'missing'        => ($value === null),
		];

	}//end row()

	/**
	 * References of the administration's transactions posted in the year.
	 *
	 * @param string $administrationId The administration.
	 * @param string $fiscalYear       The year.
	 *
	 * @return array<string,true>
	 */
	private function transactionsInYear(string $administrationId, string $fiscalYear): array {
		$posted = $this->rows(schema: 'GLTransaction', filters: ['administrationId' => $administrationId, 'state' => 'posted']);
		$inYear = [];
		foreach ($this->ledger->postingMonths(transactions: $posted) as $ref => $month) {
			if (str_starts_with($month, $fiscalYear . '-') === true) {
				$inYear[(string)$ref] = true;
			}
		}

		return $inYear;

	}//end transactionsInYear()

	/**
	 * One CSV cell: a missing value is empty, an amount a plain decimal with a point.
	 *
	 * @param string $key   The column.
	 * @param mixed  $value The value.
	 *
	 * @return string
	 */
	private static function cell(string $key, mixed $value): string {
		if ($key === 'value' && is_float($value) === true) {
			return number_format($value, 2, '.', '');
		}

		return (string)($value ?? '');

	}//end cell()

	/**
	 * One CSV line, every cell quoted and separated by a semicolon. A cell a
	 * spreadsheet would read as a formula is prefixed with an apostrophe.
	 *
	 * @param list<string> $cells The cells.
	 *
	 * @return string
	 */
	private static function csvLine(array $cells): string {
		$quoted = [];
		foreach ($cells as $cell) {
			if (preg_match('/^[=+@\t\r]/', $cell) === 1) {
				$cell = "'" . $cell;
			}

			$quoted[] = '"' . str_replace('"', '""', $cell) . '"';
		}

		return implode(';', $quoted);

	}//end csvLine()

	/**
	 * Rows of a schema as arrays.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Property filters.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$found = $this->objectService->setRegister(RegisterSlug::of(config: $this->appConfig))->setSchema($schema)->findAll(['filters' => $filters]);
		$rows = [];
		foreach ($found as $row) {
			if (is_array($row) === false) {
				$row = $row->jsonSerialize();
			}

			$rows[] = $row;
		}

		return $rows;

	}//end rows()
}//end class
