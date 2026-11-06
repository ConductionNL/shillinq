<?php

/**
 * Tests for BillablePeriodClosedEvent's answer in place.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Event
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

namespace OCA\Shillinq\Tests\Unit\Event;

use OCA\Shillinq\Event\BillablePeriodClosedEvent;
use PHPUnit\Framework\TestCase;

/**
 * The event carries the request and holds one answer.
 */
final class BillablePeriodClosedEventTest extends TestCase {

	/**
	 * The request reads back; accept and refuse replace each other.
	 *
	 * @return void
	 */
	public function testCarriesTheRequestAndOneAnswer(): void {
		$lines = [['description' => 'case.created', 'quantity' => 1, 'unitPrice' => 1]];
		$event = new BillablePeriodClosedEvent(sourceApp: 'dossiq', externalReference: 't-42', period: '2026-09', lines: $lines, correlationId: 'run-7');

		$this->assertSame('dossiq', $event->getSourceApp());
		$this->assertSame('t-42', $event->getExternalReference());
		$this->assertSame('2026-09', $event->getPeriod());
		$this->assertSame($lines, $event->getLines());
		$this->assertSame('run-7', $event->getCorrelationId());
		$this->assertFalse($event->isHandled());
		$this->assertNull($event->getError());

		$event->refuse(error: 'no customer');
		$this->assertFalse($event->isHandled());
		$this->assertSame('no customer', $event->getError());

		$event->accept(invoiceId: 'inv-0', invoiceNumber: 'BIL-0');
		$this->assertFalse($event->getResult()['duplicated']);

		$event->acceptDuplicate(invoiceId: 'inv-1', invoiceNumber: 'BIL-1');
		$this->assertTrue($event->isHandled());
		$this->assertNull($event->getError());
		$this->assertSame(
			['contractVersion' => 1, 'invoiceId' => 'inv-1', 'invoiceNumber' => 'BIL-1', 'status' => 'draft', 'duplicated' => true],
			$event->getResult()
		);
	}//end testCarriesTheRequestAndOneAnswer()
}//end class
