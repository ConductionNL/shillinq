<?php

/**
 * Integriq CloudEvent Listener
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\PaymentReconciliationService;
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
	 * Gateway recorded on the reconciled request (design D3). integriq's
	 * payment CloudEvent does not name its provider.
	 *
	 * @var string
	 */
	private const PAYMENT_GATEWAY = 'mollie';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's register and schema ids to slugs.
	 * @param PaymentReconciliationService $reconciliation Applies a payment outcome to its request.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly PaymentReconciliationService $reconciliation,
		private readonly LoggerInterface $logger,
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
			$entity = $event->getObject();
			$cloudEvent = $entity?->getObject();
			if (is_array($cloudEvent) === false || ($cloudEvent['type'] ?? null) !== self::TYPE_PAYMENT_STATUS) {
				return;
			}

			// The type is cheap to read; the register and schema cost a mapper
			// lookup each, so they are checked only for a matching type.
			if ($this->schemaResolver->matchesRegisterAndSchema(
				entity: $entity,
				registerSlug: self::REGISTER_SLUG,
				schemaSlug: self::SCHEMA_SLUG
			) === false
			) {
				return;
			}

			$this->reconcilePaymentStatus(data: $cloudEvent['data'] ?? null);
		} catch (Throwable $e) {
			$this->logger->warning(
				'IntegriqCloudEventListener: failed to apply an integriq CloudEvent; skipped',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end handle()

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
}//end class
