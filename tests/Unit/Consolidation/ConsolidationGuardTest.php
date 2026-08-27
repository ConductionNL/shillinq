<?php

/**
 * Unit tests for ConsolidationGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Consolidation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-financial-statements/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Consolidation;

use OCA\Shillinq\Service\ConsolidationGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ConsolidationGuard.
 *
 * Covers:
 * - requireFiscalPeriodClosed: missing fiscalYearId denies
 * - requireFiscalPeriodClosed: FiscalYear not found permits (T1/T2 deferral)
 * - requireFiscalPeriodClosed: closed FiscalYear permits
 * - requireFiscalPeriodClosed: open FiscalYear denies
 * - requireFiscalPeriodClosed: fail-closed on exception
 * - requireAllMembersFinalised: missing fields deny
 * - requireAllMembersFinalised: group not found permits (deferral)
 * - requireAllMembersFinalised: all members final permits
 * - requireAllMembersFinalised: one member not final denies
 * - requirePublicationApproval: always returns true
 *
 * @spec openspec/changes/bookkeeping-financial-statements/tasks.md#task-9
 */
class ConsolidationGuardTest extends TestCase {

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
	 * @var ConsolidationGuard
	 */
	private ConsolidationGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = $this->buildGuard(
			store: $this->buildObjectServiceStub(findReturn: null, findAllReturn: [])
		);

	}//end setUp()

	/**
	 * Build the guard over a seeded in-memory store.
	 *
	 * ADR-084 injects the ObjectService through the constructor, so a test's
	 * store has to be present when the guard is built — parking it on the
	 * container after the fact leaves the guard reading an empty world.
	 *
	 * @param object $store The duck-typed in-memory ObjectService double.
	 *
	 * @return ConsolidationGuard
	 */
	private function buildGuard(object $store): ConsolidationGuard {
		return new ConsolidationGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end buildGuard()

	/**
	 * RequireFiscalPeriodClosed denies when fiscalYearId is missing.
	 *
	 * @return void
	 */
	public function testRequireFiscalPeriodClosedDeniesMissingFiscalYearId(): void {
		$result = $this->guard->requireFiscalPeriodClosed(
			[
				'id' => 'bs-001',
				'administrationId' => 'adm-1',
			]
		);

		self::assertFalse(condition: $result, message: 'Missing fiscalYearId must deny finalise');

	}//end testRequireFiscalPeriodClosedDeniesMissingFiscalYearId()

	/**
	 * RequireFiscalPeriodClosed permits transition when FiscalYear schema is absent (T1/T2 deferral).
	 *
	 * @return void
	 */
	public function testRequireFiscalPeriodClosedPermitsWhenFiscalYearAbsent(): void {
		$objectService = $this->buildObjectServiceStub(findReturn: null, findAllReturn: []);
		$this->guard = $this->buildGuard(store: $objectService);

		$result = $this->guard->requireFiscalPeriodClosed(
			[
				'fiscalYearId' => 'fy-2026',
				'administrationId' => 'adm-1',
			]
		);

		self::assertTrue(condition: $result, message: 'FiscalYear absent (T1/T2): finalise must be permitted by default');

	}//end testRequireFiscalPeriodClosedPermitsWhenFiscalYearAbsent()

	/**
	 * RequireFiscalPeriodClosed permits when FiscalYear.isClosed is true.
	 *
	 * @return void
	 */
	public function testRequireFiscalPeriodClosedPermitsWhenClosed(): void {
		$fiscalYear = ['id' => 'fy-2026', 'isClosed' => true];
		$objectService = $this->buildObjectServiceStub(findReturn: $fiscalYear, findAllReturn: []);
		$this->guard = $this->buildGuard(store: $objectService);

		$result = $this->guard->requireFiscalPeriodClosed(
			[
				'fiscalYearId' => 'fy-2026',
				'administrationId' => 'adm-1',
			]
		);

		self::assertTrue(condition: $result, message: 'Closed FiscalYear must permit finalise');

	}//end testRequireFiscalPeriodClosedPermitsWhenClosed()

	/**
	 * RequireFiscalPeriodClosed denies when FiscalYear.isClosed is false.
	 *
	 * @return void
	 */
	public function testRequireFiscalPeriodClosedDenieswhenOpen(): void {
		$fiscalYear = ['id' => 'fy-2026', 'isClosed' => false];
		$objectService = $this->buildObjectServiceStub(findReturn: $fiscalYear, findAllReturn: []);
		$this->guard = $this->buildGuard(store: $objectService);

		$result = $this->guard->requireFiscalPeriodClosed(
			[
				'fiscalYearId' => 'fy-2026',
				'administrationId' => 'adm-1',
			]
		);

		self::assertFalse(condition: $result, message: 'Open FiscalYear must deny finalise');

	}//end testRequireFiscalPeriodClosedDenieswhenOpen()

	/**
	 * RequireFiscalPeriodClosed is fail-closed on exception.
	 *
	 * @return void
	 */
	public function testRequireFiscalPeriodClosedIsFailClosedOnException(): void {
		$this->guard = $this->buildGuard(store: $this->buildUnavailableObjectServiceStub());

		$result = $this->guard->requireFiscalPeriodClosed(
			[
				'fiscalYearId' => 'fy-2026',
				'administrationId' => 'adm-1',
			]
		);

		self::assertFalse(condition: $result, message: 'Exception must deny finalise (fail-closed)');

	}//end testRequireFiscalPeriodClosedIsFailClosedOnException()

	/**
	 * RequireAllMembersFinalised denies when required fields are missing.
	 *
	 * @return void
	 */
	public function testRequireAllMembersFinalisedDeniesMissingFields(): void {
		$result = $this->guard->requireAllMembersFinalised(['reportNumber' => 'CR-001']);

		self::assertFalse(condition: $result, message: 'Missing consolidationGroupId/fiscalYearId must deny');

	}//end testRequireAllMembersFinalisedDeniesMissingFields()

	/**
	 * RequireAllMembersFinalised permits when ConsolidationGroup not found (deferral).
	 *
	 * @return void
	 */
	public function testRequireAllMembersFinalisedPermitsWhenGroupAbsent(): void {
		$objectService = $this->buildObjectServiceStub(findReturn: null, findAllReturn: []);
		$this->guard = $this->buildGuard(store: $objectService);

		$result = $this->guard->requireAllMembersFinalised(
			[
				'consolidationGroupId' => 'cg-001',
				'fiscalYearId' => 'fy-2026',
			]
		);

		self::assertTrue(condition: $result, message: 'Absent ConsolidationGroup must permit by default (T2 deferral)');

	}//end testRequireAllMembersFinalisedPermitsWhenGroupAbsent()

	/**
	 * RequireAllMembersFinalised permits when all member administrations have a final BalanceSheet.
	 *
	 * @return void
	 */
	public function testRequireAllMembersFinalisedPermitsWhenAllMembersFinal(): void {
		$group = [
			'id' => 'cg-001',
			'administrationIds' => ['adm-1', 'adm-2'],
		];
		// Both administrations have a final BalanceSheet.
		$balanceSheet = [['id' => 'bs-001', 'status' => 'final']];
		$objectService = $this->buildObjectServiceStub(findReturn: $group, findAllReturn: $balanceSheet);
		$this->guard = $this->buildGuard(store: $objectService);

		$result = $this->guard->requireAllMembersFinalised(
			[
				'consolidationGroupId' => 'cg-001',
				'fiscalYearId' => 'fy-2026',
			]
		);

		self::assertTrue(condition: $result, message: 'All members final must permit consolidated report finalise');

	}//end testRequireAllMembersFinalisedPermitsWhenAllMembersFinal()

	/**
	 * RequireAllMembersFinalised denies when a member administration lacks a final BalanceSheet.
	 *
	 * @return void
	 */
	public function testRequireAllMembersFinalisedDeniesWhenMemberNotFinal(): void {
		$group = [
			'id' => 'cg-001',
			'administrationIds' => ['adm-1'],
		];
		// No final BalanceSheet for adm-1.
		$objectService = $this->buildObjectServiceStub(findReturn: $group, findAllReturn: []);
		$this->guard = $this->buildGuard(store: $objectService);

		$result = $this->guard->requireAllMembersFinalised(
			[
				'consolidationGroupId' => 'cg-001',
				'fiscalYearId' => 'fy-2026',
			]
		);

		self::assertFalse(condition: $result, message: 'Member without final BalanceSheet must deny consolidated report finalise');

	}//end testRequireAllMembersFinalisedDeniesWhenMemberNotFinal()

	/**
	 * RequirePublicationApproval always returns true (role enforcement via RBAC layer).
	 *
	 * @return void
	 */
	public function testRequirePublicationApprovalAlwaysReturnsTrue(): void {
		$result = $this->guard->requirePublicationApproval(['id' => 'bs-001', 'status' => 'final']);

		self::assertTrue(condition: $result, message: 'requirePublicationApproval must always permit (role check is in RBAC layer)');

	}//end testRequirePublicationApprovalAlwaysReturnsTrue()

	/**
	 * Build an anonymous ObjectService stub implementing the fluent setRegister/setSchema interface.
	 *
	 * The method under test resolves a single object with find(), which is the
	 * REAL OpenRegister ObjectService API and returns a ?ObjectEntity — an
	 * object exposing jsonSerialize(), not a bare array.
	 *
	 * This stub used to expose `findObject()` instead, a method OpenRegister
	 * has never had. That is why the production defect survived: the double
	 * invented the interface it was asserting against, so the guard passed its
	 * unit test while raising an Error against the real service (gate-20,
	 * .github#277). A stub must only offer methods the real collaborator has.
	 *
	 * @param array<string,mixed>|null $findReturn Row to wrap and return from find(), or null.
	 * @param array<mixed> $findAllReturn Value to return from findAll().
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(?array $findReturn, array $findAllReturn): object {
		return new class($findReturn, $findAllReturn) {
			/**
			 * Row to wrap and return from find().
			 *
			 * @var array<string,mixed>|null
			 */
			private ?array $findReturn;

			/**
			 * Return value for findAll().
			 *
			 * @var array<mixed>
			 */
			private array $findAllReturn;

			/**
			 * Construct the stub with fixed return values.
			 *
			 * @param array<string,mixed>|null $findReturn Row to wrap and return from find().
			 * @param array<mixed> $findAllReturn Value to return from findAll().
			 */
			public function __construct(?array $findReturn, array $findAllReturn) {
				$this->findReturn = $findReturn;
				$this->findAllReturn = $findAllReturn;
			}//end __construct()

			/**
			 * Fluent register setter — returns self.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — returns self.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Resolve one object by id — mirrors ObjectService::find(), which
			 * returns a ?ObjectEntity (an object with jsonSerialize()).
			 *
			 * @param string|int $id Object ID.
			 * @param array|null $_extend Unused, present to match the real signature.
			 * @param bool $files Unused, present to match the real signature.
			 * @param mixed $register Register slug or entity.
			 * @param mixed $schema Schema slug or entity.
			 *
			 * @return object|null
			 */
			public function find(
				string|int $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null,
			): ?object {
				if ($this->findReturn === null) {
					return null;
				}

				return new class($this->findReturn) {
					/**
					 * The wrapped row.
					 *
					 * @var array<string,mixed>
					 */
					private array $row;

					/**
					 * Wrap a row.
					 *
					 * @param array<string,mixed> $row The row.
					 */
					public function __construct(array $row) {
						$this->row = $row;
					}//end __construct()

					/**
					 * Serialise the wrapped row.
					 *
					 * @return array<string,mixed>
					 */
					public function jsonSerialize(): array {
						return $this->row;
					}//end jsonSerialize()
				};
			}//end find()

			/**
			 * Return the configured findAll return value.
			 *
			 * @param array<string,mixed> $params Filter params.
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return $this->findAllReturn;
			}//end findAll()
		};
	}//end buildObjectServiceStub()

	/**
	 * Build a store that models an unavailable OpenRegister.
	 *
	 * Before ADR-084 this scenario was expressed as
	 * `$container->method('get')->willThrowException(...)`. The container is no
	 * longer consulted, so the refusal has to come from the store itself; every
	 * read throws exactly as a downed ObjectService would, which is what the
	 * guard's fail-closed arm is there to catch.
	 *
	 * @return object
	 */
	private function buildUnavailableObjectServiceStub(): object {
		return new class {
			/**
			 * Fluent register setter — returns self.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — returns self.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Refuse every single-object lookup.
			 *
			 * @param string|int $id Object ID.
			 *
			 * @return object|null
			 *
			 * @throws \RuntimeException Always.
			 */
			public function find(string|int $id): ?object {
				throw new \RuntimeException('DB error');
			}//end find()

			/**
			 * Refuse every list query.
			 *
			 * @param array<string,mixed> $params Filter params.
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('DB error');
			}//end findAll()
		};
	}//end buildUnavailableObjectServiceStub()
}//end class
