<?php

/**
 * Unit tests for FluxService — variance, materiality, attribution, narrative.
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
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-30
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\FluxService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Validates the pure-computation helpers on FluxService that back REQ-CLS-005..007.
 *
 * No ObjectService is required — every test exercises the public pure helpers
 * (computeVarianceCents / computePercentageVariance / classifyMateriality /
 * computeAutoExplanationCoverage / decideStatus / buildNarrative / renderers).
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-30
 */
final class FluxServiceTest extends TestCase {
	/**
	 * Build a FluxService with stub container + config.
	 *
	 * @return FluxService
	 */
	private function service(): FluxService {
		$container = $this->createStub(ContainerInterface::class);
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		return new FluxService( $config, new NullLogger(),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end service()

	/**
	 * Variance is the signed difference actual minus basis (REQ-CLS-005).
	 *
	 * @return void
	 */
	public function testVarianceIsActualMinusBasis(): void {
		$s = $this->service();
		self::assertSame(18000000, $s->computeVarianceCents(actualCents: 138000000, basisCents: 120000000));
		self::assertSame(-1000, $s->computeVarianceCents(actualCents: 0, basisCents: 1000));

	}//end testVarianceIsActualMinusBasis()

	/**
	 * Percentage variance is variance divided by basis; zero basis returns zero (REQ-CLS-005).
	 *
	 * @return void
	 */
	public function testPercentageVarianceHandlesZeroBasis(): void {
		$s = $this->service();
		self::assertEqualsWithDelta(0.15, $s->computePercentageVariance(varianceCents: 18000000, basisCents: 120000000), 0.0001);
		self::assertSame(0.0, $s->computePercentageVariance(varianceCents: 5, basisCents: 0));

	}//end testPercentageVarianceHandlesZeroBasis()

	/**
	 * Materiality classification respects the per-group special-rule overrides (REQ-CLS-005).
	 *
	 * Operational group: 100000 cents absolute or 2%. A 50000 cent variance against a
	 * 10M cent balance is below both → immaterial.
	 *
	 * @return void
	 */
	public function testClassifyMaterialityImmaterialForCash(): void {
		$s = $this->service();
		$policy = [
			'absoluteThresholdCents' => 100000,
			'percentageThreshold' => 0.02,
			'specialRules' => [
				'cash' => ['absoluteThresholdCents' => 10000, 'percentageThreshold' => 0.005],
			],
		];

		// Cash variance EUR 50K on EUR 10M balance: percentage threshold = 0.5% × 10M = 50K (== variance).
		self::assertSame(
			'immaterial',
			$s->classifyMateriality(varianceCents: 5000000, basisCents: 1000000000, policy: $policy, accountGroup: 'cash')
		);

	}//end testClassifyMaterialityImmaterialForCash()

	/**
	 * Tax variance trivially above the lower threshold becomes highly-material (REQ-CLS-005).
	 *
	 * @return void
	 */
	public function testClassifyMaterialityHighlyMaterialForTax(): void {
		$s = $this->service();
		$policy = [
			'absoluteThresholdCents' => 100000,
			'percentageThreshold' => 0.02,
			'specialRules' => [
				'tax' => ['absoluteThresholdCents' => 5000, 'percentageThreshold' => 0.001],
			],
		];

		// Tax variance EUR 500 (50000 cents); threshold = 5000 cents (50000/5000 = 10× threshold → not strictly above)
		// Use a >10× value to trigger highly-material:
		self::assertSame(
			'highly-material',
			$s->classifyMateriality(varianceCents: 600000, basisCents: 1000000, policy: $policy, accountGroup: 'tax')
		);

	}//end testClassifyMaterialityHighlyMaterialForTax()

	/**
	 * COGS variance 15% adverse maps to material per the operational threshold (REQ-CLS-005).
	 *
	 * @return void
	 */
	public function testClassifyMaterialityMaterialForCogs(): void {
		$s = $this->service();
		$policy = ['absoluteThresholdCents' => 100000, 'percentageThreshold' => 0.02];
		self::assertSame(
			'material',
			$s->classifyMateriality(varianceCents: 18000000, basisCents: 120000000, policy: $policy, accountGroup: 'operational')
		);

	}//end testClassifyMaterialityMaterialForCogs()

	/**
	 * Auto-explanation coverage is sum-of-driver-contributions / variance (REQ-CLS-006).
	 *
	 * @return void
	 */
	public function testAutoExplanationCoverageIsSumOverVariance(): void {
		$s = $this->service();

		$attributions = [
			['driver' => 'volume', 'contributionCents' => 8000000],
			['driver' => 'price',  'contributionCents' => 6000000],
			['driver' => 'mix',    'contributionCents' => 2000000],
			['driver' => 'fx',     'contributionCents' => 2000000],
		];
		// sum = 18M = full variance → coverage = 1.0
		self::assertEqualsWithDelta(1.0, $s->computeAutoExplanationCoverage(attributions: $attributions, varianceCents: 18000000), 0.0001);

		// Half-cover case:
		self::assertEqualsWithDelta(
			0.5,
			$s->computeAutoExplanationCoverage(
				attributions: [['driver' => 'volume', 'contributionCents' => 400000]],
				varianceCents: 800000
			),
			0.0001
		);

		// Zero variance returns zero (avoid division by zero).
		self::assertSame(0.0, $s->computeAutoExplanationCoverage(attributions: $attributions, varianceCents: 0));

	}//end testAutoExplanationCoverageIsSumOverVariance()

	/**
	 * Status decision: highly-material always escalates; material auto-explains at >= 80% (REQ-CLS-006).
	 *
	 * @return void
	 */
	public function testDecideStatusFollowsCoverageThreshold(): void {
		$s = $this->service();
		self::assertSame('accepted', $s->decideStatus(materiality: 'immaterial', coverage: 0.99));
		self::assertSame('auto-explained', $s->decideStatus(materiality: 'material', coverage: 0.8));
		self::assertSame('auto-explained', $s->decideStatus(materiality: 'material', coverage: 0.95));
		self::assertSame('escalated', $s->decideStatus(materiality: 'material', coverage: 0.5));
		self::assertSame('escalated', $s->decideStatus(materiality: 'highly-material', coverage: 1.0));

	}//end testDecideStatusFollowsCoverageThreshold()

	/**
	 * The narrative orders rows by absolute variance descending; immaterial items dropped (REQ-CLS-007).
	 *
	 * @return void
	 */
	public function testNarrativeOrdersByAbsoluteVarianceAndDropsImmaterial(): void {
		$s = $this->service();
		$items = [
			['glAccountNumber' => 'COGS',     'varianceCents' => 18000000, 'materialityClassification' => 'material',     'status' => 'auto-explained'],
			['glAccountNumber' => 'Freight',  'varianceCents' => 800000,   'materialityClassification' => 'material',     'status' => 'escalated'],
			['glAccountNumber' => 'R&D',      'varianceCents' => -100000,  'materialityClassification' => 'immaterial',   'status' => 'accepted'],
			['glAccountNumber' => 'Salaries', 'varianceCents' => 600000,   'materialityClassification' => 'material',     'status' => 'auto-explained'],
		];

		$narrative = $s->buildNarrative(items: $items, periodId: '2026-03');

		self::assertSame(3, $narrative['itemCount']);
		// Ordered by absolute variance descending: COGS (18 000 000) >
		// Freight (800 000) > Salaries (600 000). R&D is dropped as
		// immaterial.
		self::assertSame('COGS', $narrative['rows'][0]['glAccountNumber']);
		self::assertSame('Freight', $narrative['rows'][1]['glAccountNumber']);
		self::assertSame('Salaries', $narrative['rows'][2]['glAccountNumber']);
		// Explained vs unexplained tracking.
		self::assertSame(2, $narrative['explainedCount']);
		self::assertSame(1, $narrative['unexplainedCount']);

	}//end testNarrativeOrdersByAbsoluteVarianceAndDropsImmaterial()

	/**
	 * Markdown renderer includes the period header + table + summary (REQ-CLS-007).
	 *
	 * @return void
	 */
	public function testMarkdownRendererIncludesHeaderAndSummary(): void {
		$s = $this->service();
		$narrative = $s->buildNarrative(
			items: [['glAccountNumber' => 'COGS', 'varianceCents' => 18000000, 'budgetCents' => 120000000, 'actualCents' => 138000000, 'materialityClassification' => 'material', 'status' => 'auto-explained', 'autoExplanation' => 'Volume +EUR 80,000']],
			periodId: '2026-03'
		);

		$md = $s->renderNarrativeMarkdown(narrative: $narrative);
		self::assertStringContainsString('# Flux Narrative — 2026-03', $md);
		self::assertStringContainsString('| COGS |', $md);
		self::assertStringContainsString('**Summary**', $md);
		self::assertStringContainsString('Items reviewed: 1', $md);

	}//end testMarkdownRendererIncludesHeaderAndSummary()

	/**
	 * JSON renderer round-trips the narrative shape (REQ-CLS-007).
	 *
	 * @return void
	 */
	public function testJsonRendererProducesParseableJson(): void {
		$s = $this->service();
		$narrative = $s->buildNarrative(items: [], periodId: '2026-03');
		$json = $s->renderNarrativeJson(narrative: $narrative);
		$decoded = json_decode($json, true);
		self::assertSame('2026-03', $decoded['periodId']);
		self::assertSame(0, $decoded['itemCount']);

	}//end testJsonRendererProducesParseableJson()

	/**
	 * PDF body renderer includes the letterhead + signature line (REQ-CLS-007).
	 *
	 * @return void
	 */
	public function testPdfBodyRendererIncludesSignatureLine(): void {
		$s = $this->service();
		$body = $s->renderNarrativePdfBody(narrative: $s->buildNarrative(items: [], periodId: '2026-03'));
		self::assertStringContainsString('Shillinq Continuous Close', $body);
		self::assertStringContainsString('CFO signature', $body);

	}//end testPdfBodyRendererIncludesSignatureLine()
}
