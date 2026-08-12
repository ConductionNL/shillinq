<?php

/**
 * Delivery Dispatch Listener
 *
 * Change inventory-sales-issue-cogs-trigger: reacts to OpenRegister's
 * `ObjectTransitionedEvent` on the `Delivery` schema — the missing
 * outbound trigger between the sales funnel
 * (bookkeeping-quote-order-invoice) and the existing stock-movement +
 * valuation + COGS pipeline (inventory-stock-movement-ledger,
 * inventory-valuation-fifo-avg, inventory-cogs-posting).
 *
 *   - `draft -> confirmed`:  {@see \OCA\Shillinq\Service\SalesDispatchStockIssueService::issueForDelivery()}
 *     creates one posted `issue` StockMove per stock-tracked line, which
 *     feeds {@see \OCA\Shillinq\Listener\StockMoveTransitionedListener}
 *     UNCHANGED.
 *   - `* -> cancelled`:      {@see \OCA\Shillinq\Service\SalesDispatchStockIssueService::reverseForDelivery()}
 *     reverses any StockMove the delivery issued via the existing
 *     `StockMove.cancel` transition.
 *
 * Fail-soft, mirroring StockMoveTransitionedListener: any downstream
 * exception is logged but never bubbles up to the Delivery transition
 * itself.
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
 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Service\SalesDispatchStockIssueService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatcher from Delivery confirm/cancel -> SalesDispatchStockIssueService.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
 */
class DeliveryDispatchListener implements IEventListener {
	/**
	 * Construct the listener with DI dependencies.
	 *
	 * @param SalesDispatchStockIssueService $dispatchService The issue/reversal service.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly SalesDispatchStockIssueService $dispatchService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event Event from OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent === false) {
			return;
		}

		try {
			if ($this->isDeliverySchema(schema: (string)$event->getSchema()) === false) {
				return;
			}

			$entity = $event->getObject();
			$delivery = $entity->getObject();
			if (is_array($delivery) === false) {
				return;
			}

			$to = (string)$event->getTo();
			if ($to === 'confirmed') {
				$this->dispatchService->issueForDelivery(delivery: $delivery);
				return;
			}

			if ($to === 'cancelled') {
				$this->dispatchService->reverseForDelivery(delivery: $delivery);
			}
		} catch (Throwable $e) {
			// Fail-soft: the Delivery transition itself already committed;
			// never bubble up and block it. Mirrors
			// StockMoveTransitionedListener's fail-soft contract.
			$this->logger->error(
				'DeliveryDispatchListener: dispatch failed (fail-soft)',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Schema-name match for Delivery (plain slug or namespaced).
	 *
	 * @param string $schema Schema identifier.
	 *
	 * @return bool
	 */
	private function isDeliverySchema(string $schema): bool {
		$normalised = strtolower(trim($schema));
		return ($normalised === 'delivery' || str_ends_with($normalised, '/delivery'));
	}//end isDeliverySchema()
}//end class
