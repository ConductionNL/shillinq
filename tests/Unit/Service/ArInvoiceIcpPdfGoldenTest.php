<?php

/**
 * Golden test for the ICP invoice overlay document.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ArInvoiceIcpPdfRenderer;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Pins the rendered document and ICP metadata byte for byte.
 *
 * Regenerate (only for an intended change) with
 * `ICP_PDF_GOLDEN_WRITE=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/ArInvoiceIcpPdfGoldenTest.php`.
 */
final class ArInvoiceIcpPdfGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/ar-invoice-icp-pdf-golden.json';

	/**
	 * The documents for a domestic, an ICP goods and a triangulation invoice.
	 *
	 * @return array<string,mixed>
	 */
	private function documents(): array {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $params = []): string => '[' . $s . ']');
		$l10n->method('getLanguageCode')->willReturn('nl');
		$renderer = new ArInvoiceIcpPdfRenderer(l10n: $l10n);

		$seller = ['vatNumber' => 'NL001234567B01', 'name' => 'Verkoper & Zn'];
		return [
			'domestic' => $renderer->render(
				['invoiceNumber' => 'VF-1', 'invoiceDate' => '2026-03-01', 'dueDate' => '2026-03-31', 'totalAmount' => 1234567.891, 'customerId' => 'cm-1'],
				[],
				[]
			),
			'icp-goods' => $renderer->render(
				[
					'invoiceNumber' => 'VF-<2>', 'invoiceDate' => '2026-03-02', 'totalAmount' => 99.5,
					'icpContext' => ['treatAsIcp' => true, 'supplyType' => 'L', 'viesValidationId' => 'VIES-9'],
				],
				['name' => 'Käufer "GmbH"', 'vatNumber' => ' DE123456789 '],
				$seller
			),
			'triangulation' => $renderer->render(
				[
					'invoiceNumber' => 'VF-3', 'totalAmount' => -10,
					'icpContext' => ['treatAsIcp' => true, 'supplyType' => 'T', 'triangulation' => true, 'cPartyVatId' => 'FR99999'],
				],
				['name' => 'B-party', 'vatId' => 'BE0123'],
				['vatId' => 'NL009', 'legalName' => 'Legal BV']
			),
			'services-unknown' => $renderer->render(
				['invoiceNumber' => 'VF-4', 'icpContext' => ['treatAsIcp' => false, 'supplyType' => 'S', 'triangulation' => true]],
				['name' => 'C'],
				$seller
			),
		];

	}//end documents()

	/**
	 * The documents match the golden byte for byte.
	 *
	 * @return void
	 */
	public function testDocumentsMatchTheGolden(): void {
		$actual = json_encode($this->documents(), JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE) . "\n";
		if (getenv('ICP_PDF_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, $actual);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $actual);

	}//end testDocumentsMatchTheGolden()
}//end class
