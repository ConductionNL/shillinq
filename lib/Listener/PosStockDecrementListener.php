<?php

/**
 * POS Stock Decrement Listener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use DateTime;
use OCA\Shillinq\AppInfo\AppIdentity;
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
		if (class_exists(\OCA\Pipelinq\Event\PosStockMovedEvent::class) === false
			|| ($event instanceof \OCA\Pipelinq\Event\PosStockMovedEvent) === false
		) {
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
		$sale = $this->saleOf(event: $event);
		$lines = $event->getLines();
		if ($this->hasContent(sale: $sale, lines: $lines) === false) {
			$this->logNothingToProcess(sale: $sale);
			return;
		}

		$issueLines = $this->issuableLines(saleLines: $this->saleLines(sale: $sale, lines: $lines));
		if ($issueLines === []) {
			return;
		}

		$result = $this->issue(sale: $sale, issueLines: $issueLines);
		$this->auditBlockedLines(sale: $sale, result: $result);

	}//end process()

	/**
	 * The event's trimmed transaction id and administration.
	 *
	 * @param \OCA\Pipelinq\Event\PosStockMovedEvent $event The typed event.
	 *
	 * @return PosSaleRun
	 */
	private function saleOf(\OCA\Pipelinq\Event\PosStockMovedEvent $event): PosSaleRun {
		return new PosSaleRun(
			posTxnId: trim((string)$event->getTransactionUuid()),
			administrationId: trim((string)$event->getAdministrationId())
		);

	}//end saleOf()

	/**
	 * Whether the event names a transaction and an administration and
	 * carries lines.
	 *
	 * @param PosSaleRun $sale  The sale.
	 * @param mixed      $lines The event's lines.
	 *
	 * @return bool
	 */
	private function hasContent(PosSaleRun $sale, mixed $lines): bool {
		return $sale->posTxnId !== '' && $sale->administrationId !== '' && is_array($lines) === true && $lines !== [];

	}//end hasContent()

	/**
	 * Log an event that carries no transaction id, administration or lines.
	 *
	 * @param PosSaleRun $sale The (partly empty) sale.
	 *
	 * @return void
	 */
	private function logNothingToProcess(PosSaleRun $sale): void {
		$this->logger->info(
			'PosStockDecrementListener: event carried no posTxnId/administrationId/lines — nothing to process',
			['posTxnId' => $sale->posTxnId, 'administrationId' => $sale->administrationId]
		);

	}//end logNothingToProcess()

	/**
	 * The event's array lines, read as sale lines with their event index.
	 *
	 * @param PosSaleRun   $sale  The sale.
	 * @param array<mixed> $lines The event's lines.
	 *
	 * @return list<PosSaleLine>
	 */
	private function saleLines(PosSaleRun $sale, array $lines): array {
		$saleLines = [];
		foreach ($lines as $index => $line) {
			if (is_array($line) === true) {
				$saleLines[] = new PosSaleLine(
					posTxnId: $sale->posTxnId,
					administrationId: $sale->administrationId,
					lineIndex: (int)$index,
					productRef: trim((string)($line['productRef'] ?? '')),
					quantity: (float)($line['qty'] ?? 0)
				);
			}
		}

		return $saleLines;

	}//end saleLines()

	/**
	 * The issue lines for every stock-tracked sale line; every other line is
	 * audited, in event order.
	 *
	 * @param list<PosSaleLine> $saleLines The sale lines.
	 *
	 * @return list<array{productReference:string,quantityShipped:float}>
	 */
	private function issuableLines(array $saleLines): array {
		$issueLines = [];
		foreach ($saleLines as $line) {
			$reason = $this->unmatchedReason(line: $line);
			if ($reason !== '') {
				$this->auditUnmatchedLine(line: $line->because(reason: $reason));
				continue;
			}

			$issueLines[] = [
				'productReference' => $line->productRef,
				'quantityShipped' => $line->quantity,
			];
		}

		return $issueLines;

	}//end issuableLines()

	/**
	 * Why a sale line cannot be issued, or '' when it can.
	 *
	 * @param PosSaleLine $line The sale line.
	 *
	 * @return string
	 */
	private function unmatchedReason(PosSaleLine $line): string {
		if ($line->productRef === '' || $line->quantity <= 0) {
			return 'no_product_ref';
		}

		if ($this->hasInventoryStock(administrationId: $line->administrationId, sku: $line->productRef) === false) {
			return 'no_inventory_stock';
		}

		return '';

	}//end unmatchedReason()

	/**
	 * Issue the lines through the reused stock-issue pipeline and log its
	 * counts.
	 *
	 * Reuse issueForDelivery() unmodified: the synthetic delivery id
	 * (pos-{posTxnId}) is the referenceDocumentUri key its OWN existing
	 * idempotency check dedupes on, so a redelivered event for the same
	 * posTxnId is a no-op for free (REQ: idempotent on posTxnId).
	 *
	 * @param PosSaleRun                                                  $sale       The sale.
	 * @param list<array{productReference:string,quantityShipped:float}> $issueLines The lines to issue.
	 *
	 * @return array<string,mixed> The pipeline's result.
	 */
	private function issue(PosSaleRun $sale, array $issueLines): array {
		$result = $this->dispatchService->issueForDelivery(
			delivery: [
				'id' => self::POS_DELIVERY_ID_PREFIX . $sale->posTxnId,
				'administrationId' => $sale->administrationId,
				'lines' => $issueLines,
			]
		);

		$this->logger->info(
			'PosStockDecrementListener: POS sale dispatched to the reused stock-issue pipeline',
			$this->dispatchCounts(sale: $sale, result: $result)
		);

		return $result;

	}//end issue()

	/**
	 * The log context of a dispatched sale.
	 *
	 * @param PosSaleRun          $sale   The sale.
	 * @param array<string,mixed> $result The pipeline's result.
	 *
	 * @return array<string,mixed>
	 */
	private function dispatchCounts(PosSaleRun $sale, array $result): array {
		return [
			'posTxnId' => $sale->posTxnId,
			'issued' => (int)($result['issued'] ?? 0),
			'skipped' => (int)($result['skipped'] ?? 0),
			'blocked' => (int)($result['blocked'] ?? 0),
		];

	}//end dispatchCounts()

	/**
	 * Audit every line the pipeline blocked.
	 *
	 * A lot-unsellable line was refused by the (reused) LotSellabilityGuard
	 * inside issueForDelivery() — surfaced the same as an unmatched line so
	 * it never vanishes silently.
	 *
	 * @param PosSaleRun          $sale   The sale.
	 * @param array<string,mixed> $result The pipeline's result.
	 *
	 * @return void
	 */
	private function auditBlockedLines(PosSaleRun $sale, array $result): void {
		foreach (($result['blockedLines'] ?? []) as $blocked) {
			$this->auditUnmatchedLine(
				line: new PosSaleLine(
					posTxnId: $sale->posTxnId,
					administrationId: $sale->administrationId,
					lineIndex: (int)($blocked['lineIndex'] ?? -1),
					productRef: (string)($blocked['sku'] ?? ''),
					quantity: (float)($blocked['quantityRequired'] ?? 0),
					reason: 'unsellable_lot'
				)
			);
		}

	}//end auditBlockedLines()

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
			$rows = $this->inventoryStockRows(administrationId: $administrationId, sku: $sku);
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
	 * The InventoryStock rows of one SKU in one administration.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $sku              Product SKU.
	 *
	 * @return mixed The findAll() result.
	 */
	private function inventoryStockRows(string $administrationId, string $sku): mixed {
		return $this->objectService()
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

	}//end inventoryStockRows()

	/**
	 * Record + surface an unmatched (or blocked) POS stock line: logs at
	 * error level and raises a Nextcloud notification to every `admin` group
	 * member — the reconciliation/audit surface the spec requires. Never
	 * throws (fail-soft): a notification-dispatch failure is only logged.
	 *
	 * @param PosSaleLine $line The audited line, with its reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
	 */
	private function auditUnmatchedLine(PosSaleLine $line): void {
		$this->logger->error(
			'PosStockDecrementListener: unmatched POS stock line — surfaced for reconciliation, NOT silently dropped',
			$this->unmatchedContext(line: $line)
		);

		try {
			$this->notifyAdmins(line: $line);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PosStockDecrementListener: failed to notify admins of an unmatched line',
				['posTxnId' => $line->posTxnId, 'exception' => $e->getMessage()]
			);
		}//end try

	}//end auditUnmatchedLine()

	/**
	 * The log context of an audited line.
	 *
	 * @param PosSaleLine $line The audited line.
	 *
	 * @return array<string,mixed>
	 */
	private function unmatchedContext(PosSaleLine $line): array {
		return [
			'posTxnId' => $line->posTxnId,
			'administrationId' => $line->administrationId,
			'lineIndex' => $line->lineIndex,
			'productRef' => $line->productRef,
			'quantity' => $line->quantity,
			'reason' => $line->reason,
		];

	}//end unmatchedContext()

	/**
	 * Notify every member of the `admin` group (none when the group is absent).
	 *
	 * @param PosSaleLine $line The audited line.
	 *
	 * @return void
	 */
	private function notifyAdmins(PosSaleLine $line): void {
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return;
		}

		foreach ($adminGroup->getUsers() as $user) {
			$this->notifyUser(uid: $user->getUID(), line: $line);
		}

	}//end notifyAdmins()

	/**
	 * Raise the unmatched-line notification for one user.
	 *
	 * @param string      $uid  The user id.
	 * @param PosSaleLine $line The audited line.
	 *
	 * @return void
	 */
	private function notifyUser(string $uid, PosSaleLine $line): void {
		$notification = $this->notificationMgr->createNotification();
		$notification->setApp(AppIdentity::APP_ID)
			->setUser($uid)
			->setObject('pos-stock-unmatched-line', $line->posTxnId . '#' . $line->lineIndex)
			->setSubject(self::NOTIFICATION_SUBJECT_UNMATCHED, $this->unmatchedSubject(line: $line))
			->setDateTime(new DateTime());
		$this->notificationMgr->notify($notification);

	}//end notifyUser()

	/**
	 * The notification subject parameters of an audited line.
	 *
	 * @param PosSaleLine $line The audited line.
	 *
	 * @return array<string,mixed>
	 */
	private function unmatchedSubject(PosSaleLine $line): array {
		return [
			'posTxnId' => $line->posTxnId,
			'productRef' => $line->productRef,
			'quantity' => $line->quantity,
			'reason' => $line->reason,
		];

	}//end unmatchedSubject()

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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
