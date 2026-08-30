<?php

/**
 * Booking Notification Service
 *
 * Evaluates notification triggers on booking lifecycle events, dispatches
 * notifications via openconnector channel adapters, enforces rate-limiting,
 * checks recipient opt-out preferences, and records tamper-evident audit
 * trail entries per ADR-022.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Evaluates booking notification triggers and dispatches via openconnector.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
 */
class BookingNotificationService {

	/**
	 * Openconnector notification endpoint path.
	 */
	private const OPENCONNECTOR_ENDPOINT = '/openconnector/api/notifications/send';

	/**
	 * Default rate-limit: max notifications per booking per hour.
	 */
	private const DEFAULT_RATE_LIMIT_BOOKING_HOURLY = 10;

	/**
	 * Default rate-limit: max notifications per organizer per day.
	 */
	private const DEFAULT_RATE_LIMIT_ORGANIZER_DAILY = 100;

	/**
	 * Default deduplication window in minutes.
	 */
	private const DEFAULT_DEDUP_WINDOW_MINUTES = 5;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param IAppConfig $appConfig The Nextcloud app config.
	 * @param LoggerInterface $logger The logger interface.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @return void
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Evaluate all active triggers for the given booking event type and dispatch
	 * notifications to matching recipients.
	 *
	 * Orchestrates the full pipeline: load triggers → evaluate conditions →
	 * check rate-limit and opt-out → render template → dispatch → audit.
	 *
	 * @param string $eventType Booking event: created|changed|cancelled|reminder.
	 * @param array<mixed> $booking Full booking object payload (see REQ-BNT-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
	 */
	public function evaluateEventTrigger(string $eventType, array $booking): void {
		$triggers = $this->loadActiveTriggers(eventType: $eventType, bookingId: ($booking['id'] ?? null));

		foreach ($triggers as $trigger) {
			$recipients = $this->evaluateRecipientRules(
				rules: ($trigger['recipients'] ?? []),
				booking: $booking
			);

			foreach ($recipients as $recipient) {
				$address = $this->resolveRecipientAddress(role: $recipient['role'], booking: $booking);
				if ($address === '') {
					continue;
				}

				$bookingId = (string)($booking['id'] ?? '');
				$organizerId = (string)($booking['organizerUserId'] ?? $booking['organizer'] ?? '');

				if ($this->isOptedOut(recipient: $address, triggerType: $eventType) === true) {
					$this->recordAuditTrail(
						notification: [
							'triggerName' => ($trigger['name'] ?? $eventType),
							'triggerType' => $eventType,
							'bookingId' => $bookingId,
							'recipient' => $address,
							'channel' => ($recipient['channels'][0] ?? 'email'),
							'templateName' => '',
						],
						status: 'skipped',
						reason: 'opt-out'
					);
					continue;
				}

				$rateLimitPerBooking = (int)($trigger['rateLimitPerBookingPerHour'] ?? self::DEFAULT_RATE_LIMIT_BOOKING_HOURLY);
				$rateLimitPerOrganizer = (int)($trigger['rateLimitPerOrganizerPerDay'] ?? self::DEFAULT_RATE_LIMIT_ORGANIZER_DAILY);
				$dedupWindowMinutes = (int)($trigger['deduplicationWindowMinutes'] ?? self::DEFAULT_DEDUP_WINDOW_MINUTES);

				if ($this->isRateLimited(
					bookingId: $bookingId,
					organizerId: $organizerId,
					rateLimitPerBooking: $rateLimitPerBooking,
					rateLimitPerOrganizer: $rateLimitPerOrganizer
				) === true
				) {
					$this->logger->warning(
						'Shillinq: notification rate-limited',
						['bookingId' => $bookingId, 'triggerType' => $eventType]
					);
					continue;
				}

				if ($this->isDuplicate(
					bookingId: $bookingId,
					recipient: $address,
					triggerType: $eventType,
					windowMinutes: $dedupWindowMinutes
				) === true
				) {
					$this->logger->info(
						'Shillinq: notification deduplicated',
						['bookingId' => $bookingId, 'recipient' => $address]
					);
					continue;
				}

				$template = $this->loadTemplate(triggerId: ($trigger['templateId'] ?? ''), eventType: $eventType);

				$this->dispatchNotification(
					trigger: $trigger,
					recipient: array_merge($recipient, ['address' => $address]),
					template: $template,
					booking: $booking
				);
			}//end foreach
		}//end foreach

	}//end evaluateEventTrigger()

	/**
	 * Dispatch a notification to a single recipient via openconnector channel adapters.
	 *
	 * Iterates preferred channels in order; returns on first success. Falls back to
	 * the next channel if the current adapter fails. Records audit trail for every
	 * attempt per REQ-BNT-004 and REQ-BNT-005.
	 *
	 * @param array<mixed> $trigger The trigger definition.
	 * @param array<mixed> $recipient Recipient with keys: role, channels, address.
	 * @param array<mixed> $template The notification template.
	 * @param array<mixed> $booking Full booking payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
	 */
	public function dispatchNotification(array $trigger, array $recipient, array $template, array $booking): void {
		$rendered = $this->renderTemplate(template: $template, booking: $booking, recipient: $recipient);
		$channels = ($recipient['channels'] ?? ['email']);
		$address = (string)($recipient['address'] ?? '');

		$retryCount = 0;
		foreach ($channels as $channel) {
			$success = $this->sendViaOpenconnector(
				channel: $channel,
				recipient: $address,
				subject: ($rendered['subject'] ?? ''),
				body: ($rendered['body'] ?? ''),
				templateId: ($template['id'] ?? '')
			);

			if ($success === true) {
				$this->recordAuditTrail(
					notification: [
						'triggerName' => ($trigger['name'] ?? ''),
						'triggerType' => ($trigger['eventType'] ?? ''),
						'bookingId' => (string)($booking['id'] ?? ''),
						'recipient' => $address,
						'channel' => $channel,
						'templateName' => ($template['name'] ?? ''),
						'retryCount' => $retryCount,
					],
					status: 'sent',
					reason: ''
				);
				return;
			}

			$retryCount++;
		}//end foreach

		// All channels failed — record final failure.
		$this->recordAuditTrail(
			notification: [
				'triggerName' => ($trigger['name'] ?? ''),
				'triggerType' => ($trigger['eventType'] ?? ''),
				'bookingId' => (string)($booking['id'] ?? ''),
				'recipient' => $address,
				'channel' => implode(separator: '/', array: $channels),
				'templateName' => ($template['name'] ?? ''),
				'retryCount' => $retryCount,
			],
			status: 'failed',
			reason: 'all channels exhausted'
		);

		$this->logger->error(
			'Shillinq: all notification channels failed',
			['bookingId' => ($booking['id'] ?? ''), 'recipient' => $address]
		);

	}//end dispatchNotification()

	/**
	 * Record a notification dispatch attempt in the OpenRegister audit trail.
	 *
	 * Per ADR-022 all notification events are recorded as NotificationDelivery
	 * objects. The record is tamper-evident via OpenRegister's hash-chaining.
	 *
	 * @param array<mixed> $notification Notification metadata (triggerName, triggerType, bookingId, etc.).
	 * @param string $status Outcome: sent|pending|failed|skipped.
	 * @param string $reason Failure or skip reason; empty string on success.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-8
	 */
	public function recordAuditTrail(array $notification, string $status, string $reason): void {
		try {
			$registerSlug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
			if ($registerSlug === '') {
				$registerSlug = 'shillinq';
			}

			$failureReason = null;
			if ($reason !== '') {
				$failureReason = $reason;
			}

			$record = [
				'triggerName' => ($notification['triggerName'] ?? ''),
				'triggerType' => ($notification['triggerType'] ?? ''),
				'bookingId' => ($notification['bookingId'] ?? ''),
				'recipient' => ($notification['recipient'] ?? ''),
				'channel' => ($notification['channel'] ?? ''),
				'templateName' => ($notification['templateName'] ?? ''),
				'status' => $status,
				'failureReason' => $failureReason,
				'retryCount' => (int)($notification['retryCount'] ?? 0),
				'sentAt' => (new DateTimeImmutable())->format('c'),
			];

			$this->objectService->saveObject(
				object: $record,
				register: $registerSlug,
				schema: 'NotificationDelivery',
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: failed to record notification audit trail',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end recordAuditTrail()

	/**
	 * Render a notification template by substituting booking, recipient, and system
	 * variables using a Twig-style {{ variable }} syntax.
	 *
	 * Missing variables are rendered as empty string (REQ-BNT-002).
	 * Supports date filter: {{ booking.startTime | date('d M Y') }}.
	 *
	 * @param array<mixed> $template Template with keys: subject, body.
	 * @param array<mixed> $booking Booking data for variable substitution.
	 * @param array<mixed> $recipient Recipient data for variable substitution.
	 *
	 * @return array{subject: string, body: string}
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
	 */
	public function renderTemplate(array $template, array $booking, array $recipient): array {
		$context = $this->buildTemplateContext(booking: $booking, recipient: $recipient);

		return [
			'subject' => $this->substituteVariables(text: (string)($template['subject'] ?? ''), context: $context),
			'body' => $this->substituteVariables(text: (string)($template['body'] ?? ''), context: $context),
		];

	}//end renderTemplate()

	/**
	 * Evaluate an ordered list of recipient rules against a booking object.
	 *
	 * Each rule has: role, channels[], condition (optional simple expression).
	 * Rules with a false condition are skipped per REQ-BNT-003.
	 *
	 * @param array<mixed> $rules Ordered recipient rule list from the trigger.
	 * @param array<mixed> $booking Booking data used for condition evaluation.
	 *
	 * @return array<mixed> Matched recipient rules in evaluation order.
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function evaluateRecipientRules(array $rules, array $booking): array {
		$matched = [];
		foreach ($rules as $rule) {
			$condition = (string)($rule['condition'] ?? 'true');
			if ($this->evaluateCondition(condition: $condition, booking: $booking) === true) {
				$matched[] = $rule;
			}
		}

		return $matched;
	}//end evaluateRecipientRules()

	/**
	 * Evaluate a simple condition expression against booking data.
	 *
	 * Supports:
	 * - Literal "true" / "false"
	 * - booking.field == 'value'
	 * - booking.field != 'value'
	 * - booking.field > numeric
	 * - booking.field < numeric
	 *
	 * Returns true when condition string is empty or unrecognised (fail-open for
	 * forward-compatible conditions).
	 *
	 * @param string $condition Simple expression string.
	 * @param array<mixed> $booking Booking data.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	public function evaluateCondition(string $condition, array $booking): bool {
		$condition = trim($condition);

		if ($condition === '' || $condition === 'true') {
			return true;
		}

		if ($condition === 'false') {
			return false;
		}

		// Pattern: booking.field == 'value'.
		if (preg_match('/^booking\.(\w+)\s*==\s*[\'"](.+?)[\'"]\s*$/', $condition, $m) === 1) {
			return ($booking[$m[1]] ?? '') === $m[2];
		}

		// Pattern: booking.field != 'value'.
		if (preg_match('/^booking\.(\w+)\s*!=\s*[\'"](.+?)[\'"]\s*$/', $condition, $m) === 1) {
			return ($booking[$m[1]] ?? '') !== $m[2];
		}

		// Pattern: booking.field > numeric.
		if (preg_match('/^booking\.(\w+)\s*>\s*(\d+(?:\.\d+)?)\s*$/', $condition, $m) === 1) {
			return (float)($booking[$m[1]] ?? 0) > (float)$m[2];
		}

		// Pattern: booking.field < numeric.
		if (preg_match('/^booking\.(\w+)\s*<\s*(\d+(?:\.\d+)?)\s*$/', $condition, $m) === 1) {
			return (float)($booking[$m[1]] ?? 0) < (float)$m[2];
		}

		// Unknown expression — fail-open for forward compatibility.
		$this->logger->warning(
			'Shillinq: unrecognised condition expression, defaulting to true',
			['condition' => $condition]
		);
		return true;
	}//end evaluateCondition()

	/**
	 * Check whether a recipient has opted out of notifications.
	 *
	 * Queries the NotificationOptOut schema in OpenRegister. When the object
	 * service is unavailable, returns false (fail-open per REQ-BNT-009).
	 *
	 * @param string $recipient Recipient address (email, phone, chat ID).
	 * @param string $triggerType Trigger event type (created|changed|cancelled|reminder).
	 *
	 * @return bool True if the recipient should NOT receive a notification.
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-13
	 */
	public function isOptedOut(string $recipient, string $triggerType): bool {
		try {
			$registerSlug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
			if ($registerSlug === '') {
				$registerSlug = 'shillinq';
			}

			$results = $this->objectService
				->setRegister($registerSlug)
				->setSchema('NotificationOptOut')
				->findAll(['filters' => ['recipient' => $recipient], 'limit' => 1]);

			if (empty($results) === true) {
				return false;
			}

			$optOut = $results[0];

			// Global opt-out overrides trigger-specific check.
			if (($optOut['globalOptOut'] ?? false) === true) {
				return true;
			}

			$optedOutTypes = ($optOut['optedOutTriggerTypes'] ?? []);
			return in_array(needle: $triggerType, haystack: $optedOutTypes, strict: true);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: opt-out check failed, defaulting to not opted-out',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end isOptedOut()

	/**
	 * Check whether sending another notification for this booking/organizer would
	 * exceed the configured rate limits.
	 *
	 * Counts NotificationDelivery records in the relevant windows:
	 * - Per booking per calendar hour (UTC)
	 * - Per organizer per calendar day (UTC)
	 *
	 * @param string $bookingId UUID of the booking.
	 * @param string $organizerId User ID of the organizer.
	 * @param int $rateLimitPerBooking Max notifications per booking per hour.
	 * @param int $rateLimitPerOrganizer Max notifications per organizer per day.
	 *
	 * @return bool True if rate-limited (should NOT send).
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
	 */
	public function isRateLimited(
		string $bookingId,
		string $organizerId,
		int $rateLimitPerBooking = self::DEFAULT_RATE_LIMIT_BOOKING_HOURLY,
		int $rateLimitPerOrganizer = self::DEFAULT_RATE_LIMIT_ORGANIZER_DAILY,
	): bool {
		if ($bookingId === '' && $organizerId === '') {
			return false;
		}

		try {
			$registerSlug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
			if ($registerSlug === '') {
				$registerSlug = 'shillinq';
			}

			// Check per-booking hourly limit.
			if ($bookingId !== '') {
				$hourStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:00:00\Z');
				$bookingHit = $this->objectService
					->setRegister($registerSlug)
					->setSchema('NotificationDelivery')
					->findAll(
						[
							'filters' => [
								'bookingId' => $bookingId,
								'sentAt[gte]' => $hourStart,
							],
							'limit' => ($rateLimitPerBooking + 1),
						]
					);

				if (count($bookingHit) >= $rateLimitPerBooking) {
					return true;
				}
			}

			// Check per-organizer daily limit.
			if ($organizerId !== '') {
				$dayStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\T00:00:00\Z');
				$organizerHit = $this->objectService
					->setRegister($registerSlug)
					->setSchema('NotificationDelivery')
					->findAll(
						[
							'filters' => [
								'organizerId' => $organizerId,
								'sentAt[gte]' => $dayStart,
							],
							'limit' => ($rateLimitPerOrganizer + 1),
						]
					);

				if (count($organizerHit) >= $rateLimitPerOrganizer) {
					return true;
				}
			}

			return false;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: rate-limit check failed, defaulting to not limited',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end isRateLimited()

	/**
	 * Check whether a duplicate notification was recently sent to prevent double-sends.
	 *
	 * A duplicate is defined as the same (bookingId, recipient, triggerType) within
	 * the configured deduplication window (default 5 minutes) per REQ-BNT-006.
	 *
	 * @param string $bookingId UUID of the booking.
	 * @param string $recipient Recipient address.
	 * @param string $triggerType Trigger event type.
	 * @param int $windowMinutes Deduplication window in minutes.
	 *
	 * @return bool True if a duplicate send was detected.
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
	 */
	public function isDuplicate(
		string $bookingId,
		string $recipient,
		string $triggerType,
		int $windowMinutes = self::DEFAULT_DEDUP_WINDOW_MINUTES,
	): bool {
		try {
			$registerSlug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
			if ($registerSlug === '') {
				$registerSlug = 'shillinq';
			}

			$windowStart = (new DateTimeImmutable(
				'-' . $windowMinutes . ' minutes',
				new DateTimeZone('UTC')
			))->format('c');

			$existing = $this->objectService
				->setRegister($registerSlug)
				->setSchema('NotificationDelivery')
				->findAll(
					[
						'filters' => [
							'bookingId' => $bookingId,
							'recipient' => $recipient,
							'triggerType' => $triggerType,
							'sentAt[gte]' => $windowStart,
						],
						'limit' => 1,
					]
				);

			return empty($existing) === false;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: deduplication check failed, defaulting to not duplicate',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end isDuplicate()

	/**
	 * Send a notification via openconnector's channel adapter API.
	 *
	 * Makes an HTTP POST to /openconnector/api/notifications/send. Returns true
	 * on success (HTTP 2xx), false on any error (adapter unavailable, network
	 * timeout, non-2xx response) per REQ-BNT-004.
	 *
	 * @param string $channel Delivery channel: email|sms|chat.
	 * @param string $recipient Recipient address.
	 * @param string $subject Rendered notification subject.
	 * @param string $body Rendered notification body.
	 * @param string $templateId UUID of the template used.
	 *
	 * @return bool True on successful dispatch.
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-5
	 */
	public function sendViaOpenconnector(
		string $channel,
		string $recipient,
		string $subject,
		string $body,
		string $templateId,
	): bool {
		try {
			$clientService = $this->container->get('OCP\Http\Client\IClientService');
			$client = $clientService->newClient();

			$payload = [
				'channelAdapter' => $channel,
				'recipient' => $recipient,
				'subject' => $subject,
				'body' => $body,
				'templateId' => $templateId,
			];

			$response = $client->post(
				url: self::OPENCONNECTOR_ENDPOINT,
				options: [
					'json' => $payload,
					'timeout' => 10,
				]
			);

			$statusCode = $response->getStatusCode();
			return ($statusCode >= 200 && $statusCode < 300);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: openconnector send failed',
				['channel' => $channel, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end sendViaOpenconnector()

	/**
	 * Load active notification triggers for the given event type.
	 *
	 * Returns both global triggers (bookingId == null) and booking-specific
	 * triggers that match the provided bookingId.
	 *
	 * @param string $eventType Booking event type.
	 * @param string|null $bookingId Optional UUID of the specific booking.
	 *
	 * @return array<mixed> Active trigger objects.
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-6
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $bookingId is accepted
	 *     and documented above as filtering "booking-specific triggers that
	 *     match", but the query below only filters on eventType+active —
	 *     flagged during issue #506 as a possible gap between docblock and
	 *     implementation, not confirmed as intentional. Left unchanged here
	 *     (style/quality pass only); worth a follow-up look.
	 */
	private function loadActiveTriggers(string $eventType, ?string $bookingId): array {
		try {
			$registerSlug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
			if ($registerSlug === '') {
				$registerSlug = 'shillinq';
			}

			return $this->objectService
				->setRegister($registerSlug)
				->setSchema('BookingNotificationTrigger')
				->findAll(
					[
						'filters' => [
							'eventType' => $eventType,
							'active' => true,
						],
						'limit' => 100,
					]
				);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: failed to load notification triggers',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end loadActiveTriggers()

	/**
	 * Load a notification template by trigger ID or fall back to event type default.
	 *
	 * @param string $triggerId UUID of the trigger's preferred template.
	 * @param string $eventType Booking event type (fallback lookup).
	 *
	 * @return array<mixed> Template object, or empty array if none found.
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
	 */
	private function loadTemplate(string $triggerId, string $eventType): array {
		try {
			$registerSlug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
			if ($registerSlug === '') {
				$registerSlug = 'shillinq';
			}

			if ($triggerId !== '') {
				// ADR-022: use the real ObjectService API (find/findAll);
				// findObject()/findObjects() do not exist on OpenRegister's ObjectService.
				$template = $this->objectService->find(
					id: $triggerId,
					register: $registerSlug,
					schema: 'BookingNotificationTemplate'
				);
				if ($template !== null) {
					return $template->jsonSerialize();
				}
			}

			// Fallback: find active template matching the event type.
			$results = $this->objectService
				->setRegister($registerSlug)
				->setSchema('BookingNotificationTemplate')
				->findAll(
					[
						'filters' => [
							'trigger' => $eventType,
							'active' => true,
						],
						'limit' => 1,
					]
				);

			$first = ($results[0] ?? []);
			if ($first instanceof \OCA\OpenRegister\Db\ObjectEntity) {
				return $first->jsonSerialize();
			}

			return $first;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: failed to load notification template',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end loadTemplate()

	/**
	 * Resolve a recipient's contact address from the booking payload.
	 *
	 * @param string $role Recipient role: customer|organizer|admin_group.
	 * @param array<mixed> $booking Booking payload.
	 *
	 * @return string Email, phone, or chat address; empty string if unresolvable.
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
	 */
	private function resolveRecipientAddress(string $role, array $booking): string {
		return match ($role) {
			'customer' => (string)($booking['guestEmail'] ?? ''),
			'organizer' => (string)($booking['organizerEmail'] ?? ''),
			'admin_group' => (string)($booking['adminEmail'] ?? ''),
			default => '',
		};

	}//end resolveRecipientAddress()

	/**
	 * Build the Twig variable context from booking and recipient data.
	 *
	 * Returns a flat key=>value map where keys are dot-notation variable names
	 * (e.g. "booking.guestName", "recipient.email", "system.appName").
	 *
	 * @param array<mixed> $booking Booking payload.
	 * @param array<mixed> $recipient Recipient data.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
	 */
	private function buildTemplateContext(array $booking, array $recipient): array {
		$context = [];

		foreach ($booking as $key => $value) {
			$context['booking.' . $key] = (string)($value ?? '');
		}

		foreach ($recipient as $key => $value) {
			if (is_string(value: $value) === true || is_numeric(value: $value) === true) {
				$context['recipient.' . $key] = (string)$value;
			}
		}

		$context['system.appName'] = 'Bookings';

		return $context;
	}//end buildTemplateContext()

	/**
	 * Substitute {{ variable }} and {{ variable | date('format') }} placeholders.
	 *
	 * Missing variables are replaced with empty string (REQ-BNT-002).
	 *
	 * @param string $text Template text with {{ }} placeholders.
	 * @param array<string,string> $context Key=>value variable map.
	 *
	 * @return string Rendered text.
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
	 */
	private function substituteVariables(string $text, array $context): string {
		// Handle {{ variable | date('format') }} filter.
		$text = preg_replace_callback(
			pattern: '/\{\{\s*([\w.]+)\s*\|\s*date\([\'"](.+?)[\'"]\)\s*\}\}/',
			callback: function (array $m) use ($context): string {
				$raw = ($context[$m[1]] ?? '');
				if ($raw === '') {
					return '';
				}

				try {
					return (new DateTimeImmutable($raw))->format($m[2]);
				} catch (\Throwable) {
					return $raw;
				}
			},
			subject: $text
		) ?? $text;

		// Handle plain {{ variable }}.
		$text = preg_replace_callback(
			pattern: '/\{\{\s*([\w.]+)\s*\}\}/',
			callback: static function (array $m) use ($context): string {
				return ($context[$m[1]] ?? '');
			},
			subject: $text
		) ?? $text;

		return $text;
	}//end substituteVariables()
}//end class
