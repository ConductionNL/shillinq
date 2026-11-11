<?php

/**
 * Golden tests for InventoryMobileScannerController: refusals, arguments, roles and location rows.
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
use OCA\Shillinq\Service\RegisterLocation;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins what each action reads and calls, in order, and what it answers.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class InventoryMobileScannerControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string,mixed> $setup user (bool), context, operations, groups, admin (bool), or (bool), rows (array|Throwable).
	 *
	 * @return InventoryMobileScannerController
	 */
	private function controller(array $setup): InventoryMobileScannerController {
		$user = null;
		if (($setup['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturnCallback(
				function (): string {
					$this->calls[] = ['getUID'];
					return 'u1';
				}
			);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use ($user): ?IUser {
				$this->calls[] = ['getUser'];
				return $user;
			}
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key) use ($setup): mixed {
				$this->calls[] = ['getParam', $key];
				return ($setup['operations'] ?? null);
			}
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $userId) use ($setup): bool {
				$this->calls[] = ['isAdmin', $userId];
				return ($setup['admin'] ?? false);
			}
		);
		$groups->method('getUserGroupIds')->willReturnCallback(
			function () use ($setup): array {
				$this->calls[] = ['getUserGroupIds'];
				return ($setup['groups'] ?? []);
			}
		);
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(($setup['or'] ?? true));
		$settings->method('getRegisterSlug')->willReturn('reg-f');
		$admin = $this->createMock(AdministrationContextService::class);
		$admin->method('buildContext')->willReturnCallback(
			function () use ($setup): array {
				$this->calls[] = ['buildContext'];
				return ($setup['context'] ?? ['activeAdministrationId' => 'adm-1']);
			}
		);
		$scanner = $this->createMock(InventoryMobileScannerService::class);
		$scanner->method('downloadDeltas')->willReturnCallback(
			function (?string $since, string $administrationId): array {
				$this->calls[] = ['downloadDeltas', $since, $administrationId];
				return ['deltas' => ['d'], 'serverTimestamp' => 'T', 'extra' => 1];
			}
		);
		$scanner->method('uploadOperations')->willReturnCallback(
			function (array $operations, string $userId, array $roles, string $administrationId): array {
				$this->calls[] = ['uploadOperations', $operations, $userId, $roles, $administrationId];
				return ['results' => ['r'], 'serverTimestamp' => 'T', 'extra' => 1];
			}
		);
		$store = new class($this, ($setup['rows'] ?? [])) {
			/**
			 * Construct.
			 *
			 * @param InventoryMobileScannerControllerGoldenTest $test Recorder.
			 * @param array<int,mixed>|\Throwable                $rows Rows.
			 */
			public function __construct(private InventoryMobileScannerControllerGoldenTest $test, private array|\Throwable $rows) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param string $register Slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->test->calls[] = ['setRegister', $register];
				return $this;
			}//end setRegister()

			/**
			 * Record.
			 *
			 * @param string $schema Slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->test->calls[] = ['setSchema', $schema];
				return $this;
			}//end setSchema()

			/**
			 * Record and answer.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $params): array {
				$this->test->calls[] = ['findAll', $params];
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}

				return $this->rows;
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['warning', $message, $context];
			}
		);

		return new InventoryMobileScannerController($request, $session, $groups, $settings, $admin, $scanner, $container, $logger);
	}//end controller()

	/**
	 * Status and body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private static function answer(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * Every action refuses 401 without a user and 403 without an active administration, reading nothing else.
	 *
	 * @return void
	 */
	public function testSessionAndScopeRefusals(): void {
		foreach (['downloadDeltas', 'uploadOperations', 'listLocations'] as $action) {
			$this->calls = [];
			$response = $this->controller(setup: ['user' => false])->$action();
			self::assertSame(expected: [401, ['message' => 'Not logged in']], actual: self::answer($response));
			self::assertSame(expected: [['getUser']], actual: $this->calls);
			foreach ([[], ['activeAdministrationId' => ''], ['activeAdministrationId' => null]] as $context) {
				$this->calls = [];
				$response = $this->controller(setup: ['context' => $context])->$action();
				self::assertSame(expected: [403, ['message' => 'No administration in scope for current user']], actual: self::answer($response));
				self::assertSame(expected: [['getUser'], ['buildContext']], actual: $this->calls);
			}
		}
	}//end testSessionAndScopeRefusals()

	/**
	 * The since value is trimmed, blank means a full sync, and only deltas + serverTimestamp are answered.
	 *
	 * @return void
	 */
	public function testDownloadDeltas(): void {
		$response = $this->controller(setup: ['context' => ['activeAdministrationId' => 7]])->downloadDeltas(' 2026-01-01T00:00:00Z ');
		self::assertSame(expected: [200, ['deltas' => ['d'], 'serverTimestamp' => 'T']], actual: self::answer($response));
		$this->controller(setup: [])->downloadDeltas('   ');
		$this->controller(setup: [])->downloadDeltas();
		$calls = array_values(array_filter($this->calls, fn (array $c): bool => $c[0] === 'downloadDeltas'));
		self::assertSame(
			expected: [['downloadDeltas', '2026-01-01T00:00:00Z', '7'], ['downloadDeltas', null, 'adm-1'], ['downloadDeltas', null, 'adm-1']],
			actual: $calls
		);
	}//end testDownloadDeltas()

	/**
	 * A non-array or oversized batch is refused; a valid one carries the de-duplicated roles.
	 *
	 * @return void
	 */
	public function testUploadOperations(): void {
		$response = $this->controller(setup: ['operations' => 'x'])->uploadOperations();
		self::assertSame(expected: [400, ['message' => 'Missing or invalid operations array']], actual: self::answer($response));
		$response = $this->controller(setup: ['operations' => array_fill(0, 201, [])])->uploadOperations();
		self::assertSame(expected: [400, ['message' => 'Batch exceeds maximum size 200']], actual: self::answer($response));
		$this->calls = [];
		$setup = ['operations' => array_fill(0, 200, ['op']), 'admin' => true, 'groups' => ['counter', 'other', 'warehouse_manager', 'counter']];
		$response = $this->controller(setup: $setup)->uploadOperations();
		self::assertSame(expected: [200, ['results' => ['r'], 'serverTimestamp' => 'T']], actual: self::answer($response));
		self::assertSame(
			expected: [
				['getUser'],
				['buildContext'],
				['getParam', 'operations'],
				['getUID'],
				['getUser'],
				['isAdmin', 'u1'],
				['getUserGroupIds'],
				['getUID'],
				['uploadOperations', array_fill(0, 200, ['op']), 'u1', ['admin', 'counter', 'warehouse_manager'], 'adm-1'],
			],
			actual: $this->calls
		);
	}//end testUploadOperations()

	/**
	 * Locations are queried by administration and projected from arrays, entities and plain objects.
	 *
	 * @return void
	 */
	public function testListLocations(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * Serialise.
			 *
			 * @return array<string,string>
			 */
			public function jsonSerialize(): array {
				return ['code' => 'E1', 'name' => 'Entity'];
			}//end jsonSerialize()
		};
		$scalarEntity = new class implements \JsonSerializable {
			/**
			 * Public field read by the object cast.
			 *
			 * @var string
			 */
			public string $code = 'S1';

			/**
			 * Serialise to a scalar.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'x';
			}//end jsonSerialize()
		};
		$plain = new \stdClass();
		$plain->warehouse = 'W9';
		$rows = [['code' => 1, 'name' => 'A', 'warehouse' => 'W', 'x' => 'y'], $entity, $scalarEntity, $plain, 'junk'];
		$expected = [
			['code' => '1', 'name' => 'A', 'warehouse' => 'W'],
			['code' => 'E1', 'name' => 'Entity', 'warehouse' => ''],
			['code' => 'S1', 'name' => '', 'warehouse' => ''],
			['code' => '', 'name' => '', 'warehouse' => 'W9'],
			['code' => '', 'name' => '', 'warehouse' => ''],
		];
		$response = $this->controller(setup: ['rows' => $rows])->listLocations();
		self::assertSame(expected: [200, ['locations' => $expected]], actual: self::answer($response));
		self::assertSame(
			expected: [
				['setRegister', 'reg-f'],
				['setSchema', 'Location'],
				['findAll', ['filters' => ['administrationId' => 'adm-1'], 'limit' => 500]],
			],
			actual: array_slice($this->calls, 2)
		);
	}//end testListLocations()

	/**
	 * Without OpenRegister, or when the lookup fails, the list is empty; the failure is a warning.
	 *
	 * @return void
	 */
	public function testListLocationsFallbacks(): void {
		self::assertSame(expected: [200, ['locations' => []]], actual: self::answer($this->controller(setup: ['or' => false])->listLocations()));
		self::assertSame(expected: [['getUser'], ['buildContext']], actual: $this->calls);
		$this->calls = [];
		$response = $this->controller(setup: ['rows' => new \RuntimeException('down')])->listLocations();
		self::assertSame(expected: [200, ['locations' => []]], actual: self::answer($response));
		$warning = ['warning', 'Shillinq mobile scanner: location list lookup failed', ['exception' => 'down']];
		self::assertSame(expected: $warning, actual: end($this->calls));
	}//end testListLocationsFallbacks()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
