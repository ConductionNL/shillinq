<?php

/**
 * Shillinq Activity Provider
 *
 * Provides activity events for Shillinq DataJob operations.
 *
 * @category Activity
 * @package  OCA\Shillinq\Activity
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

namespace OCA\Shillinq\Activity;

use OCA\Shillinq\AppInfo\Application;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Activity provider that renders Shillinq DataJob events.
 *
 * @spec openspec/changes/core/tasks.md#task-9
 */
class ShillinqActivityProvider implements IProvider
{
    /**
     * Constructor for ShillinqActivityProvider.
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
     * Parse the given activity event into a human-readable form.
     *
     * @param string $language      The target language
     * @param IEvent $event         The event to parse
     * @param IEvent $previousEvent The previous event for aggregation (nullable)
     *
     * @return IEvent
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function parse(
        string $language,
        IEvent $event,
        ?IEvent $previousEvent=null,
    ): IEvent {
        if ($event->getApp() !== Application::APP_ID) {
            throw new \InvalidArgumentException('Event not for Shillinq');
        }

        $params   = $event->getSubjectParameters();
        $fileName = ($params['fileName'] ?? 'unknown');

        if ($event->getSubject() === 'datajob_completed') {
            $processed = ($params['processedRecords'] ?? 0);
            $event->setParsedSubject(
                $this->l->t('Import of %s completed with %d records', [$fileName, $processed])
            );
        } else if ($event->getSubject() === 'datajob_failed') {
            $failed = ($params['failedRecords'] ?? 0);
            $event->setParsedSubject(
                $this->l->t('Import of %s failed with %d errors', [$fileName, $failed])
            );
        }

        $event->setIcon(
            $this->urlGenerator->imagePath(appName: Application::APP_ID, file: 'app-dark.svg')
        );

        return $event;
    }//end parse()
}//end class
