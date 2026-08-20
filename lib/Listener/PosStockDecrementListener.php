<?php

/**
 * POS Stock Decrement Listener.
 *
 * Change inventory-pos-decrement (shillinq#504) — the missing outbound
 * trigger between a pipelinq POS sale and shillinq's existing outbound
 * stock-issue + valuation + COGS pipeline (inventory-sales-issue-cogs-trigger).
 * Consumes pipelinq's cross-app `nl.pipelinq.pos.stock.moved` typed event
 * (`\OCA\Pipelinq\Event\PosStockMovedEvent`) and, for every sold line whose
 * product resolves to a shillinq `InventoryStock` row, delegates to
 * {@see \OCA\Shillinq\Service\SalesDispatchStockIssueService::issueForDelivery()}
 * — the SAME reused method {@see \OCA\Shillinq\Listener\DeliveryDispatchListener}
 * already calls for shillinq's own Delivery-driven sales. No decrement or
 * valuation/COGS logic is reimplemented here: `issueForDelivery()` creates a
 * posted `issue` StockMove per line, which feeds the existing, UNMODIFIED
 * `StockMoveTransitionedListener` -> valuation engine -> `CogsPosterService`
 * pipeline exactly as it already does for a confirmed Delivery.
 *
 * IMPORTANT correction vs. the change's draft design.md: that document names
 * `InventoryGlAdjustmentPoster` as the COGS-posting collaborator. Verified
 * against the actual pipeline: `InventoryGlAdjustmentPoster` is the shared
 * balanced-posting adapter used ONLY by `LandedCostAllocationService` /
 * `NrvWriteDownService` (inventory VALUATION adjustments — landed cost,
 * NRV write-down), not the sale/COGS path. The real sale-COGS poster is
 * `CogsPosterService`, invoked automatically once `issueForDelivery()` posts
 * the StockMove (see `DeliveryDispatchListener` + `StockMoveTransitionedListener`
 * docblocks). Calling `InventoryGlAdjustmentPoster` here in addition would
 * DOUBLE-POST a COGS entry — so this listener does not call it.
 *
 * Reuses `issueForDelivery()`'s own per-line idempotency
 * (`referenceDocumentUri`) for the POS-transaction idempotency requirement:
 * the synthetic delivery id passed is `pos-{posTxnId}` (distinguishable from
 * a real shillinq Delivery in the audit trail), so a redelivered
 * PosStockMovedEvent for the same posTxnId is a no-op — no separate dedup
 * marker is hand-rolled.
 *
 * A sold line whose product identifier does not resolve to any shillinq
 * `InventoryStock` row for the administration (pipelinq could not resolve a
 * SKU, or the SKU has no shillinq inventory item) is NEVER silently dropped:
 * it is logged at error level AND surfaced as a Nextcloud notification to
 * every member of the `admin` group (see
 * {@see \OCA\Shillinq\Notification\PosStockUnmatchedLineNotifier}) — the
 * reconciliation/audit surface the change's spec requires.
 *
 * Fail-closed guard: inert (returns immediately) when pipelinq is not
 * installed / the event class does not exist, mirroring
 * {@see \OCA\Shillinq\Listener\SigningConcludedListener}'s docudesk
 * soft-dependency guard. Fail-soft processing: any downstream exception is
 * logged but never bubbles up into pipelinq's synchronous dispatch — the
 * POS sale itself already committed.
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
 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use DateTime;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\SalesDispatchStockIssueService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Dispatcher from pipelinq's PosStockMovedEvent -> SalesDispatchStockIssueService.
 *
 * @implements IEventListener<Event>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
 */
class PosStockDecrementListener implements IEventListener {
	/**
	 * Prefix distinguishing a synthetic POS-sale "delivery" id (built from
	 * the pipelinq posTxnId) from a real shillinq Delivery id in the
	 * StockMove.referenceDocumentUri audit trail.
	 *
	 * @var string
	 */
	private const POS_DELIVERY_ID_PREFIX = 'pos-';

	/**
	 * Notification subject for an unmatched POS stock line, prepared for
	 * display by {@see \OCA\Shillinq\Notification\PosStockUnmatchedLineNotifier}.
	 *
	 * @var string
	 */
	public const NOTIFICATION_SUBJECT_UNMATCHED = 'pos_stock_unmatched_line';

