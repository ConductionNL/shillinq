<?php

/**
 * Unit tests for PensionIas19Guard.
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
 * @spec openspec/changes/bookkeeping-pension-ias19/specs/bookkeeping-pension-ias19/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\PensionIas19Guard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PensionIas19Guard lifecycle preconditions.
 *
 * Covers REQ-PEN-001 (PUC method for DB), REQ-PEN-002 (discount-rate source),
 * REQ-PEN-008 (DC plans carry no DBO) and REQ-PEN-010 (HRMQ roster reconciled
 * before lock). All guards fail closed; inline-object cases never touch the
 * container.
 */
class PensionIas19GuardTest extends TestCase {

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
	 * @var PensionIas19Guard
	 */
	private PensionIas19Guard $guard;

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

		$this->guard = new PensionIas19Guard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * A complete DB plan may be activated (REQ-PEN-001).
	 *
	 * @return void
	 */
	public function testCompleteDbPlanCanActivate(): void {
		$object = [
			'planType' => 'DB',
			'regulatoryFramework' => 'PensionsAct',
			'pensionableSalaryDefinition' => 'Bruto jaarsalaris minus AOW-franchise',
			'accrualRate' => 0.01875,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canActivatePlan(planId: 'plan-1', object: $object));

	}//end testCompleteDbPlanCanActivate()

	/**
	 * A DB plan without an accrual rate cannot be activated (REQ-PEN-001).
	 *
	 * @return void
	 */
	public function testDbPlanWithoutAccrualRateCannotActivate(): void {
		$object = [
			'planType' => 'DB',
			'regulatoryFramework' => 'PensionsAct',
			'pensionableSalaryDefinition' => 'Bruto jaarsalaris',
			'accrualRate' => 0,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canActivatePlan(planId: 'plan-2', object: $object));

	}//end testDbPlanWithoutAccrualRateCannotActivate()

	/**
	 * A DC plan needs no accrual rate to activate, only type, framework and
	 * salary definition (REQ-PEN-001 / REQ-PEN-008).
	 *
	 * @return void
	 */
	public function testDcPlanCanActivateWithoutAccrualRate(): void {
		$object = [
			'planType' => 'DC',
			'regulatoryFramework' => 'PensionsAct',
			'pensionableSalaryDefinition' => 'Bruto jaarsalaris',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canActivatePlan(planId: 'plan-3', object: $object));

	}//end testDcPlanCanActivateWithoutAccrualRate()

	/**
	 * A plan missing its pensionable-salary definition cannot activate
	 * (REQ-PEN-001 fail-closed).
	 *
	 * @return void
	 */
	public function testPlanMissingSalaryDefinitionCannotActivate(): void {
		$object = [
			'planType' => 'DC',
			'regulatoryFramework' => 'PensionsAct',
			'pensionableSalaryDefinition' => '   ',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canActivatePlan(planId: 'plan-4', object: $object));

	}//end testPlanMissingSalaryDefinitionCannotActivate()

	/**
	 * A complete PUC valuation with a discount-rate source may be approved
	 * (REQ-PEN-001 / REQ-PEN-002).
	 *
	 * @return void
	 */
	public function testCompletePucValuationCanApprove(): void {
		$object = [
			'methodology' => 'PUC',
			'discountRateSource' => 'iBoxx EUR AA 15-20Y on 2026-12-31: 2.03%',
			'dboGross' => 8000000.0,
			'mortalityTable' => 'AG-Prognosetafel 2026',
			'salaryGrowthAssumption' => 2.5,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canApproveValuation(valuationId: 'val-1', object: $object));

	}//end testCompletePucValuationCanApprove()

	/**
	 * A DB valuation declaring a non-PUC methodology is rejected (REQ-PEN-001).
	 *
	 * @return void
	 */
	public function testNonPucMethodologyRejected(): void {
		$object = [
			'methodology' => 'average-salary',
			'discountRateSource' => 'iBoxx EUR AA',
			'dboGross' => 8000000.0,
			'mortalityTable' => 'AG-2026',
			'salaryGrowthAssumption' => 2.5,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApproveValuation(valuationId: 'val-2', object: $object));

	}//end testNonPucMethodologyRejected()

