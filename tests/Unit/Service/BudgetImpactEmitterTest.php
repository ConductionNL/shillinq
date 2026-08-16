<?php

/**
 * Unit tests for BudgetImpactEmitter.
 *
 * Verifies the emitter's two contracts (Task 5.2 / Task 5.3):
 *
 *  - `emitActivated()` dispatches one `shillinq.obligation.activated`
 *    event with the contractWaarde / kostenplaats / period / dossier URL
 *    payload (REQ-007).
 *  - `emitMilestoneCompleted()` dispatches one
 *    `shillinq.milestone.completed` event with the verplichtingId /
 *    mijlpaalId / approval marker / bewijsstuk count payload (REQ-005).
 *  - A dispatcher exception is swallowed (fail-soft).
 *  - An emittedAt ISO-8601 timestamp is always set.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
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

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetImpactEmitter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the BudgetImpactEmitter.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BudgetImpactEmitterTest extends TestCase {

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

			/**
			 * @param string $eventName Event name.
			 * @param Event $event Event payload.
			 *
			 * @return void
			 */
			public function dispatch(string $eventName, Event $event): void {
				$this->events[] = ['name' => $eventName, 'event' => $event];

			}//end dispatch()

			/**
			 * @param string $eventName Event name.
			 * @param Event $event Event payload.
			 *
			 * @return void
			 */
			public function dispatchTyped(Event $event): void {
				$this->events[] = ['name' => get_class($event), 'event' => $event];

			}//end dispatchTyped()

			/**
			 * @param string $eventName Event name.
			 * @param callable $listener Listener.
			 * @param int $priority Priority.
			 *
			 * @return void
			 */
			public function addListener(string $eventName, $listener, int $priority = 0): void {
				// No-op.

			}//end addListener()

			/**
			 * @param string $eventName Event name.
			 * @param string $serviceName Service name.
			 * @param int $priority Priority.
			 *
			 * @return void
			 */
			public function addServiceListener(string $eventName, string $serviceName, int $priority = 0): void {
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
	 * Build a throwing IEventDispatcher to exercise the fail-soft path.
	 *
	 * @return IEventDispatcher
	 */
	private function throwingDispatcher(): IEventDispatcher {
		return new class implements IEventDispatcher {
			public function dispatch(string $eventName, Event $event): void {
				throw new \RuntimeException('boom');
			}//end dispatch()

			public function dispatchTyped(Event $event): void {
				// No-op.

			}//end dispatchTyped()

			public function addListener(string $eventName, $listener, int $priority = 0): void {
				// No-op.

			}//end addListener()

			public function addServiceListener(string $eventName, string $serviceName, int $priority = 0): void {
				// No-op.

			}//end addServiceListener()

			public function hasListeners(string $eventName): bool {
				return false;
			}//end hasListeners()

			public function removeListener(string $eventName, callable $listener): void {
				// No-op.

			}//end removeListener()
		};

	}//end throwingDispatcher()

	/**
	 * `emitActivated()` dispatches a `shillinq.obligation.activated`
	 * event with the budget-impact payload (REQ-007).
	 *
	 * @return void
	 */
	public function testEmitActivatedShapesAndDispatchesPayload(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());

		$emitter->emitActivated(
			[
				'sourceReference' => 'TN-2026-0001',
				'amount' => 50000.0,
				'costCentre' => 'KP-100',
				'termStart' => '2026-01-01',
				'termEnd' => '2026-12-31',
				'administrationId' => 'adm-x',
			],
			['tenderNedUrl' => 'https://www.tenderned.nl/aankondigingen/overzicht/TN-2026-0001']
		);

		$this->assertCount(1, $dispatcher->events);
		$this->assertSame(BudgetImpactEmitter::EVENT_OBLIGATION_ACTIVATED, $dispatcher->events[0]['name']);

		/** @var array<string, mixed> $args */
		$args = $dispatcher->events[0]['event']->getArguments();
		$this->assertSame('TN-2026-0001', $args['sourceReference']);
		$this->assertSame(50000.0, $args['contractValue']);
		$this->assertSame('KP-100', $args['costCentre']);
		$this->assertSame(
			'https://www.tenderned.nl/aankondigingen/overzicht/TN-2026-0001',
			$args['tenderNedUrl']
		);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string)$args['emittedAt']);

	}//end testEmitActivatedShapesAndDispatchesPayload()

	/**
	 * `emitMilestoneCompleted()` dispatches a `shillinq.milestone.completed`
	 * event with the audit payload (REQ-005).
	 *
	 * @return void
	 */
	public function testEmitMilestoneCompletedShapesAndDispatchesPayload(): void {
		$dispatcher = $this->recordingDispatcher();
		$emitter = new BudgetImpactEmitter($dispatcher, new NullLogger());

		$emitter->emitMilestoneCompleted(
			[
				'commitmentId' => 'TN-TN-2026-0001',
				'milestoneId' => 'M-Q1',
				'deliveryType' => 'eindoplevering',
				'deliveryDate' => '2026-12-15',
				'approved' => true,
				'supportingDocuments' => [
					['documentId' => 'doc-1'],
					['documentId' => 'doc-2'],
				],
				'administrationId' => 'adm-x',
			]
		);

		$this->assertCount(1, $dispatcher->events);
		$this->assertSame(BudgetImpactEmitter::EVENT_MILESTONE_COMPLETED, $dispatcher->events[0]['name']);

		$args = $dispatcher->events[0]['event']->getArguments();
		$this->assertSame('TN-TN-2026-0001', $args['commitmentId']);
		$this->assertSame('M-Q1', $args['milestoneId']);
		$this->assertSame('eindoplevering', $args['deliveryType']);
		$this->assertTrue((bool)$args['approved']);
		$this->assertSame(2, $args['bewijsstukCount']);

	}//end testEmitMilestoneCompletedShapesAndDispatchesPayload()

	/**
	 * A dispatcher exception is swallowed (fail-soft contract).
	 *
	 * @return void
	 */
	public function testEmitActivatedSwallowsDispatcherException(): void {
		$emitter = new BudgetImpactEmitter($this->throwingDispatcher(), new NullLogger());

		// The test is "does not throw". If it raises, PHPUnit fails the test
		// — no need for assertNoException semantics in PHPUnit 10.
		$emitter->emitActivated(['sourceReference' => 'TN-X'], []);
		$this->addToAssertionCount(1);

	}//end testEmitActivatedSwallowsDispatcherException()

	/**
	 * A dispatcher exception is swallowed (fail-soft contract).
	 *
	 * @return void
	 */
	public function testEmitMilestoneCompletedSwallowsDispatcherException(): void {
		$emitter = new BudgetImpactEmitter($this->throwingDispatcher(), new NullLogger());

		$emitter->emitMilestoneCompleted(['commitmentId' => 'TN-X']);
		$this->addToAssertionCount(1);

	}//end testEmitMilestoneCompletedSwallowsDispatcherException()
}//end class
