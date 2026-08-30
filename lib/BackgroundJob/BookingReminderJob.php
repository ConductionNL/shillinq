<?php

/**
 * Booking Reminder Background Job
 *
 * Cron job that runs hourly and fires booking.reminder notification triggers
 * for bookings scheduled within the configured reminder windows (24h, 1h, 15m).
 * Scheduled at booking creation time per REQ-BNT-001.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\BookingNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Hourly cron job that evaluates reminder triggers for upcoming bookings.
 *
 * Reminder windows: 24 hours, 1 hour, 15 minutes before the booking start time.
 * Runs every 3600 seconds (hourly). Idempotent — duplicate sends are prevented
 * by BookingNotificationService::isDuplicate().
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-7
 */
class BookingReminderJob extends TimedJob {

	/**
	 * Reminder windows in hours before the booking start time.
	 *
	 * @var array<int>
	 */
	private const REMINDER_WINDOWS_HOURS = [24, 1];

	/**
	 * Reminder windows in minutes before the booking start time.
	 *
	 * @var array<int>
	 */
	private const REMINDER_WINDOWS_MINUTES = [15];

	/**
	 * Tolerance window in minutes to match bookings near the target time.
	 */
	private const MATCH_TOLERANCE_MINUTES = 30;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param BookingNotificationService $notificationService The notification service.
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private BookingNotificationService $notificationService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
		parent::__construct(time: $time);
		// Run every hour.
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Execute the reminder evaluation run.
	 *
	 * Queries OpenRegister for confirmed bookings that fall within a reminder
	 * window and fires booking.reminder events for each match.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-7
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by
	 *     TimedJob::run()'s signature; this job takes no argument.
	 */
	protected function run(mixed $argument): void {
		$this->logger->info('Shillinq: BookingReminderJob starting');

		$allWindows = [];
		foreach (self::REMINDER_WINDOWS_HOURS as $hours) {
			$allWindows[] = ['hours' => $hours, 'minutes' => ($hours * 60)];
		}

		foreach (self::REMINDER_WINDOWS_MINUTES as $minutes) {
			$allWindows[] = ['hours' => 0, 'minutes' => $minutes];
		}

		$totalFired = 0;
		foreach ($allWindows as $window) {
			$totalFired += $this->processReminderWindow(windowMinutes: $window['minutes']);
		}

		$this->logger->info('Shillinq: BookingReminderJob complete', ['fired' => $totalFired]);

	}//end run()

	/**
	 * Process one reminder window: find bookings starting in windowMinutes ± tolerance
	 * and fire booking.reminder events.
	 *
	 * @param int $windowMinutes Minutes before booking start for this reminder.
	 *
	 * @return int Number of reminder events fired.
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-7
	 */
	private function processReminderWindow(int $windowMinutes): int {
		try {
			$registerSlug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
			if ($registerSlug === '') {
				$registerSlug = 'shillinq';
			}

			$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$windowStart = $now->modify('+' . ($windowMinutes - self::MATCH_TOLERANCE_MINUTES) . ' minutes');
			$windowEnd = $now->modify('+' . ($windowMinutes + self::MATCH_TOLERANCE_MINUTES) . ' minutes');

			// ADR-022: use the real ObjectService fluent API (setRegister/setSchema/findAll);
			// findObjects() does not exist on OpenRegister's ObjectService.
			$bookings = $this->objectService
				->setRegister($registerSlug)
				->setSchema('Booking')
				->findAll(
					[
						'filters' => [
							'status' => 'confirmed',
							'startTime[gte]' => $windowStart->format('c'),
							'startTime[lte]' => $windowEnd->format('c'),
						],
						'limit' => 500,
					]
				);

			$fired = 0;
			foreach ($bookings as $booking) {
				$hoursUntil = round(num: $windowMinutes / 60, precision: 1);
				$bookingWithCtx = array_merge(
					$booking,
					['hoursUntilEvent' => $hoursUntil]
				);

				$this->notificationService->evaluateEventTrigger(
					eventType: 'reminder',
					booking: $bookingWithCtx
				);
				$fired++;
			}

			return $fired;
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: BookingReminderJob window failed',
				['windowMinutes' => $windowMinutes, 'exception' => $e->getMessage()]
			);
			return 0;
		}//end try

	}//end processReminderWindow()
}//end class
