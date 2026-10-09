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

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$groupManager = $this->createStub(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);
		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('get')->willReturn(null);

		$seeder = new RuleTestDataSeeder(
			appConfig: $appConfig,
			userManager: $userManager,
			groupManager: $groupManager,
			logger: $this->createStub(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($os),
		);

		$seeder->seed();

		$lines = $os->dump(schema: 'GLLine');
		self::assertCount(2, $lines);
		foreach ($lines as $line) {
			self::assertSame('MEM-2026-0001', $line['transactionId']);
			self::assertSame('adm-7', $line['administrationId']);
		}

	}//end testSeededBalanceLegsCarryTheParentsAdministration()
}//end class
