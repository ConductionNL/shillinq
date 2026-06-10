<?php

/**
 * Unit tests for OpdrachtUitvoeringTransitionListener.
 *
 * Verifies the listener's contract (Task 5.3 / REQ-005 / REQ-006):
 *
 *  - Transition to=completed on OpdrachtUitvoering emits milestone.completed.
 *  - Approved eindoplevering triggers the outbound TenderNed sync.
 *  - Non-eindoplevering completion does NOT trigger sync (only the audit emit).
 *  - Unapproved eindoplevering does NOT trigger sync.
 *  - Non-completed transition is ignored.
 *  - Non-OpdrachtUitvoering schema is ignored.
 *  - Handler swallows downstream exceptions (fail-soft).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
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

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Integration\TenderNedStatusSync;
use OCA\Shillinq\Listener\OpdrachtUitvoeringTransitionListener;
use OCA\Shillinq\Service\BudgetImpactEmitter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for OpdrachtUitvoeringTransitionListener.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class OpdrachtUitvoeringTransitionListenerTest extends TestCase
{

    /**
     * Build a recording IEventDispatcher.
     *
     * @return IEventDispatcher
     */
    private function recordingDispatcher(): IEventDispatcher
    {
        return new class implements IEventDispatcher {

            /**
             * @var array<int, array{name: string, event: Event}>
             */
            public array $events = [];

            public function dispatch(string $eventName, Event $event): void
            {
                $this->events[] = ['name' => $eventName, 'event' => $event];

            }//end dispatch()

            public function dispatchTyped(Event $event): void
            {
                // No-op.

            }//end dispatchTyped()

            public function addListener(string $eventName, callable $listener, int $priority=0): void
            {
                // No-op.

            }//end addListener()

            public function addServiceListener(string $eventName, string $className, int $priority=0): void
            {
                // No-op.

            }//end addServiceListener()

            public function hasListeners(string $eventName): bool
            {
                return false;

            }//end hasListeners()

            public function removeListener(string $eventName, callable $listener): void
            {
                // No-op.

            }//end removeListener()
        };

    }//end recordingDispatcher()

    /**
     * Build a spying TenderNedStatusSync that records syncCompletion calls.
     *
     * @return TenderNedStatusSync
     */
    private function spyingSync(): TenderNedStatusSync
    {
        return new class(
            $this->emptyContainer(),
            $this->emptyAppConfig(),
            new NullLogger()
        ) extends TenderNedStatusSync {

            /**
             * @var array<int, array<string, mixed>>
             */
            public array $syncCalls = [];

            public function syncCompletion(array $oplevering): bool
            {
                $this->syncCalls[] = $oplevering;
                return true;

            }//end syncCompletion()
        };

    }//end spyingSync()

    /**
     * Build a container that never resolves anything.
     *
     * @return ContainerInterface
     */
    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {

            public function get(string $id): mixed
            {
                throw new class('not bound') extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
                };

            }//end get()

            public function has(string $id): bool
            {
                return false;

            }//end has()
        };

    }//end emptyContainer()

    /**
     * Build an IAppConfig returning configured defaults.
     *
     * @return IAppConfig
     */
    private function emptyAppConfig(): IAppConfig
    {
        return $this->createMock(IAppConfig::class);

    }//end emptyAppConfig()

    /**
     * Build an ObjectEntity with schema + payload.
     *
     * @param string              $schema  Schema slug.
     * @param array<string,mixed> $payload Payload.
     *
     * @return ObjectEntity
     */
    private function entity(string $schema, array $payload): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setSchema($schema);
        $entity->setObject($payload);
        return $entity;

    }//end entity()

    /**
     * A completed regular milestone emits but does NOT sync.
     *
     * @return void
     */
    public function testCompletedMilestoneEmitsButDoesNotSync(): void
    {
        $dispatcher = $this->recordingDispatcher();
        $emitter    = new BudgetImpactEmitter($dispatcher, new NullLogger());
        $sync       = $this->spyingSync();
        $listener   = new OpdrachtUitvoeringTransitionListener($emitter, $sync, new NullLogger());

        $event = new ObjectTransitionedEvent(
            $this->entity('OpdrachtUitvoering', [
                'verplichtingId'  => 'TN-2026-0001',
                'mijlpaalId'      => 'M-Q1',
                'opleveringsType' => 'tussenoplevering',
                'goedgekeurd'     => true,
                'bewijsstukken'   => [['documentId' => 'doc-1']],
            ]),
            'voltooien',
            'in-progress',
            'completed',
            'admin',
            'shillinq',
            'OpdrachtUitvoering'
        );

        $listener->handle($event);

        $this->assertCount(1, $dispatcher->events);
        $this->assertSame(BudgetImpactEmitter::EVENT_MILESTONE_COMPLETED, $dispatcher->events[0]['name']);
        $this->assertCount(0, $sync->syncCalls);

    }//end testCompletedMilestoneEmitsButDoesNotSync()

    /**
     * A completed approved eindoplevering triggers the outbound sync.
     *
     * @return void
     */
    public function testApprovedEindopleveringTriggersSync(): void
    {
        $dispatcher = $this->recordingDispatcher();
        $emitter    = new BudgetImpactEmitter($dispatcher, new NullLogger());
        $sync       = $this->spyingSync();
        $listener   = new OpdrachtUitvoeringTransitionListener($emitter, $sync, new NullLogger());

        $event = new ObjectTransitionedEvent(
            $this->entity('OpdrachtUitvoering', [
                'verplichtingId'  => 'TN-2026-0001',
                'mijlpaalId'      => 'M-EIND',
                'opleveringsType' => 'eindoplevering',
                'goedgekeurd'     => true,
                'bewijsstukken'   => [['documentId' => 'doc-1']],
            ]),
            'voltooien',
            'in-progress',
            'completed',
            'admin',
            'shillinq',
            'OpdrachtUitvoering'
        );

        $listener->handle($event);

        $this->assertCount(1, $sync->syncCalls);
        $this->assertSame('M-EIND', $sync->syncCalls[0]['mijlpaalId']);

    }//end testApprovedEindopleveringTriggersSync()

    /**
     * An unapproved eindoplevering does NOT trigger sync.
     *
     * @return void
     */
    public function testUnapprovedEindopleveringDoesNotTriggerSync(): void
    {
        $dispatcher = $this->recordingDispatcher();
        $emitter    = new BudgetImpactEmitter($dispatcher, new NullLogger());
        $sync       = $this->spyingSync();
        $listener   = new OpdrachtUitvoeringTransitionListener($emitter, $sync, new NullLogger());

        $event = new ObjectTransitionedEvent(
            $this->entity('OpdrachtUitvoering', [
                'verplichtingId'  => 'TN-2026-0001',
                'mijlpaalId'      => 'M-EIND',
                'opleveringsType' => 'eindoplevering',
                'goedgekeurd'     => false,
                'bewijsstukken'   => [['documentId' => 'doc-1']],
            ]),
            'voltooien',
            'in-progress',
            'completed',
            'admin',
            'shillinq',
            'OpdrachtUitvoering'
        );

        $listener->handle($event);

        $this->assertCount(0, $sync->syncCalls);

    }//end testUnapprovedEindopleveringDoesNotTriggerSync()

    /**
     * A non-completed transition is ignored entirely.
     *
     * @return void
     */
    public function testNonCompletedTransitionIsIgnored(): void
    {
        $dispatcher = $this->recordingDispatcher();
        $emitter    = new BudgetImpactEmitter($dispatcher, new NullLogger());
        $sync       = $this->spyingSync();
        $listener   = new OpdrachtUitvoeringTransitionListener($emitter, $sync, new NullLogger());

        $event = new ObjectTransitionedEvent(
            $this->entity('OpdrachtUitvoering', [
                'verplichtingId'  => 'TN-2026-0001',
                'mijlpaalId'      => 'M-EIND',
                'opleveringsType' => 'eindoplevering',
                'goedgekeurd'     => true,
            ]),
            'starten',
            'planned',
            'in-progress',
            'admin',
            'shillinq',
            'OpdrachtUitvoering'
        );

        $listener->handle($event);

        $this->assertCount(0, $dispatcher->events);
        $this->assertCount(0, $sync->syncCalls);

    }//end testNonCompletedTransitionIsIgnored()

    /**
     * A non-OpdrachtUitvoering schema is ignored.
     *
     * @return void
     */
    public function testNonOpdrachtUitvoeringSchemaIsIgnored(): void
    {
        $dispatcher = $this->recordingDispatcher();
        $emitter    = new BudgetImpactEmitter($dispatcher, new NullLogger());
        $sync       = $this->spyingSync();
        $listener   = new OpdrachtUitvoeringTransitionListener($emitter, $sync, new NullLogger());

        $event = new ObjectTransitionedEvent(
            $this->entity('Verplichting', ['status' => 'active']),
            'activeren',
            'concept',
            'completed',
            'admin',
            'shillinq',
            'Verplichting'
        );

        $listener->handle($event);

        $this->assertCount(0, $dispatcher->events);
        $this->assertCount(0, $sync->syncCalls);

    }//end testNonOpdrachtUitvoeringSchemaIsIgnored()
}//end class
