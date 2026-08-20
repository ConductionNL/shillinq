<?php

/**
 * Unit tests for PreNotificationGuard.
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

use OCA\Shillinq\Lifecycle\PreNotificationGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PreNotificationGuard covering REQ-SDD-003 (proof + 14-day lead).
 */
class PreNotificationGuardTest extends TestCase {

	/**
	 * The guard under test.
	 *
	 * @var PreNotificationGuard
	 */
	private PreNotificationGuard $guard;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$this->guard = new PreNotificationGuard($this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * No pre-notification record at all blocks batch inclusion (REQ-SDD-003).
	 *
	 * @return void
	 */
	public function testMissingPreNotificationBlocks(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canIncludeInBatch(collection: ['requestedCollectionDate' => '2026-07-15'], preNotification: null));
	}//end testMissingPreNotificationBlocks()

	/**
	 * A notification sent with >= 14 days lead is accepted (REQ-SDD-003).
	 *
	 * Due 2026-07-15, sent 2026-07-01 = 14 days lead.
	 *
	 * @return void
	 */
	public function testFourteenDayLeadAccepted(): void {
		$collection = ['requestedCollectionDate' => '2026-07-15'];
		$pre = ['sentAt' => '2026-07-01T09:00:00+02:00', 'channel' => 'email', 'noticeDays' => 14];
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canIncludeInBatch(collection: $collection, preNotification: $pre));
	}//end testFourteenDayLeadAccepted()

	/**
	 * A notification sent only 6 days before is too short and blocks (REQ-SDD-003).
	 *
	 * @return void
	 */
	public function testTooShortLeadBlocks(): void {
		$collection = ['requestedCollectionDate' => '2026-07-15'];
		$pre = ['sentAt' => '2026-07-09T09:00:00+02:00', 'channel' => 'email', 'noticeDays' => 14];
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canIncludeInBatch(collection: $collection, preNotification: $pre));
	}//end testTooShortLeadBlocks()

	/**
	 * An invoice-line carrier counts as proof even without an explicit sentAt (REQ-SDD-003).
	 *
	 * @return void
	 */
	public function testInvoiceLineCarrierAccepted(): void {
		$collection = ['requestedCollectionDate' => '2026-07-15'];
		$pre = ['channel' => 'invoice_line', 'noticeDays' => 14];
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canIncludeInBatch(collection: $collection, preNotification: $pre));
	}//end testInvoiceLineCarrierAccepted()

	/**
	 * A pre-notification record with neither sentAt nor invoice_line is no proof (REQ-SDD-003).
	 *
	 * @return void
	 */
	public function testUnsentNonInvoiceBlocks(): void {
		$collection = ['requestedCollectionDate' => '2026-07-15'];
		$pre = ['channel' => 'email', 'noticeDays' => 14];
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canIncludeInBatch(collection: $collection, preNotification: $pre));
	}//end testUnsentNonInvoiceBlocks()
}//end class
