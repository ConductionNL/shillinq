<?php

/**
 * Peppol Delivery Status Listener
 *
 * Consumes the cross-app `nl.conduction.peppol.delivery.status` cloud event
 * (REQ-EINV-005) emitted by openconnector's Peppol access point and advances
 * the `ARInvoice.deliveryStatus` sub-lifecycle declared in
 * add-shillinq-einvoicing-ubl-peppol.json (REQ-AR-011). The event's `status`
 * field (`queued|sent|delivered|rejected|failed`) maps 1:1 onto
 * `deliveryStatus` — the listener only applies transitions that are legal per
 * the declared state graph (never hand-rolls new state) and persists the
 * event `detail` as `deliveryDetail`. A `rejected` outcome additionally
 * notifies every `ar-controller` in the invoice's administration
 * (ADR-031 — imperative notification dispatch is a justified external-event
 * consumption surface).
 *
 * Registered against the literal event-name string (not an `Event` subclass)
 * via `IRegistrationContext::registerEventListener()`, which accepts
 * `string|class-string<T>` for exactly this cross-app cloud-event pattern
 * (mirrors {@see \OCA\Shillinq\Service\BudgetImpactEmitter}'s emit side).
 *
 * Fail-soft: any exception is logged but never bubbles up — a missed/garbled
 * delivery-status event can be re-driven by openconnector's own retry policy;
 * this listener never blocks the NC event bus.
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
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use DateTime;
use OCA\Shillinq\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Advances ARInvoice.deliveryStatus from `nl.conduction.peppol.delivery.status` events.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 */
class PeppolDeliveryStatusListener implements IEventListener {
	/**
	 * Cross-app cloud-event name this listener consumes.
	 *
	 * @var string
	 */
	public const EVENT_NAME = 'nl.conduction.peppol.delivery.status';

	/**
	 * Notification object type for the finance-operator "rejected" alert.
	 *
	 * @var string
	 */
	private const NOTIFICATION_OBJECT_TYPE = 'ar_invoice';

	/**
	 * Notification subject identifier for a rejected e-invoice.
	 *
	 * @var string
	 */
	private const NOTIFICATION_SUBJECT_REJECTED = 'einvoice_delivery_rejected';

	/**
	 * Declared delivery sub-lifecycle transitions (REQ-AR-011), keyed
	 * `"<from>|<to>"`. Only these pairs are applied; anything else is logged
	 * and skipped (fail-soft, never corrupts state).
	 *
	 * @var array<string,bool>
	 */
	private const ALLOWED_TRANSITIONS = [
		'queued|sent' => true,
		'sent|delivered' => true,
		'queued|rejected' => true,
		'sent|rejected' => true,
		'queued|failed' => true,
		// Idempotent re-delivery of the same status is harmless.
		'sent|sent' => true,
		'delivered|delivered' => true,
		'rejected|rejected' => true,
		'failed|failed' => true,
	];

	/**
	 * Construct the listener.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched
	 *                                      lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param INotificationManager $notificationManager NC notification dispatcher (rejected alert).
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a `nl.conduction.peppol.delivery.status` event.
	 *
	 * @param Event $event The dispatched GenericEvent carrying
	 *                     {objectUri, transmissionId, status, timestamp, detail}.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof GenericEvent === false) {
			return;
		}

		try {
			$payload = $event->getArguments();
			$this->apply(payload: $payload);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PeppolDeliveryStatusListener: failed to apply delivery-status event — fail-soft',
				['exception' => $e->getMessage()]
			);
		}

	}//end handle()

	/**
	 * Apply one delivery-status payload to the matching ARInvoice.
	 *
	 * @param array<string,mixed> $payload {objectUri, transmissionId, status, timestamp, detail}.
	 *
	 * @return void
	 */
	private function apply(array $payload): void {
		$objectUri = trim((string)($payload['objectUri'] ?? ''));
		$status = trim((string)($payload['status'] ?? ''));
		$detail = (string)($payload['detail'] ?? '');

		if ($objectUri === '' || $status === '') {
			return;
		}

		$id = $this->extractId(objectUri: $objectUri);
		if ($id === '') {
			return;
		}

		$invoice = $this->findByIdOrInvoiceNumber(id: $id);
		if ($invoice === null) {
			$this->logger->info(
				'PeppolDeliveryStatusListener: no matching ARInvoice for objectUri — skipping',
				['objectUri' => $objectUri]
			);
			return;
		}

		$currentStatus = (string)($invoice['deliveryStatus'] ?? 'not-sent');
		$transitionKey = $currentStatus . '|' . $status;
		if (isset(self::ALLOWED_TRANSITIONS[$transitionKey]) === false) {
			$this->logger->info(
				'PeppolDeliveryStatusListener: illegal delivery-status transition — skipping',
				['from' => $currentStatus, 'to' => $status, 'objectUri' => $objectUri]
			);
			return;
		}

		$invoice['deliveryStatus'] = $status;
		$invoice['deliveryDetail'] = $detail;
		$transmissionId = trim((string)($payload['transmissionId'] ?? ''));
		if ($transmissionId !== '') {
			$invoice['transmissionId'] = $transmissionId;
		}

		$this->saveObject(schema: 'ARInvoice', object: $invoice);

		if ($status === 'rejected') {
			$this->notifyFinanceOperators(invoice: $invoice, detail: $detail);
		}

	}//end apply()

