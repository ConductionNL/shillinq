<?php

/**
 * Releasing a payment block needs a role and a reason, and keeps the block reason.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#2-state-guards
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Listener\PaymentBlockReleaseListener;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A blocked supplier invoice, released by callers with and without the role.
 */
#[CoversClass(PaymentBlockReleaseListener::class)]
final class PaymentBlockReleaseListenerTest extends TestCase {

	private const BLOCKED = ['paymentBlocked' => true, 'paymentBlockReason' => 'IBAN change under verification', 'invoiceNumber' => 'INK-1'];

	/**
	 * @param list<string> $groups The caller's groups; null for a save without a user.
	 */
	private function listener(?array $groups, bool $admin = false, string $schema = 'APTransaction'): PaymentBlockReleaseListener {
		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->method('matchesSchema')->willReturnCallback(fn (?object $entity, string $slug): bool => $slug === $schema);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($groups !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('ann');
		}
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('ann')->willReturn($admin);
		$groupManager->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => in_array($group, ($groups ?? []), true));
		return new PaymentBlockReleaseListener($resolver, $session, $groupManager);
	}

	private function release(PaymentBlockReleaseListener $listener, array $patch, array $before = self::BLOCKED): ObjectUpdatingEvent {
		$old = new ObjectEntity();
		$old->setObject($before);
		$new = new ObjectEntity();
		$new->setObject(array_merge($before, ['paymentBlocked' => false], $patch));
		$event = new ObjectUpdatingEvent($new, $old);
		$listener->handle($event);
		return $event;
	}

	public function testABookkeeperCannotReleaseABlock(): void {
		$event = $this->release($this->listener(groups: ['shillinq_bookkeeper']), ['paymentBlockReleaseReason' => 'Creditnota ontvangen']);

		self::assertSame(['message' => PaymentBlockReleaseListener::MESSAGE_ROLE, 'field' => 'paymentBlocked'], $event->getErrors());
		self::assertTrue($event->isPropagationStopped());
	}

	public function testAReleaseWithoutAReasonIsRefused(): void {
		$listener = $this->listener(groups: ['shillinq_controller']);

		$none = $this->release($listener, []);
		self::assertSame(['message' => PaymentBlockReleaseListener::MESSAGE_REASON, 'field' => 'paymentBlockReleaseReason'], $none->getErrors());

		$blank = $this->release($listener, ['paymentBlockReleaseReason' => '   ']);
		self::assertSame(PaymentBlockReleaseListener::MESSAGE_REASON, $blank->getErrors()['message'] ?? null);

		$stale = $this->release(
			$listener,
			[],
			self::BLOCKED + ['paymentBlockReleaseReason' => 'Released last year']
		);
		self::assertSame(PaymentBlockReleaseListener::MESSAGE_REASON, $stale->getErrors()['message'] ?? null, 'A reason from an earlier release does not count.');
	}

	public function testAReleaseThatDropsTheBlockReasonIsRefused(): void {
		$event = $this->release($this->listener(groups: ['shillinq_treasurer']), ['paymentBlockReleaseReason' => 'IBAN verified', 'paymentBlockReason' => null]);

		self::assertSame(['message' => PaymentBlockReleaseListener::MESSAGE_KEEP, 'field' => 'paymentBlockReason'], $event->getErrors());
	}

	public function testAControllerTreasurerOrAdministratorMayReleaseWithAReason(): void {
		foreach ([$this->listener(groups: ['shillinq_controller']), $this->listener(groups: ['shillinq_treasurer']), $this->listener(groups: [], admin: true)] as $listener) {
			$event = $this->release($listener, ['paymentBlockReleaseReason' => 'IBAN verified']);
			self::assertSame([], $event->getErrors());
			self::assertFalse($event->isPropagationStopped());
		}
	}

	public function testASupplierBlockFollowsTheSameRules(): void {
		$event = $this->release($this->listener(groups: ['shillinq_bookkeeper'], schema: 'Payee'), ['paymentBlockReleaseReason' => 'IBAN verified']);

		self::assertSame(PaymentBlockReleaseListener::MESSAGE_ROLE, $event->getErrors()['message'] ?? null);
	}

	public function testOtherSavesAreUntouched(): void {
		$bookkeeper = $this->listener(groups: ['shillinq_bookkeeper']);

		$block = new ObjectUpdatingEvent(self::entity(self::BLOCKED), self::entity(['paymentBlocked' => false]));
		$bookkeeper->handle($block);
		self::assertSame([], $block->getErrors(), 'Setting a block is unchanged.');

		$edit = new ObjectUpdatingEvent(self::entity(self::BLOCKED + ['note' => 'x']), self::entity(self::BLOCKED));
		$bookkeeper->handle($edit);
		self::assertSame([], $edit->getErrors(), 'Editing a blocked invoice is unchanged.');

		$other = $this->release($this->listener(groups: ['shillinq_bookkeeper'], schema: 'PaymentRun'), []);
		self::assertSame([], $other->getErrors(), 'Another schema is not checked.');
	}

	public function testASystemSaveNeedsNoRoleButStillAReason(): void {
		$listener = $this->listener(groups: null);

		self::assertSame([], $this->release($listener, ['paymentBlockReleaseReason' => 'Creditnota ontvangen'])->getErrors());
		self::assertSame(PaymentBlockReleaseListener::MESSAGE_REASON, $this->release($listener, [])->getErrors()['message'] ?? null);
	}

	private static function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		return $entity;
	}
}
