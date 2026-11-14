<?php

/**
 * Unit tests for the filtered subscription path of FilteredObjectListenerTrait.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener\Registration;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\AppointmentCreatedListener;
use OCA\Shillinq\Listener\CommitmentTransitionListener;
use OCA\Shillinq\Listener\GLTransactionComplianceCacheListener;
use OCA\Shillinq\Listener\OssPaymentReconciliationListener;
use OCA\Shillinq\Listener\Registration\ListenerRegistrar;
use OCA\Shillinq\Listener\SupplierInvoiceWarningListener;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * When OpenRegister ships ObjectEventSubscription, every listener goes through
 * it with its schema filter and nothing is added to the dispatcher directly.
 *
 * Runs in a separate process: the subscription class is declared for the test
 * and must not leak into the rest of the suite, which relies on its absence.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(ListenerRegistrar::class)]
final class FilteredObjectListenerSubscriptionTest extends TestCase {

	/**
	 * Declare a recording ObjectEventSubscription in OpenRegister's namespace.
	 *
	 * @return void
	 */
	private function declareSubscription(): void {
		eval(
			'namespace OCA\\OpenRegister\\Event;
			final class ObjectEventSubscription {
				public static array $calls = [];
				public static function subscribe($dispatcher, string $event, string $listener, ?array $registers = null, ?array $schemas = null): void {
					self::$calls[] = [$dispatcher, $event, $listener, $registers, $schemas];
				}
			}'
		);

	}//end declareSubscription()

	/**
	 * Every filtered listener is subscribed with its schemas and the live dispatcher.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testListenersAreSubscribedThroughOpenRegisterWithTheirSchemas(): void {
		$this->declareSubscription();
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::never())->method('addServiceListener');

		(new ListenerRegistrar())->subscribe($dispatcher);

		$calls = \OCA\OpenRegister\Event\ObjectEventSubscription::$calls;
		self::assertCount(16, $calls);
		foreach ($calls as $call) {
			self::assertSame($dispatcher, $call[0]);
			self::assertNull($call[3], 'No listener narrows on register slugs.');
		}

		$pick = static fn (array $call): array => [$call[1], $call[2], $call[4]];

		self::assertSame([ObjectCreatedEvent::class, AppointmentCreatedListener::class, ['Appointment']], $pick($calls[0]));
		self::assertSame([ObjectCreatedEvent::class, SupplierInvoiceWarningListener::class, ['SupplierInvoice']], $pick($calls[1]));
		self::assertSame([ObjectUpdatedEvent::class, SupplierInvoiceWarningListener::class, ['SupplierInvoice']], $pick($calls[2]));
		self::assertSame([ObjectCreatedEvent::class, OssPaymentReconciliationListener::class, ['OssPayment', 'oss-payment']], $pick($calls[7]));
		self::assertSame(
			[ObjectUpdatedEvent::class, GLTransactionComplianceCacheListener::class, ['GLTransaction', 'GLLine', 'GLTransactionLine']],
			$pick($calls[10])
		);
		self::assertSame([ObjectCreatedEvent::class, CommitmentTransitionListener::class, ['Commitment']], $pick($calls[15]));

	}//end testListenersAreSubscribedThroughOpenRegisterWithTheirSchemas()
}//end class
