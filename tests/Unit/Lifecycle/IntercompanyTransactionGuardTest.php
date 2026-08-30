<?php

/**
 * Unit tests for IntercompanyTransactionGuard.
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
 * @spec openspec/changes/bookkeeping-treasury-ihb/specs/bookkeeping-treasury-ihb/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\IntercompanyTransactionGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for IntercompanyTransactionGuard per REQ-IHB-004 and REQ-IHB-005.
 *
 * Covers:
 * - Posting denied when either administration's period is closed.
 * - Posting permitted when both administrations' periods are open.
 * - Posting permitted when the FiscalYear register is absent (T1 state).
 * - Posting denied (fail-closed) on missing posting date / administration.
 * - Loan activation permitted; high fixed rate logs an arm's-length warning.
 */
class IntercompanyTransactionGuardTest extends TestCase {

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
	 * @var IntercompanyTransactionGuard
	 */
	private IntercompanyTransactionGuard $guard;

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

		$this->guard = new IntercompanyTransactionGuard(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Build a fluent ObjectService stub returning FiscalYear rows keyed by administrationId.
	 *
	 * @param array<string, array<mixed>> $yearsByAdmin Map of administrationId → FiscalYear records.
	 * @param bool $throwOnFind Throw on findAll to simulate register absence.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $yearsByAdmin = [], bool $throwOnFind = false): object {
		return new class($yearsByAdmin, $throwOnFind) {
			/**
			 * FiscalYear records by administrationId.
			 *
			 * @var array<string, array<mixed>>
			 */
			private array $yearsByAdmin;

			/**
			 * Whether findAll should throw (register absent).
			 *
			 * @var boolean
			 */
			private bool $throwOnFind;

			/**
			 * Constructor.
			 *
			 * @param array<string, array<mixed>> $yearsByAdmin FiscalYear records by admin.
			 * @param bool $throwOnFind Throw on findAll.
			 */
			public function __construct(array $yearsByAdmin, bool $throwOnFind) {
				$this->yearsByAdmin = $yearsByAdmin;
				$this->throwOnFind = $throwOnFind;

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
				return $this;
			}//end setSchema()

			/**
			 * Return FiscalYear records for the administrationId filter.
			 *
			 * @param array<string, mixed> $params Query parameters.
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				if ($this->throwOnFind === true) {
					throw new \RuntimeException('FiscalYear register not present');
				}

				$adminId = (string)($params['filters']['administrationId'] ?? '');
				return $this->yearsByAdmin[$adminId] ?? [];
			}//end findAll()
		};

	}//end buildObjectServiceStub()

	/**
	 * A movement with malformed input (no posting date) is denied fail-closed.
	 *
	 * @return void
	 */
	public function testCanPostDeniesMissingPostingDate(): void {
		$object = [
			'fromAdministrationId' => 'adm-001',
			'toAdministrationId' => 'adm-002',
		];

		self::assertFalse(
			condition: $this->guard->canPost(transactionId: 'txn-1', object: $object),
			message: 'Movement without a posting date must be denied (fail-closed).'
		);

	}//end testCanPostDeniesMissingPostingDate()

	/**
	 * Posting is permitted when both administrations have an open period.
	 *
	 * @return void
	 */
	public function testCanPostPermittedWhenBothPeriodsOpen(): void {
		$stub = $this->buildObjectServiceStub(
			yearsByAdmin: [
				'adm-001' => [['state' => 'open', 'administrationId' => 'adm-001']],
				'adm-002' => [['state' => 'open', 'administrationId' => 'adm-002']],
			]
		);
		$this->container->method('get')->willReturn($stub);

		$object = [
			'postingDate' => '2026-04-30',
			'fromAdministrationId' => 'adm-001',
			'toAdministrationId' => 'adm-002',
		];

		self::assertTrue(
			condition: $this->guard->canPost(transactionId: 'txn-1', object: $object),
			message: 'Posting must be permitted when both periods are open.'
		);

	}//end testCanPostPermittedWhenBothPeriodsOpen()

	/**
	 * Posting is denied when the receiving administration's period is closed (REQ-IHB-005).
	 *
	 * @return void
	 */
	public function testCanPostDeniedWhenReceivingPeriodClosed(): void {
		$stub = $this->buildObjectServiceStub(
			yearsByAdmin: [
				'adm-001' => [['state' => 'open', 'administrationId' => 'adm-001']],
				'adm-002' => [['state' => 'closed', 'administrationId' => 'adm-002']],
			]
		);
		$this->container->method('get')->willReturn($stub);

		$object = [
			'postingDate' => '2026-04-30',
			'fromAdministrationId' => 'adm-001',
			'toAdministrationId' => 'adm-002',
		];

		self::assertFalse(
			condition: $this->guard->canPost(transactionId: 'txn-1', object: $object),
			message: 'Posting must be denied when the receiving administration period is closed (REQ-IHB-005).'
		);

	}//end testCanPostDeniedWhenReceivingPeriodClosed()

	/**
	 * Posting is permitted when the FiscalYear register is absent (T1 state).
	 *
	 * @return void
	 */
	public function testCanPostPermittedWhenFiscalYearRegisterAbsent(): void {
		$stub = $this->buildObjectServiceStub(throwOnFind: true);
		$this->container->method('get')->willReturn($stub);

		$object = [
			'postingDate' => '2026-04-30',
			'fromAdministrationId' => 'adm-001',
			'toAdministrationId' => 'adm-002',
		];

		self::assertTrue(
			condition: $this->guard->canPost(transactionId: 'txn-1', object: $object),
			message: 'Posting must be permitted when the FiscalYear register is not yet seeded (T1 state).'
		);

	}//end testCanPostPermittedWhenFiscalYearRegisterAbsent()

	/**
	 * Loan activation is always permitted; a high fixed rate logs an arm's-length warning.
	 *
	 * @return void
	 */
	public function testCanActivateLoanWarnsOnHighFixedRate(): void {
		$this->logger->expects(self::once())->method('warning');

		$object = [
			'rateType' => 'fixed',
			'fixedRate' => 0.09,
		];

		self::assertTrue(
			condition: $this->guard->canActivateLoan(loanId: 'loan-1', object: $object),
			message: 'Loan activation is permitted even above the arm\'s-length threshold (warning only).'
		);

	}//end testCanActivateLoanWarnsOnHighFixedRate()

	/**
	 * Loan activation at a market-comparable fixed rate does not warn.
	 *
	 * @return void
	 */
	public function testCanActivateLoanNoWarnOnMarketRate(): void {
		$this->logger->expects(self::never())->method('warning');

		$object = [
			'rateType' => 'fixed',
			'fixedRate' => 0.035,
		];

		self::assertTrue(
			condition: $this->guard->canActivateLoan(loanId: 'loan-1', object: $object),
			message: 'A market-comparable fixed rate must not raise an arm\'s-length warning.'
		);

	}//end testCanActivateLoanNoWarnOnMarketRate()
}//end class
