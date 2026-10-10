<?php

/**
 * Tests for InvoiceIngestRequestedListener.
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
 * @spec openspec/changes/tenant-month-invoice-from-dossiq/specs/usage-metered-billing/spec.md (REQ-UMB-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Shillinq\Event\InvoiceIngestRequestedEvent;
use OCA\Shillinq\Listener\InvoiceIngestRequestedListener;
use OCA\Shillinq\Service\InvoiceIngestService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * The listener hands its own event to the service, and is registered.
 */
final class InvoiceIngestRequestedListenerTest extends TestCase {

	/**
	 * Its event reaches the service; any other event does not.
	 *
	 * @return void
	 */
	public function testHandsItsEventToTheService(): void {
		$event   = new InvoiceIngestRequestedEvent(sourceApp: 'dossiq', externalReference: 't-42', period: '2026-09', lines: []);
		$service = $this->createMock(InvoiceIngestService::class);
		$service->expects($this->once())->method('ingest')->with($event);

		$listener = new InvoiceIngestRequestedListener(ingest: $service);
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
			'/event: InvoiceIngestRequestedEvent::class,\s*listener: InvoiceIngestRequestedListener::class/',
			$registration
		);
	}//end testIsRegistered()
}//end class
