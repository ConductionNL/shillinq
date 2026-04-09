<?php

/**
 * Shillinq Activity Filter
 *
 * Allows filtering Shillinq events in the Activity app.
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
use OCP\Activity\IFilter;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Activity filter for Shillinq events.
 *
 * @spec openspec/changes/core/tasks.md#task-9.2
 */
class Filter implements IFilter
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
     * Get the unique identifier of this filter.
     *
     * @return string
     */
    public function getIdentifier(): string
    {
        return Application::APP_ID;
    }//end getIdentifier()

    /**
     * Get the display name of this filter.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->l10n->t('Shillinq');
    }//end getName()

    /**
     * Get the icon path for this filter.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath(
            appName: Application::APP_ID,
            file: 'app-dark.svg'
        );
    }//end getIcon()

    /**
     * Get the priority of this filter.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return 70;
    }//end getPriority()

    /**
     * Get the list of allowed apps for this filter.
     *
     * @return string[]
     */
    public function allowedApps(): array
    {
        return [Application::APP_ID];
    }//end allowedApps()

    /**
     * Filter events by type.
     *
     * @param array $types The event types
     *
     * @return string[]
     */
    public function filterTypes(array $types): array
    {
        return $types;
    }//end filterTypes()
}//end class
