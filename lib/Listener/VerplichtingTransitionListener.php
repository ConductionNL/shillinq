<?php

/**
 * Verplichting Transition Listener.
 *
 * Task 5.2 / REQ-007 — emit the cross-app `obligation.activated` CloudEvent
 * when a `bron: tenderned` Verplichting transitions to `active`, so the
 * launchpad budget-utilisation widget reflects the newly committed expense
 * within 60 seconds (REQ-007 scenario).
 *
 * Two activation paths converge here:
 *
 *  - The auto-promotion path (REQ-002), where
 *    `TenderNedAwardDetectedListener` writes a fresh Verplichting with
 *    `status: active` directly. OR fires `ObjectCreatedEvent` for that
 *    write, and this listener picks it up to emit the budget event.
 *  - The manual-import-and-enrich path (REQ-001 -> activeren), where an
 *    operator hand-enriches a concept Verplichting and transitions it
 *    through `activeren`. OR fires `ObjectTransitionedEvent` for that
 *    change, again handled here.
 *
 * Both shapes converge in `emitIfTenderNed()`, which guards on
 * `bron == tenderned` so non-TenderNed obligations (manual / inkoop-order
 * sourced) never reach the cross-app emitter — they have their own
 * budget pathway (out of scope for this change).
 *
 * Fail-soft: any unexpected exception is logged but never bubbles up to
 * the OR write path. The OR record itself is unaffected; only the
 * cross-app notification is dropped.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Service\BudgetImpactEmitter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cross-app `obligation.activated` emitter for TenderNed-sourced
 * Verplichting records (Task 5.2 / REQ-007).
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-5
 */
class VerplichtingTransitionListener implements IEventListener
{
    /**
     * Construct the listener.
     *
     * @param BudgetImpactEmitter $emitter Shared cross-app CloudEvent emitter.
     * @param LoggerInterface     $logger  Logger for fail-soft diagnostics.
     */
    public function __construct(
        private readonly BudgetImpactEmitter $emitter,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Handle an OR ObjectCreatedEvent or ObjectTransitionedEvent on the
     * Verplichting schema.
     *
     * @param Event $event OR object lifecycle event.
     *
     * @return void
     *
     * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-5
     */
    public function handle(Event $event): void
    {
        try {
            $payload = $this->extractActivatedVerplichting(event: $event);
            if ($payload === null) {
                return;
            }

            $this->emitIfTenderNed(verplichting: $payload);
        } catch (Throwable $e) {
            $this->logger->warning(
                'VerplichtingTransitionListener: emission failed — fail-soft',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end handle()

    /**
     * Pull the activated Verplichting payload from an OR event, or null
     * when the event is irrelevant.
     *
     * @param Event $event OR event.
     *
     * @return array<string, mixed>|null
     */
    private function extractActivatedVerplichting(Event $event): ?array
    {
        $entity = $this->resolveTargetEntity(event: $event);
        if ($entity === null) {
            return null;
        }

        $schema = (string) ($entity->getSchema() ?? '');
        if ($this->isVerplichtingSchema(schema: $schema) === false) {
            return null;
        }

        $payload = $entity->getObject();
        if (is_array($payload) === false) {
            return null;
        }

        // ObjectCreatedEvent fires for every fresh Verplichting; only emit
        // for those that are already in the active state (the REQ-002
        // auto-promotion path, design D4).
        if ($event instanceof ObjectCreatedEvent === true
            && (string) ($payload['status'] ?? '') !== 'active'
        ) {
            return null;
        }

        return $payload;

    }//end extractActivatedVerplichting()

    /**
     * Resolve the carrying entity for ObjectCreatedEvent or
     * ObjectTransitionedEvent-to-active, returning null when the event is
     * not a relevant lifecycle hook.
     *
     * @param Event $event OR event.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null
     */
    private function resolveTargetEntity(Event $event): ?\OCA\OpenRegister\Db\ObjectEntity
    {
        if ($event instanceof ObjectCreatedEvent === true) {
            return $event->getObject();
        }

        if ($event instanceof ObjectTransitionedEvent === true
            && $event->getTo() === 'active'
        ) {
            return $event->getObject();
        }

        return null;

    }//end resolveTargetEntity()

    /**
     * Emit the budget-impact event only for TenderNed-sourced obligations.
     *
     * @param array<string, mixed> $verplichting Verplichting payload.
     *
     * @return void
     */
    private function emitIfTenderNed(array $verplichting): void
    {
        if ((string) ($verplichting['bron'] ?? '') !== 'tenderned') {
            return;
        }

        $this->emitter->emitActivated(verplichting: $verplichting);

    }//end emitIfTenderNed()

    /**
     * Check whether the schema slug is Verplichting.
     *
     * @param string $schema Schema slug from the event.
     *
     * @return bool
     */
    private function isVerplichtingSchema(string $schema): bool
    {
        $normalised = strtolower(trim($schema));
        return ($normalised === 'verplichting'
            || str_ends_with(haystack: $normalised, needle: 'verplichting'));

    }//end isVerplichtingSchema()
}//end class
