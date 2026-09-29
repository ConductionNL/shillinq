<?php

/**
 * The repair step that marks existing control accounts.
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
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\BackfillControlAccountRoles;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Lifecycle\Action\InMemoryObjectStore;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An administration on the seed chart gets its four control accounts
 * marked; a renamed account, one on another chart and one that already has
 * a role are left alone, and each account set is logged.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BackfillControlAccountRolesTest extends TestCase {

	/**
	 * Mark the seeded administration and log every account set.
	 *
	 * @return void
	 */
	public function testTheSeedChartsControlAccountsAreMarkedAndLogged(): void {
		$store = new InMemoryObjectStore();
		$store->rows['Account'] = [
			['id' => 'a1', 'administrationId' => 'adm-1', 'accountNumber' => '1100', 'name' => 'Debiteuren', 'accountType' => 'assets', 'currency' => 'EUR', 'lifecycleState' => 'active'],
			['id' => 'a2', 'administrationId' => 'adm-1', 'accountNumber' => '2000', 'name' => 'Crediteuren', 'accountType' => 'liabilities', 'currency' => 'EUR', 'lifecycleState' => 'active'],
			['id' => 'a3', 'administrationId' => 'adm-1', 'accountNumber' => '1230', 'name' => 'BTW-vordering', 'accountType' => 'assets', 'currency' => 'EUR', 'lifecycleState' => 'active'],
			['id' => 'a4', 'administrationId' => 'adm-1', 'accountNumber' => '2110', 'name' => 'btw-schuld', 'accountType' => 'liabilities', 'currency' => 'EUR', 'lifecycleState' => 'active'],
			['id' => 'b1', 'administrationId' => 'adm-bbv', 'accountNumber' => '1100', 'name' => 'Belastingen', 'accountType' => 'assets', 'currency' => 'EUR', 'lifecycleState' => 'active'],
			['id' => 'c1', 'administrationId' => 'adm-3', 'accountNumber' => '2000', 'name' => 'Crediteuren', 'controlAccountFor' => 'expense-claims', 'accountType' => 'liabilities', 'currency' => 'EUR', 'lifecycleState' => 'active'],
		];

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$messages = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		(new BackfillControlAccountRoles($settings, $this->createMock(LoggerInterface::class), $store->mock($this)))->run($output);

		$saved = [];
		foreach ($store->savedOf('Account') as $account) {
			$saved[$account['administrationId'] . ':' . $account['accountNumber']] = $account['controlAccountFor'];
			self::assertSame([], RegisterSchema::errors(slug: 'Account', object: $account), 'the saved account validates');
		}

		self::assertSame(
			['adm-1:1100' => 'receivables', 'adm-1:2000' => 'payables', 'adm-1:1230' => 'vat', 'adm-1:2110' => 'vat'],
			$saved
		);
		self::assertCount(5, $messages, 'four accounts logged, plus the total');
		self::assertStringContainsString('1100 Debiteuren in administration adm-1 is now the receivables control account', $messages[0]);
	}//end testTheSeedChartsControlAccountsAreMarkedAndLogged()
}//end class
