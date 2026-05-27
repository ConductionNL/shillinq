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
 * @spec openspec/changes/add-shillinq-chart-of-accounts/specs/bookkeeping-chart-of-accounts/spec.md
 */
class DeepLinkRegistrationListener implements IEventListener
{
    /**
     * Handle the deep link registration event.
     *
     * Registers deep links for real Shillinq schemas. The `account` schema is
     * the only schema present in T1. Additional schemas (invoice, payment, etc.)
     * should be added here once their Vue detail routes are implemented.
     *
     * @param Event $event The event to handle
     *
     * @return void
     *
     * @spec openspec/changes/add-shillinq-chart-of-accounts/specs/bookkeeping-chart-of-accounts/spec.md
     */
    public function handle(Event $event): void
    {
        if ($event instanceof DeepLinkRegistrationEvent === false) {
            return;
        }

        // Register deep link for the Account schema (T1 schema).
        $event->register(
            appId: 'shillinq',
            registerSlug: 'shillinq',
            schemaSlug: 'account',
            urlTemplate: '/apps/shillinq/#/accounts/{uuid}'
        );

    }//end handle()
}//end class
