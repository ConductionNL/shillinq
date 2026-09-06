<?php

/**
 * SubjectCostController Unit Tests
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\SubjectCostController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SubjectCostService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The door onto the subject-cost capability.
 *
 * These tests exist because the capability shipped without one. Every branch
 * below is a branch that had no caller at all before this controller.
 *
 * @covers \OCA\Shillinq\Controller\SubjectCostController
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SubjectCostControllerTest extends TestCase {
	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock SubjectCostService.
	 *
	 * @var SubjectCostService&MockObject
	 */
	private SubjectCostService&MockObject $costs;

	/**
	 * Mock AdministrationContextService.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

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
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->costs = $this->createMock(SubjectCostService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Default: a signed-in NON-admin. The admin bypass is a branch these
		// tests must opt into, not the state they accidentally run under.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturnCallback(
			static fn (string $uid): bool => $uid === 'root'
		);
	}//end setUp()

	/**
	 * Build the controller over the current mocks.
	 *
	 * @return SubjectCostController The controller.
	 */
	private function controller(): SubjectCostController {
		return new SubjectCostController(
			request: $this->request,
			costs: $this->costs,
			context: $this->context,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->logger,
		);
	}

	/**
	 * Stub the query parameters.
	 *
	 * @param array<string, string> $params The params.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $name, mixed $default = null) use ($params): mixed {
				return ($params[$name] ?? $default);
			}
		);
	}

	/**
	 * An authenticated member gets the aggregate.
	 *
	 * @return void
	 */
	public function testAMemberGetsTheAggregate(): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('accessibleAdministrationIds')->willReturn(['adm-1']);
		$this->withParams(['subjectApp' => 'dossiq', 'subjectId' => 'case-1']);

		$this->costs->expects(self::once())
			->method('costFor')
			->with('dossiq', 'case-1', ['adm-1'], '')
			->willReturn(['hours' => 3.5, 'costCents' => 17500, 'complete' => true]);

		$response = $this->controller()->index();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(3.5, $response->getData()['hours']);
	}

	/**
	 * An unauthenticated request is rejected with 401.
	 *
	 * @return void
	 */
	public function testUnauthenticatedRequestsAreRejected(): void {
		$this->context->method('currentUserId')->willReturn(null);
		$this->withParams(['subjectApp' => 'dossiq', 'subjectId' => 'case-1']);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->index()->getStatus());
	}

	/**
	 * A request naming no subject is refused, rather than aggregating the
	 * ledger's entire hour set.
	 *
	 * @return void
	 */
	public function testAMissingSubjectIsRefused(): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('accessibleAdministrationIds')->willReturn(['adm-1']);
		$this->withParams(['subjectApp' => 'dossiq']);

		$this->costs->expects(self::never())->method('costFor');

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->index()->getStatus());
	}

	/**
	 * A malformed period is refused before it reaches hrmq.
	 *
	 * @return void
	 */
	public function testAMalformedPeriodIsRefused(): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('accessibleAdministrationIds')->willReturn(['adm-1']);
		$this->withParams(['subjectApp' => 'dossiq', 'subjectId' => 'case-1', 'period' => 'last month']);

		$this->costs->expects(self::never())->method('costFor');

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->index()->getStatus());
	}

	/**
	 * A caller belonging to no administration sees nothing.
	 *
	 * @return void
	 */
	public function testACallerWithNoAdministrationIsRefused(): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('accessibleAdministrationIds')->willReturn([]);
		$this->withParams(['subjectApp' => 'dossiq', 'subjectId' => 'case-1']);

		$this->costs->expects(self::never())->method('costFor');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->index()->getStatus());
	}

	/**
	 * Naming an administration the caller cannot reach is masked as 404, never
	 * quietly widened to the ones they can.
	 *
	 * 404 rather than 403 is the house IDOR rule stated on
	 * AdministrationContextService::canAccess() (REQ-MA-001): a resource the
	 * caller may not reach must be indistinguishable from one that is absent.
	 *
	 * @return void
	 */
	public function testAnUnreachableAdministrationIsMaskedNotWidened(): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('accessibleAdministrationIds')->willReturn(['adm-1']);
		$this->context->method('canAccess')->with('adm-9')->willReturn(false);
		$this->withParams(
			['subjectApp' => 'dossiq', 'subjectId' => 'case-1', 'administrationId' => 'adm-9']
		);

		$this->costs->expects(self::never())->method('costFor');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->index()->getStatus());
	}

	/**
	 * A named administration the caller can reach narrows the scope to it.
	 *
	 * @return void
	 */
	public function testANamedAdministrationNarrowsTheScope(): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('accessibleAdministrationIds')->willReturn(['adm-1', 'adm-2']);
		$this->context->method('canAccess')->with('adm-2')->willReturn(true);
		$this->withParams(
			['subjectApp' => 'dossiq', 'subjectId' => 'case-1', 'administrationId' => 'adm-2']
		);

		$this->costs->expects(self::once())
			->method('costFor')
			->with('dossiq', 'case-1', ['adm-2'], '')
			->willReturn(['hours' => 1.0]);

		self::assertSame(Http::STATUS_OK, $this->controller()->index()->getStatus());
	}

	/**
	 * A failure answers 500 without leaking a stack trace.
	 *
	 * @return void
	 */
	public function testAFailureIsReportedWithoutAStackTrace(): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('accessibleAdministrationIds')->willReturn(['adm-1']);
		$this->withParams(['subjectApp' => 'dossiq', 'subjectId' => 'case-1']);
		$this->costs->method('costFor')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller()->index();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('Failed to compute the subject cost', $response->getData()['error']);
	}

	/**
	 * A Nextcloud admin reads every administration, and is not refused for
	 * holding no AdministrationMembership of its own.
	 *
	 * This is the branch the CI admin account actually runs under: ci-seed.sh
	 * creates no membership for it, so without the bypass the endpoint answers
	 * 403 to the only user the e2e suite has.
	 *
	 * @return void
	 */
	public function testANextcloudAdminReadsEveryAdministration(): void {
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('root');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($admin);

		$this->context->method('currentUserId')->willReturn('root');
		$this->context->method('accessibleAdministrationIds')->willReturn([]);
		$this->withParams(['subjectApp' => 'dossiq', 'subjectId' => 'case-1']);

		$this->costs->expects(self::once())
			->method('costFor')
			->with('dossiq', 'case-1', null, '')
			->willReturn(['hours' => 9.0]);

		$controller = new SubjectCostController(
			request: $this->request,
			costs: $this->costs,
			context: $this->context,
			userSession: $session,
			groupManager: $this->groupManager,
			logger: $this->logger,
		);

		self::assertSame(Http::STATUS_OK, $controller->index()->getStatus());
	}

	/**
	 * An admin naming an administration is scoped to it, not masked.
	 *
	 * @return void
	 */
	public function testANextcloudAdminMayNameAnyAdministration(): void {
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('root');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($admin);

		$this->context->method('currentUserId')->willReturn('root');
		$this->context->method('accessibleAdministrationIds')->willReturn([]);
		$this->context->method('canAccess')->willReturn(false);
		$this->withParams(
			['subjectApp' => 'dossiq', 'subjectId' => 'case-1', 'administrationId' => 'adm-9']
		);

		$this->costs->expects(self::once())
			->method('costFor')
			->with('dossiq', 'case-1', ['adm-9'], '')
			->willReturn(['hours' => 1.0]);

		$controller = new SubjectCostController(
			request: $this->request,
			costs: $this->costs,
			context: $this->context,
			userSession: $session,
			groupManager: $this->groupManager,
			logger: $this->logger,
		);

		self::assertSame(Http::STATUS_OK, $controller->index()->getStatus());
	}
}//end class
