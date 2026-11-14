<?php

/**
 * Unit tests for PointOfSalePaymentService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Payout
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Payout;

use OCA\Shillinq\Kernel\Exception\FinancialDocumentActionRefusedException;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentResult;
use OCA\Shillinq\Service\FinancialDocumentGuard;
use OCA\Shillinq\Service\Payout\PointOfSalePaymentService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * REQ-RPPO-005: take an invoice's payment on the user's phone, else as a QR checkout.
 *
 * @covers \OCA\Shillinq\Service\Payout\PointOfSalePaymentService
 * @uses   \OCA\Shillinq\Kernel\Exception\FinancialDocumentActionRefusedException
 * @uses   \OCA\Shillinq\Service\External\Mollie\MolliePaymentResult
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
 */
final class PointOfSalePaymentServiceTest extends TestCase {

	/**
	 * Everything the store wrote.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The payment port.
	 *
	 * @var MolliePaymentAdapterInterface&MockObject
	 */
	private MolliePaymentAdapterInterface $payments;

	/**
	 * The guard.
	 *
	 * @var FinancialDocumentGuard&MockObject
	 */
	private FinancialDocumentGuard $guard;

	/**
	 * Invoice 2026-0601 of EUR 145.20, open, of the plumber anna.
	 *
	 * @var array<string, mixed>
	 */
	private const INVOICE = [
		'id' => 'inv-0601', 'invoiceNumber' => '2026-0601', 'lifecycleState' => 'issued',
		'grossAmount' => 145.2, 'paidAmount' => 0, 'currency' => 'EUR', 'administrationId' => 'THV',
	];

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->payments = $this->createMock(MolliePaymentAdapterInterface::class);
		$this->guard = $this->createMock(FinancialDocumentGuard::class);
		$this->guard->method('readableInvoice')->willReturn(self::INVOICE);
		$this->guard->method('callerId')->willReturn('anna');
	}//end setUp()

	/**
	 * The service over a store holding the given devices.
	 *
	 * @param list<array<string, mixed>> $devices PaymentDevice rows.
	 *
	 * @return PointOfSalePaymentService
	 */
	private function service(array $devices): PointOfSalePaymentService {
		$this->saved = [];
		$store = new InMemoryObjectServiceStub(data: ['PaymentDevice' => $devices], saveSink: $this->saved);

		return new PointOfSalePaymentService(
			objectService: $store,
			registerLocation: $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']),
			guard: $this->guard,
			payments: $this->payments,
		);
	}//end service()

	/**
	 * A device of anna.
	 *
	 * @param string $deviceId         The provider's device id.
	 * @param string $administrationId The administration it is for, '' for all.
	 * @param string $userId           The user.
	 *
	 * @return array<string, mixed>
	 */
	private static function device(string $deviceId, string $administrationId='', string $userId='anna'): array {
		return [
			'id' => 'dev-' . $deviceId, 'userId' => $userId, 'provider' => 'mollie',
			'deviceId' => $deviceId, 'label' => 'Telefoon', 'administrationId' => $administrationId,
		];
	}//end device()

	/**
	 * The device path: a point-of-sale payment for the open amount on the user's phone, and a pending request.
	 *
	 * @return void
	 */
	public function testThePaymentIsPushedToTheUsersPhone(): void {
		$this->payments->method('isDormant')->willReturn(false);
		$this->payments->expects($this->once())->method('createPayment')
			->with(
				[
					'amount' => ['value' => '145.20', 'currency' => 'EUR'],
					'description' => 'Invoice 2026-0601',
					'method' => 'pointofsale',
					'terminalId' => 'term_thv',
					'metadata' => ['invoiceId' => 'inv-0601', 'administrationId' => 'THV'],
				]
			)
			->willReturn(new MolliePaymentResult(paymentStatus: 'open', molliePaymentId: 'tr_pos1', checkoutUrl: '', dormant: false));

		$devices = [
			self::device(deviceId: 'term_all'),
			self::device(deviceId: 'term_thv', administrationId: 'THV'),
			self::device(deviceId: 'term_bob', userId: 'bob'),
		];
		$out = $this->service(devices: $devices)->takePayment(invoiceId: 'inv-0601');

		$this->assertSame(PointOfSalePaymentService::MODE_DEVICE, $out['mode']);
		$this->assertSame('term_thv', $out['device']['deviceId']);
		$this->assertCount(1, $this->saved);
		$this->assertSame('PaymentRequest', $this->saved[0]['schema']);
		$request = $this->saved[0]['object'];
		$this->assertSame('inv-0601', $request['invoiceReference']);
		$this->assertSame('invoice', $request['subjectKind']);
		$this->assertSame(145.2, $request['amount']);
		$this->assertSame('pending', $request['state']);
		$this->assertSame('tr_pos1', $request['paymentIntentId']);
		$this->assertSame('pointofsale', $request['paymentMethod']);
		$this->assertSame('term_thv', $request['paymentDeviceId']);
		$this->assertSame('anna', $request['requestedBy']);
		$this->assertArrayNotHasKey('paymentLink', $request);
		$this->assertSame($request['paymentIntentId'], $out['request']['paymentIntentId']);
	}//end testThePaymentIsPushedToTheUsersPhone()

	/**
	 * The fallback: the provider cannot push to the phone, so an ordinary checkout is shown as a QR code.
	 *
	 * @return void
	 */
	public function testAPhoneThatCannotReceiveItFallsBackToAQrCheckout(): void {
		$this->payments->method('isDormant')->willReturn(false);
		$payloads = [];
		$this->payments->expects($this->exactly(2))->method('createPayment')->willReturnCallback(
			static function (array $payload) use (&$payloads): MolliePaymentResult {
				$payloads[] = $payload;
				if (count($payloads) === 1) {
					return new MolliePaymentResult(paymentStatus: 'METHOD_UNAVAILABLE', molliePaymentId: '', checkoutUrl: '', dormant: false);
				}

				return new MolliePaymentResult(paymentStatus: 'open', molliePaymentId: 'tr_qr1', checkoutUrl: 'https://pay.example/qr1', dormant: false);
			}
		);

		$out = $this->service(devices: [self::device(deviceId: 'term_all')])->takePayment(invoiceId: 'inv-0601');

		$this->assertSame(PointOfSalePaymentService::MODE_QR, $out['mode']);
		$this->assertSame('https://pay.example/qr1', $out['checkoutUrl']);
		$this->assertSame('pointofsale', $payloads[0]['method']);
		$this->assertArrayNotHasKey('method', $payloads[1]);
		$this->assertArrayNotHasKey('terminalId', $payloads[1]);
		$request = $this->saved[0]['object'];
		$this->assertSame('qr', $request['paymentMethod']);
		$this->assertSame('https://pay.example/qr1', $request['paymentLink']);
		$this->assertSame('tr_qr1', $request['paymentIntentId']);
	}//end testAPhoneThatCannotReceiveItFallsBackToAQrCheckout()

	/**
	 * No device registered: refused with what to do, the provider never asked.
	 *
	 * @return void
	 */
	public function testWithoutARegisteredDeviceNothingIsAsked(): void {
		$this->payments->expects($this->never())->method('createPayment');

		try {
			$this->service(devices: [self::device(deviceId: 'term_bob', userId: 'bob'), self::device(deviceId: 'term_other', administrationId: 'OTHER')])
				->takePayment(invoiceId: 'inv-0601');
			$this->fail('A payment without a device was taken.');
		} catch (FinancialDocumentActionRefusedException $e) {
			$this->assertSame(409, $e->getStatus());
			$this->assertStringStartsWith('Register your phone as a payment device first', $e->getMessage());
		}

		$this->assertSame([], $this->saved);
	}//end testWithoutARegisteredDeviceNothingIsAsked()

	/**
	 * A dormant provider, a paid invoice, and a caller without the action are refused before anything is asked.
	 *
	 * @return void
	 */
	public function testRefusalsBeforeAnythingIsAsked(): void {
		$this->payments->expects($this->never())->method('createPayment');
		$this->payments->method('isDormant')->willReturn(true);
		$service = $this->service(devices: [self::device(deviceId: 'term_all')]);

		$statuses = [];
		foreach ([self::INVOICE, ['lifecycleState' => 'paid'] + self::INVOICE, ['amountDue' => 0] + self::INVOICE] as $invoice) {
			try {
				$service->payInvoice(invoice: $invoice, userId: 'anna');
			} catch (FinancialDocumentActionRefusedException $e) {
				$statuses[] = [$e->getStatus(), $e->getMessage()];
			}
		}

		$this->assertSame(
			[
				[503, 'The payment provider is not connected yet, so no payment can be taken on the spot.'],
				[503, 'The payment provider is not connected yet, so no payment can be taken on the spot.'],
				[409, 'Nothing is left to pay on this invoice.'],
			],
			$statuses
		);

		$paid = $this->createMock(FinancialDocumentGuard::class);
		$paid->method('readableInvoice')->willReturn(['lifecycleState' => 'paid'] + self::INVOICE);
		$this->guard = $paid;
		try {
			$this->service(devices: [])->takePayment(invoiceId: 'inv-0601');
			$this->fail('A paid invoice was taken payment for.');
		} catch (FinancialDocumentActionRefusedException $e) {
			$this->assertSame(409, $e->getStatus());
		}

		$denied = $this->createMock(FinancialDocumentGuard::class);
		$denied->expects($this->once())->method('assertMay')->with('payment.request')
			->willThrowException(new FinancialDocumentActionRefusedException(message: 'This needs the payment.request action.', status: 403));
		$denied->expects($this->never())->method('readableInvoice');
		$this->guard = $denied;
		$this->expectException(FinancialDocumentActionRefusedException::class);
		$this->service(devices: [])->takePayment(invoiceId: 'inv-0601');
	}//end testRefusalsBeforeAnythingIsAsked()

	/**
	 * A provider that starts no payment is refused, and nothing is stored.
	 *
	 * @return void
	 */
	public function testAPaymentTheProviderDidNotStartIsRefused(): void {
		$this->payments->method('isDormant')->willReturn(false);
		$this->payments->method('createPayment')->willReturn(
			new MolliePaymentResult(paymentStatus: 'failed', molliePaymentId: '', checkoutUrl: '', dormant: false)
		);

		try {
			$this->service(devices: [self::device(deviceId: 'term_all')])->takePayment(invoiceId: 'inv-0601');
			$this->fail('A payment without a provider id was stored.');
		} catch (FinancialDocumentActionRefusedException $e) {
			$this->assertSame(502, $e->getStatus());
		}

		$this->assertSame([], $this->saved);
	}//end testAPaymentTheProviderDidNotStartIsRefused()
}//end class
