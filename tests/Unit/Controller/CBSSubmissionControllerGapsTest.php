<?php

/**
 * Unit tests for CBSSubmissionController's validation, refusal, conflict and
 * failure branches.
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
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Controller\CBSSubmissionController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\CBSExportService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Anonymous callers get 401 everywhere, malformed ids and missing fields 400,
 * unreadable submissions 404, non-draft deletes 409, a failed validation 422
 * with its errors, and a failing store a logged 500 without the cause.
 *
 * @covers \OCA\Shillinq\Controller\CBSSubmissionController
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 *
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CBSSubmissionControllerGapsTest extends TestCase {

	/**
	 * Request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Export service double.
	 *
	 * @var CBSExportService&MockObject
	 */
	private CBSExportService&MockObject $exportService;

	/**
	 * App config double.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * User session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Administration context double.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $administrationContext;

	/**
	 * Group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Request query parameters.
	 *
	 * @var array<string,mixed>
	 */
	private array $query = [];

	/**
	 * Set up the doubles; a non-admin member of adm-1 is signed in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request               = $this->createMock(IRequest::class);
		$this->exportService         = $this->createMock(CBSExportService::class);
		$this->appConfig             = $this->createMock(IAppConfig::class);
		$this->userSession           = $this->createMock(IUserSession::class);
		$this->logger                = $this->createMock(LoggerInterface::class);
		$this->objectService         = $this->createMock(ObjectServiceInterface::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->groupManager          = $this->createMock(IGroupManager::class);

		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->query[$key] ?? $default)
		);
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->administrationContext->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-1'
		);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return CBSSubmissionController
	 */
	private function controller(bool $signedIn = true): CBSSubmissionController {
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$this->userSession->method('getUser')->willReturn($user);

		return new CBSSubmissionController(
			request: $this->request,
			exportService: $this->exportService,
			appConfig: $this->appConfig,
			userSession: $this->userSession,
			logger: $this->logger,
			objectService: $this->objectService,
			administrationContext: $this->administrationContext,
			groupManager: $this->groupManager,
		);
	}//end controller()

	/**
	 * An entity whose find() payload is the given row.
	 *
	 * @param array<string,mixed> $row The payload.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $row): ObjectEntityInterface {
		return $this->createConfiguredMock(ObjectEntityInterface::class, ['jsonSerialize' => $row]);
	}//end entity()

	/**
	 * Every endpoint refuses an anonymous caller with 401 and reads nothing.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsRefusedEverywhere(): void {
		$this->objectService->expects(self::never())->method('find');
		$this->objectService->expects(self::never())->method('findAll');
		$controller = $this->controller(signedIn: false);
		$expected   = ['error' => 'authentication required', 'message' => 'Authentication required'];

		foreach ([
			$controller->index(),
			$controller->create(),
			$controller->update(id: 'sub-1'),
			$controller->destroy(id: 'sub-1'),
		] as $response) {
			self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			self::assertSame($expected, $response->getData());
		}
	}//end testAnonymousCallerIsRefusedEverywhere()

	/**
	 * Index passes the status and administration filters on to the store.
	 *
	 * @return void
	 */
	public function testIndexFiltersByStatusAndAdministration(): void {
		$this->query = ['status' => ' draft ', 'administrationId' => 'adm-1'];
		$this->objectService->expects(self::once())
			->method('findAll')
			->with(['filters' => ['status' => 'draft', 'administrationId' => 'adm-1']])
			->willReturn([['id' => 'sub-1', 'administrationId' => 'adm-1'], new stdClass()]);

		$response = $this->controller()->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			['submissions' => [['id' => 'sub-1', 'administrationId' => 'adm-1'], []]],
			$response->getData()
		);
	}//end testIndexFiltersByStatusAndAdministration()

	/**
	 * Index refuses an explicit filter on a foreign administration with 403.
	 *
	 * @return void
	 */
	public function testIndexRefusesAForeignAdministrationFilter(): void {
		$this->query = ['administrationId' => 'adm-other'];
		$this->objectService->expects(self::never())->method('findAll');

		$response = $this->controller()->index();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('cbs-submission-forbidden', $response->getData()['error']);
	}//end testIndexRefusesAForeignAdministrationFilter()

	/**
	 * A failing store on index is logged with the filters and answered 500.
	 *
	 * @return void
	 */
	public function testIndexStoreFailureAnswers500WithoutTheCause(): void {
		$this->query = ['status' => 'draft'];
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects(self::once())
			->method('error')
			->with(
				'CBSSubmissionController: failed to list CBS submissions',
				['status' => 'draft', 'exception' => 'db down']
			);

		$response = $this->controller()->index();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(
			['error' => 'internal', 'message' => 'Failed to list CBS submissions'],
			$response->getData()
		);
	}//end testIndexStoreFailureAnswers500WithoutTheCause()

	/**
	 * Create with an empty body names the first missing field.
	 *
	 * @return void
	 */
	public function testCreateWithoutBodyNamesTheFirstMissingField(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->create();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(
			['error' => 'missing field', 'message' => 'reportingPeriodStartDate is required'],
			$response->getData()
		);
	}//end testCreateWithoutBodyNamesTheFirstMissingField()

	/**
	 * An empty-string required field counts as missing.
	 *
	 * @return void
	 */
	public function testCreateWithAnEmptyRequiredFieldIsRefused(): void {
		$this->request->method('getParams')->willReturn(
			[
				'_route'                   => 'x',
				'reportingPeriodStartDate' => '2026-01-01',
				'reportingPeriodEndDate'   => '2026-03-31',
				'organizationLegalName'    => 'Acme',
				'kvkNumber'                => '',
				'taxIdentificationNumber'  => 'NL123456789B01',
				'administrationId'         => 'adm-1',
			]
		);

		$response = $this->controller()->create();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('kvkNumber is required', $response->getData()['message']);
	}//end testCreateWithAnEmptyRequiredFieldIsRefused()

	/**
	 * Create falls back to the 'shillinq' register when the configured one is
	 * blank, saves a draft in EUR, and answers 201 with the entity's payload
	 * read through getObject() when jsonSerialize() gives no array.
	 *
	 * @return void
	 */
	public function testCreateSavesADraftInTheDefaultRegister(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn('');
		$this->appConfig = $appConfig;
		$body            = [
			'reportingPeriodStartDate' => '2026-01-01',
			'reportingPeriodEndDate'   => '2026-03-31',
			'organizationLegalName'    => 'Acme',
			'kvkNumber'                => '12345678',
			'taxIdentificationNumber'  => 'NL123456789B01',
			'administrationId'         => 'adm-1',
		];
		$this->request->method('getParams')->willReturn($body);
		$saved = $this->createConfiguredMock(
			ObjectEntityInterface::class,
			['jsonSerialize' => null, 'getObject' => ['id' => 'sub-new', 'status' => 'draft']]
		);
		$this->objectService->expects(self::once())
			->method('saveObject')
			->with($body + ['status' => 'draft', 'currency' => 'EUR'], [], 'shillinq', 'CBSSubmission')
			->willReturn($saved);

		$response = $this->controller()->create();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(['id' => 'sub-new', 'status' => 'draft'], $response->getData());
	}//end testCreateSavesADraftInTheDefaultRegister()

	/**
	 * Malformed ids are refused with 400 before any read.
	 *
	 * @return void
	 */
	public function testMalformedIdsAreRefusedBeforeAnyRead(): void {
		$this->objectService->expects(self::never())->method('find');
		$controller = $this->controller();

		$update = $controller->update(id: '../etc');
		self::assertSame(Http::STATUS_BAD_REQUEST, $update->getStatus());
		self::assertSame(['error' => 'invalid id', 'message' => 'id must be a valid identifier'], $update->getData());

		$destroy = $controller->destroy(id: '');
		self::assertSame(Http::STATUS_BAD_REQUEST, $destroy->getStatus());
		self::assertSame(['error' => 'missing field', 'message' => 'id is required'], $destroy->getData());
	}//end testMalformedIdsAreRefusedBeforeAnyRead()

	/**
	 * A submission that cannot be read answers 404 on update and destroy.
	 *
	 * @return void
	 */
	public function testUnreadableSubmissionAnswers404(): void {
		$this->objectService->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->objectService->expects(self::never())->method('saveObject');
		$this->objectService->expects(self::never())->method('deleteObject');
		$controller = $this->controller();
		$expected   = ['error' => 'cbs-submission-not-found', 'message' => 'CBS submission not found'];

		foreach ([$controller->update(id: 'sub-1'), $controller->destroy(id: 'sub-1')] as $response) {
			self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			self::assertSame($expected, $response->getData());
		}
	}//end testUnreadableSubmissionAnswers404()

	/**
	 * A validated transition that fails validation answers 422 with the
	 * service's errors and saves nothing.
	 *
	 * @return void
	 */
	public function testUpdateToValidatedAnswers422WhenValidationFails(): void {
		$this->objectService->method('find')->willReturn($this->entity(row: ['administrationId' => 'adm-1', 'status' => 'draft']));
		$this->request->method('getParams')->willReturn(['status' => 'validated', 'kvkNumber' => 'x']);
		$this->exportService->expects(self::once())
			->method('validateSubmission')
			->with(['status' => 'validated', 'kvkNumber' => 'x', 'id' => 'sub-1'])
			->willReturn(['valid' => false, 'errors' => ['kvkNumber must match'], 'warnings' => []]);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->update(id: 'sub-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(
			[
				'error'   => 'validation failed',
				'message' => 'CBS submission validation failed',
				'errors'  => ['kvkNumber must match'],
			],
			$response->getData()
		);
	}//end testUpdateToValidatedAnswers422WhenValidationFails()

	/**
	 * A validated transition that passes validation is saved.
	 *
	 * @return void
	 */
	public function testUpdateToValidatedSavesWhenValidationPasses(): void {
		$this->objectService->method('find')->willReturn($this->entity(row: ['administrationId' => 'adm-1', 'status' => 'draft']));
		$this->request->method('getParams')->willReturn(['status' => 'validated']);
		$this->exportService->method('validateSubmission')->willReturn(['valid' => true, 'errors' => [], 'warnings' => ['w']]);
		$this->objectService->expects(self::once())
			->method('saveObject')
			->with(['status' => 'validated', 'id' => 'sub-1'], [], 'shillinq', 'CBSSubmission')
			->willReturn($this->entity(row: ['id' => 'sub-1', 'status' => 'validated']));

		$response = $this->controller()->update(id: 'sub-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['id' => 'sub-1', 'status' => 'validated'], $response->getData());
	}//end testUpdateToValidatedSavesWhenValidationPasses()

	/**
	 * A submission that is no longer a draft cannot be deleted.
	 *
	 * @return void
	 */
	public function testDestroyRefusesANonDraftWith409(): void {
		$this->objectService->method('find')->willReturn($this->entity(row: ['administrationId' => 'adm-1', 'status' => 'submitted']));
		$this->objectService->expects(self::never())->method('deleteObject');

		$response = $this->controller()->destroy(id: 'sub-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(
			['error' => 'conflict', 'message' => 'Only draft submissions may be deleted (current status: submitted)'],
			$response->getData()
		);
	}//end testDestroyRefusesANonDraftWith409()
}//end class
