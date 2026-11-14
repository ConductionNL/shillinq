<?php

/**
 * Unit tests for InvoiceNumberLockListener.
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
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Listener\InvoiceNumberLockListener;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SIIC-002: an assigned invoice number cannot be edited.
 *
 * @covers \OCA\Shillinq\Listener\InvoiceNumberLockListener
 */
class InvoiceNumberLockListenerTest extends TestCase {
	/**
	 * The listener, with every object read as the given schema.
	 *
	 * @param string $schema The schema slug every object belongs to.
	 *
	 * @return InvoiceNumberLockListener The listener.
	 */
	private function listener(string $schema = 'ARInvoice'): InvoiceNumberLockListener {
		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->method('matchesSchema')->willReturnCallback(
			static fn (?object $entity, string $expectedSlug): bool => $expectedSlug === $schema
		);

		return new InvoiceNumberLockListener(schemaResolver: $resolver);
	}//end listener()

	/**
	 * An update from one stored invoice to another.
	 *
	 * @param array<string, mixed> $before The stored invoice.
	 * @param array<string, mixed> $after  The invoice as it would be saved.
	 * @param string               $schema The schema the objects belong to.
	 *
	 * @return ObjectUpdatingEvent The event, after the listener ran.
	 */
	private function update(array $before, array $after, string $schema = 'ARInvoice'): ObjectUpdatingEvent {
		$old = new ObjectEntity();
		$old->setObject($before);
		$new = new ObjectEntity();
		$new->setObject($after);
		$event = new ObjectUpdatingEvent($new, $old);
		$this->listener(schema: $schema)->handle($event);

		return $event;
	}//end update()

	/**
	 * A bookkeeper cannot renumber issued invoice 2026-0042, nor clear its number.
	 *
	 * @return void
	 */
	public function testAnIssuedNumberCannotBeChanged(): void {
		foreach (['issued', 'paid', 'overdue', 'disputed', 'written-off'] as $state) {
			foreach (['2026-0099', ''] as $attempt) {
				$event = $this->update(
					before: ['invoiceNumber' => '2026-0042', 'lifecycleState' => $state],
					after: ['invoiceNumber' => $attempt, 'lifecycleState' => $state]
				);
				$this->assertSame(['message' => InvoiceNumberLockListener::MESSAGE, 'field' => 'invoiceNumber'], $event->getErrors(), $state);
				$this->assertTrue($event->isPropagationStopped());
			}
		}
	}//end testAnIssuedNumberCannotBeChanged()

	/**
	 * Other edits of an issued invoice leave its number alone and pass.
	 *
	 * @return void
	 */
	public function testAnEditThatKeepsTheNumberPasses(): void {
		$event = $this->update(
			before: ['invoiceNumber' => '2026-0042', 'lifecycleState' => 'issued', 'dueDate' => '2026-04-01'],
			after: ['invoiceNumber' => ' 2026-0042', 'lifecycleState' => 'disputed', 'dueDate' => '2026-04-15']
		);

		$this->assertSame([], $event->getErrors());
		$this->assertFalse($event->isPropagationStopped());
	}//end testAnEditThatKeepsTheNumberPasses()

	/**
	 * The issue of a draft or an approved invoice writes the series number.
	 *
	 * @return void
	 */
	public function testTheIssueWritesTheNumber(): void {
		foreach (['draft', 'approved', 'awaiting-approval'] as $state) {
			$event = $this->update(
				before: ['invoiceNumber' => 'DRAFT-20260314-ab12cd34', 'lifecycleState' => $state],
				after: ['invoiceNumber' => '2026-0042', 'lifecycleState' => 'issued']
			);
			$this->assertSame([], $event->getErrors(), $state);
		}
	}//end testTheIssueWritesTheNumber()

	/**
	 * Another schema's number, and an event other than an update, are not judged.
	 *
	 * @return void
	 */
	public function testOtherSchemasAndEventsAreNotJudged(): void {
		$event = $this->update(
			before: ['invoiceNumber' => 'BIL-2026-0001', 'lifecycleState' => 'issued'],
			after: ['invoiceNumber' => 'BIL-2026-0002', 'lifecycleState' => 'issued'],
			schema: 'BillableInvoice'
		);
		$this->assertSame([], $event->getErrors());

		$entity = new ObjectEntity();
		$entity->setObject(['invoiceNumber' => '2026-0042', 'lifecycleState' => 'issued']);
		$creating = new ObjectCreatingEvent($entity);
		$this->listener()->handle($creating);
		$this->assertFalse($creating->isPropagationStopped());
	}//end testOtherSchemasAndEventsAreNotJudged()
}//end class
