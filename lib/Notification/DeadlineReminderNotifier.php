<?php

/**
 * Deadline Reminder Notifier
 *
 * Renders the `deadline_reminder` notifications raised by
 * ComplianceDeadlineCalendarService (REQ-CDC-007) for the Nextcloud
 * notification centre. Without a registered INotifier a raised
 * notification is silently discarded at display time, so this class is
 * part of the REQ-CDC-007 surface, not optional plumbing.
 *
 * Subject parameters: summary (deadline label, e.g. "BTW-aangifte
 * 2026-Q1"), dueDate (Y-m-d), category, daysUntil. i18n keys are English
 * (ADR-005); Dutch translations live in l10n/nl.json.
 *
 * @category Notification
 * @package  OCA\Shillinq\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Notification;

use OCA\Shillinq\AppInfo\Application;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Prepares `deadline_reminder` notifications for display (REQ-CDC-007).
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */
class DeadlineReminderNotifier implements INotifier {
	/**
	 * Construct the notifier.
	 *
	 * @param IFactory $l10nFactory The l10n factory for per-language rendering.
	 */
	public function __construct(
		private readonly IFactory $l10nFactory,
	) {
	}//end __construct()

	/**
	 * The notifier id (app id).
	 *
	 * @return string The identifier.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function getID(): string {
		return Application::APP_ID;
	}//end getID()

	/**
	 * The human-readable notifier name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Shillinq');
	}//end getName()

	/**
	 * Prepare a `deadline_reminder` notification for display.
	 *
	 * @param INotification $notification The raw notification.
	 * @param string $languageCode The language to render in.
	 *
	 * @return INotification The prepared notification.
	 *
	 * @throws UnknownNotificationException When the notification is not ours.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID
			|| $notification->getSubject() !== 'deadline_reminder'
		) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$parameters = $notification->getSubjectParameters();
		$summary = (string)($parameters['summary'] ?? '');
		$dueDate = (string)($parameters['dueDate'] ?? '');

		$notification->setParsedSubject(
			$l->t('Deadline approaching: %1$s (due %2$s)', [$summary, $dueDate])
		);
		$notification->setParsedMessage(
			$l->t('This deadline is coming up. Open Shillinq to review it.')
		);

		return $notification;
	}//end prepare()
}//end class
