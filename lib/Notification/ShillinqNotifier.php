<?php

/**
 * Shillinq Notifier
 *
 * Nextcloud notification handler for Shillinq DataJob events.
 *
 * @category Notification
 * @package  OCA\Shillinq\Notification
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/core/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Shillinq\Notification;

use OCA\Shillinq\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * Notifier that renders Shillinq notifications for display.
 *
 * @spec openspec/changes/core/tasks.md#task-9
 */
class ShillinqNotifier implements INotifier
{
    /**
     * Constructor for ShillinqNotifier.
     *
     * @param IL10N         $l            The localization service
     * @param IURLGenerator $urlGenerator The URL generator
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Get the identifier of this notifier.
     *
     * @return string
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function getID(): string
    {
        return Application::APP_ID;
    }//end getID()

    /**
     * Get the display name of this notifier.
     *
     * @return string
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function getName(): string
    {
        return $this->l->t('Shillinq');
    }//end getName()

    /**
     * Prepare a notification for display.
     *
     * @param INotification $notification The notification to prepare
     * @param string        $languageCode The target language
     *
     * @return INotification
     *
     * @throws \InvalidArgumentException When the notification is not for this app
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function prepare(
        INotification $notification,
        string $languageCode,
    ): INotification {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new \InvalidArgumentException('Notification not for Shillinq');
        }

        $params = $notification->getSubjectParameters();
        $subject = $notification->getSubject();
        $fileName = ($params['fileName'] ?? 'unknown');

        if ($subject === 'datajob_completed') {
            $processed = ($params['processedRecords'] ?? 0);
            $notification->setParsedSubject(
                $this->l->t('Import of %s completed: %d records imported', [$fileName, $processed])
            );
        } elseif ($subject === 'datajob_failed') {
            $failed = ($params['failedRecords'] ?? 0);
            $notification->setParsedSubject(
                $this->l->t('Import of %s failed: %d errors. View details.', [$fileName, $failed])
            );
        } else {
            throw new \InvalidArgumentException('Unknown notification subject: ' . $subject);
        }

        $link = $this->urlGenerator->linkToRouteAbsolute(
            routeName: Application::APP_ID . '.dashboard.page'
        ) . '/data-jobs/' . $notification->getObjectId();
        $notification->setLink($link);

        return $notification;
    }//end prepare()
}//end class