	/**
	 * A valuation without a discount-rate source cannot be approved
	 * (REQ-PEN-002 fail-closed).
	 *
	 * @return void
	 */
	public function testValuationWithoutDiscountRateSourceCannotApprove(): void {
		$object = [
			'methodology' => 'PUC',
			'discountRateSource' => '',
			'dboGross' => 8000000.0,
			'mortalityTable' => 'AG-2026',
			'salaryGrowthAssumption' => 2.5,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApproveValuation(valuationId: 'val-3', object: $object));

	}//end testValuationWithoutDiscountRateSourceCannotApprove()

	/**
	 * A DC valuation that carries a DBO is rejected — DC plans have no DBO
	 * measurement (REQ-PEN-008).
	 *
	 * @return void
	 */
	public function testDcValuationWithDboRejected(): void {
		$object = [
			'methodology' => 'DC',
			'discountRateSource' => 'n/a',
			'dboGross' => 100000.0,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApproveValuation(valuationId: 'val-4', object: $object));

	}//end testDcValuationWithDboRejected()

	/**
	 * A DC valuation with a zero DBO and a recorded source may be approved
	 * (REQ-PEN-008).
	 *
	 * @return void
	 */
	public function testDcValuationWithoutDboCanApprove(): void {
		$object = [
			'methodology' => 'DC',
			'discountRateSource' => 'n/a',
			'dboGross' => 0.0,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canApproveValuation(valuationId: 'val-5', object: $object));

	}//end testDcValuationWithoutDboCanApprove()

	/**
	 * An approved DB valuation with a reconciled roster and an approver may lock
	 * (REQ-PEN-002 / REQ-PEN-010).
	 *
	 * @return void
	 */
	public function testApprovedValuationWithReconciledRosterCanLock(): void {
		$object = [
			'methodology' => 'PUC',
			'approvedBy' => 'controller@example.com',
			'rosterReconciled' => true,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canLockValuation(valuationId: 'val-6', object: $object));

	}//end testApprovedValuationWithReconciledRosterCanLock()

	/**
	 * A DB valuation whose HRMQ roster is not reconciled cannot lock
	 * (REQ-PEN-010 fail-closed).
	 *
	 * @return void
	 */
	public function testValuationWithoutReconciledRosterCannotLock(): void {
		$object = [
			'methodology' => 'PUC',
			'approvedBy' => 'controller@example.com',
			'rosterReconciled' => false,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canLockValuation(valuationId: 'val-7', object: $object));

	}//end testValuationWithoutReconciledRosterCannotLock()

	/**
	 * A valuation without an approver cannot lock (REQ-PEN-002 fail-closed).
	 *
	 * @return void
	 */
	public function testValuationWithoutApproverCannotLock(): void {
		$object = [
			'methodology' => 'PUC',
			'approvedBy' => '',
			'rosterReconciled' => true,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canLockValuation(valuationId: 'val-8', object: $object));

	}//end testValuationWithoutApproverCannotLock()

	/**
	 * A DC valuation may lock with only an approver — no roster dependency
	 * (REQ-PEN-008 / REQ-PEN-010).
	 *
	 * @return void
	 */
	public function testDcValuationLocksWithoutRoster(): void {
		$object = [
			'methodology' => 'DC',
			'approvedBy' => 'controller@example.com',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canLockValuation(valuationId: 'val-9', object: $object));

	}//end testDcValuationLocksWithoutRoster()

	/**
	 * A null object resolves to a fail-closed denial across all guards
	 * (CWE-863).
	 *
	 * @return void
	 */
	public function testNullObjectFailsClosed(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('no service'));

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canActivatePlan(planId: 'plan-x'));
		self::assertFalse($this->guard->canApproveValuation(valuationId: 'val-x'));
		self::assertFalse($this->guard->canLockValuation(valuationId: 'val-x'));
		// phpcs:enable CustomSniffs.Functions.NamedParameters

	}//end testNullObjectFailsClosed()
}//end class
