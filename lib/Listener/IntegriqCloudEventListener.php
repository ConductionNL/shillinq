<?php

/**
 * Integriq CloudEvent Listener
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Takes the CloudEvents integriq publishes into shillinq. integriq does not
 * dispatch a Nextcloud event per CloudEvent: `EventService::emitCloudEvent()`
 * saves each one as an OpenRegister object in integriq's `integriq` register,
 * `event` schema, with the CloudEvent `type` and `data` on the object. So the
 * only thing shillinq can hear is OpenRegister's `ObjectCreatedEvent` for that
 * object, and this listener reads the envelope off it.
 *
 * Handled today: `nl.conduction.payment.status`, which integriq emits on every
 * payment outcome change (integriq `PaymentIntentService::emitStatusEvent()`).
 * Its `data` is shaped as the `$event` of
 * {@see PaymentReconciliationService::reconcile()} and goes into it unchanged,
 * so a payment taken through integriq settles the request and its invoice
 * (issue #1681, receivables-payment-links design D3, REQ-RPL-003).
 *
 * And `nl.conduction.peppol.inbound.received`, which integriq emits for every
 * document its Peppol access point received (integriq
 * `PeppolTransmissionService`), with `data` {senderPeppolId, documentType,
 * payloadReference}. It goes to {@see SelfBilledInvoiceIntakeInterface::receive()}: a
 * self-billed invoice becomes a draft sales invoice or a refusal, any other
 * invoice goes to the supplier invoice intake (sales-einvoice-exchange
 * design D3, REQ-SEIX-006).
 * And `nl.conduction.payment.settlement`, one per payout of a payment provider
 * (receivables-provider-payouts design D1, REQ-RPPO-001): its `data` becomes
 * one `ProviderPayout`, kept once per settlement reference, through
 * {@see PayoutIntakeService::record()}.
 *
 * Fail-soft: a failure is logged and never bubbles into integriq's write.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-2.1
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentReconciliationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\SelfBilledInvoiceIntakeInterface;
use OCA\Shillinq\Service\Payout\PayoutIntakeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Routes integriq's CloudEvent objects to shillinq by CloudEvent type.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-2.1
 */
class IntegriqCloudEventListener implements IEventListener {
	/**
	 * The OpenRegister register integriq saves its CloudEvents in.
	 *
	 * The integriq `EventService::emitCloudEvent()` saves with `register: 'integriq'`,
	 * and integriq's `lib/Settings/integriq_register.json` declares that slug. A
	 * register slug is data, frozen when an app is renamed.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'integriq';

	/**
	 * The schema integriq saves its CloudEvents in (`schema: 'event'`).
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'event';

	/**
	 * CloudEvent type integriq emits on every payment outcome change
	 * (integriq `PaymentIntentService::EVENT_TYPE_STATUS`).
	 *
	 * @var string
	 */
	public const TYPE_PAYMENT_STATUS = 'nl.conduction.payment.status';

	/**
	 * CloudEvent type integriq emits for a document its Peppol access point
	 * received (integriq `PeppolTransmissionService::EVENT_TYPE_INBOUND_RECEIVED`).
	 *
	 * @var string
	 */
	public const TYPE_PEPPOL_INBOUND = 'nl.conduction.peppol.inbound.received';

	/**
	 * CloudEvent type integriq emits once per payout of a payment provider
	 * (receivables-provider-payouts design D1).
	 *
	 * @var string
	 */
	public const TYPE_PAYMENT_SETTLEMENT = 'nl.conduction.payment.settlement';

	/**
	 * The CloudEvent types this listener takes.
	 *
	 * @var list<string>
	 */
	private const HANDLED_TYPES = [self::TYPE_PAYMENT_STATUS, self::TYPE_PAYMENT_SETTLEMENT, self::TYPE_PEPPOL_INBOUND];

