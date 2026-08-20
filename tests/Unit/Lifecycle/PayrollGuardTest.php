<?php

/**
 * Unit tests for PayrollGuard.
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
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\PayrollGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers REQ-PAY-004 (calculate precondition: deductions assigned + employee
 * active) and REQ-PAY-005 (issue precondition: annual income-tax within the
 * statutory ceiling). All collaborators are mocked; the guard's own
 * fail-closed behaviour is asserted, never a rigged mock outcome.
 */
class PayrollGuardTest extends TestCase {

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
	 * @var PayrollGuard
	 */
	private PayrollGuard $guard;

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

		// register -> shillinq; any ceiling key -> '' (use default fraction).
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				return ($key === 'register') ? 'shillinq' : '';
			}
		);

		$this->guard = new PayrollGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->buildObjectServiceStub([])),
		);

	}//end setUp()

	/**
	 * Rebuild the guard on a schema-aware ObjectService stub.
	 *
	 * The store is a constructor dependency since ADR-084, so the guard has to
	 * be rebuilt whenever a test seeds different records.
	 *
	 * @param array<string,array<mixed>> $recordsBySchema Records keyed by schema slug.
	 *
	 * @return void
	 */
	private function wireObjectService(array $recordsBySchema): void {
		$this->wireStore($this->buildObjectServiceStub($recordsBySchema));
	}//end wireObjectService()

	/**
	 * Point the guard at the given duck-typed ObjectService store.
	 *
	 * @param object $store The in-memory ObjectService double.
	 *
	 * @return void
	 */
	private function wireStore(object $store): void {
		$this->container->method('get')->willReturn($store);

		$this->guard = new PayrollGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);
	}//end wireStore()

	/**
	 * canCalculate returns true when the employee is active and at least one
	 * deduction is assigned (REQ-PAY-004).
	 *
	 * @return void
	 */
	public function testCanCalculateWhenEmployeeActiveAndDeductionsPresent(): void {
		$this->wireObjectService(
			[
				'Employee' => [['id' => 'emp-1', 'state' => 'active', 'exitDate' => null]],
				'Deduction' => [['id' => 'd-1', 'deductionType' => 'income-tax', 'amount' => 420.0]],
			]
		);

		$payroll = ['id' => 'pay-1', 'employeeId' => 'emp-1', 'periodStartDate' => '2026-05-01', 'grossAmount' => 3500.0];
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canCalculate(payrollId: 'pay-1', object: $payroll));
	}//end testCanCalculateWhenEmployeeActiveAndDeductionsPresent()

	/**
	 * canCalculate is denied when no deduction is assigned (REQ-PAY-004).
	 *
	 * @return void
	 */
	public function testCannotCalculateWithoutDeductions(): void {
		$this->wireObjectService(
			[
				'Employee' => [['id' => 'emp-1', 'state' => 'active', 'exitDate' => null]],
				'Deduction' => [],
			]
		);

		$payroll = ['id' => 'pay-1', 'employeeId' => 'emp-1', 'periodStartDate' => '2026-05-01', 'grossAmount' => 3500.0];
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canCalculate(payrollId: 'pay-1', object: $payroll));
	}//end testCannotCalculateWithoutDeductions()

	/**
	 * canCalculate is denied for an inactive employee (REQ-PAY-001).
	 *
	 * @return void
	 */
	public function testCannotCalculateForInactiveEmployee(): void {
		$this->wireObjectService(
			[
				'Employee' => [['id' => 'emp-1', 'state' => 'inactive', 'exitDate' => '2026-04-30']],
				'Deduction' => [['id' => 'd-1', 'deductionType' => 'income-tax', 'amount' => 420.0]],
			]
		);

		$payroll = ['id' => 'pay-1', 'employeeId' => 'emp-1', 'periodStartDate' => '2026-05-01', 'grossAmount' => 3500.0];
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canCalculate(payrollId: 'pay-1', object: $payroll));
	}//end testCannotCalculateForInactiveEmployee()

	/**
	 * canCalculate is denied when the period starts after the employee exit
	 * date, even if the employee record is still flagged active (REQ-PAY-001).
	 *
	 * @return void
	 */
	public function testCannotCalculateAfterExitDate(): void {
		$this->wireObjectService(
			[
				'Employee' => [['id' => 'emp-1', 'state' => 'active', 'exitDate' => '2026-04-30']],
				'Deduction' => [['id' => 'd-1', 'deductionType' => 'income-tax', 'amount' => 420.0]],
			]
		);

		$payroll = ['id' => 'pay-1', 'employeeId' => 'emp-1', 'periodStartDate' => '2026-05-01', 'grossAmount' => 3500.0];
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canCalculate(payrollId: 'pay-1', object: $payroll));
	}//end testCannotCalculateAfterExitDate()

	/**
	 * canIssue returns true when annual income-tax is within the statutory
	 * ceiling (default 52% of gross) (REQ-PAY-005).
	 *
	 * @return void
	 */
	public function testCanIssueWithinStatutoryCeiling(): void {
		$this->wireObjectService(
			[
				'Deduction' => [['id' => 'd-1', 'deductionType' => 'income-tax', 'amount' => 420.0]],
			]
		);

		$payroll = ['id' => 'pay-1', 'period' => '2026-05', 'grossAmount' => 3500.0];
		// 420 <= 0.52 * 3500 = 1820 -> allowed.
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canIssue(payrollId: 'pay-1', object: $payroll));
	}//end testCanIssueWithinStatutoryCeiling()

	/**
	 * canIssue is blocked when income-tax exceeds the statutory ceiling
	 * (REQ-PAY-005).
	 *
	 * @return void
	 */
	public function testCannotIssueWhenIncomeTaxExceedsCeiling(): void {
		$this->wireObjectService(
			[
				'Deduction' => [['id' => 'd-1', 'deductionType' => 'income-tax', 'amount' => 2000.0]],
			]
		);

		$payroll = ['id' => 'pay-1', 'period' => '2026-05', 'grossAmount' => 3500.0];
		// 2000 > 0.52 * 3500 = 1820 -> blocked.
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canIssue(payrollId: 'pay-1', object: $payroll));
	}//end testCannotIssueWhenIncomeTaxExceedsCeiling()

	/**
	 * Both guards fail closed when the ObjectService throws (CWE-863).
	 *
	 * @return void
	 */
	public function testFailsClosedOnServiceError(): void {
		$this->wireStore($this->buildFailingObjectServiceStub());

		$payroll = ['id' => 'pay-1', 'employeeId' => 'emp-1', 'period' => '2026-05', 'periodStartDate' => '2026-05-01', 'grossAmount' => 3500.0];
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canCalculate(payrollId: 'pay-1', object: $payroll));
		self::assertFalse($this->guard->canIssue(payrollId: 'pay-1', object: $payroll));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end testFailsClosedOnServiceError()

	/**
	 * Build a schema-aware anonymous ObjectService stub.
	 *
	 * The stub records the schema last set via setSchema() and returns the
	 * matching record list from findAll(), so a single instance can answer
	 * Employee, Deduction and Payroll lookups in one guard call.
	 *
	 * @param array<string,array<mixed>> $recordsBySchema Records keyed by schema slug.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $recordsBySchema): object {
		return new class($recordsBySchema) {
			/**
			 * Records keyed by schema slug.
			 *
			 * @var array<string,array<mixed>>
			 */
			private array $recordsBySchema;

			/**
			 * The schema slug last set.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<mixed>> $recordsBySchema Records keyed by schema slug.
			 */
			public function __construct(array $recordsBySchema) {
				$this->recordsBySchema = $recordsBySchema;
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
			 * Fluent schema setter; records the active schema.
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
			 * Return the stubbed records for the active schema.
			 *
			 * @param array<string,mixed> $params Query parameters (unused).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return ($this->recordsBySchema[$this->schema] ?? []);
			}//end findAll()
		};
	}//end buildObjectServiceStub()

	/**
	 * Build an ObjectService store that refuses every read.
	 *
	 * Since the store is injected rather than pulled from the container, an
	 * unavailable OpenRegister is modelled by a store that throws.
	 *
	 * @return object
	 */
	private function buildFailingObjectServiceStub(): object {
		return new class {

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (unused).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug (unused).
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Refuse the read, as an unavailable ObjectService would.
			 *
			 * @param array<string,mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('ObjectService unavailable');
			}//end findAll()
		};
	}//end buildFailingObjectServiceStub()
}//end class
