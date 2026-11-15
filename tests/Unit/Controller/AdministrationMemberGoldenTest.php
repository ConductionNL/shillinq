<?php

/**
 * Golden tests for AdministrationMemberController::create.
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
use InvalidArgumentException;
use OCA\Shillinq\Controller\AdministrationMemberController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AdministrationManagementAccess;
use OCA\Shillinq\Service\AdministrationMembershipService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every answer of adding a member, the checks made before the write,
 * the arguments written and the failure log line.
 */
final class AdministrationMemberGoldenTest extends TestCase {
	/**
	 * Recorded calls, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed> $params  Request params.
	 * @param mixed                $outcome What addMember answers, or a Throwable it throws.
	 * @param string|null          $user    The current user.
	 *
	 * @return AdministrationMemberController
	 */
	private function controller(array $params, mixed $outcome = ['ok' => true], ?string $user = 'anna'): AdministrationMemberController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($user);
		$context->method('canAccess')->willReturnCallback(
			function (string $id): bool {
				$this->calls[] = 'canAccess:' . $id;
				return $id !== 'adm-c';
			}
		);
		$access = $this->createMock(AdministrationManagementAccess::class);
		$access->method('canManage')->willReturnCallback(
			function (string $id): bool {
				$this->calls[] = 'canManage:' . $id;
				return $id === 'adm-a';
			}
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(
			function (string $uid): bool {
				$this->calls[] = 'userExists:' . $uid;
				return $uid === 'bert';
			}
		);
		$memberships = $this->createMock(AdministrationMembershipService::class);
		$memberships->method('addMember')->willReturnCallback(
			function (string $administrationId, string $userId, string $role, string $grantedBy, array $options) use ($outcome): array {
				$this->calls[] = 'add:' . json_encode([$administrationId, $userId, $role, $grantedBy, $options]);
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				return $outcome;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:' . $text);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = 'error:' . $message . json_encode($context);
			}
		);

		return new AdministrationMemberController(
			request: $request,
			memberships: $memberships,
			administrationContext: $context,
			managementAccess: $access,
			userManager: $users,
			l10n: $l10n,
			logger: $logger,
		);
	}//end controller()

	/**
	 * Add a member and return status and body.
	 *
	 * @param AdministrationMemberController $controller       The controller.
	 * @param string                         $administrationId The administration.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private static function add(AdministrationMemberController $controller, string $administrationId): array {
		$response = $controller->create(administrationId: $administrationId);
		return [$response->getStatus(), $response->getData()];
	}//end add()

	/**
	 * The refusals, with the checks made before each.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$this->assertSame([401, ['error' => 'not-logged-in']], self::add($this->controller(['userId' => 'bert'], user: null), 'adm-a'));
		$this->assertSame([], $this->calls);
		$this->assertSame([404, ['error' => 'not-found']], self::add($this->controller(['userId' => 'bert']), 'adm-c'));
		$this->assertSame(
			[403, ['error' => 'forbidden', 'message' => 'nl:Only an owner of this administration can add members.']],
			self::add($this->controller(['userId' => 'bert']), 'adm-b')
		);
		$unknown = [400, ['error' => 'unknown-user', 'message' => 'nl:Choose an existing user.']];
		$this->assertSame($unknown, self::add($this->controller(['userId' => ' ']), 'adm-a'));
		$this->assertSame($unknown, self::add($this->controller(['userId' => 'ghost']), 'adm-a'));
		$this->assertSame(
			['canManage:adm-c', 'canAccess:adm-c', 'canManage:adm-b', 'canAccess:adm-b', 'canManage:adm-a', 'canManage:adm-a', 'userExists:ghost'],
			$this->calls
		);
	}//end testRefusals()

	/**
	 * The write with its arguments, and each failure's answer.
	 *
	 * @return void
	 */
	public function testWritesAndFailures(): void {
		$params = ['userId' => ' bert ', 'role' => ' boekhouder ', 'validFrom' => '2026-01-01', 'mayCloseFiscalYear' => true];
		$this->assertSame([201, ['ok' => true]], self::add($this->controller($params), 'adm-a'));
		$this->assertSame(
			'add:' . json_encode(['adm-a', 'bert', 'boekhouder', 'anna', ['validFrom' => '2026-01-01', 'validUntil' => null, 'mayPostJournalEntries' => null, 'mayCloseFiscalYear' => true]]),
			end($this->calls)
		);

		$this->assertSame(
			[400, ['error' => 'invalid-membership', 'message' => 'nl:Choose a role, and write dates as YYYY-MM-DD.']],
			self::add($this->controller($params, new InvalidArgumentException('role')), 'adm-a')
		);
		$this->assertSame(
			[409, ['error' => 'already-member', 'message' => 'nl:This user is already a member of this administration.']],
			self::add($this->controller($params, new DomainException('already')), 'adm-a')
		);
		$this->assertSame([500, ['error' => 'save-failed']], self::add($this->controller($params, new \RuntimeException('db')), 'adm-a'));
		$this->assertSame('error:AdministrationMemberController: failed to add a member{"exception":"db"}', end($this->calls));
	}//end testWritesAndFailures()
}//end class
