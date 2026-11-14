<?php

/**
 * Golden tests for the access checks of the WBSO account, document and
 * transaction APIs.
 *
 * Every action of the three controllers checks, in this order: an
 * authenticated caller, the role the action needs, the administration the
 * request names (or the caller's first one), posting rights where the action
 * books, and the shape of the id in the route. These tests pin the allowed
 * and refused outcome of each step for every action.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\WbsoAccountApiController;
use OCA\Shillinq\Controller\WbsoDocumentApiController;
use OCA\Shillinq\Controller\WbsoTransactionApiController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\WbsoAccountService;
use OCA\Shillinq\Service\WbsoDocumentService;
use OCA\Shillinq\Service\WbsoRbacResolver;
use OCA\Shillinq\Service\WbsoTransactionService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the access steps of the WBSO API controllers.
 */
final class WbsoApiAccessGoldenTest extends TestCase {

	/**
	 * The roles the caller holds.
	 *
	 * @var array<int,string>
	 */
	private array $roles = [];

	/**
	 * Request parameters.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * Whether a user is logged in.
	 *
	 * @var boolean
	 */
	private bool $loggedIn = true;

	/**
	 * Administrations the caller may access.
	 *
	 * @var array<int,string>
	 */
	private array $accessible = ['adm-1', 'adm-2'];

	/**
	 * Administrations the caller may post in.
	 *
	 * @var array<int,string>
	 */
	private array $posting = ['adm-1', 'adm-2'];

	/**
	 * Calls made to the collaborators, in order.
	 *
	 * @var array<int,string>
	 */
	private array $calls = [];

	/**
	 * The service mock of the controller under test.
	 *
	 * @var MockObject
	 */
	private MockObject $service;

	/**
	 * Every action: controller, method, route argument, roles it needs (null
	 * for any authenticated caller), role refusal, whether it books, the
	 * refusal for a malformed id (null when it takes none), and the service
	 * method it calls.
	 *
	 * @return array<string,array{0:string,1:string,2:?string,3:?array<int,string>,4:string,5:bool,6:?string,7:string}>
	 */
	public static function actions(): array {
		$bookkeeper = ['bookkeeper', 'administrator'];
		$bookkeeperRefusal = 'Bookkeeper or administrator role required';
		return [
			'account index' => ['account', 'index', null, null, '', false, null, 'getAccountsByAdministration'],
			'account hierarchy' => ['account', 'hierarchy', null, null, '', false, null, 'getAccountHierarchy'],
			'account show' => ['account', 'show', '1000', null, '', false, 'Invalid accountNumber', 'getAccountByNumber'],
			'account create' => ['account', 'create', null, ['administrator'], 'Administrator role required', false, null, 'createAccount'],
			'account update' => ['account', 'update', '1000', ['administrator'], 'Administrator role required', false, 'Invalid accountNumber', 'updateAccount'],
			'document index' => ['document', 'index', null, null, '', false, null, 'getDocumentsByAdministration'],
			'document show' => ['document', 'show', 'doc-1', null, '', false, 'Invalid document id', 'getDocument'],
			'document create' => ['document', 'create', null, $bookkeeper, $bookkeeperRefusal, false, null, 'createDocument'],
			'document file' => ['document', 'file', 'doc-1', $bookkeeper, $bookkeeperRefusal, false, 'Invalid document id', 'fileDocument'],
			'document archive' => ['document', 'archive', 'doc-1', ['auditor', 'administrator'], 'Auditor or administrator role required', false, 'Invalid document id', 'archiveDocument'],
			'transaction index' => ['transaction', 'index', null, null, '', false, null, 'listTransactions'],
			'transaction show' => ['transaction', 'show', 'tx-1', null, '', false, 'Invalid transaction id', 'getTransaction'],
			'transaction create' => ['transaction', 'create', null, $bookkeeper, $bookkeeperRefusal, true, null, 'createTransaction'],
			'transaction post' => ['transaction', 'post', 'tx-1', $bookkeeper, $bookkeeperRefusal, true, 'Invalid transaction id', 'postTransaction'],
			'transaction reverse' => ['transaction', 'reverse', 'tx-1', ['administrator'], 'Administrator role required', true, 'Invalid transaction id', 'reverseTransaction'],
		];

	}//end actions()

