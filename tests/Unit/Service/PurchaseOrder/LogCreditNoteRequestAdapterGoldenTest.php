<?php

/**
 * Golden test for the logging CreditNote dispute adapter: the logged envelope and the answer.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\PurchaseOrder
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\PurchaseOrder;

use OCA\Shillinq\Service\PurchaseOrder\LogCreditNoteRequestAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The adapter logs the cast envelope with a fresh dispatch id and accepts.
 */
final class LogCreditNoteRequestAdapterGoldenTest extends TestCase {

	/**
	 * A full and an empty envelope.
	 *
	 * @return void
	 */
	public function testLogsTheEnvelopeAndAccepts(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('info')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$adapter = new LogCreditNoteRequestAdapter(logger: $logger);

		$full  = $adapter->submitDisputeCreditNote(
			payload: [
				'matchId'          => 7,
				'invoiceId'        => 'inv-1',
				'invoiceNumber'    => 'F-1',
				'supplierId'       => 'p-1',
				'administrationId' => 'adm-1',
				'currency'         => 'USD',
				'totalInclVat'     => '12100',
				'extra'            => 'ignored',
			]
		);
		$empty = $adapter->submitDisputeCreditNote(payload: []);

		$this->assertSame(['accepted', 'dispatchId', 'error'], array_keys($full));
		$this->assertTrue($full['accepted']);
		$this->assertNull($full['error']);
		$this->assertMatchesRegularExpression('/^urn:uuid:cn-[0-9a-f]{16}$/', (string)$full['dispatchId']);
		$this->assertNotSame($full['dispatchId'], $empty['dispatchId']);
		$this->assertSame(
			[
				[
					'LogCreditNoteRequestAdapter: dispute UBL CreditNote queued',
					['matchId' => '7', 'invoiceId' => 'inv-1', 'invoiceNumber' => 'F-1', 'supplierId' => 'p-1', 'administrationId' => 'adm-1', 'currency' => 'USD', 'totalInclVat' => 12100, 'dispatchId' => $full['dispatchId']],
				],
				[
					'LogCreditNoteRequestAdapter: dispute UBL CreditNote queued',
					['matchId' => '', 'invoiceId' => '', 'invoiceNumber' => '', 'supplierId' => '', 'administrationId' => '', 'currency' => 'EUR', 'totalInclVat' => 0, 'dispatchId' => $empty['dispatchId']],
				],
			],
			$logged
		);

	}//end testLogsTheEnvelopeAndAccepts()
}//end class
