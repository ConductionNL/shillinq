<?php

/**
 * Unit tests for SupplierQualificationService.
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
 * @spec openspec/specs/procurement-governance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SupplierQualificationService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Proves duplicate-supplier rejection (REQ-PG-003) and qualify gating (REQ-PG-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SupplierQualificationServiceTest extends TestCase {
	/**
	 * Build the service over an in-memory ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 *
	 * @return SupplierQualificationService
	 */
	private function buildService(array $data): SupplierQualificationService {
		// ADR-084: the in-memory stub used to reach the service through a
		// ContainerInterface mock, while `objectService:` got a bare
		// createMock() — so the service consulted an EMPTY double and the seeded
		// $data was never read. Inject the stub itself.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturn(true);
		$administrationContext->method('currentUserId')->willReturn('procurement-1');

		return new SupplierQualificationService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new InMemoryObjectServiceStub($data),
		);
	}//end buildService()

	/**
	 * Registering a supplier whose tax ID already exists is rejected.
	 *
	 * @return void
	 */
	public function testDuplicateTaxIdIsRejected(): void {
		$service = $this->buildService(
			data: [
				'SupplierQualification' => [
					[
						'administrationId' => 'adm-1',
						'supplierId' => 'SUP-1',
						'taxId' => 'NL001234567B01',
						'iban' => 'NL91ABNA0417164300',
						'statusCode' => 'qualified',
					],
				],
			]
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('tax ID already exists');
		$service->registerSupplier(
			administrationId: 'adm-1',
			payload: ['supplierId' => 'SUP-2', 'supplierName' => 'Other B.V.', 'taxId' => 'NL001234567B01', 'iban' => 'NL02RABO0123456789'],
		);
	}//end testDuplicateTaxIdIsRejected()

	/**
	 * Registering a supplier whose IBAN already exists is rejected.
	 *
	 * @return void
	 */
	public function testDuplicateIbanIsRejected(): void {
		$service = $this->buildService(
			data: [
				'SupplierQualification' => [
					[
						'administrationId' => 'adm-1',
						'supplierId' => 'SUP-1',
						'taxId' => 'NL001234567B01',
						'iban' => 'NL91ABNA0417164300',
						'statusCode' => 'qualified',
					],
				],
			]
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('IBAN already exists');
		$service->registerSupplier(
			administrationId: 'adm-1',
			payload: ['supplierId' => 'SUP-2', 'supplierName' => 'Other B.V.', 'taxId' => 'NL999999999B01', 'iban' => 'NL91ABNA0417164300'],
		);
	}//end testDuplicateIbanIsRejected()

	/**
	 * A fresh, unique supplier registers as draft.
	 *
	 * @return void
	 */
	public function testRegisterUniqueSupplierPersistsDraft(): void {
		$service = $this->buildService(data: []);

		$record = $service->registerSupplier(
			administrationId: 'adm-1',
			payload: [
				'supplierId' => 'SUP-9',
				'supplierName' => 'Fresh Vendor B.V.',
				'taxId' => 'NL555555555B01',
				'iban' => 'NL77INGB0000000000',
				'requiredDocuments' => [['documentType' => 'iso-9001', 'provided' => true, 'expiresAt' => '2099-01-01']],
			],
		);

		self::assertSame('draft', $record['statusCode']);
		self::assertSame('SUP-9', $record['supplierId']);
	}//end testRegisterUniqueSupplierPersistsDraft()

	/**
	 * The qualify() method refuses a supplier with an expired required document.
	 *
	 * @return void
	 */
	public function testQualifyRefusesExpiredDocument(): void {
		$service = $this->buildService(
			data: [
				'SupplierQualification' => [
					[
						'id' => 'sq-1',
						'administrationId' => 'adm-1',
						'supplierId' => 'SUP-1',
						'statusCode' => 'draft',
						'requiredDocuments' => [['documentType' => 'iso-9001', 'provided' => true, 'expiresAt' => '2020-01-01']],
					],
				],
			]
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('missing or expired');
		$service->qualify(administrationId: 'adm-1', supplierId: 'SUP-1', approverId: 'procurement-1');
	}//end testQualifyRefusesExpiredDocument()

	/**
	 * The qualify() method promotes a draft supplier with valid documents.
	 *
	 * @return void
	 */
	public function testQualifyPromotesValidSupplier(): void {
		$service = $this->buildService(
			data: [
				'SupplierQualification' => [
					[
						'id' => 'sq-1',
						'administrationId' => 'adm-1',
						'supplierId' => 'SUP-1',
						'statusCode' => 'draft',
						'requiredDocuments' => [['documentType' => 'iso-9001', 'provided' => true, 'expiresAt' => '2099-01-01']],
					],
				],
			]
		);

		$record = $service->qualify(administrationId: 'adm-1', supplierId: 'SUP-1', approverId: 'procurement-1');
		self::assertSame('qualified', $record['statusCode']);
		self::assertSame('procurement-1', $record['qualifiedBy']);
	}//end testQualifyPromotesValidSupplier()
}//end class
