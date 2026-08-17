<?php

/**
 * Unit tests for SupplierQualificationGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/procurement-governance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\SupplierQualificationGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Proves the fail-closed supplier-qualification gate (REQ-PG-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SupplierQualificationGuardTest extends TestCase {
	/**
	 * Build an in-memory ObjectService stub honouring equality filters.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 *
	 * @return object
	 */
	private function buildStub(array $data): object {
		return new class($data) {
			/**
			 * Schema rows.
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
			 * @param array<string,array<int,array<string,mixed>>> $data Rows.
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
			 * Return rows for the active schema, applying equality filters.
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
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
		};
	}//end buildStub()

	/**
	 * Build the guard over an in-memory ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 *
	 * @return SupplierQualificationGuard
	 */
	private function buildGuard(array $data): SupplierQualificationGuard {
		$stub = $this->buildStub($data);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new SupplierQualificationGuard(
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($stub),
		);
	}//end buildGuard()

	/**
	 * A supplier with no qualification record is blocked from a first PO.
	 *
	 * @return void
	 */
	public function testUnqualifiedSupplierIsBlocked(): void {
		$guard = $this->buildGuard(data: []);

		self::assertFalse($guard->isQualifiedForPo(administrationId: 'adm-1', supplierId: 'SUP-1'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('not qualified');
		$guard->assertQualifiedForPo(administrationId: 'adm-1', supplierId: 'SUP-1');
	}//end testUnqualifiedSupplierIsBlocked()

	/**
	 * A "qualified" supplier whose required certificate has expired is blocked.
	 *
	 * @return void
	 */
	public function testExpiredDocumentSupplierIsBlocked(): void {
		$guard = $this->buildGuard(
			data: [
				'SupplierQualification' => [
					[
						'administrationId' => 'adm-1',
						'supplierId' => 'SUP-1',
						'statusCode' => 'qualified',
						'requiredDocuments' => [
							['documentType' => 'iso-9001', 'provided' => true, 'expiresAt' => '2020-01-01'],
						],
					],
				],
			]
		);

		self::assertFalse($guard->isQualifiedForPo(administrationId: 'adm-1', supplierId: 'SUP-1'));
		$this->expectException(\RuntimeException::class);
		$guard->assertQualifiedForPo(administrationId: 'adm-1', supplierId: 'SUP-1');
	}//end testExpiredDocumentSupplierIsBlocked()

	/**
	 * A qualified supplier with all documents provided and unexpired passes.
	 *
	 * @return void
	 */
	public function testQualifiedSupplierWithValidDocumentsPasses(): void {
		$guard = $this->buildGuard(
			data: [
				'SupplierQualification' => [
					[
						'administrationId' => 'adm-1',
						'supplierId' => 'SUP-1',
						'statusCode' => 'qualified',
						'requiredDocuments' => [
							['documentType' => 'iso-9001', 'provided' => true, 'expiresAt' => '2099-12-31'],
							['documentType' => 'insurance', 'provided' => true, 'expiresAt' => ''],
						],
					],
				],
			]
		);

		self::assertTrue($guard->isQualifiedForPo(administrationId: 'adm-1', supplierId: 'SUP-1'));
		$guard->assertQualifiedForPo(administrationId: 'adm-1', supplierId: 'SUP-1');
		$this->addToAssertionCount(1);
	}//end testQualifiedSupplierWithValidDocumentsPasses()
}//end class