	/**
	 * Gateway recorded on the reconciled request (design D3). integriq's
	 * payment CloudEvent does not name its provider.
	 *
	 * @var string
	 */
	private const PAYMENT_GATEWAY = 'mollie';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's register and schema ids to slugs.
	 * @param PaymentReconciliationServiceInterface $reconciliation Applies a payment outcome to its request.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 * @param SelfBilledInvoiceIntakeInterface $inboundInvoices Takes a received Peppol invoice.
	 * @param PayoutIntakeService $payoutIntake Records a reported settlement as a ProviderPayout.
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly PaymentReconciliationServiceInterface $reconciliation,
		private readonly LoggerInterface $logger,
		private readonly SelfBilledInvoiceIntakeInterface $inboundInvoices,
		private readonly PayoutIntakeService $payoutIntake,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister object creation; act only on integriq CloudEvents.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-2.1
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		try {
			$cloudEvent = $this->handledCloudEvent(entity: $event->getObject());
			if ($cloudEvent === null) {
				return;
			}

			if ($cloudEvent['type'] === self::TYPE_PEPPOL_INBOUND) {
				$this->receiveInbound(data: $cloudEvent['data'] ?? null);
				return;
			}

			if ($cloudEvent['type'] === self::TYPE_PAYMENT_SETTLEMENT) {
				$this->recordSettlement(data: $cloudEvent['data'] ?? null);
				return;
			}

			$this->reconcilePaymentStatus(data: $cloudEvent['data'] ?? null);
		} catch (Throwable $e) {
			$this->logger->warning('IntegriqCloudEventListener: failed to apply an integriq CloudEvent; skipped', ['exception' => $e->getMessage()]);
		}//end try
	}//end handle()

	/**
	 * The created object's CloudEvent when it is an integriq payment status or
	 * settlement or inbound Peppol document in the CloudEvent schema, else null.
	 *
	 * @param mixed $entity The created ObjectEntity, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function handledCloudEvent(mixed $entity): array|null {
		$cloudEvent = $entity?->getObject();
		if (is_array($cloudEvent) === false || in_array(($cloudEvent['type'] ?? null), self::HANDLED_TYPES, true) === false) {
			return null;
		}

		// The type is cheap to read; the register and schema cost a mapper
		// lookup each, so they are checked only for a matching type.
		if ($this->schemaResolver->matchesRegisterAndSchema(
			entity: $entity,
			registerSlug: self::REGISTER_SLUG,
			schemaSlug: self::SCHEMA_SLUG
		) === false
		) {
			return null;
		}

		return $cloudEvent;
	}//end handledCloudEvent()

	/**
	 * Feed a payment status envelope into the shared reconciliation.
	 *
	 * @param mixed $data The CloudEvent `data`: {paymentIntentId, outcome, errorCode,
	 *                    errorMessage, settlementReference, gatewayFeeAmount}.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-2.1
	 */
	private function reconcilePaymentStatus(mixed $data): void {
		if (is_array($data) === false) {
			$this->logger->info('IntegriqCloudEventListener: payment status CloudEvent without data; skipped');
			return;
		}

		$outcome = $this->reconciliation->reconcile(gateway: self::PAYMENT_GATEWAY, event: $data);
		$this->logger->info(
			'IntegriqCloudEventListener: integriq payment status reconciled',
			['outcome' => ($data['outcome'] ?? null), 'result' => $outcome['result'], 'schema' => $outcome['schema']]
		);
	}//end reconcilePaymentStatus()

	/**
	 * Hand a received Peppol document to the inbound invoice intake.
	 *
	 * @param mixed $data The CloudEvent `data`: {senderPeppolId, documentType, payloadReference}.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.3
	 */
	private function receiveInbound(mixed $data): void {
		if (is_array($data) === false) {
			$this->logger->info('IntegriqCloudEventListener: inbound Peppol CloudEvent without data; skipped');
			return;
		}

		$outcome = $this->inboundInvoices->receive(data: $data);
		$this->logger->info(
			'IntegriqCloudEventListener: inbound Peppol document taken in',
			['result' => $outcome['result'], 'reason' => ($outcome['reason'] ?? null)]
		);
	}//end receiveInbound()

	/**
	 * Keep a reported settlement as one ProviderPayout.
	 *
	 * @param mixed $data The CloudEvent `data` of design D1.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-2.1
	 */
	private function recordSettlement(mixed $data): void {
		if (is_array($data) === false) {
			$this->logger->info('IntegriqCloudEventListener: settlement CloudEvent without data; skipped');
			return;
		}

		$outcome = $this->payoutIntake->record(settlement: $data, defaultProvider: self::PAYMENT_GATEWAY);
		$this->logger->info(
			'IntegriqCloudEventListener: integriq settlement taken',
			['settlementReference' => ($data['settlementReference'] ?? null), 'result' => $outcome['result'], 'reason' => $outcome['reason']]
		);
	}//end recordSettlement()
}//end class
