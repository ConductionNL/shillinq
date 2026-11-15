<?php

/**
 * Unit tests for the refusals of DownPaymentService: an unknown invoice, a
 * down payment without customer or order, one asking nothing, and deductions
 * on an invoice that is not a draft or is itself a down-payment invoice.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sales
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sales;

use OCA\Shillinq\Kernel\Exception\DownPaymentRefusedException;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Sales\DownPaymentService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IL10N;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// phpcs:disable CustomSniffs.Functions.NamedParameters

#[CoversClass(DownPaymentService::class)]
#[UsesClass(DownPaymentRefusedException::class)]
#[UsesClass(ObjectIdentifier::class)]
final class DownPaymentServiceRefusalsTest extends TestCase {
	/**
	 * What the store saved: schema and object, in order.
	 *
	 * @var array<int,array{schema:string,object:array<string,mixed>}>
	 */
	private array $saved = [];

	/**
	 * The service over a store with one sales order of 1,000.00.
	 *
	 * @return DownPaymentService
	 */
	private function service(): DownPaymentService {
		$this->saved = [];
		$store = new InMemoryObjectServiceStub(
			[
				'OrderPrimitive' => [
					['id' => 'order-1', 'orderNumber' => 'SO-1', 'administrationId' => 'adm-1', 'orderType' => 'sales', 'totalAmount' => 1210.0],
				],
				'OrderLine'      => [['id' => 'ol-1', 'orderId' => 'order-1', 'description' => 'Work', 'lineAmount' => 1000.0, 'vatRate' => 21]],
				'ARInvoice'      => [['id' => 'ar-1', 'invoiceNumber' => '2026-0001', 'lifecycleState' => 'draft']],
			],
			$this->saved
		);
		$location = $this->createStub(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new DownPaymentService($store, $location, $l10n, $this->createStub(LoggerInterface::class));

	}//end service()

	/**
	 * The message of the refusal a call raises.
	 *
	 * @param callable $call The call.
	 *
	 * @return string
	 */
	private static function refusal(callable $call): string {
		try {
			$call();
		} catch (DownPaymentRefusedException $e) {
			return $e->getMessage();
		}

		self::fail('Expected a refusal.');

	}//end refusal()

	/**
	 * An invoice is found by id or number; an unknown one is not found.
	 *
	 * @return void
	 */
	public function testAnUnknownInvoiceIsNotFound(): void {
		$service = $this->service();
		self::assertSame('ar-1', $service->findInvoice('2026-0001')['id']);

		$this->expectException(OutOfBoundsException::class);
		$this->expectExceptionMessage('invoice 2026-9999 not found');
		$service->findInvoice('2026-9999');

	}//end testAnUnknownInvoiceIsNotFound()

	/**
	 * A down payment needs the administration, the customer and the order.
	 *
	 * @return void
	 */
	public function testADownPaymentNeedsItsParties(): void {
		$service = $this->service();
		$request = ['administrationId' => 'adm-1', 'customerId' => 'c-1', 'orderReference' => 'order-1', 'percentage' => 30];

		foreach (['administrationId', 'customerId', 'orderReference'] as $field) {
			self::assertSame(
				'Choose the customer and the order.',
				self::refusal(static fn () => $service->raise(array_merge($request, [$field => '  '])))
			);
		}

		self::assertSame([], $this->saved);

	}//end testADownPaymentNeedsItsParties()

	/**
	 * A down payment of nothing is refused, whether it asks no percentage and
	 * no amount, a zero percentage, or a zero amount.
	 *
	 * @return void
	 */
	public function testADownPaymentOfNothingIsRefused(): void {
		$service = $this->service();
		$request = ['administrationId' => 'adm-1', 'customerId' => 'c-1', 'orderReference' => 'order-1'];

		foreach ([[], ['percentage' => 0], ['amount' => 0], ['amount' => -5]] as $ask) {
			self::assertSame(
				'Enter a percentage or an amount above zero.',
				self::refusal(static fn () => $service->raise(array_merge($request, $ask)))
			);
		}

		self::assertSame([], $this->saved);

	}//end testADownPaymentOfNothingIsRefused()

	/**
	 * Down payments are deducted only on a draft that is not itself a
	 * down-payment invoice.
	 *
	 * @return void
	 */
	public function testDeductionsNeedADraftInvoice(): void {
		$service = $this->service();

		self::assertSame(
			'Down payments can only be deducted on a draft invoice.',
			self::refusal(static fn () => $service->deductOnto(['id' => 'ar-1', 'lifecycleState' => 'issued'], 'order-1'))
		);
		self::assertSame(
			'A down-payment invoice cannot deduct other down payments.',
			self::refusal(static fn () => $service->deductOnto(['id' => 'ar-1', 'lifecycleState' => 'draft', 'invoiceTypeCode' => '386'], 'order-1'))
		);
		self::assertSame([], $this->saved);

	}//end testDeductionsNeedADraftInvoice()
}//end class
