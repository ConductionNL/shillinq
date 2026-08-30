<?php

/**
 * Unit tests for DBAController.
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
 * @spec openspec/specs/dba-compliance-marker/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\DBAController;
use OCA\Shillinq\Enums\DBAConstants;
use OCA\Shillinq\Guard\DBAOpdrachtGuard;
use OCA\Shillinq\Guard\DBAScoreCalculator;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\DBAVbarMonitorService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stream wrapper that replaces the built-in `php://` protocol so a test can
 * feed a real request body to DBAController::jsonBody(), which reads
 * `php://input` directly and therefore cannot be reached through the IRequest
 * mock. Registered only for the duration of one controller call and always
 * restored (see DBAControllerTest::withBody()).
 */
final class DBAPhpInputStream {

	/**
	 * The body served for the next `php://input` read.
	 *
	 * @var string
	 */
	public static string $body = '';

	/**
	 * Stream context, assigned by the stream layer.
	 *
	 * @var resource|null
	 */
	public $context = null;

	/**
	 * Read cursor into self::$body.
	 *
	 * @var int
	 */
	private int $position = 0;

	/**
	 * Open the stream.
	 *
	 * @param string      $path       The requested path.
	 * @param string      $mode       The open mode.
	 * @param int         $options    Stream options.
	 * @param string|null $openedPath Out-param for the opened path.
	 *
	 * @return bool Always true.
	 */
	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->position = 0;
		return true;

	}//end stream_open()

	/**
	 * Read up to $count bytes.
	 *
	 * @param int $count Maximum byte count.
	 *
	 * @return string The chunk read.
	 */
	public function stream_read(int $count): string {
		$chunk = substr(self::$body, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;

	}//end stream_read()

	/**
	 * Whether the cursor reached the end of the body.
	 *
	 * @return bool True at end of body.
	 */
	public function stream_eof(): bool {
		return ($this->position >= strlen(self::$body));

	}//end stream_eof()

	/**
	 * Current cursor position.
	 *
	 * @return int The byte offset.
	 */
	public function stream_tell(): int {
		return $this->position;

	}//end stream_tell()

	/**
	 * Seeking is not supported; readers fall back to sequential reads.
	 *
	 * @param int $offset Byte offset.
	 * @param int $whence Seek origin.
	 *
	 * @return bool Always false.
	 */
	public function stream_seek(int $offset, int $whence = SEEK_SET): bool {
		return false;

	}//end stream_seek()

	/**
	 * Minimal stat record.
	 *
	 * @return array<int|string,int> Empty stat data.
	 */
	public function stream_stat(): array {
		return [];

	}//end stream_stat()

	/**
	 * Stream options are not supported.
	 *
	 * @param int $option The option.
	 * @param int $arg1   First argument.
	 * @param int $arg2   Second argument.
	 *
	 * @return bool Always false.
	 */
	public function stream_set_option(int $option, int $arg1, int $arg2): bool {
		return false;

	}//end stream_set_option()

	/**
	 * Close the stream.
	 *
	 * @return void
	 */
	public function stream_close(): void {

	}//end stream_close()
}//end class

/**
 * Fluent stand-in for OCA\OpenRegister\Service\ObjectService as DBAController
 * uses it: setRegister()->setSchema()->find()/findAll(), plus a recording
 * saveObject() invoked with NAMED arguments.
 */
final class FakeDBAObjectService {

	/**
	 * Seed rows per schema slug.
	 *
	 * @var array<string, list<array<string,mixed>>>
	 */
	private array $rows;

	/**
	 * Recorded saves, in call order.
	 *
	 * @var array<int, array{schema:string,register:string,object:array<string,mixed>}>
	 */
	public array $saves = [];

	/**
	 * Schemas whose saveObject() must throw.
	 *
	 * @var list<string>
	 */
	public array $failingSchemas = [];

	/**
	 * The currently selected schema slug.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * The currently selected register slug.
	 *
	 * @var string
	 */
	private string $register = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, list<array<string,mixed>>> $rows Seed rows per schema.
	 */
	public function __construct(array $rows = []) {
		$this->rows = $rows;

	}//end __construct()

	/**
	 * Select the register.
	 *
	 * @param string $register Register slug.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		$this->register = $register;
		return $this;

	}//end setRegister()

	/**
	 * Select the schema.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;

	}//end setSchema()

	/**
	 * Find one seeded row by id.
	 *
	 * @param string $id The object id.
	 *
	 * @return array<string,mixed>|null The row, or null when absent.
	 */
	public function find(string $id): ?array {
		foreach (($this->rows[$this->schema] ?? []) as $row) {
			if (((string)($row['id'] ?? '')) === $id) {
				return $row;
			}
		}

		return null;

	}//end find()

	/**
	 * Return seeded rows matching the query's equality filters.
	 *
	 * @param array<string,mixed> $query Query with filters + limit.
	 *
	 * @return list<array<string,mixed>> The matching rows.
	 */
	public function findAll(array $query): array {
		$filters = ($query['filters'] ?? []);
		$limit   = (int)($query['limit'] ?? 0);
		$out     = [];
		foreach (($this->rows[$this->schema] ?? []) as $row) {
			$ok = true;
			foreach ($filters as $key => $value) {
				if (((string)($row[$key] ?? '')) !== (string)$value) {
					$ok = false;
					break;
				}
			}

			if ($ok === true) {
				$out[] = $row;
			}

			if ($limit > 0 && count($out) >= $limit) {
				break;
			}
		}

		return $out;

	}//end findAll()

