<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Shillinq Activity Provider
 *
 * Provides activity events for Shillinq operations.
 *
 * @spec openspec/changes/core/tasks.md#task-9.2
 *
 * @category Activity
 * @package  OCA\Shillinq\Activity
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Activity;

use OCA\Shillinq\AppInfo\Application;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Activity provider for Shillinq events.
 *
 * @spec openspec/changes/core/tasks.md#task-9.2
 */
class ShillinqActivityProvider implements IProvider
{
    /**
     * Constructor.
     *
     * @param IL10N         $l10n         The localization service
     * @param IURLGenerator $urlGenerator The URL generator
     *
     * @return void
     */
    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Parse the activity event into a human-readable format.
     *
     * @param string $language The language to use for translation
     * @param IEvent $event    The activity event
     * @param IEvent $previous The previous event (for grouping)
     *
     * @spec openspec/changes/core/tasks.md#task-9.2
     *
     * @return IEvent
     */
    public function parse(string $language, IEvent $event, ?IEvent $previous=null): IEvent
    {
        if ($event->getApp() !== Application::APP_ID) {
            throw new \InvalidArgumentException('Not a Shillinq event');
        }

        $params  = $event->getSubjectParameters();
        $subject = $event->getSubject();

        if ($subject === 'shillinq_datajob') {
            $fileName = ($params['fileName'] ?? 'unknown');
            $status   = ($params['status'] ?? 'unknown');
            $event->setParsedSubject(
                $this->l10n->t(
                    'Data import "%s" %s',
                    [$fileName, $status]
                )
            );
            $event->setIcon(
                $this->urlGenerator->imagePath(
                    appName: Application::APP_ID,
                    file: 'app-dark.svg'
                )
            );
        }

        return $event;
    }//end parse()
}//end class
