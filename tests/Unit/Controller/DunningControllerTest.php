<?php

/**
 * Unit tests for DunningController.
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\DunningController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BIKStaffelCalculator;
use OCA\Shillinq\Service\Dunning\DunningChannelSendResult;
use OCA\Shillinq\Service\Dunning\IncassoDossierComposer;
use OCA\Shillinq\Service\DunningRunService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the BIK compute endpoint (REQ-CCD-003) and the dunning-run executor
 * (REQ-CCD-002 / REQ-CCD-016).
 *
 * Covers the anonymous rejection, administration-scope validation, the masked
 * cross-tenant 404, the B2C 14-day grace 422 (art. 6:96 BW), body validation
 * and the 409 the executor returns when a DunningPauseDispute blocks the stage.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DunningControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock BIKStaffelCalculator.
	 *
	 * @var BIKStaffelCalculator&MockObject
	 */
	private BIKStaffelCalculator&MockObject $bik;

	/**
	 * Mock DunningRunService.
	 *
	 * @var DunningRunService&MockObject
	 */
	private DunningRunService&MockObject $runs;

	/**
	 * Mock IncassoDossierComposer.
	 *
	 * @var IncassoDossierComposer&MockObject
	 */
	private IncassoDossierComposer&MockObject $dossier;

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
	 * Whether the context grants access to the requested administration —
	 * the default answer when an administration id has no entry in
	 * {@see $canAccessMap}.
	 *
	 * @var boolean
	 */
	private bool $canAccess = true;

	/**
	 * Per-administration-id overrides for canAccess(), so a single test can
	 * grant access to the caller's own administration while denying access
	 * to a DIFFERENT administration a target object actually belongs to
	 * (the resumePause() cross-tenant guard, security-endpoint-guards).
	 * Falls back to {@see $canAccess} for any id not listed here.
	 *
	 * @var array<string,bool>
	 */
	private array $canAccessMap = [];

	/**
	 * In-memory DunningPauseDispute rows, keyed by id, consulted by the
	 * fake ObjectService's find().
	 *
	 * Public: read directly by the anonymous ObjectService stub class
	 * below, which is a distinct class from this TestCase and so cannot
	 * see a private property (mirrors the pattern already used by
	 * ExtractionRequestControllerTest::$stubRows).
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $pauseRecords = [];

	/**
	 * The controller under test.
	 *
	 * @var DunningController
	 */
	private DunningController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->bik = $this->createMock(BIKStaffelCalculator::class);
		$this->runs = $this->createMock(DunningRunService::class);
		$this->dossier = $this->createMock(IncassoDossierComposer::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->context->method('currentUserId')->willReturnCallback(
			function (): ?string {
				return $this->userId;
			}
		);
		$this->context->method('canAccess')->willReturnCallback(
			function (string $administrationId): bool {
				return ($this->canAccessMap[$administrationId] ?? $this->canAccess);
			}
		);

		// The 14-day B2C grace gate is open unless a test closes it.
		$this->bik->method('isCalculationPermitted')->willReturn(true);

		$this->controller = $this->buildController();

	}//end setUp()

	/**
	 * Build a controller wired to the shared mocks, with an ObjectService
	 * double backed by {@see $pauseRecords} (security-endpoint-guards —
	 * resumePause() fetches the target DunningPauseDispute directly).
	 *
	 * @param BIKStaffelCalculator|null $bikOverride Substitute BIK calculator, when a test needs one.
	 *
	 * @return DunningController
	 */
	private function buildController(?BIKStaffelCalculator $bikOverride = null): DunningController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new DunningController(
			request: $this->request,
			bik: ($bikOverride ?? $this->bik),
			runs: $this->runs,
			dossier: $this->dossier,
			context: $this->context,
			logger: $this->logger,
			appConfig: $appConfig,
			objectService: new DuckObjectServiceAdapter($this->makePauseObjectServiceStub()),
			l10n: $this->makeL10n(),
		);

	}//end buildController()

	/**
	 * Build a duck-typed in-memory ObjectService double for
	 * `DunningPauseDispute`, wrapped in {@see DuckObjectServiceAdapter} so
	 * it satisfies the ADR-084 `ObjectServiceInterface` the controller is
	 * now constructed against.
	 *
	 * @return object
	 */
	private function makePauseObjectServiceStub(): object {
		$test = $this;
		return new class($test) {
			/**
			 * Back-reference to the test case.
			 *
			 * @var DunningControllerTest
			 */
			private $test;

			/**
			 * Constructor.
			 *
			 * @param DunningControllerTest $test Test case.
			 */
			public function __construct($test) {
				$this->test = $test;
			}//end __construct()

			/**
			 * No-op fluent register selector.
			 *
			 * @param string $r Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $r): self {
				return $this;
			}//end setRegister()

			/**
			 * No-op fluent schema selector (the stub is single-schema).
			 *
			 * @param string $s Schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $s): self {
				return $this;
			}//end setSchema()

			/**
			 * Single-object lookup by id. THROWS on a miss, matching the real
			 * ObjectService contract.
			 *
			 * @param string $id Object id.
			 *
			 * @return array<string,mixed>
			 *
			 * @throws \RuntimeException When no record matches.
			 */
			public function find(string $id): array {
				if (isset($this->test->pauseRecords[$id]) === true) {
					return $this->test->pauseRecords[$id];
				}

				throw new \RuntimeException(sprintf("Object with identifier '%s' not found", $id));
			}//end find()
		};

	}//end makePauseObjectServiceStub()

	/**
	 * Build a minimal IL10N stub that echoes the string handed to t().
	 *
	 * @return IL10N&MockObject
	 */
	private function makeL10n(): IL10N&MockObject {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $params = []): string => $text
		);
		return $l10n;

	}//end makeL10n()

	/**
	 * Configure request params (getParam and getParams) from one map.
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
		$this->request->method('getParams')->willReturn($map);

	}//end withParams()

	/**
	 * An anonymous caller is rejected with HTTP 401 on the BIK endpoint.
	 *
	 * @return void
	 */
	public function testBikAnonymousReturns401(): void {
		$this->userId = null;
		$this->withParams(['administration_id' => 'adm-1', 'principal' => 1000.0]);
		$this->bik->expects($this->never())->method('compose');

		$response = $this->controller->bik();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testBikAnonymousReturns401()

	/**
	 * A missing administration_id yields HTTP 400.
	 *
	 * @return void
	 */
	public function testBikMissingAdministrationReturns400(): void {
		$this->withParams(['principal' => 1000.0]);

		$response = $this->controller->bik();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());

	}//end testBikMissingAdministrationReturns400()

	/**
	 * A path-traversal administration_id is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testBikMalformedAdministrationReturns400(): void {
		$this->withParams(['administration_id' => '../../etc', 'principal' => 1000.0]);
		$this->bik->expects($this->never())->method('compose');

		$response = $this->controller->bik();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testBikMalformedAdministrationReturns400()

	/**
	 * A non-member sees a masked HTTP 404 (ADR-005, no IDOR).
	 *
	 * @return void
	 */
	public function testBikForeignAdministrationReturns404(): void {
		$this->canAccess = false;
		$this->withParams(['administration_id' => 'adm-other', 'principal' => 1000.0]);
		$this->bik->expects($this->never())->method('compose');

		$response = $this->controller->bik();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testBikForeignAdministrationReturns404()

	/**
	 * A negative hoofdsom yields HTTP 400 — a credit balance carries no
	 * incassokosten.
	 *
	 * @return void
	 */
	public function testBikNegativePrincipalReturns400(): void {
		$this->withParams(['administration_id' => 'adm-1', 'principal' => -50.0]);
		$this->bik->expects($this->never())->method('compose');

		$response = $this->controller->bik();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testBikNegativePrincipalReturns400()

	/**
	 * An unknown partyType yields HTTP 400.
	 *
	 * @return void
	 */
	public function testBikUnknownPartyTypeReturns400(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'principal' => 1000.0,
				'partyType' => 'B2X',
			]
		);
		$this->bik->expects($this->never())->method('compose');

		$response = $this->controller->bik();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testBikUnknownPartyTypeReturns400()

	/**
	 * A B2C debtor still inside the 14-day grace period yields HTTP 422 with
	 * the B2C_GRACE_PERIOD code (art. 6:96 BW), not a computed amount.
	 *
	 * @return void
	 */
	public function testBikB2cInsideGracePeriodReturns422(): void {
		$bik = $this->createMock(BIKStaffelCalculator::class);
		$bik->method('isCalculationPermitted')->willReturn(false);
		$bik->expects($this->never())->method('compose');
		$controller = $this->buildController(bikOverride: $bik);
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'principal' => 500.0,
				'partyType' => 'B2C',
				'dagenVerzuim' => 3,
			]
		);

		$response = $controller->bik();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('B2C_GRACE_PERIOD', $response->getData()['code']);

	}//end testBikB2cInsideGracePeriodReturns422()

	/**
	 * An unparsable effectiveDate yields HTTP 400 rather than a PHP exception.
	 *
	 * @return void
	 */
	public function testBikMalformedEffectiveDateReturns400(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'principal' => 1000.0,
				'effectiveDate' => 'not-a-date',
			]
		);
		$this->bik->expects($this->never())->method('compose');

		$response = $this->controller->bik();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testBikMalformedEffectiveDateReturns400()

	/**
	 * A valid BIK request returns HTTP 200 with the composed calculation and
	 * passes the optional tariff overrides through as floats (REQ-CCD-003).
	 *
	 * @return void
	 */
	public function testBikValidReturns200(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'invoiceId' => 'inv-7',
				'principal' => 2500.0,
				'partyType' => 'B2B',
				'dagenVerzuim' => 60,
				'effectiveDate' => '2026-04-01',
				'calculatedOn' => '2026-06-01',
				'tariefB2B' => '12.5',
			]
		);
		$calculation = [
			'invoiceId' => 'inv-7',
			'administrationId' => 'adm-1',
			'principal' => 2500.0,
			'incassoCosts' => 375.0,
			'interest' => 30.82,
		];
		$seenRateB2B = 'unset';
		$this->bik->method('compose')->willReturnCallback(
			static function (
				string $invoiceId,
				string $administrationId,
				string $partyType,
				float $principal,
				\DateTimeImmutable $effectiveDate,
				\DateTimeImmutable $calculatedOn,
				?float $rateB2B = null
			) use (&$seenRateB2B, $calculation): array {
				$seenRateB2B = $rateB2B;
				return $calculation;
			}
		);

		$response = $this->controller->bik();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($calculation, $response->getData());
		self::assertSame(12.5, $seenRateB2B);

	}//end testBikValidReturns200()

	/**
	 * An anonymous caller cannot execute a dunning stage (HTTP 401).
	 *
	 * @return void
	 */
	public function testExecuteRunAnonymousReturns401(): void {
		$this->userId = null;
		$this->withParams(['administration_id' => 'adm-1', 'invoiceId' => 'inv-7']);
		$this->runs->expects($this->never())->method('executeStage');

		$response = $this->controller->executeRun();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testExecuteRunAnonymousReturns401()

	/**
	 * A missing invoiceId yields HTTP 400.
	 *
	 * @return void
	 */
	public function testExecuteRunMissingInvoiceReturns400(): void {
		$this->withParams(['administration_id' => 'adm-1']);
		$this->runs->expects($this->never())->method('executeStage');

		$response = $this->controller->executeRun();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());

	}//end testExecuteRunMissingInvoiceReturns400()

	/**
	 * A non-member sees a masked HTTP 404 on the run executor.
	 *
	 * @return void
	 */
	public function testExecuteRunForeignAdministrationReturns404(): void {
		$this->canAccess = false;
		$this->withParams(['administration_id' => 'adm-other', 'invoiceId' => 'inv-7']);
		$this->runs->expects($this->never())->method('executeStage');

		$response = $this->controller->executeRun();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testExecuteRunForeignAdministrationReturns404()

	/**
	 * A valid stage execution returns HTTP 201 with the persisted DunningRun
	 * and the server-resolved administration id reaches the orchestrator —
	 * never a client-supplied tenant (REQ-CCD-002).
	 *
	 * @return void
	 */
	public function testExecuteRunValidReturns201(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'invoiceId' => 'inv-7',
				'stage' => 2,
			]
		);
		$persisted = ['runId' => 'run-1', 'invoiceId' => 'inv-7', 'stage' => 2, 'status' => 'sent'];
		$seenAdministration = null;
		$this->runs->method('executeStage')->willReturnCallback(
			static function (string $administrationId, array $params) use (&$seenAdministration, $persisted): array {
				$seenAdministration = $administrationId;
				return $persisted;
			}
		);

		$response = $this->controller->executeRun();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame($persisted, $response->getData());
		self::assertSame('adm-1', $seenAdministration);

	}//end testExecuteRunValidReturns201()

	/**
	 * A stage refused by an active DunningPauseDispute is reported as HTTP 409
	 * (REQ-CCD-016), so the client can distinguish a block from a crash.
	 *
	 * @return void
	 */
	public function testExecuteRunPausedInvoiceReturns409(): void {
		$this->withParams(['administration_id' => 'adm-1', 'invoiceId' => 'inv-7']);
		$this->runs->method('executeStage')->willThrowException(
			new \RuntimeException('Invoice inv-7 has an active dunning pause')
		);

		$response = $this->controller->executeRun();

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		// ADR-050: the response carries a stable slug, never the raw
		// exception text (security-endpoint-guards REQ-003).
		self::assertSame('dunning-run-execution-failed', $response->getData()['error']);
		self::assertStringNotContainsString('inv-7', (string)$response->getData()['message']);

	}//end testExecuteRunPausedInvoiceReturns409()

	/**
	 * An anonymous caller cannot resume a dunning pause.
	 *
	 * @return void
	 */
	public function testResumePauseAnonymousReturns401(): void {
		$this->userId = null;
		$this->withParams(['administration_id' => 'adm-1']);
		$this->runs->expects($this->never())->method('resumePause');

		$response = $this->controller->resumePause('pause-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testResumePauseAnonymousReturns401()

	/**
	 * A caller who is not a member of the request-supplied
	 * administration_id at all sees a masked HTTP 404.
	 *
	 * @return void
	 */
	public function testResumePauseForeignAdministrationParamReturns404(): void {
		$this->canAccess = false;
		$this->withParams(['administration_id' => 'adm-other']);
		$this->runs->expects($this->never())->method('resumePause');

		$response = $this->controller->resumePause('pause-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testResumePauseForeignAdministrationParamReturns404()

	/**
	 * NEGATIVE CONTROL / IDOR (security-endpoint-guards REQ-001): the
	 * caller IS a genuine member of the request-supplied administration_id
	 * (adm-1, so the first canAccess() check passes) but the
	 * DunningPauseDispute named by $pauseId actually belongs to a
	 * DIFFERENT administration (adm-OTHER, which the caller cannot
	 * access). Before this change's guard was added,
	 * DunningRunService::resumePause() never read its own
	 * $administrationId parameter, so this call would have resumed and
	 * returned another organisation's pause. It must now be rejected with
	 * a masked 404, and the service must never be called.
	 *
	 * @return void
	 */
	public function testResumePauseCrossTenantPauseIsRejected(): void {
		$this->canAccessMap = ['adm-1' => true, 'adm-OTHER' => false];
		$this->pauseRecords['pause-1'] = [
			'id' => 'pause-1',
			'administrationId' => 'adm-OTHER',
			'invoiceId' => 'inv-secret',
			'reason' => 'DISPUTED',
		];
		$this->withParams(['administration_id' => 'adm-1']);
		$this->runs->expects($this->never())->method('resumePause');

		$response = $this->controller->resumePause('pause-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testResumePauseCrossTenantPauseIsRejected()

	/**
	 * POSITIVE CONTROL: a member of the pause's OWN administration can
	 * still resume it exactly as before (no regression).
	 *
	 * @return void
	 */
	public function testResumePauseOwnAdministrationSucceeds(): void {
		$this->canAccessMap = ['adm-1' => true];
		$this->pauseRecords['pause-1'] = [
			'id' => 'pause-1',
			'administrationId' => 'adm-1',
			'invoiceId' => 'inv-7',
			'reason' => 'DISPUTED',
		];
		$this->withParams(['administration_id' => 'adm-1', 'resolution' => 'resolve']);
		$updated = ['id' => 'pause-1', 'administrationId' => 'adm-1', 'lifecycleState' => 'resolved'];
		$this->runs->expects($this->once())
			->method('resumePause')
			->with('adm-1', 'pause-1', 'resolve', null)
			->willReturn($updated);

		$response = $this->controller->resumePause('pause-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($updated, $response->getData());

	}//end testResumePauseOwnAdministrationSucceeds()

	/**
	 * A resume failure (e.g. the pause is already resolved) is reported as
	 * a stable slug, never the raw exception text (REQ-003).
	 *
	 * @return void
	 */
	public function testResumePauseServiceFailureReturnsSlug(): void {
		$this->canAccessMap = ['adm-1' => true];
		$this->pauseRecords['pause-1'] = ['id' => 'pause-1', 'administrationId' => 'adm-1'];
		$this->withParams(['administration_id' => 'adm-1']);
		$this->runs->method('resumePause')->willThrowException(
			new \RuntimeException('DunningPauseDispute pause-1 not found.')
		);

		$response = $this->controller->resumePause('pause-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('dunning-pause-not-found', $response->getData()['error']);
		self::assertStringNotContainsString('pause-1', (string)$response->getData()['message']);

	}//end testResumePauseServiceFailureReturnsSlug()

	/**
	 * An anonymous caller cannot dispatch a dossier to a collection agency.
	 *
	 * @return void
	 */
	public function testTransferAnonymousReturns401(): void {
		$this->userId = null;
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'invoiceId' => 'inv-1',
				'customerId' => 'cust-1',
				'dunningRunId' => 'run-1',
			]
		);
		$this->runs->expects($this->never())->method('transferToIncasso');

		$response = $this->controller->transfer();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testTransferAnonymousReturns401()

	/**
	 * An administration the caller cannot access is refused as 404, not 403.
	 *
	 * A 403 would confirm the administration exists and turn the endpoint into
	 * an existence oracle for another tenant's ids; the masked 404 is the house
	 * pattern the sibling dossier endpoint already uses.
	 *
	 * @return void
	 */
	public function testTransferForeignAdministrationReturns404(): void {
		$this->canAccess = false;
		$this->withParams(
			[
				'administration_id' => 'adm-other',
				'invoiceId' => 'inv-1',
				'customerId' => 'cust-1',
				'dunningRunId' => 'run-1',
			]
		);
		$this->runs->expects($this->never())->method('transferToIncasso');
		$this->dossier->expects($this->never())->method('compose');

		$response = $this->controller->transfer();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testTransferForeignAdministrationReturns404()

	/**
	 * A missing dunningRunId is rejected before anything is dispatched.
	 *
	 * Without the run id the service cannot seal the run on a DELIVERED
	 * outcome, so a dossier would reach the bureau with no record sealed
	 * behind it — the transfer must not start at all.
	 *
	 * @return void
	 */
	public function testTransferMissingRunIdReturns400(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'invoiceId' => 'inv-1',
				'customerId' => 'cust-1',
			]
		);
		$this->runs->expects($this->never())->method('transferToIncasso');

		$response = $this->controller->transfer();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testTransferMissingRunIdReturns400()

	/**
	 * The happy path composes the dossier SERVER-SIDE and dispatches it.
	 *
	 * The assertion that matters is that the bundle handed to the bureau is the
	 * one the composer produced, not anything the request carried: this is the
	 * evidence bundle a debt-collection agency acts on.
	 *
	 * @return void
	 */
	public function testTransferComposesServerSideAndReturnsOutcome(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'invoiceId' => 'inv-1',
				'customerId' => 'cust-1',
				'dunningRunId' => 'run-1',
				// A client-supplied dossier must be ignored entirely.
				'dossier' => ['forged' => true],
			]
		);

		$composed = ['invoice' => 'inv-1', 'lines' => []];
		$this->dossier->method('compose')->willReturn($composed);

		$seen = null;
		$this->runs->method('transferToIncasso')->willReturnCallback(
			static function (
				string $administrationId,
				string $invoiceId,
				array $dossier,
				string $dunningRunId
			) use (&$seen): DunningChannelSendResult {
				$seen = $dossier;
				return new DunningChannelSendResult(
					channel: 'incasso',
					deliveryStatus: 'DELIVERED',
					providerMessageId: 'msg-1',
					extras: ['dossierId' => 'dos-1'],
				);
			}
		);

		$response = $this->controller->transfer();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($composed, $seen, 'The dispatched dossier must be the composed one, never the request body');
		self::assertSame('DELIVERED', $response->getData()['deliveryStatus']);
		self::assertSame('dos-1', $response->getData()['extras']['dossierId']);

	}//end testTransferComposesServerSideAndReturnsOutcome()

	/**
	 * A non-DELIVERED outcome is reported as 200 carrying the provider verdict.
	 *
	 * The dispatch attempt itself succeeded; the operator needs the status and
	 * errorMessage to choose between a retry and a manual escalation, and an
	 * HTTP error would hide both.
	 *
	 * @return void
	 */
	public function testTransferSurfacesANonDeliveredOutcome(): void {
		$this->withParams(
			[
				'administration_id' => 'adm-1',
				'invoiceId' => 'inv-1',
				'customerId' => 'cust-1',
				'dunningRunId' => 'run-1',
			]
		);
		$this->dossier->method('compose')->willReturn([]);
		$this->runs->method('transferToIncasso')->willReturn(
			new DunningChannelSendResult(
				channel: 'incasso',
				deliveryStatus: 'FAILED',
				providerMessageId: null,
				extras: [],
				errorMessage: 'bureau rejected the dossier',
			)
		);

		$response = $this->controller->transfer();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('FAILED', $response->getData()['deliveryStatus']);
		self::assertSame('bureau rejected the dossier', $response->getData()['errorMessage']);

	}//end testTransferSurfacesANonDeliveredOutcome()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