	/**
	 * Extract the trailing id segment from an `openregister://{register}/ARInvoice/{id}` URI.
	 *
	 * @param string $objectUri The event's objectUri.
	 *
	 * @return string
	 */
	private function extractId(string $objectUri): string {
		$parts = explode('/', rtrim($objectUri, '/'));

		return trim((string)end($parts));
	}//end extractId()

	/**
	 * Find an ARInvoice by OR object id, falling back to invoiceNumber (the
	 * objectUri id segment may be either, depending on whether the record had
	 * an OR-assigned id at emission time).
	 *
	 * @param string $id Candidate id or invoiceNumber.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findByIdOrInvoiceNumber(string $id): ?array {
		$byId = $this->findAll(schema: 'ARInvoice', filters: ['id' => $id]);
		foreach ($byId as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		$byNumber = $this->findAll(schema: 'ARInvoice', filters: ['invoiceNumber' => $id]);
		foreach ($byNumber as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findByIdOrInvoiceNumber()

	/**
	 * Notify every `ar-controller` in the invoice's administration that the
	 * e-invoice was rejected (REQ-EINV-005 — surfaced, never silent).
	 *
	 * @param array<string,mixed> $invoice Updated ARInvoice record.
	 * @param string $detail Rejection detail from the event.
	 *
	 * @return void
	 */
	private function notifyFinanceOperators(array $invoice, string $detail): void {
		$administrationId = (string)($invoice['administrationId'] ?? '');
		$invoiceNumber = (string)($invoice['invoiceNumber'] ?? '');
		if ($administrationId === '') {
			return;
		}

		$memberships = $this->findAll(
			schema: 'AdministrationMembership',
			filters: [
				'administrationId' => $administrationId,
				'role' => 'ar-controller',
			]
		);

		foreach ($memberships as $membership) {
			$userId = trim((string)($membership['userId'] ?? ''));
			if ($userId === '') {
				continue;
			}

			try {
				$notification = $this->notificationManager->createNotification();
				$notification
					->setApp(Application::APP_ID)
					->setUser($userId)
					->setDateTime(new DateTime())
					->setObject(self::NOTIFICATION_OBJECT_TYPE, $invoiceNumber)
					->setSubject(
						self::NOTIFICATION_SUBJECT_REJECTED,
						[
							'invoiceNumber' => $invoiceNumber,
							'detail' => $detail,
						]
					);
				$this->notificationManager->notify($notification);
			} catch (Throwable $e) {
				$this->logger->warning(
					'PeppolDeliveryStatusListener: failed to dispatch rejected-e-invoice notification',
					['invoiceNumber' => $invoiceNumber, 'userId' => $userId, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

	}//end notifyFinanceOperators()

	/**
	 * Persist an object via the real ObjectService API.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $object Object payload.
	 *
	 * @return void
	 */
	private function saveObject(string $schema, array $object): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService
				->setRegister($this->register())
				->setSchema($schema)
				->saveObject($object);
		} catch (Throwable $e) {
			$this->logger->error(
				'PeppolDeliveryStatusListener: failed to persist ARInvoice delivery-status update',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
		}

	}//end saveObject()

	/**
	 * Fetch all matching records via the real ObjectService API.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (Throwable $e) {
			$this->logger->info(
				'PeppolDeliveryStatusListener: OR query unavailable — skipping',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Resolve the OpenRegister register slug from app config (defaults to "shillinq").
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
