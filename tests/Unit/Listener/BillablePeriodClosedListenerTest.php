<?php

/**
 * Tests for BillablePeriodClosedListener.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md (REQ-UMB-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Shillinq\Event\BillablePeriodClosedEvent;
use OCA\Shillinq\Listener\BillablePeriodClosedListener;
use OCA\Shillinq\Service\BillablePeriodInvoiceService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * The listener hands its own event to the service, and is registered.
 */
final class BillablePeriodClosedListenerTest extends TestCase {

	/**
	 * Its event reaches the service; any other event does not.
	 *
	 * @return void
	 */
	public function testHandsItsEventToTheService(): void {
		$event   = new BillablePeriodClosedEvent(sourceApp: 'dossiq', externalReference: 't-42', period: '2026-09', lines: []);
		$service = $this->createMock(BillablePeriodInvoiceService::class);
		$service->expects($this->once())->method('invoicePeriod')->with($event);

		$listener = new BillablePeriodClosedListener(ingest: $service);
		$listener->handle(event: $event);
		$listener->handle(event: new Event());
	}//end testHandsItsEventToTheService()

	/**
	 * The registration wires the event to this listener.
	 *
	 * @return void
	 */
	public function testIsRegistered(): void {
		$registration = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/ObjectRequestSettlementRegistration.php');

		$this->assertMatchesRegularExpression(
			'/event: BillablePeriodClosedEvent::class,\s*listener: BillablePeriodClosedListener::class/',
			$registration
		);
	}//end testIsRegistered()
}//end class
