<?php

/**
 * Unit tests for FluxService::run() and its persistence path.
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

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\FluxService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers run(), persistRun(), the owner/SLA escalation and narrative summary helpers.
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-30
 */
#[CoversClass(FluxService::class)]
final class FluxServiceRunTest extends TestCase {

	/**
	 * Captured saveObject calls as [schema, register, object].
	 *
	 * @var array<int,array{schema:string,register:string,object:array<string,mixed>}>
	 */
	private array $saves = [];

	/**
	 * Build a FluxService whose ObjectService records every save.
	 *
	 * @param string               $registerSlug Value the app config returns for 'register'.
	 * @param LoggerInterface|null $logger       Optional logger mock.
	 * @param bool                 $failSave     Whether saveObject should throw.
	 *
	 * @return FluxService
	 */
	private function service(string $registerSlug = 'books', ?LoggerInterface $logger = null, bool $failSave = false): FluxService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')
			->with('shillinq', 'register', 'shillinq')
			->willReturn($registerSlug);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null) use ($failSave): ObjectEntityInterface {
				if ($failSave === true) {
					throw new RuntimeException('store down');
				}

				$this->saves[] = ['schema' => (string)$schema, 'register' => (string)$register, 'object' => $object];
				return $this->createStub(ObjectEntityInterface::class);
			}
		);

		return new FluxService(
			appConfig: $config,
			logger: ($logger ?? $this->createStub(LoggerInterface::class)),
			objectService: $objectService,
		);
	}//end service()

	/**
	 * Standard run inputs covering immaterial, auto-explained and escalated accounts.
	 *
	 * @return array<string,mixed>
	 */
	private function inputs(): array {
		return [
			'administrationId' => 'adm1',
			'periodId' => '2026-03',
			'scope' => 'entity',
			'comparisonBasis' => 'prior-year',
			'runTimestamp' => new DateTimeImmutable('2026-03-31T10:20:30+00:00'),
			'materialityPolicy' => ['absoluteThresholdCents' => 10000, 'percentageThreshold' => 0.01],
			'accounts' => [
				[
					'glAccountNumber' => '4000',
					'accountGroupCode' => 'operational',
					'actualCents' => 100500,
					'basisCents' => 100000,
				],
				[
					'glAccountNumber' => '7000',
					'accountGroupCode' => 'revenue',
					'actualCents' => 150000,
					'basisCents' => 100000,
					'attributions' => [
						['driver' => 'volume', 'contributionCents' => 30000],
						['driver' => 'price', 'contributionCents' => 15000],
						['driver' => 'mix', 'contributionCents' => 0],
						['driver' => '', 'contributionCents' => 999],
					],
				],
				[
					'glAccountNumber' => '8000',
					'accountGroupCode' => '',
					'actualCents' => 50000,
					'basisCents' => 100000,
					'attributions' => [
						['driver' => 'fx', 'contributionCents' => -10000],
					],
				],
			],
		];
	}//end inputs()

	/**
	 * run() returns the aggregated summary and per-item classification (REQ-CLS-005/006).
	 *
	 * @return void
	 */
	public function testRunClassifiesAndSummarisesAccounts(): void {
		$result = $this->service()->run(inputs: $this->inputs());

		self::assertSame('flux-adm1-2026-03-102030', $result['fluxRunId']);
		self::assertSame(3, $result['itemCount']);
		self::assertSame(2, $result['materialCount']);
		self::assertSame(1, $result['autoExplainedCount']);
		self::assertSame(1, $result['escalatedCount']);
		self::assertSame(100500, $result['totalVarianceCents']);

		[$immaterial, $auto, $escalated] = $result['items'];

		self::assertSame('immaterial', $immaterial['materialityClassification']);
		self::assertSame('accepted', $immaterial['status']);
		self::assertNull($immaterial['ownerEscalationSLA']);
		self::assertSame('', $immaterial['ownerEscalatedTo']);
		self::assertSame('', $immaterial['autoExplanation']);

		self::assertSame('material', $auto['materialityClassification']);
		self::assertSame('auto-explained', $auto['status']);
		self::assertSame(50000, $auto['varianceCents']);
		self::assertEqualsWithDelta(0.5, $auto['percentageVariance'], 0.0001);
		self::assertEqualsWithDelta(0.91998, $auto['autoExplanationCoverage'], 0.000001);
		self::assertSame('volume EUR +300, price EUR +150', $auto['autoExplanation']);
		self::assertNull($auto['ownerEscalationSLA']);

		self::assertSame('escalated', $escalated['status']);
		self::assertSame(-50000, $escalated['varianceCents']);
		self::assertSame('2026-04-01T10:20:30+00:00', $escalated['ownerEscalationSLA']);
		self::assertSame('controller', $escalated['ownerEscalatedTo']);
		self::assertSame('fx EUR -100', $escalated['autoExplanation']);
		self::assertSame(100000, $escalated['budgetCents']);

	}//end testRunClassifiesAndSummarisesAccounts()

	/**
	 * Escalated accounts with a non-empty group are routed to '<group>-owner'.
	 *
	 * @return void
	 */
	public function testEscalationRoutesToGroupOwner(): void {
		$inputs = $this->inputs();
		$inputs['accounts'] = [
			['glAccountNumber' => '1200', 'accountGroupCode' => 'cash', 'actualCents' => 900000, 'basisCents' => 0],
		];

		$result = $this->service()->run(inputs: $inputs);

		self::assertSame('highly-material', $result['items'][0]['materialityClassification']);
		self::assertSame('escalated', $result['items'][0]['status']);
		self::assertSame('cash-owner', $result['items'][0]['ownerEscalatedTo']);
		self::assertSame(0.0, $result['items'][0]['percentageVariance']);

	}//end testEscalationRoutesToGroupOwner()

	/**
	 * persistRun() writes one FluxRun, one FluxItem per account and one FluxAttribution per driver.
	 *
	 * @return void
	 */
	public function testRunPersistsRunItemsAndAttributions(): void {
		$this->service()->run(inputs: $this->inputs());

		$schemas = array_column($this->saves, 'schema');
		self::assertSame(
			[
				'FluxRun',
				'FluxItem',
				'FluxItem', 'FluxAttribution', 'FluxAttribution', 'FluxAttribution', 'FluxAttribution',
				'FluxItem', 'FluxAttribution',
			],
			$schemas
		);
		self::assertSame(['books'], array_values(array_unique(array_column($this->saves, 'register'))));

		self::assertSame(
			[
				'administrationId' => 'adm1',
				'periodId' => '2026-03',
				'scope' => 'entity',
				'comparisonBasis' => 'prior-year',
				'materialityAbsoluteCents' => 10000,
				'materialityPercentage' => 0.01,
				'runTimestamp' => '2026-03-31T10:20:30+00:00',
				'status' => 'completed',
				'resultSummary' => [
					'materialCount' => 2,
					'autoExplainedCount' => 1,
					'escalatedCount' => 1,
					'totalVarianceCents' => 100500,
				],
			],
			$this->saves[0]['object']
		);

		$item = $this->saves[2]['object'];
		self::assertArrayNotHasKey('attributions', $item);
		self::assertSame('7000', $item['glAccountNumber']);
		self::assertSame('flux-adm1-2026-03-102030', $item['fluxRunId']);

		self::assertSame(
			['driver' => 'volume', 'contributionCents' => 30000, 'fluxItemId' => '7000@flux-adm1-2026-03-102030'],
			$this->saves[3]['object']
		);
		self::assertSame('8000@flux-adm1-2026-03-102030', $this->saves[8]['object']['fluxItemId']);

	}//end testRunPersistsRunItemsAndAttributions()

	/**
	 * An empty register slug falls back to 'shillinq'.
	 *
	 * @return void
	 */
	public function testEmptyRegisterSlugFallsBackToShillinq(): void {
		$this->service(registerSlug: '')->run(inputs: $this->inputs());

		self::assertCount(9, $this->saves);
		self::assertSame(['shillinq'], array_values(array_unique(array_column($this->saves, 'register'))));

	}//end testEmptyRegisterSlugFallsBackToShillinq()

	/**
	 * A failing store is logged and run() still returns its summary.
	 *
	 * @return void
	 */
	public function testPersistFailureIsLoggedAndSwallowed(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('error')
			->with(
				'FluxService: persistRun failed',
				['exception' => 'store down', 'fluxRunId' => 'flux-adm1-2026-03-102030']
			);

		$result = $this->service(logger: $logger, failSave: true)->run(inputs: $this->inputs());

		self::assertSame(3, $result['itemCount']);
		self::assertSame([], $this->saves);

	}//end testPersistFailureIsLoggedAndSwallowed()

	/**
	 * Missing inputs default; a non-DateTimeImmutable timestamp is replaced with "now".
	 *
	 * @return void
	 */
	public function testRunDefaultsMissingInputs(): void {
		$result = $this->service()->run(inputs: ['runTimestamp' => '2026-01-01']);

		self::assertMatchesRegularExpression('/^flux---\d{6}$/', $result['fluxRunId']);
		self::assertSame(0, $result['itemCount']);
		self::assertSame(0, $result['totalVarianceCents']);
		self::assertCount(1, $this->saves);
		self::assertSame('administration', $this->saves[0]['object']['scope']);
		self::assertSame('budget', $this->saves[0]['object']['comparisonBasis']);
		self::assertSame(0, $this->saves[0]['object']['materialityAbsoluteCents']);

	}//end testRunDefaultsMissingInputs()

	/**
	 * Without any threshold, zero variance is immaterial and anything else material.
	 *
	 * @return void
	 */
	public function testClassifyMaterialityWithoutThreshold(): void {
		$s = $this->service();

		self::assertSame('immaterial', $s->classifyMateriality(varianceCents: 0, basisCents: 0, policy: []));
		self::assertSame('material', $s->classifyMateriality(varianceCents: -1, basisCents: 0, policy: []));

	}//end testClassifyMaterialityWithoutThreshold()

	/**
	 * Coverage is capped at 1.0 when attributions over-explain the variance.
	 *
	 * @return void
	 */
	public function testCoverageIsCappedAtOne(): void {
		$coverage = $this->service()->computeAutoExplanationCoverage(
			attributions: [['contributionCents' => 300], ['contributionCents' => 200]],
			varianceCents: -100
		);

		self::assertSame(1.0, $coverage);

	}//end testCoverageIsCappedAtOne()

	/**
	 * Escalated rows render "explanation; escalated to owner" and negative unsigned amounts get a minus.
	 *
	 * @return void
	 */
	public function testMarkdownRendersEscalationSummaries(): void {
		$markdown = $this->service()->renderNarrativeMarkdown(
			narrative: [
				'periodId' => '2026-03',
				'itemCount' => 2,
				'explainedCount' => 0,
				'unexplainedCount' => 2,
				'totalAdverseCents' => 0,
				'rows' => [
					[
						'glAccountNumber' => '8000',
						'budgetCents' => -250000,
						'actualCents' => 100000,
						'varianceCents' => 350000,
						'autoExplanation' => 'fx EUR -100',
						'status' => 'escalated',
						'ownerEscalatedTo' => 'cash-owner',
					],
					[
						'glAccountNumber' => '8100',
						'budgetCents' => 0,
						'actualCents' => 0,
						'varianceCents' => -100,
						'autoExplanation' => '',
						'status' => 'escalated',
					],
				],
			]
		);

		self::assertStringContainsString(
			"| 8000 | EUR -2,500 | EUR 1,000 | EUR +3,500 | fx EUR -100; escalated to cash-owner |\n",
			$markdown
		);
		self::assertStringContainsString("| 8100 | EUR 0 | EUR 0 | EUR -1 | escalated to owner |\n", $markdown);

	}//end testMarkdownRendersEscalationSummaries()
}//end class
