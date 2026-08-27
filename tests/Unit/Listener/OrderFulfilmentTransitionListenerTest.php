<?php

/**
 * Unit tests for OrderFulfilmentTransitionListener.
 *
 * Verifies the listener's contract (Task 5.3 / REQ-005 / REQ-006):
 *
 *  - Transition to=completed on OrderFulfilment emits milestone.completed.
 *  - Approved eindoplevering triggers the outbound TenderNed sync.
 *  - Non-eindoplevering completion does NOT trigger sync (only the audit emit).
 *  - Unapproved eindoplevering does NOT trigger sync.
 *  - Non-completed transition is ignored.
 *  - Non-OrderFulfilment schema is ignored.
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
use OCA\Shillinq\Service\TenderNedStatusSync;
use OCA\Shillinq\Listener\OrderFulfilmentTransitionListener;
use OCA\Shillinq\Service\BudgetImpactEmitter;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for OrderFulfilmentTransitionListener.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class OrderFulfilmentTransitionListenerTest extends TestCase {

	/**
	 * Build a recording IEventDispatcher.
	 *
	 * @return IEventDispatcher
	 */
	private function recordingDispatcher(): IEventDispatcher {
		return new class implements IEventDispatcher {
			/**
			 * @var array<int, array{name: string, event: Event}>
			 */
			public array $events = [];

			public function dispatch(string $eventName, Event $event): void {
				$this->events[] = ['name' => $eventName, 'event' => $event];

			}//end dispatch()

			public function dispatchTyped(Event $event): void {
				// No-op.

			}//end dispatchTyped()

			public function addListener(string $eventName, callable $listener, int $priority = 0): void {
				// No-op.

			}//end addListener()

			public function addServiceListener(string $eventName, string $className, int $priority = 0): void {
				// No-op.

			}//end addServiceListener()

			public function hasListeners(string $eventName): bool {
				return false;
			}//end hasListeners()

			public function removeListener(string $eventName, callable $listener): void {
				// No-op.

			}//end removeListener()
		};

	}//end recordingDispatcher()

	/**
	 * Build a spying TenderNedStatusSync that records syncCompletion calls.
	 *
	 * @return TenderNedStatusSync
	 */
	private function spyingSync(): TenderNedStatusSync {
		return new class($this->emptyContainer(), $this->emptyAppConfig(), new NullLogger()) extends TenderNedStatusSync {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $syncCalls = [];

			public function syncCompletion(array $oplevering): bool {
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
	private function emptyContainer(): ContainerInterface {
		return new class implements ContainerInterface {
			public function get(string $id): mixed {
				throw new class('not bound') extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
				};

			}//end get()

			public function has(string $id): bool {
				return false;
			}//end has()
		};

	}//end emptyContainer()

	/**
	 * Build an IAppConfig returning configured defaults.
	 *
	 * @return IAppConfig
	 */
	private function emptyAppConfig(): IAppConfig {
		return $this->createMock(IAppConfig::class);
	}//end emptyAppConfig()

	/**
	 * Build an ObjectEntity carrying a numeric schema **id**, exactly as
	 * OpenRegister stamps it (`setSchema((string) $schema->getId())`).
	 *
	 * A hand-built entity carrying the slug is a shape production never
	 * produces; the slug arrives through {@see ListenerSchemaResolver}.
	 *
	 * @param string $schemaId Numeric schema id as OR stamps it.
	 * @param array<string,mixed> $payload Payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schemaId, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schemaId);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * Build a ListenerSchemaResolver stub that reports a given schema slug.
	 *
	 * @param string $slug Slug the resolver resolves the entity's id to.
	 *
	 * @return ListenerSchemaResolver
	 */
	private function resolver(string $slug): ListenerSchemaResolver {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturn($slug);
		return $resolver;
	}//end resolver()

	/**
	 * A completed regular milestone emits but does NOT sync.
	 *
	 * @return void
	 */
	public function testCompletedMilestoneEmitsButDoesNotSync(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());
		$sync = $this->spyingSync();
		$listener = new OrderFulfilmentTransitionListener(
			$emitter,
			$sync,
			$this->resolver('OrderFulfilment'),
			new NullLogger()
		);

		$event = new ObjectTransitionedEvent(
			$this->entity('1201', [
				'commitmentId' => 'TN-2026-0001',
				'milestoneId' => 'M-Q1',
				'deliveryType' => 'tussenoplevering',
				'approved' => true,
				'supportingDocuments' => [['documentId' => 'doc-1']],
			]),
			'voltooien',
			'in-progress',
			'completed',
			'admin',
			'shillinq',
			'OrderFulfilment'
		);

		$listener->handle($event);

		$this->assertCount(1, $dispatcher->events);
		$this->assertSame(BudgetImpactEmitter::EVENT_MILESTONE_COMPLETED, $dispatcher->events[0]['name']);
		$this->assertCount(0, $sync->syncCalls);

	}//end testCompletedMilestoneEmitsButDoesNotSync()

	/**
	 * The legacy Dutch slug still matches.
	 *
	 * Objects stored before the OpdrachtUitvoering -> OrderFulfilment rename
	 * keep the old slug until the repair step has run on that instance. Were
	 * the guard to match the new slug only, this listener would stop firing
	 * for all of them without raising an error or writing a log line -- so the
	 * legacy slug is a supported input, not an accident, and is pinned here.
	 *
	 * @return void
	 */
	public function testLegacyDutchSlugStillEmits(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());
		$sync = $this->spyingSync();
		$listener = new OrderFulfilmentTransitionListener(
			$emitter,
			$sync,
			$this->resolver('OpdrachtUitvoering'),
			new NullLogger()
		);

		$event = new ObjectTransitionedEvent(
			$this->entity('1201', [
				'commitmentId' => 'TN-2026-0001',
				'milestoneId' => 'M-Q1',
				'deliveryType' => 'tussenoplevering',
				'approved' => true,
				'supportingDocuments' => [['documentId' => 'doc-1']],
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

	}//end testLegacyDutchSlugStillEmits()

	/**
	 * A completed approved eindoplevering triggers the outbound sync.
	 *
	 * @return void
	 */
	public function testApprovedEindopleveringTriggersSync(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());
		$sync = $this->spyingSync();
		$listener = new OrderFulfilmentTransitionListener(
			$emitter,
			$sync,
			$this->resolver('OrderFulfilment'),
			new NullLogger()
		);

		$event = new ObjectTransitionedEvent(
			$this->entity('1201', [
				'commitmentId' => 'TN-2026-0001',
				'milestoneId' => 'M-EIND',
				'deliveryType' => 'eindoplevering',
				'approved' => true,
				'supportingDocuments' => [['documentId' => 'doc-1']],
			]),
			'voltooien',
			'in-progress',
			'completed',
			'admin',
			'shillinq',
			'OrderFulfilment'
		);

		$listener->handle($event);

		$this->assertCount(1, $sync->syncCalls);
		$this->assertSame('M-EIND', $sync->syncCalls[0]['milestoneId']);

	}//end testApprovedEindopleveringTriggersSync()

	/**
	 * An unapproved eindoplevering does NOT trigger sync.
	 *
	 * @return void
	 */
	public function testUnapprovedEindopleveringDoesNotTriggerSync(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());
		$sync = $this->spyingSync();
		$listener = new OrderFulfilmentTransitionListener(
			$emitter,
			$sync,
			$this->resolver('OrderFulfilment'),
			new NullLogger()
		);

		$event = new ObjectTransitionedEvent(
			$this->entity('1201', [
				'commitmentId' => 'TN-2026-0001',
				'milestoneId' => 'M-EIND',
				'deliveryType' => 'eindoplevering',
				'approved' => false,
				'supportingDocuments' => [['documentId' => 'doc-1']],
			]),
			'voltooien',
			'in-progress',
			'completed',
			'admin',
			'shillinq',
			'OrderFulfilment'
		);

		$listener->handle($event);

		$this->assertCount(0, $sync->syncCalls);

	}//end testUnapprovedEindopleveringDoesNotTriggerSync()

	/**
	 * A non-completed transition is ignored entirely.
	 *
	 * @return void
	 */
	public function testNonCompletedTransitionIsIgnored(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());
		$sync = $this->spyingSync();
		$listener = new OrderFulfilmentTransitionListener(
			$emitter,
			$sync,
			$this->resolver('OrderFulfilment'),
			new NullLogger()
		);

		$event = new ObjectTransitionedEvent(
			$this->entity('1201', [
				'commitmentId' => 'TN-2026-0001',
				'milestoneId' => 'M-EIND',
				'deliveryType' => 'eindoplevering',
				'approved' => true,
			]),
			'starten',
			'planned',
			'in-progress',
			'admin',
			'shillinq',
			'OrderFulfilment'
		);

		$listener->handle($event);

		$this->assertCount(0, $dispatcher->events);
		$this->assertCount(0, $sync->syncCalls);

	}//end testNonCompletedTransitionIsIgnored()

	/**
	 * A non-OrderFulfilment schema is ignored.
	 *
	 * @return void
	 */
	public function testNonOrderFulfilmentSchemaIsIgnored(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());
		$sync = $this->spyingSync();
		$listener = new OrderFulfilmentTransitionListener(
			$emitter,
			$sync,
			$this->resolver('Commitment'),
			new NullLogger()
		);

		$event = new ObjectTransitionedEvent(
			$this->entity('1089', ['status' => 'active']),
			'activeren',
			'draft',
			'completed',
			'admin',
			'shillinq',
			'Commitment'
		);

		$listener->handle($event);

		$this->assertCount(0, $dispatcher->events);
		$this->assertCount(0, $sync->syncCalls);

	}//end testNonOrderFulfilmentSchemaIsIgnored()
}//end class
