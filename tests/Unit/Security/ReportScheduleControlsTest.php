<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\ReportScheduleWriteListener;
use OCA\Shillinq\Reporting\Schedule\ReportRecipientPolicy;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Only the owner changes a schedule's delivery, and only to people who may read the administration.
 */
final class ReportScheduleControlsTest extends TestCase {

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function policy(): ReportRecipientPolicy {
		$administrations = $this->createMock(AdministrationContextService::class);
		$administrations->method('canUserAccess')->willReturnCallback(
			fn (string $userId, string $administrationId): bool => $administrationId === 'A' && in_array($userId, ['owner', 'colleague'], true)
		);
		$finance = $this->createMock(IGroup::class);
		$finance->method('getGID')->willReturn('finance');
		$finance->method('getUsers')->willReturn([$this->user('owner'), $this->user('colleague')]);
		$everyone = $this->createMock(IGroup::class);
		$everyone->method('getGID')->willReturn('everyone');
		$everyone->method('getUsers')->willReturn([$this->user('owner'), $this->user('outsider')]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(fn (string $gid) => ['finance' => $finance, 'everyone' => $everyone][$gid] ?? null);
		$groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $gid): bool => $uid === 'owner');
		return new ReportRecipientPolicy($administrations, $groups);
	}

	public function testRecipientsWithoutAccessAreRefused(): void {
		self::assertSame(
			['user:outsider', 'group:everyone', 'group:missing', 'mail:x@example.org'],
			$this->policy()->refused('owner', 'A', ['user:colleague', 'user:outsider', 'group:finance', 'group:everyone', 'group:missing', 'mail:x@example.org'])
		);
		self::assertSame(['user:colleague'], $this->policy()->refused('owner', 'B', ['user:colleague']));
	}

	private function listener(string $caller): ReportScheduleWriteListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturn(true);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user($caller));
		return new ReportScheduleWriteListener($resolver, $this->policy(), $session);
	}

	private function update(array $old, array $new): ObjectUpdatingEvent {
		$before = $this->getMockBuilder(ObjectEntity::class)->onlyMethods(['getOwner'])->getMock();
		$before->method('getOwner')->willReturn('owner');
		$before->setObject($old);
		$after = new ObjectEntity();
		$after->setObject($new);
		return new ObjectUpdatingEvent($after, $before);
	}

	public function testSomebodyElseCannotAddThemselves(): void {
		$schedule = ['administrationId' => 'A', 'recipients' => ['user:owner']];
		$event = $this->update($schedule, ['administrationId' => 'A', 'recipients' => ['user:owner', 'user:colleague']]);
		$this->listener('colleague')->handle($event);
		self::assertSame(ReportScheduleWriteListener::MESSAGE_NOT_OWNER, $event->getErrors()['message'] ?? null);
	}

	public function testOwnerMayShareOnlyWithReaders(): void {
		$schedule = ['administrationId' => 'A', 'recipients' => ['user:owner']];
		$event = $this->update($schedule, ['administrationId' => 'A', 'recipients' => ['user:colleague']]);
		$this->listener('owner')->handle($event);
		self::assertSame([], $event->getErrors());

		$event = $this->update($schedule, ['administrationId' => 'A', 'recipients' => ['user:outsider']]);
		$this->listener('owner')->handle($event);
		self::assertStringStartsWith(ReportScheduleWriteListener::MESSAGE_RECIPIENT, $event->getErrors()['message'] ?? '');
	}
}