	/**
	 * Record a save and echo the payload back with an id.
	 *
	 * @param array<string,mixed> $object   The payload.
	 * @param string              $register Register slug.
	 * @param string              $schema   Schema slug.
	 *
	 * @return array<string,mixed> The persisted payload.
	 */
	public function saveObject(array $object, string $register, string $schema): array {
		if (in_array($schema, $this->failingSchemas, true) === true) {
			throw new \RuntimeException('save refused for ' . $schema);
		}

		$this->saves[] = [
			'schema'   => $schema,
			'register' => $register,
			'object'   => $object,
		];

		return array_merge(['id' => ($object['id'] ?? ('new-' . $schema))], $object);

	}//end saveObject()

	/**
	 * Return the saves recorded for one schema.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return list<array<string,mixed>> The saved payloads.
	 */
	public function savedFor(string $schema): array {
		$out = [];
		foreach ($this->saves as $save) {
			if ($save['schema'] === $schema) {
				$out[] = $save['object'];
			}
		}

		return $out;

	}//end savedFor()
}//end class

/**
 * Tests the DBA compliance-marker endpoint façade.
 *
 * Covers the ten routed endpoints: input validation (400), missing-object
 * (404), OpenRegister-unavailable (503), unauthenticated (401), the persisted
 * write shape for each mutation, and the audit-report payload + PDF content
 * negotiation.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DBAControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock DI container serving the OpenRegister ObjectService.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock score calculator guard.
	 *
	 * @var DBAScoreCalculator&MockObject
	 */
	private DBAScoreCalculator&MockObject $scoreCalc;

	/**
	 * Mock save-precondition guard.
	 *
	 * @var DBAOpdrachtGuard&MockObject
	 */
	private DBAOpdrachtGuard&MockObject $assignmentGuard;

	/**
	 * Mock VBAR monitor service.
	 *
	 * @var DBAVbarMonitorService&MockObject
	 */
	private DBAVbarMonitorService&MockObject $vbarMonitor;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The OpenRegister fake handed to the controller.
	 *
	 * @var FakeDBAObjectService
	 */
	private FakeDBAObjectService $objectService;

	/**
	 * Administration-membership seam (security-endpoint-guards REQ-001).
	 * Defaults in setUp() to granting 'adm-1' — every seeded fixture in
	 * this file uses that administration id — so the pre-existing
	 * positive-path tests keep passing once `ensureAdministrationAccess()`
	 * actually enforces membership instead of unconditionally logging.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $administrationContext;

	/**
	 * Group manager stub — non-admin by default in setUp() so the
	 * membership check is genuinely exercised by the pre-existing tests;
	 * see testSetTussenkomstModeByAdminBypassesMembershipCheck() for the
	 * admin-bypass positive control.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Whether the container should fail to resolve the ObjectService.
	 *
	 * @var bool
	 */
	private bool $openRegisterDown = false;

	/**
	 * Request headers served to the controller.
	 *
	 * @var array<string,string>
	 */
	private array $headers = [];

	/**
	 * The controller under test.
	 *
	 * @var DBAController
	 */
	private DBAController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request         = $this->createMock(IRequest::class);
		$this->container       = $this->createMock(ContainerInterface::class);
		$this->appConfig       = $this->createMock(IAppConfig::class);
		$this->userSession     = $this->createMock(IUserSession::class);
		$this->scoreCalc       = $this->createMock(DBAScoreCalculator::class);
		$this->assignmentGuard = $this->createMock(DBAOpdrachtGuard::class);
		$this->vbarMonitor     = $this->createMock(DBAVbarMonitorService::class);
		$this->logger          = $this->createMock(LoggerInterface::class);
		$this->objectService   = new FakeDBAObjectService();
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->administrationContext->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-1'
		);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturn(false);

		$this->request->method('getHeader')->willReturnCallback(
			function (string $name): string {
				return ($this->headers[$name] ?? '');
			}
		);

		$this->container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($this->openRegisterDown === true) {
					throw new \RuntimeException('OpenRegister app is not installed');
				}

				return $this->objectService;
			}
		);

		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return $default;
			}
		);

		$this->signIn('alice');

		$this->controller = new DBAController(
			request: $this->request,
			container: $this->container,
			appConfig: $this->appConfig,
			userSession: $this->userSession,
			scoreCalc: $this->scoreCalc,
			assignmentGuard: $this->assignmentGuard,
			vbarMonitor: $this->vbarMonitor,
			logger: $this->logger,
			administrationContext: $this->administrationContext,
			groupManager: $this->groupManager,
		);

	}//end setUp()

	/**
	 * Restore the built-in php:// wrapper if a test failed mid-call.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		DBAPhpInputStream::$body = '';
		parent::tearDown();

	}//end tearDown()

	/**
	 * Bind the session to a user, or to nobody when $uid is null.
	 *
	 * @param string|null $uid The user id, or null for an anonymous session.
	 *
	 * @return void
	 */
	private function signIn(?string $uid): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end signIn()

	/**
	 * Run a controller call with $body served as the raw `php://input` payload.
	 *
	 * DBAController::jsonBody() reads php://input directly, so the only way to
	 * exercise anything past its validation branch is to swap the php:// stream
	 * wrapper for the duration of the call. The built-in wrapper is always
	 * restored.
	 *
	 * @param array<string,mixed> $body The request body.
	 * @param callable            $call The controller invocation.
	 *
	 * @return mixed The controller's return value.
	 */
	private function withBody(array $body, callable $call): mixed {
		DBAPhpInputStream::$body = (string)json_encode($body);
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', DBAPhpInputStream::class);

		try {
			return $call();
		} finally {
			stream_wrapper_restore('php');
			DBAPhpInputStream::$body = '';
		}

	}//end withBody()

	/**
	 * Seed the OpenRegister fake with rows per schema.
	 *
	 * @param array<string, list<array<string,mixed>>> $rows Rows per schema.
	 *
	 * @return void
	 */
	private function seed(array $rows): void {
		$this->objectService = new FakeDBAObjectService($rows);

	}//end seed()

	/**
	 * A canonical DBAOpdracht row.
	 *
	 * @return array<string,mixed> The opdracht payload.
	 */
	private function assignmentRow(): array {
		return [
			'id'               => 'opd-1',
			'administrationId' => 'adm-1',
			'intakeStatus'     => 'INTAKE_PENDING',
		];

	}//end assignmentRow()

	/*
	 * ---------------------------------------------------------------------
	 * scoreIntake()
	 * ---------------------------------------------------------------------
	 */

	/**
	 * scoreIntake is fail-closed: an anonymous session gets HTTP 401 and the
	 * calculator is never reached.
	 *
	 * @return void
	 */
	public function testScoreIntakeRejectsAnonymousSession(): void {
		$controller = $this->anonymousController();
		$this->scoreCalc->expects($this->never())->method('computeTotal');

		$response = $controller->scoreIntake();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testScoreIntakeRejectsAnonymousSession()

	/**
	 * scoreIntake returns the total, the derived risk band and all four
	 * subtotals for the submitted draft, without persisting anything.
	 *
	 * @return void
	 */
	public function testScoreIntakeReturnsBandedBreakdown(): void {
		$body = ['assignmentId' => 'opd-1', 'instructionsDetailed' => true];
		$this->scoreCalc->method('computeTotal')->willReturn(60);
		$this->scoreCalc->method('subtotalGezag')->willReturn(30);
		$this->scoreCalc->method('subtotalArbeid')->willReturn(10);
		$this->scoreCalc->method('subtotalFinancieel')->willReturn(15);
		$this->scoreCalc->method('subtotalDeliveroo')->willReturn(5);

		$response = $this->withBody($body, fn (): JSONResponse => $this->controller->scoreIntake());

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame(60, $data['totalScore']);
		self::assertSame(DBAConstants::bandFromScore(60), $data['riskLevel']);
		self::assertSame('MIDDEN_HIGH', $data['riskLevel']);
		self::assertSame(30, $data['authorityRelationship']);
		self::assertSame(10, $data['personalLabour']);
		self::assertSame(15, $data['financialRisk']);
		self::assertSame(5, $data['deliverooCriteria']);
		self::assertSame([], $this->objectService->saves);

	}//end testScoreIntakeReturnsBandedBreakdown()

	/*
	 * ---------------------------------------------------------------------
	 * saveIntake()
	 * ---------------------------------------------------------------------
	 */

	/**
	 * saveIntake rejects a body without an assignmentId with HTTP 400.
	 *
	 * @return void
	 */
	public function testSaveIntakeRejectsMissingAssignmentId(): void {
		$response = $this->withBody(['filledOn' => '2026-01-01'], fn (): JSONResponse => $this->controller->saveIntake());

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());

	}//end testSaveIntakeRejectsMissingAssignmentId()

	/**
	 * saveIntake returns HTTP 503 when OpenRegister cannot be resolved, rather
	 * than a 500 or a silent success.
	 *
	 * @return void
	 */
	public function testSaveIntakeReturns503WhenOpenRegisterUnavailable(): void {
		$this->openRegisterDown = true;

		$response = $this->withBody(['assignmentId' => 'opd-1'], fn (): JSONResponse => $this->controller->saveIntake());

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());

	}//end testSaveIntakeReturns503WhenOpenRegisterUnavailable()

	/**
	 * saveIntake returns HTTP 404 when the referenced opdracht does not exist.
	 *
	 * @return void
	 */
	public function testSaveIntakeReturns404ForUnknownAssignment(): void {
		$this->seed(['DBAOpdracht' => []]);

		$response = $this->withBody(['assignmentId' => 'ghost'], fn (): JSONResponse => $this->controller->saveIntake());

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testSaveIntakeReturns404ForUnknownAssignment()

	/**
	 * saveIntake stamps the server-computed score, band, filler and
	 * administration on the intake, then writes the parent opdracht back with
	 * intakeStatus INTAKE_COMPLETED — two writes, never one.
	 *
	 * @return void
	 */
	public function testSaveIntakePersistsScoredIntakeAndCompletesAssignment(): void {
		$this->seed(['DBAOpdracht' => [$this->assignmentRow()]]);
		$this->scoreCalc->method('computeTotal')->willReturn(80);

		$response = $this->withBody(
			['assignmentId' => 'opd-1', 'filledOn' => '2026-02-01', 'totalScore' => 0],
			fn (): JSONResponse => $this->controller->saveIntake()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$intakes = $this->objectService->savedFor('DBAIntake');
		self::assertCount(1, $intakes);
		self::assertSame(80, $intakes[0]['totalScore']);
		self::assertSame('HIGH', $intakes[0]['interpretation']);
		self::assertSame('alice', $intakes[0]['filledBy']);
		self::assertSame('adm-1', $intakes[0]['administrationId']);

		$assignments = $this->objectService->savedFor('DBAOpdracht');
		self::assertCount(1, $assignments);
		self::assertSame('INTAKE_COMPLETED', $assignments[0]['intakeStatus']);
		self::assertSame('2026-02-01', $assignments[0]['intakeDate']);
		self::assertSame(80, $assignments[0]['actueleRisicoscore']);
		self::assertSame('HIGH', $assignments[0]['riskLevel']);

	}//end testSaveIntakePersistsScoredIntakeAndCompletesAssignment()

	/**
	 * An abbreviated intake overrides the band with the low-threshold marker on
	 * the opdracht while the intake keeps its computed interpretation.
	 *
	 * @return void
	 */
	public function testSaveIntakeAbbreviatedTypeOverridesAssignmentRiskLevel(): void {
		$this->seed(['DBAOpdracht' => [$this->assignmentRow()]]);
		$this->scoreCalc->method('computeTotal')->willReturn(80);

		$response = $this->withBody(
			['assignmentId' => 'opd-1', 'filledOn' => '2026-02-01', 'abbreviatedType' => true],
			fn (): JSONResponse => $this->controller->saveIntake()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$assignments = $this->objectService->savedFor('DBAOpdracht');
		self::assertSame('ABBREVIATED_LOW_THRESHOLD', $assignments[0]['riskLevel']);
		self::assertSame('HIGH', $this->objectService->savedFor('DBAIntake')[0]['interpretation']);

	}//end testSaveIntakeAbbreviatedTypeOverridesAssignmentRiskLevel()

	/**
	 * A failing intake write yields HTTP 500 and logs — the opdracht is never
	 * marked completed off the back of a failed intake.
	 *
	 * @return void
	 */
	public function testSaveIntakeReturns500WhenIntakeWriteFails(): void {
		$this->seed(['DBAOpdracht' => [$this->assignmentRow()]]);
		$this->objectService->failingSchemas = ['DBAIntake'];
		$this->scoreCalc->method('computeTotal')->willReturn(10);
		$this->logger->expects($this->atLeastOnce())->method('error');

		$response = $this->withBody(['assignmentId' => 'opd-1'], fn (): JSONResponse => $this->controller->saveIntake());

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame([], $this->objectService->savedFor('DBAOpdracht'));

	}//end testSaveIntakeReturns500WhenIntakeWriteFails()

	/*
	 * ---------------------------------------------------------------------
	 * vbarCheck()
	 * ---------------------------------------------------------------------
	 */

	/**
	 * vbarCheck is fail-closed for an anonymous session.
	 *
	 * @return void
	 */
	public function testVbarCheckRejectsAnonymousSession(): void {
		$controller = $this->anonymousController();
		$this->vbarMonitor->expects($this->never())->method('assess');

		$response = $controller->vbarCheck();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testVbarCheckRejectsAnonymousSession()

	/**
	 * An OK assessment is returned verbatim and raises no risk flag.
	 *
	 * @return void
	 */
	public function testVbarCheckReturnsAssessmentWithoutFlagWhenOk(): void {
		$assessment = [
			'result'          => DBAVbarMonitorService::RESULT_OK,
			'uurtariefCents'  => 5000,
			'vbarGrensCents'  => DBAConstants::VBAR_GRENS_EUR_CENTS,
			'message'         => 'ok',
		];
		$this->vbarMonitor->method('assess')->willReturn($assessment);
		$this->vbarMonitor->expects($this->never())->method('emitFlag');

		$response = $this->withBody(
			['bedragCents' => 500000, 'hours' => 100.0, 'assignmentId' => 'opd-1', 'invoiceId' => 'inv-1'],
			fn (): JSONResponse => $this->controller->vbarCheck()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($assessment, $response->getData());

	}//end testVbarCheckReturnsAssessmentWithoutFlagWhenOk()

	/**
	 * A sub-threshold rate on a known opdracht + factuur emits exactly one
	 * risk flag carrying the computed rate and the applied threshold.
	 *
	 * @return void
	 */
	public function testVbarCheckEmitsFlagWhenBelowThreshold(): void {
		$this->vbarMonitor->method('assess')->willReturn(
			[
				'result'         => DBAVbarMonitorService::RESULT_BLOCK,
				'uurtariefCents' => 2500,
				'vbarGrensCents' => 3300,
				'message'        => 'below',
			]
		);
		$this->vbarMonitor->expects($this->once())->method('emitFlag')->willReturn(true);

		$response = $this->withBody(
			[
				'bedragCents'      => 250000,
				'hours'            => 100.0,
				'assignmentId'     => 'opd-1',
				'invoiceId'        => 'inv-1',
				'administrationId' => 'adm-1',
			],
			fn (): JSONResponse => $this->controller->vbarCheck()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(DBAVbarMonitorService::RESULT_BLOCK, $response->getData()['result']);

	}//end testVbarCheckEmitsFlagWhenBelowThreshold()

	/**
	 * A sub-threshold rate without a factuur reference cannot be attributed, so
	 * no flag is raised.
	 *
	 * @return void
	 */
	public function testVbarCheckSkipsFlagWithoutInvoiceReference(): void {
		$this->vbarMonitor->method('assess')->willReturn(
			[
				'result'         => DBAVbarMonitorService::RESULT_WARN,
				'uurtariefCents' => 2500,
				'vbarGrensCents' => 3300,
				'message'        => 'below',
			]
		);
		$this->vbarMonitor->expects($this->never())->method('emitFlag');

		$response = $this->withBody(
			['bedragCents' => 250000, 'hours' => 100.0, 'assignmentId' => 'opd-1'],
			fn (): JSONResponse => $this->controller->vbarCheck()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testVbarCheckSkipsFlagWithoutInvoiceReference()

	/*
	 * ---------------------------------------------------------------------
	 * uploadWba()
	 * ---------------------------------------------------------------------
	 */

	/**
	 * uploadWba requires both the opdracht reference and the WBA outcome.
	 *
	 * @return void
	 */
	public function testUploadWbaRejectsMissingOutcome(): void {
		$response = $this->withBody(['assignmentId' => 'opd-1'], fn (): JSONResponse => $this->controller->uploadWba());

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testUploadWbaRejectsMissingOutcome()

	/**
	 * uploadWba stores the outcome and stamps a server-computed validity window
	 * of WBA_GELDIGHEID_DAGEN days — the client never supplies wbaValidTo.
	 *
	 * @return void
	 */
	public function testUploadWbaStampsServerComputedValidity(): void {
		$this->seed(['DBAOpdracht' => [$this->assignmentRow()]]);
		$expected = (new \DateTimeImmutable())
			->modify('+' . DBAConstants::WBA_GELDIGHEID_DAGEN . ' days')->format('Y-m-d');

		$response = $this->withBody(
			['assignmentId' => 'opd-1', 'wbaAssessmentResult' => 'ZELFSTANDIG', 'wbaValidTo' => '1999-01-01'],
			fn (): JSONResponse => $this->controller->uploadWba()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$saved = $this->objectService->savedFor('DBAOpdracht');
		self::assertCount(1, $saved);
		self::assertSame('ZELFSTANDIG', $saved[0]['wbaAssessmentResult']);
		self::assertSame($expected, $saved[0]['wbaValidTo']);
		self::assertArrayHasKey('opdracht', $response->getData());

	}//end testUploadWbaStampsServerComputedValidity()

	/**
	 * uploadWba returns HTTP 404 for an unknown opdracht.
	 *
	 * @return void
	 */
	public function testUploadWbaReturns404ForUnknownAssignment(): void {
		$this->seed(['DBAOpdracht' => []]);

		$response = $this->withBody(
			['assignmentId' => 'ghost', 'wbaAssessmentResult' => 'ZELFSTANDIG'],
			fn (): JSONResponse => $this->controller->uploadWba()
		);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUploadWbaReturns404ForUnknownAssignment()

	/*
	 * ---------------------------------------------------------------------
	 * beeindigen()
	 * ---------------------------------------------------------------------
	 */

	/**
	 * beeindigen requires both the opdracht reference and the factual end date.
	 *
	 * @return void
	 */
	public function testBeeindigenRejectsMissingEndDate(): void {
		$response = $this->withBody(['assignmentId' => 'opd-1'], fn (): JSONResponse => $this->controller->beeindigen());

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testBeeindigenRejectsMissingEndDate()

	/**
	 * beeindigen ends the opdracht and starts the retention clock computed by
	 * the guard, returning the deadline alongside the updated opdracht.
	 *
	 * @return void
	 */
	public function testBeeindigenEndsAssignmentAndStartsRetentionClock(): void {
		$this->seed(['DBAOpdracht' => [$this->assignmentRow()]]);
		$this->assignmentGuard->method('computeRetentieDeadline')->willReturn('2033-03-31');

		$response = $this->withBody(
			['assignmentId' => 'opd-1', 'actualEndDate' => '2026-03-31'],
			fn (): JSONResponse => $this->controller->beeindigen()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('2033-03-31', $response->getData()['retentionDeadline']);

		$saved = $this->objectService->savedFor('DBAOpdracht');
		self::assertCount(1, $saved);
		self::assertSame('ENDED', $saved[0]['intakeStatus']);
		self::assertSame('2026-03-31', $saved[0]['actualEndDate']);
		self::assertSame('2033-03-31', $saved[0]['retentionDeadline']);

	}//end testBeeindigenEndsAssignmentAndStartsRetentionClock()

	/**
	 * When the guard cannot compute a retention deadline the field is omitted
	 * rather than written as null.
	 *
	 * @return void
	 */
	public function testBeeindigenOmitsRetentionDeadlineWhenGuardReturnsNull(): void {
		$this->seed(['DBAOpdracht' => [$this->assignmentRow()]]);
		$this->assignmentGuard->method('computeRetentieDeadline')->willReturn(null);

		$response = $this->withBody(
			['assignmentId' => 'opd-1', 'actualEndDate' => 'not-a-date'],
			fn (): JSONResponse => $this->controller->beeindigen()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertNull($response->getData()['retentionDeadline']);
		self::assertArrayNotHasKey('retentionDeadline', $this->objectService->savedFor('DBAOpdracht')[0]);

	}//end testBeeindigenOmitsRetentionDeadlineWhenGuardReturnsNull()

	/*
	 * ---------------------------------------------------------------------
	 * setMode()
	 * ---------------------------------------------------------------------
	 */

	/**
	 * setMode rejects a compliance-mode outside the closed vocabulary.
	 *
	 * @return void
	 */
	public function testSetModeRejectsUnknownMode(): void {
		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->withBody(['mode' => 'permissive'], fn (): JSONResponse => $this->controller->setMode());

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testSetModeRejectsUnknownMode()

	/**
	 * setMode is fail-closed for an anonymous session.
	 *
	 * @return void
	 */
	public function testSetModeRejectsAnonymousSession(): void {
		$controller = $this->anonymousController();

		$response = $controller->setMode();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testSetModeRejectsAnonymousSession()

	/**
	 * A per-administration mode is stored under an administration-suffixed key
	 * so one administration's setting can never overwrite another's.
	 *
	 * @return void
	 */
	public function testSetModeScopesConfigKeyPerAdministration(): void {
		$captured = [];
		$this->appConfig->expects($this->once())->method('setValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $value) use (&$captured): bool {
					$captured = ['app' => $app, 'key' => $key, 'value' => $value];
					return true;
				}
			);

		$response = $this->withBody(
			['mode' => DBAConstants::COMPLIANCE_MODE_HARD, 'administrationId' => 'adm-1'],
			fn (): JSONResponse => $this->controller->setMode()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(DBAConstants::COMPLIANCE_MODE_HARD, $response->getData()['mode']);
		self::assertSame('adm-1', $response->getData()['administrationId']);
		self::assertSame(DBAConstants::CONFIG_PREFIX . 'compliance_mode.adm-1', $captured['key']);
		self::assertSame(DBAConstants::COMPLIANCE_MODE_HARD, $captured['value']);

	}//end testSetModeScopesConfigKeyPerAdministration()

	/**
	 * Without an administrationId the mode lands on the unsuffixed global key.
	 *
	 * @return void
	 */
	public function testSetModeWritesGlobalKeyWithoutAdministration(): void {
		$captured = [];
		$this->appConfig->expects($this->once())->method('setValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $value) use (&$captured): bool {
					$captured = ['key' => $key];
					return true;
				}
			);

		$response = $this->withBody(
			['mode' => DBAConstants::COMPLIANCE_MODE_SOFT],
			fn (): JSONResponse => $this->controller->setMode()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(DBAConstants::CONFIG_PREFIX . 'compliance_mode', $captured['key']);

	}//end testSetModeWritesGlobalKeyWithoutAdministration()

	/*
	 * ---------------------------------------------------------------------
	 * setTussenkomstMode()
	 * ---------------------------------------------------------------------
	 */

	/**
	 * setTussenkomstMode requires an opdracht reference.
	 *
	 * @return void
	 */
	public function testSetTussenkomstModeRejectsMissingAssignmentId(): void {
		$response = $this->withBody(
			['intermediaryMode' => true],
			fn (): JSONResponse => $this->controller->setTussenkomstMode()
		);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testSetTussenkomstModeRejectsMissingAssignmentId()

	/**
	 * setTussenkomstMode persists the intermediair flag as a real boolean.
	 *
	 * @return void
	 */
	public function testSetTussenkomstModePersistsBooleanFlag(): void {
		$this->seed(['DBAOpdracht' => [$this->assignmentRow()]]);

		$response = $this->withBody(
			['assignmentId' => 'opd-1', 'intermediaryMode' => 1],
			fn (): JSONResponse => $this->controller->setTussenkomstMode()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$saved = $this->objectService->savedFor('DBAOpdracht');
		self::assertCount(1, $saved);
		self::assertTrue($saved[0]['intermediaryMode']);

	}//end testSetTussenkomstModePersistsBooleanFlag()

	/**
	 * NEGATIVE CONTROL (security-endpoint-guards REQ-001): a caller with no
	 * membership in the assignment's administration is rejected —
	 * `ensureAdministrationAccess()` was a documented stub that logged and
	 * unconditionally permitted every caller (STUB verdict); before this
	 * change's fix this exact call would have persisted the write. Fixed
	 * via `AdministrationContextService::canAccess()`, throwing
	 * `OCSForbiddenException` instead. See
	 * testSetTussenkomstModePersistsBooleanFlag() above for the
	 * positive-direction counterpart (an 'adm-1' member still succeeds).
	 *
	 * @return void
	 */
	public function testSetTussenkomstModeRejectsNonMemberCaller(): void {
		$this->seed(['DBAOpdracht' => [$this->assignmentRow()]]);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->administrationContext->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-2'
		);
		$controller = new DBAController(
			request: $this->request,
			container: $this->container,
			appConfig: $this->appConfig,
			userSession: $this->userSession,
			scoreCalc: $this->scoreCalc,
			assignmentGuard: $this->assignmentGuard,
			vbarMonitor: $this->vbarMonitor,
			logger: $this->logger,
			administrationContext: $this->administrationContext,
			groupManager: $this->groupManager,
		);

		$this->expectException(OCSForbiddenException::class);
		$this->withBody(
			['assignmentId' => 'opd-1', 'intermediaryMode' => true],
			fn (): JSONResponse => $controller->setTussenkomstMode()
		);

		self::assertCount(0, $this->objectService->savedFor('DBAOpdracht'), 'No write for a non-member caller');

	}//end testSetTussenkomstModeRejectsNonMemberCaller()

	/**
	 * A Nextcloud admin bypasses the per-administration membership check —
	 * matching `BookingNotificationController::authorizeBookingAccess()`'s
	 * established pattern. Without this, the admin account (which carries
	 * no `AdministrationMembership` of its own by default — see
	 * tests/e2e/ci-seed.sh) would be locked out of managing any
	 * administration's DBA records.
	 *
	 * @return void
	 */
	public function testSetTussenkomstModeByAdminBypassesMembershipCheck(): void {
		$this->seed(['DBAOpdracht' => [$this->assignmentRow()]]);
		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturn(false);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$controller = new DBAController(
			request: $this->request,
			container: $this->container,
			appConfig: $this->appConfig,
			userSession: $this->userSession,
			scoreCalc: $this->scoreCalc,
			assignmentGuard: $this->assignmentGuard,
			vbarMonitor: $this->vbarMonitor,
			logger: $this->logger,
			administrationContext: $administrationContext,
			groupManager: $groupManager,
		);

		$response = $this->withBody(
			['assignmentId' => 'opd-1', 'intermediaryMode' => true],
			fn (): JSONResponse => $controller->setTussenkomstMode()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testSetTussenkomstModeByAdminBypassesMembershipCheck()

	/**
	 * An anonymous caller is rejected before any membership check runs.
	 *
	 * @return void
	 */
	public function testSetTussenkomstModeRejectsAnonymousCaller(): void {
		$this->seed(['DBAOpdracht' => [$this->assignmentRow()]]);

		$this->expectException(OCSForbiddenException::class);
		$this->withBody(
			['assignmentId' => 'opd-1', 'intermediaryMode' => true],
			fn (): JSONResponse => $this->anonymousController()->setTussenkomstMode()
		);

	}//end testSetTussenkomstModeRejectsAnonymousCaller()

	/**
	 * A failing write surfaces as HTTP 500, not a false success.
	 *
	 * @return void
	 */
	public function testSetTussenkomstModeReturns500WhenWriteFails(): void {
		$this->seed(['DBAOpdracht' => [$this->assignmentRow()]]);
		$this->objectService->failingSchemas = ['DBAOpdracht'];

		$response = $this->withBody(
			['assignmentId' => 'opd-1', 'intermediaryMode' => true],
			fn (): JSONResponse => $this->controller->setTussenkomstMode()
		);

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());

	}//end testSetTussenkomstModeReturns500WhenWriteFails()

	/*
	 * ---------------------------------------------------------------------
	 * evidenceConsent()
	 * ---------------------------------------------------------------------
	 */

	/**
	 * evidenceConsent requires the dossier reference.
	 *
	 * @return void
	 */
	public function testEvidenceConsentRejectsMissingDossierId(): void {
		$response = $this->withBody(['optIn' => true], fn (): JSONResponse => $this->controller->evidenceConsent());

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testEvidenceConsentRejectsMissingDossierId()

	/**
	 * An opt-in records both the consent flag and the consent-record reference
	 * that the AVG trail needs (REQ-DBA-012).
	 *
	 * @return void
	 */
	public function testEvidenceConsentStoresOptInWithConsentRecord(): void {
		$this->seed(
			['DBAEvidenceDossier' => [['id' => 'dos-1', 'administrationId' => 'adm-1']]]
		);

		$response = $this->withBody(
			['dossierId' => 'dos-1', 'optIn' => true, 'consentRecordId' => 'consent-9'],
			fn (): JSONResponse => $this->controller->evidenceConsent()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$saved = $this->objectService->savedFor('DBAEvidenceDossier');
		self::assertCount(1, $saved);
		self::assertTrue($saved[0]['emailArchiveOptIn']);
		self::assertSame('consent-9', $saved[0]['emailArchiveConsentRecordId']);

	}//end testEvidenceConsentStoresOptInWithConsentRecord()

	/**
	 * An opt-OUT never writes a consent-record reference — a withdrawn consent
	 * must not leave a record id claiming permission.
	 *
	 * @return void
	 */
	public function testEvidenceConsentOptOutDropsConsentRecord(): void {
		$this->seed(
			['DBAEvidenceDossier' => [['id' => 'dos-1', 'administrationId' => 'adm-1']]]
		);

		$response = $this->withBody(
			['dossierId' => 'dos-1', 'optIn' => false, 'consentRecordId' => 'consent-9'],
			fn (): JSONResponse => $this->controller->evidenceConsent()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$saved = $this->objectService->savedFor('DBAEvidenceDossier');
		self::assertFalse($saved[0]['emailArchiveOptIn']);
		self::assertArrayNotHasKey('emailArchiveConsentRecordId', $saved[0]);

	}//end testEvidenceConsentOptOutDropsConsentRecord()

	/**
	 * evidenceConsent returns HTTP 404 for an unknown dossier.
	 *
	 * @return void
	 */
	public function testEvidenceConsentReturns404ForUnknownDossier(): void {
		$this->seed(['DBAEvidenceDossier' => []]);

		$response = $this->withBody(
			['dossierId' => 'ghost', 'optIn' => true],
			fn (): JSONResponse => $this->controller->evidenceConsent()
		);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testEvidenceConsentReturns404ForUnknownDossier()

	/*
	 * ---------------------------------------------------------------------
	 * inhuurIntake()
	 * ---------------------------------------------------------------------
	 */

	/**
	 * inhuurIntake requires an opdracht reference.
	 *
	 * @return void
	 */
	public function testInhuurIntakeRejectsMissingAssignmentId(): void {
		$response = $this->withBody([], fn (): JSONResponse => $this->controller->inhuurIntake());

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testInhuurIntakeRejectsMissingAssignmentId()

	/**
	 * inhuurIntake stamps the opdrachtgever perspective on the opdracht and
	 * then runs the ordinary intake save — so the opdracht is written twice
	 * (perspective, then completion) and exactly one intake is created.
	 *
	 * @return void
	 */
	public function testInhuurIntakeStampsClientPerspectiveThenSavesIntake(): void {
		$this->seed(['DBAOpdracht' => [$this->assignmentRow()]]);
		$this->scoreCalc->method('computeTotal')->willReturn(20);

		$response = $this->withBody(
			['assignmentId' => 'opd-1', 'filledOn' => '2026-04-01'],
			fn (): JSONResponse => $this->controller->inhuurIntake()
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$assignments = $this->objectService->savedFor('DBAOpdracht');
		self::assertCount(2, $assignments);
		self::assertSame('CLIENT', $assignments[0]['perspective']);
		self::assertSame('INTAKE_COMPLETED', $assignments[1]['intakeStatus']);

		$intakes = $this->objectService->savedFor('DBAIntake');
		self::assertCount(1, $intakes);
		self::assertSame('LOW', $intakes[0]['interpretation']);

	}//end testInhuurIntakeStampsClientPerspectiveThenSavesIntake()

	/**
	 * inhuurIntake returns HTTP 404 for an unknown opdracht before writing.
	 *
	 * @return void
	 */
	public function testInhuurIntakeReturns404ForUnknownAssignment(): void {
		$this->seed(['DBAOpdracht' => []]);

		$response = $this->withBody(
			['assignmentId' => 'ghost'],
			fn (): JSONResponse => $this->controller->inhuurIntake()
		);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame([], $this->objectService->saves);

	}//end testInhuurIntakeReturns404ForUnknownAssignment()

	/*
	 * ---------------------------------------------------------------------
	 * auditReport()
	 * ---------------------------------------------------------------------
	 */

	/**
	 * auditReport returns HTTP 404 for an unknown opdracht.
	 *
	 * @return void
	 */
	public function testAuditReportReturns404ForUnknownAssignment(): void {
		$this->seed(['DBAOpdracht' => []]);

		$response = $this->controller->auditReport('ghost');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAuditReportReturns404ForUnknownAssignment()

	/**
	 * auditReport returns HTTP 503 when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testAuditReportReturns503WhenOpenRegisterUnavailable(): void {
		$this->openRegisterDown = true;

		$response = $this->controller->auditReport('opd-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());

	}//end testAuditReportReturns503WhenOpenRegisterUnavailable()

	/**
	 * The audit-rapport bundles opdracht, intake, risk flags and the evidence
	 * dossier, and seals the payload with a SHA-256 integrity hash plus the
	 * fiscal grounds — the record an inspector receives (REQ-DBA-008).
	 *
	 * @return void
	 */
	public function testAuditReportBundlesSourcesAndSealsWithSha256(): void {
		$this->seed(
			[
				'DBAOpdracht' => [
					[
						'id'                => 'opd-1',
						'administrationId'  => 'adm-1',
						'evidenceDossierId' => 'dos-1',
					],
				],
				'DBAIntake' => [
					['id' => 'int-1', 'assignmentId' => 'opd-1', 'totalScore' => 42],
					['id' => 'int-2', 'assignmentId' => 'opd-other', 'totalScore' => 7],
				],
				'DBARisicoflag' => [
					['id' => 'flag-1', 'assignmentId' => 'opd-1', 'type' => 'VBAR_GRENS_ONDERSCHREDEN'],
					['id' => 'flag-2', 'assignmentId' => 'opd-1', 'type' => 'CONCENTRATIE'],
					['id' => 'flag-3', 'assignmentId' => 'opd-other', 'type' => 'CONCENTRATIE'],
				],
				'DBAEvidenceDossier' => [
					['id' => 'dos-1', 'administrationId' => 'adm-1', 'emailArchiveOptIn' => true],
				],
			]
		);

		$response = $this->controller->auditReport('opd-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertSame('opd-1', $data['opdracht']['id']);
		self::assertSame(42, $data['intake']['totalScore']);
		self::assertCount(2, $data['flags']);
		self::assertSame('dos-1', $data['evidenceDossier']['id']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['sha256']);
		self::assertStringContainsString('Deliveroo-arrest', $data['fiscaleGrondslag']);
		self::assertStringContainsString(
			(string)DBAConstants::VBAR_GRENS_PEILJAAR,
			$data['fiscaleGrondslag']
		);

	}//end testAuditReportBundlesSourcesAndSealsWithSha256()

	/**
	 * An opdracht without an evidence-dossier reference still produces a report
	 * with an explicit null dossier rather than failing.
	 *
	 * @return void
	 */
	public function testAuditReportWithoutDossierReturnsNullDossier(): void {
		$this->seed(['DBAOpdracht' => [$this->assignmentRow()]]);

		$response = $this->controller->auditReport('opd-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertNull($data['evidenceDossier']);
		self::assertNull($data['intake']);
		self::assertSame([], $data['flags']);

	}//end testAuditReportWithoutDossierReturnsNullDossier()

	/**
	 * Requesting `Accept: application/pdf` negotiates a download response whose
	 * filename carries the opdracht id.
	 *
	 * @return void
	 */
	public function testAuditReportHonoursPdfAcceptHeader(): void {
		$this->seed(['DBAOpdracht' => [$this->assignmentRow()]]);
		$this->headers = ['Accept' => 'application/pdf'];

		$response = $this->controller->auditReport('opd-1');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertStringContainsString('dba-audit-opd-1', (string)$response->getHeaders()['Content-Disposition']);
		self::assertStringContainsString('"opdracht"', (string)$response->render());

	}//end testAuditReportHonoursPdfAcceptHeader()

	/**
	 * Build a second controller bound to an anonymous session.
	 *
	 * @return DBAController The controller with no signed-in user.
	 */
	private function anonymousController(): DBAController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new DBAController(
			request: $this->request,
			container: $this->container,
			appConfig: $this->appConfig,
			userSession: $session,
			scoreCalc: $this->scoreCalc,
			assignmentGuard: $this->assignmentGuard,
			vbarMonitor: $this->vbarMonitor,
			logger: $this->logger,
			administrationContext: $this->administrationContext,
			groupManager: $this->groupManager,
		);

	}//end anonymousController()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
