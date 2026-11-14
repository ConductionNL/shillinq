<?php

/**
 * Shillinq IntegriqPaymentAdapter
 *
 * The live binding of the payment port: a payment is created by integriq's
 * payment providers through integriq's typed payment command event (ADR-041),
 * which needs no Nextcloud session, so a portal forward, a background job and
 * an intake raise all get a checkout. Integriq absent, the command not
 * handled, a refusal or a log-provider source answer dormant with the reason
 * and never with a link no provider created.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Integriq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Integriq;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentResult;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates payments through integriq's payment command event.
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-1.2
 */
class IntegriqPaymentAdapter implements MolliePaymentAdapterInterface {

	/**
	 * Integriq's payment command, named by string so shillinq loads without integriq.
	 *
	 * @var string
	 */
	public const COMMAND_EVENT = 'OCA\Integriq\Event\PaymentRequestedEvent';

	/**
	 * Refusal codes that mean integriq has no live payment for this request:
	 * no payment source, or a method the source does not offer.
	 *
	 * @var list<string>
	 */
	private const NOT_SET_UP = ['no-source', 'unsupported'];

	/**
	 * The metadata keys that name what is paid, most specific first, with the
	 * subject type each names.
	 *
	 * @var array<string, string>
	 */
	private const SUBJECT_KEYS = [
		'paymentRequestId' => 'PaymentRequest',
		'invoiceId' => 'ARInvoice',
		'depositPaymentId' => 'DepositPayment',
	];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher The dispatcher integriq listens on.
	 * @param LoggerInterface  $logger          Logger for the dormant reasons.
	 * @param string           $commandEvent    The command class; integriq's unless a test names an absent one.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
		private readonly string $commandEvent = self::COMMAND_EVENT,
	) {
	}//end __construct()

	/**
	 * Ask integriq for a payment and translate its answer.
	 *
	 * @param array<string,mixed> $payload amount{value,currency}, description,
	 *                                     redirectUrl, method, metadata.
	 *
	 * @return MolliePaymentResult The checkout, or a dormant result naming why there is none.
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-1.2
	 */
	public function createPayment(array $payload): MolliePaymentResult {
		if ($this->commandAvailable() === false) {
			return $this->dormant(reason: 'integriq-absent', detail: 'Integriq is not installed or does not offer the payment command.');
		}

		try {
			$event = $this->commandFor(payload: $payload);
			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->warning('Shillinq: the integriq payment command could not be sent', ['error' => $e->getMessage()]);
			return $this->dormant(reason: 'command-failed', detail: $e->getMessage());
		}

		return $this->resultOf(event: $event);
	}//end createPayment()

	/**
	 * Dormant when integriq's payment command is not available.
	 *
	 * Whether integriq has a live source is only known per payment: a
	 * refusal or a log-provider answer makes that payment dormant.
	 *
	 * @return bool True when integriq or its command is absent.
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-1.2
	 */
	public function isDormant(): bool {
		return $this->commandAvailable() === false;
	}//end isDormant()

	/**
	 * Whether integriq's payment command class can be loaded.
	 *
	 * @return bool
	 */
	private function commandAvailable(): bool {
		return class_exists($this->commandEvent) === true;
	}//end commandAvailable()

	/**
	 * Build the command from the port's payload, with shillinq as the source app.
	 *
	 * @param array<string,mixed> $payload The port payload.
	 *
	 * @return Event The command.
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-1.2
	 */
	private function commandFor(array $payload): Event {
		$amount = [];
		if (is_array($payload['amount'] ?? null) === true) {
			$amount = $payload['amount'];
		}

		$metadata = [];
		if (is_array($payload['metadata'] ?? null) === true) {
			$metadata = $payload['metadata'];
		}

		$method = null;
		if ((string)($payload['method'] ?? '') !== '') {
			$method = (string)$payload['method'];
		}

		$class = $this->commandEvent;

		return new $class(
			sourceApp: AppIdentity::APP_ID,
			subjectRef: $this->subjectRef(metadata: $metadata),
			amountValue: (string)($amount['value'] ?? ''),
			amountCurrency: (string)($amount['currency'] ?? 'EUR'),
			description: (string)($payload['description'] ?? ''),
			redirectUrl: (string)($payload['redirectUrl'] ?? ''),
			method: $method,
			metadata: $metadata,
			correlationId: (string)($metadata['correlationId'] ?? ''),
		);
	}//end commandFor()

	/**
	 * What is paid, as `<type>/<id>` from the most specific metadata key.
	 *
	 * @param array<string,mixed> $metadata The payment metadata.
	 *
	 * @return string The subject reference, or '' when the metadata names none.
	 */
	private function subjectRef(array $metadata): string {
		foreach (self::SUBJECT_KEYS as $key => $type) {
			$id = (string)($metadata[$key] ?? '');
			if ($id !== '') {
				return $type . '/' . $id;
			}
		}

		return '';
	}//end subjectRef()

	/**
	 * Translate integriq's answer on the event into the port's result.
	 *
	 * Read through `method_exists()`: the command is integriq's class, which
	 * shillinq only knows by name.
	 *
	 * @param Event $event The dispatched command.
	 *
	 * @return MolliePaymentResult The result.
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-1.2
	 */
	private function resultOf(Event $event): MolliePaymentResult {
		if (method_exists($event, 'isHandled') === false || $event->isHandled() !== true) {
			return $this->dormant(reason: 'command-not-handled', detail: 'Integriq did not answer the payment command.');
		}

		$refusal = null;
		if (method_exists($event, 'getRefusal') === true) {
			$refusal = $event->getRefusal();
		}

		if (is_array($refusal) === true) {
			return $this->refused(refusal: $refusal);
		}

		$result = null;
		if (method_exists($event, 'getResult') === true) {
			$result = $event->getResult();
		}

		if (is_array($result) === false) {
			return $this->dormant(reason: 'command-not-handled', detail: 'Integriq answered the payment command without a result.');
		}

		if ((bool)($result['dormant'] ?? false) === true) {
			return $this->dormant(reason: 'log-provider', detail: 'The integriq payment source uses its log provider.');
		}

		return new MolliePaymentResult(
			paymentStatus: (string)($result['paymentStatus'] ?? ''),
			molliePaymentId: (string)($result['providerPaymentId'] ?? ''),
			checkoutUrl: (string)($result['checkoutUrl'] ?? ''),
			dormant: false,
			extras: ['paymentIntentId' => (string)($result['paymentIntentId'] ?? '')],
		);
	}//end resultOf()

	/**
	 * A refused command answers dormant: there is no checkout, whatever the reason.
	 *
	 * A refusal other than "not set up" (an invalid request, a provider error)
	 * is logged as a warning as well, since it needs someone to look.
	 *
	 * @param array<string,mixed> $refusal The refusal {code, reason}.
	 *
	 * @return MolliePaymentResult The dormant result naming the refusal code.
	 */
	private function refused(array $refusal): MolliePaymentResult {
		$code = (string)($refusal['code'] ?? '');
		$reason = (string)($refusal['reason'] ?? '');
		if (in_array($code, self::NOT_SET_UP, true) === false) {
			$this->logger->warning('Shillinq: integriq refused the payment', ['code' => $code, 'reason' => $reason]);
		}

		if ($code === '') {
			$code = 'refused';
		}

		return $this->dormant(reason: $code, detail: $reason);
	}//end refused()

	/**
	 * A dormant result: no payment id, no link, the reason in the extras.
	 *
	 * @param string $reason Machine-readable reason.
	 * @param string $detail What happened.
	 *
	 * @return MolliePaymentResult The result.
	 */
	private function dormant(string $reason, string $detail): MolliePaymentResult {
		$this->logger->info('Shillinq: no live payment through integriq', ['reason' => $reason, 'detail' => $detail]);

		return new MolliePaymentResult(
			paymentStatus: 'PAYMENT_DEFERRED',
			molliePaymentId: '',
			checkoutUrl: '',
			dormant: true,
			extras: ['reason' => $reason, 'note' => $detail],
		);
	}//end dormant()
}//end class
