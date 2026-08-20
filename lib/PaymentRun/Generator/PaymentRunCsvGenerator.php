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
		$currency = strtoupper(trim((string)($paymentRun['currency'] ?? 'EUR')));
		if ($currency === '') {
			$currency = 'EUR';
		}

		$lines = ($paymentRun['paymentLines'] ?? []);
		if (is_array($lines) === false) {
			$lines = [];
		}

		$handle = fopen('php://temp', 'r+');

		// Explicit empty escape ('') — PHP 8.4 deprecates the default escape arg.
		fputcsv($handle, self::COLUMNS, ',', '"', '');

		foreach (array_values($lines) as $line) {
			if (is_array($line) === false) {
				continue;
			}

			fputcsv(
				$handle,
				[
					(string)($line['payeeName'] ?? ''),
					(string)($line['creditorIban'] ?? ''),
					number_format((float)($line['amount'] ?? 0), 2, '.', ''),
					$currency,
					(string)($line['remittanceInfo'] ?? ''),
					(string)($line['apTransactionRef'] ?? ''),
				],
				',',
				'"',
				''
			);
		}

		rewind($handle);
		$content = stream_get_contents($handle);
		fclose($handle);

		if (is_string($content) === false) {
			$content = '';
		}

		$stem = 'payment-run';
		if ($runNumber !== '') {
			$stem = $runNumber;
		}

		return new RenderedFile(
			fileName: $stem . '.csv',
			mimeType: 'text/csv',
			format: self::format(),
			content: $content,
		);

	}//end render()
}//end class
