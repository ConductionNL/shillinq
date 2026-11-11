<?php

/**
 * Golden test for the XAF customerSupplier element writer.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
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

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\Generator\XafAuditfileGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use XMLWriter;

/**
 * Pins the exact XML written per customer/supplier row.
 *
 * Regenerate with XAF_CUSTOMER_SUPPLIER_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class XafCustomerSupplierGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/xaf-customer-supplier.golden.json';

	public function testElementsMatchGolden(): void {
		$rows = [
			'full' => ['C', 'c-1', ['name' => 'Ada & Co', 'kvkNumber' => '123', 'vatNumber' => 'NL1B01', 'email' => 'a@x.test', 'phone' => '010']],
			'legalNameVatId' => ['S', 's-1', ['legalName' => 'Legal BV', 'vatID' => 'NL2B02', 'telephone' => '020']],
			'tradingNameVatIdLower' => ['C', 'c-2', ['tradingName' => 'Trade', 'vatId' => 'NL3B03', 'email' => 'e@x.test']],
			'tradeNameOnly' => ['S', 's-2', ['tradeName' => 'TN']],
			'bare' => ['C', 'c-3', []],
			'noId' => ['C', '', ['name' => 'Ignored']],
		];

		$method = new ReflectionMethod(XafAuditfileGenerator::class, 'writeCustomerSupplier');
		$generator = (new \ReflectionClass(XafAuditfileGenerator::class))->newInstanceWithoutConstructor();
		$actual = [];
		foreach ($rows as $name => [$type, $id, $row]) {
			$writer = new XMLWriter();
			$writer->openMemory();
			$writer->setIndent(true);
			$writer->startElement('root');
			$method->invoke($generator, $writer, $type, $id, $row);
			$writer->endElement();
			$actual[$name] = $writer->outputMemory();
		}

		if (getenv('XAF_CUSTOMER_SUPPLIER_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}
}
