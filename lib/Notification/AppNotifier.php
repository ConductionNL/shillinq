<?php

/**
 * App Notifier
 *
 * Base for the Shillinq notifiers that each render one notification
 * subject in the app's own notifier slot: the id is the app id, the name is
 * "Shillinq", and a notification of another app or subject is refused with
 * UnknownNotificationException so the next notifier can take it.
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

use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * The shared identity and ownership check of a one-subject Shillinq notifier.
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */
abstract class AppNotifier implements INotifier {

	/**
	 * The notification subject this notifier renders.
	 *
	 * @var string
	 */
	protected const SUBJECT = '';

	/**
	 * Construct the notifier.
	 *
	 * @param IFactory $l10nFactory The l10n factory for per-language rendering.
	 */
	public function __construct(
		protected readonly IFactory $l10nFactory,
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
		return AppIdentity::APP_ID;
	}//end getID()

	/**
	 * The human-readable notifier name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function getName(): string {
		return $this->l10nFactory->get(AppIdentity::APP_ID)->t('Shillinq');
	}//end getName()

	/**
	 * The translator for a notification this notifier owns.
	 *
	 * @param INotification $notification The raw notification.
	 * @param string        $languageCode The language to render in.
	 *
	 * @return IL10N
	 *
	 * @throws UnknownNotificationException When the notification is not ours.
	 */
	protected function ownedL10n(INotification $notification, string $languageCode): IL10N {
		if ($notification->getApp() !== AppIdentity::APP_ID
			|| $notification->getSubject() !== static::SUBJECT
		) {
			throw new UnknownNotificationException();
		}

		return $this->l10nFactory->get(AppIdentity::APP_ID, $languageCode);
	}//end ownedL10n()
}//end class
