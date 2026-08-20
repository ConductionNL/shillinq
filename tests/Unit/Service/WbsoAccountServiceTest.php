<?php

/**
 * Unit tests for WbsoAccountService.
 *
 * Covers REQ-WBSO-001 (schema fields + validations), REQ-WBSO-006
 * (hierarchy navigation), and the depth + circular-ref preconditions
 * mirrored from the schema's x-openregister-constraint.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-31
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\WbsoAccountService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-31
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WbsoAccountServiceTest extends TestCase {

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock app-config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');
	}//end setUp()

	/**
	 * Build a service with an ObjectService stub.
	 *
	 * @param array<int,array<string,mixed>> $accounts Account rows the stub returns.
	 *
	 * @return WbsoAccountService
	 */
	private function buildService(array $accounts): WbsoAccountService {
		$stub = new class($accounts) {

			/**
			 * Backing data.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $accounts;

			/**
			 * Last saved object.
			 *
			 * @var array<string,mixed>|null
			 */
			public ?array $saved = null;

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $accounts Initial rows.
			 */
			public function __construct(array $accounts) {
				$this->accounts = $accounts;
			}

			/**
			 * Fluent.
			 *
			 * @param string $r Register.
			 *
			 * @return static
			 */
			public function setRegister(string $r): static {
				return $this;
			}

			/**
			 * Fluent.
			 *
			 * @param string $s Schema.
			 *
			 * @return static
			 */
			public function setSchema(string $s): static {
				return $this;
			}

			/**
			 * Match the OR API.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $this->accounts;
				}

				return array_values(array_filter(
					$this->accounts,
					static function (array $row) use ($filters): bool {
						foreach ($filters as $k => $v) {
							if (($row[$k] ?? null) !== $v) {
								return false;
							}
						}
						return true;
					}
				));
			}

			/**
			 * Save passthrough.
			 *
			 * @param array<string,mixed> $object Object.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->saved = $object;
				return $object;
			}
		};

		$this->container->method('get')->willReturn($stub);

		return new WbsoAccountService(appConfig: $this->appConfig,
			objectService: new DuckObjectServiceAdapter($stub),
		);
	}//end buildService()

	/**
	 * Happy path: list accounts filtered by administration.
	 *
	 * @return void
	 */
	public function testGetAccountsByAdministration(): void {
		$rows = [
			['accountNumber' => '1000', 'name' => 'Kas', 'administrationId' => 'adm-1', 'status' => 'active', 'accountType' => 'assets'],
			['accountNumber' => '4100', 'name' => 'Omzet', 'administrationId' => 'adm-2', 'status' => 'active', 'accountType' => 'revenue'],
		];

		$service = $this->buildService($rows);
		$result = $service->getAccountsByAdministration(administrationId: 'adm-1');

		self::assertCount(1, $result);
		self::assertSame('1000', $result[0]['accountNumber']);

	}//end testGetAccountsByAdministration()

	/**
	 * Hierarchy builds parent → children tree.
	 *
	 * @return void
	 */
	public function testBuildHierarchyNestsChildren(): void {
		$service = $this->buildService([]);
		$tree = $service->buildHierarchy([
			['accountNumber' => '1', 'name' => 'Top assets', 'accountType' => 'assets', 'status' => 'active'],
			['accountNumber' => '1000', 'parentAccountNumber' => '1', 'name' => 'Kas', 'accountType' => 'assets', 'status' => 'active'],
			['accountNumber' => '1500', 'parentAccountNumber' => '1', 'name' => 'Crediteuren', 'accountType' => 'liabilities', 'status' => 'active'],
		]);

		self::assertCount(1, $tree);
		self::assertSame('1', $tree[0]['accountNumber']);
		self::assertCount(2, $tree[0]['children']);

	}//end testBuildHierarchyNestsChildren()

	/**
	 * Validation rejects unknown accountType.
	 *
	 * @return void
	 */
	public function testValidationRejectsUnknownAccountType(): void {
		$service = $this->buildService([]);

		$this->expectException(InvalidArgumentException::class);
		$service->validatePayload([
			'accountNumber' => '9999',
			'name' => 'Bad',
			'accountType' => 'unknown',
			'administrationId' => 'adm-1',
		]);

	}//end testValidationRejectsUnknownAccountType()

	/**
	 * Validation rejects non-EUR currency.
	 *
	 * @return void
	 */
	public function testValidationRejectsNonEurCurrency(): void {
		$service = $this->buildService([]);

		$this->expectException(InvalidArgumentException::class);
		$service->validatePayload([
			'accountNumber' => '1000',
			'name' => 'USD account',
			'accountType' => 'assets',
			'currency' => 'USD',
			'administrationId' => 'adm-1',
		]);

	}//end testValidationRejectsNonEurCurrency()

	/**
	 * Parent must reference an active account.
	 *
	 * @return void
	 */
	public function testAssertHierarchyRejectsBlockedParent(): void {
		$service = $this->buildService([
			['accountNumber' => '1', 'name' => 'Top', 'status' => 'blocked', 'administrationId' => 'adm-1'],
		]);

		$this->expectException(InvalidArgumentException::class);
		$service->assertHierarchy(administrationId: 'adm-1', accountNumber: '1000', parent: '1');

	}//end testAssertHierarchyRejectsBlockedParent()

	/**
	 * Depth > 5 is rejected.
	 *
	 * @return void
	 */
	public function testAssertHierarchyRejectsExcessiveDepth(): void {
		// Build a 5-deep ancestor chain (1 → 2 → 3 → 4 → 5) so adding a
		// child under 5 would create depth 6.
		$accounts = [];
		$parent = '';
		foreach (['1', '2', '3', '4', '5'] as $node) {
			$accounts[] = [
				'accountNumber' => $node,
				'name' => 'Lvl ' . $node,
				'parentAccountNumber' => $parent,
				'status' => 'active',
				'administrationId' => 'adm-1',
			];
			$parent = $node;
		}

		$service = $this->buildService($accounts);

		$this->expectException(InvalidArgumentException::class);
		$service->assertHierarchy(administrationId: 'adm-1', accountNumber: '6', parent: '5');

	}//end testAssertHierarchyRejectsExcessiveDepth()

	/**
	 * Circular reference (account is its own ancestor) is rejected.
	 *
	 * @return void
	 */
	public function testAssertHierarchyRejectsCircularReference(): void {
		$accounts = [
			['accountNumber' => '1', 'parentAccountNumber' => '2', 'status' => 'active', 'administrationId' => 'adm-1'],
			['accountNumber' => '2', 'parentAccountNumber' => '1', 'status' => 'active', 'administrationId' => 'adm-1'],
		];
		$service = $this->buildService($accounts);

		$this->expectException(InvalidArgumentException::class);
		$service->assertHierarchy(administrationId: 'adm-1', accountNumber: '1', parent: '2');

	}//end testAssertHierarchyRejectsCircularReference()

	/**
	 * Create persists a valid account.
	 *
	 * @return void
	 */
	public function testCreateAccount(): void {
		$service = $this->buildService([]);
		$row = $service->createAccount(
			administrationId: 'adm-1',
			payload: [
				'accountNumber' => '1000',
				'name' => 'Kas en bank',
				'accountType' => 'assets',
				'currency' => 'EUR',
			]
		);

		self::assertSame('1000', $row['accountNumber']);
		self::assertSame('adm-1', $row['administrationId']);

	}//end testCreateAccount()

	/**
	 * Update merges new fields on top of the stored row.
	 *
	 * @return void
	 */
	public function testUpdateAccountMergesFields(): void {
		$accounts = [
			[
				'accountNumber' => '1000',
				'name' => 'Old name',
				'accountType' => 'assets',
				'status' => 'active',
				'currency' => 'EUR',
				'administrationId' => 'adm-1',
			],
		];

		$service = $this->buildService($accounts);
		$row = $service->updateAccount(
			administrationId: 'adm-1',
			accountNumber: '1000',
			payload: ['name' => 'New name', 'description' => 'Updated'],
		);

		self::assertSame('New name', $row['name']);
		self::assertSame('Updated', $row['description']);
		self::assertSame('assets', $row['accountType']);

	}//end testUpdateAccountMergesFields()
}//end class
