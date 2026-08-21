<?php

/**
 * Unit tests for ReconciliationResolutionController.
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
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ReconciliationResolutionController;
use OCA\Shillinq\Service\ReconciliationResolutionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the bulk unmatched-item resolution endpoint (REQ-REC-008).
 *
 * Asserts the payload validation (classification allow-list + mandatory
 * audit reason), the authenticated-session guard, the per-id failure
 * isolation that must not short-circuit the batch, and the actor stamping
 * that comes from the session rather than the request body.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ReconciliationResolutionControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock ReconciliationResolutionService.
	 *
	 * @var ReconciliationResolutionService&MockObject
	 */
	private ReconciliationResolutionService&MockObject $service;

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
	 * Mock IL10N.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

	/**
	 * Set up shared fixtures — authenticated as `alice` by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(ReconciliationResolutionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

	}//end setUp()

	/**
	 * Configure the request params from a key => value map.
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
	 * Build the controller over the current mocks.
	 *
	 * @return ReconciliationResolutionController
	 */
	private function controller(): ReconciliationResolutionController {
		return new ReconciliationResolutionController(
			$this->request,
			$this->service,
			$this->userSession,
			$this->logger,
			$this->l10n,
		);

	}//end controller()

	/**
	 * bulkResolve() applies the same classification + reason to every id and
	 * reports the applied count with HTTP 200 (REQ-REC-008).
	 *
	 * @return void
	 */
	public function testBulkResolveAppliesClassificationToEveryMatch(): void {
		$this->withParams(
			[
				'matchIds' => ['m-1', 'm-2', 'm-3'],
				'resolutionStatus' => 'timing',
				'resolutionReason' => 'cheque cleared next period',
			]
		);
		$seen = [];
		$this->service->expects($this->exactly(3))
			->method('resolveMatch')
			->willReturnCallback(
				static function (
					string $reconId,
					string $matchId,
					string $resolutionStatus,
					string $resolutionReason,
					string $actor
				) use (&$seen): array {
					self::assertSame('rec-1', $reconId);
					self::assertSame('timing', $resolutionStatus);
					self::assertSame('cheque cleared next period', $resolutionReason);
					self::assertSame('alice', $actor);
					$seen[] = $matchId;
					return ['id' => $matchId, 'resolutionStatus' => $resolutionStatus];
				}
			);

		$response = $this->controller()->bulkResolve('rec-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['applied' => 3, 'failed' => []], $response->getData());
		self::assertSame(['m-1', 'm-2', 'm-3'], $seen);

	}//end testBulkResolveAppliesClassificationToEveryMatch()

	/**
	 * A failure on one id is surfaced under `failed` without short-circuiting
	 * the rest of the batch — and the surfaced text is the localized generic
	 * message for the exception *type*, not the raw exception message.
	 *
	 * The raw text is only written to the server log. Contract changed
	 * deliberately: this map previously carried `$e->getMessage()` verbatim
	 * inside a 200 body, which is the same information-leak class already
	 * closed across ~30 other endpoints in this app.
	 *
	 * @return void
	 */
	public function testBulkResolveIsolatesPerIdFailures(): void {
		$this->withParams(
			[
				'matchIds' => ['m-1', 'm-locked', 'm-3'],
				'resolutionStatus' => 'matched',
				'resolutionReason' => 'manual tie-out',
			]
		);
		$this->service->method('resolveMatch')->willReturnCallback(
			static function (
				string $reconId,
				string $matchId,
				string $resolutionStatus,
				string $resolutionReason,
				string $actor
			): array {
				if ($matchId === 'm-locked') {
					throw new \DomainException('reconciliation rec-1 is closed');
				}

				return ['id' => $matchId];
			}
		);

		$logged = [];
		$this->logger->expects($this->once())
			->method('error')
			->willReturnCallback(
				static function (mixed $message, array $context = []) use (&$logged): void {
					$logged = $context;
				}
			);

		$response = $this->controller()->bulkResolve('rec-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame(2, $data['applied']);
		self::assertSame(
			['m-locked' => 'Unable to resolve a match on a locked reconciliation'],
			$data['failed']
		);

		// The raw service text must not survive anywhere in the 200 body.
		self::assertStringNotContainsString(
			'reconciliation rec-1 is closed',
			(string)json_encode($data)
		);

		// ...but it must still be recoverable from the server log.
		self::assertSame('reconciliation rec-1 is closed', $logged['exception']);
		self::assertSame('m-locked', $logged['matchId']);

	}//end testBulkResolveIsolatesPerIdFailures()

	/**
	 * A missing match inside a batch reports the generic not-found message and
	 * leaks neither the raw text nor the internals it carries.
	 *
	 * @return void
	 */
	public function testBulkResolveMasksMissingMatchText(): void {
		$this->withParams(
			[
				'matchIds' => ['m-gone'],
				'resolutionStatus' => 'timing',
				'resolutionReason' => 'in transit',
			]
		);
		$this->service->method('resolveMatch')->willThrowException(
			new \OutOfBoundsException('ReconciliationMatch m-gone absent from register 42')
		);

		$this->logger->expects($this->once())->method('error');

		$response = $this->controller()->bulkResolve('rec-1');

		$data = $response->getData();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(0, $data['applied']);
		self::assertSame(
			['m-gone' => 'Unable to find the reconciliation match'],
			$data['failed']
		);
		self::assertStringNotContainsString('register 42', (string)json_encode($data));

	}//end testBulkResolveMasksMissingMatchText()

	/**
	 * An unexpected infrastructure failure (the shape that carries SQL state,
	 * class names and file paths) is reduced to a generic message before it
	 * reaches the 200 body.
	 *
	 * @return void
	 */
	public function testBulkResolveMasksUnexpectedFailureText(): void {
		$this->withParams(
			[
				'matchIds' => ['m-1'],
				'resolutionStatus' => 'adjustment',
				'resolutionReason' => 'write-off',
			]
		);
		$this->service->method('resolveMatch')->willThrowException(
			new \RuntimeException(
				'SQLSTATE[42S02]: Base table oc_shillinq_recon_match missing in /var/www/lib/Db/Mapper.php'
			)
		);

		$this->logger->expects($this->once())->method('error');

		$response = $this->controller()->bulkResolve('rec-1');

		$data = $response->getData();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(0, $data['applied']);
		self::assertSame(['m-1' => 'Unable to resolve this match'], $data['failed']);

		$encoded = (string)json_encode($data);
		self::assertStringNotContainsStringIgnoringCase('SQLSTATE', $encoded);
		self::assertStringNotContainsStringIgnoringCase('/var/www', $encoded);
		self::assertStringNotContainsStringIgnoringCase('oc_shillinq_recon_match', $encoded);

	}//end testBulkResolveMasksUnexpectedFailureText()

	/**
	 * Blank ids inside the array are skipped without counting as applied or
	 * failed.
	 *
	 * @return void
	 */
	public function testBulkResolveSkipsBlankIds(): void {
		$this->withParams(
			[
				'matchIds' => ['m-1', '   ', ''],
				'resolutionStatus' => 'pending',
				'resolutionReason' => 'awaiting supplier',
			]
		);
		$this->service->expects($this->once())
			->method('resolveMatch')
			->willReturn(['id' => 'm-1']);

		$response = $this->controller()->bulkResolve('rec-1');

		self::assertSame(['applied' => 1, 'failed' => []], $response->getData());

	}//end testBulkResolveSkipsBlankIds()

	/**
	 * An empty reconId (path parameter) is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testBulkResolveMissingReconIdReturns400(): void {
		$this->withParams([]);
		$this->service->expects($this->never())->method('resolveMatch');

		$response = $this->controller()->bulkResolve('   ');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('reconId is required', $response->getData()['error']);

	}//end testBulkResolveMissingReconIdReturns400()

	/**
	 * A non-array matchIds payload is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testBulkResolveNonArrayMatchIdsReturns400(): void {
		$this->withParams(
			[
				'matchIds' => 'm-1',
				'resolutionStatus' => 'matched',
				'resolutionReason' => 'manual tie-out',
			]
		);
		$this->service->expects($this->never())->method('resolveMatch');

		$response = $this->controller()->bulkResolve('rec-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('matchIds must be a non-empty array', $response->getData()['error']);

	}//end testBulkResolveNonArrayMatchIdsReturns400()

	/**
	 * A classification outside the REQ-REC-004 allow-list is rejected with
	 * HTTP 400 — the endpoint uses an allow-list, not a deny-list.
	 *
	 * @return void
	 */
	public function testBulkResolveRejectsUnknownClassification(): void {
		$this->withParams(
			[
				'matchIds' => ['m-1'],
				'resolutionStatus' => 'written-off',
				'resolutionReason' => 'manager said so',
			]
		);
		$this->service->expects($this->never())->method('resolveMatch');

		$response = $this->controller()->bulkResolve('rec-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('resolutionStatus must be one of', $response->getData()['error']);

	}//end testBulkResolveRejectsUnknownClassification()

	/**
	 * The audit reason is mandatory — a blank one is HTTP 400 (REQ-REC-004).
	 *
	 * @return void
	 */
	public function testBulkResolveRequiresAuditReason(): void {
		$this->withParams(
			[
				'matchIds' => ['m-1'],
				'resolutionStatus' => 'adjustment',
				'resolutionReason' => '   ',
			]
		);
		$this->service->expects($this->never())->method('resolveMatch');

		$response = $this->controller()->bulkResolve('rec-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('resolutionReason is required', $response->getData()['error']);

	}//end testBulkResolveRequiresAuditReason()

	/**
	 * An unauthenticated caller with an otherwise valid payload is refused —
	 * the guard throws rather than writing with a 'system' actor.
	 *
	 * @return void
	 */
	public function testBulkResolveWithoutSessionIsForbidden(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->withParams(
			[
				'matchIds' => ['m-1'],
				'resolutionStatus' => 'matched',
				'resolutionReason' => 'manual tie-out',
			]
		);
		$this->service->expects($this->never())->method('resolveMatch');

		$this->expectException(OCSForbiddenException::class);

		$this->controller()->bulkResolve('rec-1');

	}//end testBulkResolveWithoutSessionIsForbidden()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
