<?php

/**
 * Unit tests for the bookkeeping-ifrs15-revenue register fragment.
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
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/specs/bookkeeping-ifrs15-revenue/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the IFRS 15 fragment is valid JSON, declares the eleven five-step
 * schemas with their lifecycle / materialisation / aggregation metadata
 * (ADR-037 / ADR-031), merges additively onto the monolith without disturbing
 * existing schemas, marks the derived schemas read-only (REQ-IFRS15-007/008), and
 * ships seed objects that obey the worked examples from design.md.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class Ifrs15RevenueFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-ifrs15-revenue.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * The eleven IFRS 15 schema slugs (REQ-IFRS15-001).
	 *
	 * @var array<int,string>
	 */
	private array $expectedSchemas = [
		'RevenueContract',
		'PerformanceObligation',
		'TransactionPrice',
		'PriceAllocation',
		'RevenueRecognitionEvent',
		'ContractAsset',
		'ContractLiability',
		'ContractModification',
		'VariableConsiderationAdjustment',
		'ContractCostAsset',
		'RevenueWaterfall',
	];

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Load the fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

	/**
	 * The fragment is present and valid JSON with the expected sections.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares all eleven five-step schemas (REQ-IFRS15-001).
	 *
	 * @return void
	 */
	public function testDeclaresAllElevenSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach ($this->expectedSchemas as $slug) {
			self::assertArrayHasKey($slug, $schemas, "IFRS 15 fragment must declare $slug");
			self::assertSame($slug, $schemas[$slug]['slug']);
		}

	}//end testDeclaresAllElevenSchemas()

	/**
	 * The derived balance/waterfall schemas are read-only (REQ-IFRS15-007, REQ-IFRS15-008).
	 *
	 * @return void
	 */
	public function testDerivedSchemasAreReadOnly(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['ContractAsset', 'ContractLiability', 'RevenueWaterfall'] as $slug) {
			self::assertTrue($schemas[$slug]['readonly'], "$slug must be read-only (derived nightly)");
			self::assertTrue($schemas[$slug]['x-openregister']['readonly']);
		}

	}//end testDerivedSchemasAreReadOnly()

	/**
	 * RevenueContract declares an x-openregister-lifecycle state machine (REQ-IFRS15-001).
	 *
	 * @return void
	 */
	public function testContractDeclaresLifecycle(): void {
		$contract = $this->fragment()['components']['schemas']['RevenueContract'];
		self::assertArrayHasKey('x-openregister-lifecycle', $contract);
		$transitions = $contract['x-openregister-lifecycle']['transitions'];
		foreach (['sign', 'beginDelivery', 'complete', 'cancel'] as $transition) {
			self::assertArrayHasKey($transition, $transitions);
		}

	}//end testContractDeclaresLifecycle()

	/**
	 * RevenueRecognitionEvent declares the GL materialisation (REQ-IFRS15-007).
	 *
	 * @return void
	 */
	public function testRecognitionEventDeclaresMaterialisation(): void {
		$event = $this->fragment()['components']['schemas']['RevenueRecognitionEvent'];
		self::assertArrayHasKey('x-openregister-materialisations', $event);
		$posting = $event['x-openregister-materialisations']['recognitionGlPosting'];
		self::assertSame('GLTransaction', $posting['target']);
		// The posting MUST be balanced: one debit, one credit, both for the amount.
		self::assertCount(2, $posting['lines']);
		$sides = array_column($posting['lines'], 'side');
		self::assertContains('debit', $sides);
		self::assertContains('credit', $sides);

	}//end testRecognitionEventDeclaresMaterialisation()

	/**
	 * RevenueWaterfall declares the per-contract aggregation (ADR-031, REQ-IFRS15-008).
	 *
	 * @return void
	 */
	public function testWaterfallDeclaresAggregation(): void {
		$waterfall = $this->fragment()['components']['schemas']['RevenueWaterfall'];
		self::assertArrayHasKey('x-openregister-aggregations', $waterfall);
		self::assertArrayHasKey('revenueWaterfallByContractPeriod', $waterfall['x-openregister-aggregations']);

	}//end testWaterfallDeclaresAggregation()

	/**
	 * TransactionPrice declares the five IFRS 15.46-72 components (REQ-IFRS15-002).
	 *
	 * @return void
	 */
	public function testTransactionPriceDeclaresComponents(): void {
		$price = $this->fragment()['components']['schemas']['TransactionPrice']['properties'];
		foreach ([
			'fixedConsideration',
			'variableConsideration',
			'constraintAmount',
			'significantFinancingComponent',
			'nonCashConsideration',
			'considerationPayableToCustomer',
		] as $field) {
			self::assertArrayHasKey($field, $price, "TransactionPrice must declare $field");
		}

	}//end testTransactionPriceDeclaresComponents()

	/**
	 * Merging the fragment adds the IFRS 15 schemas without dropping monolith schemas (ADR-037).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		foreach ($this->expectedSchemas as $slug) {
			self::assertArrayHasKey($slug, $schemas);
		}

		// The pre-existing monolith schemas survive the merge (sample a few).
		self::assertArrayHasKey('GLTransaction', $schemas);
		self::assertArrayHasKey('GLLine', $schemas);

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas and the shillinq register (ADR-037).
	 *
	 * @return void
	 */
	public function testSeedObjectsTargetDeclaredSchemas(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertArrayHasKey($object['@self']['schema'], $schemas);
		}

	}//end testSeedObjectsTargetDeclaredSchemas()

	/**
	 * Relative-SSP seed allocations tie back to the total transaction price (REQ-IFRS15-004).
	 *
	 * The C-2026-001 contract allocates EUR 360K across three POs; the seed
	 * PriceAllocation rows must sum to 360K in cents.
	 *
	 * @return void
	 */
	public function testSeedAllocationsTieBackToTotalPrice(): void {
		$objects = $this->fragment()['components']['objects'];
		$sumCents = 0;
		foreach ($objects as $object) {
			if ($object['@self']['schema'] === 'PriceAllocation' && $object['contractId'] === 'C-2026-001') {
				$sumCents += (int)round(((float)$object['allocatedAmount']) * 100);
			}
		}

		self::assertSame(36000000, $sumCents, 'C-2026-001 price allocations must tie back to EUR 360K');

	}//end testSeedAllocationsTieBackToTotalPrice()

	/**
	 * At least four worked-example contracts are seeded (design.md examples 1-4).
	 *
	 * @return void
	 */
	public function testFourWorkedExampleContractsSeeded(): void {
		$objects = $this->fragment()['components']['objects'];
		$contracts = array_filter(
			$objects,
			static fn (array $o): bool => $o['@self']['schema'] === 'RevenueContract'
		);

		self::assertGreaterThanOrEqual(4, count($contracts), 'Expect >= 4 worked-example contracts');

	}//end testFourWorkedExampleContractsSeeded()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
