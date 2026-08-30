<?php

/**
 * Unit tests for InventoryMobileScannerController.
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
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\InventoryMobileScannerController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InventoryMobileScannerService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the warehouse-PWA location list endpoint.
 *
 * Covers the anonymous rejection, the 403 a user without any administration
 * membership gets, the degraded (OpenRegister absent) response, the happy path
 * projection down to code/name/warehouse and the lookup-failure path that must
 * degrade to an empty list rather than a 500.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InventoryMobileScannerControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock IGroupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock SettingsService.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Mock AdministrationContextService.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $admin;

	/**
	 * Mock InventoryMobileScannerService.
	 *
	 * @var InventoryMobileScannerService&MockObject
	 */
	private InventoryMobileScannerService&MockObject $scanner;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The signed-in user, or null for an anonymous session.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * The administration context the service resolves; empty means "no scope".
	 *
	 * @var array<string,mixed>
	 */
	private array $adminContext = ['activeAdministrationId' => 'adm-1'];

	/**
	 * The controller under test.
	 *
	 * @var InventoryMobileScannerController
	 */
	private InventoryMobileScannerController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->admin = $this->createMock(AdministrationContextService::class);
		$this->scanner = $this->createMock(InventoryMobileScannerService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->user = $user;

		$this->userSession->method('getUser')->willReturnCallback(
			function (): ?IUser {
				return $this->user;
			}
		);
		$this->admin->method('buildContext')->willReturnCallback(
			function (): array {
				return $this->adminContext;
			}
		);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');

		$this->controller = new InventoryMobileScannerController(
			request: $this->request,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			settings: $this->settings,
			admin: $this->admin,
			scanner: $this->scanner,
			container: $this->container,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Build a stand-in OpenRegister ObjectService that records the register and
	 * schema it was scoped to and hands back the supplied rows.
	 *
	 * @param array<int,mixed> $rows Rows findAll() should return.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $rows): object {
		return new class($rows) {

			/**
			 * The schema the caller scoped to.
			 *
			 * @var string
			 */
			public string $schema = '';

			/**
			 * The findAll() config the caller supplied.
			 *
			 * @var array<string,mixed>
			 */
			public array $config = [];

			/**
			 * Construct the fake with its canned rows.
			 *
			 * @param array<int,mixed> $rows Rows to return from findAll().
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * Scope to a register.
			 *
			 * @param string $register Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * Scope to a schema.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * Return the canned rows.
			 *
			 * @param array<string,mixed> $config Query config.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $config = []): array {
				$this->config = $config;
				return $this->rows;
			}
		};

	}//end fakeObjectService()

	/**
	 * An anonymous caller is rejected with HTTP 401.
	 *
	 * @return void
	 */
	public function testListLocationsAnonymousReturns401(): void {
		$this->user = null;
		$this->container->expects($this->never())->method('get');

		$response = $this->controller->listLocations();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testListLocationsAnonymousReturns401()

	/**
	 * A user with no administration membership gets HTTP 403 — the PWA must
	 * not be handed a tenant-less location list.
	 *
	 * @return void
	 */
	public function testListLocationsWithoutAdministrationReturns403(): void {
		$this->adminContext = ['activeAdministrationId' => null];
		$this->container->expects($this->never())->method('get');

		$response = $this->controller->listLocations();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testListLocationsWithoutAdministrationReturns403()

	/**
	 * When OpenRegister is not installed the endpoint degrades to an empty
	 * list rather than erroring the whole PWA boot.
	 *
	 * @return void
	 */
	public function testListLocationsWithoutOpenRegisterReturnsEmptyList(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(false);
		$this->container->expects($this->never())->method('get');

		$response = $this->controller->listLocations();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData()['locations']);

	}//end testListLocationsWithoutOpenRegisterReturnsEmptyList()

	/**
	 * The happy path scopes the query to the Location schema, filters on the
	 * server-resolved administration and projects each row down to the three
	 * fields the selector needs.
	 *
	 * @return void
	 */
	public function testListLocationsReturnsProjectedRows(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$fake = $this->fakeObjectService(
			[
				['code' => 'A1', 'name' => 'Aisle 1', 'warehouse' => 'WH-MAIN', 'secretCostPrice' => 42],
				['code' => 'B2', 'name' => 'Aisle 2', 'warehouse' => 'WH-MAIN'],
			]
		);
		$this->container->method('get')->willReturn($fake);

		$response = $this->controller->listLocations();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$locations = $response->getData()['locations'];
		self::assertCount(2, $locations);
		self::assertSame(['code' => 'A1', 'name' => 'Aisle 1', 'warehouse' => 'WH-MAIN'], $locations[0]);
		self::assertArrayNotHasKey('secretCostPrice', $locations[0]);
		self::assertSame('Location', $fake->schema);
		self::assertSame(['administrationId' => 'adm-1'], $fake->config['filters']);

	}//end testListLocationsReturnsProjectedRows()

	/**
	 * A row arriving as an entity (rather than an array) is normalised via
	 * jsonSerialize() instead of being dropped.
	 *
	 * @return void
	 */
	public function testListLocationsNormalisesEntityRows(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$entity = new class implements \JsonSerializable {

			/**
			 * Serialise the entity.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return ['code' => 'C3', 'name' => 'Cold store', 'warehouse' => 'WH-COLD'];
			}
		};
		$this->container->method('get')->willReturn($this->fakeObjectService([$entity]));

		$response = $this->controller->listLocations();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			[['code' => 'C3', 'name' => 'Cold store', 'warehouse' => 'WH-COLD']],
			$response->getData()['locations']
		);

	}//end testListLocationsNormalisesEntityRows()

	/**
	 * A lookup failure is logged and degrades to an empty list — the offline
	 * PWA must still boot, and no stack trace reaches the client.
	 *
	 * @return void
	 */
	public function testListLocationsLookupFailureDegradesToEmptyList(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->container->method('get')->willThrowException(new \RuntimeException('register exploded'));
		$this->logger->expects($this->once())->method('warning');

		$response = $this->controller->listLocations();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData()['locations']);
		self::assertStringNotContainsStringIgnoringCase(
			'register exploded',
			(string)json_encode($response->getData())
		);

	}//end testListLocationsLookupFailureDegradesToEmptyList()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
