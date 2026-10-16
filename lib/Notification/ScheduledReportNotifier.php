<?php

/**
 * Scheduled Report Notifier
 *
 * Renders the two notifications a report schedule raises
 * (reporting-data-delivery REQ-RDD-002): a produced report for its
 * recipients, and a failed run for the schedule's owner.
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
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Notification;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Reporting\Schedule\ScheduledReportDelivery;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders scheduled report notifications.
 */
class ScheduledReportNotifier implements INotifier {

	/**
	 * Constructor.
	 *
	 * @param IFactory $l10nFactory The l10n factory for per-language rendering.
	 */
	public function __construct(
		private readonly IFactory $l10nFactory,
	) {

	}//end __construct()

	/**
	 * The notifier id.
	 *
	 * @return string The identifier.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function getID(): string {
		return Application::APP_ID . '-scheduled-reports';

	}//end getID()

	/**
	 * The notifier name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Scheduled reports');

	}//end getName()

	/**
	 * Render a scheduled report notification.
	 *
	 * @param INotification $notification The raw notification.
	 * @param string        $languageCode  The language.
	 *
	 * @return INotification The rendered notification.
	 *
	 * @throws UnknownNotificationException When it is not a scheduled report notification.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		$subject = $notification->getSubject();
		if ($notification->getApp() !== Application::APP_ID
			|| in_array($subject, [ScheduledReportDelivery::SUBJECT_READY, ScheduledReportDelivery::SUBJECT_FAILED], true) === false
		) {
			throw new UnknownNotificationException();
		}

		$l          = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$parameters = $notification->getSubjectParameters();
		$name       = (string)($parameters['name'] ?? '');
		if ($subject === ScheduledReportDelivery::SUBJECT_READY) {
			$notification->setParsedSubject($l->t('%1$s for %2$s is ready', [$name, (string)($parameters['period'] ?? '')]));
			return $notification;
		}

		$notification->setParsedSubject($l->t('The scheduled report %1$s could not be produced', [$name]));
		$notification->setParsedMessage((string)($parameters['reason'] ?? ''));

		return $notification;

	}//end prepare()
}//end class
