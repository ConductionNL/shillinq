<?php

/**
 * Unit tests for ConsolidationGuard.
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
 * @spec openspec/changes/bookkeeping-consolidation-commercial/specs/bookkeeping-consolidation-commercial/index.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\ConsolidationGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ConsolidationGuard lifecycle preconditions.
 *
 * Covers REQ-CONS-001 (group/entity activation + method consistency),
 * REQ-CONS-002 (balance-sheet equation), REQ-CONS-003/008 (elimination
 * approval/rejection + balanced lines), and REQ-CONS-006 (net-profit split).
 * All guards fail closed; inline-object cases never touch the container.
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

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new ConsolidationGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * A group with a parent administration may be activated (REQ-CONS-001).
	 *
	 * @return void
	 */
	public function testGroupWithParentCanActivate(): void {
		$object = ['parentAdministrationId' => 'holding-bv'];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canActivateGroup(groupId: 'g-1', object: $object));

	}//end testGroupWithParentCanActivate()

	/**
	 * A group without a parent administration cannot be activated (REQ-CONS-001).
	 *
	 * @return void
	 */
	public function testGroupWithoutParentCannotActivate(): void {
		$object = ['parentAdministrationId' => '   '];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canActivateGroup(groupId: 'g-2', object: $object));

	}//end testGroupWithoutParentCannotActivate()

	/**
	 * A controlling holding consolidated integrally is consistent (design D2).
	 *
	 * @return void
	 */
	public function testIntegralMethodConsistentWithControl(): void {
		$object = ['consolidationMethod' => 'integral', 'ownershipPercentage' => 100];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canActivateEntity(entityId: 'e-1', object: $object));

	}//end testIntegralMethodConsistentWithControl()

	/**
	 * Integral consolidation of a non-controlling stake is rejected (design D2).
	 *
	 * @return void
	 */
	public function testIntegralMethodWithMinorityStakeRejected(): void {
		$object = ['consolidationMethod' => 'integral', 'ownershipPercentage' => 30];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canActivateEntity(entityId: 'e-2', object: $object));

	}//end testIntegralMethodWithMinorityStakeRejected()

	/**
	 * A <50% associate consolidated by the equity method is consistent (D2).
	 *
	 * @return void
	 */
	public function testEquityMethodConsistentWithAssociate(): void {
		$object = ['consolidationMethod' => 'equity', 'ownershipPercentage' => 30];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canActivateEntity(entityId: 'e-3', object: $object));

	}//end testEquityMethodConsistentWithAssociate()

	/**
	 * A 50% joint venture consolidated proportionally is consistent (D2).
	 *
	 * @return void
	 */
	public function testProportionalMethodConsistentWithJointVenture(): void {
		$object = ['consolidationMethod' => 'proportional', 'ownershipPercentage' => 50];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canActivateEntity(entityId: 'e-4', object: $object));

	}//end testProportionalMethodConsistentWithJointVenture()

	/**
	 * A period may start elimination once group + dates are present (REQ-CONS-002).
	 *
	 * @return void
	 */
	public function testStartEliminationRequiresGroupAndDates(): void {
		$ok = [
			'consolidationGroupId' => 'g-1',
			'periodStart' => '2025-01-01',
			'periodEnd' => '2025-12-31',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canStartElimination(periodId: 'p-1', object: $ok));

		$missing = ['consolidationGroupId' => 'g-1', 'periodStart' => '', 'periodEnd' => '2025-12-31'];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canStartElimination(periodId: 'p-2', object: $missing));

	}//end testStartEliminationRequiresGroupAndDates()

	/**
	 * A period with eliminations and no pending mismatch may go to review
	 * (REQ-CONS-008).
	 *
	 * @return void
	 */
	public function testSubmitForReviewRequiresEliminationsAndNoPendingMismatch(): void {
		$ok = [
			'totalEliminationCount' => 3,
			'mismatches' => [
				['intercompanyRelationId' => 'icr-1', 'status' => 'overridden'],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canSubmitForReview(periodId: 'p-3', object: $ok));

		$pending = [
			'totalEliminationCount' => 3,
			'mismatches' => [
				['intercompanyRelationId' => 'icr-2', 'status' => 'pending'],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmitForReview(periodId: 'p-4', object: $pending));

	}//end testSubmitForReviewRequiresEliminationsAndNoPendingMismatch()

	/**
	 * A period with no eliminations cannot go to review (REQ-CONS-008).
	 *
	 * @return void
	 */
	public function testSubmitForReviewWithoutEliminationsDenied(): void {
		$object = ['totalEliminationCount' => 0, 'mismatches' => []];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmitForReview(periodId: 'p-5', object: $object));

	}//end testSubmitForReviewWithoutEliminationsDenied()

	/**
	 * A balanced elimination with a reviewer may be approved (REQ-CONS-003/008).
	 *
	 * @return void
	 */
	public function testApproveEliminationRequiresReviewerAndBalancedLines(): void {
		$ok = [
			'reviewedBy' => 'accountant-1',
			'lines' => [
				['accountNumber' => '8200', 'debit' => 100000, 'credit' => 0],
				['accountNumber' => '7200', 'debit' => 0, 'credit' => 100000],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canApproveElimination(entryId: 'el-1', object: $ok));

	}//end testApproveEliminationRequiresReviewerAndBalancedLines()

	/**
	 * An unbalanced elimination cannot be approved (REQ-CONS-003).
	 *
	 * @return void
	 */
	public function testApproveUnbalancedEliminationDenied(): void {
		$object = [
			'reviewedBy' => 'accountant-1',
			'lines' => [
				['accountNumber' => '8200', 'debit' => 100000, 'credit' => 0],
				['accountNumber' => '7200', 'debit' => 0, 'credit' => 99500],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApproveElimination(entryId: 'el-2', object: $object));

	}//end testApproveUnbalancedEliminationDenied()

	/**
	 * An elimination without a reviewer cannot be approved (REQ-CONS-008).
	 *
	 * @return void
	 */
	public function testApproveEliminationWithoutReviewerDenied(): void {
		$object = [
			'reviewedBy' => '',
			'lines' => [
				['accountNumber' => '8200', 'debit' => 100, 'credit' => 0],
				['accountNumber' => '7200', 'debit' => 0, 'credit' => 100],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApproveElimination(entryId: 'el-3', object: $object));

	}//end testApproveEliminationWithoutReviewerDenied()

	/**
	 * A rejection needs both a reviewer and a rationale (REQ-CONS-008).
	 *
	 * @return void
	 */
	public function testRejectEliminationRequiresReviewerAndComment(): void {
		$ok = ['reviewedBy' => 'accountant-1', 'reviewComment' => 'Was an external sale.'];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canRejectElimination(entryId: 'el-4', object: $ok));

		$noComment = ['reviewedBy' => 'accountant-1', 'reviewComment' => ''];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canRejectElimination(entryId: 'el-5', object: $noComment));

	}//end testRejectEliminationRequiresReviewerAndComment()

	/**
	 * A balanced consolidated balance may be finalised (REQ-CONS-002).
	 *
	 * @return void
	 */
	public function testFinalizeBalanceRequiresBalanceSheetEquation(): void {
		$ok = ['totalAssets' => 1000000, 'totalLiabilities' => 600000, 'totalEquity' => 400000];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canFinalizeBalance(balanceId: 'cb-1', object: $ok));

		$broken = ['totalAssets' => 1000000, 'totalLiabilities' => 600000, 'totalEquity' => 350000];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canFinalizeBalance(balanceId: 'cb-2', object: $broken));

	}//end testFinalizeBalanceRequiresBalanceSheetEquation()

	/**
	 * A reconciling profit split may finalise the income statement (REQ-CONS-006).
	 *
	 * @return void
	 */
	public function testFinalizeIncomeStatementRequiresProfitSplit(): void {
		$ok = [
			'netProfitTotal' => 100000,
			'netProfitAttributedToParent' => 70000,
			'netProfitAttributedToMinority' => 30000,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canFinalizeIncomeStatement(statementId: 'is-1', object: $ok));

		$broken = [
			'netProfitTotal' => 100000,
			'netProfitAttributedToParent' => 70000,
			'netProfitAttributedToMinority' => 25000,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canFinalizeIncomeStatement(statementId: 'is-2', object: $broken));

	}//end testFinalizeIncomeStatementRequiresProfitSplit()
}//end class
