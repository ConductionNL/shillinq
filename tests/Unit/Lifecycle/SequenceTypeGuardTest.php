<?php

/**
 * Unit tests for SequenceTypeGuard.
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

use OCA\Shillinq\Lifecycle\SequenceTypeGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SequenceTypeGuard covering REQ-SDD-002 sequence derivation and
 * REQ-SDD-008 collection eligibility.
 */
class SequenceTypeGuardTest extends TestCase {

	/**
	 * The guard under test.
	 *
	 * @var SequenceTypeGuard
	 */
	private SequenceTypeGuard $guard;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$this->guard = new SequenceTypeGuard($this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * A one-off mandate always derives OOFF (REQ-SDD-002).
	 *
	 * @return void
	 */
	public function testOneOffDerivesOoff(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertSame('OOFF', $this->guard->deriveSequenceType(mandate: ['type' => 'oneoff', 'status' => 'active']));
	}//end testOneOffDerivesOoff()

	/**
	 * A recurring mandate with no prior collections derives FRST (REQ-SDD-002).
	 *
	 * @return void
	 */
	public function testFirstRecurringDerivesFrst(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertSame('FRST', $this->guard->deriveSequenceType(mandate: ['type' => 'recurring', 'status' => 'active'], priorCollections: []));
	}//end testFirstRecurringDerivesFrst()

	/**
	 * A recurring mandate with a prior succeeded collection derives RCUR (REQ-SDD-002).
	 *
	 * @return void
	 */
	public function testSubsequentRecurringDerivesRcur(): void {
		$prior = [['status' => 'succeeded']];
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertSame('RCUR', $this->guard->deriveSequenceType(mandate: ['type' => 'recurring', 'status' => 'active'], priorCollections: $prior));
	}//end testSubsequentRecurringDerivesRcur()

	/**
	 * A prior collection that only ever rejected does not promote to RCUR (REQ-SDD-002).
	 *
	 * @return void
	 */
	public function testOnlyRejectedPriorStaysFrst(): void {
		$prior = [['status' => 'rejected'], ['status' => 'refunded']];
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertSame('FRST', $this->guard->deriveSequenceType(mandate: ['type' => 'recurring', 'status' => 'active'], priorCollections: $prior));
	}//end testOnlyRejectedPriorStaysFrst()

	/**
	 * A second collection against a one-off mandate is refused (REQ-SDD-002).
	 *
	 * @return void
	 */
	public function testOneOffSecondCollectionRefused(): void {
		$mandate = ['type' => 'oneoff', 'status' => 'active'];
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canScheduleCollection(mandate: $mandate, priorCollections: []));
		self::assertFalse($this->guard->canScheduleCollection(mandate: $mandate, priorCollections: [['status' => 'succeeded']]));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end testOneOffSecondCollectionRefused()

	/**
	 * Collections against cancelled/expired/suspended/pending mandates are refused (REQ-SDD-008).
	 *
	 * @return void
	 */
	public function testBlockingMandateStatesRefuseCollection(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		foreach (['cancelled', 'expired', 'suspended', 'pending'] as $state) {
			self::assertFalse(
				$this->guard->canScheduleCollection(mandate: ['type' => 'recurring', 'status' => $state]),
				"state $state must refuse a collection"
			);
		}

		self::assertTrue($this->guard->canScheduleCollection(mandate: ['type' => 'recurring', 'status' => 'active']));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end testBlockingMandateStatesRefuseCollection()
}//end class
