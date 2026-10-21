<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\Shillinq\Service\RoleMembership;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * A role is the group shillinq_<role>; an administrator holds every role.
 */
final class RoleMembershipTest extends TestCase {

	private function roles(?array $groups): RoleMembership {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($groups === null ? null : $this->createMock(IUser::class));
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('getUserGroupIds')->willReturn($groups ?? []);
		return new RoleMembership(userSession: $session, groupManager: $manager);
	}

	public function testRoleGroupGrants(): void {
		self::assertTrue($this->roles(['shillinq_controller'])->callerHoldsAny(['treasurer', 'controller']));
	}

	public function testOtherRoleOrBareNameDoesNotGrant(): void {
		self::assertFalse($this->roles(['shillinq_bookkeeper', 'controller'])->callerHoldsAny(['controller']));
	}

	public function testAdministratorAndAnonymous(): void {
		self::assertTrue($this->roles(['admin'])->callerHoldsAny(['controller']));
		self::assertFalse($this->roles(null)->callerHoldsAny(['controller']));
	}
}