	/**
	 * Build the controller of a kind with recording collaborators.
	 *
	 * @param string $kind account, document or transaction.
	 *
	 * @return object
	 */
	private function controller(string $kind): object {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default = null): mixed {
				$this->calls[] = 'param:' . $key;
				return ($this->params[$key] ?? $default);
			}
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function (): ?IUser {
				$this->calls[] = 'user';
				if ($this->loggedIn === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn('alice');
				return $user;
			}
		);
		$rbac = $this->createMock(WbsoRbacResolver::class);
		$rbac->method('hasAny')->willReturnCallback(
			function (array $allowed): bool {
				$this->calls[] = 'role:' . implode('|', $allowed);
				return array_intersect($allowed, $this->roles) !== [];
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('accessibleAdministrationIds')->willReturnCallback(
			function (): array {
				$this->calls[] = 'accessible';
				return $this->accessible;
			}
		);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId): bool {
				$this->calls[] = 'canAccess:' . $administrationId;
				return in_array($administrationId, $this->accessible, true);
			}
		);
		$context->method('canPostJournalEntry')->willReturnCallback(
			function (string $administrationId): bool {
				$this->calls[] = 'canPost:' . $administrationId;
				return in_array($administrationId, $this->posting, true);
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);

		if ($kind === 'account') {
			$this->service = $this->createMock(WbsoAccountService::class);
			return new WbsoAccountApiController($request, $this->service, $rbac, $session, $logger, $l10n, $context);
		}

		if ($kind === 'document') {
			$this->service = $this->createMock(WbsoDocumentService::class);
			return new WbsoDocumentApiController($request, $this->service, $rbac, $session, $logger, $l10n, $context);
		}

		$this->service = $this->createMock(WbsoTransactionService::class);
		return new WbsoTransactionApiController($request, $this->service, $rbac, $session, $logger, $l10n, $context);

	}//end controller()

	/**
	 * Call an action.
	 *
	 * @param string $kind The controller kind.
	 * @param string $method The action.
	 * @param string|null $argument The route argument.
	 *
	 * @return JSONResponse
	 */
	private function call(string $kind, string $method, ?string $argument): JSONResponse {
		$controller = $this->controller($kind);
		if ($argument === null) {
			return $controller->$method();
		}

		return $controller->$method($argument);

	}//end call()

	/**
	 * Response status and body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private static function outcome(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];

	}//end outcome()

	/**
	 * An anonymous caller is refused before anything else is asked.
	 *
	 * @param string $kind Controller kind.
	 * @param string $method Action.
	 * @param string|null $argument Route argument.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testAnonymousCallerIsRefused(string $kind, string $method, ?string $argument): void {
		$this->loggedIn = false;

		$response = $this->call($kind, $method, $argument);

		self::assertSame([401, ['error' => 'Authentication required']], self::outcome($response));
		self::assertSame(['user'], $this->calls);

	}//end testAnonymousCallerIsRefused()

	/**
	 * A caller without the role is refused before the administration is read.
	 *
	 * @param string $kind Controller kind.
	 * @param string $method Action.
	 * @param string|null $argument Route argument.
	 * @param array<int,string>|null $roles Required roles.
	 * @param string $refusal Role refusal.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testCallerWithoutTheRoleIsRefused(string $kind, string $method, ?string $argument, ?array $roles, string $refusal): void {
		if ($roles === null) {
			self::assertTrue(true, 'read action: no role is required');
			return;
		}

		$this->roles = ['viewer'];

		$response = $this->call($kind, $method, $argument);

		self::assertSame([403, ['error' => $refusal]], self::outcome($response));
		self::assertSame(['user', 'role:' . implode('|', $roles)], $this->calls);

	}//end testCallerWithoutTheRoleIsRefused()

	/**
	 * Administration parameters the caller is refused for.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<int,string>}>
	 */
	public static function refusedAdministrations(): array {
		return [
			'malformed' => [['administration_id' => 'adm 1'], []],
			'too long' => [['administration_id' => str_repeat('a', 65)], []],
			'foreign' => [['administration_id' => 'adm-9'], ['canAccess:adm-9']],
			'none to default to' => [[], ['accessible']],
		];

	}//end refusedAdministrations()

	/**
	 * Every action with every refused administration.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function actionsWithRefusedAdministrations(): array {
		$cases = [];
		foreach (self::actions() as $name => $action) {
			foreach (self::refusedAdministrations() as $label => $administration) {
				$cases[$name . ' / ' . $label] = array_merge([$action[0], $action[1], $action[2], $action[3]], $administration);
			}
		}

		return $cases;

	}//end actionsWithRefusedAdministrations()

	/**
	 * A malformed, foreign or missing administration is a masked 404.
	 *
	 * @param string $kind Controller kind.
	 * @param string $method Action.
	 * @param string|null $argument Route argument.
	 * @param array<int,string>|null $roles Required roles.
	 * @param array<string,mixed> $params Request parameters.
	 * @param array<int,string> $checks The administration checks made.
	 *
	 * @return void
	 *
	 * @dataProvider actionsWithRefusedAdministrations
	 */
	public function testRefusedAdministrationIsNotFound(string $kind, string $method, ?string $argument, ?array $roles, array $params, array $checks): void {
		$this->roles = ['administrator', 'bookkeeper', 'auditor'];
		$this->params = $params;
		if ($params === []) {
			$this->accessible = [];
		}

		$response = $this->call($kind, $method, $argument);

		$expected = ['user'];
		if ($roles !== null) {
			$expected[] = 'role:' . implode('|', $roles);
		}

		$expected = array_merge($expected, ['param:administration_id'], $checks);
		self::assertSame([404, ['error' => 'Administration not found']], self::outcome($response));
		self::assertSame($expected, $this->calls);

	}//end testRefusedAdministrationIsNotFound()

