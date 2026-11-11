<?php

/**
 * Golden test for the CSV fallback payment-run file.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentRun;

use OCA\Shillinq\PaymentRun\Generator\PaymentRunCsvGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the rendered CSV files exactly.
 */
final class PaymentRunCsvGoldenTest extends TestCase {

	/**
	 * A run with quoting, a skipped non-array line and a padded currency; and an empty run.
	 *
	 * @return void
	 */
	public function testTheFilesAreExact(): void {
		$generator = new PaymentRunCsvGenerator();

		$file = $generator->render(
			[
				'runNumber' => 'PR-9',
				'currency' => ' usd ',
				'paymentLines' => [
					['payeeName' => 'Bakker, "De" Korenaar', 'creditorIban' => 'NL20INGB0001234567', 'amount' => '12.345', 'remittanceInfo' => "VF\n1", 'apTransactionRef' => 'ap-1'],
					'skip-me',
					['amount' => 3],
				],
			]
		);
		self::assertSame(['PR-9.csv', 'text/csv', 'csv'], [$file->fileName, $file->mimeType, $file->format]);
		self::assertSame(
			"payeeName,creditorIban,amount,currency,remittanceInfo,apTransactionRef\n"
			. "\"Bakker, \"\"De\"\" Korenaar\",NL20INGB0001234567,12.35,USD,\"VF\n1\",ap-1\n"
			. ",,3.00,USD,,\n",
			$file->content
		);

		$empty = $generator->render(['currency' => '', 'paymentLines' => 'none']);
		self::assertSame(['payment-run.csv', "payeeName,creditorIban,amount,currency,remittanceInfo,apTransactionRef\n"], [$empty->fileName, $empty->content]);

	}//end testTheFilesAreExact()
}//end class
