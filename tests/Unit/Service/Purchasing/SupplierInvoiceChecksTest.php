<?php

/**
 * Unit tests for SupplierInvoiceChecks.
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
 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/specs/bookkeeping-purchase-order-3way/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Purchasing;

use OCA\Shillinq\Service\Purchasing\SupplierInvoiceChecks;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * REQ-PSII-001, 003 and 004: payee, duplicate and IBAN rules.
 */
class SupplierInvoiceChecksTest extends TestCase {

	public const ADMIN = 'adm-voorbeeld';
	public const DRUKKERIJ = '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e4f';

	/**
	 * The records of design.md's seed.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	public static function records(): array {
		return [
			'Payee' => [
				[
					'id' => self::DRUKKERIJ, 'vendorNumber' => 'V-2026-101', 'name' => 'Drukkerij Van der Meer B.V.',
					'kvkNumber' => '12345678', 'vatNumber' => 'NL812345678B01', 'paymentTermDays' => 30,
					'defaultExpenseAccountNumber' => '4300', 'bankAccount' => ['iban' => 'NL20INGB0001234567'],
					'administrationId' => self::ADMIN, 'lifecycleState' => 'active',
				],
			],
			'SupplierInvoice' => [
				['id' => 'si-0455', 'invoiceNumber' => '2026-0455', 'supplierId' => self::DRUKKERIJ, 'administrationId' => self::ADMIN, 'statusCode' => 'received'],
			],
			'APTransaction' => [
				['id' => 'ap-0300', 'invoiceNumber' => '2026-0300', 'vendorId' => self::DRUKKERIJ, 'administrationId' => self::ADMIN, 'state' => 'paid'],
			],
			'SupplierQualification' => [
				['id' => 'sq-1', 'supplierId' => self::DRUKKERIJ, 'iban' => 'NL91ABNA0417164300', 'administrationId' => self::ADMIN],
			],
		];

	}//end records()

	/**
	 * The checks over the given records.
	 *
	 * @param array<string, list<array<string, mixed>>> $records Records by schema.
	 *
	 * @return SupplierInvoiceChecks
	 */
	private function checks(array $records): SupplierInvoiceChecks {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new SupplierInvoiceChecks(new InMemoryObjectServiceStub($records), $settings);

	}//end checks()

	/**
	 * A KvK number finds the payee; a VAT number does when the KvK does not; neither is null.
	 *
	 * @return void
	 */
	public function testThePayeeIsFoundByKvkThenVat(): void {
		$checks = $this->checks(self::records());

		$this->assertSame(self::DRUKKERIJ, $checks->resolvePayee(self::ADMIN, '1234 5678', '')['id']);
		$this->assertSame(self::DRUKKERIJ, $checks->resolvePayee(self::ADMIN, '99999999', 'nl812345678b01')['id']);
		$this->assertNull($checks->resolvePayee(self::ADMIN, '99999999', 'NL000000000B01'));
		$this->assertNull($checks->resolvePayee('adm-other', '12345678', ''));

	}//end testThePayeeIsFoundByKvkThenVat()

	/**
	 * The same number for the same payee is a duplicate, in either schema, but never itself.
	 *
	 * @return void
	 */
	public function testTheSameNumberForTheSamePayeeIsADuplicate(): void {
		$checks = $this->checks(self::records());

		$this->assertSame('si-0455', $checks->duplicateOf(self::ADMIN, self::DRUKKERIJ, '2026-0455'));
		$this->assertSame('ap-0300', $checks->duplicateOf(self::ADMIN, self::DRUKKERIJ, '2026-0300'));
		$this->assertNull($checks->duplicateOf(self::ADMIN, self::DRUKKERIJ, '2026-0455', ['si-0455']));
		$this->assertNull($checks->duplicateOf(self::ADMIN, 'another-payee', '2026-0455'));

	}//end testTheSameNumberForTheSamePayeeIsADuplicate()

	/**
	 * A failed lookup is a possible duplicate, not none.
	 *
	 * @return void
	 */
	public function testAFailedLookupIsAPossibleDuplicate(): void {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willThrowException(new RuntimeException('register gone'));
		$checks = new SupplierInvoiceChecks(new InMemoryObjectServiceStub([]), $settings);

		$this->assertSame(SupplierInvoiceChecks::DUPLICATE_UNKNOWN, $checks->duplicateOf(self::ADMIN, self::DRUKKERIJ, '2026-0455'));

	}//end testAFailedLookupIsAPossibleDuplicate()

	/**
	 * An IBAN the payee or its qualification knows is fine, spaces and case aside.
	 *
	 * @return void
	 */
	public function testAKnownIbanIsNoMismatch(): void {
		$checks = $this->checks(self::records());

		$this->assertNull($checks->ibanMismatch(self::ADMIN, self::DRUKKERIJ, 'nl20 ingb 0001 2345 67'));
		$this->assertNull($checks->ibanMismatch(self::ADMIN, self::DRUKKERIJ, 'NL91ABNA0417164300'));
		$this->assertNull($checks->ibanMismatch(self::ADMIN, self::DRUKKERIJ, ''));

	}//end testAKnownIbanIsNoMismatch()

	/**
	 * An unknown IBAN is named beside the known one.
	 *
	 * @return void
	 */
	public function testAnUnknownIbanIsAMismatch(): void {
		$mismatch = $this->checks(self::records())->ibanMismatch(self::ADMIN, self::DRUKKERIJ, 'NL02ABNA0123456789');

		$this->assertSame(['invoiceIban' => 'NL02ABNA0123456789', 'knownIban' => 'NL20INGB0001234567'], $mismatch);

	}//end testAnUnknownIbanIsAMismatch()
}//end class
