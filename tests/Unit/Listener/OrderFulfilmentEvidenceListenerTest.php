<?php

/**
 * Tests the REQ-004 bewijsstuk write-path gate.
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
 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Lifecycle\OrderFulfilmentGuard;
use OCA\Shillinq\Listener\OrderFulfilmentEvidenceListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-004: an OrderFulfilment may only carry `status: completed` when at
 * least one bewijsstuk with a documentId is attached — on the PLAIN WRITE path,
 * not only on the lifecycle transition endpoint.
 *
 * Measured on a live Nextcloud 32 + OpenRegister instance before the fix:
 * `POST /apps/openregister/api/objects/shillinq/OrderFulfilment` with
 * `{"status":"completed","supportingDocuments":[]}` returned **201 Created** and
 * persisted the terminal state. After the fix the same request returns 422,
 * and the same request WITH a valid bewijsstuk still returns 201.
 *
 * Both directions are asserted here, plus the two ways an over-eager guard
 * would do damage: blocking a non-completed write, and firing on another
 * schema. A guard that refuses everything is not a fix.
 *
 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
 */
final class OrderFulfilmentEvidenceListenerTest extends TestCase {

	/**
	 * A completion without a bewijsstuk is vetoed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
	 */
	public function testCompletionWithoutBewijsstukIsVetoed(): void {
		$event = $this->creatingEvent(
			[
				'commitmentId' => 'V-1',
				'milestoneId' => 'MS-1',
				'status' => 'completed',
				'supportingDocuments' => [],
			]
		);

		$this->listener(matches: true)->handle($event);

		self::assertTrue($event->isPropagationStopped(), 'The write must be refused (REQ-004).');
		self::assertSame('supportingDocuments', $event->getErrors()['field'] ?? null);
		self::assertSame(
			OrderFulfilmentEvidenceListener::DENY_MESSAGE,
			$event->getErrors()['message'] ?? null
		);

	}//end testCompletionWithoutBewijsstukIsVetoed()

	/**
	 * A completion WITH a valid bewijsstuk still goes through.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
	 */
	public function testCompletionWithAValidBewijsstukIsAllowed(): void {
		$event = $this->creatingEvent(
			[
				'commitmentId' => 'V-1',
				'milestoneId' => 'MS-1',
				'status' => 'completed',
				'supportingDocuments' => [['app' => 'docudesk', 'documentId' => 'doc-1']],
			]
		);

		$this->listener(matches: true)->handle($event);

		self::assertFalse($event->isPropagationStopped(), 'A proven completion must not be blocked.');

	}//end testCompletionWithAValidBewijsstukIsAllowed()

	/**
	 * The UPDATE path is gated too — a PUT into `completed` is not a loophole.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
	 */
	public function testUpdateIntoCompletedWithoutBewijsstukIsVetoed(): void {
		$event = $this->updatingEvent(
			[
				'commitmentId' => 'V-1',
				'status' => 'completed',
				'supportingDocuments' => [],
			]
		);

		$this->listener(matches: true)->handle($event);

		self::assertTrue($event->isPropagationStopped(), 'A PUT into completed must be refused too.');

	}//end testUpdateIntoCompletedWithoutBewijsstukIsVetoed()

	/**
	 * A bewijsstuk without a documentId does not satisfy the gate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
	 */
	public function testEmptyPlaceholderBewijsstukDoesNotSatisfyTheGate(): void {
		$event = $this->creatingEvent(
			[
				'status' => 'completed',
				'supportingDocuments' => [['app' => 'docudesk', 'documentId' => '   ']],
			]
		);

		$this->listener(matches: true)->handle($event);

		self::assertTrue($event->isPropagationStopped(), 'A blank documentId is not proof of delivery.');

	}//end testEmptyPlaceholderBewijsstukDoesNotSatisfyTheGate()

	/**
	 * A non-completed write is never gated, with or without bewijsstukken.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
	 */
	public function testInProgressWriteIsNotGated(): void {
		$event = $this->creatingEvent(
			[
				'commitmentId' => 'V-1',
				'status' => 'in-progress',
				'supportingDocuments' => [],
			]
		);

		$this->listener(matches: true)->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testInProgressWriteIsNotGated()

	/**
	 * Another schema's write is untouched even when it looks completed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
	 */
	public function testOtherSchemasAreUntouched(): void {
		$event = $this->creatingEvent(['status' => 'completed', 'supportingDocuments' => []]);

		$this->listener(matches: false)->handle($event);

		self::assertFalse($event->isPropagationStopped(), 'The guard must be scoped to OrderFulfilment.');

	}//end testOtherSchemasAreUntouched()

	/**
	 * Build the listener with a schema resolver that does or does not match.
	 *
	 * @param bool $matches Whether the entity is an OrderFulfilment.
	 *
	 * @return OrderFulfilmentEvidenceListener
	 */
	private function listener(bool $matches): OrderFulfilmentEvidenceListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturn($matches);

		return new OrderFulfilmentEvidenceListener(
			guard: new OrderFulfilmentGuard(logger: new NullLogger()),
			schemaResolver: $resolver,
			logger: new NullLogger(),
		);

	}//end listener()

	/**
	 * Build an ObjectCreatingEvent carrying the given payload.
	 *
	 * Uses the suite's OpenRegister stubs (tests/stubs/OpenRegister), which the
	 * unit bootstrap registers under the OCA\OpenRegister\ PSR-4 prefix.
	 *
	 * @param array<string,mixed> $data The object payload being written.
	 *
	 * @return ObjectCreatingEvent The event.
	 */
	private function creatingEvent(array $data): ObjectCreatingEvent {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setSchema('OrderFulfilment');
		$entity->setRegister('shillinq');

		return new ObjectCreatingEvent($entity);
	}//end creatingEvent()

	/**
	 * Build an ObjectUpdatingEvent carrying the given payload.
	 *
	 * @param array<string,mixed> $data The object payload being written.
	 *
	 * @return ObjectUpdatingEvent The event.
	 */
	private function updatingEvent(array $data): ObjectUpdatingEvent {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setSchema('OrderFulfilment');
		$entity->setRegister('shillinq');

		return new ObjectUpdatingEvent($entity);
	}//end updatingEvent()
}//end class
