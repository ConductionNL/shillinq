<?php

/**
 * Golden test for the SEPA pain.001 document.
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

use OCA\Shillinq\PaymentRun\Generator\SepaPain001Generator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the rendered documents byte for byte (creation timestamps masked).
 *
 * Regenerate (only for an intended change) with
 * `SEPA_GOLDEN_WRITE=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/PaymentRun/SepaPain001GoldenTest.php`.
 */
final class SepaPain001GoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/sepa-pain001-golden.json';

	/**
	 * The rendered files, with the wall-clock timestamps masked.
	 *
	 * @return array<string,mixed>
	 */
	private static function documents(): array {
		$generator = new SepaPain001Generator();
		$runs = [
			'multi-date' => [
				'runNumber' => 'PR-2026-007', 'currency' => ' usd ', 'executionDate' => '2026-10-01', 'debtorAccountIban' => 'NL91ABNA0417164300',
				'initiatorName' => '  ', 'administrationName' => 'Bakkerij & Zn',
				'paymentLines' => [
					['amount' => '100.5', 'payeeName' => 'A <B>', 'creditorIban' => 'NL20INGB0001234567', 'remittanceInfo' => 'VF-1', 'creditorBic' => ' INGBNL2A '],
					['amount' => 20, 'payeeName' => 'C', 'creditorIban' => 'DE89370400440532013000', 'requestedExecutionDate' => '2026-10-05'],
					['amount' => 0.333, 'payeeName' => 'D', 'creditorIban' => 'NL02ABNA0123456789', 'requestedExecutionDate' => ' '],
				],
			],
			'minimal' => ['paymentLines' => 'not-a-list', 'administrationId' => 'adm-1', 'totalAmount' => '12.345', 'executionDate' => '2026-01-02'],
			'no-name' => ['runNumber' => '', 'currency' => '', 'executionDate' => '2026-02-03', 'paymentLines' => [['amount' => 5]]],
		];

		$out = [];
		foreach ($runs as $name => $run) {
			$file = $generator->render($run);
			$out[$name] = [
				'fileName' => $file->fileName,
				'mimeType' => $file->mimeType,
				'format' => $file->format,
				'content' => preg_replace(['/<CreDtTm>[^<]*</', '/-\d{14}</'], ['<CreDtTm>TS<', '-TS<'], $file->content),
			];
		}

		return $out;

	}//end documents()

	/**
	 * The documents match the golden byte for byte.
	 *
	 * @return void
	 */
	public function testDocumentsMatchTheGolden(): void {
		$actual = json_encode(self::documents(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('SEPA_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, $actual);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $actual);

	}//end testDocumentsMatchTheGolden()
}//end class
