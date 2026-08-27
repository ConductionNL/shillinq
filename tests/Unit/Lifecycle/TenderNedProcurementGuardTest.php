<?php

/**
 * Unit tests for TenderNedProcurementGuard.
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
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\TenderNedProcurementGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for TenderNedProcurementGuard::canGunnen (REQ-002) and ::canAfronden (REQ-006).
 *
 * Covers:
 * - canGunnen: missing supplier denied; zero value denied; valid award permitted.
 * - canAfronden: no linked obligation permitted; approved eindoplevering permitted;
 *   no approved eindoplevering denied; non-approved eindoplevering denied.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class TenderNedProcurementGuardTest extends TestCase {

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
	 * @var TenderNedProcurementGuard
	 */
	private TenderNedProcurementGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new TenderNedProcurementGuard(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Build a fluent ObjectService stub returning the given OrderFulfilment records.
	 *
	 * @param array<int, array<string, mixed>> $records OrderFulfilment records.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $records): object {
		return new class($records) {
			/**
			 * Stubbed records.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			private array $records;

			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $records Records to return.
			 */
			public function __construct(array $records) {
				$this->records = $records;

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
			 * Return the stubbed records.
			 *
			 * @param array<string, mixed> $params Query parameters (unused).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return $this->records;
			}//end findAll()
		};

	}//end buildObjectServiceStub()

	/**
	 * The canGunnen check denies the award when no supplier is recorded (REQ-002).
	 *
	 * @return void
	 */
	public function testCanGunnenDeniesWithoutSupplier(): void {
		$this->assertFalse(
			$this->guard->canGunnen(['tenderId' => 'TN-1', 'contractValue' => 1000.0])
		);

	}//end testCanGunnenDeniesWithoutSupplier()

	/**
	 * The canGunnen check denies the award when the contract value is zero (REQ-002).
	 *
	 * @return void
	 */
	public function testCanGunnenDeniesWithZeroValue(): void {
		$this->assertFalse(
			$this->guard->canGunnen(
				['tenderId' => 'TN-1', 'awardedSupplier' => '76741850 Conduction B.V.', 'contractValue' => 0]
			)
		);

	}//end testCanGunnenDeniesWithZeroValue()

	/**
	 * The canGunnen check permits a complete award (REQ-002).
	 *
	 * @return void
	 */
	public function testCanGunnenPermitsValidAward(): void {
		$this->assertTrue(
			$this->guard->canGunnen(
				['tenderId' => 'TN-1', 'awardedSupplier' => '76741850 Conduction B.V.', 'contractValue' => 50000.0]
			)
		);

	}//end testCanGunnenPermitsValidAward()

	/**
	 * The canAfronden check permits completion when there is no linked obligation.
	 *
	 * @return void
	 */
	public function testCanAfrondenPermitsWithoutLinkedObligation(): void {
		$this->assertTrue($this->guard->canAfronden(['tenderId' => 'TN-1']));

	}//end testCanAfrondenPermitsWithoutLinkedObligation()

	/**
	 * The canAfronden check permits completion when an approved eindoplevering exists (REQ-006).
	 *
	 * @return void
	 */
	public function testCanAfrondenPermitsWithApprovedEindoplevering(): void {
		$stub = $this->buildObjectServiceStub(
			[['deliveryType' => 'eindoplevering', 'status' => 'completed', 'approved' => true]]
		);
		$this->container->method('get')->willReturn($stub);

		$this->assertTrue(
			$this->guard->canAfronden(['tenderId' => 'TN-1', 'commitmentId' => 'vpl-1'])
		);

	}//end testCanAfrondenPermitsWithApprovedEindoplevering()

	/**
	 * The canAfronden check denies completion when no eindoplevering exists (REQ-006).
	 *
	 * @return void
	 */
	public function testCanAfrondenDeniesWithoutEindoplevering(): void {
		$stub = $this->buildObjectServiceStub([]);
		$this->container->method('get')->willReturn($stub);

		$this->assertFalse(
			$this->guard->canAfronden(['tenderId' => 'TN-1', 'commitmentId' => 'vpl-1'])
		);

	}//end testCanAfrondenDeniesWithoutEindoplevering()

	/**
	 * The canAfronden check denies completion when the eindoplevering is not approved (REQ-006).
	 *
	 * @return void
	 */
	public function testCanAfrondenDeniesWhenEindopleveringNotApproved(): void {
		$stub = $this->buildObjectServiceStub(
			[['deliveryType' => 'eindoplevering', 'status' => 'in-progress', 'approved' => false]]
		);
		$this->container->method('get')->willReturn($stub);

		$this->assertFalse(
			$this->guard->canAfronden(['tenderId' => 'TN-1', 'commitmentId' => 'vpl-1'])
		);

	}//end testCanAfrondenDeniesWhenEindopleveringNotApproved()
}//end class
