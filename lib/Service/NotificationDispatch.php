<?php

/**
 * Notification Dispatch
 *
 * One notification to one recipient (REQ-BNT-004, REQ-BNT-005): the trigger,
 * the recipient with its preferred channels and address, the template and
 * the booking, with the per-channel notification and the audit record of an
 * attempt.
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

/**
 * A trigger's notification to one recipient about one booking.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
 */
final class NotificationDispatch {
	/**
	 * Hold the dispatch.
	 *
	 * @param array<mixed> $trigger The trigger definition.
	 * @param array<mixed> $recipient Recipient with keys: role, channels, address.
	 * @param array<mixed> $template The notification template.
	 * @param array<mixed> $booking Full booking payload.
	 */
	public function __construct(
		public readonly array $trigger,
		public readonly array $recipient,
		public readonly array $template,
		public readonly array $booking,
	) {
	}//end __construct()

	/**
	 * The preferred channels in order, email when none are given.
	 *
	 * @return array<mixed>
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
	 */
	public function channels(): array {
		return ($this->recipient['channels'] ?? ['email']);
	}//end channels()

	/**
	 * The recipient's address.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
	 */
	public function address(): string {
		return (string)($this->recipient['address'] ?? '');
	}//end address()

	/**
	 * The rendered notification on one channel.
	 *
	 * @param string $channel The channel.
	 * @param array<string,mixed> $rendered The rendered template (subject, body).
	 *
	 * @return OpenconnectorNotification
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
	 */
	public function via(string $channel, array $rendered): OpenconnectorNotification {
		return new OpenconnectorNotification(
			channel: $channel,
			recipient: $this->address(),
			subject: ($rendered['subject'] ?? ''),
			body: ($rendered['body'] ?? ''),
			templateId: ($this->template['id'] ?? '')
		);
	}//end via()

	/**
	 * The audit-trail notification of an attempt on a channel.
	 *
	 * @param string $channel The channel (or channels) attempted.
	 * @param int $retryCount The failed attempts before it.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-8
	 */
	public function auditRecord(string $channel, int $retryCount): array {
		return [
			'triggerName' => ($this->trigger['name'] ?? ''),
			'triggerType' => ($this->trigger['eventType'] ?? ''),
			'bookingId' => (string)($this->booking['id'] ?? ''),
			'recipient' => $this->address(),
			'channel' => $channel,
			'templateName' => ($this->template['name'] ?? ''),
			'retryCount' => $retryCount,
		];
	}//end auditRecord()
}//end class
