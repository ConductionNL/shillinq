<?php

/**
 * Unit tests for ProgrammabegrotingController.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-30
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ProgrammabegrotingController;
use OCA\Shillinq\Service\ProgrammabegrotingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the two routed read endpoints of the tier-2 programmabegroting API:
 * the computed sluitend-status (REQ-011) and the taakveld-aggregated iv3
 * export (REQ-012).
 *
 * Asserts the 401 body-guard, the slug validation on both identifiers
 * (IDOR-safe input), the 200 payload shape and the 500 fail path that leaks
 * no stack trace (ADR-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ProgrammabegrotingControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock ProgrammabegrotingService.
	 *
	 * @var ProgrammabegrotingService&MockObject
	 */
	private ProgrammabegrotingService&MockObject $service;

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
	 * Set up shared fixtures — authenticated by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(ProgrammabegrotingService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

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
	 * @return ProgrammabegrotingController
	 */
	private function controller(): ProgrammabegrotingController {
		return new ProgrammabegrotingController(
			$this->request,
			$this->service,
			$this->userSession,
			$this->logger,
		);

	}//end controller()

	/**
	 * sluitend() returns the computed status verbatim with HTTP 200 (REQ-011).
	 *
	 * @return void
	 */
	public function testSluitendReturns200WithComputedStatus(): void {
		$this->withParams(['begroting_id' => 'beg-2026', 'administration_id' => 'adm-1']);
		$status = [
			'budgetId' => 'beg-2026',
			'sluitend' => false,
			'saldo' => -12500.0,
			'totalBaten' => 1000000.0,
			'totalLasten' => 1012500.0,
		];
		$this->service->expects($this->once())
			->method('sluitendStatus')
			->willReturn($status);

		$response = $this->controller()->sluitend();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($status, $response->getData());

	}//end testSluitendReturns200WithComputedStatus()

	/**
	 * sluitend() passes the two validated identifiers through in the right
	 * order (administration first, budget second).
	 *
	 * @return void
	 */
	public function testSluitendPassesValidatedIdentifiersToService(): void {
		$this->withParams(['begroting_id' => 'beg-2026', 'administration_id' => 'adm-7']);
		$this->service->expects($this->once())
			->method('sluitendStatus')
			->willReturnCallback(
				static function (string $administrationId, string $budgetId): array {
					self::assertSame('adm-7', $administrationId);
					self::assertSame('beg-2026', $budgetId);
					return ['sluitend' => true];
				}
			);

		$response = $this->controller()->sluitend();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['sluitend']);

	}//end testSluitendPassesValidatedIdentifiersToService()

	/**
	 * sluitend() rejects an anonymous caller with HTTP 401 before validating
	 * parameters.
	 *
	 * @return void
	 */
	public function testSluitendAnonymousReturns401(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->withParams(['begroting_id' => 'beg-2026', 'administration_id' => 'adm-1']);
		$this->service->expects($this->never())->method('sluitendStatus');

		$response = $this->controller()->sluitend();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testSluitendAnonymousReturns401()

	/**
	 * A missing begroting_id yields HTTP 400 on the sluitend endpoint.
	 *
	 * @return void
	 */
	public function testSluitendMissingBegrotingIdReturns400(): void {
		$this->withParams(['administration_id' => 'adm-1']);
		$this->service->expects($this->never())->method('sluitendStatus');

		$response = $this->controller()->sluitend();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testSluitendMissingBegrotingIdReturns400()

	/**
	 * iv3() wraps the taakveld rows in a `data` envelope with HTTP 200
	 * (REQ-012).
	 *
	 * @return void
	 */
	public function testIv3Returns200WithDataEnvelope(): void {
		$this->withParams(['begroting_id' => 'beg-2026', 'administration_id' => 'adm-1']);
		$rows = [
			['taakveld' => '0.1', 'omschrijving' => 'Bestuur', 'lasten' => 250000.0, 'baten' => 0.0],
			['taakveld' => '6.1', 'omschrijving' => 'Samenkracht', 'lasten' => 90000.0, 'baten' => 12000.0],
		];
		$this->service->expects($this->once())
			->method('iv3Export')
			->willReturn($rows);

		$response = $this->controller()->iv3();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['data' => $rows], $response->getData());

	}//end testIv3Returns200WithDataEnvelope()

	/**
	 * A path-traversal administration_id is rejected with HTTP 400 on the iv3
	 * endpoint (IDOR-safe input validation).
	 *
	 * @return void
	 */
	public function testIv3MalformedAdministrationIdReturns400(): void {
		$this->withParams(['begroting_id' => 'beg-2026', 'administration_id' => '../../etc/passwd']);
		$this->service->expects($this->never())->method('iv3Export');

		$response = $this->controller()->iv3();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testIv3MalformedAdministrationIdReturns400()

	/**
	 * iv3() rejects an anonymous caller with HTTP 401.
	 *
	 * @return void
	 */
	public function testIv3AnonymousReturns401(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->withParams(['begroting_id' => 'beg-2026', 'administration_id' => 'adm-1']);
		$this->service->expects($this->never())->method('iv3Export');

		$response = $this->controller()->iv3();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testIv3AnonymousReturns401()

	/**
	 * A service failure on the iv3 endpoint yields HTTP 500 with the generic
	 * message only — no stack trace, no exception text (ADR-005).
	 *
	 * @return void
	 */
	public function testIv3ServiceFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['begroting_id' => 'beg-2026', 'administration_id' => 'adm-1']);
		$this->service->method('iv3Export')
			->willThrowException(new \RuntimeException('SQLSTATE[42P01] relation missing'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller()->iv3();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('Failed to produce iv3 export', $response->getData()['error']);
		self::assertStringNotContainsStringIgnoringCase(
			'SQLSTATE',
			(string)json_encode($response->getData())
		);

	}//end testIv3ServiceFailureReturns500WithoutStackTrace()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
