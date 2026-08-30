<?php

/**
 * Unit tests for the KlantbeeldResult + KlantbeeldTransaction value objects.
 *
 * Slice 04 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Covers the named factories, the `isUnavailable()` / `isEmpty()` predicates,
 * and JSON serialisation (used by the slice-05 controller).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use OCA\Shillinq\Service\Pipelinq\KlantbeeldResult;
use OCA\Shillinq\Service\Pipelinq\KlantbeeldTransaction;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the immutable klantbeeld value objects.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
 */
final class KlantbeeldResultTest extends TestCase {
	/**
	 * Available factory: NOT unavailable; empty rows imply isEmpty.
	 *
	 * @return void
	 */
	public function testAvailableEmptyMarksEmpty(): void {
		$result = KlantbeeldResult::available([], 5, 0);
		self::assertFalse($result->isUnavailable());
		self::assertTrue($result->isEmpty());
		self::assertSame(5, $result->limit);
		self::assertSame(0, $result->offset);

	}//end testAvailableEmptyMarksEmpty()

	/**
	 * Available factory: rows present, NOT empty.
	 *
	 * @return void
	 */
	public function testAvailableWithRowsNotEmpty(): void {
		$tx = new KlantbeeldTransaction('2026-06-05', 'INV-1', 100.0, 'EUR', 'paid');
		$result = KlantbeeldResult::available([$tx], 5, 0);

		self::assertFalse($result->isUnavailable());
		self::assertFalse($result->isEmpty());
		self::assertSame([$tx], $result->transactions);

	}//end testAvailableWithRowsNotEmpty()

	/**
	 * Unavailable factory: marks the envelope so the UI hides history.
	 *
	 * @return void
	 */
	public function testUnavailableMarker(): void {
		$result = KlantbeeldResult::unavailable(10, 20);

		self::assertTrue($result->isUnavailable());
		self::assertFalse($result->isEmpty(), 'Unavailable is NOT the same as empty');
		self::assertSame([], $result->transactions);
		self::assertSame(10, $result->limit);
		self::assertSame(20, $result->offset);

	}//end testUnavailableMarker()

	/**
	 * JSON serialisation echoes the page meta + transactions.
	 *
	 * @return void
	 */
	public function testJsonSerialiseShape(): void {
		$tx = new KlantbeeldTransaction('2026-06-05', 'INV-1', 100.0, 'EUR', 'paid');
		$result = KlantbeeldResult::available([$tx], 5, 0);

		$payload = $result->jsonSerialize();
		self::assertSame(5, $payload['limit']);
		self::assertSame(0, $payload['offset']);
		self::assertFalse($payload['unavailable']);
		self::assertCount(1, $payload['transactions']);
		self::assertSame(
			[
				'date' => '2026-06-05',
				'description' => 'INV-1',
				'amount' => 100.0,
				'currency' => 'EUR',
				'status' => 'paid',
			],
			$payload['transactions'][0]
		);

	}//end testJsonSerialiseShape()

	/**
	 * Transaction::fromArray tolerates missing optional fields with sane
	 * defaults (empty string / 0.0) so one bad row doesn't kill the page.
	 *
	 * @return void
	 */
	public function testTransactionFromArrayTolerantOfMissingFields(): void {
		$tx = KlantbeeldTransaction::fromArray(['date' => '2026-06-05']);
		self::assertSame('2026-06-05', $tx->date);
		self::assertSame('', $tx->description);
		self::assertSame(0.0, $tx->amount);
		self::assertSame('', $tx->currency);
		self::assertSame('', $tx->status);

	}//end testTransactionFromArrayTolerantOfMissingFields()

	/**
	 * Numeric-string amount is coerced to float, non-numeric becomes 0.0.
	 *
	 * @return void
	 */
	public function testTransactionAmountCoercion(): void {
		$a = KlantbeeldTransaction::fromArray(['amount' => '12.34']);
		self::assertSame(12.34, $a->amount);

		$b = KlantbeeldTransaction::fromArray(['amount' => 'not-a-number']);
		self::assertSame(0.0, $b->amount);

		$c = KlantbeeldTransaction::fromArray(['amount' => 7]);
		self::assertSame(7.0, $c->amount);

	}//end testTransactionAmountCoercion()
}//end class
