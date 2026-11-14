<?php

/**
 * GR/IR Clearing Listener
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Change grir-accrual-wiring: the missing GR/IR accrual-posting trigger.
 * {@see \OCA\Shillinq\Service\GRIRClearingService} fully implements the
 * clearing (`createGRIRPosting()`) and settlement (`settleGRIRPosting()`)
 * GL postings (member 09 of `bookkeeping-purchase-order-3way`,
 * REQ-PO3W-009), but nothing ever called either method. This listener
 * reacts to OpenRegister's `ObjectTransitionedEvent` on three EXISTING,
 * unmodified lifecycle transitions:
 *
 *   - `GoodsReceiptNote * -> accepted`: {@see \OCA\Shillinq\Service\GRIRClearingService::postGRIRForGoodsReceiptAccept()}
 *     posts the clearing entry for every accepted `GoodsReceiptLine`.
 *   - `SvcReceipt confirmed -> accepted`: {@see \OCA\Shillinq\Service\GRIRClearingService::postGRIRForServiceReceiptAccept()}
 *     posts the same clearing entry for every accepted `SvcReceiptLine`.
 *   - `SupplierInvoice matching -> matched`: {@see \OCA\Shillinq\Service\GRIRClearingService::settleGRIRForMatchedInvoice()}
 *     resolves the driving `ThreeWayMatch` and posts the settlement entry.
 *
 * `SupplierInvoice.matched` — not `ThreeWayMatch` creation — is the
 * correct trigger: a `ThreeWayMatch` row is created with its terminal
 * `matchStatus` already set (see `ThreeWayMatchingEngine::evaluateMatch()`),
 * and OpenRegister does not dispatch `ObjectTransitionedEvent` for a
 * lifecycle field set at create time. `ThreeWayMatchingEngine`'s own
 * docblock documents `SupplierInvoice.matched` as the intended trigger
 * point ("...so member 09's GR/IR clearing posting fires declaratively").
 *
 * Fail-soft, mirroring DeliveryDispatchListener/StockMoveTransitionedListener:
 * any downstream exception is logged but never bubbles up to the
 * triggering transition itself (REQ-004).
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
 * @spec openspec/specs/grir-accrual-wiring/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Kernel\Contracts\GRIRClearingServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatcher from GoodsReceiptNote/SvcReceipt accept + SupplierInvoice
 * match -> GRIRClearingService.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/grir-accrual-wiring/spec.md
 */
class GRIRClearingListener implements IEventListener {
	/**
	 * The state at which each schema's GR/IR posting is triggered, in the
	 * order they are tried.
	 *
	 * @var array<string,string>
	 */
	private const TRIGGERS = [
		'goodsreceiptnote' => 'accepted',
		'svcreceipt' => 'accepted',
		'supplierinvoice' => 'matched',
	];

	/**
	 * Construct the listener with DI dependencies.
	 *
	 * @param GRIRClearingServiceInterface $grirClearingService The GR/IR posting orchestration service.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly GRIRClearingServiceInterface $grirClearingService,
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
	 * @spec openspec/specs/grir-accrual-wiring/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent === false) {
			return;
		}

		try {
			$this->dispatch(event: $event);
		} catch (Throwable $e) {
			// Fail-soft: the triggering transition itself already
			// committed; never bubble up and block it. Mirrors
			// DeliveryDispatchListener's / StockMoveTransitionedListener's
			// fail-soft contract (REQ-004).
			$this->logger->error(
				'GRIRClearingListener: GR/IR posting dispatch failed (fail-soft)',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Forward an accepted receipt or a matched supplier invoice of an
	 * administration to its GR/IR posting.
	 *
	 * @param ObjectTransitionedEvent $event The transition.
	 *
	 * @return void
	 */
	private function dispatch(ObjectTransitionedEvent $event): void {
		$target = $this->postingTarget(schema: strtolower(trim((string)$event->getSchema())), to: (string)$event->getTo());
		$object = $event->getObject()->getObject();
		$administrationId = trim((string)($object['administrationId'] ?? ''));
		if ($target === null || $administrationId === '') {
			return;
		}

		match ($target) {
			'goodsreceiptnote' => $this->grirClearingService->postGRIRForGoodsReceiptAccept(administrationId: $administrationId, grn: $object),
			'svcreceipt' => $this->grirClearingService->postGRIRForServiceReceiptAccept(administrationId: $administrationId, receipt: $object),
			'supplierinvoice' => $this->settleMatchedInvoice(administrationId: $administrationId, invoice: $object),
		};

	}//end dispatch()

	/**
	 * The schema whose GR/IR posting this transition triggers, or null.
	 *
	 * @param string $schema The lower-cased, trimmed schema identifier.
	 * @param string $to     The state reached.
	 *
	 * @return key-of<self::TRIGGERS>|null
	 */
	private function postingTarget(string $schema, string $to): string|null {
		foreach (self::TRIGGERS as $target => $state) {
			if ($to === $state && $this->isSchema(normalisedSchema: $schema, target: $target) === true) {
				return $target;
			}
		}

		return null;

	}//end postingTarget()

	/**
	 * Settle the GR/IR accrual of a matched supplier invoice that has an id.
	 *
	 * @param string               $administrationId The administration.
	 * @param array<string, mixed> $invoice          The SupplierInvoice.
	 *
	 * @return void
	 */
	private function settleMatchedInvoice(string $administrationId, array $invoice): void {
		$invoiceId = (string)($invoice['id'] ?? ($invoice['@self']['id'] ?? ''));
		if ($invoiceId === '') {
			return;
		}

		$this->grirClearingService->settleGRIRForMatchedInvoice(
			administrationId: $administrationId,
			invoiceId: $invoiceId
		);

	}//end settleMatchedInvoice()

	/**
	 * Schema-name match (plain slug or namespaced).
	 *
	 * @param string $normalisedSchema Already-lowercased, trimmed schema identifier.
	 * @param string $target Target slug, lowercase.
	 *
	 * @return bool
	 */
	private function isSchema(string $normalisedSchema, string $target): bool {
		return ($normalisedSchema === $target || str_ends_with($normalisedSchema, '/' . $target));
	}//end isSchema()
}//end class
