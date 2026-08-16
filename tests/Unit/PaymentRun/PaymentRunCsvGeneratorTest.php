<?php

/**
 * Unit tests for PaymentRunCsvGenerator.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentRun
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
 * @spec openspec/changes/payment-run-sepa-export/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentRun;

use OCA\Shillinq\PaymentRun\Generator\PaymentRunCsvGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests REQ-SEPA-003 — the CSV fallback (header + one row per line).
 */
class PaymentRunCsvGeneratorTest extends TestCase {
	/**
	 * The seeded approved PR-2026-001 fixture (SAFE placeholders).
	 *
	 * @return array<string, mixed>
	 */
	private function paymentRun(): array {
		return [
			'runNumber' => 'PR-2026-001',
			'currency' => 'EUR',
			'paymentLines' => [
				[
					'payeeName' => 'Eneco Energie B.V.',
					'creditorIban' => 'NL00BANK0123456789',
					'amount' => 892.50,
					'remittanceInfo' => 'ENECO-2026-04-0001',
					'apTransactionRef' => 'ap-txn-eneco-2026-04-0001',
				],
				[
					'payeeName' => 'Jan de Vries (ZZP)',
					'creditorIban' => 'NL00TEST0222222222',
					'amount' => 605.00,
					'remittanceInfo' => 'JDV-2026-06-0003',
					'apTransactionRef' => 'ap-txn-jdv-2026-06-0003',
				],
			],
		];
	}//end paymentRun()

	/**
	 * Parse the rendered CSV into rows.
	 *
	 * @param string $csv The rendered CSV content.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function rows(string $csv): array {
		$rows = [];
		$lines = preg_split('/\r\n|\n|\r/', trim($csv));
		foreach ($lines as $line) {
			if ($line === '') {
				continue;
			}

			$rows[] = str_getcsv($line, ',', '"', '');
		}

		return $rows;
	}//end rows()

	/**
	 * HAPPY: header + one data row per line, with matching amount + IBAN.
	 *
	 * @return void
	 */
	public function testHeaderAndOneRowPerLine(): void {
		$generator = new PaymentRunCsvGenerator();
		$rendered = $generator->render($this->paymentRun());

		$this->assertSame('csv', $rendered->format);
		$this->assertSame('PR-2026-001.csv', $rendered->fileName);

		$rows = $this->rows($rendered->content);

		// 1 header + 2 data rows.
		$this->assertCount(3, $rows);
		$this->assertSame(
			['payeeName', 'creditorIban', 'amount', 'currency', 'remittanceInfo', 'apTransactionRef'],
			$rows[0]
		);

		// Row 1 mirrors line 1.
		$this->assertSame('Eneco Energie B.V.', $rows[1][0]);
		$this->assertSame('NL00BANK0123456789', $rows[1][1]);
		$this->assertSame('892.50', $rows[1][2]);
		$this->assertSame('EUR', $rows[1][3]);

		// Row 2 mirrors line 2.
		$this->assertSame('NL00TEST0222222222', $rows[2][1]);
		$this->assertSame('605.00', $rows[2][2]);
	}//end testHeaderAndOneRowPerLine()

	/**
	 * EDGE: an empty paymentLines array yields a header-only CSV.
	 *
	 * @return void
	 */
	public function testEmptyRunYieldsHeaderOnly(): void {
		$generator = new PaymentRunCsvGenerator();
		$rendered = $generator->render(['runNumber' => 'PR-EMPTY', 'paymentLines' => []]);

		$rows = $this->rows($rendered->content);
		$this->assertCount(1, $rows);
		$this->assertSame('payeeName', $rows[0][0]);
	}//end testEmptyRunYieldsHeaderOnly()

	/**
	 * ERROR: a non-array paymentLines value degrades to a header-only CSV
	 * rather than crashing.
	 *
	 * @return void
	 */
	public function testNonArrayLinesDegradeGracefully(): void {
		$generator = new PaymentRunCsvGenerator();
		$rendered = $generator->render(['runNumber' => 'PR-BAD', 'paymentLines' => 'not-an-array']);

		$rows = $this->rows($rendered->content);
		$this->assertCount(1, $rows);
	}//end testNonArrayLinesDegradeGracefully()
}//end class
