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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
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
	 * Whether the context grants access to the requested administration.
	 *
	 * @var boolean
	 */
	private bool $canAccess = true;

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
			function (): bool {
				return $this->canAccess;
			}
		);

		// The 14-day B2C grace gate is open unless a test closes it.
		$this->bik->method('isCalculationPermitted')->willReturn(true);

		$this->controller = new DunningController(
			request: $this->request,
			bik: $this->bik,
			runs: $this->runs,
			dossier: $this->dossier,
			context: $this->context,
			logger: $this->logger,
		);

	}//end setUp()

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
		$controller = new DunningController(
			request: $this->request,
			bik: $bik,
			runs: $this->runs,
			dossier: $this->dossier,
			context: $this->context,
			logger: $this->logger,
		);
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
		self::assertStringContainsString('active dunning pause', (string)$response->getData()['error']);

	}//end testExecuteRunPausedInvoiceReturns409()

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
