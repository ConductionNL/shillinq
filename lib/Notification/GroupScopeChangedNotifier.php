<?php

/**
 * Shillinq Group Scope Changed Notifier
 *
 * Renders the notification the weekly scope assessment sends when a group
 * comes into or leaves scope for the country-by-country report or the
 * minimum tax (REQ-CBC-001).
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
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Notification;

use OCA\Shillinq\BackgroundJob\GroupScopeAssessmentJob;
use OCP\Notification\INotification;

/**
 * Renders the scope-changed notification.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.2
 */
class GroupScopeChangedNotifier extends AppNotifier {
	/**
	 * The subject this notifier renders.
	 *
	 * @var string
	 */
	protected const SUBJECT = GroupScopeAssessmentJob::SUBJECT;

	/**
	 * Render the notification.
	 *
	 * @param INotification $notification The notification.
	 * @param string        $languageCode The user's language.
	 *
	 * @return INotification
	 *
	 * @throws \OCP\Notification\UnknownNotificationException When it is not this notifier's.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.2
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		$l          = $this->ownedL10n(notification: $notification, languageCode: $languageCode);
		$parameters = $notification->getSubjectParameters();
		$group      = (string)($parameters['group'] ?? '');
		$year       = (string)($parameters['year'] ?? '');

		$notification->setParsedSubject($l->t('The tax scope of %1$s changed for %2$s', [$group, $year]));
		$notification->setParsedMessage(
			self::pick(
				inScope: (bool)($parameters['cbcrInScope'] ?? false),
				inLine: $l->t('A country-by-country report is due.'),
				outLine: $l->t('No country-by-country report is due.')
			)
			. ' '
			. self::pick(
				inScope: (bool)($parameters['pillar2InScope'] ?? false),
				inLine: $l->t('The minimum tax applies.'),
				outLine: $l->t('The minimum tax does not apply.')
			)
		);

		return $notification;
	}//end prepare()

	/**
	 * The line for one scope.
	 *
	 * @param bool   $inScope Whether the scope applies.
	 * @param string $inLine  The translated line when it applies.
	 * @param string $outLine The translated line when it does not.
	 *
	 * @return string
	 */
	private static function pick(bool $inScope, string $inLine, string $outLine): string {
		if ($inScope === true) {
			return $inLine;
		}

		return $outLine;
	}//end pick()
}//end class
