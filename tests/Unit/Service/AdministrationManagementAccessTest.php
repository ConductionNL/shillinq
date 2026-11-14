<?php

/**
 * Tests for AdministrationManagementAccess.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AdministrationManagementAccess;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * An owner manages their administration; a Nextcloud admin manages every one.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
 */
final class AdministrationManagementAccessTest extends TestCase {

	/**
	 * Owner of adm-a, not of adm-b, not an admin.
	 *
	 * @return void
	 */
	public function testOwnerManagesOnlyTheirAdministration(): void {
		$access = $this->access(userId: 'anna', isAdmin: false);

		self::assertTrue($access->canManage('adm-a'));
		self::assertFalse($access->canManage('adm-b'));
		self::assertFalse($access->canManage(''));

	}//end testOwnerManagesOnlyTheirAdministration()

	/**
	 * A Nextcloud admin manages any administration and the instance default.
	 *
	 * @return void
	 */
	public function testNextcloudAdminManagesEveryAdministration(): void {
		$access = $this->access(userId: 'admin', isAdmin: true);

		self::assertTrue($access->canManage('adm-b'));
		self::assertTrue($access->canManage(''));

	}//end testNextcloudAdminManagesEveryAdministration()

	/**
	 * Without a session nobody manages anything.
	 *
	 * @return void
	 */
	public function testAnonymousManagesNothing(): void {
		self::assertFalse($this->access(userId: null, isAdmin: true)->canManage('adm-a'));

	}//end testAnonymousManagesNothing()

	/**
	 * Build the access check.
	 *
	 * @param string|null $userId  The session user.
	 * @param bool        $isAdmin Whether the user is a Nextcloud admin.
	 *
	 * @return AdministrationManagementAccess
	 */
	private function access(?string $userId, bool $isAdmin): AdministrationManagementAccess {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($userId);
		$context->method('isOwnerOf')->willReturnCallback(static fn (string $id): bool => $id === 'adm-a');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return new AdministrationManagementAccess(administrationContext: $context, groupManager: $groups);

	}//end access()
}//end class
