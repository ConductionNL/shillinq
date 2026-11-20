<?php

/**
 * Unit tests for DBAController::auditReport() source reading.
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

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\DBAController;
use OCA\Shillinq\Guard\DBAOpdrachtGuard;
use OCA\Shillinq\Guard\DBAScoreCalculator;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\DBAVbarMonitorService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An OpenRegister row as an entity: only getObject() gives its data.
 */
final class AuditReportEntityRow {
	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data The object data.
	 */
	public function __construct(private readonly array $data) {
	}//end __construct()

	/**
	 * The object data.
	 *
	 * @return array<string,mixed>
	 */
	public function getObject(): array {
		return $this->data;
	}//end getObject()
}//end class

/**
 * Object service answering findAll() with fixed rows per schema, or throwing.
 */
final class AuditReportObjectService {
	/**
	 * The current schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * The findAll() queries asked, per schema.
	 *
	 * @var array<string,list<array<string,mixed>>>
	 */
	public array $queries = [];

	/**
	 * Constructor.
	 *
	 * @param array<string,array<string,mixed>> $byId    Rows find() returns, by schema then id.
	 * @param array<string,list<mixed>>          $lists   Rows findAll() returns, by schema.
	 * @param list<string>                       $failing Schemas whose findAll() throws.
	 */
	public function __construct(
		private readonly array $byId,
		private readonly array $lists = [],
		private readonly array $failing = [],
	) {
	}//end __construct()

	/**
	 * Set the register.
	 *
	 * @param string $register The register.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		return $this;
	}//end setRegister()

	/**
	 * Set the schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * One row by id.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find(string $id): ?array {
		return ($this->byId[$this->schema][$id] ?? null);
	}//end find()

	/**
	 * The rows of the current schema.
	 *
	 * @param array<string,mixed> $query The query.
	 *
	 * @return list<mixed>
	 */
	public function findAll(array $query): array {
		$this->queries[$this->schema][] = $query;
		if (in_array($this->schema, $this->failing, true) === true) {
			throw new RuntimeException('findAll refused for ' . $this->schema);
		}

		return ($this->lists[$this->schema] ?? []);
	}//end findAll()
}//end class

/**
 * How auditReport() reads intake and flags: entity rows, odd rows, failing reads.
 */
final class DBAControllerAuditReportTest extends TestCase {
	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The Accept header the request carries.
	 *
	 * @var string
	 */
	private string $accept = '';

	/**
	 * The assignment every test reports on.
	 *
	 * @var array<string,mixed>
	 */
	private array $assignment = ['id' => 'opd-1', 'administrationId' => 'adm-1'];

