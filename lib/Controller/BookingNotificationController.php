<?php

/**
 * Booking Notification Controller
 *
 * REST API controller for booking notification trigger configuration and the
 * admin notification monitor dashboard per REQ-BNT-007 and REQ-BNT-008.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * REST endpoints for notification trigger configuration and admin monitoring.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
 *
 * @SuppressWarnings(PHPMD.ShortVariable) Pre-existing debt (issue #506):
 *     not in the project's curated idiomatic-abbreviation allowlist;
 *     deferred pending a dedicated rename pass.
 */
class BookingNotificationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param SettingsService $settingsService The settings service.
	 * @param IGroupManager $groupManager The group manager.
	 * @param IUserSession $userSession The user session.
	 * @param LoggerInterface $logger The logger.
	 * @param AdministrationContextService $administrationContext The administration membership seam.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private SettingsService $settingsService,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private AdministrationContextService $administrationContext,
		private readonly ObjectServiceInterface $objectService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Get notification triggers for a specific booking.
	 *
	 * Returns all active and inactive triggers scoped to the booking or global.
	 * Organizers may read their own bookings' triggers.
	 *
	 * @param string $id Booking UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getBookingTriggers(string $id): JSONResponse {
		$this->authorizeBookingAccess(bookingId: $id);

		try {
			$registerSlug = $this->settingsService->getRegisterSlug();

			$triggers = $this->objectService
				->setRegister($registerSlug)
				->setSchema('BookingNotificationTrigger')
				->findAll(['filters' => ['bookingId' => $id], 'limit' => 100]);

			return new JSONResponse(['triggers' => $triggers]);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: getBookingTriggers failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['message' => 'Failed to load triggers.'], 500);
		}//end try

	}//end getBookingTriggers()

	/**
	 * Update notification trigger configuration for a specific booking.
	 *
	 * Accepts a JSON body with trigger overrides (active, recipients, templateId).
	 * Organizers may update their own bookings' triggers.
	 *
	 * @param string $id Booking UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
	 */
	#[NoAdminRequired]
	public function updateBookingTriggers(string $id): JSONResponse {
		$this->authorizeBookingAccess(bookingId: $id);

		$data = $this->request->getParams();
		unset($data['_route']);

		if (isset($data['triggers']) === false || is_array(value: $data['triggers']) === false) {
			return new JSONResponse(['message' => 'triggers array is required.'], 400);
		}

		try {
			$registerSlug = $this->settingsService->getRegisterSlug();

			$saved = [];
			foreach ($data['triggers'] as $triggerData) {
				$triggerData['bookingId'] = $id;
				$saved[] = $this->objectService->saveObject(
					object: $triggerData,
					register: $registerSlug,
					schema: 'BookingNotificationTrigger',
				);
			}

			return new JSONResponse(['triggers' => $saved]);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: updateBookingTriggers failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['message' => 'Failed to update triggers.'], 500);
		}//end try

	}//end updateBookingTriggers()

	/**
	 * Get notification monitor data for the admin dashboard.
	 *
	 * Returns send counts (today/week/month), per-trigger failure rates, and
	 * recent failed deliveries per REQ-BNT-008.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	#[NoCSRFRequired]
	public function getNotificationMonitor(): JSONResponse {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();

			$todayStart = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('c');
			$weekStart = (new DateTimeImmutable('-7 days', new DateTimeZone('UTC')))->format('c');
			$monthStart = (new DateTimeImmutable('-30 days', new DateTimeZone('UTC')))->format('c');

			$todayDeliveries = $this->objectService
				->setRegister($registerSlug)
				->setSchema('NotificationDelivery')
				->findAll(['filters' => ['sentAt[gte]' => $todayStart], 'limit' => 1000]);
			$weekDeliveries = $this->objectService
				->setRegister($registerSlug)
				->setSchema('NotificationDelivery')
				->findAll(['filters' => ['sentAt[gte]' => $weekStart], 'limit' => 5000]);
			$monthDeliveries = $this->objectService
				->setRegister($registerSlug)
				->setSchema('NotificationDelivery')
				->findAll(['filters' => ['sentAt[gte]' => $monthStart], 'limit' => 10000]);

			$recentFailures = array_filter(
				$todayDeliveries,
				static fn (array $d): bool => ($d['status'] ?? '') === 'failed'
			);

			return new JSONResponse(
				[
					'counts' => [
						'today' => count($todayDeliveries),
						'week' => count($weekDeliveries),
						'month' => count($monthDeliveries),
						'failed' => count($recentFailures),
					],
					'recentFailures' => array_values($recentFailures),
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: getNotificationMonitor failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['message' => 'Failed to load monitor data.'], 500);
		}//end try

	}//end getNotificationMonitor()

	/**
	 * Disable all notification triggers globally (emergency off-switch).
	 *
	 * Sets active=false on every BookingNotificationTrigger in the register
	 * per REQ-BNT-008. Admin-only.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function disableAllTriggers(): JSONResponse {
		try {
			$registerSlug = $this->settingsService->getRegisterSlug();

			$triggers = $this->objectService
				->setRegister($registerSlug)
				->setSchema('BookingNotificationTrigger')
				->findAll(['filters' => ['active' => true], 'limit' => 1000]);

			$disabled = 0;
			foreach ($triggers as $trigger) {
				$trigger['active'] = false;
				$this->objectService->saveObject(
					object: $trigger,
					register: $registerSlug,
					schema: 'BookingNotificationTrigger',
				);
				$disabled++;
			}

			$this->logger->warning('Shillinq: all notification triggers disabled by admin', ['count' => $disabled]);

			return new JSONResponse(['disabled' => $disabled, 'message' => 'All triggers disabled.']);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: disableAllTriggers failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['message' => 'Failed to disable triggers.'], 500);
		}//end try

	}//end disableAllTriggers()

	/**
	 * Authorise a user to access notification triggers for a booking.
	 *
	 * Throws OCSForbiddenException when the current user is neither a member of
	 * the booking's administration nor an admin, per Rule 3 (per-object
	 * authorization / OWASP A01).
	 *
	 * The Booking schema carries NO user-id field — its properties are
	 * calendar / resource / title / startTime / endTime / attendee / status /
	 * externalId / administrationId / bookingId, and `attendee` is a display
	 * name, not a uid. The per-object authority for a Booking is therefore its
	 * `administrationId`, checked through AdministrationContextService, which
	 * is this app's canonical membership seam.
	 *
	 * @param string $bookingId UUID of the booking.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When not authorised.
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 *
	 * RE-VERIFIED for security-endpoint-guards (Open Question / Risk 3):
	 * re-read against HEAD before any change. This guard is genuine and
	 * enforcing — admin bypass, then `AdministrationContextService::canAccess()`
	 * against the booking's own `administrationId`, throwing on both a
	 * missing booking and a non-member caller. Its docblock's own history
	 * note documents a PRIOR bug (`findObject()` — a method that does not
	 * exist on OpenRegister's ObjectService — silently turning every
	 * non-admin call into a 403 via the catch-all `\Throwable` arm) that
	 * was already fixed before this change started; that fix is what the
	 * audit's "confirmed" note was most likely trailing. No code change
	 * was needed here — verdict: ALREADY-GUARDED. The `#[NoAdminRequired]`
	 * (`getBookingTriggers`/`updateBookingTriggers`, both per-object,
	 * guarded by this method) vs `#[AuthorizedAdminSetting]`
	 * (`getNotificationMonitor`/`disableAllTriggers`, both instance-wide
	 * admin CRUD across every administration's data) split on this
	 * controller was also re-checked and is correct for what each method
	 * actually does — not a finding.
	 */
	private function authorizeBookingAccess(string $bookingId): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Not authenticated.');
		}

		$uid = $user->getUID();

		// phpcs:disable CustomSn.Functions.NamedParameters
		if ($this->groupManager->isAdmin($uid) === true) {
			// phpcs:enable CustomSn.Functions.NamedParameters
			return;
		}

		try {
			$registerSlug = $this->settingsService->getRegisterSlug();

			// ADR-022: the real ObjectService API is find()/findAll().
			// findObject() does not exist — calling it raised an Error that the
			// \Throwable arm below turned into a blanket 403, so this guard
			// denied EVERY non-admin instead of checking anything.
			$booking = $this->objectService->find(
				id: $bookingId,
				register: $registerSlug,
				schema: 'Booking'
			);

			if ($booking === null) {
				throw new OCSForbiddenException('Booking not found.');
			}

			$row = $booking->jsonSerialize();

			// An absent or empty administrationId must DENY, never skip the
			// check — that is the defect fixed in #474, where `?? ''` plus a
			// `!== ''` guard meant canAccess() was never reached.
			$administrationId = (string)($row['administrationId'] ?? '');
			if ($this->administrationContext->canAccess($administrationId) === false) {
				throw new OCSForbiddenException('Not authorized to access this booking.');
			}
		} catch (OCSForbiddenException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: booking authorization lookup failed',
				['bookingId' => $bookingId, 'exception' => $e->getMessage()]
			);
			throw new OCSForbiddenException('Authorization failed.');
		}//end try

	}//end authorizeBookingAccess()
}//end class
