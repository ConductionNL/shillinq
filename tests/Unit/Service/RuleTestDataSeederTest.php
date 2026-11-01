<?php

/**
 * Unit tests for RuleTestDataSeeder.
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
 * @spec openspec/changes/glline-administration-scope/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\RuleTestDataSeeder;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * RuleTestDataSeeder unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RuleTestDataSeederTest extends TestCase {

	/**
	 * REQ-GLS-001: both seeded balance legs carry the parent transaction's
	 * administration, so the spend views never read an unscoped seed row.
	 *
	 * @return void
	 */
	public function testSeededBalanceLegsCarryTheParentsAdministration(): void {
		$os = new InMemoryObjectService();
		$os->setSchema('GLTransaction')->saveObject(
			[
				'id' => 'tx-1',
				'transactionNumber' => 'MEM-2026-0001',
				'sourceReference' => 'DOC-1',
				'administrationId' => 'adm-7',
				'currency' => 'EUR',
			]
		);

		$this->seeder(os: $os)->seed();

		$lines = $os->dump(schema: 'GLLine');
		self::assertCount(2, $lines);
		foreach ($lines as $line) {
			self::assertSame('MEM-2026-0001', $line['transactionId']);
			self::assertSame('adm-7', $line['administrationId']);
		}

	}//end testSeededBalanceLegsCarryTheParentsAdministration()

	/**
	 * A sales invoice whose seller VAT id and type code are blank strings gets
	 * the defaults, so the next run finds it complete and saves nothing.
	 *
	 * @return void
	 */
	public function testABlankSellerVatIdGetsTheDefaultAndIsNotSeededAgain(): void {
		$os = new InMemoryObjectService();
		$os->setSchema('ARInvoice')->saveObject(
			[
				'id' => 'ar-1',
				'netAmount' => 100,
				'vatAmount' => 21,
				'sellerVatId' => '',
				'invoiceTypeCode' => ' ',
				'sellerName' => 'Own Seller B.V.',
			]
		);

		$first = $this->seeder(os: $os)->seed();
		$invoice = $os->dump(schema: 'ARInvoice')[0];
		self::assertSame(1, $first['invoiceLinesAdded']);
		self::assertSame('NL123456789B01', $invoice['sellerVatId']);
		self::assertSame('380', $invoice['invoiceTypeCode']);
		self::assertSame('Own Seller B.V.', $invoice['sellerName'], 'A value already set is kept.');

		$second = $this->seeder(os: $os)->seed();
		self::assertSame(0, $second['invoiceLinesAdded'], 'The completed invoice is not saved again.');

	}//end testABlankSellerVatIdGetsTheDefaultAndIsNotSeededAgain()

	/**
	 * A seeder over the in-memory store, with no admin user.
	 *
	 * @param InMemoryObjectService $os The store.
	 *
	 * @return RuleTestDataSeeder
	 */
	private function seeder(InMemoryObjectService $os): RuleTestDataSeeder {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$groupManager = $this->createStub(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);
		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('get')->willReturn(null);

		return new RuleTestDataSeeder(
			appConfig: $appConfig,
			userManager: $userManager,
			groupManager: $groupManager,
			logger: $this->createStub(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($os),
		);

	}//end seeder()
}//end class
