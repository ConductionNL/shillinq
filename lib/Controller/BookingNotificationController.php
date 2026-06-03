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

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\BookingNotificationService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * REST endpoints for notification trigger configuration and admin monitoring.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
 */
class BookingNotificationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                   $request             The request object.
     * @param BookingNotificationService $notificationService The notification service.
     * @param SettingsService            $settingsService     The settings service.
     * @param ContainerInterface         $container           The DI container.
     * @param IGroupManager              $groupManager        The group manager.
     * @param IUserSession               $userSession         The user session.
     * @param LoggerInterface            $logger              The logger.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private BookingNotificationService $notificationService,
        private SettingsService $settingsService,
        private ContainerInterface $container,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private LoggerInterface $logger,
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
    public function getBookingTriggers(string $id): JSONResponse
    {
        $this->authorizeBookingAccess(bookingId: $id);

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->settingsService->getRegisterSlug();

            $triggers = $objectService->findObjects(
                register: $registerSlug,
                schema: 'BookingNotificationTrigger',
                params: ['bookingId' => $id, '_limit' => 100]
            );

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
    public function updateBookingTriggers(string $id): JSONResponse
    {
        $this->authorizeBookingAccess(bookingId: $id);

        $data = $this->request->getParams();
        unset($data['_route']);

        if (isset($data['triggers']) === false || is_array(value: $data['triggers']) === false) {
            return new JSONResponse(['message' => 'triggers array is required.'], 400);
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->settingsService->getRegisterSlug();

            $saved = [];
            foreach ($data['triggers'] as $triggerData) {
                $triggerData['bookingId'] = $id;
                $saved[] = $objectService->saveObject(
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
    public function getNotificationMonitor(): JSONResponse
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->settingsService->getRegisterSlug();

            $todayStart = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('c');
            $weekStart  = (new \DateTimeImmutable('-7 days', new \DateTimeZone('UTC')))->format('c');
            $monthStart = (new \DateTimeImmutable('-30 days', new \DateTimeZone('UTC')))->format('c');

            $todayDeliveries = $objectService->findObjects(
                register: $registerSlug,
                schema: 'NotificationDelivery',
                params: ['sentAt[gte]' => $todayStart, '_limit' => 1000]
            );
            $weekDeliveries  = $objectService->findObjects(
                register: $registerSlug,
                schema: 'NotificationDelivery',
                params: ['sentAt[gte]' => $weekStart, '_limit' => 5000]
            );
            $monthDeliveries = $objectService->findObjects(
                register: $registerSlug,
                schema: 'NotificationDelivery',
                params: ['sentAt[gte]' => $monthStart, '_limit' => 10000]
            );

            $recentFailures = array_filter(
                $todayDeliveries,
                static fn(array $d): bool => ($d['status'] ?? '') === 'failed'
            );

            return new JSONResponse(
                    [
                        'counts'         => [
                            'today'  => count($todayDeliveries),
                            'week'   => count($weekDeliveries),
                            'month'  => count($monthDeliveries),
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
    public function disableAllTriggers(): JSONResponse
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->settingsService->getRegisterSlug();

            $triggers = $objectService->findObjects(
                register: $registerSlug,
                schema: 'BookingNotificationTrigger',
                params: ['active' => true, '_limit' => 1000]
            );

            $disabled = 0;
            foreach ($triggers as $trigger) {
                $trigger['active'] = false;
                $objectService->saveObject(
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
     * Throws OCSForbiddenException when the current user is neither the booking
     * organizer nor an admin per Rule 3 (per-object authorization / OWASP A01).
     *
     * @param string $bookingId UUID of the booking.
     *
     * @return void
     *
     * @throws \OCP\AppFramework\OCS\OCSForbiddenException When not authorised.
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
     */
    private function authorizeBookingAccess(string $bookingId): void
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \OCP\AppFramework\OCS\OCSForbiddenException('Not authenticated.');
        }

        $uid = $user->getUID();

        // phpcs:disable CustomSn.Functions.NamedParameters
        if ($this->groupManager->isAdmin($uid) === true) {
            // phpcs:enable CustomSn.Functions.NamedParameters
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->settingsService->getRegisterSlug();

            $booking = $objectService->findObject(
                register: $registerSlug,
                schema: 'Booking',
                id: $bookingId
            );

            if ($booking === null) {
                throw new \OCP\AppFramework\OCS\OCSForbiddenException('Booking not found.');
            }

            $organizerUserId = (string) ($booking['organizerUserId'] ?? '');
            if ($organizerUserId !== $uid) {
                throw new \OCP\AppFramework\OCS\OCSForbiddenException('Not authorized to access this booking.');
            }
        } catch (\OCP\AppFramework\OCS\OCSForbiddenException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Shillinq: booking authorization lookup failed',
                ['bookingId' => $bookingId, 'exception' => $e->getMessage()]
            );
            throw new \OCP\AppFramework\OCS\OCSForbiddenException('Authorization failed.');
        }//end try

    }//end authorizeBookingAccess()
}//end class
