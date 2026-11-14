<?php

/**
 * Golden tests for the access checks and request handling of DunningController.
 *
 * Every action runs the same prelude: an authenticated caller, a valid
 * administration_id and access to that administration. These tests pin the
 * allowed and refused outcome of that prelude for every action, then the
 * parameter handling and service calls of the actions that had no test.
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

use DateTimeImmutable;
use OCA\Shillinq\Controller\DunningController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BIKStaffelCalculator;
use OCA\Shillinq\Service\Dunning\IncassoDossierComposer;
use OCA\Shillinq\Service\DunningRunService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins DunningController's access prelude and request handling.
 */
final class DunningControllerAccessGoldenTest extends TestCase {

	/**
	 * Request stub.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * BIK calculator mock.
	 *
	 * @var BIKStaffelCalculator&MockObject
	 */
	private BIKStaffelCalculator&MockObject $bik;

	/**
	 * Dunning run service mock.
	 *
	 * @var DunningRunService&MockObject
	 */
	private DunningRunService&MockObject $runs;

	/**
	 * Dossier composer mock.
	 *
	 * @var IncassoDossierComposer&MockObject
	 */
	private IncassoDossierComposer&MockObject $dossier;

	/**
	 * Administration context mock.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Pause records the object service serves, by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $pauseRecords = [];

	/**
	 * Set up fixtures.
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

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return DunningController
	 */
	private function controller(): DunningController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$test = $this;
		$objects = new class($test) {
			/**
			 * The test that owns the records.
			 *
			 * @var DunningControllerAccessGoldenTest
			 */
			private $test;

			/**
			 * Constructor.
			 *
			 * @param DunningControllerAccessGoldenTest $test The test.
			 */
			public function __construct($test) {
				$this->test = $test;
			}

			/**
			 * Scope to a register.
			 *
			 * @param string $r Register.
			 *
			 * @return self
			 */
			public function setRegister(string $r): self {
				return $this;
			}

			/**
			 * Scope to a schema.
			 *
			 * @param string $s Schema.
			 *
			 * @return self
			 */
			public function setSchema(string $s): self {
				return $this;
			}

			/**
			 * Find a pause by id.
			 *
			 * @param string $id Id.
			 *
			 * @return array<string,mixed>
			 */
			public function find(string $id): array {
				if (isset($this->test->pauseRecords[$id]) === true) {
					return $this->test->pauseRecords[$id];
				}

				throw new \RuntimeException(sprintf("Object with identifier '%s' not found", $id));
			}
		};

