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

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SettingsService;
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
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class NotificationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request object.
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settings Register slug + OR availability.
	 * @param IUserSession $userSession Logged-in user — the 401 anonymous-rejection guard only.
	 * @param AdministrationContextService $context RBAC guard — the per-booking authorization gate (ADR-005).
	 * @param LoggerInterface $logger Logger for failure paths.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly IUserSession $userSession,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

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
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$error = $this->requireAccessibleBooking(bookingId: $id);
		if ($error !== null) {
			return $error;
		}

		try {
			$triggers = $this->fetchTriggers();
		} catch (Throwable $e) {
			$this->logger->warning('shillinq.notification.list.fail', ['error' => $e->getMessage()]);
			return new JSONResponse(data: ['message' => 'Notification triggers unavailable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$eligible = array_values(
			array_filter(
				$triggers,
				static function (array $trigger) use ($id): bool {
					if (isset($trigger['appliesToBookingSlug']) === true) {
						$scope = (string)$trigger['appliesToBookingSlug'];
					} else {
						$scope = '';
					}

					if ($scope === '' || $scope === $id) {
						return true;
					}

					return false;
				}
			)
		);

		return new JSONResponse(
			data: [
				'bookingId' => $id,
				'triggers' => $eligible,
			]
		);
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
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$error = $this->requireAccessibleBooking(bookingId: $id);
		if ($error !== null) {
			return $error;
		}

		$params = $this->request->getParams();
		$updates = (array)($params['updates'] ?? []);
		if (count($updates) === 0) {
			return new JSONResponse(data: ['message' => 'updates array required'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$service = $this->resolveObjectService();
		} catch (Throwable $e) {
			$this->logger->warning('shillinq.notification.update.unavailable', ['error' => $e->getMessage()]);
			return new JSONResponse(data: ['message' => 'OR ObjectService unavailable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$applied = [];
		foreach ($updates as $update) {
			if (is_array($update) === false) {
				continue;
			}

			$slug = (string)($update['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			try {
				$trigger = $this->findTriggerBySlug(service: $service, slug: $slug);
			} catch (Throwable $e) {
				$this->logger->warning('shillinq.notification.update.find', ['slug' => $slug, 'error' => $e->getMessage()]);
				continue;
			}

			if ($trigger === null) {
				continue;
			}

			if (isset($trigger['appliesToBookingSlug']) === true) {
				$scope = (string)$trigger['appliesToBookingSlug'];
			} else {
				$scope = '';
			}

			// A global trigger can be cloned to a per-booking override but cannot be mutated in place from this endpoint.
			// The endpoint only persists status + channels onto per-booking-scoped triggers, leaving the global config untouched.
			if ($scope === '' || $scope !== $id) {
				continue;
			}

			$trigger['status'] = (string)($update['status'] ?? $trigger['status']);
			$trigger['channels'] = (array)($update['channels'] ?? $trigger['channels']);

			try {
				$service->updateObject($trigger['id'] ?? $slug, $trigger);
				$applied[] = $trigger;
			} catch (Throwable $e) {
				$this->logger->warning('shillinq.notification.update.persist', ['slug' => $slug, 'error' => $e->getMessage()]);
			}
		}//end foreach

		return new JSONResponse(data: ['bookingId' => $id, 'triggers' => $applied]);
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
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function adminMonitor(): JSONResponse {
		try {
			$triggers = $this->fetchTriggers();
			$deliveries = $this->fetchRecentDeliveries(limit: 200);
		} catch (Throwable $e) {
			$this->logger->warning('shillinq.notification.monitor.fail', ['error' => $e->getMessage()]);
			return new JSONResponse(data: ['message' => 'Monitor unavailable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$sent = 0;
		$failed = 0;
		$queued = 0;
		$skipped = 0;
		$recentFailures = [];
		foreach ($deliveries as $delivery) {
			$status = (string)($delivery['status'] ?? '');
			if ($status === 'sent') {
				$sent++;
				continue;
			}

			if ($status === 'failed') {
				$failed++;
				if (count($recentFailures) < 25) {
					$recentFailures[] = $delivery;
				}

				continue;
			}

			if ($status === 'queued') {
				$queued++;
				continue;
			}

			if ($status === 'skipped') {
				$skipped++;
			}
		}//end foreach

		return new JSONResponse(
			data: [
				'summary' => [
					'sent' => $sent,
					'failed' => $failed,
					'queued' => $queued,
					'skipped' => $skipped,
				],
				'triggers' => $triggers,
				'recentFailures' => $recentFailures,
			]
		);
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
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function adminDisableAll(): JSONResponse {
		try {
			$service = $this->resolveObjectService();
			$triggers = $this->fetchTriggers();
		} catch (Throwable $e) {
			$this->logger->warning('shillinq.notification.disable-all.unavailable', ['error' => $e->getMessage()]);
			return new JSONResponse(data: ['message' => 'OR ObjectService unavailable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$disabled = 0;
		foreach ($triggers as $trigger) {
			if (((string)($trigger['status'] ?? '')) !== 'enabled') {
				continue;
			}

			$trigger['status'] = 'disabled';
			try {
				$service->updateObject($trigger['id'] ?? ($trigger['slug'] ?? ''), $trigger);
				$disabled++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'shillinq.notification.disable-all.persist',
					[
						'slug' => ($trigger['slug'] ?? ''),
						'error' => $e->getMessage(),
					]
				);
			}
		}//end foreach

		return new JSONResponse(data: ['disabled' => $disabled]);
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
		try {
			$service = $this->resolveObjectService();
		} catch (Throwable $e) {
			return [];
		}

		try {
			$items = $service->findAll(
				['register' => $this->settings->getRegisterSlug(), 'schema' => 'BookingNotificationTrigger']
			);
		} catch (Throwable $e) {
			$this->logger->warning('shillinq.notification.fetch-triggers', ['error' => $e->getMessage()]);
			return [];
		}

		if (is_array($items) === true) {
			return $items;
		}

		return [];
	}//end fetchTriggers()

	/**
	 * Fetch recent NotificationDelivery records (descending sentAt).
	 *
	 * @param int $limit Maximum records (admin monitor uses 200 by default).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchRecentDeliveries(int $limit = 200): array {
		try {
			$service = $this->resolveObjectService();
		} catch (Throwable $e) {
			return [];
		}

		try {
			$items = $service->findAll(
				[
					'register' => $this->settings->getRegisterSlug(),
					'schema' => 'NotificationDelivery',
					'order' => ['sentAt' => 'desc'],
					'limit' => $limit,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('shillinq.notification.fetch-deliveries', ['error' => $e->getMessage()]);
			return [];
		}

		if (is_array($items) === true) {
			return $items;
		}

		return [];
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
				'register' => $this->settings->getRegisterSlug(),
				'schema' => 'BookingNotificationTrigger',
				'filters' => ['slug' => $slug],
				'limit' => 1,
			]
		);

		if (is_array($items) === false || count($items) === 0) {
			return null;
		}

		$head = reset($items);
		if (is_array($head) === true) {
			return $head;
		}

		return null;
	}//end findTriggerBySlug()

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
		try {
			$service = $this->resolveObjectService();
			// NOT findAll(['filters' => ['id' => …]]) — `filters` addresses JSON
			// properties and `id` is the entity's own column, so that shape
			// matched nothing for every value and this guard refused every
			// booking addressed by uuid. findOne() resolves the uuid via find()
			// and falls back to the `bookingId` property, which is the other
			// identifier the loop below already accepts.
			$booking = ObjectIdentifier::findOne(
				scoped: $service
					->setRegister($this->settings->getRegisterSlug())
					->setSchema('Booking'),
				id: $bookingId,
				fallbackProperty: 'bookingId'
			);
			$rows = [];
			if ($booking !== null) {
				$rows = [$booking];
			}
		} catch (Throwable $e) {
			$this->logger->warning('shillinq.notification.booking.unavailable', ['error' => $e->getMessage()]);
			return new JSONResponse(data: ['message' => 'Booking unavailable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		foreach ((array)$rows as $row) {
			$rowId = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
			if ($rowId !== $bookingId && (string)($row['bookingId'] ?? '') !== $bookingId) {
				continue;
			}

			// A booking with no administrationId is refused: canAccess() fails
			// closed on '' (AdministrationContextService:220).
			if ($this->context->canAccess(administrationId: (string)($row['administrationId'] ?? '')) === true) {
				return null;
			}

			break;
		}

		return new JSONResponse(data: ['message' => 'Booking not found'], statusCode: Http::STATUS_NOT_FOUND);
	}//end requireAccessibleBooking()
}//end class