	/**
	 * A controller over the given object service.
	 *
	 * @param AuditReportObjectService $objectService The object service.
	 *
	 * @return DBAController
	 */
	private function controller(AuditReportObjectService $objectService): DBAController {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => (['Accept' => $this->accept][$name] ?? '')
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-1'
		);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);

		return new DBAController(
			request: $this->request,
			container: $container,
			appConfig: $appConfig,
			userSession: $userSession,
			scoreCalc: $this->createMock(DBAScoreCalculator::class),
			assignmentGuard: $this->createMock(DBAOpdrachtGuard::class),
			vbarMonitor: $this->createMock(DBAVbarMonitorService::class),
			logger: $this->logger,
			administrationContext: $administrationContext,
			groupManager: $groupManager,
		);
	}//end controller()

	/**
	 * Entity rows are read through getObject(); a flag row that is neither an
	 * array nor an entity is left out; only the first intake row counts.
	 *
	 * @return void
	 */
	public function testEntityRowsAreReadThroughGetObject(): void {
		$objectService = new AuditReportObjectService(
			['DBAOpdracht' => ['opd-1' => $this->assignment]],
			[
				'DBAIntake'     => [
					new AuditReportEntityRow(['id' => 'int-1', 'totalScore' => 42]),
					['id' => 'int-2', 'totalScore' => 7],
				],
				'DBARisicoflag' => [
					new AuditReportEntityRow(['id' => 'flag-1', 'type' => 'CONCENTRATIE']),
					'not-a-row',
					['id' => 'flag-2', 'type' => 'VBAR_GRENS_ONDERSCHREDEN'],
				],
			]
		);

		$response = $this->controller($objectService)->auditReport('opd-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		self::assertSame(['id' => 'int-1', 'totalScore' => 42], $data['intake']);
		self::assertSame(['flag-1', 'flag-2'], array_column($data['flags'], 'id'));
		self::assertNull($data['evidenceDossier']);
		self::assertSame(
			[['filters' => ['assignmentId' => 'opd-1'], 'limit' => 1]],
			$objectService->queries['DBAIntake']
		);
		self::assertSame(
			[['filters' => ['assignmentId' => 'opd-1'], 'limit' => 500]],
			$objectService->queries['DBARisicoflag']
		);
	}//end testEntityRowsAreReadThroughGetObject()

	/**
	 * A first intake row that is neither an array nor an entity gives no intake.
	 *
	 * @return void
	 */
	public function testAnUnreadableIntakeRowGivesNoIntake(): void {
		$objectService = new AuditReportObjectService(
			['DBAOpdracht' => ['opd-1' => $this->assignment]],
			['DBAIntake' => ['not-a-row', ['id' => 'int-2']]]
		);

		$data = $this->controller($objectService)->auditReport('opd-1')->getData();

		self::assertNull($data['intake']);
		self::assertSame([], $data['flags']);
	}//end testAnUnreadableIntakeRowGivesNoIntake()

	/**
	 * Failing intake and flag reads are logged and the report still renders without them.
	 *
	 * @return void
	 */
	public function testFailingReadsAreLoggedAndLeftOut(): void {
		$objectService = new AuditReportObjectService(
			['DBAOpdracht' => ['opd-1' => $this->assignment]],
			[],
			['DBAIntake', 'DBARisicoflag']
		);
		$controller = $this->controller($objectService);

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$response = $controller->auditReport('opd-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertNull($response->getData()['intake']);
		self::assertSame([], $response->getData()['flags']);
		self::assertSame(
			[
				['Audit-report intake fetch failed', ['exception' => 'findAll refused for DBAIntake']],
				['Audit-report flags fetch failed', ['exception' => 'findAll refused for DBARisicoflag']],
			],
			$warnings
		);
	}//end testFailingReadsAreLoggedAndLeftOut()

	/**
	 * A payload that cannot be encoded is a 500.
	 *
	 * @return void
	 */
	public function testAnUnencodablePayloadIsA500(): void {
		$objectService = new AuditReportObjectService(
			['DBAOpdracht' => ['opd-1' => array_merge($this->assignment, ['name' => "\xB1\x31"])]]
		);

		$response = $this->controller($objectService)->auditReport('opd-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}//end testAnUnencodablePayloadIsA500()

	/**
	 * Asking for a PDF downloads the sealed JSON whose hash the payload names.
	 *
	 * @return void
	 */
	public function testAskingForAPdfDownloadsTheJson(): void {
		$this->accept = 'application/pdf';
		$objectService = new AuditReportObjectService(['DBAOpdracht' => ['opd-1' => $this->assignment]]);

		$response = $this->controller($objectService)->auditReport('opd-1');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertStringContainsString('dba-audit-opd-1.json', $response->getHeaders()['Content-Disposition']);
		self::assertSame('application/json', $response->getHeaders()['Content-Type']);
		$json = $response->render();
		$decoded = json_decode($json, true);
		self::assertSame('opd-1', $decoded['opdracht']['id']);
		self::assertArrayNotHasKey('sha256', $decoded);
	}//end testAskingForAPdfDownloadsTheJson()
}//end class
