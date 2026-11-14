<?php

/**
 * Golden tests for SupplierQualificationService::registerSupplier: its gates in order and the saved record.
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
use Throwable;

/**
 * Registration refuses in a fixed order and saves one draft record.
 */
final class SupplierQualificationRegisterGoldenTest extends TestCase {

	/**
	 * The objects saved.
	 *
	 * @var array<int, mixed>
	 */
	private array $saved = [];

	/**
	 * The administrations asked about.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * The service; only adm-1 is accessible; adm-1 already has taxId NL001 and IBAN NL99.
	 *
	 * @return SupplierQualificationService
	 */
	private function service(): SupplierQualificationService {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$context = $this->createStub(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId): bool {
				$this->asked[] = $administrationId;
				return $administrationId === 'adm-1';
			}
		);
		$context->method('currentUserId')->willReturn('procurement-1');
		$store = new InMemoryObjectServiceStub(
			[
				'SupplierQualification' => [
					['id' => 'sq-1', 'administrationId' => 'adm-1', 'taxId' => 'NL001', 'iban' => 'NL00BANK0000000001'],
					['id' => 'sq-2', 'administrationId' => 'adm-1', 'taxId' => 'NL002', 'iban' => 'NL99'],
				],
			],
			$this->saved
		);

		return new SupplierQualificationService(appConfig: $appConfig, administrationContext: $context, logger: $this->createStub(LoggerInterface::class), objectService: $store);

	}//end service()

	/**
	 * The outcome of one registration.
	 *
	 * @param string               $administrationId The administration.
	 * @param array<string, mixed> $payload          The payload.
	 *
	 * @return string The exception message, or "saved".
	 */
	private function outcome(string $administrationId, array $payload): string {
		try {
			$this->service()->registerSupplier(administrationId: $administrationId, payload: $payload);
			return 'saved';
		} catch (Throwable $e) {
			return $e->getMessage();
		}

	}//end outcome()

	/**
	 * Every refusal, in the order the gates run.
	 *
	 * @return void
	 */
	public function testRefusalsInOrder(): void {
		$this->assertSame(
			[
				'administrationId is required',
				'Administration not found',
				'supplierId is required',
				'supplierName is required',
			],
			[
				$this->outcome(administrationId: '', payload: []),
				$this->outcome(administrationId: 'adm-2', payload: []),
				$this->outcome(administrationId: 'adm-1', payload: ['supplierId' => '  ', 'supplierName' => 'X', 'taxId' => 'NL001']),
				$this->outcome(administrationId: 'adm-1', payload: ['supplierId' => 's-1', 'supplierName' => ' ', 'taxId' => 'NL001']),
			]
		);
		$this->assertSame(['adm-2', 'adm-1', 'adm-1'], $this->asked);
		$this->assertNotSame('saved', $this->outcome(administrationId: 'adm-1', payload: ['supplierId' => 's-1', 'supplierName' => 'X', 'taxId' => ' NL001 ']));
		$this->assertNotSame('saved', $this->outcome(administrationId: 'adm-1', payload: ['supplierId' => 's-1', 'supplierName' => 'X', 'iban' => 'NL99']));
		$this->assertSame([], $this->saved);

	}//end testRefusalsInOrder()

	/**
	 * The saved draft record.
	 *
	 * @return void
	 */
	public function testSavedRecordGolden(): void {
		$record = $this->service()->registerSupplier(
			administrationId: 'adm-1',
			payload: [
				'supplierId'        => ' s-1 ',
				'supplierName'      => ' Drukkerij ',
				'taxId'             => ' NL777 ',
				'iban'              => '',
				'requiredDocuments' => [['documentType' => ' kvk ', 'provided' => 1, 'expiresAt' => ' 2027-01-01 '], 'skip', ['documentType' => 'vog']],
				'statusCode'        => 'qualified',
			]
		);

		$expected = [
			'administrationId'  => 'adm-1',
			'supplierId'        => 's-1',
			'supplierName'      => 'Drukkerij',
			'taxId'             => 'NL777',
			'iban'              => '',
			'requiredDocuments' => [
				['documentType' => 'kvk', 'provided' => true, 'expiresAt' => '2027-01-01'],
				['documentType' => 'vog', 'provided' => false, 'expiresAt' => ''],
			],
			'statusCode'        => 'draft',
		];
		$this->assertCount(1, $this->saved);
		$this->assertSame($expected, array_intersect_key($record, $expected));
		$this->assertSame(array_keys($expected), array_slice(array_keys($record), 0, count($expected)));

	}//end testSavedRecordGolden()
}//end class
