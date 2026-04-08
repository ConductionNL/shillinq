<?php

/**
 * Shillinq DeepLinkRegistrationListener
 *
 * Registers Shillinq's deep link URL patterns with OpenRegister's search provider.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/core/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers Shillinq's deep link URL patterns with OpenRegister's search provider.
 *
 * When a user searches in Nextcloud's unified search, results for Shillinq schemas
 * will link directly to the relevant detail views in the app.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/core/tasks.md#task-1
 */
class DeepLinkRegistrationListener implements IEventListener
{
    /**
     * Handle the deep link registration event.
     *
     * @param Event $event The event to handle
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-1
     */
    public function handle(Event $event): void
    {
        if ($event instanceof DeepLinkRegistrationEvent === false) {
            return;
        }

        $event->register(
            appId: 'shillinq',
            registerSlug: 'shillinq',
            schemaSlug: 'organization',
            urlTemplate: '/apps/shillinq/organizations/{uuid}'
        );

        $event->register(
            appId: 'shillinq',
            registerSlug: 'shillinq',
            schemaSlug: 'appSettings',
            urlTemplate: '/apps/shillinq/settings/{uuid}'
        );

        $event->register(
            appId: 'shillinq',
            registerSlug: 'shillinq',
            schemaSlug: 'dashboard',
            urlTemplate: '/apps/shillinq/dashboard/{uuid}'
        );

        $event->register(
            appId: 'shillinq',
            registerSlug: 'shillinq',
            schemaSlug: 'dataJob',
            urlTemplate: '/apps/shillinq/data-jobs/{uuid}'
        );

    }//end handle()
}//end class