	/**
	 * Construct the listener with DI dependencies.
	 *
	 * @param SalesDispatchStockIssueService $dispatchService The reused issue/reversal service.
	 * @param IAppConfig $appConfig App config for the register slug (same key SalesDispatchStockIssueService reads).
	 * @param IGroupManager $groupManager Resolves the `admin` group for the audit surface.
	 * @param IManager $notificationMgr Nextcloud notification manager for the audit surface.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly SalesDispatchStockIssueService $dispatchService,
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
		private readonly IManager $notificationMgr,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Handle a pipelinq PosStockMovedEvent.
	 *
	 * Fail-closed: inert when pipelinq's event class is not autoloadable
	 * (pipelinq not installed) or the dispatched event is not one.
	 * Fail-soft once past the guard: a processing exception is logged, never
	 * rethrown into pipelinq's synchronous dispatch.
	 *
	 * @param Event $event Event dispatched by pipelinq (or anything else — filtered below).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
	 */
	public function handle(Event $event): void {
		if (class_exists(\OCA\Pipelinq\Event\PosStockMovedEvent::class) === false) {
			return;
		}

		if (($event instanceof \OCA\Pipelinq\Event\PosStockMovedEvent) === false) {
			return;
		}

		try {
			$this->process(event: $event);
		} catch (Throwable $e) {
			$this->logger->error(
				'PosStockDecrementListener: processing failed (fail-soft)',
				['exception' => $e->getMessage()]
			);
		}

	}//end handle()

	/**
	 * Process one PosStockMovedEvent: classify each sold line as
	 * stock-tracked (delegate to issueForDelivery) or unmatched (audit), then
	 * issue the matched lines in a single reused call.
	 *
	 * @param \OCA\Pipelinq\Event\PosStockMovedEvent $event The typed event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
	 */
	private function process(\OCA\Pipelinq\Event\PosStockMovedEvent $event): void {
		$posTxnId = trim((string)$event->getTransactionUuid());
		$administrationId = trim((string)$event->getAdministrationId());
		$lines = $event->getLines();

		if ($posTxnId === '' || $administrationId === '' || is_array($lines) === false || $lines === []) {
			$this->logger->info(
				'PosStockDecrementListener: event carried no posTxnId/administrationId/lines — nothing to process',
				['posTxnId' => $posTxnId, 'administrationId' => $administrationId]
			);
			return;
		}

		$issueLines = [];
		foreach ($lines as $index => $line) {
			if (is_array($line) === false) {
				continue;
			}

			$sku = trim((string)($line['productRef'] ?? ''));
			$qty = (float)($line['qty'] ?? 0);

			if ($sku === '' || $qty <= 0) {
				$this->auditUnmatchedLine(
					posTxnId: $posTxnId,
					administrationId: $administrationId,
					lineIndex: (int)$index,
					productRef: $sku,
					quantity: $qty,
					reason: 'no_product_ref'
				);
				continue;
			}

			if ($this->hasInventoryStock(administrationId: $administrationId, sku: $sku) === false) {
				$this->auditUnmatchedLine(
					posTxnId: $posTxnId,
					administrationId: $administrationId,
					lineIndex: (int)$index,
					productRef: $sku,
					quantity: $qty,
					reason: 'no_inventory_stock'
				);
				continue;
			}

			$issueLines[] = [
				'productReference' => $sku,
				'quantityShipped' => $qty,
			];
		}//end foreach

		if ($issueLines === []) {
			return;
		}

		// Reuse issueForDelivery() unmodified: the synthetic delivery id
		// (pos-{posTxnId}) is the referenceDocumentUri key its OWN existing
		// idempotency check dedupes on, so a redelivered event for the same
		// posTxnId is a no-op for free (REQ: idempotent on posTxnId).
		$result = $this->dispatchService->issueForDelivery(
			delivery: [
				'id' => self::POS_DELIVERY_ID_PREFIX . $posTxnId,
				'administrationId' => $administrationId,
				'lines' => $issueLines,
			]
		);

		$this->logger->info(
			'PosStockDecrementListener: POS sale dispatched to the reused stock-issue pipeline',
			[
				'posTxnId' => $posTxnId,
				'issued' => (int)($result['issued'] ?? 0),
				'skipped' => (int)($result['skipped'] ?? 0),
				'blocked' => (int)($result['blocked'] ?? 0),
			]
		);

		// A lot-unsellable line was refused by the (reused) LotSellabilityGuard
		// inside issueForDelivery() — surfaced the same as an unmatched line so
		// it never vanishes silently.
		foreach (($result['blockedLines'] ?? []) as $blocked) {
			$this->auditUnmatchedLine(
				posTxnId: $posTxnId,
				administrationId: $administrationId,
				lineIndex: (int)($blocked['lineIndex'] ?? -1),
				productRef: (string)($blocked['sku'] ?? ''),
				quantity: (float)($blocked['quantityRequired'] ?? 0),
				reason: 'unsellable_lot'
			);
		}

	}//end process()

