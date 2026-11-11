<?php

/**
 * Golden tests for SupplierInvoiceChecks::duplicateOf: the queries it makes and the record it picks.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Purchasing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Purchasing;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Purchasing\SupplierInvoiceChecks;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The duplicate lookup reads both sources in order, scoped to the administration, and skips the ignored ids.
 */
class SupplierInvoiceDuplicateGoldenTest extends TestCase {

	/**
	 * The queries made, as [schema, config, rbac, multitenancy].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: bool, 3: bool}>
	 */
	private array $queries = [];

	/**
	 * The checks over a store answering per schema.
	 *
	 * @param array<string, list<mixed>|RuntimeException> $answers Schema => rows or a failure.
	 *
	 * @return SupplierInvoiceChecks
	 */
	private function checks(array $answers): SupplierInvoiceChecks {
		$schema = '';
		$store  = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnSelf();
		$store->method('setSchema')->willReturnCallback(
			static function (string|int $name) use (&$schema, $store): ObjectServiceInterface {
				$schema = (string)$name;
				return $store;
			}
		);
		$store->method('findAll')->willReturnCallback(
			function (array $config = [], bool $rbac = true, bool $multitenancy = true) use (&$schema, $answers): array {
				$this->queries[] = [$schema, $config, $rbac, $multitenancy];
				$answer          = ($answers[$schema] ?? []);
				if ($answer instanceof RuntimeException) {
					throw $answer;
				}

				return $answer;
			}
		);
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new SupplierInvoiceChecks($store, $settings);

	}//end checks()

	/**
	 * The query of one source.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array{0: string, 1: array<string, mixed>, 2: bool, 3: bool}
	 */
	private function query(string $schema): array {
		return [$schema, ['filters' => ['administrationId' => 'adm-1', 'invoiceNumber' => 'F-1'], 'limit' => 5000], true, true];

	}//end query()

	/**
	 * Both sources read in order; the first matching record not ignored wins.
	 *
	 * @return void
	 */
	public function testPicksTheFirstMatchingRecordAcrossSources(): void {
		$checks = $this->checks(
			[
				'SupplierInvoice' => [
					['id' => 'si-other', 'supplierId' => 'p-2'],
					['id' => 'si-self', 'supplierId' => 'p-1'],
					['supplierId' => 'p-1', 'vendorId' => 'p-1'],
					'not-a-record',
				],
				'APTransaction'   => [
					['id' => 'ap-wrong-field', 'supplierId' => 'p-1'],
					['id' => 'ap-1', 'vendorId' => 'p-1'],
					['id' => 'ap-2', 'vendorId' => 'p-1'],
				],
			]
		);

		$this->assertSame('ap-1', $checks->duplicateOf(administrationId: 'adm-1', supplierId: 'p-1', invoiceNumber: ' F-1 ', ignoreIds: ['si-self', '']));
		$this->assertSame([$this->query(schema: 'SupplierInvoice'), $this->query(schema: 'APTransaction')], $this->queries);

	}//end testPicksTheFirstMatchingRecordAcrossSources()

	/**
	 * A match in the first source stops the lookup.
	 *
	 * @return void
	 */
	public function testAMatchInTheFirstSourceStops(): void {
		$checks = $this->checks(['SupplierInvoice' => [['id' => 'si-1', 'supplierId' => 'p-1']], 'APTransaction' => new RuntimeException('not read')]);

		$this->assertSame('si-1', $checks->duplicateOf(administrationId: 'adm-1', supplierId: 'p-1', invoiceNumber: 'F-1'));
		$this->assertSame([$this->query(schema: 'SupplierInvoice')], $this->queries);

	}//end testAMatchInTheFirstSourceStops()

	/**
	 * A record without an id matches as the empty id unless that is ignored.
	 *
	 * @return void
	 */
	public function testARecordWithoutIdMatchesAsEmpty(): void {
		$this->assertSame('', $this->checks(['SupplierInvoice' => [['supplierId' => 'p-1']]])->duplicateOf(administrationId: 'adm-1', supplierId: 'p-1', invoiceNumber: 'F-1'));
		$this->assertNull($this->checks(['SupplierInvoice' => [['supplierId' => 'p-1']]])->duplicateOf(administrationId: 'adm-1', supplierId: 'p-1', invoiceNumber: 'F-1', ignoreIds: ['']));

	}//end testARecordWithoutIdMatchesAsEmpty()

	/**
	 * No lookup without a number or a supplier; a failure in either source is unknown.
	 *
	 * @return void
	 */
	public function testGuardsAndFailures(): void {
		$checks = $this->checks([]);
		$this->assertNull($checks->duplicateOf(administrationId: 'adm-1', supplierId: 'p-1', invoiceNumber: '  '));
		$this->assertNull($checks->duplicateOf(administrationId: 'adm-1', supplierId: '', invoiceNumber: 'F-1'));
		$this->assertSame([], $this->queries);
		$this->assertNull($checks->duplicateOf(administrationId: 'adm-1', supplierId: 'p-1', invoiceNumber: 'F-1'));
		$this->assertCount(2, $this->queries);

		$failing = $this->checks(['APTransaction' => new RuntimeException('down')]);
		$this->assertSame(SupplierInvoiceChecks::DUPLICATE_UNKNOWN, $failing->duplicateOf(administrationId: 'adm-1', supplierId: 'p-1', invoiceNumber: 'F-1'));

	}//end testGuardsAndFailures()
}//end class
