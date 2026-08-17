<?php

/**
 * Unit tests for KorController.
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
 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\KorController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\KorMonitorService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the read-only KOR drempel-bewaking endpoint (REQ-KOR-002, REQ-KOR-003).
 *
 * Covers the anonymous rejection, the two parameter-validation rejections, the
 * masked cross-tenant 404 (ADR-005 IDOR safety), the happy path and the 500
 * fail path that leaks no stack trace.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class KorControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock KorMonitorService.
	 *
	 * @var KorMonitorService&MockObject
	 */
	private KorMonitorService&MockObject $monitorService;

	/**
	 * Mock AdministrationContextService.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The user id the context resolves to; null means anonymous.
	 *
	 * @var string|null
	 */
	private ?string $userId = 'alice';

	/**
	 * Whether the context grants access to the requested administration.
	 *
	 * @var boolean
	 */
	private bool $canAccess = true;

	/**
	 * The controller under test.
	 *
	 * @var KorController
	 */
	private KorController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->monitorService = $this->createMock(KorMonitorService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->context->method('currentUserId')->willReturnCallback(
			function (): ?string {
				return $this->userId;
			}
		);
		$this->context->method('canAccess')->willReturnCallback(
			function (): bool {
				return $this->canAccess;
			}
		);

		$this->controller = new KorController(
			request: $this->request,
			korMonitorService: $this->monitorService,
			context: $this->context,
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
	 * An anonymous caller is rejected with HTTP 401 before any service call.
	 *
	 * @return void
	 */
	public function testMonitorAnonymousReturns401(): void {
		$this->userId = null;
		$this->withParams(['administration_id' => 'adm-1']);
		$this->monitorService->expects($this->never())->method('status');

		$response = $this->controller->monitor();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testMonitorAnonymousReturns401()

	/**
	 * A missing administration_id yields HTTP 400 (REQ-KOR-002).
	 *
	 * @return void
	 */
	public function testMonitorMissingAdministrationReturns400(): void {
		$this->withParams([]);

		$response = $this->controller->monitor();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());

	}//end testMonitorMissingAdministrationReturns400()

	/**
	 * A path-traversal administration_id is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testMonitorMalformedAdministrationReturns400(): void {
		$this->withParams(['administration_id' => '../../etc/passwd']);
		$this->monitorService->expects($this->never())->method('status');

		$response = $this->controller->monitor();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMonitorMalformedAdministrationReturns400()

	/**
	 * A non-member sees a masked HTTP 404, not a 403 oracle (ADR-005).
	 *
	 * @return void
	 */
	public function testMonitorForeignAdministrationReturns404(): void {
		$this->canAccess = false;
		$this->withParams(['administration_id' => 'adm-someone-else']);
		$this->monitorService->expects($this->never())->method('status');

		$response = $this->controller->monitor();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testMonitorForeignAdministrationReturns404()

	/**
	 * A non four-digit year is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testMonitorMalformedYearReturns400(): void {
		$this->withParams(['administration_id' => 'adm-1', 'year' => '26']);
		$this->monitorService->expects($this->never())->method('status');

		$response = $this->controller->monitor();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMonitorMalformedYearReturns400()

	/**
	 * A valid request returns HTTP 200 with the drempel-status payload
	 * (REQ-KOR-002, REQ-KOR-003).
	 *
	 * @return void
	 */
	public function testMonitorValidReturns200(): void {
		$this->withParams(['administration_id' => 'adm-1', 'year' => '2026']);
		$payload = [
			'administrationId' => 'adm-1',
			'year' => 2026,
			'revenue' => 14500.0,
			'threshold' => 20000.0,
			'utilisation' => 0.725,
			'alertBand' => 'none',
			'monthly' => [],
			'forecast' => 17400.0,
		];
		$this->monitorService->expects($this->once())
			->method('status')
			->willReturn($payload);

		$response = $this->controller->monitor();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testMonitorValidReturns200()

	/**
	 * An omitted year defaults to the current calendar year.
	 *
	 * @return void
	 */
	public function testMonitorDefaultsToCurrentYear(): void {
		$this->withParams(['administration_id' => 'adm-1']);
		$seen = null;
		$this->monitorService->method('status')->willReturnCallback(
			static function (string $administrationId, int $year) use (&$seen): array {
				$seen = $year;
				return ['administrationId' => $administrationId, 'year' => $year];
			}
		);

		$response = $this->controller->monitor();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame((int)date('Y'), $seen);

	}//end testMonitorDefaultsToCurrentYear()

	/**
	 * A service failure yields HTTP 500 and leaks no stack trace (ADR-005).
	 *
	 * @return void
	 */
	public function testMonitorServiceFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['administration_id' => 'adm-1', 'year' => '2026']);
		$this->monitorService->method('status')->willThrowException(new \RuntimeException('ledger exploded'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->monitor();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsStringIgnoringCase(
			'ledger exploded',
			(string)json_encode($response->getData())
		);

	}//end testMonitorServiceFailureReturns500WithoutStackTrace()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
