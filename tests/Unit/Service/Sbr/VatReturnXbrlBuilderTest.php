<?php

/**
 * Unit tests for VatReturnXbrlBuilder: the XBRL instance of a VAT return.
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
use OCA\Shillinq\Service\Sbr\SbrRecords;
use OCA\Shillinq\Service\Sbr\VatReturnXbrlBuilder;
use OCA\Shillinq\Service\Sbr\XbrlInstanceCheck;
use OCA\Shillinq\Service\Sbr\XbrlInstanceWriter;
use OCA\Shillinq\Service\Sbr\XbrlTaxonomyLookup;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(VatReturnXbrlBuilder::class)]
#[UsesClass(XbrlInstanceWriter::class)]
#[UsesClass(XbrlInstanceCheck::class)]
final class VatReturnXbrlBuilderTest extends TestCase {

	private const RETURN = [
		'id' => 'ret-1',
		'returnNumber' => 'BTW-2026-Q3',
		'administrationId' => 'adm-1',
		'startDate' => '2026-07-01',
		'endDate' => '2026-09-30',
		'statusCode' => 'draft',
	];

	private const TAXONOMY = [
		'taxonomyVersion' => 'NT20',
		'entryPoints' => ['belastingdienst-ob'],
		'namespaces' => ['bd-i' => 'http://example.test/bd-data'],
		'schemaRefs' => ['belastingdienst-ob' => 'http://example.test/bd-rpt-ob-aangifte.xsd'],
		'conceptMap' => ['belastingdienst-ob' => ['5g.vat' => 'bd-i:Renamed', '9z.vat' => 'bd-i:Ignored', '1a.vat' => 'no-prefix']],
		'requiredConcepts' => ['belastingdienst-ob' => ['bd-i:ValueAddedTaxOwed', 'bd-i:ValueAddedTaxOnInput', 'bd-i:Renamed']],
	];

	private static function declarations(): array {
		return [
			['returnBox' => '1a', 'totalTaxableAmount' => 10000.40, 'totalVATAmount' => 2100.08],
			['returnBox' => '1a', 'totalTaxableAmount' => 99.30, 'totalVATAmount' => 20.85],
			['returnBox' => '1B', 'totalTaxableAmount' => 500, 'totalVATAmount' => 45],
			['returnBox' => '5b', 'totalTaxableAmount' => 0, 'totalVATAmount' => 812.49],
			['returnBox' => '', 'totalTaxableAmount' => 777, 'totalVATAmount' => 77],
		];
	}

	private static function facts(string $xml): array {
		$document = new DOMDocument();
		$document->loadXML($xml);
		$facts = [];
		foreach ($document->documentElement->childNodes as $node) {
			if ($node instanceof \DOMElement && $node->hasAttribute('contextRef')) {
				$facts[$node->tagName] = $node->textContent;
			}
		}

		return $facts;
	}

	private function builder(?SbrRecords $records = null, ?XbrlTaxonomyLookup $lookup = null): VatReturnXbrlBuilder {
		return new VatReturnXbrlBuilder(
			$records ?? $this->createMock(SbrRecords::class),
			$lookup ?? $this->createMock(XbrlTaxonomyLookup::class)
		);
	}

	public function testAmountsSumPerBoxInWholeEurosAndTheTotalsAddUp(): void {
		$amounts = VatReturnXbrlBuilder::amounts(self::declarations());

		$this->assertSame(10100, $amounts['1a.base']);
		$this->assertSame(2121, $amounts['1a.vat']);
		$this->assertSame(500, $amounts['1b.base']);
		$this->assertSame(45, $amounts['1b.vat']);
		$this->assertSame(0, $amounts['4b.vat']);
		$this->assertSame(2166, $amounts['5a.vat']);
		$this->assertSame(812, $amounts['5b.vat']);
		$this->assertSame(1354, $amounts['5g.vat']);
		$this->assertSame(array_keys(VatReturnXbrlBuilder::CONCEPTS), array_keys($amounts));
	}

	public function testTheInstanceCarriesTheReturnsFactsOnTheObEntryPoint(): void {
		$instance = $this->builder()->instance(self::RETURN, self::declarations(), ['vatNumber' => 'nl 0012.34567 b01'], self::TAXONOMY);

		$this->assertSame('belastingdienst-ob', $instance['entryPoint']);
		$this->assertSame('vat-return', $instance['filingType']);
		$this->assertSame('ret-1', $instance['sourceReturnId']);
		$this->assertSame('OB-BTW-2026-Q3', $instance['instanceNumber']);
		$this->assertSame('NT20', $instance['taxonomyVersion']);
		$this->assertSame('draft', $instance['state']);
		$this->assertSame('digipoort-sbr', $instance['digipoortSourceSlug']);
		$this->assertSame(hash('sha256', $instance['instanceXml']), $instance['instanceHash']);

		$facts = self::facts($instance['instanceXml']);
		$this->assertSame('10100', $facts['bd-i:TurnoverSuppliesServicesGeneralTariff']);
		$this->assertSame('2121', $facts['bd-i:ValueAddedTaxSuppliesServicesGeneralTariff']);
		$this->assertSame('1354', $facts['bd-i:Renamed']);
		$this->assertArrayNotHasKey('bd-i:ValueAddedTaxOwedToBePaidBack', $facts);
		$this->assertArrayNotHasKey('bd-i:Ignored', $facts);

		$xpath = new DOMXPath((function (string $xml): DOMDocument { $d = new DOMDocument(); $d->loadXML($xml); return $d; })($instance['instanceXml']));
		$xpath->registerNamespace('xbrli', XbrlInstanceWriter::XBRLI);
		$xpath->registerNamespace('link', 'http://www.xbrl.org/2003/linkbase');
		$xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');
		$this->assertSame('NL001234567B01', $xpath->evaluate('string(//xbrli:context[@id="d"]//xbrli:identifier)'));
		$this->assertSame('www.belastingdienst.nl/omzetbelastingnummer', $xpath->evaluate('string(//xbrli:identifier/@scheme)'));
		$this->assertSame('2026-07-01', $xpath->evaluate('string(//xbrli:context[@id="d"]//xbrli:startDate)'));
		$this->assertSame('2026-09-30', $xpath->evaluate('string(//xbrli:context[@id="d"]//xbrli:endDate)'));
		$this->assertSame('http://example.test/bd-rpt-ob-aangifte.xsd', $xpath->evaluate('string(//link:schemaRef/@xlink:href)'));
		$this->assertSame('http://example.test/bd-data', $xpath->document->documentElement->lookupNamespaceURI('bd-i'));
	}

	public function testTheBuiltInstancePassesTheValidationCheck(): void {
		$instance = $this->builder()->instance(self::RETURN, self::declarations(), ['vatNumber' => 'NL001234567B01'], self::TAXONOMY);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));

		$this->assertSame([], (new XbrlInstanceCheck($l10n))->problems($instance, self::TAXONOMY));
	}

	public function testWithoutATaxonomyTheDefaultsAreWrittenAndNoSchemaIsReferred(): void {
		$instance = $this->builder()->instance(self::RETURN, [], [], null);

		$this->assertSame('', $instance['taxonomyVersion']);
		$this->assertStringNotContainsString('schemaRef', $instance['instanceXml']);
		$this->assertStringContainsString('xmlns:bd-i="' . VatReturnXbrlBuilder::DEFAULT_NAMESPACES['bd-i'] . '"', $instance['instanceXml']);
		$this->assertSame('0', self::facts($instance['instanceXml'])['bd-i:ValueAddedTaxOwedToBePaidBack']);
	}

	public function testAReturnWithoutAPeriodIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The VAT return has no period; it cannot be filed.');

		$this->builder()->instance(['id' => 'ret-1', 'startDate' => '2026-07-01'], [], [], null);
	}

	public function testBuildSavesTheInstanceAndLinksTheReturnToIt(): void {
		$records = $this->createMock(SbrRecords::class);
		$records->method('find')->willReturnCallback(static fn (string $schema, string $id, ?string $fallback = null): ?array => match ($schema) {
			'Administration' => ($id === 'adm-1' && $fallback === 'administrationCode') ? ['vatNumber' => 'NL001234567B01'] : null,
			default => null,
		});
		$records->expects($this->once())->method('findAll')->with('VATDeclaration', ['returnId' => 'ret-1'])->willReturn(self::declarations());
		$saved = [];
		$records->expects($this->exactly(2))->method('save')->willReturnCallback(static function (string $schema, array $record) use (&$saved): array {
			$saved[] = [$schema, $record];
			return $record + ['id' => 'xbrl-1'];
		});
		$lookup = $this->createMock(XbrlTaxonomyLookup::class);
		$lookup->expects($this->once())->method('effectiveFor')->with('belastingdienst-ob', '2026-07-01', '2026-09-30')->willReturn(self::TAXONOMY);

		$instance = $this->builder($records, $lookup)->build(self::RETURN);

		$this->assertSame('xbrl-1', $instance['id']);
		$this->assertSame('XbrlInstance', $saved[0][0]);
		$this->assertStringContainsString('NL001234567B01', $saved[0][1]['instanceXml']);
		$this->assertSame('BtwAangifte', $saved[1][0]);
		$this->assertSame('xbrl-1', $saved[1][1]['xbrlInstanceId']);
		$this->assertSame('ret-1', $saved[1][1]['id']);
	}

	public function testADraftInstanceIsRebuiltInPlace(): void {
		$records = $this->createMock(SbrRecords::class);
		$records->method('find')->willReturnCallback(static fn (string $schema): ?array => $schema === 'XbrlInstance' ? ['id' => 'xbrl-1', 'state' => 'draft', 'mappingId' => 'm-1'] : null);
		$records->method('findAll')->willReturn([]);
		$records->expects($this->once())->method('save')->with('XbrlInstance', $this->callback(static fn (array $r): bool => $r['id'] === 'xbrl-1' && $r['mappingId'] === 'm-1' && $r['state'] === 'draft'))->willReturnArgument(1);

		$this->assertSame('xbrl-1', $this->builder($records)->build(self::RETURN + ['xbrlInstanceId' => 'xbrl-1'])['id']);
	}

	public function testAnInstanceThatLeftDraftIsNotRewritten(): void {
		$records = $this->createMock(SbrRecords::class);
		$records->method('find')->with('XbrlInstance', 'xbrl-1')->willReturn(['id' => 'xbrl-1', 'state' => 'validated']);
		$records->expects($this->never())->method('save');
		$records->expects($this->never())->method('findAll');

		$this->assertSame('validated', $this->builder($records)->build(self::RETURN + ['xbrlInstanceId' => 'xbrl-1'])['state']);
	}

	public function testAReturnWithoutAnIdIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The VAT return has no id; save it before filing it.');

		$this->builder()->build(['startDate' => '2026-07-01']);
	}
	public function testTheReturnsOwnTransitionLinksTheReturnItself(): void {
		$records = $this->createMock(SbrRecords::class);
		$records->method('find')->willReturn(null);
		$records->method('findAll')->willReturn([]);
		$records->expects($this->once())->method('save')->with('XbrlInstance')->willReturnCallback(static fn (string $s, array $r): array => $r + ['id' => 'xbrl-3']);

		$this->assertSame('xbrl-3', $this->builder($records)->instanceFor(self::RETURN)['id']);
	}
}
