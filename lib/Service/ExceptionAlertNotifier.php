<?php

/**
 * Exception Alert Notifier
 *
 * Delivers an {@see ExceptionAlert} to each resolved recipient through the
 * Nextcloud notification manager, logging and continuing on a failed delivery.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTime;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * Sends ThreeWayMatch alerts to role members.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
 */
class ExceptionAlertNotifier {

	/**
	 * Construct the notifier.
	 *
	 * @param INotificationManager $notificationManager NC notification dispatcher.
	 * @param LoggerInterface      $logger              Logger.
	 */
	public function __construct(
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Notify every recipient, or log that the role has none.
	 *
	 * @param ExceptionAlert    $alert   The alert.
	 * @param array<int,string> $userIds Unique recipient user ids.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
	 */
	public function deliver(ExceptionAlert $alert, array $userIds): void {
		if ($userIds === []) {
			$this->logger->info(
				'ExceptionResolutionService: no recipients for exception alert',
				['administrationId' => $alert->administrationId, 'role' => $alert->role, 'matchId' => $alert->matchId]
			);
			return;
		}

		foreach ($userIds as $userId) {
			$this->notifyMember(alert: $alert, userId: $userId);
		}

	}//end deliver()

	/**
	 * Notify one recipient; a failure is logged, never thrown.
	 *
	 * @param ExceptionAlert $alert  The alert.
	 * @param string         $userId Recipient user id.
	 *
	 * @return void
	 */
	private function notifyMember(ExceptionAlert $alert, string $userId): void {
		try {
			$this->notificationManager->notify($this->composeNotification(alert: $alert, userId: $userId));
		} catch (\Throwable $exception) {
			$context = ['administrationId' => $alert->administrationId, 'userId' => $userId, 'role' => $alert->role];
			$context += ['subject' => $alert->subject, 'matchId' => $alert->matchId, 'exception' => $exception->getMessage()];
			$this->logger->warning('ExceptionResolutionService: failed to dispatch exception notification', $context);
		}

	}//end notifyMember()

	/**
	 * Build the notification for one recipient.
	 *
	 * @param ExceptionAlert $alert  The alert.
	 * @param string         $userId Recipient user id.
	 *
	 * @return INotification
	 */
	private function composeNotification(ExceptionAlert $alert, string $userId): INotification {
		$notification = $this->notificationManager->createNotification();
		$notification
			->setApp(AppIdentity::APP_ID)
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject(ExceptionResolutionService::NOTIFICATION_OBJECT_TYPE, $alert->matchId)
			->setSubject($alert->subject, $this->parameters(alert: $alert));

		return $notification;

	}//end composeNotification()

	/**
	 * The notification parameter bag.
	 *
	 * @param ExceptionAlert $alert The alert.
	 *
	 * @return array<string,int|string>
	 */
	private function parameters(ExceptionAlert $alert): array {
		$invoice = $alert->invoice;

		return [
			'matchId' => $alert->matchId,
			'invoiceId' => (string)($invoice['id'] ?? ''),
			'invoiceNumber' => (string)($invoice['invoiceNumber'] ?? ''),
			'supplierId' => (string)($invoice['supplierId'] ?? ''),
			'totalInclVat' => (int)($invoice['totalInclVat'] ?? 0),
			'currency' => (string)($invoice['currency'] ?? 'EUR'),
		];

	}//end parameters()
}//end class
