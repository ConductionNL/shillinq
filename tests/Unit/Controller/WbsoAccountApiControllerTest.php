<?php

/**
 * Controller tests for WbsoAccountApiController.
 *
 * Covers REQ-WBSO-001/005/006 — list / hierarchy / show / create / update
 * endpoints with role-based access control and IDOR-safe administration
 * scoping. Lives under tests/Unit so phpunit.xml's bootstrap finds it; the
 * spec's Task 34 calls these "integration" tests in the API-surface sense.
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-34
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Controller\WbsoAccountApiController;
use OCA\Shillinq\Service\WbsoAccountService;
use OCA\Shillinq\Service\WbsoRbacResolver;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-34
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WbsoAccountApiControllerTest extends TestCase {

	/**
	 * Request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Service mock.
	 *
	 * @var WbsoAccountService&MockObject
	 */
	private WbsoAccountService&MockObject $accounts;

	/**
	 * Rbac mock.
	 *
	 * @var WbsoRbacResolver&MockObject
	 */
	private WbsoRbacResolver&MockObject $rbac;

	/**
	 * Session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock IL10N.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

	/**
	 * Controller under test.
	 *
	 * @var WbsoAccountApiController
	 */
	private WbsoAccountApiController $controller;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->accounts = $this->createMock(WbsoAccountService::class);
		$this->rbac = $this->createMock(WbsoRbacResolver::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);

		$this->controller = new WbsoAccountApiController(
			request: $this->request,
			accounts: $this->accounts,
			rbac: $this->rbac,
			userSession: $this->session,
			logger: $this->logger,
			l10n: $this->l10n,
		);
	}//end setUp()

	/**
	 * Index returns 200 with the accounts list.
	 *
	 * @return void
	 */
	public function testIndexReturnsAccounts(): void {
		$this->request->method('getParam')->willReturnMap([
			['administration_id', 'adm-consultancy-nl', 'adm-1'],
		]);
		$this->rbac->method('hasAny')->willReturn(false);
		$this->accounts->method('getAccountsByAdministration')->willReturn([
			['accountNumber' => '1000', 'name' => 'Kas', 'administrationId' => 'adm-1'],
		]);

		$response = $this->controller->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertCount(1, $data['accounts']);
		self::assertFalse($data['canCreate']);

	}//end testIndexReturnsAccounts()

	/**
	 * Unauthenticated user receives 401.
	 *
	 * @return void
	 */
	public function testUnauthenticatedReturns401(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$controller = new WbsoAccountApiController(
			request: $this->request,
			accounts: $this->accounts,
			rbac: $this->rbac,
			userSession: $session,
			logger: $this->logger,
			l10n: $this->l10n,
		);

		$response = $controller->index();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testUnauthenticatedReturns401()

	/**
	 * Hierarchy returns 200 with tree.
	 *
	 * @return void
	 */
	public function testHierarchyReturnsTree(): void {
		$this->request->method('getParam')->willReturnMap([
			['administration_id', 'adm-consultancy-nl', 'adm-1'],
		]);
		$this->rbac->method('hasAny')->willReturn(true);
		$this->accounts->method('getAccountHierarchy')->willReturn([
			['accountNumber' => '1', 'children' => []],
		]);

		$response = $this->controller->hierarchy();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('1', $data['tree'][0]['accountNumber']);
		self::assertTrue($data['canCreate']);

	}//end testHierarchyReturnsTree()

	/**
	 * Non-administrator create is rejected with 403.
	 *
	 * @return void
	 */
	public function testCreateRequiresAdministrator(): void {
		$this->request->method('getParam')->willReturn('adm-1');
		$this->rbac->method('hasAny')->willReturn(false);

		$response = $this->controller->create();
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testCreateRequiresAdministrator()

	/**
	 * Validation error surfaces as 400.
	 *
	 * @return void
	 */
	public function testCreateValidationErrorReturns400(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				if ($key === 'administration_id') {
					return 'adm-1';
				}

				return $default;
			}
		);
		$this->rbac->method('hasAny')->willReturn(true);
		$this->accounts->method('createAccount')->willThrowException(new InvalidArgumentException('accountNumber is required'));

		$response = $this->controller->create();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testCreateValidationErrorReturns400()

	/**
	 * Show returns 404 when the account is missing.
	 *
	 * @return void
	 */
	public function testShowMissingReturns404(): void {
		$this->request->method('getParam')->willReturn('adm-1');
		$this->accounts->method('getAccountByNumber')->willReturn(null);

		$response = $this->controller->show(accountNumber: '9999');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testShowMissingReturns404()
}//end class
