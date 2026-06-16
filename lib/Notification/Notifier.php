<?php

/**
 * Shillinq Notifier
 *
 * Prepares Shillinq in-app notifications for display. Currently handles the
 * `subsidie_verantwoording_overdue` subject fired by OverdueVerantwoordingJob
 * (REQ-SUBV-010) when an accountability report has not been finalised within
 * 90 days of grant award.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Notification;

use OCA\Shillinq\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Notifier for Shillinq notifications (REQ-SUBV-010).
 *
 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
 */
class Notifier implements INotifier
{
    /**
     * Constructor.
     *
     * @param IFactory      $l10nFactory  The l10n factory.
     * @param IURLGenerator $urlGenerator The URL generator.
     */
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Get the notifier ID.
     *
     * @return string The notifier ID.
     *
     * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
     */
    public function getID(): string
    {
        return Application::APP_ID;
    }//end getID()

    /**
     * Get the notifier display name.
     *
     * @return string The notifier name.
     *
     * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
     */
    public function getName(): string
    {
        return $this->l10nFactory->get(Application::APP_ID)->t('Shillinq');
    }//end getName()

    /**
     * Prepare a notification for display.
     *
     * @param INotification $notification The notification to prepare.
     * @param string        $languageCode The language code.
     *
     * @return INotification The prepared notification.
     *
     * @throws UnknownNotificationException When the notification is not a Shillinq subject.
     *
     * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
     */
    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new UnknownNotificationException();
        }

        $l      = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $params = $notification->getSubjectParameters();

        if ($notification->getSubject() !== 'subsidie_verantwoording_overdue') {
            throw new UnknownNotificationException();
        }

        $grantId = (string) ($params['grantId'] ?? $notification->getObjectId());
        $notification->setParsedSubject(
            $l->t('Accountability report for %s overdue — please submit or approve', [$grantId])
        );
        $notification->setRichSubject(
            $l->t('Accountability report for {grant} overdue — please submit or approve'),
            ['grant' => ['type' => 'highlight', 'id' => $grantId, 'name' => $grantId]]
        );

        $notification->setIcon(
            $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->imagePath(appName: Application::APP_ID, file: 'app-dark.svg')
            )
        );

        $baseUrl = $this->urlGenerator->linkToRouteAbsolute('shillinq.dashboard.page');
        $notification->setLink($baseUrl.'#/subsidies/accountability-reports');

        return $notification;
    }//end prepare()
}//end class
