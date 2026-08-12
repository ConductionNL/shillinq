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

		$this->guard = new ExpenseClaimGuard(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

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
		$this->container->method('get')->willReturn($objectService);

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
		$this->container->method('get')->willReturn($objectService);

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
		$this->container->method('get')
			->willThrowException(new \RuntimeException('ObjectService unavailable'));

		$claim = ['id' => 'claim-1', 'receiptIds' => ['rec-1'], 'mileageIds' => [], 'perDiemIds' => []];

		$result = $this->guard->requireCostCentresAndItems(claim: $claim);

		self::assertFalse(condition: $result, message: 'Exception must deny submit (fail-closed)');

	}//end testRequireCostCentresAndItemsIsFailClosedOnException()

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

		$this->container->method('get')->willReturn($throwingStub);

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
		$this->container->method('get')->willReturn($objectService);

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
		$this->container->method('get')->willReturn($objectService);

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
}//end class
