<?php

/**
 * Unit tests for ThreeWayMatchExceptionController.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ThreeWayMatchExceptionController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ExceptionResolutionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the three exception dispositions: accept-with-motivation, dispute and
 * reject-and-block-payment.
 *
 * All three are writes on a match record, so each is exercised for: the
 * anonymous refusal, the required administration scope, the cross-tenant 404
 * mask (ADR-005), the required matchId and free-text reason, the 2000-character
 * notes cap, the service-refusal mapping, and the no-stack-trace 500.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ThreeWayMatchExceptionControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock ExceptionResolutionService (slice 08, server-authoritative).
	 *
	 * @var ExceptionResolutionService&MockObject
	 */
	private ExceptionResolutionService&MockObject $exceptions;

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
	 * @var ThreeWayMatchExceptionController
	 */
	private ThreeWayMatchExceptionController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->exceptions = $this->createMock(ExceptionResolutionService::class);
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

		$this->controller = new ThreeWayMatchExceptionController(
			request: $this->request,
			exceptions: $this->exceptions,
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
	 * Accept-with-motivation returns 200 and forwards scope, match and notes.
	 *
	 * @return void
	 */
	public function testAcceptReturns200WithUpdatedMatch(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'matchId' => 'match-3',
				'resolutionNotes' => '  price rise agreed with supplier  ',
			]
		);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$seen = [];
		$this->exceptions->expects($this->once())
			->method('acceptWithMotivation')
			->willReturnCallback(
				static function (string $administrationId, string $matchId, string $resolutionNotes) use (&$seen): array {
					$seen = [$administrationId, $matchId, $resolutionNotes];
					return ['id' => $matchId, 'status' => 'accepted', 'resolutionNotes' => $resolutionNotes];
				}
			);

		$response = $this->controller->accept();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['adm-1', 'match-3', 'price rise agreed with supplier'], $seen);
		self::assertSame('accepted', $response->getData()['status']);

	}//end testAcceptReturns200WithUpdatedMatch()

	/**
	 * An anonymous accept is refused with 401 and never reaches the service.
	 *
	 * @return void
	 */
	public function testAcceptRejectsAnonymousCaller(): void {
		$this->currentUser = null;
		$this->exceptions->expects($this->never())->method('acceptWithMotivation');

		$response = $this->controller->accept();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not logged in'], $response->getData());

	}//end testAcceptRejectsAnonymousCaller()

	/**
	 * A missing administrationId is a 400 — the disposition is never unscoped.
	 *
	 * @return void
	 */
	public function testAcceptRequiresAdministrationId(): void {
		$this->withParams(['matchId' => 'match-3', 'resolutionNotes' => 'ok']);
		$this->exceptions->expects($this->never())->method('acceptWithMotivation');

		$response = $this->controller->accept();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'administrationId is required'], $response->getData());

	}//end testAcceptRequiresAdministrationId()

	/**
	 * Accepting inside another tenant's administration is masked as 404 (ADR-005).
	 *
	 * @return void
	 */
	public function testAcceptForeignAdministrationReturns404(): void {
		$this->withParams(['administrationId' => 'adm-other', 'matchId' => 'match-3', 'resolutionNotes' => 'ok']);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->exceptions->expects($this->never())->method('acceptWithMotivation');

		$response = $this->controller->accept();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Administration not found'], $response->getData());

	}//end testAcceptForeignAdministrationReturns404()

	/**
	 * A missing matchId is a 400 (a blank id would otherwise reach the service).
	 *
	 * @return void
	 */
	public function testAcceptRequiresMatchId(): void {
		$this->withParams(['administrationId' => 'adm-1', 'resolutionNotes' => 'ok']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->exceptions->expects($this->never())->method('acceptWithMotivation');

		$response = $this->controller->accept();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'matchId is required'], $response->getData());

	}//end testAcceptRequiresMatchId()

	/**
	 * Acceptance without a motivation is refused with 400 — the motivation is the
	 * whole point of the disposition.
	 *
	 * @return void
	 */
	public function testAcceptRequiresResolutionNotes(): void {
		$this->withParams(['administrationId' => 'adm-1', 'matchId' => 'match-3', 'resolutionNotes' => '   ']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->exceptions->expects($this->never())->method('acceptWithMotivation');

		$response = $this->controller->accept();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'resolutionNotes is required'], $response->getData());

	}//end testAcceptRequiresResolutionNotes()

	/**
	 * Over-long motivations are capped at 2000 characters, so the endpoint cannot
	 * be used as a blob store.
	 *
	 * @return void
	 */
	public function testAcceptCapsResolutionNotesAtTwoThousandCharacters(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'matchId' => 'match-3',
				'resolutionNotes' => str_repeat('x', 5000),
			]
		);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$received = '';
		$this->exceptions->method('acceptWithMotivation')->willReturnCallback(
			static function (string $administrationId, string $matchId, string $resolutionNotes) use (&$received): array {
				$received = $resolutionNotes;
				return ['id' => $matchId, 'status' => 'accepted'];
			}
		);

		$response = $this->controller->accept();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(2000, mb_strlen($received));

	}//end testAcceptCapsResolutionNotesAtTwoThousandCharacters()

	/**
	 * An unexpected failure is logged and returns a generic 500 with no trace.
	 *
	 * @return void
	 */
	public function testAcceptUnexpectedFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['administrationId' => 'adm-1', 'matchId' => 'match-3', 'resolutionNotes' => 'ok']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->exceptions->method('acceptWithMotivation')
			->willThrowException(new \LogicException('SQLSTATE[42S02] shillinq_match missing'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->accept();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not record acceptance'], $response->getData());
		self::assertStringNotContainsStringIgnoringCase(
			'SQLSTATE',
			(string)json_encode($response->getData())
		);

	}//end testAcceptUnexpectedFailureReturns500WithoutStackTrace()

	/**
	 * Filing a dispute returns 200 with both the updated match and the credit-note
	 * dispatch record.
	 *
	 * @return void
	 */
	public function testDisputeReturns200WithMatchAndDispatch(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'matchId' => 'match-3',
				'disputeReason' => 'quantity short by 4 units',
			]
		);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$seen = [];
		$this->exceptions->expects($this->once())
			->method('fileDispute')
			->willReturnCallback(
				static function (string $administrationId, string $matchId, string $disputeReason) use (&$seen): array {
					$seen = [$administrationId, $matchId, $disputeReason];
					return [
						'match' => ['id' => $matchId, 'status' => 'disputed'],
						'dispatch' => ['documentType' => 'CreditNoteRequest', 'delivered' => true],
					];
				}
			);

		$response = $this->controller->dispute();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['adm-1', 'match-3', 'quantity short by 4 units'], $seen);
		self::assertSame('disputed', $response->getData()['match']['status']);
		self::assertSame('CreditNoteRequest', $response->getData()['dispatch']['documentType']);

	}//end testDisputeReturns200WithMatchAndDispatch()

	/**
	 * A dispute without a reason is refused with 400.
	 *
	 * @return void
	 */
	public function testDisputeRequiresReason(): void {
		$this->withParams(['administrationId' => 'adm-1', 'matchId' => 'match-3']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->exceptions->expects($this->never())->method('fileDispute');

		$response = $this->controller->dispute();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'disputeReason is required'], $response->getData());

	}//end testDisputeRequiresReason()

	/**
	 * Disputing inside another tenant's administration is masked as 404
	 * (ADR-005) and the exception-resolution service is never reached —
	 * proving the guard short-circuits rather than merely matching the
	 * status code.
	 *
	 * @return void
	 */
	public function testDisputeForeignAdministrationReturns404(): void {
		$this->withParams(['administrationId' => 'adm-other', 'matchId' => 'match-3', 'disputeReason' => 'short']);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->exceptions->expects($this->never())->method('fileDispute');

		$response = $this->controller->dispute();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Administration not found'], $response->getData());

	}//end testDisputeForeignAdministrationReturns404()

	/**
	 * A "not found" refusal from the service maps to 404, not 400.
	 *
	 * @return void
	 */
	public function testDisputeUnknownMatchReturns404(): void {
		$this->withParams(['administrationId' => 'adm-1', 'matchId' => 'match-3', 'disputeReason' => 'short']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->exceptions->method('fileDispute')
			->willThrowException(new \RuntimeException('ThreeWayMatch not found'));

		$response = $this->controller->dispute();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'ThreeWayMatch not found'], $response->getData());

	}//end testDisputeUnknownMatchReturns404()

	/**
	 * Any other service refusal on dispute maps to 400 (validation).
	 *
	 * @return void
	 */
	public function testDisputeServiceRefusalReturns400(): void {
		$this->withParams(['administrationId' => 'adm-1', 'matchId' => 'match-3', 'disputeReason' => 'short']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->exceptions->method('fileDispute')
			->willThrowException(new \RuntimeException('ThreeWayMatch is not in an exception state'));

		$response = $this->controller->dispute();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testDisputeServiceRefusalReturns400()

	/**
	 * An anonymous dispute is refused with 401 and never reaches the service.
	 *
	 * @return void
	 */
	public function testDisputeRejectsAnonymousCaller(): void {
		$this->currentUser = null;
		$this->exceptions->expects($this->never())->method('fileDispute');

		$response = $this->controller->dispute();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testDisputeRejectsAnonymousCaller()

	/**
	 * Rejecting blocks payment and returns 200 with the updated match.
	 *
	 * @return void
	 */
	public function testRejectReturns200WithBlockedMatch(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'matchId' => 'match-3',
				'rejectionReason' => 'goods never delivered',
			]
		);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$seen = [];
		$this->exceptions->expects($this->once())
			->method('rejectAndBlockPayment')
			->willReturnCallback(
				static function (string $administrationId, string $matchId, string $rejectionReason) use (&$seen): array {
					$seen = [$administrationId, $matchId, $rejectionReason];
					return ['id' => $matchId, 'status' => 'rejected', 'paymentBlocked' => true];
				}
			);

		$response = $this->controller->reject();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['adm-1', 'match-3', 'goods never delivered'], $seen);
		self::assertTrue($response->getData()['paymentBlocked']);

	}//end testRejectReturns200WithBlockedMatch()

	/**
	 * A rejection without a reason is refused with 400.
	 *
	 * @return void
	 */
	public function testRejectRequiresReason(): void {
		$this->withParams(['administrationId' => 'adm-1', 'matchId' => 'match-3']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->exceptions->expects($this->never())->method('rejectAndBlockPayment');

		$response = $this->controller->reject();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'rejectionReason is required'], $response->getData());

	}//end testRejectRequiresReason()

	/**
	 * Rejecting inside another tenant's administration is masked as 404 (ADR-005).
	 *
	 * @return void
	 */
	public function testRejectForeignAdministrationReturns404(): void {
		$this->withParams(['administrationId' => 'adm-other', 'matchId' => 'match-3', 'rejectionReason' => 'nope']);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->exceptions->expects($this->never())->method('rejectAndBlockPayment');

		$response = $this->controller->reject();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testRejectForeignAdministrationReturns404()

	/**
	 * A malformed matchId never reaches the service — the slug pattern rejects it
	 * as a missing id, so no forged path can pivot into another record.
	 *
	 * @return void
	 */
	public function testRejectRejectsMalformedMatchId(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'matchId' => '../../match',
				'rejectionReason' => 'nope',
			]
		);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->exceptions->expects($this->never())->method('rejectAndBlockPayment');

		$response = $this->controller->reject();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'matchId is required'], $response->getData());

	}//end testRejectRejectsMalformedMatchId()

	/**
	 * An unexpected failure on reject is logged and returns a generic 500.
	 *
	 * @return void
	 */
	public function testRejectUnexpectedFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['administrationId' => 'adm-1', 'matchId' => 'match-3', 'rejectionReason' => 'nope']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->exceptions->method('rejectAndBlockPayment')
			->willThrowException(new \LogicException('SQLSTATE[42S02] shillinq_match missing'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->reject();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not record rejection'], $response->getData());
		self::assertStringNotContainsStringIgnoringCase(
			'SQLSTATE',
			(string)json_encode($response->getData())
		);

	}//end testRejectUnexpectedFailureReturns500WithoutStackTrace()

	/**
	 * An anonymous reject is refused with 401 and never reaches the service.
	 *
	 * @return void
	 */
	public function testRejectRejectsAnonymousCaller(): void {
		$this->currentUser = null;
		$this->exceptions->expects($this->never())->method('rejectAndBlockPayment');

		$response = $this->controller->reject();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testRejectRejectsAnonymousCaller()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
