<?php

/**
 * Unit tests for SoftCloseController.
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
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-22
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\SoftCloseController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FluxService;
use OCA\Shillinq\Service\SoftCloseExecutor;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the Tier-2 continuous-close endpoints (REQ-CLS-002, REQ-CLS-005,
 * REQ-CLS-007).
 *
 * executeNow and executeFlux both WRITE into an administration's ledger, so the
 * membership guard is the only thing standing between tenants — it is asserted
 * here for both, along with slug/period validation, the failed-run 500, the
 * three narrative render formats, and the no-stack-trace error contract
 * (ADR-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SoftCloseControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock SoftCloseExecutor (the orchestration service).
	 *
	 * @var SoftCloseExecutor&MockObject
	 */
	private SoftCloseExecutor&MockObject $softCloseExecutor;

	/**
	 * Mock FluxService (analysis + narrative rendering).
	 *
	 * @var FluxService&MockObject
	 */
	private FluxService&MockObject $fluxService;

	/**
	 * Mock AdministrationContextService (membership guard, REQ-MA-001).
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
	 * The user the session reports; null models an anonymous caller.
	 *
	 * @var IUser|null
	 */
	private ?IUser $currentUser = null;

	/**
	 * The controller under test.
	 *
	 * @var SoftCloseController
	 */
	private SoftCloseController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->softCloseExecutor = $this->createMock(SoftCloseExecutor::class);
		$this->fluxService = $this->createMock(FluxService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
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

		$this->controller = new SoftCloseController(
			request: $this->request,
			softCloseExecutor: $this->softCloseExecutor,
			fluxService: $this->fluxService,
			context: $this->context,
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
	 * A completed soft-close run returns 200 with the run report under `data`.
	 *
	 * @return void
	 */
	public function testExecuteNowReturns200WithRunReport(): void {
		$this->withParams(['periodId' => '2026-07']);
		$this->context->method('canAccess')->willReturn(true);

		$seen = [];
		$this->softCloseExecutor->expects($this->once())
			->method('execute')
			->willReturnCallback(
				static function (string $administrationId, string $periodId, \DateTimeImmutable $asOf) use (&$seen): array {
					$seen = [$administrationId, $periodId];
					return ['status' => 'completed', 'administrationId' => $administrationId, 'periodId' => $periodId, 'steps' => []];
				}
			);

		$response = $this->controller->executeNow('adm-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['adm-1', '2026-07'], $seen);
		self::assertSame('completed', $response->getData()['data']['status']);

	}//end testExecuteNowReturns200WithRunReport()

	/**
	 * Omitting periodId defaults to the current month rather than failing.
	 *
	 * @return void
	 */
	public function testExecuteNowDefaultsPeriodToCurrentMonth(): void {
		$this->withParams([]);
		$this->context->method('canAccess')->willReturn(true);

		$seen = '';
		$this->softCloseExecutor->method('execute')->willReturnCallback(
			static function (string $administrationId, string $periodId, \DateTimeImmutable $asOf) use (&$seen): array {
				$seen = $periodId;
				return ['status' => 'completed'];
			}
		);

		$response = $this->controller->executeNow('adm-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame((new \DateTimeImmutable())->format('Y-m'), $seen);

	}//end testExecuteNowDefaultsPeriodToCurrentMonth()

	/**
	 * A run that reports `failed` is surfaced as 500 with the report intact.
	 *
	 * @return void
	 */
	public function testExecuteNowFailedRunReturns500(): void {
		$this->withParams(['periodId' => '2026-07']);
		$this->context->method('canAccess')->willReturn(true);
		$this->softCloseExecutor->method('execute')->willReturn(['status' => 'failed', 'steps' => []]);

		$response = $this->controller->executeNow('adm-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('failed', $response->getData()['data']['status']);

	}//end testExecuteNowFailedRunReturns500()

	/**
	 * An anonymous caller is refused with 401 and no ledger write is attempted.
	 *
	 * @return void
	 */
	public function testExecuteNowRejectsAnonymousCaller(): void {
		$this->currentUser = null;
		$this->softCloseExecutor->expects($this->never())->method('execute');

		$response = $this->controller->executeNow('adm-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Authentication required'], $response->getData());

	}//end testExecuteNowRejectsAnonymousCaller()

	/**
	 * A malformed administration slug is rejected with 400 before the guard runs.
	 *
	 * @return void
	 */
	public function testExecuteNowRejectsMalformedAdministrationId(): void {
		$this->softCloseExecutor->expects($this->never())->method('execute');

		$response = $this->controller->executeNow('../../adm');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'administration_id must be a valid identifier'], $response->getData());

	}//end testExecuteNowRejectsMalformedAdministrationId()

	/**
	 * Another tenant's administration is masked as 404 — never 403, which would
	 * make the endpoint a tenant-enumeration oracle.
	 *
	 * @return void
	 */
	public function testExecuteNowForeignAdministrationReturns404(): void {
		$this->context->method('canAccess')->willReturn(false);
		$this->softCloseExecutor->expects($this->never())->method('execute');

		$response = $this->controller->executeNow('adm-other');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Administration not found'], $response->getData());

	}//end testExecuteNowForeignAdministrationReturns404()

	/**
	 * A period that is not yyyy-mm is rejected with 400.
	 *
	 * @return void
	 */
	public function testExecuteNowRejectsMalformedPeriod(): void {
		$this->withParams(['periodId' => 'July 2026']);
		$this->context->method('canAccess')->willReturn(true);
		$this->softCloseExecutor->expects($this->never())->method('execute');

		$response = $this->controller->executeNow('adm-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'period_id must match yyyy-mm'], $response->getData());

	}//end testExecuteNowRejectsMalformedPeriod()

	/**
	 * An executor crash is logged and returns a generic 500 with no stack trace.
	 *
	 * @return void
	 */
	public function testExecuteNowServiceCrashReturns500WithoutStackTrace(): void {
		$this->withParams(['periodId' => '2026-07']);
		$this->context->method('canAccess')->willReturn(true);
		$this->softCloseExecutor->method('execute')
			->willThrowException(new \RuntimeException('SQLSTATE[42S02] shillinq_journal missing'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->executeNow('adm-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'An unexpected error occurred'], $response->getData());
		self::assertStringNotContainsStringIgnoringCase(
			'SQLSTATE',
			(string)json_encode($response->getData())
		);

	}//end testExecuteNowServiceCrashReturns500WithoutStackTrace()

	/**
	 * A valid flux run returns 200 and forwards every run input to FluxService.
	 *
	 * @return void
	 */
	public function testExecuteFluxReturns200WithRunSummary(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'periodId' => '2026-07',
				'scope' => 'costCenter',
				'comparisonBasis' => 'priorPeriod',
				'accounts' => [['accountId' => '4000', 'actualCents' => 120000]],
				'materialityPolicy' => ['absoluteCents' => 50000],
			]
		);
		$this->context->method('canAccess')->willReturn(true);

		$seen = [];
		$this->fluxService->expects($this->once())
			->method('run')
			->willReturnCallback(
				static function (array $inputs) use (&$seen): array {
					$seen = $inputs;
					return ['fluxRunId' => 'flux-1', 'status' => 'complete', 'itemCount' => 1];
				}
			);

		$response = $this->controller->executeFlux();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('adm-1', $seen['administrationId']);
		self::assertSame('2026-07', $seen['periodId']);
		self::assertSame('costCenter', $seen['scope']);
		self::assertSame('priorPeriod', $seen['comparisonBasis']);
		self::assertSame(['absoluteCents' => 50000], $seen['materialityPolicy']);
		self::assertInstanceOf(\DateTimeImmutable::class, $seen['runTimestamp']);
		self::assertSame('flux-1', $response->getData()['data']['fluxRunId']);

	}//end testExecuteFluxReturns200WithRunSummary()

	/**
	 * Scope and comparison basis fall back to their documented defaults.
	 *
	 * @return void
	 */
	public function testExecuteFluxAppliesDocumentedDefaults(): void {
		$this->withParams(['administrationId' => 'adm-1', 'periodId' => '2026-07']);
		$this->context->method('canAccess')->willReturn(true);

		$seen = [];
		$this->fluxService->method('run')->willReturnCallback(
			static function (array $inputs) use (&$seen): array {
				$seen = $inputs;
				return ['fluxRunId' => 'flux-2'];
			}
		);

		$response = $this->controller->executeFlux();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('administration', $seen['scope']);
		self::assertSame('budget', $seen['comparisonBasis']);
		self::assertSame([], $seen['accounts']);

	}//end testExecuteFluxAppliesDocumentedDefaults()

	/**
	 * A missing administrationId is a 400 — a flux run is never unscoped.
	 *
	 * @return void
	 */
	public function testExecuteFluxRequiresAdministrationId(): void {
		$this->withParams(['periodId' => '2026-07']);
		$this->fluxService->expects($this->never())->method('run');

		$response = $this->controller->executeFlux();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'administration_id is required'], $response->getData());

	}//end testExecuteFluxRequiresAdministrationId()

	/**
	 * Another tenant's administration is masked as 404 on the flux endpoint too.
	 *
	 * @return void
	 */
	public function testExecuteFluxForeignAdministrationReturns404(): void {
		$this->withParams(['administrationId' => 'adm-other', 'periodId' => '2026-07']);
		$this->context->method('canAccess')->willReturn(false);
		$this->fluxService->expects($this->never())->method('run');

		$response = $this->controller->executeFlux();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testExecuteFluxForeignAdministrationReturns404()

	/**
	 * A malformed period is rejected with 400 on the flux endpoint.
	 *
	 * @return void
	 */
	public function testExecuteFluxRejectsMalformedPeriod(): void {
		$this->withParams(['administrationId' => 'adm-1', 'periodId' => '2026']);
		$this->context->method('canAccess')->willReturn(true);
		$this->fluxService->expects($this->never())->method('run');

		$response = $this->controller->executeFlux();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'period_id must match yyyy-mm'], $response->getData());

	}//end testExecuteFluxRejectsMalformedPeriod()

	/**
	 * A FluxService crash is logged and returns a generic 500 with no trace.
	 *
	 * @return void
	 */
	public function testExecuteFluxServiceCrashReturns500WithoutStackTrace(): void {
		$this->withParams(['administrationId' => 'adm-1', 'periodId' => '2026-07']);
		$this->context->method('canAccess')->willReturn(true);
		$this->fluxService->method('run')->willThrowException(new \RuntimeException('division by zero in basis'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->executeFlux();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsStringIgnoringCase(
			'division by zero',
			(string)json_encode($response->getData())
		);

	}//end testExecuteFluxServiceCrashReturns500WithoutStackTrace()

	/**
	 * The default narrative format is JSON, returned under `data`.
	 *
	 * @return void
	 */
	public function testNarrativeDefaultsToJson(): void {
		$this->withParams(['periodId' => '2026-07', 'items' => [['accountId' => '4000']]]);

		$seen = [];
		$this->fluxService->expects($this->once())
			->method('buildNarrative')
			->willReturnCallback(
				static function (array $items, string $periodId) use (&$seen): array {
					$seen = [$items, $periodId];
					return ['periodId' => $periodId, 'sections' => ['summary' => 'no material variances']];
				}
			);
		$this->fluxService->expects($this->never())->method('renderNarrativeMarkdown');

		$response = $this->controller->narrative('flux-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['accountId' => '4000']], $seen[0]);
		self::assertSame('2026-07', $seen[1]);
		self::assertSame('2026-07', $response->getData()['data']['periodId']);

	}//end testNarrativeDefaultsToJson()

	/**
	 * The markdown format returns the rendered body as a text/markdown DataResponse.
	 *
	 * @return void
	 */
	public function testNarrativeMarkdownReturnsMarkdownBody(): void {
		$this->withParams(['format' => 'MarkDown', 'periodId' => '2026-07']);
		$this->fluxService->method('buildNarrative')->willReturn(['periodId' => '2026-07']);
		$this->fluxService->expects($this->once())
			->method('renderNarrativeMarkdown')
			->willReturn("# Flux 2026-07\n\nNo material variances.\n");

		$response = $this->controller->narrative('flux-1');

		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertStringContainsString('# Flux 2026-07', (string)$response->getData());
		self::assertSame('text/markdown; charset=utf-8', $response->getHeaders()['Content-Type']);

	}//end testNarrativeMarkdownReturnsMarkdownBody()

	/**
	 * The pdf format returns the rendered PDF body with the pdf content type.
	 *
	 * @return void
	 */
	public function testNarrativePdfReturnsPdfBody(): void {
		$this->withParams(['format' => 'pdf', 'periodId' => '2026-07']);
		$this->fluxService->method('buildNarrative')->willReturn(['periodId' => '2026-07']);
		$this->fluxService->expects($this->once())
			->method('renderNarrativePdfBody')
			->willReturn('%PDF-1.4 body');

		$response = $this->controller->narrative('flux-1');

		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame('%PDF-1.4 body', $response->getData());
		self::assertSame('application/pdf', $response->getHeaders()['Content-Type']);

	}//end testNarrativePdfReturnsPdfBody()

	/**
	 * An unsupported format is a 400 rather than a silent JSON fallback.
	 *
	 * @return void
	 */
	public function testNarrativeUnsupportedFormatReturns400(): void {
		$this->withParams(['format' => 'docx', 'periodId' => '2026-07']);
		$this->fluxService->method('buildNarrative')->willReturn(['periodId' => '2026-07']);

		$response = $this->controller->narrative('flux-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'format must be json, markdown or pdf'], $response->getData());

	}//end testNarrativeUnsupportedFormatReturns400()

	/**
	 * A malformed flux-run id is rejected with 400 before any narrative is built.
	 *
	 * @return void
	 */
	public function testNarrativeRejectsMalformedRunId(): void {
		$this->fluxService->expects($this->never())->method('buildNarrative');

		$response = $this->controller->narrative('../../flux');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'flux_run_id must be a valid identifier'], $response->getData());

	}//end testNarrativeRejectsMalformedRunId()

	/**
	 * An anonymous narrative request is refused with 401.
	 *
	 * @return void
	 */
	public function testNarrativeRejectsAnonymousCaller(): void {
		$this->currentUser = null;
		$this->fluxService->expects($this->never())->method('buildNarrative');

		$response = $this->controller->narrative('flux-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testNarrativeRejectsAnonymousCaller()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
