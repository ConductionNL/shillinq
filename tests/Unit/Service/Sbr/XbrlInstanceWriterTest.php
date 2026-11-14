<?php

/**
 * Unit tests for XbrlInstanceWriter: the XBRL document itself.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sbr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sbr;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use OCA\Shillinq\Service\Sbr\XbrlInstanceWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(XbrlInstanceWriter::class)]
final class XbrlInstanceWriterTest extends TestCase {

	private const ENTITY = ['scheme' => 'http://www.kvk.nl/kvk-id/', 'identifier' => '12345678'];

	public function testItWritesTheContextsUnitAndWholeEuroFacts(): void {
		$xml = XbrlInstanceWriter::write(self::ENTITY, '2025-01-01', '2025-12-31', ['j' => 'urn:j'], [
			['concept' => 'j:Equity', 'value' => 1234.5, 'context' => XbrlInstanceWriter::INSTANT],
			['concept' => 'j:Revenue', 'value' => -10.4, 'context' => XbrlInstanceWriter::DURATION],
		], ' http://example.test/ep.xsd ');
		$document = new DOMDocument();
		$this->assertTrue($document->loadXML($xml));
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('xbrli', XbrlInstanceWriter::XBRLI);
		$xpath->registerNamespace('j', 'urn:j');
		$xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');

		$this->assertSame('1235', $xpath->evaluate('string(//j:Equity)'));
		$this->assertSame('-10', $xpath->evaluate('string(//j:Revenue)'));
		$this->assertSame('INF', $xpath->evaluate('string(//j:Equity/@decimals)'));
		$this->assertSame('EUR', $xpath->evaluate('string(//j:Revenue/@unitRef)'));
		$this->assertSame('iso4217:EUR', $xpath->evaluate('string(//xbrli:unit[@id="EUR"]/xbrli:measure)'));
		$this->assertSame('2025-12-31', $xpath->evaluate('string(//xbrli:context[@id="i"]//xbrli:instant)'));
		$this->assertSame('2025-01-01', $xpath->evaluate('string(//xbrli:context[@id="d"]//xbrli:startDate)'));
		$this->assertSame(2.0, $xpath->evaluate('count(//xbrli:identifier[@scheme="http://www.kvk.nl/kvk-id/"][.="12345678"])'));
		$this->assertSame('http://example.test/ep.xsd', $xpath->evaluate('string(//*[local-name()="schemaRef"]/@xlink:href)'));
	}

	public function testWithoutASchemaNoReferenceIsWritten(): void {
		$this->assertStringNotContainsString('schemaRef', XbrlInstanceWriter::write(self::ENTITY, '2025-01-01', '2025-12-31', [], [], ' '));
	}

	public function testAConceptWithoutANamespaceIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The concept x:Equity has no namespace.');

		XbrlInstanceWriter::write(self::ENTITY, '2025-01-01', '2025-12-31', ['j' => 'urn:j'], [['concept' => 'x:Equity', 'value' => 1, 'context' => 'i']]);
	}
}
