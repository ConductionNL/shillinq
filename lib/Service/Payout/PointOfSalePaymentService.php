<?php

/**
 * Point Of Sale Payment Service
 *
 * Take payment now (design D5, REQ-RPPO-005): asks the payment provider, through
 * the payment port, for a point-of-sale payment of an open invoice's amount on
 * the phone the user registered as a `PaymentDevice`. When the provider answers
 * that it cannot push the payment to the device, it asks for an ordinary
 * checkout instead, which the page shows as a QR code for the customer to scan.
 * Either way a pending `PaymentRequest` on the invoice carries the provider's
 * payment id, so the capture settles the invoice as any provider payment does.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Payout
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

namespace OCA\Shillinq\Service\Payout;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Exception\FinancialDocumentActionRefusedException;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentResult;
use OCA\Shillinq\Service\FinancialDocumentGuard;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Takes an open invoice's payment on the user's registered phone, or as a QR checkout.
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
 */
class PointOfSalePaymentService {

	public const MODE_DEVICE = 'device';
	public const MODE_QR = 'qr';

	/**
	 * The provider's payment method for a payment pushed to a phone or terminal.
	 *
	 * @var string
	 */
	public const METHOD_POINT_OF_SALE = 'pointofsale';

	/**
	 * The payment port's answer when the provider cannot push a payment to the device.
	 *
	 * @var string
	 */
	public const STATUS_METHOD_UNAVAILABLE = 'METHOD_UNAVAILABLE';

	/**
	 * Invoice states that still wait for their money.
	 *
	 * @var list<string>
	 */
	private const OPEN_STATES = ['issued', 'overdue', 'partially-paid'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface        $objectService    OpenRegister's object service.
	 * @param RegisterLocation              $registerLocation Where the register lives.
	 * @param FinancialDocumentGuard        $guard            The caller's action, administration and invoice checks.
	 * @param MolliePaymentAdapterInterface $payments         The payment port to the provider, through integriq.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly FinancialDocumentGuard $guard,
		private readonly MolliePaymentAdapterInterface $payments,
	) {
	}//end __construct()

	/**
	 * Take an open invoice's payment on the spot, as the calling user.
	 *
	 * @param string $invoiceId The invoice.
	 *
	 * @return array{mode: string, request: array<string, mixed>, checkoutUrl: string, device: array<string, mixed>}
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller, the invoice, the device or the provider refuses it.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
	 */
	public function takePayment(string $invoiceId): array {
		$this->guard->assertMay(action: PaymentActionAuthorizer::ACTION_REQUEST);
		$invoice = $this->guard->readableInvoice(invoiceId: $invoiceId);
		$this->guard->assertAdministration(administrationId: (string)($invoice['administrationId'] ?? ''));

		if (in_array((string)($invoice['lifecycleState'] ?? ''), self::OPEN_STATES, true) === false) {
			throw new FinancialDocumentActionRefusedException(message: 'Only an issued invoice that is not paid can be paid.', status: 409);
		}

		return $this->payInvoice(invoice: $invoice, userId: $this->guard->callerId());
	}//end takePayment()

