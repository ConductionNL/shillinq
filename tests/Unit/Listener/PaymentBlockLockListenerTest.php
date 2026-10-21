<?php

/**
 * Tests that payment blocks change only through the block endpoint.
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
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\PaymentBlockLockListener;
use OCA\Shillinq\PaymentRun\PaymentBlockWriteScope;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * F2-S2: a browser PATCH can no longer release a payment block.
 *
 * @spec openspec/specs/payment-control-guards/spec.md
 */
final class PaymentBlockLockListenerTest extends TestCase {

	private const BLOCKED = [
		'invoiceNumber' => 'JDV-2026-06-0003',
		'paymentBlocked' => true,
		'paymentBlockReason' => 'Wacht op creditnota',
		'paymentBlockedBy' => 'anna',
	];

	/**
	 * A PATCH that releases the block is refused.
	 *
	 * @return void
	 */
	public function testAReleaseOutsideTheEndpointIsRefused(): void {
		$event = $this->event(old: self::BLOCKED, new: ['paymentBlocked' => false, 'paymentBlockReason' => null] + self::BLOCKED);

		$this->listener(scope: new PaymentBlockWriteScope())->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['paymentBlocked', 'paymentBlockReason'], $event->getErrors()['fields']);
	}//end testAReleaseOutsideTheEndpointIsRefused()

	/**
	 * A PATCH that sets a block is refused too: the reason and actor come from the endpoint.
	 *
	 * @return void
	 */
	public function testABlockOutsideTheEndpointIsRefused(): void {
		$event = $this->event(old: ['paymentBlocked' => false], new: ['paymentBlocked' => true]);

		$this->listener(scope: new PaymentBlockWriteScope())->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testABlockOutsideTheEndpointIsRefused()

	/**
	 * The endpoint's own write passes.
	 *
	 * @return void
	 */
	public function testTheEndpointsWritePasses(): void {
		$event = $this->event(old: self::BLOCKED, new: ['paymentBlocked' => false, 'paymentReleasedBy' => 'bram'] + self::BLOCKED);
		$scope = new PaymentBlockWriteScope();

		$scope->run(fn () => $this->listener(scope: $scope)->handle($event));

		self::assertFalse($event->isPropagationStopped());
		self::assertFalse($scope->isOpen(), 'The scope closes after the write.');
	}//end testTheEndpointsWritePasses()

	/**
	 * An edit that leaves the block fields out keeps the stored block.
	 *
	 * @return void
	 */
	public function testAnEditWithoutTheBlockFieldsKeepsTheBlock(): void {
		$event = $this->event(old: self::BLOCKED, new: ['invoiceNumber' => 'JDV-2026-06-0003-A']);

		$this->listener(scope: new PaymentBlockWriteScope())->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(
			['paymentBlocked' => true, 'paymentBlockReason' => 'Wacht op creditnota', 'paymentBlockedBy' => 'anna'],
			$event->getModifiedData()
		);
	}//end testAnEditWithoutTheBlockFieldsKeepsTheBlock()

	/**
	 * An edit that sends the block fields unchanged passes; a missing flag equals false.
	 *
	 * @return void
	 */
	public function testAnEditWithUnchangedBlockFieldsPasses(): void {
		$event = $this->event(old: ['invoiceNumber' => 'A'], new: ['invoiceNumber' => 'B', 'paymentBlocked' => false, 'paymentBlockReason' => '']);

		$this->listener(scope: new PaymentBlockWriteScope())->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnEditWithUnchangedBlockFieldsPasses()

	/**
	 * Other schemas are untouched.
	 *
	 * @return void
	 */
	public function testOtherSchemasAreUntouched(): void {
		$event = $this->event(old: self::BLOCKED, new: ['paymentBlocked' => false]);

		$this->listener(scope: new PaymentBlockWriteScope(), matches: false)->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testOtherSchemasAreUntouched()

	/**
	 * Build the listener.
	 *
	 * @param PaymentBlockWriteScope $scope   The write scope.
	 * @param bool                   $matches Whether the record is an invoice or supplier.
	 *
	 * @return PaymentBlockLockListener
	 */
	private function listener(PaymentBlockWriteScope $scope, bool $matches=true): PaymentBlockLockListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturn($matches);

		return new PaymentBlockLockListener(scope: $scope, schemaResolver: $resolver, logger: new NullLogger());
	}//end listener()

	/**
	 * Build an update event on an APTransaction.
	 *
	 * @param array<string,mixed> $old The stored record.
	 * @param array<string,mixed> $new The record being written.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function event(array $old, array $new): ObjectUpdatingEvent {
		$entities = [];
		foreach ([$new, $old] as $data) {
			$entity = new ObjectEntity();
			$entity->setObject($data);
			$entity->setSchema('APTransaction');
			$entity->setRegister('shillinq');
			$entities[] = $entity;
		}

		return new ObjectUpdatingEvent($entities[0], $entities[1]);
	}//end event()
}//end class
