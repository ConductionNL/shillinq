<?php

/**
 * Unit tests for AnnualAccountsXbrlBuilder: the KvK XBRL instance of a set of annual accounts.
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
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sbr;

use DOMDocument;
use DOMXPath;
use OCA\Shillinq\Service\Sbr\AnnualAccountsXbrlBuilder;
use OCA\Shillinq\Service\Sbr\SbrRecords;
use OCA\Shillinq\Service\Sbr\XbrlInstanceCheck;
use OCA\Shillinq\Service\Sbr\XbrlInstanceWriter;
use OCA\Shillinq\Service\Sbr\XbrlTaxonomyLookup;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AnnualAccountsXbrlBuilder::class)]
#[UsesClass(XbrlInstanceWriter::class)]
#[UsesClass(XbrlInstanceCheck::class)]
final class AnnualAccountsXbrlBuilderTest extends TestCase {

	/**
	 * The seed statement of design.md: Voorbeeld Bouw B.V., 2025, small.
	 */
	private const STATEMENT = [
		'id' => 'fs-2025',
		'reportingPeriodStart' => '2025-01-01',
		'reportingPeriodEnd' => '2025-12-31',
		'sizeClass' => 'small',
		'balanceSheet' => ['fixedAssets' => 420000.40, 'currentAssets' => 180000.30, 'equity' => 250000, 'provisions' => 50000.49, 'liabilities' => 300000.21],
		'profitAndLoss' => ['netTurnover' => 1200000, 'operatingExpenses' => 1100000, 'financialResult' => -5000],
	];

	private const TAXONOMY = [
		'taxonomyVersion' => 'NT20',
		'entryPoints' => ['kvk-jaarrekening'],
		'schemaRefs' => ['kvk-jaarrekening' => 'http://example.test/kvk.xsd', 'kvk-jaarrekening:small' => 'http://example.test/kvk-klein.xsd'],
		'namespaces' => ['jenv-bw2-i' => 'http://example.test/jenv'],
		'conceptMap' => ['kvk-jaarrekening' => ['result' => 'jenv-bw2-i:NetIncome']],
		'requiredConcepts' => ['kvk-jaarrekening' => ['jenv-bw2-i:Equity', 'jenv-bw2-i:Assets']],
		'summations' => [
			['entryPoint' => 'kvk-jaarrekening', 'total' => 'jenv-bw2-i:Assets', 'parts' => ['jenv-bw2-i:AssetsNoncurrent', 'jenv-bw2-i:AssetsCurrent']],
			['entryPoint' => 'kvk-jaarrekening', 'total' => 'jenv-bw2-i:EquityAndLiabilities', 'parts' => ['jenv-bw2-i:Equity', 'jenv-bw2-i:Provisions', 'jenv-bw2-i:Liabilities']],
		],
	];

	private function builder(?SbrRecords $records = null, ?XbrlTaxonomyLookup $lookup = null): AnnualAccountsXbrlBuilder {
		return new AnnualAccountsXbrlBuilder(
			$records ?? $this->createMock(SbrRecords::class),
			$lookup ?? $this->createMock(XbrlTaxonomyLookup::class)
		);
	}

	private static function xpath(string $xml): DOMXPath {
		$document = new DOMDocument();
		$document->loadXML($xml);
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('xbrli', XbrlInstanceWriter::XBRLI);
		$xpath->registerNamespace('j', 'http://example.test/jenv');
		$xpath->registerNamespace('link', 'http://www.xbrl.org/2003/linkbase');
		$xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');
		return $xpath;
	}

	public function testAmountsRoundEachLineAndSumTheTotalsFromTheRoundedParts(): void {
		$amounts = AnnualAccountsXbrlBuilder::amounts(self::STATEMENT);

		$this->assertSame(420000, $amounts['fixedAssets']);
		$this->assertSame(600000, $amounts['assets']);
		$this->assertSame(600000, $amounts['equityAndLiabilities']);
		$this->assertSame(95000, $amounts['result']);
		$this->assertSame(['fixedAssets', 'currentAssets', 'assets', 'equity', 'provisions', 'liabilities', 'equityAndLiabilities', 'netTurnover', 'operatingExpenses', 'financialResult', 'result'], array_keys($amounts));
		$this->assertSame(81000, AnnualAccountsXbrlBuilder::amounts(['profitAndLoss' => ['netTurnover' => 1, 'result' => 81000.2]])['result']);
		$this->assertSame(0, AnnualAccountsXbrlBuilder::amounts(['balanceSheet' => 'none'])['assets']);
	}

	public function testTheInstanceReportsTheBalanceAtYearEndAndTheResultOverTheYear(): void {
		$instance = $this->builder()->instance(self::STATEMENT, ['id' => 'adm-1', 'kvkNumber' => '1234 5678'], self::TAXONOMY);

		$this->assertSame('kvk-jaarrekening', $instance['entryPoint']);
		$this->assertSame('kvk-deponering', $instance['filingType']);
		$this->assertSame('fs-2025', $instance['sourceStatementId']);
		$this->assertSame('adm-1', $instance['administrationId']);
		$this->assertSame('KVK-2025-small', $instance['instanceNumber']);
		$this->assertSame('NT20', $instance['taxonomyVersion']);
		$this->assertSame('draft', $instance['state']);
		$this->assertSame(hash('sha256', $instance['instanceXml']), $instance['instanceHash']);

		$xpath = self::xpath($instance['instanceXml']);
		$this->assertSame('i', $xpath->evaluate('string(//j:Equity/@contextRef)'));
		$this->assertSame('250000', $xpath->evaluate('string(//j:Equity)'));
		$this->assertSame('d', $xpath->evaluate('string(//j:NetRevenue/@contextRef)'));
		$this->assertSame('95000', $xpath->evaluate('string(//j:NetIncome)'));
		$this->assertSame(0.0, $xpath->evaluate('count(//j:ResultAfterTax)'));
		$this->assertSame('12345678', $xpath->evaluate('string(//xbrli:identifier)'));
		$this->assertSame('http://www.kvk.nl/kvk-id/', $xpath->evaluate('string(//xbrli:identifier/@scheme)'));
		$this->assertSame('2025-12-31', $xpath->evaluate('string(//xbrli:context[@id="i"]//xbrli:instant)'));
		$this->assertSame('http://example.test/kvk-klein.xsd', $xpath->evaluate('string(//link:schemaRef/@xlink:href)'));
	}

	public function testTheBuiltInstancePassesValidationAndWithoutEquityItIsRefused(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));
		$check = new XbrlInstanceCheck($l10n);
		$builder = $this->builder();

		$this->assertSame([], $check->problems($builder->instance(self::STATEMENT, ['kvkNumber' => '12345678'], self::TAXONOMY), self::TAXONOMY));

		$renamed = self::TAXONOMY;
		$renamed['conceptMap']['kvk-jaarrekening']['equity'] = 'jenv-bw2-i:EquityElsewhere';
		$this->assertContains(
			'The required concept jenv-bw2-i:Equity is missing.',
			$check->problems($builder->instance(self::STATEMENT, ['kvkNumber' => '12345678'], $renamed), $renamed)
		);
	}

	public function testAnActiveMappingRenamesALineAndAnInactiveOneDoesNot(): void {
		$mappings = [
			['sourceAccountId' => 'equity', 'targetXBRLConcept' => 'jenv-bw2-i:EquityMapped', 'status' => 'active'],
			['sourceAccountId' => 'provisions', 'targetXBRLConcept' => 'jenv-bw2-i:ProvisionsMapped', 'status' => 'retired'],
			['sourceAccountId' => '1c45852d', 'targetXBRLConcept' => 'jenv-bw2-i:NotALine', 'status' => 'active'],
		];
		$xpath = self::xpath($this->builder()->instance(self::STATEMENT, [], self::TAXONOMY, $mappings)['instanceXml']);

		$this->assertSame('250000', $xpath->evaluate('string(//j:EquityMapped)'));
		$this->assertSame(0.0, $xpath->evaluate('count(//j:ProvisionsMapped)'));
		$this->assertSame('50000', $xpath->evaluate('string(//j:Provisions)'));
	}

	public function testWithoutATaxonomyTheDefaultsAreWritten(): void {
		$instance = $this->builder()->instance(['sizeClass' => 'micro'] + self::STATEMENT, [], null);

		$this->assertSame('', $instance['taxonomyVersion']);
		$this->assertStringNotContainsString('schemaRef', $instance['instanceXml']);
		$this->assertStringContainsString('xmlns:jenv-bw2-i="' . AnnualAccountsXbrlBuilder::DEFAULT_NAMESPACES['jenv-bw2-i'] . '"', $instance['instanceXml']);
		$this->assertStringContainsString('<jenv-bw2-i:ResultAfterTax contextRef="d"', $instance['instanceXml']);
	}

	public function testAStatementWithoutAPeriodIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The financial statement has no reporting period; it cannot be filed.');

		$this->builder()->instance(['reportingPeriodEnd' => '2025-12-31'], [], null);
	}

	public function testBuildSavesADraftForTheStatementWithTheAdministrationsMappings(): void {
		$records = $this->createMock(SbrRecords::class);
		$records->expects($this->once())->method('find')->with('Administration', 'adm-1', 'administrationCode')->willReturn(['kvkNumber' => '12345678']);
		$records->expects($this->exactly(2))->method('findAll')->willReturnCallback(static fn (string $schema, array $filters): array => match ($schema) {
			'XbrlInstance' => $filters === ['sourceStatementId' => 'fs-2025'] ? [['id' => 'vat', 'filingType' => 'vat-return', 'state' => 'accepted']] : [],
			'XBRLMapping' => $filters === ['administrationId' => 'adm-1', 'taxonomyVersion' => 'NT20'] ? [['sourceAccountId' => 'equity', 'targetXBRLConcept' => 'jenv-bw2-i:EquityMapped']] : [],
		});
		$records->expects($this->once())->method('save')->with('XbrlInstance', $this->callback(static fn (array $r): bool => isset($r['id']) === false && str_contains($r['instanceXml'], 'EquityMapped') && $r['administrationId'] === 'adm-1'))->willReturnCallback(static fn (string $s, array $r): array => $r + ['id' => 'xbrl-9']);
		$lookup = $this->createMock(XbrlTaxonomyLookup::class);
		$lookup->expects($this->once())->method('effectiveFor')->with('kvk-jaarrekening', '2025-01-01', '2025-12-31')->willReturn(self::TAXONOMY);

		$this->assertSame('xbrl-9', $this->builder($records, $lookup)->build(self::STATEMENT, 'adm-1')['id']);
	}

	public function testADraftIsRebuiltInPlaceAndAFiledOneIsLeftAlone(): void {
		$records = $this->createMock(SbrRecords::class);
		$records->method('findAll')->willReturnOnConsecutiveCalls(
			[['id' => 'xbrl-1', 'filingType' => 'kvk-deponering', 'state' => 'draft', 'mappingId' => 'm']],
			[['id' => 'xbrl-2', 'filingType' => 'kvk-deponering', 'state' => 'submitted']]
		);
		$records->expects($this->once())->method('save')->with('XbrlInstance', $this->callback(static fn (array $r): bool => $r['id'] === 'xbrl-1' && $r['mappingId'] === 'm'))->willReturnArgument(1);
		$builder = $this->builder($records);

		$this->assertSame('xbrl-1', $builder->build(self::STATEMENT, 'adm-1')['id']);
		$this->assertSame('submitted', $builder->build(self::STATEMENT, 'adm-1')['state']);
	}

	public function testAStatementWithoutAnIdIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The financial statement has no id; save it before filing it.');

		$this->builder()->build(['reportingPeriodEnd' => '2025-12-31'], 'adm-1');
	}
}
