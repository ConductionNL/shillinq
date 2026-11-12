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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\AppInfo\AppIdentity;
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
			return $this->remind(bookings: $this->bookingsInWindow(windowMinutes: $windowMinutes), windowMinutes: $windowMinutes);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: BookingReminderJob window failed',
				['windowMinutes' => $windowMinutes, 'exception' => $e->getMessage()]
			);
			return 0;
		}
	}//end processReminderWindow()

	/**
	 * The confirmed bookings starting within windowMinutes ± tolerance (at most 500).
	 *
	 * ADR-022: uses the real ObjectService fluent API (setRegister/setSchema/findAll);
	 * findObjects() does not exist on OpenRegister's ObjectService.
	 *
	 * @param int $windowMinutes Minutes before booking start for this reminder.
	 *
	 * @return array<int, mixed>
	 */
	private function bookingsInWindow(int $windowMinutes): array {
		$registerSlug = $this->registerSlug();
		$filters = self::windowFilters(windowMinutes: $windowMinutes);

		return $this->objectService
			->setRegister($registerSlug)
			->setSchema('Booking')
			->findAll(['filters' => $filters, 'limit' => 500]);
	}//end bookingsInWindow()

	/**
	 * The configured register slug, 'shillinq' when blank.
	 *
	 * @return string
	 */
	private function registerSlug(): string {
		$registerSlug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($registerSlug === '') {
			return 'shillinq';
		}

		return $registerSlug;
	}//end registerSlug()

	/**
	 * The booking filter: confirmed, starting between now + window - tolerance and now + window + tolerance (UTC, ISO 8601).
	 *
	 * @param int $windowMinutes Minutes before booking start for this reminder.
	 *
	 * @return array<string, string>
	 */
	private static function windowFilters(int $windowMinutes): array {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$windowStart = $now->modify('+' . ($windowMinutes - self::MATCH_TOLERANCE_MINUTES) . ' minutes');
		$windowEnd = $now->modify('+' . ($windowMinutes + self::MATCH_TOLERANCE_MINUTES) . ' minutes');

		return [
			'status' => 'confirmed',
			'startTime[gte]' => $windowStart->format('c'),
			'startTime[lte]' => $windowEnd->format('c'),
		];
	}//end windowFilters()

	/**
	 * Fire one booking.reminder event per booking, with the hours until it starts.
	 *
	 * @param array<int, mixed> $bookings      The bookings in the window.
	 * @param int               $windowMinutes Minutes before booking start for this reminder.
	 *
	 * @return int Number of reminder events fired.
	 */
	private function remind(array $bookings, int $windowMinutes): int {
		$fired = 0;
		foreach ($bookings as $booking) {
			$hoursUntil = round(num: $windowMinutes / 60, precision: 1);
			$bookingWithCtx = array_merge($booking, ['hoursUntilEvent' => $hoursUntil]);
			$this->notificationService->evaluateEventTrigger(eventType: 'reminder', booking: $bookingWithCtx);
			$fired++;
		}

		return $fired;
	}//end remind()
}//end class
