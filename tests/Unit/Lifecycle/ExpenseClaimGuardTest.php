<?php

/**
 * Unit tests for ExpenseClaimGuard.
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
 * @spec openspec/changes/expense-capture-core/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\ExpenseClaimGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ExpenseClaimGuard per REQ-EC-007.
 *
 * Covers:
 * - Empty claim denied on submit.
 * - Item missing costCentreCode denied on submit.
 * - All items with cost centres permitted on submit.
 * - FiscalYear register absent permits posting (T1 state).
 * - Open FiscalYear permits posting.
 * - Closed FiscalYear denies posting.
 * - Exception is fail-closed on submit.
 */
class ExpenseClaimGuardTest extends TestCase {

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
	 * @var ExpenseClaimGuard
	 */
	private ExpenseClaimGuard $guard;

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

		$this->guard = $this->buildGuard(store: $this->buildObjectServiceStub());

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
	 * @return ExpenseClaimGuard
	 */
	private function buildGuard(object $store): ExpenseClaimGuard {
		return new ExpenseClaimGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end buildGuard()

	/**
	 * Build a fluent ObjectService stub returning items by schema.
	 *
	 * @param array<string, array<mixed>> $itemsBySchema Map of schema → records.
	 * @param array<mixed> $fiscalYears FiscalYear records for period check.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $itemsBySchema = [], array $fiscalYears = []): object {
		return new class($itemsBySchema, $fiscalYears) {
			/**
			 * Map of schema name → record arrays.
			 *
			 * @var array<string, array<mixed>>
			 */
			private array $itemsBySchema;

			/**
			 * FiscalYear records.
			 *
			 * @var array<mixed>
			 */
			private array $fiscalYears;

			/**
			 * Currently active schema name.
			 *
			 * @var string
			 */
			public string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string, array<mixed>> $itemsBySchema Items by schema.
			 * @param array<mixed> $fiscalYears FiscalYear records.
			 */
			public function __construct(array $itemsBySchema, array $fiscalYears) {
				$this->itemsBySchema = $itemsBySchema;
				$this->fiscalYears = $fiscalYears;

			}//end __construct()

			/**
			 * Fluent register setter.
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
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return all stubbed records for the current schema.
			 *
			 * @param array<string, mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				if ($this->currentSchema === 'FiscalYear') {
					return $this->fiscalYears;
				}

				return $this->itemsBySchema[$this->currentSchema] ?? [];
			}//end findAll()

			/**
			 * Find a single record by ID in the current schema.
			 *
			 * @param string $id Record ID.
			 *
			 * @return array<mixed>|null Null when not found.
			 */
			public function find(string $id): ?array {
				$items = $this->itemsBySchema[$this->currentSchema] ?? [];
				foreach ($items as $item) {
					if (($item['id'] ?? '') === $id) {
						return $item;
					}
				}

				return null;
			}//end find()
		};

	}//end buildObjectServiceStub()

	/**
	 * Empty claim (no receipts, no mileage, no per-diem) is denied on submit.
	 *
	 * @return void
	 */
	public function testRequireCostCentresAndItemsDeniesEmptyClaim(): void {
		$claim = ['id' => 'claim-1', 'receiptIds' => [], 'mileageIds' => [], 'perDiemIds' => []];

		$result = $this->guard->requireCostCentresAndItems(claim: $claim);

		self::assertFalse(condition: $result, message: 'Empty claim must be denied');

	}//end testRequireCostCentresAndItemsDeniesEmptyClaim()

	/**
	 * Claim with a receipt missing costCentreCode is denied on submit.
	 *
	 * @return void
	 */
	public function testRequireCostCentresAndItemsDeniesItemMissingCostCentre(): void {
		$objectService = $this->buildObjectServiceStub(
			itemsBySchema: [
				'Receipt' => [['id' => 'rec-1', 'costCentreCode' => null]],
			]
		);
		$this->guard = $this->buildGuard(store: $objectService);

		$claim = ['id' => 'claim-1', 'receiptIds' => ['rec-1'], 'mileageIds' => [], 'perDiemIds' => []];

		$result = $this->guard->requireCostCentresAndItems(claim: $claim);

		self::assertFalse(condition: $result, message: 'Receipt missing costCentreCode must deny submit');

	}//end testRequireCostCentresAndItemsDeniesItemMissingCostCentre()

	/**
	 * Claim with all items having costCentreCode is permitted on submit.
	 *
	 * @return void
	 */
	public function testRequireCostCentresAndItemsPermitsWhenAllHaveCostCentre(): void {
		$objectService = $this->buildObjectServiceStub(
			itemsBySchema: [
				'Receipt' => [['id' => 'rec-1', 'costCentreCode' => 'CC100']],
				'MileageEntry' => [['id' => 'mlg-1', 'costCentreCode' => 'CC200']],
			]
		);
		$this->guard = $this->buildGuard(store: $objectService);

		$claim = ['id' => 'claim-1', 'receiptIds' => ['rec-1'], 'mileageIds' => ['mlg-1'], 'perDiemIds' => []];

		$result = $this->guard->requireCostCentresAndItems(claim: $claim);

		self::assertTrue(condition: $result, message: 'All items with costCentreCode must permit submit');

	}//end testRequireCostCentresAndItemsPermitsWhenAllHaveCostCentre()

	/**
	 * Exception from ObjectService is fail-closed — submit is denied.
	 *
	 * @return void
	 */
	public function testRequireCostCentresAndItemsIsFailClosedOnException(): void {
		$this->guard = $this->buildGuard(store: $this->buildUnavailableObjectServiceStub());

		$claim = ['id' => 'claim-1', 'receiptIds' => ['rec-1'], 'mileageIds' => [], 'perDiemIds' => []];

		$result = $this->guard->requireCostCentresAndItems(claim: $claim);

		self::assertFalse(condition: $result, message: 'Exception must deny submit (fail-closed)');

	}//end testRequireCostCentresAndItemsIsFailClosedOnException()

	/**
	 * The guard DISCRIMINATES: same guard, two claims, opposite verdicts.
	 *
	 * This exists because the individual permit/deny tests cannot, on their
	 * own, prove the guard is deciding anything. Before this repair the guard
	 * denied EVERYTHING -- `$item['costCentreCode']` on the ObjectEntityInterface
	 * that ADR-084 made find() return raised an Error, and
	 * requireOpenPeriodAndCostCentres()'s catch (\Throwable) converted it to
	 * `return false`. Every deny test therefore PASSED, for a reason that had
	 * nothing to do with cost centres, while every permit test failed.
	 *
	 * A guard that always denies and a guard that always permits are both
	 * broken, and each is invisible to half the suite. Asserting both verdicts
	 * from ONE guard instance in ONE test is the assertion neither half can
	 * satisfy vacuously: it fails if the guard reverts to always-deny, and it
	 * fails if the guard is ever loosened into always-permit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/expense-capture-core/tasks.md#task-9
	 */
	public function testRequireCostCentresAndItemsDiscriminatesBetweenClaims(): void {
		$objectService = $this->buildObjectServiceStub(
			itemsBySchema: [
				'Receipt' => [
					['id' => 'rec-ok', 'costCentreCode' => 'CC100'],
					['id' => 'rec-bad', 'costCentreCode' => ''],
				],
			]
		);
		$this->guard = $this->buildGuard(store: $objectService);

		$permitted = $this->guard->requireCostCentresAndItems(
			claim: ['id' => 'claim-ok', 'receiptIds' => ['rec-ok'], 'mileageIds' => [], 'perDiemIds' => []]
		);
		$denied = $this->guard->requireCostCentresAndItems(
			claim: ['id' => 'claim-bad', 'receiptIds' => ['rec-bad'], 'mileageIds' => [], 'perDiemIds' => []]
		);

		self::assertTrue(condition: $permitted, message: 'A claim whose items all carry a costCentreCode must be permitted');
		self::assertFalse(condition: $denied, message: 'A claim with an item lacking a costCentreCode must be denied');
		self::assertNotSame(
			expected: $permitted,
			actual: $denied,
			message: 'The guard must DISCRIMINATE: identical wiring, opposite verdicts. '
				. 'Equal verdicts here mean the guard is answering the same way regardless '
				. 'of the claim, which is what the ADR-084 array-access defect did.'
		);

	}//end testRequireCostCentresAndItemsDiscriminatesBetweenClaims()

	/**
	 * Posting is permitted when the FiscalYear register is absent (T1 state).
	 *
	 * @return void
	 */
	public function testRequireOpenPeriodPermitsPostingInT1State(): void {
		$objectService = $this->buildObjectServiceStub(
			itemsBySchema: [
				'Receipt' => [['id' => 'rec-1', 'costCentreCode' => 'CC100']],
			],
			fiscalYears: []
		);

		// FiscalYear schema throws to simulate T1 state (schema not yet seeded).
		$throwingStub = new class($objectService) {

			/**
			 * Delegate for non-FiscalYear schemas.
			 *
			 * @var object
			 */
			private object $delegate;

			/**
			 * Currently active schema name.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param object $delegate Delegate object service stub.
			 */
			public function __construct(object $delegate) {
				$this->delegate = $delegate;

			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — throws when schema is FiscalYear.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 *
			 * @throws \RuntimeException When schema is FiscalYear (T1 simulation).
			 */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				if ($schema === 'FiscalYear') {
					throw new \RuntimeException('FiscalYear schema not found');
				}

				$this->delegate->setSchema(schema: $schema);
				return $this;
			}//end setSchema()

			/**
			 * Return all records for current schema via delegate.
			 *
			 * @param array<string, mixed> $params Query parameters.
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return $this->delegate->findAll(params: $params);
			}//end findAll()

			/**
			 * Find record by ID via delegate.
			 *
			 * @param string $id Record ID.
			 *
			 * @return array<mixed>|null
			 */
			public function find(string $id): ?array {
				return $this->delegate->find(id: $id);
			}//end find()
		};

		$this->guard = $this->buildGuard(store: $throwingStub);

		$claim = [
			'id' => 'claim-1',
			'receiptIds' => ['rec-1'],
			'mileageIds' => [],
			'perDiemIds' => [],
			'fromDate' => '2026-06-01',
			'administrationId' => 'adm-1',
		];

		$result = $this->guard->requireOpenPeriodAndCostCentres(claim: $claim);

		self::assertTrue(condition: $result, message: 'T1: FiscalYear absent must permit posting');

	}//end testRequireOpenPeriodPermitsPostingInT1State()

	/**
	 * Open FiscalYear covering fromDate permits posting.
	 *
	 * @return void
	 */
	public function testRequireOpenPeriodPermitsPostingWhenFiscalYearIsOpen(): void {
		$objectService = $this->buildObjectServiceStub(
			itemsBySchema: [
				'Receipt' => [['id' => 'rec-1', 'costCentreCode' => 'CC100']],
			],
			fiscalYears: [
				['id' => 'fy-2026', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'state' => 'open'],
			]
		);
		$this->guard = $this->buildGuard(store: $objectService);

		$claim = [
			'id' => 'claim-1',
			'receiptIds' => ['rec-1'],
			'mileageIds' => [],
			'perDiemIds' => [],
			'fromDate' => '2026-06-01',
			'administrationId' => 'adm-1',
		];

		$result = $this->guard->requireOpenPeriodAndCostCentres(claim: $claim);

		self::assertTrue(condition: $result, message: 'Open FiscalYear must permit posting');

	}//end testRequireOpenPeriodPermitsPostingWhenFiscalYearIsOpen()

	/**
	 * Closed FiscalYear covering fromDate denies posting.
	 *
	 * @return void
	 */
	public function testRequireOpenPeriodDeniesPostingWhenFiscalYearIsClosed(): void {
		$objectService = $this->buildObjectServiceStub(
			itemsBySchema: [
				'Receipt' => [['id' => 'rec-1', 'costCentreCode' => 'CC100']],
			],
			fiscalYears: [
				['id' => 'fy-2026', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'state' => 'closed'],
			]
		);
		$this->guard = $this->buildGuard(store: $objectService);

		$claim = [
			'id' => 'claim-1',
			'receiptIds' => ['rec-1'],
			'mileageIds' => [],
			'perDiemIds' => [],
			'fromDate' => '2026-06-01',
			'administrationId' => 'adm-1',
		];

		$result = $this->guard->requireOpenPeriodAndCostCentres(claim: $claim);

		self::assertFalse(condition: $result, message: 'Closed FiscalYear must deny posting');

	}//end testRequireOpenPeriodDeniesPostingWhenFiscalYearIsClosed()

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
			 * Refuse every list query.
			 *
			 * @param array<string,mixed> $params Query parameters (unused).
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('ObjectService unavailable');
			}//end findAll()

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
				throw new \RuntimeException('ObjectService unavailable');
			}//end find()
		};
	}//end buildUnavailableObjectServiceStub()
}//end class
