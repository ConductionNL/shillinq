<?php

/**
 * Payment Link Creator
 *
 * Asks the bound payment provider for a checkout when a payment request is
 * raised, and gives the fields the request stores from it
 * (receivables-payment-links D2): `paymentIntentId` and `providerPaymentId`
 * (the provider payment id integriq's status event carries), `paymentLink`
 * (the checkout URL) and `checkoutExpiresAt`. With a dormant provider, a
 * request that is not pending or carries no chargeable amount, or a provider
 * that fails, it gives nothing: the request is raised without a link, which
 * is never a fake one, and raising it never fails for the provider's sake.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Payment;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates the provider checkout of a newly raised payment request.
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-1.3
 */
class PaymentLinkCreator {

	/**
	 * How long a checkout stays usable after it is created, in seconds; an
	 * iDEAL checkout expires after fifteen minutes.
	 *
	 * @var int
	 */
	public const CHECKOUT_LIFETIME = 900;

	/**
	 * The webhook route the provider calls back on.
	 *
	 * @var string
	 */
	private const WEBHOOK_ROUTE = 'shillinq.paymentRequestWebhook.handle';

	/**
	 * The payer return URL setting, shared with the portal pay flow.
	 *
	 * @var string
	 */
	private const CONFIG_REDIRECT_URL = 'portal_payment_redirect_url';

	/**
	 * Constructor.
	 *
	 * @param PaymentProviderInterface $provider     The bound payment provider.
	 * @param IURLGenerator            $urlGenerator Builds the webhook and the default return URL.
	 * @param IAppConfig               $appConfig    The configured return URL.
	 * @param LoggerInterface          $logger       Logs a provider failure.
	 */
	public function __construct(
		private readonly PaymentProviderInterface $provider,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The link fields to store on a raised request, or [] when it gets no link.
	 *
	 * @param array<string, mixed> $request The saved PaymentRequest, with its id.
	 *
	 * @return array<string, string> paymentIntentId, providerPaymentId, paymentLink and checkoutExpiresAt, or [].
	 *
	 * @spec openspec/changes/receivables-payment-links/specs/ar-invoice-payment-links/spec.md#requirement-a-payment-request-gets-a-live-link-from-the-provider-through-integriq-req-rpl-001
	 */
	public function linkFieldsFor(array $request): array {
		$amount = ($request['amount'] ?? null);
		$id     = (string)($request['id'] ?? '');
		if ($id === '' || (string)($request['state'] ?? 'pending') !== 'pending'
			|| is_bool($amount) === true || is_numeric($amount) === false || (float)$amount <= 0.0
		) {
			return [];
		}

		try {
			$session = $this->provider->createSession($this->sessionFor(request: $request, amount: (float)$amount));
		} catch (Throwable $e) {
			$this->logger->warning('Shillinq: no payment link could be created for a raised request', ['error' => $e->getMessage()]);
			return [];
		}

		return self::fieldsOf(session: $session);
	}//end linkFieldsFor()

	/**
	 * The fields a live session gives a request, or [] for a dormant one or one without a checkout.
	 *
	 * @param PaymentSessionResult $session The provider session.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-1.3
	 */
	public static function fieldsOf(PaymentSessionResult $session): array {
		if ($session->dormant === true || $session->checkoutUrl === '' || $session->paymentIntentId === '') {
			return [];
		}

		return [
			'paymentIntentId' => $session->paymentIntentId,
			'providerPaymentId' => $session->paymentIntentId,
			'paymentLink' => $session->checkoutUrl,
			'checkoutExpiresAt' => gmdate('Y-m-d\TH:i:s\Z', (time() + self::CHECKOUT_LIFETIME)),
		];
	}//end fieldsOf()

	/**
	 * The provider session for a request: its own amount, currency and description.
	 *
	 * @param array<string, mixed> $request The PaymentRequest, with its id.
	 * @param float                $amount  The chargeable amount.
	 *
	 * @return PaymentSessionRequest
	 */
	private function sessionFor(array $request, float $amount): PaymentSessionRequest {
		$description = trim((string)($request['description'] ?? ''));
		if ($description === '') {
			$description = 'Payment request';
		}

		$metadata = [
			'paymentRequestId' => (string)$request['id'],
			'administrationId' => (string)($request['administrationId'] ?? ''),
			'correlationId' => (string)$request['id'],
		];
		if ((string)($request['invoiceReference'] ?? '') !== '') {
			$metadata['invoiceId'] = (string)$request['invoiceReference'];
		}

		return new PaymentSessionRequest(
			amount: $amount,
			currency: (string)($request['currency'] ?? 'EUR'),
			description: $description,
			redirectUrl: $this->redirectUrl(),
			webhookUrl: $this->urlGenerator->linkToRouteAbsolute(self::WEBHOOK_ROUTE, ['gateway' => 'mollie']),
			method: 'ideal',
			metadata: $metadata,
		);
	}//end sessionFor()

	/**
	 * The configured payer return URL, else the instance root.
	 *
	 * @return string
	 */
	private function redirectUrl(): string {
		$configured = $this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_REDIRECT_URL, '');
		if ($configured !== '') {
			return $configured;
		}

		return $this->urlGenerator->getAbsoluteURL('/');
	}//end redirectUrl()
}//end class
