<?php

/**
 * Tests for AdministrationMemberController.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
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

namespace OCA\Shillinq\Tests\Unit\Controller;

use DomainException;
use OCA\Shillinq\Controller\AdministrationMemberController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AdministrationManagementAccess;
use OCA\Shillinq\Service\AdministrationMembershipService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Only an owner adds members; a member cannot add themselves to another administration.
 *
 * The caller (anna) owns adm-a and is a plain member of adm-b; adm-c is not theirs.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
 */
final class AdministrationMemberControllerTest extends TestCase {

	/**
	 * Request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Membership writes.
	 *
	 * @var AdministrationMembershipService&MockObject
	 */
	private AdministrationMembershipService&MockObject $memberships;

	/**
	 * Controller.
	 *
	 * @var AdministrationMemberController
	 */
	private AdministrationMemberController $controller;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->memberships = $this->createMock(AdministrationMembershipService::class);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('anna');
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => in_array($id, ['adm-a', 'adm-b'], true));
		$access = $this->createMock(AdministrationManagementAccess::class);
		$access->method('canManage')->willReturnCallback(static fn (string $id): bool => $id === 'adm-a');
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => in_array($uid, ['anna', 'bert'], true));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$this->controller = new AdministrationMemberController(
			request: $this->request,
			memberships: $this->memberships,
			administrationContext: $context,
			managementAccess: $access,
			userManager: $users,
			l10n: $l10n,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * The owner adds a member, recorded as granted by the owner.
	 *
	 * @return void
	 */
	public function testOwnerAddsAMember(): void {
		$this->params(['userId' => 'bert', 'role' => 'boekhouder']);
		$this->memberships->expects(self::once())
			->method('addMember')
			->with('adm-a', 'bert', 'boekhouder', 'anna', self::anything())
			->willReturn(['userId' => 'bert', 'administrationId' => 'adm-a', 'role' => 'boekhouder']);

		$response = $this->controller->create(administrationId: 'adm-a');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());

	}//end testOwnerAddsAMember()

	/**
	 * A user cannot add themselves to an administration they are no member of.
	 *
	 * @return void
	 */
	public function testUserCannotAddThemselvesToAnotherAdministration(): void {
		$this->params(['userId' => 'anna', 'role' => 'eigenaar']);
		$this->memberships->expects(self::never())->method('addMember');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->create(administrationId: 'adm-c')->getStatus());

	}//end testUserCannotAddThemselvesToAnotherAdministration()

	/**
	 * A member who is not an owner cannot add anyone, themselves as owner included.
	 *
	 * @return void
	 */
	public function testMemberWhoIsNotOwnerCannotAdd(): void {
		$this->params(['userId' => 'anna', 'role' => 'eigenaar']);
		$this->memberships->expects(self::never())->method('addMember');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->create(administrationId: 'adm-b')->getStatus());

	}//end testMemberWhoIsNotOwnerCannotAdd()

	/**
	 * An unknown user is refused before anything is written.
	 *
	 * @return void
	 */
	public function testUnknownUserIsRefused(): void {
		$this->params(['userId' => 'ghost', 'role' => 'inkijker']);
		$this->memberships->expects(self::never())->method('addMember');

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create(administrationId: 'adm-a')->getStatus());

	}//end testUnknownUserIsRefused()

	/**
	 * Adding an existing member is a conflict.
	 *
	 * @return void
	 */
	public function testExistingMemberIsAConflict(): void {
		$this->params(['userId' => 'bert', 'role' => 'inkijker']);
		$this->memberships->method('addMember')->willThrowException(new DomainException('already'));

		self::assertSame(Http::STATUS_CONFLICT, $this->controller->create(administrationId: 'adm-a')->getStatus());

	}//end testExistingMemberIsAConflict()

	/**
	 * Stub the request parameters.
	 *
	 * @param array<string,mixed> $params The parameters.
	 *
	 * @return void
	 */
	private function params(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
	}//end params()
}//end class
