<?php

/**
 * Unit tests for VATReturnController.
 *
 * Exercises the HTTP-shape and validation paths for the
 * bookkeeping-vat-btw-filing change (issue #127). Service interactions
 * are mocked; OR ObjectService interactions are mocked through the
 * container so the real OR-API call shape stays honest.
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
 * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\VATReturnController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\VATReturnService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the VAT-return API controller.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VATReturnControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock service.
	 *
	 * @var VATReturnService&MockObject
	 */
	private VATReturnService&MockObject $service;

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock IL10N — client-facing error messages (ADR-050).
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

	/**
	 * Mock AdministrationContextService — the ADR-005 membership guard.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * What canAccess() answers. Flipped by the ADR-005 refusal tests.
	 *
	 * Read through a callback rather than re-stubbed per test: a second
	 * `->method('canAccess')` APPENDS a matcher instead of replacing the first,
	 * so re-stubbing would silently keep answering true.
	 *
	 * @var bool
	 */
	private bool $canAccess = true;

	/**
	 * What accessibleAdministrationIds() answers when canAccess is true.
	 *
	 * Separate from $canAccess so a test can hold MORE than one membership and
	 * still distinguish "the scope is the caller's memberships" from "the scope
	 * was narrowed to the requested administration" — with a single-element
	 * default the two are indistinguishable and a narrowing bug reads as green.
	 *
	 * @var array<int,string>
	 */
	private array $accessible = ['adm-1'];

	/**
	 * Controller under test.
	 *
	 * @var VATReturnController
	 */
	private VATReturnController $controller;

	/**
	 * Currently-authenticated user (mutable across a single test).
	 *
	 * @var IUser|null
	 */
	private ?IUser $currentUser = null;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(VATReturnService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$this->context = $this->createMock(AdministrationContextService::class);

		$this->canAccess = true;
		$this->accessible = ['adm-1'];
		$this->context->method('canAccess')->willReturnCallback(fn (): bool => $this->canAccess);
		$this->context->method('accessibleAdministrationIds')->willReturnCallback(
			fn (): array => $this->canAccess === true ? $this->accessible : []
		);

		$this->controller = new VATReturnController(
			request: $this->request,
			service: $this->service,
			container: $this->container,
			session: $this->session,
			context: $this->context,
			logger: $this->logger,
			l10n: $this->l10n,
		);

		// Bind the session once to a mutable reference; tests can override the
		// current user mid-test via withUser() without re-stubbing the mock.
		$this->session->method('getUser')->willReturnCallback(
			fn (): ?IUser => $this->currentUser
		);
		$this->withUser(uid: 'admin');

	}//end setUp()

	/**
	 * Configure request params via callback.
	 *
	 * @param array<string,mixed> $params Param key/value map.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return $params[$key] ?? $default;
			}
		);

	}//end withParams()

	/**
	 * Bind the container to return an inline ObjectService fake.
	 *
	 * @param object $stub Inline stub.
	 *
	 * @return void
	 */
	private function withObjectService(object $stub): void {
		$this->container->method('get')->willReturn($stub);

	}//end withObjectService()

	/**
	 * Bind the session to return a user with the given uid.
	 *
	 * @param string $uid User uid.
	 *
	 * @return void
	 */
	private function withUser(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->currentUser = $user;

	}//end withUser()

	/**
	 * Build an inline ObjectService fake.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => records.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $data): object {
		return new class($data) {
			/**
			 * Records keyed by schema slug.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Schema => records.
			 */
			public function __construct(array $data) {
				$this->data = $data;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Equality-filter findAll.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Find by id.
			 *
			 * @param string $id Record id.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id): ?array {
				foreach (($this->data[$this->schema] ?? []) as $row) {
					if (((string)($row['id'] ?? '')) === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

			/**
			 * Save (insert).
			 *
			 * @param array<string,mixed> $data Record body.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $data): array {
				$data['id'] = ($data['id'] ?? ('id-' . count($this->data[$this->schema] ?? [])));
				$this->data[$this->schema][] = $data;
				return $data;
			}//end saveObject()

			/**
			 * Delete by id.
			 *
			 * @param string $id Record id.
			 *
			 * @return void
			 */
			public function deleteObject(string $id): void {
				foreach (($this->data[$this->schema] ?? []) as $idx => $row) {
					if (((string)($row['id'] ?? '')) === $id) {
						unset($this->data[$this->schema][$idx]);
					}
				}

				$this->data[$this->schema] = array_values($this->data[$this->schema] ?? []);
			}//end deleteObject()
		};

	}//end fakeObjectService()

	/**
	 * index() returns paginated list + total.
	 *
	 * @return void
	 */
	public function testIndexReturnsPaginatedList(): void {
		$this->withParams(['_page' => 1, '_limit' => 10]);
		$this->withObjectService(
			$this->fakeObjectService(
				[
					// administrationId is now part of every index() query: the
					// listing is scoped to the caller's memberships (ADR-005).
					'BtwAangifte' => [
						['id' => 'r-1', 'administrationId' => 'adm-1', 'period' => 'quarter', 'regime' => 'standard', 'statusCode' => 'draft'],
						['id' => 'r-2', 'administrationId' => 'adm-1', 'period' => 'quarter', 'regime' => 'kor', 'statusCode' => 'submitted'],
					],
				]
			)
		);

		$response = $this->controller->index();
		$payload = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(2, $payload['total']);
		self::assertCount(2, $payload['data']);

	}//end testIndexReturnsPaginatedList()

	/**
	 * index() applies whitelisted filters (regime).
	 *
	 * @return void
	 */
	public function testIndexFiltersByRegime(): void {
		$this->withParams(['regime' => 'kor', '_page' => 1, '_limit' => 10]);
		$this->withObjectService(
			$this->fakeObjectService(
				[
					// administrationId is now part of every index() query: the
					// listing is scoped to the caller's memberships (ADR-005).
					'BtwAangifte' => [
						['id' => 'r-1', 'administrationId' => 'adm-1', 'period' => 'quarter', 'regime' => 'standard', 'statusCode' => 'draft'],
						['id' => 'r-2', 'administrationId' => 'adm-1', 'period' => 'quarter', 'regime' => 'kor', 'statusCode' => 'submitted'],
					],
				]
			)
		);

		$response = $this->controller->index();
		$payload = $response->getData();

		self::assertSame(1, $payload['total']);
		self::assertSame('kor', $payload['data'][0]['regime']);

	}//end testIndexFiltersByRegime()

	/**
	 * show() returns the return + declarations + lines.
	 *
	 * @return void
	 */
	public function testShowReturnsReturnWithChildren(): void {
		// The record itself is resolved through VATReturnService::findReturn(),
		// which owns the ObjectEntity → array normalisation. This stub used to
		// reach straight into a fake ObjectService whose find() handed back an
		// ARRAY — a shape the real OpenRegister never produces (find() is
		// `: ?ObjectEntity`), so the test agreed with the controller's broken
		// `is_array()` check and both were wrong together.
		$this->service->method('findReturn')
			->willReturn(['id' => 'ret-1', 'statusCode' => 'draft']);
		$this->withObjectService(
			$this->fakeObjectService(
				[
					'VATDeclaration' => [['id' => 'd-1', 'returnId' => 'ret-1', 'type' => 'collected', 'taxRate' => 21.0]],
					'VATLine' => [['id' => 'l-1', 'returnId' => 'ret-1', 'type' => 'collected', 'taxRate' => 21.0]],
				]
			)
		);

		$response = $this->controller->show(returnId: 'ret-1');
		$payload = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('ret-1', $payload['data']['id']);
		self::assertCount(1, $payload['declarations']);
		self::assertCount(1, $payload['lines']);

	}//end testShowReturnsReturnWithChildren()

	/**
	 * show() returns 404 when missing.
	 *
	 * @return void
	 */
	public function testShowReturns404WhenMissing(): void {
		$this->withObjectService($this->fakeObjectService(['BtwAangifte' => []]));
		$response = $this->controller->show(returnId: 'ret-missing');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testShowReturns404WhenMissing()

	/**
	 * create() with a future period yields 400 (REQ-VAT-001 validation).
	 *
	 * @return void
	 */
	public function testCreateRejectsFuturePeriod(): void {
		$futureYear = ((int)gmdate(format: 'Y') + 1);
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'period' => 'quarter',
				'periodYear' => $futureYear,
				'periodNumber' => 1,
				'regime' => 'standard',
			]
		);

		$response = $this->controller->create();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testCreateRejectsFuturePeriod()

	/**
	 * create() with an invalid regime yields 400.
	 *
	 * @return void
	 */
	public function testCreateRejectsInvalidRegime(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'period' => 'quarter',
				'periodYear' => 2026,
				'periodNumber' => 1,
				'regime' => 'imaginary',
			]
		);

		$response = $this->controller->create();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testCreateRejectsInvalidRegime()

	/**
	 * create() delegates to the service and returns 201 with the persisted record.
	 *
	 * @return void
	 */
	public function testCreateDelegatesToService(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'period' => 'quarter',
				'periodYear' => 2024,
				'periodNumber' => 1,
				'regime' => 'standard',
			]
		);
		$this->service->expects($this->once())
			->method('createReturn')
			->with('adm-1', 'quarter', 2024, 1, 'standard')
			->willReturn(['id' => 'ret-new', 'statusCode' => 'draft']);

		$response = $this->controller->create();
		$payload = $response->getData();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('ret-new', $payload['data']['id']);

	}//end testCreateDelegatesToService()

	/**
	 * submit() returns 200 + the submitted return on success.
	 *
	 * @return void
	 */
	public function testSubmitReturns200(): void {
		$this->withUser(uid: 'alice');
		// submit() now loads the return first to authorise the caller against
		// its administration (ADR-005) before filing it.
		$this->service->method('findReturn')
			->willReturn(['id' => 'ret-1', 'administrationId' => 'adm-1', 'statusCode' => 'draft']);
		$this->service->expects($this->once())
			->method('submitReturn')
			->with('ret-1', 'alice')
			->willReturn(['id' => 'ret-1', 'statusCode' => 'submitted']);

		$response = $this->controller->submit(returnId: 'ret-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('submitted', $response->getData()['data']['statusCode']);

	}//end testSubmitReturns200()

	/**
	 * submit() returns 409 when the service rejects (e.g. already submitted).
	 *
	 * @return void
	 */
	public function testSubmitReturns409OnConflict(): void {
		$this->withUser(uid: 'alice');
		$this->service->method('findReturn')
			->willReturn(['id' => 'ret-1', 'administrationId' => 'adm-1', 'statusCode' => 'submitted']);
		$this->service->method('submitReturn')->willThrowException(new \RuntimeException('not draft'));

		$response = $this->controller->submit(returnId: 'ret-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testSubmitReturns409OnConflict()

	/**
	 * rebase() returns 200 + the rebased return.
	 *
	 * @return void
	 */
	public function testRebaseReturns200(): void {
		$this->withUser(uid: 'bob');
		$this->service->method('findReturn')
			->willReturn(['id' => 'ret-1', 'administrationId' => 'adm-1', 'statusCode' => 'submitted']);
		$this->service->expects($this->once())
			->method('rebaseReturn')
			->with('ret-1', 'bob')
			->willReturn(['id' => 'ret-1', 'statusCode' => 'draft']);

		$response = $this->controller->rebase(returnId: 'ret-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testRebaseReturns200()

	/**
	 * destroy() deletes a draft return and returns 200.
	 *
	 * @return void
	 */
	public function testDestroyDeletesDraft(): void {
		$this->service->method('findReturn')
			->willReturn(['id' => 'ret-del', 'statusCode' => 'draft']);
		$this->withObjectService($this->fakeObjectService([]));

		$response = $this->controller->destroy(returnId: 'ret-del');
		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testDestroyDeletesDraft()

	/**
	 * destroy() rejects non-draft returns with 409.
	 *
	 * @return void
	 */
	public function testDestroyRejectsNonDraft(): void {
		$this->service->method('findReturn')
			->willReturn(['id' => 'ret-submitted', 'statusCode' => 'submitted']);
		$this->withObjectService($this->fakeObjectService([]));

		$response = $this->controller->destroy(returnId: 'ret-submitted');
		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testDestroyRejectsNonDraft()

	/**
	 * create() refuses to file a statutory VAT return into a foreign administration (#518).
	 *
	 * This is the worst of the 23 findings: `POST /api/vat-returns` took
	 * `administrationId` straight off the wire behind nothing but
	 * `preg_match('/^[A-Za-z0-9_.\-]{1,64}$/')`, while
	 * VATReturnService::createReturn()'s own docblock declares the parameter
	 * "Server-resolved administration scope". Live on a two-account rig the
	 * pre-fix call answered **HTTP 201**.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testCreateRefusesAForeignAdministration(): void {
		$this->canAccess = false;
		$this->withParams(
			[
				'administrationId' => 'adm-not-mine',
				'period' => 'quarter',
				'periodYear' => 2020,
				'periodNumber' => 1,
				'regime' => 'standard',
			]
		);
		$this->service->expects($this->never())->method('createReturn');

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testCreateRefusesAForeignAdministration()

	/**
	 * show/update/submit/rebase/destroy all mask a foreign return as 404 (#518).
	 *
	 * The whole BtwAangifte chain resolved `returnId` with
	 * `->setSchema('BtwAangifte')->find($returnId)` and no administration
	 * filter, so quoting another tenant's id was enough to read it, edit it,
	 * file it, or revert a submitted filing to draft. Every one answered 200
	 * live before this change.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testForeignReturnIsMaskedAs404OnEveryVerb(): void {
		$this->canAccess = false;
		$this->withParams(['notes' => 'owned by someone else']);
		$this->service->method('findReturn')
			->willReturn(['id' => 'ret-1', 'administrationId' => 'adm-not-mine', 'statusCode' => 'draft']);
		$this->service->expects($this->never())->method('submitReturn');
		$this->service->expects($this->never())->method('rebaseReturn');
		$this->withObjectService($this->fakeObjectService(['BtwAangifte' => []]));

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->show(returnId: 'ret-1')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->update(returnId: 'ret-1')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->submit(returnId: 'ret-1')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->rebase(returnId: 'ret-1')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->destroy(returnId: 'ret-1')->getStatus());

	}//end testForeignReturnIsMaskedAs404OnEveryVerb()

	/**
	 * index() lists only administrations the caller is a member of (#518).
	 *
	 * `administrationId` used to be an OPTIONAL filter, so omitting it returned
	 * every tenant's statutory returns in one page.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testIndexIsScopedToTheCallersMemberships(): void {
		$this->withParams(['_page' => 1, '_limit' => 10]);
		$this->withObjectService(
			$this->fakeObjectService(
				[
					'BtwAangifte' => [
						['id' => 'r-1', 'administrationId' => 'adm-1', 'period' => 'quarter', 'regime' => 'standard', 'statusCode' => 'draft'],
						['id' => 'r-9', 'administrationId' => 'adm-9', 'period' => 'quarter', 'regime' => 'standard', 'statusCode' => 'draft'],
					],
				]
			)
		);

		$payload = $this->controller->index()->getData();

		self::assertSame(1, $payload['total']);
		self::assertSame('r-1', $payload['data'][0]['id']);

	}//end testIndexIsScopedToTheCallersMemberships()

	/**
	 * create() refuses an omitted administrationId with 400, before any lookup.
	 *
	 * The membership guard runs first and its "not well-formed" arm is the only
	 * one that answers 400 rather than 404: an empty administration is a client
	 * error, an inaccessible one is masked as absent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testCreateRefusesAnOmittedAdministrationId(): void {
		$this->withParams(
			[
				'administrationId' => '',
				'period' => 'quarter',
				'periodYear' => 2024,
				'periodNumber' => 1,
				'regime' => 'standard',
			]
		);
		$this->service->expects($this->never())->method('createReturn');

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('administrationId is required', $response->getData()['error']);

	}//end testCreateRefusesAnOmittedAdministrationId()

	/**
	 * create() rejects a period unit outside quarter | month | year.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testCreateRejectsAnUnknownPeriodUnit(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'period' => 'fortnight',
				'periodYear' => 2024,
				'periodNumber' => 1,
				'regime' => 'standard',
			]
		);
		$this->service->expects($this->never())->method('createReturn');

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('period must be one of quarter | month | year', $response->getData()['error']);

	}//end testCreateRejectsAnUnknownPeriodUnit()

	/**
	 * create() rejects a fiscal year outside the 2020..2099 window.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testCreateRejectsAnOutOfRangeFiscalYear(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'period' => 'quarter',
				'periodYear' => 1999,
				'periodNumber' => 1,
				'regime' => 'standard',
			]
		);
		$this->service->expects($this->never())->method('createReturn');

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('periodYear / periodNumber must be valid', $response->getData()['error']);

	}//end testCreateRejectsAnOutOfRangeFiscalYear()

	/**
	 * create() rejects a fifth quarter — the period unit bounds its number.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testCreateRejectsAFifthQuarter(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'period' => 'quarter',
				'periodYear' => 2024,
				'periodNumber' => 5,
				'regime' => 'standard',
			]
		);
		$this->service->expects($this->never())->method('createReturn');

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('periodNumber must be 1..4 for quarter', $response->getData()['error']);

	}//end testCreateRejectsAFifthQuarter()

	/**
	 * create() rejects a thirteenth month.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testCreateRejectsAThirteenthMonth(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'period' => 'month',
				'periodYear' => 2024,
				'periodNumber' => 13,
				'regime' => 'standard',
			]
		);
		$this->service->expects($this->never())->method('createReturn');

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('periodNumber must be 1..12 for month', $response->getData()['error']);

	}//end testCreateRejectsAThirteenthMonth()

	/**
	 * index() refuses an explicit administrationId the caller is not a member of.
	 *
	 * The filter may only NARROW the membership scope; a value outside it is
	 * masked as 404 rather than silently ignored, because silently ignoring it
	 * would answer 200 with the caller's own rows and read as success.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testIndexRefusesAForeignAdministrationFilter(): void {
		$this->canAccess = false;
		$this->withParams(['administrationId' => 'adm-9', '_page' => 1, '_limit' => 10]);
		$this->container->expects($this->never())->method('get');

		$response = $this->controller->index();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Administration not found', $response->getData()['error']);

	}//end testIndexRefusesAForeignAdministrationFilter()

	/**
	 * index() narrows the scope to an explicit administration inside it.
	 *
	 * The caller holds two memberships and asks for one; the other's rows must
	 * not appear. With a single-membership fixture this assertion cannot fail,
	 * which is why $accessible carries two ids here.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testIndexNarrowsToAnExplicitAdministrationInScope(): void {
		$this->accessible = ['adm-1', 'adm-2'];
		$this->withParams(['administrationId' => 'adm-2', '_page' => 1, '_limit' => 10]);
		$this->withObjectService(
			$this->fakeObjectService(
				[
					'BtwAangifte' => [
						['id' => 'r-1', 'administrationId' => 'adm-1', 'period' => 'quarter', 'regime' => 'standard', 'statusCode' => 'draft'],
						['id' => 'r-2', 'administrationId' => 'adm-2', 'period' => 'quarter', 'regime' => 'standard', 'statusCode' => 'draft'],
					],
				]
			)
		);

		$payload = $this->controller->index()->getData();

		self::assertSame(1, $payload['total']);
		self::assertSame('r-2', $payload['data'][0]['id']);

	}//end testIndexNarrowsToAnExplicitAdministrationInScope()
}//end class