	/**
	 * A booking action without posting rights in the administration is refused.
	 *
	 * @param string $kind Controller kind.
	 * @param string $method Action.
	 * @param string|null $argument Route argument.
	 * @param array<int,string>|null $roles Required roles.
	 * @param string $refusal Role refusal.
	 * @param bool $books Whether the action books.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testPostingRightsAreRequiredToBook(string $kind, string $method, ?string $argument, ?array $roles, string $refusal, bool $books): void {
		$this->roles = ['administrator', 'bookkeeper', 'auditor'];
		$this->params = ['administration_id' => 'adm-2'];
		$this->posting = ['adm-1'];

		$response = $this->call($kind, $method, $argument);

		if ($books === false) {
			self::assertNotContains('canPost:adm-2', $this->calls);
			self::assertNotSame(403, $response->getStatus());
			return;
		}

		self::assertSame([403, ['error' => 'Posting rights in this administration required']], self::outcome($response));
		self::assertSame(['user', 'role:' . implode('|', $roles), 'param:administration_id', 'canAccess:adm-2', 'canPost:adm-2'], $this->calls);

	}//end testPostingRightsAreRequiredToBook()

	/**
	 * A malformed route id is a 400 after every access step, before the service.
	 *
	 * @param string $kind Controller kind.
	 * @param string $method Action.
	 * @param string|null $argument Route argument.
	 * @param array<int,string>|null $roles Required roles.
	 * @param string $refusal Role refusal.
	 * @param bool $books Whether the action books.
	 * @param string|null $idRefusal Refusal for a malformed id.
	 * @param string $serviceMethod Service method.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testMalformedIdIsRefused(string $kind, string $method, ?string $argument, ?array $roles, string $refusal, bool $books, ?string $idRefusal, string $serviceMethod): void {
		if ($idRefusal === null) {
			self::assertTrue(true, 'the action takes no id');
			return;
		}

		$this->roles = ['administrator', 'bookkeeper', 'auditor'];
		$this->params = ['administration_id' => 'adm-1'];

		$controller = $this->controller($kind);
		$this->service->expects(self::never())->method($serviceMethod);
		$response = $controller->$method('bad id');

		self::assertSame([400, ['error' => $idRefusal]], self::outcome($response));
		if ($books === true) {
			self::assertContains('canPost:adm-1', $this->calls);
		}

	}//end testMalformedIdIsRefused()

	/**
	 * An allowed caller reaches the service with the resolved administration;
	 * without a parameter that is the caller's first administration.
	 *
	 * @param string $kind Controller kind.
	 * @param string $method Action.
	 * @param string|null $argument Route argument.
	 * @param array<int,string>|null $roles Required roles.
	 * @param string $refusal Role refusal.
	 * @param bool $books Whether the action books.
	 * @param string|null $idRefusal Refusal for a malformed id.
	 * @param string $serviceMethod Service method.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testAllowedCallerReachesTheService(string $kind, string $method, ?string $argument, ?array $roles, string $refusal, bool $books, ?string $idRefusal, string $serviceMethod): void {
		$this->roles = ['administrator', 'bookkeeper', 'auditor'];
		$this->accessible = ['adm-2', 'adm-1'];

		$controller = $this->controller($kind);
		$this->service->expects(self::once())->method($serviceMethod)
			->with(self::identicalTo('adm-2'))
			->willThrowException(new \LogicException('stop here'));
		$response = $this->call2($controller, $method, $argument);

		self::assertSame(500, $response->getStatus());
		$expected = ['user'];
		if ($roles !== null) {
			$expected[] = 'role:' . implode('|', $roles);
		}

		$expected = array_merge($expected, ['param:administration_id', 'accessible']);
		if ($books === true) {
			$expected[] = 'canPost:adm-2';
		}

		self::assertSame($expected, array_slice($this->calls, 0, count($expected)));

	}//end testAllowedCallerReachesTheService()

	/**
	 * Call an action on a built controller.
	 *
	 * @param object $controller The controller.
	 * @param string $method The action.
	 * @param string|null $argument The route argument.
	 *
	 * @return JSONResponse
	 */
	private function call2(object $controller, string $method, ?string $argument): JSONResponse {
		if ($argument === null) {
			return $controller->$method();
		}

		return $controller->$method($argument);

	}//end call2()

	/**
	 * Early archiving is reserved to administrators, after the id check.
	 *
	 * @return void
	 */
	public function testEarlyArchiveNeedsAnAdministrator(): void {
		$this->roles = ['auditor'];
		$this->params = ['administration_id' => 'adm-1', 'allowEarly' => '1'];

		$response = $this->call('document', 'archive', 'doc-1');

		self::assertSame([403, ['error' => 'Only administrators can override the 7-year retention boundary']], self::outcome($response));
		self::assertSame(
			['user', 'role:auditor|administrator', 'param:administration_id', 'canAccess:adm-1', 'param:reason', 'param:allowEarly', 'role:administrator'],
			$this->calls
		);

	}//end testEarlyArchiveNeedsAnAdministrator()
}//end class
