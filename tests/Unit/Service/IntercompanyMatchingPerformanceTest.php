<?php

/**
 * Performance test for IntercompanyMatchingService at REQ-ICE-010 scale.
 *
 * Wires IntercompanyMatchingService to an in-memory ObjectService stub seeded
 * with a representative full-month dataset (12 entities, ~4 000 intercompany
 * transactions across a single IntercompanyRelation) and asserts that
 * matchRelationPeriod() returns inside the <5 minute REQ-ICE-010 budget.
 *
 * A second case exercises the "incremental re-match" path: a delta of 50-100
 * transactions on the same relation must complete in under 30 seconds.
 *
 * Both budgets are intentionally loose ceilings sourced from REQ-ICE-010 so a
 * regression of one or two orders of magnitude trips the test even on slow
 * CI hardware. The algorithm is O(transactions) over two scoped reads + a
 * roll-forward read; the stub mimics OR's findAll filters so the time spent
 * in the service is representative of real data shape.
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
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-23
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\IntercompanyMatchingCalculator;
use OCA\Shillinq\Service\IntercompanyMatchingService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Asserts IntercompanyMatchingService::matchRelationPeriod() returns in
 * < 300 s for ~4 000 IC-transactions (REQ-ICE-010 full-match target) and in
 * < 30 s for an incremental delta of 50-100 transactions.
 *
 * The named-parameter sniff does not apply to PHPUnit assertions.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[Group('performance')]
final class IntercompanyMatchingPerformanceTest extends TestCase {
	/**
	 * Number of IC-transactions to seed for the full-match case (REQ-ICE-010
	 * "typical month" target of 4 000 transactions for a 12-entity group).
	 */
	private const TRANSACTION_COUNT = 4000;

	/**
	 * Number of IC-transactions to seed for the incremental delta case.
	 */
	private const INCREMENTAL_DELTA = 100;

	/**
	 * Soft budget the full-match case must beat (seconds) per REQ-ICE-010.
	 */
	private const FULL_MATCH_BUDGET_SECONDS = 300.0;

	/**
	 * Soft budget the incremental case must beat (seconds) per REQ-ICE-010.
	 */
	private const INCREMENTAL_BUDGET_SECONDS = 30.0;

	/**
	 * Full month matching for a single relation with ~4 000 transactions must
	 * complete under the REQ-ICE-010 budget.
	 *
	 * @return void
	 */
	public function testFullMonthMatchUnderFiveMinutes(): void {
		$service = $this->buildService(transactionCount: self::TRANSACTION_COUNT);

		$startedAt = microtime(true);
		$match = $service->matchRelationPeriod('rel-perf', '2026-01');
		$elapsed = (microtime(true) - $startedAt);

		// The seeded dataset posts paired debits across A/B with identical
		// totals so the match is always a perfect-match status; the assertion
		// guards against accidental algorithmic regressions that quietly drop
		// transactions on one side.
		self::assertSame('perfect-match', $match['matchStatus']);
		self::assertSame(0.0, $match['mismatchAmount']);

		self::assertLessThan(
			self::FULL_MATCH_BUDGET_SECONDS,
			$elapsed,
			sprintf(
				'IntercompanyMatchingService::matchRelationPeriod() took %.3fs for %d transactions; REQ-ICE-010 budget is %.1fs (5 minutes).',
				$elapsed,
				self::TRANSACTION_COUNT,
				self::FULL_MATCH_BUDGET_SECONDS
			)
		);

	}//end testFullMonthMatchUnderFiveMinutes()

	/**
	 * Incremental re-match for a delta of ~100 transactions on the same
	 * relation must complete in under 30 seconds per REQ-ICE-010.
	 *
	 * @return void
	 */
	public function testIncrementalReMatchUnderThirtySeconds(): void {
		$service = $this->buildService(transactionCount: self::INCREMENTAL_DELTA);

		$startedAt = microtime(true);
		$match = $service->matchRelationPeriod('rel-perf', '2026-01');
		$elapsed = (microtime(true) - $startedAt);

		self::assertSame('perfect-match', $match['matchStatus']);

		self::assertLessThan(
			self::INCREMENTAL_BUDGET_SECONDS,
			$elapsed,
			sprintf(
				'Incremental matchRelationPeriod() took %.3fs for %d transactions; REQ-ICE-010 budget is %.1fs (30 seconds).',
				$elapsed,
				self::INCREMENTAL_DELTA,
				self::INCREMENTAL_BUDGET_SECONDS
			)
		);

	}//end testIncrementalReMatchUnderThirtySeconds()

	/**
	 * Build a wired IntercompanyMatchingService backed by a seeded stub.
	 *
	 * @param int $transactionCount The number of paired IC-transactions to seed.
	 *
	 * @return IntercompanyMatchingService
	 */
	private function buildService(int $transactionCount): IntercompanyMatchingService {
		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$relations = [$this->seedRelation()];
		$transactions = $this->seedTransactions(count: $transactionCount);

		$stub = $this->newObjectServiceStub(
			relations: $relations,
			transactions: $transactions,
		);
		$container->method('get')->willReturn($stub);

		return new IntercompanyMatchingService(
			$appConfig,
			new IntercompanyMatchingCalculator(),
			$logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Build the single IntercompanyRelation fixture used by all transactions.
	 *
	 * @return array<string,mixed>
	 */
	private function seedRelation(): array {
		return [
			'relationId' => 'rel-perf',
			'administrationId' => 'adm-perf',
			'entityAId' => 'entA',
			'entityBId' => 'entB',
			'defaultAccountA' => '8200',
			'defaultAccountB' => '4400',
			'toleranceAbsolute' => 10.0,
			'toleranceRelative' => 0.5,
		];

	}//end seedRelation()

	/**
	 * Seed `$count` paired IC-transactions (half A-side, half B-side) on a
	 * single relation. Each pair shares an integer-cent amount so totals match
	 * exactly and the result is a perfect-match status.
	 *
	 * @param int $count The total transaction count (paired across A/B).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function seedTransactions(int $count): array {
		$transactions = [];
		$half = (int)($count / 2);
		for ($i = 0; $i < $half; $i++) {
			$amount = (100.0 + ($i % 50));
			$transactions[] = [
				'id' => 'txA-' . $i,
				'sourceAdministrationId' => 'entA',
				'relationId' => 'rel-perf',
				'debitAmount' => $amount,
				'creditAmount' => 0.0,
				'currency' => 'EUR',
			];
			$transactions[] = [
				'id' => 'txB-' . $i,
				'sourceAdministrationId' => 'entB',
				'relationId' => 'rel-perf',
				'debitAmount' => $amount,
				'creditAmount' => 0.0,
				'currency' => 'EUR',
			];
		}

		return $transactions;
	}//end seedTransactions()

	/**
	 * Construct an anonymous ObjectService stub that mimics setRegister /
	 * setSchema / findAll / saveObject. Matches the shape exercised by
	 * IntercompanyMatchingService.
	 *
	 * @param array<int,array<string,mixed>> $relations IntercompanyRelation rows.
	 * @param array<int,array<string,mixed>> $transactions IntercompanyTransaction rows.
	 *
	 * @return object
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) The anonymous-class stub
	 * mirrors the OR ObjectService fluent shape; splitting it across helpers
	 * would obscure the test wiring without reducing complexity.
	 */
	private function newObjectServiceStub(array $relations, array $transactions): object {
		return new class($relations, $transactions) {
			/**
			 * IntercompanyRelation rows.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $relations;

			/**
			 * IntercompanyTransaction rows.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $transactions;

			/**
			 * Last selected schema slug.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $relations Relation rows.
			 * @param array<int,array<string,mixed>> $transactions Transaction rows.
			 */
			public function __construct(array $relations, array $transactions) {
				$this->relations = $relations;
				$this->transactions = $transactions;
			}//end __construct()

			/**
			 * Fluent register setter (no-op for the stub).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
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
			 * Return rows for the active schema (no prior matches → trivially
			 * consistent roll-forward).
			 *
			 * @param array<string,mixed> $params Query params (mirrors the OR
			 *                                    ObjectService signature; the
			 *                                    perf stub does not filter).
			 *
			 * @return array<int,array<string,mixed>>
			 *
			 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature must
			 * mirror OR ObjectService::findAll for the service call to type-match.
			 */
			public function findAll(array $params = []): array {
				if ($this->schema === 'IntercompanyRelation') {
					return $this->relations;
				}

				if ($this->schema === 'IntercompanyTransaction') {
					return $this->transactions;
				}

				// IntercompanyMatch reads (existing matches lookup +
				// roll-forward) return empty so the perf path measures only
				// detection + aggregation cost.
				return [];
			}//end findAll()

			/**
			 * Echo the object back without recording (perf path).
			 *
			 * @param array<string,mixed> $object The object being saved.
			 * @param string|null $register The register slug (unused).
			 * @param string|null $schema The schema slug (unused).
			 *
			 * @return array<string,mixed>
			 *
			 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature must
			 * mirror OR ObjectService::saveObject for the service call to type-match.
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null): array {
				return $object;
			}//end saveObject()
		};

	}//end newObjectServiceStub()
}//end class
