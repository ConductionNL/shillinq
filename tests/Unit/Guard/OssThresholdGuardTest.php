<?php

/**
 * Unit tests for OssThresholdGuard.
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
 * @spec openspec/changes/bookkeeping-btw-oss-eu/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\OssThresholdGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers REQ-OSS-002 (threshold monitoring/opt-in) and REQ-OSS-009 (voluntary lock-in).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class OssThresholdGuardTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var OssThresholdGuard
	 */
	private OssThresholdGuard $guard;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new OssThresholdGuard($this->logger);

	}//end setUp()

	/**
	 * B2C-to-EU turnover sums only OSS-eligible, in-year, non-credit invoices (REQ-OSS-002).
	 *
	 * @return void
	 */
	public function testCurrentB2cEuTurnover(): void {
		$invoices = [
			['administrationId' => 'adm-1', 'invoiceDate' => '2026-03-01', 'netAmount' => 5000.0, 'ossContext' => ['ossEligible' => true]],
			['administrationId' => 'adm-1', 'invoiceDate' => '2026-06-01', 'netAmount' => 4200.0, 'ossContext' => ['ossEligible' => true]],
			// Domestic / non-OSS: no eligible context.
			['administrationId' => 'adm-1', 'invoiceDate' => '2026-06-01', 'netAmount' => 9000.0],
			// Other administration.
			['administrationId' => 'adm-2', 'invoiceDate' => '2026-06-01', 'netAmount' => 1000.0, 'ossContext' => ['ossEligible' => true]],
			// Wrong year.
			['administrationId' => 'adm-1', 'invoiceDate' => '2025-06-01', 'netAmount' => 1000.0, 'ossContext' => ['ossEligible' => true]],
			// Cancelled.
			[
				'administrationId' => 'adm-1',
				'invoiceDate' => '2026-06-01',
				'netAmount' => 1000.0,
				'state' => 'cancelled',
				'ossContext' => ['ossEligible' => true],
			],
			// Credit note.
			[
				'administrationId' => 'adm-1',
				'invoiceDate' => '2026-06-01',
				'netAmount' => 500.0,
				'documentType' => 'credit-note',
				'ossContext' => ['ossEligible' => true],
			],
		];

		self::assertSame(9200.0, $this->guard->currentB2cEuTurnover($invoices, 'adm-1', 2026));

	}//end testCurrentB2cEuTurnover()

	/**
	 * Evaluation warns within EUR 100 and blocks on crossing without registration (REQ-OSS-002).
	 *
	 * @return void
	 */
	public function testEvaluateWarnsAndBlocks(): void {
		// 9200 + 900 = 10100 -> block (crosses 10000).
		self::assertSame('block', $this->guard->evaluate(9200.0, 900.0, false));
		// 9800 + 500 = 10300 -> block.
		self::assertSame('block', $this->guard->evaluate(9800.0, 500.0, false));
		// 9920 + 50 = 9970 -> within warning band (>= 9900).
		self::assertSame('warning', $this->guard->evaluate(9920.0, 50.0, false));
		// 5000 + 500 = 5500 -> ok.
		self::assertSame('ok', $this->guard->evaluate(5000.0, 500.0, false));

	}//end testEvaluateWarnsAndBlocks()

	/**
	 * An active registration disables the threshold gate entirely (REQ-OSS-002).
	 *
	 * @return void
	 */
	public function testEvaluateRegisteredNeverBlocks(): void {
		self::assertSame('registered', $this->guard->evaluate(50000.0, 5000.0, true));

	}//end testEvaluateRegisteredNeverBlocks()

	/**
	 * Voluntary opt-in requires an identifier and an effective date (REQ-OSS-009).
	 *
	 * @return void
	 */
	public function testCanEnableVoluntary(): void {
		self::assertTrue($this->guard->canEnableVoluntary(['ossIdentifier' => 'NL1B01', 'effectiveDate' => '2026-07-01']));
		self::assertFalse($this->guard->canEnableVoluntary(['ossIdentifier' => 'NL1B01']));
		self::assertFalse($this->guard->canEnableVoluntary([]));

	}//end testCanEnableVoluntary()

	/**
	 * Voluntary registration cannot deregister before the lock-in end (REQ-OSS-009).
	 *
	 * @return void
	 */
	public function testCanDeregisterRespectsLockIn(): void {
		$voluntary = ['registrationStatus' => 'voluntaryBelowThreshold', 'voluntaryBelowThreshold' => true, 'lockInPeriodEndDate' => '2028-12-31'];
		// Mid lock-in -> blocked.
		self::assertFalse($this->guard->canDeregister($voluntary, '2026-09-15'));
		// After lock-in -> allowed.
		self::assertTrue($this->guard->canDeregister($voluntary, '2029-01-01'));

		// Non-voluntary (threshold-driven) registration may always deregister.
		$threshold = ['registrationStatus' => 'active', 'voluntaryBelowThreshold' => false];
		self::assertTrue($this->guard->canDeregister($threshold, '2026-09-15'));

	}//end testCanDeregisterRespectsLockIn()

	/**
	 * Voluntary registration without a recorded lock-in end fails safe (blocks).
	 *
	 * @return void
	 */
	public function testCanDeregisterFailsSafeWithoutLockEnd(): void {
		$this->logger->expects(self::once())->method('warning');
		$voluntary = ['registrationStatus' => 'voluntaryBelowThreshold', 'voluntaryBelowThreshold' => true];
		self::assertFalse($this->guard->canDeregister($voluntary, '2026-09-15'));

	}//end testCanDeregisterFailsSafeWithoutLockEnd()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
