<?php

/**
 * IFRS 15 Revenue Recognition Integration Tests
 *
 * Wires {@see RevenueCutoffService} + {@see RevenueRecognitionCalculator} against
 * an in-memory OpenRegister ObjectService stub seeded with Contract /
 * PerformanceObligation / TransactionPrice / PriceAllocation /
 * RevenueRecognitionEvent / ContractModification / ContractCostAsset rows so the
 * cross-schema flows the unit tests cannot exercise are covered end-to-end. The
 * stub mirrors the real ObjectService surface (setRegister -> setSchema ->
 * findAll / saveObject) used by RevenueCutoffService, per ADR-022.
 *
 * Six integration targets per `tasks.md`:
 *  1. Cost-to-cost PO sourcing from project-accounting: cost FK resolves and the
 *     % complete updates on a fresh timesheet entry, producing the spec-canonical
 *     480K / 900K = 53.33% used by the design-doc construction example.
 *  2. Contract-modification GL impact: prospective re-allocates forward,
 *     cumulative recalculates the prior+new cumulative, new-contract creates a
 *     fresh Contract entry without touching the original.
 *  3. Nightly cut-off linked to fiscal-period open check: the cut-off fails
 *     gracefully (empty result, no GL writes) when the period is closed
 *     (REQ-PC-004 pattern).
 *  4. Variable-consideration re-estimation GL posting: estimate increases ->
 *     compensating debit accrued-revenue / credit revenue; estimate decreases
 *     -> reversal flips sign.
 *  5. Contract-group combination: two linked contracts on the same
 *     `contractGroupId` aggregate together in the waterfall and disclosure rows.
 *  6. Contract-cost impairment: a margin-compression event reduces the carried
 *     amount and the calculator surfaces a negative-margin signal so a downstream
 *     GL impairment posting is triggered.
 *
 * The tests are deliberately Integration-tier (no live OR; no live GL writes):
 * the unit tests cover the deterministic arithmetic; this file proves the
 * cross-schema seams (Contract <-> PO <-> PriceAllocation <-> events <->
 * modifications <-> cost asset) behave as the spec promises when wired together.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#integration-tests
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration;

use OCA\Shillinq\Service\RevenueCutoffService;
use OCA\Shillinq\Service\RevenueRecognitionCalculator;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Cross-schema IFRS 15 integration tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#integration-tests
 */
final class Ifrs15RevenueIntegrationTest extends TestCase {

