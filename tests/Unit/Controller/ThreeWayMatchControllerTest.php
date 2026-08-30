<?php

/**
 * Unit tests for ThreeWayMatchController.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ThreeWayMatchController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ThreeWayMatchingEngine;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the single trigger endpoint (evaluate) for: the anonymous refusal,
 * the required administration scope, the cross-tenant 404 mask (ADR-005) —
 * with the matching engine proven unreached — the required invoiceId, the
 * service-refusal mapping and the no-stack-trace 500.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ThreeWayMatchControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock ThreeWayMatchingEngine (server-authoritative).
	 *
	 * @var ThreeWayMatchingEngine&MockObject
	 */
	private ThreeWayMatchingEngine&MockObject $matchingEngine;

	/**
	 * Mock AdministrationContextService (IDOR + tenant scope).
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $administrationContext;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The user the session reports; null models an anonymous caller.
	 *
	 * @var IUser|null
	 */
	private ?IUser $currentUser = null;

	/**
	 * The controller under test.
	 *
	 * @var ThreeWayMatchController
	 */
	private ThreeWayMatchController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->matchingEngine = $this->createMock(ThreeWayMatchingEngine::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->currentUser = $user;

		$this->userSession->method('getUser')->willReturnCallback(
			function (): ?IUser {
				return $this->currentUser;
			}
		);

		$this->controller = new ThreeWayMatchController(
			request: $this->request,
			matchingEngine: $this->matchingEngine,
			administrationContext: $this->administrationContext,
			userSession: $this->userSession,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Configure request params from a key => value map.
	 *
	 * @param array<string,mixed> $map Param map.
	 *
	 * @return void
	 */
	private function withParams(array $map): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($map): mixed {
				return ($map[$key] ?? $default);
			}
		);

	}//end withParams()

	/**
	 * A valid trigger returns 200 with the persisted match and forwards the
	 * scope + invoice id to the engine.
	 *
	 * @return void
	 */
	public function testEvaluateReturns200WithPersistedMatch(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$seen = [];
		$this->matchingEngine->expects($this->once())
			->method('evaluateMatch')
			->willReturnCallback(
				static function (string $administrationId, string $invoiceId) use (&$seen): array {
					$seen = [$administrationId, $invoiceId];
					return ['id' => 'twm-1', 'invoiceId' => $invoiceId, 'status' => 'matched'];
				}
			);

		$response = $this->controller->evaluate();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['adm-1', 'inv-9'], $seen);
		self::assertSame('matched', $response->getData()['status']);

	}//end testEvaluateReturns200WithPersistedMatch()

	/**
	 * An anonymous evaluate is refused with 401 and never reaches the engine.
	 *
	 * @return void
	 */
	public function testEvaluateRejectsAnonymousCaller(): void {
		$this->currentUser = null;
		$this->matchingEngine->expects($this->never())->method('evaluateMatch');

		$response = $this->controller->evaluate();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not logged in'], $response->getData());

	}//end testEvaluateRejectsAnonymousCaller()

	/**
	 * A missing administrationId is a 400 — the trigger is never unscoped.
	 *
	 * @return void
	 */
	public function testEvaluateRequiresAdministrationId(): void {
		$this->withParams(['invoiceId' => 'inv-9']);
		$this->matchingEngine->expects($this->never())->method('evaluateMatch');

		$response = $this->controller->evaluate();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'administrationId is required'], $response->getData());

	}//end testEvaluateRequiresAdministrationId()

	/**
	 * Triggering inside another tenant's administration is masked as 404
	 * (ADR-005) and the matching engine is never reached — proving the
	 * guard short-circuits rather than merely matching the status code.
	 *
	 * @return void
	 */
	public function testEvaluateForeignAdministrationReturns404(): void {
		$this->withParams(['administrationId' => 'adm-other', 'invoiceId' => 'inv-9']);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->matchingEngine->expects($this->never())->method('evaluateMatch');

		$response = $this->controller->evaluate();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Supplier invoice not found'], $response->getData());

	}//end testEvaluateForeignAdministrationReturns404()

	/**
	 * A missing invoiceId is a 400 (a blank id would otherwise reach the engine).
	 *
	 * @return void
	 */
	public function testEvaluateRequiresInvoiceId(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->matchingEngine->expects($this->never())->method('evaluateMatch');

		$response = $this->controller->evaluate();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'invoiceId is required'], $response->getData());

	}//end testEvaluateRequiresInvoiceId()

	/**
	 * A malformed invoiceId never reaches the engine — the slug pattern
	 * rejects it as a missing id.
	 *
	 * @return void
	 */
	public function testEvaluateRejectsMalformedInvoiceId(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => '../../inv']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->matchingEngine->expects($this->never())->method('evaluateMatch');

		$response = $this->controller->evaluate();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'invoiceId is required'], $response->getData());

	}//end testEvaluateRejectsMalformedInvoiceId()

	/**
	 * A "not found" refusal from the engine maps to 404, not 400.
	 *
	 * @return void
	 */
	public function testEvaluateUnknownInvoiceReturns404(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->matchingEngine->method('evaluateMatch')
			->willThrowException(new \RuntimeException('SupplierInvoice not found'));

		$response = $this->controller->evaluate();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'SupplierInvoice not found'], $response->getData());

	}//end testEvaluateUnknownInvoiceReturns404()

	/**
	 * Any other engine refusal maps to 400 (validation).
	 *
	 * @return void
	 */
	public function testEvaluateServiceRefusalReturns400(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->matchingEngine->method('evaluateMatch')
			->willThrowException(new \RuntimeException('SupplierInvoice has no PO reference'));

		$response = $this->controller->evaluate();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testEvaluateServiceRefusalReturns400()

	/**
	 * An unexpected failure is logged and returns a generic 500 with no trace.
	 *
	 * @return void
	 */
	public function testEvaluateUnexpectedFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->matchingEngine->method('evaluateMatch')
			->willThrowException(new \LogicException('SQLSTATE[42S02] shillinq_match missing'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->evaluate();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not evaluate three-way match'], $response->getData());
		self::assertStringNotContainsStringIgnoringCase(
			'SQLSTATE',
			(string)json_encode($response->getData())
		);

	}//end testEvaluateUnexpectedFailureReturns500WithoutStackTrace()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
