<?php

/**
 * Unit tests for RoleFallbackResolver.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/migrate-legacy-notification-dialect/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Notification\RoleFallbackResolver;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the primary/fallback group resolution behaviour (task 1.4):
 * primary has members -> returns primary; primary empty -> falls back;
 * both empty -> returns [] (fail-closed, same as the legacy resolver+fallback
 * behaviour it replaces).
 */
class RoleFallbackResolverTest extends TestCase {
	/**
	 * Build a mock IUser with a fixed uid.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function makeUser(string $uid): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end makeUser()

	/**
	 * Build a mock IGroup with the given member uids.
	 *
	 * @param array<int,string> $uids The member uids.
	 *
	 * @return IGroup
	 */
	private function makeGroup(array $uids): IGroup {
		$group = $this->createMock(originalClassName: IGroup::class);
		$group->method('getUsers')->willReturn(
			array_map(fn (string $uid) => $this->makeUser(uid: $uid), $uids)
		);
		return $group;
	}//end makeGroup()

	/**
	 * Primary group has members -> returns the primary group's uids, fallback untouched.
	 *
	 * @return void
	 */
	public function testPrimaryGroupWithMembersIsReturned(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnMap(
			[
				['shillinq_finance_officer', $this->makeGroup(['alice'])],
				['shillinq_subsidie_coordinator', $this->makeGroup(['bob'])],
			]
		);
		$logger = $this->createMock(LoggerInterface::class);

		$resolver = new RoleFallbackResolver(
			groupManager: $groupManager,
			logger: $logger,
			primaryGroup: 'shillinq_finance_officer',
			fallbackGroup: 'shillinq_subsidie_coordinator',
		);

		$object = $this->createMock(ObjectEntity::class);
		$this->assertSame(['alice'], $resolver->resolve($object, []));

	}//end testPrimaryGroupWithMembersIsReturned()

	/**
	 * Primary group is empty -> falls back to the fallback group's uids.
	 *
	 * @return void
	 */
	public function testEmptyPrimaryFallsBackToFallbackGroup(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnMap(
			[
				['shillinq_finance_officer', $this->makeGroup([])],
				['shillinq_subsidie_coordinator', $this->makeGroup(['bob'])],
			]
		);
		$logger = $this->createMock(LoggerInterface::class);

		$resolver = new RoleFallbackResolver(
			groupManager: $groupManager,
			logger: $logger,
			primaryGroup: 'shillinq_finance_officer',
			fallbackGroup: 'shillinq_subsidie_coordinator',
		);

		$object = $this->createMock(ObjectEntity::class);
		$this->assertSame(['bob'], $resolver->resolve($object, []));

	}//end testEmptyPrimaryFallsBackToFallbackGroup()

	/**
	 * Both primary and fallback groups are empty -> returns [] (fail-closed).
	 *
	 * @return void
	 */
	public function testBothGroupsEmptyReturnsEmptyArray(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnMap(
			[
				['shillinq_finance_officer', $this->makeGroup([])],
				['shillinq_subsidie_coordinator', $this->makeGroup([])],
			]
		);
		$logger = $this->createMock(LoggerInterface::class);

		$resolver = new RoleFallbackResolver(
			groupManager: $groupManager,
			logger: $logger,
			primaryGroup: 'shillinq_finance_officer',
			fallbackGroup: 'shillinq_subsidie_coordinator',
		);

		$object = $this->createMock(ObjectEntity::class);
		$this->assertSame([], $resolver->resolve($object, []));

	}//end testBothGroupsEmptyReturnsEmptyArray()

	/**
	 * An unknown primary group (get() returns null) and no fallback configured
	 * -> returns [] without throwing.
	 *
	 * @return void
	 */
	public function testUnknownGroupAndNoFallbackReturnsEmptyArray(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);
		$logger = $this->createMock(LoggerInterface::class);

		$resolver = new RoleFallbackResolver(
			groupManager: $groupManager,
			logger: $logger,
			primaryGroup: 'shillinq_unknown_role',
		);

		$object = $this->createMock(ObjectEntity::class);
		$this->assertSame([], $resolver->resolve($object, []));

	}//end testUnknownGroupAndNoFallbackReturnsEmptyArray()

	/**
	 * A group-lookup failure (Throwable) is caught and yields [] fail-safe.
	 *
	 * @return void
	 */
	public function testGroupLookupFailureIsFailSafe(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willThrowException(new \RuntimeException('boom'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$resolver = new RoleFallbackResolver(
			groupManager: $groupManager,
			logger: $logger,
			primaryGroup: 'shillinq_finance_officer',
		);

		$object = $this->createMock(ObjectEntity::class);
		$this->assertSame([], $resolver->resolve($object, []));

	}//end testGroupLookupFailureIsFailSafe()
}//end class
