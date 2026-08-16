<?php

/**
 * Unit tests for DualGaapGuard.
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
 * @spec openspec/changes/bookkeeping-ifrs-rj-dual-gaap/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\DualGaapGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DualGaapGuard lifecycle preconditions.
 *
 * Covers REQ-DGAAP-003 / REQ-DGAAP-006 (DualTransaction reconcile requires a reason
 * code and, for temporary differences, a deferred-tax effect) and REQ-DGAAP-010
 * (FrameworkElection activate requires motivation + AVA reference + a variant
 * consistent with measured size). All guards fail closed; inline-object cases never
 * touch the container.
 */
class DualGaapGuardTest extends TestCase {

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
	 * @var DualGaapGuard
	 */
	private DualGaapGuard $guard;

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

		$this->guard = new DualGaapGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * A temporary divergence with a reason code and a deferred-tax effect may be
	 * reconciled (REQ-DGAAP-003 / REQ-DGAAP-006).
	 *
	 * @return void
	 */
	public function testTemporaryWithReasonAndTaxCanReconcile(): void {
		$object = [
			'divergenceReasonCode' => 'LEASE_IFRS16',
			'divergenceClassification' => 'temporary',
			'deferredTaxEffect' => 6075.0,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canReconcileTransaction(transactionId: 'dt-1', object: $object));

	}//end testTemporaryWithReasonAndTaxCanReconcile()

	/**
	 * A temporary divergence missing its deferred-tax effect cannot be reconciled
	 * (REQ-DGAAP-006 fail-closed).
	 *
	 * @return void
	 */
	public function testTemporaryWithoutTaxCannotReconcile(): void {
		$object = [
			'divergenceReasonCode' => 'LEASE_IFRS16',
			'divergenceClassification' => 'temporary',
			'deferredTaxEffect' => null,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canReconcileTransaction(transactionId: 'dt-2', object: $object));

	}//end testTemporaryWithoutTaxCannotReconcile()

	/**
	 * A temporary divergence with a zero deferred-tax effect cannot be reconciled
	 * (REQ-DGAAP-006).
	 *
	 * @return void
	 */
	public function testTemporaryWithZeroTaxCannotReconcile(): void {
		$object = [
			'divergenceReasonCode' => 'ECL_IFRS9',
			'divergenceClassification' => 'temporary',
			'deferredTaxEffect' => 0.0,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canReconcileTransaction(transactionId: 'dt-3', object: $object));

	}//end testTemporaryWithZeroTaxCannotReconcile()

	/**
	 * A permanent difference needs no tax effect to reconcile (REQ-DGAAP-006).
	 *
	 * @return void
	 */
	public function testPermanentDifferenceCanReconcileWithoutTax(): void {
		$object = [
			'divergenceReasonCode' => 'IMPAIRMENT_IAS36',
			'divergenceClassification' => 'permanent',
			'deferredTaxEffect' => null,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canReconcileTransaction(transactionId: 'dt-4', object: $object));

	}//end testPermanentDifferenceCanReconcileWithoutTax()

	/**
	 * A transaction with no reason code cannot be reconciled (REQ-DGAAP-003).
	 *
	 * @return void
	 */
	public function testNoReasonCodeCannotReconcile(): void {
		$object = [
			'divergenceReasonCode' => '',
			'divergenceClassification' => 'permanent',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canReconcileTransaction(transactionId: 'dt-5', object: $object));

	}//end testNoReasonCodeCannotReconcile()

	/**
	 * A complete election with a size-consistent variant may be activated
	 * (REQ-DGAAP-010).
	 *
	 * @return void
	 */
	public function testCompleteElectionCanActivate(): void {
		$object = [
			'complyOrExplainMotivation' => 'Kleine rechtspersoon BW2 art 2:396.',
			'avaDecisionReference' => 'AVA-2026-03-12-4',
			'rjVariant' => 'RJk',
			'sizeCriteriaBalanceSheetTotal' => 5800000.0,
			'sizeCriteriaNetRevenue' => 11200000.0,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canActivateElection(electionId: 'fe-1', object: $object));

	}//end testCompleteElectionCanActivate()

	/**
	 * An election missing the AVA-besluit reference cannot be activated
	 * (REQ-DGAAP-010 fail-closed).
	 *
	 * @return void
	 */
	public function testElectionWithoutAvaReferenceCannotActivate(): void {
		$object = [
			'complyOrExplainMotivation' => 'Kleine rechtspersoon.',
			'avaDecisionReference' => '',
			'rjVariant' => 'RJk',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canActivateElection(electionId: 'fe-2', object: $object));

	}//end testElectionWithoutAvaReferenceCannotActivate()

	/**
	 * An RJk (small-entity) election whose measured size exceeds the BW2 art 2:396
	 * ceilings is inconsistent and cannot be activated (REQ-DGAAP-010).
	 *
	 * @return void
	 */
	public function testRjkElectionOverSizeCeilingCannotActivate(): void {
		$object = [
			'complyOrExplainMotivation' => 'Claims small-entity status.',
			'avaDecisionReference' => 'AVA-2026-03-12-4',
			'rjVariant' => 'RJk',
			'sizeCriteriaBalanceSheetTotal' => 9000000.0,
			'sizeCriteriaNetRevenue' => 18000000.0,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canActivateElection(electionId: 'fe-3', object: $object));

	}//end testRjkElectionOverSizeCeilingCannotActivate()

	/**
	 * A full RJ-onverkort election imposes no size ceiling and may activate at any
	 * measured size (REQ-DGAAP-010).
	 *
	 * @return void
	 */
	public function testRjOnverkortElectionHasNoSizeCeiling(): void {
		$object = [
			'complyOrExplainMotivation' => 'Middelgrote rechtspersoon.',
			'avaDecisionReference' => 'AVA-2026-03-12-5',
			'rjVariant' => 'rj-in_full',
			'sizeCriteriaBalanceSheetTotal' => 25000000.0,
			'sizeCriteriaNetRevenue' => 60000000.0,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canActivateElection(electionId: 'fe-4', object: $object));

	}//end testRjOnverkortElectionHasNoSizeCeiling()
}//end class
