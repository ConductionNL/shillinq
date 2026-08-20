<?php

/**
 * Unit tests for WbsoRbacResolver.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-30
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\WbsoRbacResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the group→role mapping, admin elevation, default fallback and
 * hasAny()/canCreate() helpers per REQ-WBSO-005.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-30
 */
final class WbsoRbacResolverTest extends TestCase {

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock IGroupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

	}//end setUp()

	/**
	 * Build the subject with the shared mocks.
	 *
	 * @return WbsoRbacResolver
	 */
	private function resolver(): WbsoRbacResolver {
		return new WbsoRbacResolver($this->userSession, $this->groupManager);
	}//end resolver()

	/**
	 * Configure the mocks for a given user-id + group-membership map.
	 *
	 * @param string|null $uid User id, or null for unauthenticated.
	 * @param array<string,bool> $groups Group → membership.
	 * @param bool $admin Whether the user is an admin.
	 *
	 * @return void
	 */
	private function arrangeUser(?string $uid, array $groups = [], bool $admin = false): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		$this->groupManager->method('isAdmin')->with($uid)->willReturn($admin);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static function (string $u, string $g) use ($uid, $groups): bool {
				if ($u !== $uid) {
					return false;
				}

				return ($groups[$g] ?? false) === true;
			}
		);

	}//end arrangeUser()

	/**
	 * Unauthenticated session returns no roles.
	 *
	 * @return void
	 */
	public function testCurrentRolesReturnsEmptyWhenNoUser(): void {
		$this->arrangeUser(null);

		self::assertSame([], $this->resolver()->currentRoles());
		self::assertFalse($this->resolver()->hasAny(['bookkeeper']));
		self::assertFalse($this->resolver()->canCreate());

	}//end testCurrentRolesReturnsEmptyWhenNoUser()

	/**
	 * A Nextcloud admin always picks up the `administrator` role first.
	 *
	 * @return void
	 */
	public function testAdminGetsAdministratorRole(): void {
		$this->arrangeUser('alice', [], true);

		$roles = $this->resolver()->currentRoles();
		self::assertContains('administrator', $roles);
		self::assertSame('administrator', $roles[0]);
		self::assertTrue($this->resolver()->canCreate());

	}//end testAdminGetsAdministratorRole()

	/**
	 * `shillinq_bookkeeper` group → `bookkeeper` role.
	 *
	 * @return void
	 */
	public function testBookkeeperGroupMapsToBookkeeperRole(): void {
		$this->arrangeUser('bob', ['shillinq_bookkeeper' => true]);

		$roles = $this->resolver()->currentRoles();
		self::assertContains('bookkeeper', $roles);
		self::assertNotContains('auditor', $roles);
		self::assertNotContains('administrator', $roles);
		self::assertTrue($this->resolver()->canCreate());

	}//end testBookkeeperGroupMapsToBookkeeperRole()

	/**
	 * `shillinq_auditor` group → `auditor` role (read-only — cannot create).
	 *
	 * @return void
	 */
	public function testAuditorGroupMapsToAuditorRoleWithoutCreatePermission(): void {
		$this->arrangeUser('carol', ['shillinq_auditor' => true]);

		$roles = $this->resolver()->currentRoles();
		self::assertSame(['auditor'], $roles);
		self::assertTrue($this->resolver()->hasAny(['auditor']));
		self::assertFalse($this->resolver()->canCreate());

	}//end testAuditorGroupMapsToAuditorRoleWithoutCreatePermission()

	/**
	 * Plain `bookkeeper` / `auditor` group aliases (no `shillinq_` prefix)
	 * resolve to the same roles.
	 *
	 * @return void
	 */
	public function testUnprefixedGroupAliasesResolveToSameRoles(): void {
		$this->arrangeUser('dave', ['bookkeeper' => true, 'auditor' => true]);

		$roles = $this->resolver()->currentRoles();
		self::assertContains('bookkeeper', $roles);
		self::assertContains('auditor', $roles);
		// No duplicates introduced by overlapping aliases.
		self::assertSame(count(array_unique($roles)), count($roles));

	}//end testUnprefixedGroupAliasesResolveToSameRoles()

	/**
	 * Admin + bookkeeper group → both roles, admin listed first, no dupes.
	 *
	 * @return void
	 */
	public function testAdminPlusBookkeeperYieldsBothRolesOnce(): void {
		$this->arrangeUser('erin', ['shillinq_bookkeeper' => true], true);

		$roles = $this->resolver()->currentRoles();
		self::assertSame(['administrator', 'bookkeeper'], $roles);
		self::assertTrue($this->resolver()->canCreate());

	}//end testAdminPlusBookkeeperYieldsBothRolesOnce()

	/**
	 * Authenticated user with no shillinq group falls back to `bookkeeper`
	 * read scope (write still requires an explicit role — but the helper
	 * `canCreate()` includes `bookkeeper`, so this honours REQ-WBSO-005).
	 *
	 * @return void
	 */
	public function testUserWithoutShillinqGroupDefaultsToBookkeeper(): void {
		$this->arrangeUser('frank', []);

		self::assertSame(['bookkeeper'], $this->resolver()->currentRoles());

	}//end testUserWithoutShillinqGroupDefaultsToBookkeeper()

	/**
	 * `hasAny()` returns true when at least one allowed role is held.
	 *
	 * @return void
	 */
	public function testHasAnyMatchesAtLeastOneRole(): void {
		$this->arrangeUser('grace', ['shillinq_auditor' => true]);

		$r = $this->resolver();
		self::assertTrue($r->hasAny(['auditor', 'administrator']));
		self::assertFalse($r->hasAny(['administrator']));
		self::assertFalse($r->hasAny([]));

	}//end testHasAnyMatchesAtLeastOneRole()

	/**
	 * `shillinq_admin` group resolves to `administrator` even without
	 * Nextcloud admin elevation.
	 *
	 * @return void
	 */
	public function testShillinqAdminGroupMapsToAdministratorRole(): void {
		$this->arrangeUser('heidi', ['shillinq_admin' => true]);

		$roles = $this->resolver()->currentRoles();
		self::assertContains('administrator', $roles);
		self::assertTrue($this->resolver()->canCreate());

	}//end testShillinqAdminGroupMapsToAdministratorRole()

}//end class