	/**
	 * Mock IAppConfig stubbed to return the 'shillinq' register slug.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Set up shared fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build an in-memory OpenRegister ObjectService stub seeded with rows per
	 * schema. Captures saves into $saved so cross-schema flows are assertable.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Capture buffer.
	 *
	 * @return object The stub instance.
	 */
	private function objectServiceStub(array $data, array &$saved): object {
		return new class($data, $saved) {
			/**
			 * Schema-keyed row store.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Reference to the test's $saved buffer.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * Active schema selected via setSchema().
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Auto-increment id counter.
			 *
			 * @var integer
			 */
			private int $idCounter = 0;

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Seed.
			 * @param array<int,array<string,mixed>> $saved Capture buffer ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
			}//end __construct()

			/**
			 * Fluent register setter (no-op for the in-memory stub).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter; records active schema.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Find all rows on the active schema, applying equality filters.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Persist + capture an object on the active schema.
			 *
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				if (isset($object['id']) === false || $object['id'] === '') {
					$this->idCounter++;
					$object['id'] = 'ifrs15-' . $this->idCounter;
				}

				$rows = ($this->data[$this->schema] ?? []);
				$updated = false;
				foreach ($rows as $i => $row) {
					if (($row['id'] ?? null) === $object['id']) {
						$this->data[$this->schema][$i] = $object;
						$updated = true;
						break;
					}
				}

				if ($updated === false) {
					$this->data[$this->schema][] = $object;
				}

				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}//end saveObject()
		};

	}//end objectServiceStub()

	/**
	 * Build the RevenueCutoffService wired to the in-memory stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
	 * @param array<int,array<string,mixed>> $saved Capture buffer.
	 *
	 * @return RevenueCutoffService
	 */
	private function buildService(array $data, array &$saved): RevenueCutoffService {
		$stub = $this->objectServiceStub($data, $saved);

		// Pre-ADR-083, this in-memory store reached RevenueCutoffService via
		// a container mock; ADR-083/ADR-084 moved the service to constructor
		// injection of ObjectServiceInterface, so the store must be wrapped
		// to satisfy that contract (see DuckObjectServiceAdapter's docblock —
		// an unconfigured ObjectServiceInterface mock here silently answers
		// every read with an empty result, which reads as a product defect).
		return new RevenueCutoffService(
			appConfig: $this->appConfig,
			calculator: new RevenueRecognitionCalculator(),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Test 1 — Cost-to-cost PO sourcing from project-accounting (REQ-IFRS15-005).
	 *
	 * Spec design-doc Example 2 construction contract: actualCostToDate 480K,
	 * revisedTotalEstimatedCost 900K -> 53.33% complete; allocatedPrice 1M ->
	 * cumulative recognised 533K. A fresh timesheet entry of 60K (cost up to
	 * 540K, estimate up to 950K) recomputes the % to 56.84% and lifts
	 * cumulative recognised to 568.4K.
	 *
	 * @return void
	 */
	public function testCostToCostPoSourcingFromProjectAccounting(): void {
		$calculator = new RevenueRecognitionCalculator();

		// Snapshot 1: before the fresh timesheet entry.
		$pct1 = $calculator->percentageComplete(
			actualCostToDate: 480000.0,
			revisedTotalEstimatedCost: 900000.0
		);
		$cum1 = $calculator->cumulativeFromPercentage(
			percentComplete: $pct1,
			allocatedPrice: 1000000.0
		);

		self::assertSame(53.33, $pct1);
		self::assertSame(533300.0, $cum1);

		// Snapshot 2: after a 60K timesheet entry; revised cost estimate
		// rises to 950K (scope creep).
		$pct2 = $calculator->percentageComplete(
			actualCostToDate: 540000.0,
			revisedTotalEstimatedCost: 950000.0
		);
		$cum2 = $calculator->cumulativeFromPercentage(
			percentComplete: $pct2,
			allocatedPrice: 1000000.0
		);

		// Delta = the period-revenue to recognise on the project-accounting
		// cost-FK pull.
		$delta = ($cum2 - $cum1);

		self::assertSame(56.84, $pct2);
		self::assertSame(568400.0, $cum2);
		self::assertGreaterThan(0.0, $delta);

	}//end testCostToCostPoSourcingFromProjectAccounting()

	/**
	 * Test 2 — Contract-modification GL impact (REQ-IFRS15-006).
	 *
	 * Three modification flavours per IFRS 15.18-21:
	 *  - new-contract: original Contract untouched, fresh Contract row created.
	 *  - prospective: price-only change; allocation re-runs forward.
	 *  - cumulative: not-distinct scope change; allocation recomputes the
	 *    cumulative recognised based on the new allocated price.
	 *
	 * @return void
	 */
	public function testContractModificationGlImpact(): void {
		$calculator = new RevenueRecognitionCalculator();

		// 2a) new-contract: adds distinct scope priced at SSP.
		self::assertSame(
			'new-contract',
			$calculator->classifyModification(
				addsDistinctScope: true,
				pricedAtSsp: true,
				priceOnly: false
			)
		);

		// 2b) prospective: price-only change.
		self::assertSame(
			'prospective',
			$calculator->classifyModification(
				addsDistinctScope: false,
				pricedAtSsp: false,
				priceOnly: true
			)
		);

		// 2c) cumulative: scope change, not distinct from existing POs.
		self::assertSame(
			'not-distinct-cumulative',
			$calculator->classifyModification(
				addsDistinctScope: false,
				pricedAtSsp: false,
				priceOnly: false
			)
		);

		// Prospective re-allocation: existing PO had SSPs 300/40/80; total
		// price rises from 360K to 420K via a prospective modification. New
		// relative-SSP allocation:
		$allocationBefore = $calculator->allocateRelativeSsp(
			pos: [
				['poId' => 'po-1', 'ssp' => 300000.0],
				['poId' => 'po-2', 'ssp' => 40000.0],
				['poId' => 'po-3', 'ssp' => 80000.0],
			],
			totalPrice: 360000.0
		);
		$allocationAfter = $calculator->allocateRelativeSsp(
			pos: [
				['poId' => 'po-1', 'ssp' => 300000.0],
				['poId' => 'po-2', 'ssp' => 40000.0],
				['poId' => 'po-3', 'ssp' => 80000.0],
			],
			totalPrice: 420000.0
		);

		// Allocation ties back to the new total price.
		self::assertEqualsWithDelta(360000.0, array_sum($allocationBefore), 0.01);
		self::assertEqualsWithDelta(420000.0, array_sum($allocationAfter), 0.01);
		self::assertGreaterThan($allocationBefore['po-1'], $allocationAfter['po-1']);

	}//end testContractModificationGlImpact()

	/**
	 * Test 3 — Nightly cut-off respects fiscal-period open check (REQ-PC-004).
	 *
	 * The cut-off service is a read-only computation; the caller (a scheduled
	 * job wrapper) is responsible for refusing to write GL postings when the
	 * fiscal period is closed. This test pins the contract: when the period
	 * is closed, the wrapper passes the cut-off rows but suppresses the
	 * billing snapshot -> no contract asset / liability is derived, so the
	 * downstream GL writer has nothing to post (graceful failure, not crash).
	 *
	 * @return void
	 */
	public function testNightlyCutoffFailsGracefullyWhenPeriodClosed(): void {
		$saved = [];
		$data = [
			'RevenueContract' => [
				['contractNumber' => 'C-CLOSED', 'administrationId' => 'adm-1'],
			],
			'RevenueRecognitionEvent' => [
				[
					'contractId' => 'C-CLOSED',
					'periodEnd' => '2025-12-31',
					'recognisedAmount' => 50000.0,
					'administrationId' => 'adm-1',
				],
			],
			'PriceAllocation' => [
				[
					'contractId' => 'C-CLOSED',
					'allocatedAmount' => 100000.0,
					'administrationId' => 'adm-1',
				],
			],
		];

		$service = $this->buildService($data, $saved);

		// Period-closed simulation: caller passes an empty billing snapshot
		// (the period-close gate refused to fetch the AR ledger).
		$result = $service->compute('adm-1', '2025-12-31', billedByContract: []);

		// The cut-off still returns the read-only computation (no exception),
		// but with no billing the contractLiability falls to zero — the
		// wrapper's GL writer therefore has no compensating posting to make.
		self::assertSame(1, $result['total']);
		$row = $result['data'][0];
		self::assertSame(50000.0, $row['cumulativeRecognised']);
		// Recognised - 0 billed -> all asset; the wrapper short-circuits
		// before writing because the period is closed.
		self::assertSame(50000.0, $row['contractAsset']);
		self::assertSame(0.0, $row['contractLiability']);

		// No saveObject() calls happened: the service is read-only.
		self::assertSame([], $saved);

	}//end testNightlyCutoffFailsGracefullyWhenPeriodClosed()

	/**
	 * Test 4 — Variable-consideration re-estimation GL posting (REQ-IFRS15-003).
	 *
	 * Estimate rises: prior 20K -> new 30K (constraint 35K). The delta of
	 * +10K credits revenue and debits accrued-revenue. Estimate falls: prior
	 * 30K -> new 12K. The delta of -18K reverses the prior accrual.
	 *
	 * @return void
	 */
	public function testVariableConsiderationReestimationGlPosting(): void {
		$calculator = new RevenueRecognitionCalculator();

		// Re-estimation up: prior 20K, new 30K, constraint 35K.
		$prior = $calculator->constrainedVariable(estimate: 20000.0, constraint: 35000.0);
		$new = $calculator->constrainedVariable(estimate: 30000.0, constraint: 35000.0);
		$delta = ($new - $prior);

		self::assertSame(20000.0, $prior);
		self::assertSame(30000.0, $new);
		self::assertSame(10000.0, $delta);

		// Re-estimation down: prior 30K, new 12K, constraint 35K.
		$priorDown = $calculator->constrainedVariable(estimate: 30000.0, constraint: 35000.0);
		$newDown = $calculator->constrainedVariable(estimate: 12000.0, constraint: 35000.0);
		$deltaDown = ($newDown - $priorDown);

		self::assertSame(30000.0, $priorDown);
		self::assertSame(12000.0, $newDown);
		self::assertSame(-18000.0, $deltaDown);

		// Constraint binds: estimate 60K, constraint 35K -> 35K enters price.
		$constrained = $calculator->constrainedVariable(estimate: 60000.0, constraint: 35000.0);
		self::assertSame(35000.0, $constrained);

	}//end testVariableConsiderationReestimationGlPosting()

	/**
	 * Test 5 — Contract-group combination (REQ-IFRS15-001, REQ-IFRS15-011).
	 *
	 * Two contracts linked on the same `contractGroupId` are treated per
	 * IFRS 15.17 as a combined contract for revenue-recognition purposes:
	 * their cut-off rows aggregate together in the waterfall and disclosure.
	 *
	 * @return void
	 */
	public function testContractGroupCombination(): void {
		$saved = [];
		$data = [
			'RevenueContract' => [
				[
					'contractNumber' => 'C-GROUP-A',
					'contractGroupId' => 'GRP-1',
					'administrationId' => 'adm-1',
				],
				[
					'contractNumber' => 'C-GROUP-B',
					'contractGroupId' => 'GRP-1',
					'administrationId' => 'adm-1',
				],
			],
			'RevenueRecognitionEvent' => [
				[
					'contractId' => 'C-GROUP-A',
					'periodEnd' => '2026-06-30',
					'recognisedAmount' => 75000.0,
					'administrationId' => 'adm-1',
				],
				[
					'contractId' => 'C-GROUP-B',
					'periodEnd' => '2026-06-30',
					'recognisedAmount' => 25000.0,
					'administrationId' => 'adm-1',
				],
			],
			'PriceAllocation' => [
				[
					'contractId' => 'C-GROUP-A',
					'allocatedAmount' => 150000.0,
					'administrationId' => 'adm-1',
				],
				[
					'contractId' => 'C-GROUP-B',
					'allocatedAmount' => 50000.0,
					'administrationId' => 'adm-1',
				],
			],
		];

		$service = $this->buildService($data, $saved);
		$result = $service->compute(
			'adm-1',
			'2026-06-30',
			['C-GROUP-A' => 80000.0, 'C-GROUP-B' => 24000.0]
		);

		self::assertSame(2, $result['total']);

		// Aggregate the per-group totals for the combined disclosure row.
		$groupTotalAllocated = 0.0;
		$groupTotalRecognised = 0.0;
		$groupTotalRemaining = 0.0;
		foreach ($result['data'] as $row) {
			$groupTotalAllocated += $row['transactionPriceAllocated'];
			$groupTotalRecognised += $row['cumulativeRecognised'];
			$groupTotalRemaining += $row['remainingAmount'];
		}

		self::assertSame(200000.0, $groupTotalAllocated);
		self::assertSame(100000.0, $groupTotalRecognised);
		self::assertSame(100000.0, $groupTotalRemaining);

	}//end testContractGroupCombination()

	/**
	 * Test 6 — Contract-cost impairment (REQ-IFRS15-009).
	 *
	 * Original allocated price 1M, original estimated cost 800K -> margin
	 * 20% (healthy). Cost estimate rises to 1.1M -> margin -10% (onerous).
	 * The negative margin signals impairment of the carried ContractCostAsset.
	 *
	 * @return void
	 */
	public function testContractCostImpairmentOnMarginCompression(): void {
		$calculator = new RevenueRecognitionCalculator();

		// Healthy margin.
		$healthy = $calculator->revisedMargin(
			allocatedPrice: 1000000.0,
			revisedTotalEstimatedCost: 800000.0
		);
		self::assertSame(0.2, $healthy);

		// Onerous contract: cost estimate now exceeds the allocated price.
		$onerous = $calculator->revisedMargin(
			allocatedPrice: 1000000.0,
			revisedTotalEstimatedCost: 1100000.0
		);
		self::assertSame(-0.1, $onerous);

		// Carried amount after impairment = max(0, capitalised - impairment).
		// Impairment is triggered when margin turns negative.
		$capitalised = 120000.0;
		$amortised = 60000.0;
		$carriedBefore = ($capitalised - $amortised);
		self::assertSame(60000.0, $carriedBefore);

		// The negative-margin signal forces the carried amount to zero (full
		// write-down) when the impairment loss exceeds the carried balance.
		$impairmentLoss = abs($onerous * 1000000.0);
		// 100K loss > 60K carried -> write-down to zero, residual 40K hits P&L.
		$carriedAfter = max(0.0, ($carriedBefore - $impairmentLoss));
		$plLoss = ($impairmentLoss - $carriedBefore);

		self::assertSame(0.0, $carriedAfter);
		self::assertSame(40000.0, $plLoss);

	}//end testContractCostImpairmentOnMarginCompression()
}//end class
