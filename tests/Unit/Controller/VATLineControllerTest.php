<?php

/**
 * Unit tests for VATLineController.
 *
 * Covers the ADR-005 / REQ-MA-001 administration scoping added by #520.
 * `findLines()` used to run ONE query keyed only on `returnId` /
 * `declarationId`, with no administration term at all, so quoting another
 * tenant's return id was enough to read its VAT lines. The scope is now the
 * caller's memberships, one query per administration, and a caller with no
 * memberships gets an empty list rather than everything.
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
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\VATLineController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the VAT-line read API.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VATLineControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

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
	 * Mock membership guard.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The caller's memberships, read through a callback so a test can change
	 * them without re-stubbing (a second ->method() APPENDS a matcher rather
	 * than replacing the first, so a re-stub would silently keep the old answer).
	 *
	 * @var array<int,string>
	 */
	private array $accessible = ['adm-1'];

	/**
	 * Controller under test.
	 *
	 * @var VATLineController
	 */
	private VATLineController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->accessible = ['adm-1'];
		$this->context->method('accessibleAdministrationIds')->willReturnCallback(
			fn (): array => $this->accessible
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);

		$this->controller = new VATLineController(
			request: $this->request,
			container: $this->container,
			session: $this->session,
			context: $this->context,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Build an ObjectService double whose signatures mirror the real service.
	 *
	 * Parameter NAMES matter: the controller calls `setRegister(register:)`,
	 * `setSchema(schema:)` and merges `findAll()`'s result, so a double that
	 * renamed them would accept a call the real OpenRegister rejects with
	 * "Unknown named parameter" — the shape that kept a 912-line suite green
	 * over a live outage elsewhere in the fleet.
	 *
	 * @param array<int,array<string,mixed>> $rows VATLine rows to serve.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $rows): object {
		return new class($rows) {
			/**
			 * Rows to serve.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $rows;

			/**
			 * Filter sets received, in call order — the assertion subject for
			 * "one query per administration".
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $seenFilters = [];

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $rows Rows to serve.
			 */
			public function __construct(array $rows) {
				$this->rows = $rows;
			}//end __construct()

			/**
			 * Fluent register setter (mirrors ObjectService::setRegister).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter (mirrors ObjectService::setSchema).
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Equality-filter findAll (mirrors ObjectService::findAll).
			 *
			 * @param array<string,mixed> $config Query configuration.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config = []): array {
				$filters = ($config['filters'] ?? []);
				$this->seenFilters[] = $filters;

				return array_values(
					array_filter(
						$this->rows,
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
		};

	}//end fakeObjectService()

	/**
	 * listByReturn() returns only lines in an administration the caller holds.
	 *
	 * Two rows share one returnId across two tenants — the pre-#520 query would
	 * have returned both, because it carried no administration term.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testListByReturnIsScopedToTheCallersMemberships(): void {
		$stub = $this->fakeObjectService(
			[
				['id' => 'l-1', 'returnId' => 'ret-1', 'administrationId' => 'adm-1'],
				['id' => 'l-9', 'returnId' => 'ret-1', 'administrationId' => 'adm-9'],
			]
		);
		$this->container->method('get')->willReturn($stub);

		$response = $this->controller->listByReturn(returnId: 'ret-1');
		$payload = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(1, $payload['total']);
		self::assertSame('l-1', $payload['data'][0]['id']);
		self::assertSame(
			[['returnId' => 'ret-1', 'administrationId' => 'adm-1']],
			$stub->seenFilters,
			'the administration term must be part of the query, not applied afterwards'
		);

	}//end testListByReturnIsScopedToTheCallersMemberships()

	/**
	 * listByDeclaration() unions the caller's administrations, one query each.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testListByDeclarationUnionsEveryMembership(): void {
		$this->accessible = ['adm-1', 'adm-2'];
		$stub = $this->fakeObjectService(
			[
				['id' => 'l-1', 'declarationId' => 'dec-1', 'administrationId' => 'adm-1'],
				['id' => 'l-2', 'declarationId' => 'dec-1', 'administrationId' => 'adm-2'],
				['id' => 'l-9', 'declarationId' => 'dec-1', 'administrationId' => 'adm-9'],
			]
		);
		$this->container->method('get')->willReturn($stub);

		$payload = $this->controller->listByDeclaration(declarationId: 'dec-1')->getData();

		self::assertSame(2, $payload['total']);
		self::assertSame(['l-1', 'l-2'], array_column($payload['data'], 'id'));
		self::assertCount(2, $stub->seenFilters, 'one query per administration');

	}//end testListByDeclarationUnionsEveryMembership()

	/**
	 * A caller with no memberships gets an empty list, not everything.
	 *
	 * The empty scope means the loop body never runs, so no query is issued at
	 * all — the assertion on seenFilters is what separates "scoped to nothing"
	 * from "queried without a scope and happened to match nothing".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testACallerWithNoMembershipsSeesNothing(): void {
		$this->accessible = [];
		$stub = $this->fakeObjectService(
			[['id' => 'l-1', 'returnId' => 'ret-1', 'administrationId' => 'adm-1']]
		);
		$this->container->method('get')->willReturn($stub);

		$payload = $this->controller->listByReturn(returnId: 'ret-1')->getData();

		self::assertSame(0, $payload['total']);
		self::assertSame([], $payload['data']);
		self::assertSame([], $stub->seenFilters);

	}//end testACallerWithNoMembershipsSeesNothing()

	/**
	 * A malformed id is refused with 400 before the data layer is touched.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testAMalformedReturnIdIsRefused(): void {
		$this->container->expects($this->never())->method('get');

		$response = $this->controller->listByReturn(returnId: '');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testAMalformedReturnIdIsRefused()

	/**
	 * A data-layer failure is reported as 500, never as an empty success.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function testADataLayerFailureIsNotReportedAsAnEmptyList(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('no ObjectService'));

		$response = $this->controller->listByReturn(returnId: 'ret-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('Failed to list VAT lines', $response->getData()['error']);

	}//end testADataLayerFailureIsNotReportedAsAnEmptyList()
}//end class