	/**
	 * Whether a shillinq `InventoryStock` row exists for (administrationId,
	 * sku) — the same stock-tracked signal
	 * {@see SalesDispatchStockIssueService}'s own internal check uses. A
	 * simple existence probe only; the actual decrement/valuation stays
	 * entirely inside the reused service.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $sku Product SKU (pipelinq's resolved productRef).
	 *
	 * @return bool True when at least one InventoryStock row matches.
	 */
	private function hasInventoryStock(string $administrationId, string $sku): bool {
		try {
			$rows = $this->objectService()
				->setRegister($this->register())
				->setSchema('InventoryStock')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'sku' => $sku,
						],
					]
				);

			return is_array($rows) === true && count($rows) > 0;
		} catch (Throwable $e) {
			$this->logger->debug(
				'PosStockDecrementListener: InventoryStock existence probe failed; treating as unmatched',
				['sku' => $sku, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end hasInventoryStock()

	/**
	 * Record + surface an unmatched (or blocked) POS stock line: logs at
	 * error level and raises a Nextcloud notification to every `admin` group
	 * member — the reconciliation/audit surface the spec requires. Never
	 * throws (fail-soft): a notification-dispatch failure is only logged.
	 *
	 * @param string $posTxnId The POS transaction id.
	 * @param string $administrationId Tenant scope.
	 * @param int $lineIndex The line's ordinal index in the event.
	 * @param string $productRef The (possibly empty) product reference.
	 * @param float $quantity The sold quantity.
	 * @param string $reason 'no_product_ref' | 'no_inventory_stock' | 'unsellable_lot'.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
	 */
	private function auditUnmatchedLine(
		string $posTxnId,
		string $administrationId,
		int $lineIndex,
		string $productRef,
		float $quantity,
		string $reason,
	): void {
		$this->logger->error(
			'PosStockDecrementListener: unmatched POS stock line — surfaced for reconciliation, NOT silently dropped',
			[
				'posTxnId' => $posTxnId,
				'administrationId' => $administrationId,
				'lineIndex' => $lineIndex,
				'productRef' => $productRef,
				'quantity' => $quantity,
				'reason' => $reason,
			]
		);

		try {
			$adminGroup = $this->groupManager->get('admin');
			if ($adminGroup === null) {
				return;
			}

			foreach ($adminGroup->getUsers() as $user) {
				$notification = $this->notificationMgr->createNotification();
				$notification->setApp(Application::APP_ID)
					->setUser($user->getUID())
					->setObject('pos-stock-unmatched-line', $posTxnId . '#' . $lineIndex)
					->setSubject(
						self::NOTIFICATION_SUBJECT_UNMATCHED,
						[
							'posTxnId' => $posTxnId,
							'productRef' => $productRef,
							'quantity' => $quantity,
							'reason' => $reason,
						]
					)
					->setDateTime(new DateTime());
				$this->notificationMgr->notify($notification);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'PosStockDecrementListener: failed to notify admins of an unmatched line',
				['posTxnId' => $posTxnId, 'exception' => $e->getMessage()]
			);
		}//end try

	}//end auditUnmatchedLine()

	/**
	 * Lazily resolve the OpenRegister ObjectService from the container.
	 *
	 * @return object
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Resolve the OR register slug, defaulting to 'shillinq' — the same
	 * app-config key {@see SalesDispatchStockIssueService::register()}
	 * reads, so the InventoryStock existence probe always looks in the exact
	 * register the reused decrement pipeline itself writes to.
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
