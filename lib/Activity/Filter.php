<?php

/**
 * Shillinq Activity Filter
 *
 * Allows filtering Shillinq events in the Activity app.
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
use OCP\Activity\IFilter;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Activity filter for Shillinq events.
 *
 * @spec openspec/changes/core/tasks.md#task-9
 */
class Filter implements IFilter
{
    /**
     * Constructor for Filter.
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
     * Get the filter identifier.
     *
     * @return string
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function getIdentifier(): string
    {
        return Application::APP_ID;
    }//end getIdentifier()

    /**
     * Get the display name for this filter.
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
     * Get the priority of this filter.
     *
     * @return int
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function getPriority(): int
    {
        return 70;
    }//end getPriority()

    /**
     * Get the icon for this filter.
     *
     * @return string
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath(appName: Application::APP_ID, file: 'app-dark.svg');
    }//end getIcon()

    /**
     * Get the types of events this filter includes.
     *
     * @param array $types The current list of event types
     *
     * @return string[]
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function filterTypes(array $types): array
    {
        $types[] = 'shillinq_datajob';
        return $types;
    }//end filterTypes()

    /**
     * Get the apps that are relevant to this filter.
     *
     * @return string[]
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function allowedApps(): array
    {
        return [Application::APP_ID];
    }//end allowedApps()
}//end class
