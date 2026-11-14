<?php

/**
 * Webshop Order Listener
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Hands every saved WebshopOrder that waits for its invoice to the
 * WebshopOrderInvoicer (REQ-SWO-001). The schema is resolved through
 * ListenerSchemaResolver, because OpenRegister stamps the entity with numeric
 * register and schema ids. Fail-soft: a failure is logged and never rolls
 * back the connector's save; the order stays received and is picked up on its
 * next save.
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
 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Service\Webshop\WebshopOrderInvoicer;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatcher from a saved WebshopOrder to its invoicer.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.1
 */
class WebshopOrderListener implements IEventListener {

	/**
	 * Construct the listener.
	 *
	 * @param WebshopOrderInvoicer            $invoicer       Writes the order's invoice.
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface                 $logger         Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly WebshopOrderInvoicer $invoicer,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectCreatedEvent or ObjectUpdatedEvent.
	 *
	 * @param Event $event Event from OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.1
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false && ($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$entity = $event->getObject();
		if ($entity === null || $this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: 'WebshopOrder') === false) {
			return;
		}

		$order = $entity->getObject();
		if (is_array($order) === false) {
			return;
		}

		$orderId = ObjectIdentifier::resolve(saved: $entity);
		try {
			if (WebshopOrderInvoicer::isWaiting(order: $order) === true) {
				$this->invoicer->handle(order: $order, orderId: $orderId, today: gmdate('Y-m-d'));
				return;
			}

			// REQ-SWO-004: a payment status sent later as an update of the order settles its invoice.
			$this->invoicer->settle(order: $order, orderId: $orderId);
		} catch (Throwable $e) {
			$this->logger->error(
				'WebshopOrderListener: the web shop order could not be invoiced',
				['orderId' => $orderId, 'exception' => $e->getMessage()]
			);
		}
	}//end handle()
}//end class
