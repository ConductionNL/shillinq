<?php

/**
 * Unit tests for RetainerGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retainer-billing-engine/specs/retainer-billing-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\RetainerGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for RetainerGuard lifecycle preconditions.
 *
 * Covers REQ-RETN-001 (non-overlapping pool periods), REQ-RETN-002 (drawdown
 * rate-immutability on materialization) and REQ-RETN-011 (true-up approver
 * present). All guards fail closed; inline-object cases that need no cross-record
 * lookup never touch the container.
 */
class RetainerGuardTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var RetainerGuard
	 */
	private RetainerGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new RetainerGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->buildObjectServiceStub([])),
		);

	}//end setUp()

	/**
	 * Rebuild the guard on an ObjectService store whose findAll() returns the
	 * given rows for any schema query (used by canActivatePool's client lookup).
	 *
	 * The store is a constructor dependency since ADR-084, so the guard has to
	 * be rebuilt whenever a test seeds different rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows to return from findAll().
	 *
	 * @return void
	 */
	private function stubObjectService(array $rows): void {
		$objectService = $this->buildObjectServiceStub($rows);

		$this->container->method('get')->willReturn($objectService);

		$this->guard = new RetainerGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($objectService),
		);

	}//end stubObjectService()

	/**
	 * Build a duck-typed ObjectService store over the given rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows to return from findAll().
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $rows): object {
		return new class($rows) {
			/**
			 * Construct the fake ObjectService.
			 *
			 * @param array<int,array<string,mixed>> $rows Rows to return.
			 */
			public function __construct(
				private array $rows,
			) {
			}//end __construct()

			// phpcs:disable CustomSniffs.Functions.NamedParameters

			/**
			 * Fluent register setter (no-op).
			 *
			 * @param string $r Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $r): self {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter (no-op).
			 *
			 * @param string $s Schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $s): self {
				return $this;
			}//end setSchema()

			/**
			 * Return the stubbed rows, EXCEPT for an identifier-column query.
			 *
			 * 🔴 The store used to answer every query with its rows, the
			 * identifier lookups included. Real OpenRegister cannot do that:
			 * `filters` addresses the object's JSON properties, while `id` and
			 * `uuid` are the entity's own columns, so
			 * `findAll(['filters' => ['id' => …]])` matches ZERO rows for every
			 * value, silently. A store that answers it anyway certifies a
			 * production lookup that is dead by construction — which is how
			 * `canMaterializeDrawdown()` came to skip its rate-immutability
			 * check on every real call while this suite stayed green.
			 *
			 * Property filters are still ignored (each test seeds exactly the
			 * rows its subject should see); only the identifier columns are
			 * answered the way the engine answers them.
			 *
			 * @param array<string,mixed> $q Query.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $q): array {
				$filters = (array)($q['filters'] ?? []);
				if (array_key_exists('id', $filters) === true || array_key_exists('uuid', $filters) === true) {
					return [];
				}

				return $this->rows;
			}//end findAll()

			// phpcs:enable CustomSniffs.Functions.NamedParameters
		};

	}//end buildObjectServiceStub()

	/**
	 * A pool with no overlapping sibling for the same client/project may activate
	 * (REQ-RETN-001). The only sibling returned has a non-overlapping period.
	 *
	 * @return void
	 */
	public function testPoolWithoutOverlapCanActivate(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$this->stubObjectService(
			rows: [
				[
					'poolId' => 'RETN-2026-02-001',
					'clientId' => 'c1',
					'projectId' => '',
					'periodStart' => '2026-02-01',
					'periodEnd' => '2026-02-28',
					'status' => 'active',
				],
			]
		);

		$pool = [
			'poolId' => 'RETN-2026-01-001',
			'clientId' => 'c1',
			'projectId' => '',
			'periodStart' => '2026-01-01',
			'periodEnd' => '2026-01-31',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canActivatePool(poolId: 'RETN-2026-01-001', object: $pool));

	}//end testPoolWithoutOverlapCanActivate()

	/**
	 * A pool overlapping an existing active sibling for the same client/project
	 * is rejected (REQ-RETN-001 fail-closed).
	 *
	 * @return void
	 */
	public function testOverlappingPoolCannotActivate(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$this->stubObjectService(
			rows: [
				[
					'poolId' => 'RETN-2026-01-002',
					'clientId' => 'c1',
					'projectId' => '',
					'periodStart' => '2026-01-15',
					'periodEnd' => '2026-02-15',
					'status' => 'active',
				],
			]
		);

		$pool = [
			'poolId' => 'RETN-2026-01-001',
			'clientId' => 'c1',
			'projectId' => '',
			'periodStart' => '2026-01-01',
			'periodEnd' => '2026-01-31',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canActivatePool(poolId: 'RETN-2026-01-001', object: $pool));

	}//end testOverlappingPoolCannotActivate()

	/**
	 * An overlapping pool for a DIFFERENT project does not conflict — pools are
	 * scoped to (clientId, projectId) (REQ-RETN-001).
	 *
	 * @return void
	 */
	public function testOverlapOnDifferentProjectDoesNotConflict(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$this->stubObjectService(
			rows: [
				[
					'poolId' => 'RETN-2026-01-002',
					'clientId' => 'c1',
					'projectId' => 'other',
					'periodStart' => '2026-01-15',
					'periodEnd' => '2026-02-15',
					'status' => 'active',
				],
			]
		);

		$pool = [
			'poolId' => 'RETN-2026-01-001',
			'clientId' => 'c1',
			'projectId' => 'migration',
			'periodStart' => '2026-01-01',
			'periodEnd' => '2026-01-31',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canActivatePool(poolId: 'RETN-2026-01-001', object: $pool));

	}//end testOverlapOnDifferentProjectDoesNotConflict()

	/**
	 * An archived overlapping sibling does not block activation (only
	 * active/draft siblings conflict) (REQ-RETN-001).
	 *
	 * @return void
	 */
	public function testArchivedOverlapDoesNotConflict(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$this->stubObjectService(
			rows: [
				[
					'poolId' => 'RETN-2026-01-002',
					'clientId' => 'c1',
					'projectId' => '',
					'periodStart' => '2026-01-15',
					'periodEnd' => '2026-02-15',
					'status' => 'archived',
				],
			]
		);

		$pool = [
			'poolId' => 'RETN-2026-01-001',
			'clientId' => 'c1',
			'projectId' => '',
			'periodStart' => '2026-01-01',
			'periodEnd' => '2026-01-31',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canActivatePool(poolId: 'RETN-2026-01-001', object: $pool));

	}//end testArchivedOverlapDoesNotConflict()

	/**
	 * A pool with an inverted period (start after end) is rejected
	 * (REQ-RETN-001 fail-closed).
	 *
	 * @return void
	 */
	public function testInvertedPeriodCannotActivate(): void {
		$pool = [
			'poolId' => 'RETN-2026-01-001',
			'clientId' => 'c1',
			'periodStart' => '2026-01-31',
			'periodEnd' => '2026-01-01',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canActivatePool(poolId: 'RETN-2026-01-001', object: $pool));

	}//end testInvertedPeriodCannotActivate()

	/**
	 * A drawdown whose amount equals hoursOrAmount × drawdownRate materializes
	 * when no pool can be cross-checked (REQ-RETN-002, recorded rate authoritative).
	 *
	 * @return void
	 */
	public function testConsistentDrawdownMaterializesWithoutPool(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$this->stubObjectService(rows: []);

		$drawdown = [
			'poolId' => 'RETN-2026-01-001',
			'hoursOrAmount' => 20,
			'drawdownRate' => 75,
			'drawdownAmount' => 1500,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canMaterializeDrawdown(drawdownId: 'DD-1', object: $drawdown));

	}//end testConsistentDrawdownMaterializesWithoutPool()

	/**
	 * A drawdown whose amount does NOT equal hoursOrAmount × drawdownRate is
	 * rejected (REQ-RETN-002 fail-closed).
	 *
	 * @return void
	 */
	public function testInconsistentDrawdownCannotMaterialize(): void {
		$drawdown = [
			'poolId' => 'RETN-2026-01-001',
			'hoursOrAmount' => 20,
			'drawdownRate' => 75,
			'drawdownAmount' => 1400,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canMaterializeDrawdown(drawdownId: 'DD-1', object: $drawdown));

	}//end testInconsistentDrawdownCannotMaterialize()

	/**
	 * A drawdown whose recorded rate diverges from the pool's configured
	 * retainerRate is rejected — rate immutability (REQ-RETN-002 / design D2).
	 *
	 * @return void
	 */
	public function testDrawdownRateMustMatchPoolRate(): void {
		// Pool lookup returns a pool with a different retainerRate.
		//
		// ⚠️ The row carries `id` deliberately. `drawdown.poolId` is a foreign
		// key onto the pool's IDENTIFIER, and the guard resolves it by
		// identity. The fixture used to declare only `poolId`, which no
		// identity lookup can answer — it passed solely because this store's
		// `findAll()` returns its rows whatever it is asked, i.e. the test was
		// green over a lookup that could never work in production.
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$this->stubObjectService(
			rows: [
				['id' => 'RETN-2026-01-001', 'poolId' => 'RETN-2026-01-001', 'retainerRate' => 100],
			]
		);

		$drawdown = [
			'poolId' => 'RETN-2026-01-001',
			'hoursOrAmount' => 20,
			'drawdownRate' => 75,
			'drawdownAmount' => 1500,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canMaterializeDrawdown(drawdownId: 'DD-1', object: $drawdown));

	}//end testDrawdownRateMustMatchPoolRate()

	/**
	 * The rate-immutability check must be REACHED, not merely present.
	 *
	 * 🔑 This is the assertion `testDrawdownRateMustMatchPoolRate` above cannot
	 * make on its own. `canMaterializeDrawdown()` guards the comparison with
	 * `if ($pool !== null …)` and falls through to `return true` otherwise —
	 * a deliberate, narrow concession for a pool that lives in another app.
	 * With the pool lookup written as `filters['id']` that concession applied
	 * to 100% of calls against real OpenRegister, so the guard FAILED OPEN and
	 * every divergent rate was accepted. A store that answers by identity is
	 * the only way to tell the two apart: the matching-rate case must pass and
	 * the divergent one must fail, over the SAME store.
	 *
	 * @return void
	 */
	public function testDrawdownMatchingThePoolRateMayMaterialize(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$this->stubObjectService(
			rows: [
				['id' => 'RETN-2026-01-001', 'poolId' => 'RETN-2026-01-001', 'retainerRate' => 75],
			]
		);

		$drawdown = [
			'poolId' => 'RETN-2026-01-001',
			'hoursOrAmount' => 20,
			'drawdownRate' => 75,
			'drawdownAmount' => 1500,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canMaterializeDrawdown(drawdownId: 'DD-1', object: $drawdown));

	}//end testDrawdownMatchingThePoolRateMayMaterialize()

	/**
	 * A true-up with a recorded approver may be approved (REQ-RETN-011).
	 *
	 * @return void
	 */
	public function testTrueUpWithApproverCanApprove(): void {
		$trueUp = ['trueUpId' => 'TU-1', 'approvedBy' => 'director-1'];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canApproveTrueUp(trueUpId: 'TU-1', object: $trueUp));

	}//end testTrueUpWithApproverCanApprove()

	/**
	 * A true-up without a recorded approver cannot be approved
	 * (REQ-RETN-011 fail-closed).
	 *
	 * @return void
	 */
	public function testTrueUpWithoutApproverCannotApprove(): void {
		$trueUp = ['trueUpId' => 'TU-1', 'approvedBy' => ''];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApproveTrueUp(trueUpId: 'TU-1', object: $trueUp));

	}//end testTrueUpWithoutApproverCannotApprove()
}//end class
