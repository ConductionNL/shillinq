<?php

/**
 * Unit tests for GoodsReceiptNoteController.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\GoodsReceiptNoteController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\GoodsReceiptNoteService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the three-way-match GRN transition endpoints (add line, quality check,
 * accept).
 *
 * Covers the anonymous rejection, the path-parameter and scope validation, the
 * masked cross-tenant 404 and the RuntimeException → status mapping the
 * controller performs (not found → 404, terminal state / quantity overrun →
 * 409, anything else → 400).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class GoodsReceiptNoteControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock GoodsReceiptNoteService.
	 *
	 * @var GoodsReceiptNoteService&MockObject
	 */
	private GoodsReceiptNoteService&MockObject $grnService;

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
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The signed-in user, or null for an anonymous session.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * Whether the context grants access to the requested administration.
	 *
	 * @var boolean
	 */
	private bool $canAccess = true;

	/**
	 * The controller under test.
	 *
	 * @var GoodsReceiptNoteController
	 */
	private GoodsReceiptNoteController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->grnService = $this->createMock(GoodsReceiptNoteService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->user = $user;

		$this->userSession->method('getUser')->willReturnCallback(
			function (): ?IUser {
				return $this->user;
			}
		);
		$this->context->method('canAccess')->willReturnCallback(
			function (): bool {
				return $this->canAccess;
			}
		);

		$this->controller = new GoodsReceiptNoteController(
			request: $this->request,
			grnService: $this->grnService,
			administrationContext: $this->context,
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
	 * An anonymous caller cannot append a GRN line (HTTP 401).
	 *
	 * @return void
	 */
	public function testAddLineAnonymousReturns401(): void {
		$this->user = null;
		$this->withParams(['administrationId' => 'adm-1']);
		$this->grnService->expects($this->never())->method('addGRNLine');

		$response = $this->controller->addLine('grn-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAddLineAnonymousReturns401()

	/**
	 * A path-traversal GRN id is rejected with HTTP 400 before any lookup.
	 *
	 * @return void
	 */
	public function testAddLineMalformedIdReturns400(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->grnService->expects($this->never())->method('addGRNLine');

		$response = $this->controller->addLine('../../etc/passwd');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testAddLineMalformedIdReturns400()

	/**
	 * A missing administrationId yields HTTP 400.
	 *
	 * @return void
	 */
	public function testAddLineMissingAdministrationReturns400(): void {
		$this->withParams([]);

		$response = $this->controller->addLine('grn-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());

	}//end testAddLineMissingAdministrationReturns400()

	/**
	 * A non-member sees a masked HTTP 404 (ADR-005, no IDOR).
	 *
	 * @return void
	 */
	public function testAddLineForeignAdministrationReturns404(): void {
		$this->canAccess = false;
		$this->withParams(['administrationId' => 'adm-other']);
		$this->grnService->expects($this->never())->method('addGRNLine');

		$response = $this->controller->addLine('grn-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAddLineForeignAdministrationReturns404()

	/**
	 * A valid line is created with HTTP 201 and the body reaches the service.
	 *
	 * @return void
	 */
	public function testAddLineValidReturns201(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'poLineId' => 'pol-9',
				'quantityReceived' => 12,
				'quantityAccepted' => 10,
				'quantityRejected' => 2,
				'rejectionReason' => 'damaged',
				'batchReference' => 'B-2026-06',
			]
		);
		$seen = null;
		$this->grnService->method('addGRNLine')->willReturnCallback(
			static function (string $administrationId, string $grnId, array $payload) use (&$seen): array {
				$seen = $payload;
				return ['lineId' => 'grnl-1', 'grnId' => $grnId, 'poLineId' => $payload['poLineId']];
			}
		);

		$response = $this->controller->addLine('grn-1');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('grnl-1', $response->getData()['lineId']);
		self::assertSame('pol-9', $seen['poLineId']);
		self::assertSame(12, $seen['quantityReceived']);

	}//end testAddLineValidReturns201()

	/**
	 * A "may not exceed" overrun is mapped to HTTP 409, not 400 — receiving
	 * more than ordered is a lifecycle conflict, not bad input.
	 *
	 * @return void
	 */
	public function testAddLineOverReceiptReturns409(): void {
		$this->withParams(['administrationId' => 'adm-1', 'poLineId' => 'pol-9', 'quantityReceived' => 999]);
		$this->grnService->method('addGRNLine')->willThrowException(
			new \RuntimeException('quantityReceived may not exceed the ordered quantity')
		);

		$response = $this->controller->addLine('grn-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testAddLineOverReceiptReturns409()

	/**
	 * A "not found" service error is mapped to HTTP 404.
	 *
	 * @return void
	 */
	public function testAddLineUnknownPoLineReturns404(): void {
		$this->withParams(['administrationId' => 'adm-1', 'poLineId' => 'nope']);
		$this->grnService->method('addGRNLine')->willThrowException(
			new \RuntimeException('purchase order line not found')
		);

		$response = $this->controller->addLine('grn-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAddLineUnknownPoLineReturns404()

	/**
	 * An unexpected failure yields HTTP 500 and leaks no stack trace.
	 *
	 * @return void
	 */
	public function testAddLineUnexpectedFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['administrationId' => 'adm-1', 'poLineId' => 'pol-9']);
		$this->grnService->method('addGRNLine')->willThrowException(new \LogicException('grn exploded'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->addLine('grn-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsStringIgnoringCase(
			'grn exploded',
			(string)json_encode($response->getData())
		);

	}//end testAddLineUnexpectedFailureReturns500WithoutStackTrace()

	/**
	 * An anonymous caller cannot pass the quality check (HTTP 401).
	 *
	 * @return void
	 */
	public function testQualityCheckAnonymousReturns401(): void {
		$this->user = null;
		$this->withParams(['administrationId' => 'adm-1']);
		$this->grnService->expects($this->never())->method('qualityCheckPass');

		$response = $this->controller->qualityCheck('grn-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testQualityCheckAnonymousReturns401()

	/**
	 * A valid quality check transitions the GRN and returns HTTP 200.
	 *
	 * @return void
	 */
	public function testQualityCheckValidReturns200(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$grn = ['grnId' => 'grn-1', 'statusCode' => 'quality_checked'];
		$this->grnService->expects($this->once())->method('qualityCheckPass')->willReturn($grn);

		$response = $this->controller->qualityCheck('grn-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('quality_checked', $response->getData()['statusCode']);

	}//end testQualityCheckValidReturns200()

	/**
	 * A "requires statusCode" precondition failure is mapped to HTTP 409.
	 *
	 * @return void
	 */
	public function testQualityCheckWrongStateReturns409(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->grnService->method('qualityCheckPass')->willThrowException(
			new \RuntimeException('quality check requires statusCode received')
		);

		$response = $this->controller->qualityCheck('grn-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testQualityCheckWrongStateReturns409()

	/**
	 * A non-member sees a masked HTTP 404 on the quality-check transition.
	 *
	 * @return void
	 */
	public function testQualityCheckForeignAdministrationReturns404(): void {
		$this->canAccess = false;
		$this->withParams(['administrationId' => 'adm-other']);
		$this->grnService->expects($this->never())->method('qualityCheckPass');

		$response = $this->controller->qualityCheck('grn-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testQualityCheckForeignAdministrationReturns404()

	/**
	 * An anonymous caller cannot accept a GRN (HTTP 401) — acceptance posts
	 * StockMove credits, so it must never run unauthenticated.
	 *
	 * @return void
	 */
	public function testAcceptAnonymousReturns401(): void {
		$this->user = null;
		$this->withParams(['administrationId' => 'adm-1']);
		$this->grnService->expects($this->never())->method('acceptGRN');

		$response = $this->controller->accept('grn-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAcceptAnonymousReturns401()

	/**
	 * A valid acceptance returns HTTP 200 with the updated GRN.
	 *
	 * @return void
	 */
	public function testAcceptValidReturns200(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$grn = ['grnId' => 'grn-1', 'statusCode' => 'accepted', 'stockMoveIds' => ['sm-1', 'sm-2']];
		$this->grnService->expects($this->once())->method('acceptGRN')->willReturn($grn);

		$response = $this->controller->accept('grn-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('accepted', $response->getData()['statusCode']);
		self::assertCount(2, $response->getData()['stockMoveIds']);

	}//end testAcceptValidReturns200()

	/**
	 * Accepting an already-terminal GRN is mapped to HTTP 409, so a double
	 * submit cannot post the stock credits twice.
	 *
	 * @return void
	 */
	public function testAcceptTerminalStateReturns409(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->grnService->method('acceptGRN')->willThrowException(
			new \RuntimeException('GRN is in a terminal state and cannot be accepted')
		);

		$response = $this->controller->accept('grn-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testAcceptTerminalStateReturns409()

	/**
	 * A malformed GRN id is rejected with HTTP 400 on accept.
	 *
	 * @return void
	 */
	public function testAcceptMalformedIdReturns400(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->grnService->expects($this->never())->method('acceptGRN');

		$response = $this->controller->accept('grn 1/../../secret');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testAcceptMalformedIdReturns400()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
