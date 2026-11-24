<?php

/**
 * Payment-run CSV fallback generator
 *
 * Renders an approved PaymentRun into a flat CSV fallback (REQ-SEPA-003) for
 * banks / operators that ingest CSV rather than pain.001 XML. One header row
 * plus one data row per payment line, columns: payeeName, creditorIban, amount,
 * currency, remittanceInfo, apTransactionRef. Rendered natively via fputcsv
 * over a php://temp stream — no new composer dependency.
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun\Generator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentRun\Generator;

use OCA\Shillinq\PaymentRun\RenderedFile;

/**
 * Native fputcsv CSV-fallback generator for a PaymentRun.
 */
final class PaymentRunCsvGenerator implements PaymentRunGeneratorInterface {

	/**
	 * The CSV header row, in column order (REQ-SEPA-003).
	 *
	 * @var array<int, string>
	 */
	private const COLUMNS = [
		'payeeName',
		'creditorIban',
		'amount',
		'currency',
		'remittanceInfo',
		'apTransactionRef',
	];

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public static function format(): string {
		return 'csv';
	}//end format()

	/**
	 * Render the approved PaymentRun into a CSV fallback file.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 *
	 * @return RenderedFile
	 */
	public function render(array $paymentRun): RenderedFile {
		$runNumber = (string)($paymentRun['runNumber'] ?? '');
		$stem = 'payment-run';
		if ($runNumber !== '') {
			$stem = $runNumber;
		}

		return new RenderedFile(
			fileName: $stem . '.csv',
			mimeType: 'text/csv',
			format: self::format(),
			content: $this->csv(rows: $this->rows(paymentRun: $paymentRun)),
		);

	}//end render()

	/**
	 * The header and one row per payment line (non-array lines skipped).
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 *
	 * @return list<list<string>>
	 */
	private function rows(array $paymentRun): array {
		$currency = strtoupper(trim((string)($paymentRun['currency'] ?? 'EUR')));
		if ($currency === '') {
			$currency = 'EUR';
		}

		$lines = ($paymentRun['paymentLines'] ?? []);
		if (is_array($lines) === false) {
			$lines = [];
		}

		$rows = [self::COLUMNS];
		foreach (array_values(array_filter($lines, 'is_array')) as $line) {
			$rows[] = $this->row(line: $line, currency: $currency);
		}

		return $rows;

	}//end rows()

	/**
	 * One payment line as a CSV row.
	 *
	 * @param array<string, mixed> $line     The payment line.
	 * @param string               $currency The run currency.
	 *
	 * @return list<string>
	 */
	private function row(array $line, string $currency): array {
		return [
			(string)($line['payeeName'] ?? ''),
			(string)($line['creditorIban'] ?? ''),
			number_format((float)($line['amount'] ?? 0), 2, '.', ''),
			$currency,
			(string)($line['remittanceInfo'] ?? ''),
			(string)($line['apTransactionRef'] ?? ''),
		];

	}//end row()

	/**
	 * The rows written as CSV (comma, double quote, no escape character).
	 *
	 * @param list<list<string>> $rows The rows.
	 *
	 * @return string
	 */
	private function csv(array $rows): string {
		$handle = fopen('php://temp', 'r+');
		foreach ($rows as $row) {
			// Explicit empty escape ('') — PHP 8.4 deprecates the default escape arg.
			fputcsv($handle, $row, ',', '"', '');
		}

		rewind($handle);
		$content = stream_get_contents($handle);
		fclose($handle);

		if (is_string($content) === false) {
			return '';
		}

		return $content;

	}//end csv()
}//end class
