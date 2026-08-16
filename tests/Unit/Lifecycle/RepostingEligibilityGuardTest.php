<?php

/**
 * Unit tests for RepostingEligibilityGuard.
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
 * @spec openspec/changes/bookkeeping-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\RepostingEligibilityGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RepostingEligibilityGuard covering REQ-SDD-009 reason-code heuristic.
 */
class RepostingEligibilityGuardTest extends TestCase {

	/**
	 * The guard under test.
	 *
	 * @var RepostingEligibilityGuard
	 */
	private RepostingEligibilityGuard $guard;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$this->guard = new RepostingEligibilityGuard($this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * Insufficient funds (AM04) is a bank problem and may repost (REQ-SDD-009).
	 *
	 * @return void
	 */
	public function testInsufficientFundsCanRepost(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canRepost(reasonCode: 'AM04'));
		self::assertTrue($this->guard->canRepost(reasonCode: 'AC04'));
		self::assertTrue($this->guard->canRepost(reasonCode: 'ac01'));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end testInsufficientFundsCanRepost()

	/**
	 * Debtor refusals (MD01, MD06) may not repost (REQ-SDD-009).
	 *
	 * @return void
	 */
	public function testDebtorRefusalCannotRepost(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canRepost(reasonCode: 'MD01'));
		self::assertFalse($this->guard->canRepost(reasonCode: 'MD06'));
		self::assertTrue($this->guard->isDebtorRefusal('MD01'));
		self::assertFalse($this->guard->isDebtorRefusal('AM04'));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end testDebtorRefusalCannotRepost()

	/**
	 * An unknown or empty reason code fails closed (REQ-SDD-009 / CWE-863).
	 *
	 * @return void
	 */
	public function testUnknownReasonFailsClosed(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canRepost(reasonCode: 'ZZ99'));
		self::assertFalse($this->guard->canRepost(reasonCode: ''));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end testUnknownReasonFailsClosed()

	/**
	 * The reason code may be sourced from the collection object (REQ-SDD-009).
	 *
	 * @return void
	 */
	public function testReasonFromObject(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canRepost(reasonCode: '', object: ['pain002ReasonCode' => 'AM04']));
	}//end testReasonFromObject()
}//end class
