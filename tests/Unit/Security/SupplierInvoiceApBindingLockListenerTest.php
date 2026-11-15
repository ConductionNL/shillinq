<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\SupplierInvoiceApBindingLockListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use PHPUnit\Framework\TestCase;

/**
 * A supplier invoice handed to accounts payable keeps its AP binding: its AP
 * transaction, administration, number, supplier and total.
 *
 * @covers \OCA\Shillinq\Listener\SupplierInvoiceApBindingLockListener
 */
final class SupplierInvoiceApBindingLockListenerTest extends TestCase {

	private const HANDED = [
		'state' => 'approved',
		'apTransactionId' => 'tx-a',
		'administrationId' => 'adm-1',
		'invoiceNumber' => 'A-1',
		'supplierId' => 'payee-1',
		'totalInclVat' => 100000,
		'note' => 'x',
	];

	private function listener(string $schema = 'SupplierInvoice'): SupplierInvoiceApBindingLockListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturnCallback(static fn ($entity, string $slug): bool => $slug === $schema);
		return new SupplierInvoiceApBindingLockListener($resolver);
	}

	private static function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('inv-a');
		$entity->setObject($data);
		return $entity;
	}

	/**
	 * Run an update from $before to $after and return the errors it set.
	 *
	 * @return array<string, mixed>
	 */
	private function update(array $before, array $after, string $schema = 'SupplierInvoice'): array {
		$event = new ObjectUpdatingEvent(self::entity($after), self::entity($before));
		$this->listener($schema)->handle($event);
		if ($event->getErrors() !== []) {
			self::assertTrue($event->isPropagationStopped(), 'a refusal stops propagation');
		}
		return $event->getErrors();
	}

	public function testEachBoundFieldIsFrozenAfterTheHandover(): void {
		$changes = ['apTransactionId' => 'tx-b', 'administrationId' => 'adm-2', 'invoiceNumber' => 'B-1', 'supplierId' => 'payee-2', 'totalInclVat' => 5000];
		foreach ($changes as $field => $value) {
			$errors = $this->update(self::HANDED, [$field => $value] + self::HANDED);
			self::assertSame(SupplierInvoiceApBindingLockListener::MESSAGE, $errors['message'] ?? null, $field);
			self::assertSame($field, $errors['field'] ?? null);
		}
	}

	public function testRemovingABoundFieldIsAChange(): void {
		$after = self::HANDED;
		unset($after['supplierId']);
		self::assertSame('supplierId', $this->update(self::HANDED, $after)['field'] ?? null);
	}

	public function testTheRealignmentOntoAnotherPaidInvoiceIsRefused(): void {
		$after = ['apTransactionId' => 'tx-b', 'invoiceNumber' => 'B-1', 'supplierId' => 'payee-2', 'totalInclVat' => 5000, 'state' => 'paid'] + self::HANDED;
		self::assertSame(SupplierInvoiceApBindingLockListener::MESSAGE, $this->update(self::HANDED, $after)['message'] ?? null);
	}

	public function testOtherFieldsAndTheStateMayChange(): void {
		self::assertSame([], $this->update(self::HANDED, ['note' => 'y', 'state' => 'paid'] + self::HANDED));
	}

	/**
	 * Before the hand-over the lock lets the binding be written, the hand-over's
	 * own write included. A binding written by hand does not make the invoice
	 * payable: the pay guard accepts only a transaction that names the invoice's
	 * uuid (SupplierInvoicePayGuardLookupTest).
	 */
	public function testBeforeTheHandoverTheLockLeavesTheBindingToThePayGuard(): void {
		$before = ['apTransactionId' => ''] + self::HANDED;
		self::assertSame([], $this->update($before, ['apTransactionId' => 'tx-a', 'totalInclVat' => 1] + self::HANDED));
		$before = self::HANDED;
		unset($before['apTransactionId']);
		self::assertSame([], $this->update($before, ['invoiceNumber' => 'A-2'] + self::HANDED));
	}

	public function testDataThatNamesAnotherInvoiceIsRefusedBeforeTheHandover(): void {
		$before = ['apTransactionId' => ''] + self::HANDED;
		$errors = $this->update($before, ['id' => 'inv-b', 'apTransactionId' => 'tx-b'] + self::HANDED);
		self::assertSame(SupplierInvoiceApBindingLockListener::ID_MESSAGE, $errors['message'] ?? null);
		self::assertSame('id', $errors['field'] ?? null);
	}

	public function testDataThatNamesTheInvoiceItselfPasses(): void {
		self::assertSame([], $this->update(self::HANDED, ['id' => 'inv-a', 'note' => 'y'] + self::HANDED));
		self::assertSame([], $this->update(self::HANDED, ['id' => '', 'note' => 'y'] + self::HANDED));
	}

	public function testOtherSchemasAndCreatesAreLeftAlone(): void {
		self::assertSame([], $this->update(self::HANDED, ['apTransactionId' => 'tx-b'] + self::HANDED, 'PurchaseOrder'));
		$event = new ObjectCreatingEvent(self::entity(self::HANDED));
		$this->listener()->handle($event);
		self::assertSame([], $event->getErrors());
	}

	public function testANumberWrittenAsTextIsAChange(): void {
		self::assertSame('totalInclVat', $this->update(self::HANDED, ['totalInclVat' => '100000'] + self::HANDED)['field'] ?? null);
	}
}
