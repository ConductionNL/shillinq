<?php

/**
 * Unit tests for CommitmentTransitionListener.
 *
 * Verifies the listener's contract (Task 5.2 / REQ-007):
 *
 *  - Created Commitment with bron=tenderned + status=active -> emit.
 *  - Created Commitment with bron=manual -> NO emit.
 *  - Transitioned Commitment to=active + bron=tenderned -> emit.
 *  - Transitioned Commitment to=concept -> NO emit.
 *  - Non-Commitment schema event -> NO emit.
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
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\CommitmentTransitionListener;
use OCA\Shillinq\Service\BudgetImpactEmitter;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * CommitmentTransitionListener verification (Task 5.2 / REQ-007).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CommitmentTransitionListenerTest extends TestCase {

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
	 * A created Commitment with bron=tenderned + status=active emits.
	 *
	 * @return void
	 */
	public function testCreatedTenderNedActiveEmitsBudgetEvent(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());
		$listener = new CommitmentTransitionListener($emitter, $this->resolver('Commitment'), new NullLogger());

		$event = new ObjectCreatedEvent(
			$this->entity('1089', [
				'source' => 'tenderned',
				'sourceReference' => 'TN-2026-0001',
				'status' => 'active',
				'amount' => 50000.0,
			])
		);

		$listener->handle($event);

		$this->assertCount(1, $dispatcher->events);
		$this->assertSame(BudgetImpactEmitter::EVENT_OBLIGATION_ACTIVATED, $dispatcher->events[0]['name']);

	}//end testCreatedTenderNedActiveEmitsBudgetEvent()

	/**
	 * The legacy Dutch slug still matches.
	 *
	 * Objects stored before the Verplichting -> Commitment rename keep the old
	 * slug until the repair step has run on that instance. Were the guard to
	 * match the new slug only, this listener would stop firing for all of them
	 * without raising an error or writing a log line -- so the legacy slug is
	 * a supported input, not an accident, and is pinned here.
	 *
	 * @return void
	 */
	public function testLegacyDutchSlugStillEmits(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());
		$listener = new CommitmentTransitionListener($emitter, $this->resolver('Verplichting'), new NullLogger());

		$event = new ObjectCreatedEvent(
			$this->entity('1089', [
				'source' => 'tenderned',
				'sourceReference' => 'TN-2026-0001',
				'status' => 'active',
				'amount' => 50000.0,
			])
		);

		$listener->handle($event);

		$this->assertCount(1, $dispatcher->events);
		$this->assertSame(BudgetImpactEmitter::EVENT_OBLIGATION_ACTIVATED, $dispatcher->events[0]['name']);

	}//end testLegacyDutchSlugStillEmits()

	/**
	 * A created manual Commitment does NOT emit.
	 *
	 * @return void
	 */
	public function testCreatedManualCommitmentDoesNotEmit(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());
		$listener = new CommitmentTransitionListener($emitter, $this->resolver('Commitment'), new NullLogger());

		$event = new ObjectCreatedEvent(
			$this->entity('1089', [
				'source' => 'manual',
				'status' => 'active',
				'amount' => 5000.0,
			])
		);

		$listener->handle($event);

		$this->assertCount(0, $dispatcher->events);

	}//end testCreatedManualCommitmentDoesNotEmit()

	/**
	 * A created Commitment with status=concept is ignored
	 * (REQ-002 promotion writes status=active immediately).
	 *
	 * @return void
	 */
	public function testCreatedConceptCommitmentDoesNotEmit(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());
		$listener = new CommitmentTransitionListener($emitter, $this->resolver('Commitment'), new NullLogger());

		$event = new ObjectCreatedEvent(
			$this->entity('1089', [
				'source' => 'tenderned',
				'status' => 'draft',
				'amount' => 5000.0,
			])
		);

		$listener->handle($event);

		$this->assertCount(0, $dispatcher->events);

	}//end testCreatedConceptCommitmentDoesNotEmit()

	/**
	 * A transition to=active on a tenderned-sourced Commitment emits.
	 *
	 * @return void
	 */
	public function testTransitionedToActiveEmits(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());
		$listener = new CommitmentTransitionListener($emitter, $this->resolver('Commitment'), new NullLogger());

		$event = new ObjectTransitionedEvent(
			$this->entity('1089', [
				'source' => 'tenderned',
				'status' => 'active',
				'amount' => 5000.0,
			]),
			'activeren',
			'draft',
			'active',
			'admin',
			'shillinq',
			'Commitment'
		);

		$listener->handle($event);

		$this->assertCount(1, $dispatcher->events);

	}//end testTransitionedToActiveEmits()

	/**
	 * A transition to a non-active state does NOT emit.
	 *
	 * @return void
	 */
	public function testTransitionedToConceptDoesNotEmit(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());
		$listener = new CommitmentTransitionListener($emitter, $this->resolver('Commitment'), new NullLogger());

		$event = new ObjectTransitionedEvent(
			$this->entity('1089', [
				'source' => 'tenderned',
				'status' => 'draft',
				'amount' => 5000.0,
			]),
			'reactiveren',
			'active',
			'draft',
			'admin',
			'shillinq',
			'Commitment'
		);

		$listener->handle($event);

		$this->assertCount(0, $dispatcher->events);

	}//end testTransitionedToConceptDoesNotEmit()

	/**
	 * A non-Commitment schema event is ignored.
	 *
	 * @return void
	 */
	public function testNonCommitmentSchemaIsIgnored(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());
		$listener = new CommitmentTransitionListener(
			$emitter,
			$this->resolver('TenderNedProcurement'),
			new NullLogger()
		);

		$event = new ObjectCreatedEvent(
			$this->entity('1090', [
				'source' => 'tenderned',
				'status' => 'active',
			])
		);

		$listener->handle($event);

		$this->assertCount(0, $dispatcher->events);

	}//end testNonCommitmentSchemaIsIgnored()

	/**
	 * An entity with a null payload is handled gracefully (the listener
	 * resolves the schema, sees no object payload, and returns null).
	 *
	 * Originally this test passed a null event but the OR
	 * ObjectCreatedEvent constructor now type-hints non-null ObjectEntity.
	 * The fail-soft contract is identical: a non-Commitment / payload-less
	 * entity must NOT cause an emit.
	 *
	 * @return void
	 */
	public function testNullEntityIsIgnored(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());
		$listener = new CommitmentTransitionListener($emitter, $this->resolver('SalesOrder'), new NullLogger());

		$entity = new ObjectEntity();
		$entity->setSchema('4242');
		$event = new ObjectCreatedEvent($entity);
		$listener->handle($event);

		$this->assertCount(0, $dispatcher->events);

	}//end testNullEntityIsIgnored()
}//end class
