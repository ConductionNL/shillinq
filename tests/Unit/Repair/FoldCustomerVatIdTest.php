<?php

/**
 * FoldCustomerVatId tests (tax-vat-number-check task 1.1)
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\FoldCustomerVatId;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One spelling for a customer's VAT number.
 */
class FoldCustomerVatIdTest extends TestCase {

	/**
	 * A vatID moves to an empty vatId; a filled vatId is kept; a rerun changes nothing.
	 *
	 * @return void
	 */
	public function testTheOldSpellingMovesIntoTheCheckedField(): void {
		$store = new InMemoryObjectServiceStub(
			[
				'CustomerMaster' => [
					['id' => 'c-1', 'customerId' => 'DEB-0001', 'legalName' => 'Gemeente Zuidoost', 'vatID' => 'NL001234567B01', 'administrationId' => 'adm-1'],
					['id' => 'c-2', 'customerId' => 'DEB-0002', 'legalName' => 'Kunstverlag Müller GmbH', 'vatID' => 'DE111', 'vatId' => 'DE000000000', 'administrationId' => 'adm-1'],
					['id' => 'c-3', 'customerId' => 'DEB-0003', 'legalName' => 'Brightside Consulting Ltd', 'administrationId' => 'adm-1'],
				],
			]
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$step = new FoldCustomerVatId($store, $settings, $this->createMock(LoggerInterface::class));

		$messages = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);
		$step->run($output);
		$step->run($output);

		$this->assertSame(
			[
				'Shillinq: 1 customer VAT number(s) moved from vatID to vatId.',
				'Shillinq: 0 customer VAT number(s) moved from vatID to vatId.',
			],
			$messages
		);

		$this->assertSame('NL001234567B01', $store->find(id: 'c-1', schema: 'CustomerMaster')->getObject()['vatId']);
		$this->assertSame('DE000000000', $store->find(id: 'c-2', schema: 'CustomerMaster')->getObject()['vatId']);
		$this->assertArrayNotHasKey('vatId', $store->find(id: 'c-3', schema: 'CustomerMaster')->getObject());

	}//end testTheOldSpellingMovesIntoTheCheckedField()

	/**
	 * The register no longer declares vatID on CustomerMaster; vatId is the field.
	 *
	 * @return void
	 */
	public function testTheSchemaKeepsOneSpelling(): void {
		$properties = RegisterSchema::schema('CustomerMaster')['properties'];

		$this->assertArrayNotHasKey('vatID', $properties);
		$this->assertArrayHasKey('vatId', $properties);

	}//end testTheSchemaKeepsOneSpelling()
}//end class