	/**
	 * Ask the provider for an invoice's open amount on the user's device, or as a QR checkout.
	 *
	 * The caller has already checked the user may do this for the invoice.
	 *
	 * @param array<string, mixed> $invoice The invoice, with its id.
	 * @param string               $userId  The user whose device takes the payment.
	 *
	 * @return array{mode: string, request: array<string, mixed>, checkoutUrl: string, device: array<string, mixed>}
	 *
	 * @throws FinancialDocumentActionRefusedException When there is nothing to pay, no device, or no provider.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
	 */
	public function payInvoice(array $invoice, string $userId): array {
		$amount = self::amountDue(invoice: $invoice);
		if ($amount <= 0.0) {
			throw new FinancialDocumentActionRefusedException(message: 'Nothing is left to pay on this invoice.', status: 409);
		}

		$administrationId = (string)($invoice['administrationId'] ?? '');
		$device = $this->deviceOf(userId: $userId, administrationId: $administrationId);
		if ($device === null) {
			throw new FinancialDocumentActionRefusedException(
				message: 'Register your phone as a payment device first, in your settings, to take payments on the spot.',
				status: 409
			);
		}

		if ($this->payments->isDormant() === true) {
			throw new FinancialDocumentActionRefusedException(
				message: 'The payment provider is not connected yet, so no payment can be taken on the spot.',
				status: 503
			);
		}

		$number = (string)($invoice['invoiceNumber'] ?? $invoice['id']);
		$currency = strtoupper((string)($invoice['currency'] ?? 'EUR'));
		$payload = [
			'amount' => ['value' => number_format($amount, 2, '.', ''), 'currency' => $currency],
			'description' => 'Invoice ' . $number,
			'method' => self::METHOD_POINT_OF_SALE,
			'terminalId' => (string)$device['deviceId'],
			'metadata' => ['invoiceId' => (string)$invoice['id'], 'administrationId' => $administrationId],
		];

		$mode = self::MODE_DEVICE;
		$result = $this->payments->createPayment(payload: $payload);
		if (self::methodUnavailable(result: $result) === true) {
			// The phone cannot receive it: an ordinary checkout, shown as a QR code (D5).
			unset($payload['method'], $payload['terminalId']);
			$mode = self::MODE_QR;
			$result = $this->payments->createPayment(payload: $payload);
		}

		if (trim($result->molliePaymentId) === '' || ($mode === self::MODE_QR && trim($result->checkoutUrl) === '')) {
			throw new FinancialDocumentActionRefusedException(message: 'The payment provider did not start the payment. Try again.', status: 502);
		}

		$request = [
			'subjectKind' => 'invoice',
			'invoiceReference' => (string)$invoice['id'],
			'amount' => $amount,
			'currency' => $currency,
			'description' => 'Invoice ' . $number,
			'state' => 'pending',
			'administrationId' => $administrationId,
			'paymentIntentId' => $result->molliePaymentId,
			'paymentGateway' => (string)($device['provider'] ?? 'mollie'),
			'paymentMethod' => self::METHOD_POINT_OF_SALE,
			'paymentDeviceId' => (string)$device['deviceId'],
			'requestedBy' => $userId,
		];
		if ($mode === self::MODE_QR) {
			$request['paymentMethod'] = self::MODE_QR;
			$request['paymentLink'] = $result->checkoutUrl;
		}

		$saved = $this->objectService
			->setRegister($this->registerLocation->getRegisterSlug())
			->setSchema('PaymentRequest')
			->saveObject(object: $request);

		return [
			'mode' => $mode,
			'request' => ['id' => ObjectIdentifier::resolve(saved: $saved)] + $request,
			'checkoutUrl' => $result->checkoutUrl,
			'device' => $device,
		];
	}//end payInvoice()

	/**
	 * The user's registered device for an administration: one for that
	 * administration first, else one for every administration.
	 *
	 * @param string $userId           The user.
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
	 */
	public function deviceOf(string $userId, string $administrationId): ?array {
		if (trim($userId) === '') {
			return null;
		}

		$rows = $this->objectService
			->setRegister($this->registerLocation->getRegisterSlug())
			->setSchema('PaymentDevice')
			->findAll(['filters' => ['userId' => $userId], 'limit' => 20]);
		$general = null;
		foreach ($rows as $row) {
			$device = ObjectIdentifier::recordWithId(candidate: $row);
			// The filter is re-checked: a filter the query layer ignores must not hand out another user's phone.
			if ($device === null || (string)($device['userId'] ?? '') !== $userId || trim((string)($device['deviceId'] ?? '')) === '') {
				continue;
			}

			$scope = trim((string)($device['administrationId'] ?? ''));
			if ($scope === $administrationId) {
				return $device;
			}

			if ($scope === '' && $general === null) {
				$general = $device;
			}
		}

		return $general;
	}//end deviceOf()

	/**
	 * What is still open on an invoice, rounded to cents.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 *
	 * @return float
	 */
	private static function amountDue(array $invoice): float {
		if (is_numeric($invoice['amountDue'] ?? null) === true) {
			return round((float)$invoice['amountDue'], 2);
		}

		return round((float)($invoice['grossAmount'] ?? 0) - (float)($invoice['paidAmount'] ?? 0), 2);
	}//end amountDue()

	/**
	 * Whether the provider answered that it cannot push the payment to the device.
	 *
	 * @param MolliePaymentResult $result The port's answer.
	 *
	 * @return bool
	 */
	private static function methodUnavailable(MolliePaymentResult $result): bool {
		return strtoupper($result->paymentStatus) === self::STATUS_METHOD_UNAVAILABLE
			|| (string)($result->extras['reason'] ?? '') === 'method-unavailable';
	}//end methodUnavailable()
}//end class
