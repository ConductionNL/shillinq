<?php

/**
 * Notification Mailer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Sends one rendered notification (subject and plain-text body) to one
 * address through Nextcloud's mailer. The booking notification triggers used
 * to hand every email to openconnector's send endpoint, which no installed
 * app answers; an email now leaves through the instance's own mail server
 * (REQ-BET-011). Other channels still go to their channel adapter.
 *
 * Best-effort: a failure is logged and answered as false, never thrown, so
 * the caller can try the next channel and record the outcome.
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
 * @spec openspec/changes/bookings-messages-and-embedding/specs/notification-booking-email-templates/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mails a rendered notification through Nextcloud's mailer.
 *
 * @spec openspec/changes/bookings-messages-and-embedding/specs/notification-booking-email-templates/spec.md
 */
class NotificationMailer {
	/**
	 * Constructor.
	 *
	 * @param IMailer         $mailer Nextcloud's mailer.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send one mail; true only when the mail server accepted it for the address.
	 *
	 * @param string $address The recipient's email address.
	 * @param string $subject The rendered subject.
	 * @param string $body    The rendered plain-text body.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.2
	 */
	public function send(string $address, string $subject, string $body): bool {
		if ($this->mailer->validateMailAddress($address) === false) {
			$this->logger->info('Shillinq: notification not mailed, the address is not an email address');
			return false;
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$address]);
			$message->setSubject($subject);
			$message->setPlainBody($body);
			$failed = $this->mailer->send($message);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: notification mail could not be sent', ['exception' => $e->getMessage()]);
			return false;
		}

		if ($failed !== []) {
			$this->logger->warning('Shillinq: the mail server refused the notification mail');
			return false;
		}

		return true;
	}//end send()
}//end class
