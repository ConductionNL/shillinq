<?php

/**
 * NotificationController — admin-monitor REST endpoints for booking
 * notification triggers.
 *
 * Surfaces the four endpoints called out in REQ-BNT-007 / REQ-BNT-008:
 *
 *   GET    /api/bookings/{id}/notification-triggers       — list triggers
 *                                                           that apply to one booking
 *                                                           (global + per-booking
 *                                                           overrides).
 *   PATCH  /api/bookings/{id}/notification-triggers       — bulk-update
 *                                                           status + channels for
 *                                                           the triggers wired
 *                                                           to one booking from
 *                                                           the per-booking modal.
 *   GET    /api/admin/notification-monitor                — admin monitor
 *                                                           summary (counts +
 *                                                           recent failures +
 *                                                           per-trigger metrics).
 *   POST   /api/admin/notification-monitor/disable-all    — emergency
 *                                                           off-switch
 *                                                           (REQ-BNT-008).
 *
 * The controller is intentionally thin: it composes the declarative
 * trigger / delivery objects out of OR via the ContainerInterface, runs
 * the BookingNotificationService gates for evaluation, and never
 * implements its own opt-out / rate-limit / dedupe logic. CRUD on the
 * trigger schema goes through the canonical OR routes; this controller
 * is for the orchestration glue the OR endpoint cannot model.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Admin / per-booking notification REST controller.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
 */
class NotificationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request object.
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param RegisterLocation $registerLocation Where the register lives (slug + OpenRegister availability).
	 * @param IUserSession $userSession Logged-in user — the 401 anonymous-rejection guard only.
	 * @param AdministrationContextService $context RBAC guard — the per-booking authorization gate (ADR-005).
	 * @param LoggerInterface $logger Logger for failure paths.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly RegisterLocation $registerLocation,
		private readonly IUserSession $userSession,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET /api/bookings/{id}/notification-triggers
	 *
	 * Returns triggers that apply to the named booking — the global
	 * triggers (appliesToBookingSlug=null) whose triggerType matches one
	 * of the booking lifecycle events, plus any triggers explicitly
	 * scoped to this booking. Used by the per-booking modal to populate
	 * its toggles (REQ-BNT-007).
	 *
	 * @param string $id Booking slug / uuid.
	 *
	 * @return JSONResponse `{triggers: [{slug, name, triggerType, channels, status, appliesToBookingSlug}]}`.
	 *
	 * @spec openspec/specs/bookings-notification-triggers/spec.md
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	public function listForBooking(string $id): JSONResponse {
		$refusal = $this->bookingRefusal(bookingId: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->listTriggers(bookingId: $id);
	}//end listForBooking()

	/**
	 * PATCH /api/bookings/{id}/notification-triggers
	 *
	 * Bulk-update trigger status + channels for a single booking (the
	 * per-booking modal save action). Body shape:
	 *   `{updates: [{slug, status, channels}]}`. The endpoint validates
	 * the booking-scope (trigger.appliesToBookingSlug must be null
	 * (global override is forbidden) or equal to the booking id) and
	 * persists via the ObjectService. Returns the updated trigger set.
	 *
	 * @param string $id Booking slug / uuid.
	 *
	 * @return JSONResponse `{triggers: […]}` or error envelope.
	 *
	 * @spec openspec/specs/bookings-notification-triggers/spec.md
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	public function updateForBooking(string $id): JSONResponse {
		$refusal = $this->bookingRefusal(bookingId: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		$updates = (array)($this->request->getParams()['updates'] ?? []);
		if (count($updates) === 0) {
			return new JSONResponse(data: ['message' => 'updates array required'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return $this->applyUpdates(bookingId: $id, updates: $updates);
	}//end updateForBooking()

	/**
	 * GET /api/admin/notification-monitor
	 *
	 * Admin monitor summary: total dispatches today, per-trigger send +
	 * failure counts, recent failure samples. Read-only — drives the
	 * Notification Monitor index page (REQ-BNT-008). The endpoint loads
	 * in O(triggers + recent deliveries) — the page polls every 5 min.
	 *
	 * @return JSONResponse `{summary: {…}, triggers: [{…}], recentFailures: [{…}]}`.
	 */
	#[AuthorizedAdminSetting(AppIdentity::APP_ID)]
	public function adminMonitor(): JSONResponse {
		try {
			$triggers = $this->fetchTriggers();
			$deliveries = $this->fetchRecentDeliveries(limit: 200);
		} catch (Throwable $e) {
			$this->logger->warning('shillinq.notification.monitor.fail', ['error' => $e->getMessage()]);
			return new JSONResponse(data: ['message' => 'Monitor unavailable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return self::monitorResponse(triggers: $triggers, tally: self::deliveryTally(deliveries: $deliveries));
	}//end adminMonitor()

	/**
	 * POST /api/admin/notification-monitor/disable-all
	 *
	 * Emergency off-switch (REQ-BNT-008). Iterates every trigger in
	 * status=enabled and transitions it to disabled — no notifications
	 * dispatch until an admin re-enables them. Returns the number of
	 * triggers affected.
	 *
	 * @return JSONResponse `{disabled: N}` or error envelope.
	 */
	#[AuthorizedAdminSetting(AppIdentity::APP_ID)]
	public function adminDisableAll(): JSONResponse {
		try {
			$service = $this->resolveObjectService();
			$triggers = $this->fetchTriggers();
		} catch (Throwable $e) {
			$this->logger->warning('shillinq.notification.disable-all.unavailable', ['error' => $e->getMessage()]);
			return new JSONResponse(data: ['message' => 'OR ObjectService unavailable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(data: ['disabled' => $this->disableAll(service: $service, triggers: $triggers)]);
	}//end adminDisableAll()

	/**
	 * Resolve the OR ObjectService bound to the BookingNotificationTrigger schema.
	 *
	 * The container exposes the canonical OR service in
	 * production; integration tests bind a fake. Pure-logic helpers
	 * never call this — they take config as input — so unit tests don't
	 * need the binding.
	 *
	 * @return object OR ObjectService.
	 *
	 * @throws Throwable When the OR service is unavailable.
	 */
	private function resolveObjectService(): object {
		if ($this->container->has('OCA\\OpenRegister\\Service\\ObjectService') === true) {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		}

		throw new RuntimeException('OR ObjectService not bound');
	}//end resolveObjectService()

	/**
	 * Fetch every BookingNotificationTrigger from OR.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws Throwable When OR is unavailable.
	 */
	private function fetchTriggers(): array {
		return $this->findAllOrEmpty(
			query: ['schema' => 'BookingNotificationTrigger'],
			failureLog: 'shillinq.notification.fetch-triggers'
		);
	}//end fetchTriggers()

	/**
	 * Fetch recent NotificationDelivery records (descending sentAt).
	 *
	 * @param int $limit Maximum records (admin monitor uses 200 by default).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchRecentDeliveries(int $limit = 200): array {
		return $this->findAllOrEmpty(
			query: [
				'schema' => 'NotificationDelivery',
				'order' => ['sentAt' => 'desc'],
				'limit' => $limit,
			],
			failureLog: 'shillinq.notification.fetch-deliveries'
		);
	}//end fetchRecentDeliveries()

	/**
	 * Find a single trigger by slug.
	 *
	 * @param object $service OR ObjectService.
	 * @param string $slug Trigger slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws Throwable
	 */
	private function findTriggerBySlug(object $service, string $slug): ?array {
		$items = $service->findAll(
			[
				'register' => $this->registerLocation->getRegisterSlug(),
				'schema' => 'BookingNotificationTrigger',
				'filters' => ['slug' => $slug],
				'limit' => 1,
			]
		);

		return self::firstRecord(items: $items);
	}//end findTriggerBySlug()

	/**
	 * The first item when the answer is a non-empty list and that item is a
	 * record, otherwise null.
	 *
	 * @param mixed $items The answer of findAll().
	 *
	 * @return array<string,mixed>|null
	 */
	private static function firstRecord(mixed $items): ?array {
		if (is_array($items) === false || count($items) === 0) {
			return null;
		}

		$head = reset($items);
		if (is_array($head) === true) {
			return $head;
		}

		return null;

	}//end firstRecord()

	/**
	 * Require the caller to be a member of the administration owning a booking (ADR-005).
	 *
	 * `BookingNotificationTrigger` carries no tenant field at all — its only
	 * scoping is `appliesToBookingSlug` — so the administration has to come from
	 * the `Booking` the route names. Both endpoints previously fetched the
	 * triggers instance-wide and used the booking id only to WIDEN the filter
	 * (a trigger with an empty `appliesToBookingSlug` matches every booking), so
	 * quoting any booking id returned the instance's trigger configuration.
	 *
	 * A booking that does not exist and a booking in another administration both
	 * answer 404 (REQ-MA-001: mask, never confirm).
	 *
	 * @param string $bookingId The booking slug / uuid from the route.
	 *
	 * @return JSONResponse|null A 404 response when refused, null when allowed.
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-8
	 */
	private function requireAccessibleBooking(string $bookingId): ?JSONResponse {
		$rows = $this->bookingRows(bookingId: $bookingId);
		if ($rows instanceof JSONResponse) {
			return $rows;
		}

		if ($this->canAccessBooking(rows: $rows, bookingId: $bookingId) === true) {
			return null;
		}

		return new JSONResponse(data: ['message' => 'Booking not found'], statusCode: Http::STATUS_NOT_FOUND);
	}//end requireAccessibleBooking()

	/**
	 * The booking as a list of at most one row; a 503 (logged) when
	 * OpenRegister cannot be reached.
	 *
	 * @param string $bookingId Booking uuid or bookingId.
	 *
	 * @return array<int,array<string,mixed>>|JSONResponse
	 */
	private function bookingRows(string $bookingId): array|JSONResponse {
		try {
			$booking = $this->findBooking(service: $this->resolveObjectService(), bookingId: $bookingId);
		} catch (Throwable $e) {
			$this->logger->warning('shillinq.notification.booking.unavailable', ['error' => $e->getMessage()]);
			return new JSONResponse(data: ['message' => 'Booking unavailable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return self::listOf(booking: $booking);

	}//end bookingRows()

	/**
	 * Find the booking by uuid, or else by its bookingId property.
	 *
	 * @param object $service OpenRegister's object service.
	 * @param string $bookingId Booking uuid or bookingId.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findBooking(object $service, string $bookingId): ?array {
		// NOT findAll(['filters' => ['id' => …]]) — `filters` addresses JSON
		// properties and `id` is the entity's own column, so that shape
		// matched nothing for every value and this guard refused every
		// booking addressed by uuid. findOne() resolves the uuid via find()
		// and falls back to the `bookingId` property, which is the other
		// identifier canAccessBooking() already accepts.
		return ObjectIdentifier::findOne(
			scoped: $service
				->setRegister($this->registerLocation->getRegisterSlug())
				->setSchema('Booking'),
			id: $bookingId,
			fallbackProperty: 'bookingId'
		);

	}//end findBooking()

	/**
	 * The booking as a list: empty when there is none.
	 *
	 * @param array<string,mixed>|null $booking The booking.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function listOf(?array $booking): array {
		if ($booking === null) {
			return [];
		}

		return [$booking];

	}//end listOf()

	/**
	 * Whether the first row that is this booking belongs to an administration
	 * the caller may access.
	 *
	 * @param array<int,array<string,mixed>> $rows The booking rows.
	 * @param string $bookingId Booking uuid or bookingId.
	 *
	 * @return bool
	 */
	private function canAccessBooking(array $rows, string $bookingId): bool {
		foreach ($rows as $row) {
			if (self::isBooking(row: $row, bookingId: $bookingId) === false) {
				continue;
			}

			// A booking with no administrationId is refused: canAccess() fails
			// closed on '' (AdministrationContextService:220).
			return $this->context->canAccess(administrationId: (string)($row['administrationId'] ?? ''));
		}

		return false;

	}//end canAccessBooking()

	/**
	 * Whether a row is the booking, by uuid or bookingId.
	 *
	 * @param array<string,mixed> $row The row.
	 * @param string $bookingId Booking uuid or bookingId.
	 *
	 * @return bool
	 */
	private static function isBooking(array $row, string $bookingId): bool {
		$rowId = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		return $rowId === $bookingId || (string)($row['bookingId'] ?? '') === $bookingId;

	}//end isBooking()

	/**
	 * The booking's global and own triggers; a 503 (logged) when they cannot
	 * be read.
	 *
	 * @param string $bookingId The booking.
	 *
	 * @return JSONResponse
	 */
	private function listTriggers(string $bookingId): JSONResponse {
		try {
			$triggers = $this->fetchTriggers();
		} catch (Throwable $e) {
			$this->logger->warning('shillinq.notification.list.fail', ['error' => $e->getMessage()]);
			return new JSONResponse(data: ['message' => 'Notification triggers unavailable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(
			data: [
				'bookingId' => $bookingId,
				'triggers' => self::eligibleTriggers(triggers: $triggers, bookingId: $bookingId),
			]
		);

	}//end listTriggers()

	/**
	 * The global triggers and those scoped to the booking.
	 *
	 * @param array<int,array<string,mixed>> $triggers All triggers.
	 * @param string $bookingId The booking.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function eligibleTriggers(array $triggers, string $bookingId): array {
		return array_values(
			array_filter(
				$triggers,
				static fn (array $trigger): bool => in_array(self::scopeOf(trigger: $trigger), ['', $bookingId], true)
			)
		);

	}//end eligibleTriggers()

	/**
	 * The booking a trigger is scoped to, '' for a global trigger.
	 *
	 * @param array<string,mixed> $trigger The trigger.
	 *
	 * @return string
	 */
	private static function scopeOf(array $trigger): string {
		return (string)($trigger['appliesToBookingSlug'] ?? '');

	}//end scopeOf()

	/**
	 * Apply the update entries to the booking's own triggers; a 503 (logged)
	 * when OpenRegister cannot be reached.
	 *
	 * @param string $bookingId The booking.
	 * @param array<mixed> $updates The update entries.
	 *
	 * @return JSONResponse
	 */
	private function applyUpdates(string $bookingId, array $updates): JSONResponse {
		try {
			$run = new BookingTriggerUpdate(service: $this->resolveObjectService(), bookingId: $bookingId);
		} catch (Throwable $e) {
			$this->logger->warning('shillinq.notification.update.unavailable', ['error' => $e->getMessage()]);
			return new JSONResponse(data: ['message' => 'OR ObjectService unavailable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		foreach ($updates as $update) {
			$this->applyUpdate(run: $run, update: $update);
		}

		return new JSONResponse(data: ['bookingId' => $bookingId, 'triggers' => $run->applied]);

	}//end applyUpdates()

	/**
	 * Apply one entry's status and channels to the trigger it names, when that
	 * trigger is scoped to this booking.
	 *
	 * A global trigger can be cloned to a per-booking override but cannot be
	 * mutated in place from this endpoint: only per-booking-scoped triggers
	 * are persisted, leaving the global config untouched.
	 *
	 * @param BookingTriggerUpdate $run The run.
	 * @param mixed $update The entry.
	 *
	 * @return void
	 */
	private function applyUpdate(BookingTriggerUpdate $run, mixed $update): void {
		if (is_array($update) === false) {
			return;
		}

		$run->slug = (string)($update['slug'] ?? '');
		if ($run->slug === '') {
			return;
		}

		$trigger = $this->bookingTrigger(run: $run);
		if ($trigger === null) {
			return;
		}

		$this->persistTrigger(run: $run, trigger: self::withUpdate(trigger: $trigger, update: $update));

	}//end applyUpdate()

	/**
	 * The trigger with the entry's status and channels, each kept when the
	 * entry leaves it out.
	 *
	 * @param array<string,mixed> $trigger The trigger.
	 * @param array<string,mixed> $update The entry.
	 *
	 * @return array<string,mixed>
	 */
	private static function withUpdate(array $trigger, array $update): array {
		$trigger['status'] = (string)($update['status'] ?? $trigger['status']);
		$trigger['channels'] = (array)($update['channels'] ?? $trigger['channels']);
		return $trigger;

	}//end withUpdate()

	/**
	 * The trigger the entry's slug names, when it is scoped to this booking;
	 * a failing lookup is logged and skipped.
	 *
	 * @param BookingTriggerUpdate $run The run, at the entry.
	 *
	 * @return array<string,mixed>|null
	 */
	private function bookingTrigger(BookingTriggerUpdate $run): ?array {
		try {
			$trigger = $this->findTriggerBySlug(service: $run->service, slug: $run->slug);
		} catch (Throwable $e) {
			$this->logger->warning('shillinq.notification.update.find', ['slug' => $run->slug, 'error' => $e->getMessage()]);
			return null;
		}

		if ($trigger === null || self::isScopedTo(trigger: $trigger, bookingId: $run->bookingId) === false) {
			return null;
		}

		return $trigger;

	}//end bookingTrigger()

	/**
	 * Whether a trigger is scoped to this booking (a global one is not).
	 *
	 * @param array<string,mixed> $trigger The trigger.
	 * @param string $bookingId The booking.
	 *
	 * @return bool
	 */
	private static function isScopedTo(array $trigger, string $bookingId): bool {
		$scope = self::scopeOf(trigger: $trigger);
		return $scope !== '' && $scope === $bookingId;

	}//end isScopedTo()

	/**
	 * Persist the trigger, by its id or else its slug; a failing write is
	 * logged and the trigger left out.
	 *
	 * @param BookingTriggerUpdate $run The run, at the entry.
	 * @param array<string,mixed> $trigger The updated trigger.
	 *
	 * @return void
	 */
	private function persistTrigger(BookingTriggerUpdate $run, array $trigger): void {
		try {
			$run->service->updateObject($trigger['id'] ?? $run->slug, $trigger);
			$run->applied[] = $trigger;
		} catch (Throwable $e) {
			$this->logger->warning('shillinq.notification.update.persist', ['slug' => $run->slug, 'error' => $e->getMessage()]);
		}

	}//end persistTrigger()

	/**
	 * The delivery counts per status (sent, failed, queued, skipped; others
	 * are not counted) and the first 25 failures.
	 *
	 * @param array<int,mixed> $deliveries The recent deliveries, newest first.
	 *
	 * @return array{summary: array<string,int>, recentFailures: array<int,mixed>}
	 */
	private static function deliveryTally(array $deliveries): array {
		$tally = ['summary' => ['sent' => 0, 'failed' => 0, 'queued' => 0, 'skipped' => 0], 'recentFailures' => []];
		foreach ($deliveries as $delivery) {
			$status = (string)($delivery['status'] ?? '');
			if (isset($tally['summary'][$status]) === false) {
				continue;
			}

			$tally['summary'][$status]++;
			if ($status === 'failed' && count($tally['recentFailures']) < 25) {
				$tally['recentFailures'][] = $delivery;
			}
		}

		return $tally;

	}//end deliveryTally()

	/**
	 * Disable an enabled trigger, by its id or else its slug; a failing write
	 * is logged.
	 *
	 * @param object $service OpenRegister's object service.
	 * @param array<string,mixed> $trigger The trigger.
	 *
	 * @return bool Whether it was disabled.
	 */
	private function disableTrigger(object $service, array $trigger): bool {
		if (((string)($trigger['status'] ?? '')) !== 'enabled') {
			return false;
		}

		$trigger['status'] = 'disabled';
		try {
			$service->updateObject($trigger['id'] ?? ($trigger['slug'] ?? ''), $trigger);
			return true;
		} catch (Throwable $e) {
			$this->logDisableFailure(trigger: $trigger, failure: $e);
			return false;
		}

	}//end disableTrigger()

	/**
	 * Log a trigger that could not be disabled.
	 *
	 * @param array<string,mixed> $trigger The trigger.
	 * @param Throwable $failure The failure.
	 *
	 * @return void
	 */
	private function logDisableFailure(array $trigger, Throwable $failure): void {
		$this->logger->warning(
			'shillinq.notification.disable-all.persist',
			[
				'slug' => ($trigger['slug'] ?? ''),
				'error' => $failure->getMessage(),
			]
		);

	}//end logDisableFailure()

	/**
	 * Disable every enabled trigger and count those disabled.
	 *
	 * @param object $service OpenRegister's object service.
	 * @param array<int,array<string,mixed>> $triggers The triggers.
	 *
	 * @return int
	 */
	private function disableAll(object $service, array $triggers): int {
		$disabled = 0;
		foreach ($triggers as $trigger) {
			if ($this->disableTrigger(service: $service, trigger: $trigger) === true) {
				$disabled++;
			}
		}

		return $disabled;

	}//end disableAll()

	/**
	 * The monitor body: the tally, the triggers and the recent failures.
	 *
	 * @param array<int,mixed> $triggers The triggers.
	 * @param array{summary: array<string,int>, recentFailures: array<int,mixed>} $tally The delivery tally.
	 *
	 * @return JSONResponse
	 */
	private static function monitorResponse(array $triggers, array $tally): JSONResponse {
		return new JSONResponse(
			data: [
				'summary' => $tally['summary'],
				'triggers' => $triggers,
				'recentFailures' => $tally['recentFailures'],
			]
		);

	}//end monitorResponse()

	/**
	 * A 401 without a logged-in user, a 404 or 503 when the booking is not the
	 * caller's or cannot be read; otherwise null.
	 *
	 * @param string $bookingId Booking uuid or bookingId.
	 *
	 * @return JSONResponse|null
	 */
	private function bookingRefusal(string $bookingId): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return $this->requireAccessibleBooking(bookingId: $bookingId);

	}//end bookingRefusal()

	/**
	 * Every object the query matches in the register; [] when OpenRegister is
	 * not bound or the query fails (logged) or answers no list.
	 *
	 * @param array<string,mixed> $query The query, without the register.
	 * @param string $failureLog The log key for a failing query.
	 *
	 * @return array<int,mixed>
	 */
	private function findAllOrEmpty(array $query, string $failureLog): array {
		try {
			$service = $this->resolveObjectService();
		} catch (Throwable $e) {
			return [];
		}

		try {
			$items = $service->findAll(['register' => $this->registerLocation->getRegisterSlug()] + $query);
		} catch (Throwable $e) {
			$this->logger->warning($failureLog, ['error' => $e->getMessage()]);
			return [];
		}

		return self::listOrEmpty(items: $items);

	}//end findAllOrEmpty()

	/**
	 * The items when they are a list, otherwise [].
	 *
	 * @param mixed $items The items.
	 *
	 * @return array<int,mixed>
	 */
	private static function listOrEmpty(mixed $items): array {
		if (is_array($items) === true) {
			return $items;
		}

		return [];

	}//end listOrEmpty()
}//end class
