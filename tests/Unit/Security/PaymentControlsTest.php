<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Lifecycle\PaymentRunReconcileGuard;
use OCA\Shillinq\Listener\PaymentRunLineLockListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\ServerWriteAuthority;
use PHPUnit\Framework\TestCase;

/**
 * Approved runs are locked and reconcile needs a CAMT match.
 */
final class PaymentControlsTest extends TestCase {

	private const LINE = ['payeeId' => 'p1', 'creditorIban' => 'NL91ABNA0417164300', 'amount' => 100.0, 'apTransactionRef' => 'ap1'];

	private function resolver(): ListenerSchemaResolver {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturn(true);
		return $resolver;
	}

	private function event(array $old, array $new): ObjectUpdatingEvent {
		$before = new ObjectEntity();
		$before->setObject($old);
		$after = new ObjectEntity();
		$after->setObject($new);
		return new ObjectUpdatingEvent($after, $before);
	}

	public function testApprovedRunLinesCannotChange(): void {
		$run = ['lifecycleState' => 'approved', 'paymentLines' => [self::LINE], 'totalAmount' => 100.0];
		$edited = $run;
		$edited['paymentLines'][0]['creditorIban'] = 'NL02RABO0123456789';
		$event = $this->event($run, $edited);
		(new PaymentRunLineLockListener($this->resolver()))->handle($event);
		self::assertSame(PaymentRunLineLockListener::MESSAGE, $event->getErrors()['message'] ?? null);
		self::assertSame('paymentLines', $event->getErrors()['field'] ?? null);
	}

	public function testDraftRunAndStateMovesStayOpen(): void {
		$listener = new PaymentRunLineLockListener($this->resolver());
		$draft = ['lifecycleState' => 'draft', 'paymentLines' => [self::LINE]];
		$event = $this->event($draft, ['lifecycleState' => 'draft', 'paymentLines' => []]);
		$listener->handle($event);
		self::assertSame([], $event->getErrors());

		$approved = ['lifecycleState' => 'approved', 'paymentLines' => [self::LINE], 'totalAmount' => 100.0];
		$event = $this->event($approved, $approved + ['exportedFileRef' => 'f1'] + ['lifecycleState' => 'exported']);
		$listener->handle($event);
		self::assertSame([], $event->getErrors());
	}

	public function testReconcileOnlyUnderTheCamtGrant(): void {
		$authority = new ServerWriteAuthority();
		$guard = new PaymentRunReconcileGuard($authority);
		self::assertFalse($guard->check(['id' => 'run-1'], 'reconcile', 'mallory')->isAllowed());
		self::assertTrue($authority->during(
			PaymentRunReconcileGuard::PURPOSE,
			'run-1',
			fn () => $guard->check(['id' => 'run-1'], 'reconcile', 'ann')
		)->isAllowed());
	}
}
