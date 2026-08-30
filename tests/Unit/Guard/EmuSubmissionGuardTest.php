<?php

/**
 * Unit tests for EmuSubmissionGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-emu-reporting/specs/bookkeeping-emu-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\EmuSubmissionGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the EMUReport submission approval gate (REQ-EMU-006).
 */
class EmuSubmissionGuardTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Guard under test.
	 *
	 * @var EmuSubmissionGuard
	 */
	private EmuSubmissionGuard $guard;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new EmuSubmissionGuard(logger: $this->logger);
	}//end setUp()

	/**
	 * A reviewed concept with computed saldo and passed reconciliation may submit.
	 *
	 * @return void
	 */
	public function testApprovedConceptMaySubmit(): void {
		self::assertTrue(
			$this->guard->requireApproval(
				[
					'status' => 'draft',
					'emuBalanceCalculated' => -2300000.0,
					'bbvReconciliationCheck' => 'succeeded',
				]
			)
		);
	}//end testApprovedConceptMaySubmit()

	/**
	 * An already-ingediend report is never re-submitted (idempotent).
	 *
	 * @return void
	 */
	public function testAlreadySubmittedIsBlocked(): void {
		$this->logger->expects(self::once())->method('info');
		self::assertFalse(
			$this->guard->requireApproval(
				['status' => 'submitted', 'emuBalanceCalculated' => -2300000.0, 'bbvReconciliationCheck' => 'succeeded']
			)
		);
	}//end testAlreadySubmittedIsBlocked()

	/**
	 * A concept without a computed saldo cannot be submitted.
	 *
	 * @return void
	 */
	public function testConceptWithoutSaldoIsBlocked(): void {
		$this->logger->expects(self::once())->method('info');
		self::assertFalse(
			$this->guard->requireApproval(['status' => 'draft', 'bbvReconciliationCheck' => 'succeeded'])
		);
	}//end testConceptWithoutSaldoIsBlocked()

	/**
	 * A failed reconciliation blocks submission (REQ-EMU-009).
	 *
	 * @return void
	 */
	public function testFailedReconciliationBlocks(): void {
		$this->logger->expects(self::once())->method('info');
		self::assertFalse(
			$this->guard->requireApproval(
				['status' => 'draft', 'emuBalanceCalculated' => 1.0, 'bbvReconciliationCheck' => 'failed']
			)
		);
	}//end testFailedReconciliationBlocks()

	/**
	 * A null computed saldo is treated as not-computed.
	 *
	 * @return void
	 */
	public function testNullSaldoIsBlocked(): void {
		$this->logger->expects(self::once())->method('info');
		self::assertFalse(
			$this->guard->requireApproval(
				['status' => 'draft', 'emuBalanceCalculated' => null, 'bbvReconciliationCheck' => 'succeeded']
			)
		);
	}//end testNullSaldoIsBlocked()
}//end class