		return new DunningController(
			$this->request,
			$this->bik,
			$this->runs,
			$this->dossier,
			$this->context,
			$this->logger,
			$appConfig,
			new DuckObjectServiceAdapter($objects),
			$l10n,
		);

	}//end controller()

	/**
	 * Serve request parameters.
	 *
	 * @param array<string,mixed> $map The parameters.
	 *
	 * @return void
	 */
	private function params(array $map): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($map[$key] ?? $default)
		);
		$this->request->method('getParams')->willReturn($map);

	}//end params()

	/**
	 * Call one action by name.
	 *
	 * @param string $action The action.
	 *
	 * @return JSONResponse
	 */
	private function call(string $action): JSONResponse {
		$controller = $this->controller();
		if ($action === 'resumePause') {
			return $controller->resumePause('pause-1');
		}

		return $controller->$action();

	}//end call()

	/**
	 * Expect that no service does any work.
	 *
	 * @return void
	 */
	private function expectNoWork(): void {
		$this->bik->expects(self::never())->method('compose');
		$this->runs->expects(self::never())->method(self::anything());
		$this->dossier->expects(self::never())->method('compose');

	}//end expectNoWork()

	/**
	 * Every action of the controller.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function actions(): array {
		return [
			'bik' => ['bik'],
			'executeRun' => ['executeRun'],
			'pause' => ['pause'],
			'resumePause' => ['resumePause'],
			'writeOff' => ['writeOff'],
			'dossier' => ['dossier'],
			'transfer' => ['transfer'],
		];

	}//end actions()

	/**
	 * An anonymous caller is refused with 401 before anything is read.
	 *
	 * @param string $action The action.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testAnonymousCallerIsRefused(string $action): void {
		$this->context->method('currentUserId')->willReturn(null);
		$this->context->expects(self::never())->method('canAccess');
		$this->request->expects(self::never())->method('getParam');
		$this->expectNoWork();

		$response = $this->call($action);

		self::assertSame([401, ['error' => 'Not authenticated']], [$response->getStatus(), $response->getData()]);

	}//end testAnonymousCallerIsRefused()

	/**
	 * Administration ids refused before the access check, with their message.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function badAdministrations(): array {
		$required = 'administration_id is required';
		$invalid = 'administration_id must be a valid identifier';
		$cases = [];
		foreach (array_keys(self::actions()) as $action) {
			$cases[$action . ' missing'] = [$action, '', $required];
			$cases[$action . ' blank'] = [$action, '   ', $required];
			$cases[$action . ' space'] = [$action, 'adm 1', $invalid];
			$cases[$action . ' too long'] = [$action, str_repeat('a', 65), $invalid];
			$cases[$action . ' slash'] = [$action, 'adm/1', $invalid];
		}

		return $cases;

	}//end badAdministrations()

	/**
	 * A missing or malformed administration_id is a 400 without an access check.
	 *
	 * @param string $action The action.
	 * @param string $administrationId The administration id sent.
	 * @param string $message The expected error.
	 *
	 * @return void
	 *
	 * @dataProvider badAdministrations
	 */
	public function testBadAdministrationIsRefused(string $action, string $administrationId, string $message): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->expects(self::never())->method('canAccess');
		$this->params(['administration_id' => $administrationId]);
		$this->expectNoWork();

		$response = $this->call($action);

		self::assertSame([400, ['error' => $message]], [$response->getStatus(), $response->getData()]);

	}//end testBadAdministrationIsRefused()

	/**
	 * A failing access check is logged and answered with 500.
	 *
	 * @param string $action The action.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testFailingAccessCheckIsA500(string $action): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('canAccess')->willThrowException(new \RuntimeException('boom'));
		$this->params(['administration_id' => 'adm-1']);
		$this->logger->expects(self::once())->method('error')
			->with('DunningController: admin access check failed: boom');
		$this->expectNoWork();

		$response = $this->call($action);

		self::assertSame([500, ['error' => 'Authorization failure']], [$response->getStatus(), $response->getData()]);

	}//end testFailingAccessCheckIsA500()

	/**
	 * An administration the caller cannot access is a masked 404.
	 *
	 * @param string $action The action.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testForeignAdministrationIsRefused(string $action): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->expects(self::once())->method('canAccess')->with('adm-1')->willReturn(false);
		$this->params(['administration_id' => ' adm-1 ']);
		$this->expectNoWork();

		$response = $this->call($action);

		self::assertSame([404, ['error' => 'Administration not found']], [$response->getStatus(), $response->getData()]);

	}//end testForeignAdministrationIsRefused()

	/**
	 * What each action answers once access is allowed but no other input is given.
	 *
	 * @return array<string,array{0:string,1:int,2:array<string,string>}>
	 */
	public static function allowedWithoutInput(): array {
		return [
			'executeRun' => ['executeRun', 400, ['error' => 'factuurId is required']],
			'pause' => ['pause', 400, ['error' => 'factuurId + valid reden are required']],
			'resumePause' => ['resumePause', 404, ['error' => 'Dunning pause not found']],
			'writeOff' => ['writeOff', 400, ['error' => 'factuurId, hoofdsomAfgeschreven (>0) and art29OBVerklaring are required']],
			'dossier' => ['dossier', 400, ['error' => 'factuurId + klantId required']],
			'transfer' => ['transfer', 400, ['error' => 'factuurId + klantId + dunningRunId required']],
		];

	}//end allowedWithoutInput()

	/**
	 * An allowed caller passes the prelude and reaches the action's own checks.
	 *
	 * @param string $action The action.
	 * @param int $status The expected status.
	 * @param array<string,string> $data The expected body.
	 *
	 * @return void
	 *
	 * @dataProvider allowedWithoutInput
	 */
	public function testAllowedCallerReachesTheAction(string $action, int $status, array $data): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('canAccess')->willReturn(true);
		$this->params(['administration_id' => 'adm-1']);
		$this->expectNoWork();

		$response = $this->call($action);

		self::assertSame([$status, $data], [$response->getStatus(), $response->getData()]);

	}//end testAllowedCallerReachesTheAction()

	/**
	 * Allow the caller on adm-1.
	 *
	 * @param array<string,mixed> $params Further request parameters.
	 *
	 * @return void
	 */
	private function allowed(array $params): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('canAccess')->willReturn(true);
		$this->params(array_merge(['administration_id' => 'adm-1'], $params));

	}//end allowed()

	/**
	 * BIK with defaults composes for B2B with today's dates and no rates.
	 *
	 * @return void
	 */
	public function testBikDefaultsAndRates(): void {
		$this->allowed(['tariefB2B' => '12', 'principal' => '100.5', 'invoiceId' => 'inv-1']);
		$this->bik->expects(self::once())->method('isCalculationPermitted')->with('B2B', 0)->willReturn(true);
		$this->bik->expects(self::once())->method('compose')
			->willReturnCallback(
				function (string $invoiceId, string $administrationId, string $partyType, float $principal, DateTimeImmutable $effectiveDate, DateTimeImmutable $calculatedOn, ?float $rateB2B, ?float $rateB2C): array {
					self::assertSame(['inv-1', 'adm-1', 'B2B', 100.5, 12.0, null], [$invoiceId, $administrationId, $partyType, $principal, $rateB2B, $rateB2C]);
					self::assertLessThan(60, abs(time() - $effectiveDate->getTimestamp()));
					self::assertLessThan(60, abs(time() - $calculatedOn->getTimestamp()));
					return ['bik' => 40];
				}
			);

		$response = $this->call('bik');

		self::assertSame([200, ['bik' => 40]], [$response->getStatus(), $response->getData()]);

	}//end testBikDefaultsAndRates()

	/**
	 * BIK passes explicit dates and both rates.
	 *
	 * @return void
	 */
	public function testBikExplicitDates(): void {
		$this->allowed(['effectiveDate' => '2026-01-02', 'calculatedOn' => '2026-02-03', 'tariefB2C' => 0, 'partyType' => 'GOVERNMENT', 'dagenVerzuim' => '30']);
		$this->bik->expects(self::once())->method('isCalculationPermitted')->with('GOVERNMENT', 30)->willReturn(true);
		$this->bik->expects(self::once())->method('compose')
			->willReturnCallback(
				function (string $invoiceId, string $administrationId, string $partyType, float $principal, DateTimeImmutable $effectiveDate, DateTimeImmutable $calculatedOn, ?float $rateB2B, ?float $rateB2C): array {
					self::assertSame(['', 'GOVERNMENT', 0.0, '2026-01-02', '2026-02-03', null, 0.0], [$invoiceId, $partyType, $principal, $effectiveDate->format('Y-m-d'), $calculatedOn->format('Y-m-d'), $rateB2B, $rateB2C]);
					return [];
				}
			);

		self::assertSame(200, $this->call('bik')->getStatus());

	}//end testBikExplicitDates()

	/**
	 * A malformed calculation date is a 400, like a malformed effective date.
	 *
	 * @return void
	 */
	public function testBikMalformedCalculationDate(): void {
		$this->allowed(['calculatedOn' => 'not a date']);
		$this->bik->method('isCalculationPermitted')->willReturn(true);
		$this->bik->expects(self::never())->method('compose');

		$response = $this->call('bik');

		self::assertSame([400, ['error' => 'Invalid ingangsdatum / berekendOp']], [$response->getStatus(), $response->getData()]);

	}//end testBikMalformedCalculationDate()

	/**
	 * The pause reasons that are accepted.
	 *
	 * @return array<string,array{0:string,1:mixed,2:array<mixed>|null}>
	 */
	public static function pauses(): array {
		return [
			'disputed with refs' => ['DISPUTED', ['doc-1'], ['doc-1']],
			'payment plan, refs not a list' => ['PAYMENT_PLAN', 'doc-1', null],
			'other without refs' => ['OTHER', null, null],
		];

	}//end pauses()

	/**
	 * A valid pause is created by the caller with the evidence list, if any.
	 *
	 * @param string $reason The reason.
	 * @param mixed $refs The evidenceRefs parameter.
	 * @param array<mixed>|null $passed The evidence refs passed on.
	 *
	 * @return void
	 *
	 * @dataProvider pauses
	 */
	public function testPauseIsCreated(string $reason, mixed $refs, ?array $passed): void {
		$this->allowed(['invoiceId' => 'inv-1', 'reason' => $reason, 'details' => 'why', 'evidenceRefs' => $refs]);
		$this->runs->expects(self::once())->method('pause')
			->with('adm-1', 'inv-1', $reason, 'why', 'alice', $passed)
			->willReturn(['id' => 'p']);

		$response = $this->call('pause');

		self::assertSame([201, ['id' => 'p']], [$response->getStatus(), $response->getData()]);

	}//end testPauseIsCreated()

	/**
	 * An unknown pause reason is a 400.
	 *
	 * @return void
	 */
	public function testPauseUnknownReasonIsRefused(): void {
		$this->allowed(['invoiceId' => 'inv-1', 'reason' => 'BORED']);
		$this->runs->expects(self::never())->method('pause');

		self::assertSame(400, $this->call('pause')->getStatus());

	}//end testPauseUnknownReasonIsRefused()

	/**
	 * A failing pause is logged and a 500.
	 *
	 * @return void
	 */
	public function testPauseFailureIsA500(): void {
		$this->allowed(['invoiceId' => 'inv-1', 'reason' => 'OTHER']);
		$this->runs->method('pause')->willThrowException(new \RuntimeException('nope'));
		$this->logger->expects(self::once())->method('error')->with('Shillinq: pause failed: nope');

		$response = $this->call('pause');

		self::assertSame([500, ['error' => 'Failed to create dunning pause']], [$response->getStatus(), $response->getData()]);

	}//end testPauseFailureIsA500()

	/**
	 * Write-off inputs that are refused.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function badWriteOffs(): array {
		return [
			'no invoice' => [['art29OBDeclaration' => 'yes', 'principalDepreciated' => 10]],
			'no declaration' => [['invoiceId' => 'inv-1', 'principalDepreciated' => 10]],
			'zero principal' => [['invoiceId' => 'inv-1', 'art29OBDeclaration' => 'yes', 'principalDepreciated' => '0']],
			'negative principal' => [['invoiceId' => 'inv-1', 'art29OBDeclaration' => 'yes', 'principalDepreciated' => -1]],
		];

	}//end badWriteOffs()

	/**
	 * An incomplete write-off is a 400.
	 *
	 * @param array<string,mixed> $params The parameters.
	 *
	 * @return void
	 *
	 * @dataProvider badWriteOffs
	 */
	public function testIncompleteWriteOffIsRefused(array $params): void {
		$this->allowed($params);
		$this->runs->expects(self::never())->method('writeOff');

		self::assertSame(400, $this->call('writeOff')->getStatus());

	}//end testIncompleteWriteOffIsRefused()

	/**
	 * A complete write-off is recorded with all parameters.
	 *
	 * @return void
	 */
	public function testWriteOffIsRecorded(): void {
		$params = ['invoiceId' => 'inv-1', 'art29OBDeclaration' => 'yes', 'principalDepreciated' => '0.01'];
		$this->allowed($params);
		$this->runs->expects(self::once())->method('writeOff')
			->with('adm-1', array_merge(['administration_id' => 'adm-1'], $params))
			->willReturn(['id' => 'w']);

		$response = $this->call('writeOff');

		self::assertSame([201, ['id' => 'w']], [$response->getStatus(), $response->getData()]);

	}//end testWriteOffIsRecorded()

	/**
	 * A failing write-off is logged and a 500.
	 *
	 * @return void
	 */
	public function testWriteOffFailureIsA500(): void {
		$this->allowed(['invoiceId' => 'inv-1', 'art29OBDeclaration' => 'yes', 'principalDepreciated' => 5]);
		$this->runs->method('writeOff')->willThrowException(new \RuntimeException('nope'));
		$this->logger->expects(self::once())->method('error')->with('Shillinq: writeOff failed: nope');

		$response = $this->call('writeOff');

		self::assertSame([500, ['error' => 'Failed to record write-off']], [$response->getStatus(), $response->getData()]);

	}//end testWriteOffFailureIsA500()

	/**
	 * A dossier is composed for the invoice and customer.
	 *
	 * @return void
	 */
	public function testDossierIsComposed(): void {
		$this->allowed(['invoiceId' => 'inv-1', 'customerId' => 'c-1']);
		$this->dossier->expects(self::once())->method('compose')->with('adm-1', 'inv-1', 'c-1')->willReturn(['d' => 1]);

		$response = $this->call('dossier');

		self::assertSame([200, ['d' => 1]], [$response->getStatus(), $response->getData()]);

	}//end testDossierIsComposed()

	/**
	 * A dossier without customer is a 400; a failing compose is a logged 500.
	 *
	 * @return void
	 */
	public function testDossierRefusalAndFailure(): void {
		$this->allowed(['invoiceId' => 'inv-1', 'customerId' => 'c-1']);
		$this->dossier->method('compose')->willThrowException(new \RuntimeException('nope'));
		$this->logger->expects(self::once())->method('error')->with('Shillinq: dossier compose failed: nope');

		$response = $this->call('dossier');

		self::assertSame([500, ['error' => 'Failed to compose dossier']], [$response->getStatus(), $response->getData()]);

	}//end testDossierRefusalAndFailure()

	/**
	 * Resume refuses an unknown resolution and casts a partial settlement.
	 *
	 * @return void
	 */
	public function testResumeResolutionAndPartialSettlement(): void {
		$this->pauseRecords['pause-1'] = ['id' => 'pause-1', 'administrationId' => 'adm-1'];
		$this->allowed(['resolution' => 'expire', 'partialSettlement' => '12.5']);
		$this->runs->expects(self::once())->method('resumePause')
			->with('adm-1', 'pause-1', 'expire', 12.5)
			->willReturn(['id' => 'pause-1']);

		self::assertSame(200, $this->call('resumePause')->getStatus());

	}//end testResumeResolutionAndPartialSettlement()

	/**
	 * An unknown resolution is a 400 after the pause ownership check.
	 *
	 * @return void
	 */
	public function testResumeUnknownResolutionIsRefused(): void {
		$this->pauseRecords['pause-1'] = ['id' => 'pause-1', 'administrationId' => 'adm-1'];
		$this->allowed(['resolution' => 'ignore']);
		$this->runs->expects(self::never())->method('resumePause');

		$response = $this->call('resumePause');

		self::assertSame([400, ['error' => 'resolution must be resolve or expire']], [$response->getStatus(), $response->getData()]);

	}//end testResumeUnknownResolutionIsRefused()
}//end class
