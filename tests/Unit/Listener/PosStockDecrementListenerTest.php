<?php

/**
 * Unit tests for PosStockDecrementListener.
 *
 * Verifies the change inventory-pos-decrement (shillinq#504) contract:
 *
 *  - fail-closed when pipelinq's PosStockMovedEvent class does not exist /
 *    the dispatched event is not one (nothing touched).
 *  - a matched line (InventoryStock row exists) delegates to the reused
 *    SalesDispatchStockIssueService::issueForDelivery() with a synthetic
 *    `pos-{posTxnId}` delivery id.
 *  - `testPosSaleProducesIssueMoveThatDrivesCogsPosting` is the correctness
 *    proof: wiring REAL SalesDispatchStockIssueService,
 *    StockMoveTransitionedListener, FifoValuationService and
 *    CogsPosterService (only ObjectService/TransitionEngine faked,
 *    in-memory — mirrors DeliveryDispatchListenerTest's proof) shows a POS
 *    sale decrements stock AND posts a balanced COGS GLTransaction via the
 *    pre-existing, UNMODIFIED pipeline.
 *  - re-delivering the SAME event for the same posTxnId is a no-op (REQ:
 *    idempotent on posTxnId) — reusing issueForDelivery()'s own
 *    referenceDocumentUri dedup, no separate marker.
 *  - an unmatched line (no InventoryStock row / no productRef) is logged
 *    AND notified to every `admin` group member — never silently dropped.
 *  - a downstream exception is logged but never rethrown (fail-soft).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
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

namespace OCA\Pipelinq\Event;

// In-test stub of pipelinq's PosStockMovedEvent contract so the listener can
// class_exists()-guard, instanceof-check, and read its getters without
// pipelinq installed. The real class lives in pipelinq
// (lib/Event/PosStockMovedEvent.php, change pos-stock-moved-event).
if (class_exists(\OCA\Pipelinq\Event\PosStockMovedEvent::class, false) === false) {
	class PosStockMovedEvent extends \OCP\EventDispatcher\Event {
		/**
		 * @param array<int, array<string, mixed>> $lines
		 */
		public function __construct(
			private readonly string $eventId,
			private readonly string $transactionUuid,
			private readonly string $transactionReference,
			private readonly string $administrationId,
			private readonly array $lines,
			private readonly string $emittedAt,
		) {
			parent::__construct();
		}//end __construct()

		public function getEventId(): string {
			return $this->eventId;
		}//end getEventId()

		public function getTransactionUuid(): string {
			return $this->transactionUuid;
		}//end getTransactionUuid()

		public function getTransactionReference(): string {
			return $this->transactionReference;
		}//end getTransactionReference()

		public function getAdministrationId(): string {
			return $this->administrationId;
		}//end getAdministrationId()

		/**
		 * @return array<int, array<string, mixed>>
		 */
		public function getLines(): array {
			return $this->lines;
		}//end getLines()

		public function getEmittedAt(): string {
			return $this->emittedAt;
		}//end getEmittedAt()
	}//end class
}//end if

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Pipelinq\Event\PosStockMovedEvent;
use OCA\Shillinq\Lifecycle\LotSellabilityGuard;
use OCA\Shillinq\Listener\PosStockDecrementListener;
use OCA\Shillinq\Listener\StockMoveTransitionedListener;
use OCA\Shillinq\Service\CogsPosterService;
use OCA\Shillinq\Service\FifoValuationService;
use OCA\Shillinq\Service\MovingAverageValuationService;
use OCA\Shillinq\Service\SalesDispatchStockIssueService;
use OCA\Shillinq\Sort\FefoSort;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PosStockDecrementListener.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class PosStockDecrementListenerTest extends TestCase {
	/**
	 * Mock SalesDispatchStockIssueService (mock-based dispatch tests only).
	 *
	 * @var SalesDispatchStockIssueService&MockObject
	 */
	private SalesDispatchStockIssueService&MockObject $dispatchService;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock IGroupManager, resolving one admin user by default.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock notification manager, capturing notify() calls.
	 *
	 * @var IManager&MockObject
	 */
	private IManager&MockObject $notificationMgr;

	/**
	 * Captured notifications passed to notify().
	 *
	 * @var array<int, INotification>
	 */
	private array $notifiedNotifications = [];

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test (mocked-service tests).
	 *
	 * @var PosStockDecrementListener
	 */
	private PosStockDecrementListener $listener;

	/**
	 * Container used by the mock-based tests (only needed for the
	 * InventoryStock existence probe).
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Fake ObjectService backing the container, for the InventoryStock probe.
	 *
	 * @var object
	 */
	private object $fakeObjectService;

	/**
	 * Set up test fixtures.
	 *
	 * @param array<int, array<string, mixed>> $inventoryStock Seed InventoryStock rows for the probe.
	 *
	 * @return void
	 */
	private function setUpListener(array $inventoryStock = []): void {
		$store = new \stdClass();
		$store->inventoryStock = $inventoryStock;

		$this->fakeObjectService = new class($store) {
			public string $currentSchema = '';

			public function __construct(
				private \stdClass $store,
			) {
			}//end __construct()

			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			public function findAll(array $params = []): array {
				if ($this->currentSchema !== 'InventoryStock') {
					return [];
				}

				$filters = ($params['filters'] ?? []);
				return array_values(
					array_filter(
						$this->store->inventoryStock,
						static function (array $item) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (($item[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()
		};

		$this->container = $this->createMock(ContainerInterface::class);
		$objectService = $this->fakeObjectService;
		$this->container->method('get')->willReturnCallback(
			static fn (string $class): object => $objectService
		);

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->dispatchService = $this->createMock(SalesDispatchStockIssueService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$adminUser = $this->createMock(IUser::class);
		$adminUser->method('getUID')->willReturn('admin-1');

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('getUsers')->willReturn([$adminUser]);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('get')->with('admin')->willReturn($adminGroup);

		$this->notifiedNotifications = [];
		$this->notificationMgr = $this->createMock(IManager::class);
		$this->notificationMgr->method('createNotification')->willReturnCallback(
			fn (): INotification => $this->fakeNotification()
		);
		$this->notificationMgr->method('notify')->willReturnCallback(
			function (INotification $n): void {
				$this->notifiedNotifications[] = $n;
			}
		);

		$this->listener = new PosStockDecrementListener(
			dispatchService: $this->dispatchService,
			appConfig: $this->appConfig,
			groupManager: $this->groupManager,
			notificationMgr: $this->notificationMgr,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->fakeObjectService),
		);
	}//end setUpListener()

	/**
	 * Build a minimal chainable INotification fake (setters return $this).
	 *
	 * @return INotification
	 */
	private function fakeNotification(): INotification {
		return new class implements INotification {
			public string $app = '';
			public string $user = '';
			public string $subject = '';
			/** @var array<string,mixed> */
			public array $subjectParameters = [];

			public function setApp(string $app): INotification {
				$this->app = $app;
				return $this;
			}//end setApp()

			public function getApp(): string {
				return $this->app;
			}//end getApp()

			public function setUser(string $user): INotification {
				$this->user = $user;
				return $this;
			}//end setUser()

			public function getUser(): string {
				return $this->user;
			}//end getUser()

			public function setDateTime(\DateTime $dateTime): INotification {
				return $this;
			}//end setDateTime()

			public function getDateTime(): \DateTime {
				return new \DateTime();
			}//end getDateTime()

			public function setObject(string $type, string $id): INotification {
				return $this;
			}//end setObject()

			public function getObjectType(): string {
				return '';
			}//end getObjectType()

			public function getObjectId(): string {
				return '';
			}//end getObjectId()

			public function setSubject(string $subject, array $parameters = []): INotification {
				$this->subject = $subject;
				$this->subjectParameters = $parameters;
				return $this;
			}//end setSubject()

			public function getSubject(): string {
				return $this->subject;
			}//end getSubject()

			public function getSubjectParameters(): array {
				return $this->subjectParameters;
			}//end getSubjectParameters()

			public function setParsedSubject(string $subject): INotification {
				return $this;
			}//end setParsedSubject()

			public function getParsedSubject(): string {
				return '';
			}//end getParsedSubject()

			public function setRichSubject(string $subject, array $parameters = []): INotification {
				return $this;
			}//end setRichSubject()

			public function getRichSubject(): string {
				return '';
			}//end getRichSubject()

			public function getRichSubjectParameters(): array {
				return [];
			}//end getRichSubjectParameters()

			public function setMessage(string $message, array $parameters = []): INotification {
				return $this;
			}//end setMessage()

			public function getMessage(): string {
				return '';
			}//end getMessage()

			public function getMessageParameters(): array {
				return [];
			}//end getMessageParameters()

			public function setParsedMessage(string $message): INotification {
				return $this;
			}//end setParsedMessage()

			public function getParsedMessage(): string {
				return '';
			}//end getParsedMessage()

			public function setRichMessage(string $message, array $parameters = []): INotification {
				return $this;
			}//end setRichMessage()

			public function getRichMessage(): string {
				return '';
			}//end getRichMessage()

			public function getRichMessageParameters(): array {
				return [];
			}//end getRichMessageParameters()

			public function setLink(string $link): INotification {
				return $this;
			}//end setLink()

			public function getLink(): string {
				return '';
			}//end getLink()

			public function setIcon(string $icon): INotification {
				return $this;
			}//end setIcon()

			public function getIcon(): string {
				return '';
			}//end getIcon()

			public function createAction(): \OCP\Notification\IAction {
				throw new \RuntimeException('not used in these tests');
			}//end createAction()

			public function addAction(\OCP\Notification\IAction $action): INotification {
				return $this;
			}//end addAction()

			public function getActions(): array {
				return [];
			}//end getActions()

			public function addParsedAction(\OCP\Notification\IAction $action): INotification {
				return $this;
			}//end addParsedAction()

			public function getParsedActions(): array {
				return [];
			}//end getParsedActions()

			public function isValid(): bool {
				return true;
			}//end isValid()

			public function isValidParsed(): bool {
				return true;
			}//end isValidParsed()

			public function setPriorityNotification(bool $priorityNotification): INotification {
				return $this;
			}//end setPriorityNotification()

			public function isPriorityNotification(): bool {
				return false;
			}//end isPriorityNotification()
		};
	}//end fakeNotification()

	/**
	 * Build a PosStockMovedEvent with sane defaults, overridable per test.
	 *
	 * @param array<int, array<string, mixed>> $lines
	 */
	private function makeEvent(
		string $posTxnId = 'tx-1',
		string $administrationId = 'adm-1',
		array $lines = [],
	): PosStockMovedEvent {
		return new PosStockMovedEvent(
			eventId: 'evt-1',
			transactionUuid: $posTxnId,
			transactionReference: 'TXN-0001',
			administrationId: $administrationId,
			lines: $lines,
			emittedAt: '2026-07-23T10:00:00+00:00',
		);
	}//end makeEvent()

	// -------------------------------------------------------------------
	// Fail-closed / irrelevant-event guards.
	// -------------------------------------------------------------------

	/**
	 * A plain (non-PosStockMovedEvent) event is ignored — fail-closed when
	 * pipelinq's event class is absent from the dispatch.
	 *
	 * @return void
	 */
	public function testNonPosStockMovedEventIsIgnored(): void {
		$this->setUpListener();

		$this->dispatchService->expects(self::never())->method('issueForDelivery');

		$this->listener->handle(new Event());
		self::assertTrue(true);
	}//end testNonPosStockMovedEventIsIgnored()

	/**
	 * An event with no lines / no posTxnId / no administrationId is a no-op.
	 *
	 * @return void
	 */
	public function testEmptyEventIsNoOp(): void {
		$this->setUpListener();

		$this->dispatchService->expects(self::never())->method('issueForDelivery');

		$this->listener->handle($this->makeEvent(lines: []));
		self::assertTrue(true);
	}//end testEmptyEventIsNoOp()

	// -------------------------------------------------------------------
	// Matched-line delegation (decrement, mock-based).
	// -------------------------------------------------------------------

	/**
	 * A matched line (InventoryStock row exists) delegates to
	 * issueForDelivery() with a synthetic pos-{posTxnId} delivery id and the
	 * POS line mapped onto {productReference, quantityShipped}.
	 *
	 * @return void
	 */
	public function testMatchedLineDelegatesToIssueForDelivery(): void {
		$this->setUpListener(inventoryStock: [['sku' => 'SKU-1001', 'administrationId' => 'adm-1']]);

		$this->dispatchService->expects(self::once())
			->method('issueForDelivery')
			->with(
				[
					'id' => 'pos-tx-1',
					'administrationId' => 'adm-1',
					'lines' => [
						['productReference' => 'SKU-1001', 'quantityShipped' => 3.0],
					],
				]
			)
			->willReturn(['issued' => 1, 'skipped' => 0, 'blocked' => 0, 'moves' => [], 'blockedLines' => []]);

		$this->listener->handle(
			$this->makeEvent(
				posTxnId: 'tx-1',
				administrationId: 'adm-1',
				lines: [['productRef' => 'SKU-1001', 'qty' => 3.0, 'unit' => 'pcs', 'location' => '']]
			)
		);

		self::assertCount(0, $this->notifiedNotifications, 'a matched line raises no audit notification');
	}//end testMatchedLineDelegatesToIssueForDelivery()

	/**
	 * A downstream exception from issueForDelivery() is logged but never
	 * rethrown into pipelinq's synchronous dispatch (fail-soft).
	 *
	 * @return void
	 */
	public function testDownstreamExceptionIsFailSoft(): void {
		$this->setUpListener(inventoryStock: [['sku' => 'SKU-1001', 'administrationId' => 'adm-1']]);

		$this->dispatchService->method('issueForDelivery')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects(self::once())->method('error');

		$this->listener->handle(
			$this->makeEvent(lines: [['productRef' => 'SKU-1001', 'qty' => 1.0, 'unit' => 'pcs', 'location' => '']])
		);

		self::assertTrue(true);
	}//end testDownstreamExceptionIsFailSoft()

	// -------------------------------------------------------------------
	// Unmatched-line audit surface.
	// -------------------------------------------------------------------

	/**
	 * A line whose productRef resolves to no InventoryStock row is logged
	 * AND raises a notification to every admin-group member — never
	 * silently dropped — and is NOT forwarded to issueForDelivery().
	 *
	 * @return void
	 */
	public function testUnmatchedLineIsAuditedNotDropped(): void {
		$this->setUpListener(inventoryStock: []);

		$this->dispatchService->expects(self::never())->method('issueForDelivery');
		$this->logger->expects(self::atLeastOnce())->method('error');

		$this->listener->handle(
			$this->makeEvent(
				posTxnId: 'tx-2',
				lines: [['productRef' => 'SKU-GHOST', 'qty' => 2.0, 'unit' => 'pcs', 'location' => '']]
			)
		);

		self::assertCount(1, $this->notifiedNotifications);
		$notification = $this->notifiedNotifications[0];
		self::assertSame('admin-1', $notification->getUser());
		self::assertSame(PosStockDecrementListener::NOTIFICATION_SUBJECT_UNMATCHED, $notification->getSubject());
		self::assertSame('SKU-GHOST', $notification->getSubjectParameters()['productRef']);
	}//end testUnmatchedLineIsAuditedNotDropped()

	/**
	 * A line with an empty productRef (pipelinq itself could not resolve a
	 * SKU) is also audited, not dropped, and never reaches the InventoryStock
	 * probe / issueForDelivery().
	 *
	 * @return void
	 */
	public function testEmptyProductRefIsAuditedNotDropped(): void {
		$this->setUpListener();

		$this->dispatchService->expects(self::never())->method('issueForDelivery');

		$this->listener->handle(
			$this->makeEvent(lines: [['productRef' => '', 'qty' => 1.0, 'unit' => 'pcs', 'location' => '']])
		);

		self::assertCount(1, $this->notifiedNotifications);
		self::assertSame('', $this->notifiedNotifications[0]->getSubjectParameters()['productRef']);
		self::assertSame('no_product_ref', $this->notifiedNotifications[0]->getSubjectParameters()['reason']);
	}//end testEmptyProductRefIsAuditedNotDropped()

	/**
	 * A mixed event (one matched + one unmatched line) issues the matched
	 * line and audits the unmatched one — neither vanishes, neither blocks
	 * the other.
	 *
	 * @return void
	 */
	public function testMixedMatchedAndUnmatchedLinesBothHandled(): void {
		$this->setUpListener(inventoryStock: [['sku' => 'SKU-OK', 'administrationId' => 'adm-1']]);

		$this->dispatchService->expects(self::once())
			->method('issueForDelivery')
			->with(
				[
					'id' => 'pos-tx-3',
					'administrationId' => 'adm-1',
					'lines' => [
						['productReference' => 'SKU-OK', 'quantityShipped' => 1.0],
					],
				]
			)
			->willReturn(['issued' => 1, 'skipped' => 0, 'blocked' => 0, 'moves' => [], 'blockedLines' => []]);

		$this->listener->handle(
			$this->makeEvent(
				posTxnId: 'tx-3',
				administrationId: 'adm-1',
				lines: [
					['productRef' => 'SKU-OK', 'qty' => 1.0, 'unit' => 'pcs', 'location' => ''],
					['productRef' => 'SKU-GHOST', 'qty' => 5.0, 'unit' => 'pcs', 'location' => ''],
				]
			)
		);

		self::assertCount(1, $this->notifiedNotifications, 'exactly the unmatched line is audited');
	}//end testMixedMatchedAndUnmatchedLinesBothHandled()

	// -------------------------------------------------------------------
	// CORRECTNESS PROOF: real decrement + COGS + idempotency, in-memory.
	// -------------------------------------------------------------------

	/**
	 * CORRECTNESS PROOF: a PosStockMovedEvent produces a posted `issue`
	 * StockMove via the reused, UNMODIFIED SalesDispatchStockIssueService,
	 * and feeding that StockMove into the pre-existing, UNMODIFIED
	 * StockMoveTransitionedListener pipeline (FifoValuationService ->
	 * CogsPosterService) posts a balanced COGS GLTransaction — mirroring
	 * DeliveryDispatchListenerTest's proof for shillinq's own Delivery path.
	 * Re-delivering the SAME event (redelivery / at-least-once transport) is
	 * then shown to be a no-op (idempotent on posTxnId), reusing
	 * issueForDelivery()'s own referenceDocumentUri dedup.
	 *
	 * @return void
	 */
	public function testPosSaleProducesIssueMoveThatDrivesCogsPostingAndIsIdempotent(): void {
		$store = new \stdClass();
		$store->objects = ['StockMove' => [], 'InventoryStock' => [], 'InventoryValuation' => [], 'GLTransaction' => [], 'GLLine' => []];
		$store->nextId = 1;

		// Seed one posted receipt so FIFO has an open lot to consume:
		// 10 units @ EUR 6.00 for SKU-1001 at loc-1 / adm-1.
		$store->objects['StockMove']['sm-receipt-1'] = [
			'id' => 'sm-receipt-1',
			'movementNumber' => 'SM-2026-0001',
			'itemId' => 'SKU-1001',
			'quantity' => 10.0,
			'unitCost' => 6.00,
			'movementType' => 'receipt',
			'sourceLocationId' => null,
			'destinationLocationId' => 'loc-1',
			'administrationId' => 'adm-1',
			'lifecycleState' => 'posted',
			'postedAt' => '2026-07-01T09:00:00Z',
		];
		$store->objects['InventoryStock'][] = [
			'sku' => 'SKU-1001',
			'administrationId' => 'adm-1',
			'locationId' => 'loc-1',
			'quantity' => 10,
			'reservedQuantity' => 0,
		];

		$fakeObjectService = new class($store) {
			public string $currentSchema = '';

			public function __construct(
				private \stdClass $store,
			) {
			}//end __construct()

			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			public function saveObject(array $object): array {
				$schema = $this->currentSchema;
				if ($schema === 'StockMove') {
					$id = ($object['id'] ?? ('sm-' . $this->store->nextId++));
					$object['id'] = $id;
					$this->store->objects['StockMove'][$id] = $object;
					return $object;
				}

				if ($schema === 'GLTransaction' || $schema === 'GLLine' || $schema === 'InventoryValuation') {
					$id = 'obj-' . $this->store->nextId++;
					$object['id'] = $id;
					if (isset($this->store->objects[$schema]) === false) {
						$this->store->objects[$schema] = [];
					}

					$this->store->objects[$schema][] = $object;
					return $object;
				}

				return $object;
			}//end saveObject()

			public function updateObject(string $id, array $object, ?string $register = null, ?string $schema = null): array {
				$object['id'] = $id;
				if (isset($this->store->objects['StockMove'][$id]) === true) {
					$this->store->objects['StockMove'][$id] = $object;
				}

				return $object;
			}//end updateObject()

			public function find(string $id): ?array {
				return ($this->store->objects['StockMove'][$id] ?? null);
			}//end find()

			public function findAll(array $params = []): array {
				$raw = ($this->store->objects[$this->currentSchema] ?? []);
				$items = array_values($raw);

				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $items;
				}

				return array_values(
					array_filter(
						$items,
						static function (array $item) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (($item[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()
		};

		$fakeTransitionEngine = new class($store) {
			public function __construct(
				private \stdClass $store,
			) {
			}//end __construct()

			public function transition(string $id, string $action): array {
				$move = ($this->store->objects['StockMove'][$id] ?? null);
				if ($move === null) {
					throw new \RuntimeException('not found');
				}

				if ($action === 'post') {
					$move['lifecycleState'] = 'posted';
					$move['locked'] = true;
					$move['postedAt'] = gmdate('Y-m-d\TH:i:s\Z');
				}

				$this->store->objects['StockMove'][$id] = $move;
				return $move;
			}//end transition()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(
			static fn (string $class): bool => $class === 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine'
		);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($fakeObjectService, $fakeTransitionEngine): object {
				if ($class === 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine') {
					return $fakeTransitionEngine;
				}

				return $fakeObjectService;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $appId, string $key, string $default = ''): string {
				return match ($key) {
					'cogs_account' => '7000',
					'inventory_account' => '1300',
					default => 'shillinq',
				};
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		// Real business-logic classes throughout — nothing about the
		// existing decrement + valuation + COGS pipeline is mocked or
		// reimplemented.
		$fifo = new FifoValuationService(appConfig: $appConfig, logger: $logger,
			objectService: new DuckObjectServiceAdapter($fakeObjectService),
		);
		$average = new MovingAverageValuationService(appConfig: $appConfig, logger: $logger,
			objectService: new DuckObjectServiceAdapter($fakeObjectService),
		);
		$cogs = new CogsPosterService(appConfig: $appConfig, logger: $logger,
			objectService: new DuckObjectServiceAdapter($fakeObjectService),
		);
		$stockMoveListener = new StockMoveTransitionedListener(
			fifo: $fifo,
			average: $average,
			cogs: $cogs,
			appConfig: $appConfig,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($fakeObjectService),
		);

		$dispatchService = new SalesDispatchStockIssueService(
			container: $container,
			appConfig: $appConfig,
			logger: $logger,
			lotGuard: new LotSellabilityGuard(fefoSort: new FefoSort()),
			objectService: new DuckObjectServiceAdapter($fakeObjectService),
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);
		$notificationMgr = $this->createMock(IManager::class);

		$posListener = new PosStockDecrementListener(
			dispatchService: $dispatchService,
			appConfig: $appConfig,
			groupManager: $groupManager,
			notificationMgr: $notificationMgr,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($fakeObjectService),
		);

		// Step 1: a POS sale of 4 units of SKU-1001 -> expect exactly one new
		// posted `issue` StockMove where NONE existed before (besides the
		// seeded receipt).
		self::assertCount(1, $store->objects['StockMove'], 'only the seeded receipt exists before the POS sale');

		$event = new PosStockMovedEvent(
			eventId: 'evt-1',
			transactionUuid: 'POS-2026-0001',
			transactionReference: 'TXN-0001',
			administrationId: 'adm-1',
			lines: [['productRef' => 'SKU-1001', 'qty' => 4.0, 'unit' => 'pcs', 'location' => '']],
			emittedAt: '2026-07-23T10:00:00+00:00',
		);

		$posListener->handle($event);

		self::assertCount(2, $store->objects['StockMove'], 'the POS sale created exactly one new StockMove');
		$issueMove = null;
		foreach ($store->objects['StockMove'] as $move) {
			if (($move['movementType'] ?? '') === 'issue') {
				$issueMove = $move;
			}
		}

		self::assertNotNull($issueMove, 'an issue StockMove was created');
		self::assertSame('posted', $issueMove['lifecycleState']);
		self::assertSame(4.0, $issueMove['quantity']);
		self::assertSame('SKU-1001', $issueMove['itemId']);
		self::assertStringContainsString('pos-POS-2026-0001', (string)$issueMove['referenceDocumentUri']);

		// Step 2: feed the newly-posted issue StockMove into the EXISTING,
		// UNMODIFIED StockMoveTransitionedListener pipeline and prove it
		// posts a balanced COGS GLTransaction.
		self::assertCount(0, $store->objects['GLTransaction'], 'no GL entries exist before the issue move posts');

		$entity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$entity->method('getSchema')->willReturn('StockMove');
		$entity->method('getObject')->willReturn($issueMove);
		$stockMoveEvent = $this->createConfiguredMock(
			\OCA\OpenRegister\Event\ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'posted', 'getSchema' => 'StockMove']
		);

		$stockMoveListener->handle($stockMoveEvent);

		self::assertCount(1, $store->objects['GLTransaction'], 'the POS sale posted exactly one COGS GLTransaction');
		self::assertCount(2, $store->objects['GLLine']);
		// 4 units consumed from the EUR 6.00 FIFO lot = EUR 24.00.
		$amounts = array_map(static fn (array $l): float => (float)$l['amount'], $store->objects['GLLine']);
		sort($amounts);
		self::assertEqualsWithDelta([24.00, 24.00], $amounts, 0.001);

		// Step 3 (REQ: idempotent on posTxnId): re-deliver the SAME event
		// (simulating at-least-once transport redelivery). Reusing
		// issueForDelivery()'s own referenceDocumentUri dedup, this MUST be a
		// no-op — no second StockMove, no second COGS entry.
		$posListener->handle($event);

		self::assertCount(2, $store->objects['StockMove'], 'redelivery created NO second StockMove');
		self::assertCount(1, $store->objects['GLTransaction'], 'redelivery posted NO second COGS entry');
	}//end testPosSaleProducesIssueMoveThatDrivesCogsPostingAndIsIdempotent()
}//end class
