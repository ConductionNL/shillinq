<?php

/**
 * VatReturnBoxRegisterTest
 *
 * Task 1.1 of tax-vat-return-from-books (REQ-VBTW-004): ledger lines, VAT
 * declarations and VAT lines can carry a return box, and the statutory
 * tariffs that say which box a tariff books to are actually seeded.
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
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The register fields of the return box and the seeded tariffs.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VatReturnBoxRegisterTest extends TestCase {

	/**
	 * A ledger line with a tariff, a box and a kind validates against the
	 * merged register; a box that is not on the return does not.
	 *
	 * @return void
	 */
	public function testAGlLineCarriesItsTariffBoxAndKind(): void {
		$line = [
			'transactionId' => '0f8fad5b-d9cb-469f-a165-70867728950e',
			'lineNumber' => 2,
			'accountNumber' => '8000',
			'side' => 'credit',
			'amount' => 500.0,
			'currency' => 'EUR',
			'periodId' => '2026-08',
			'administrationId' => 'adm-1',
			'vatTariffCode' => 'low',
			'vatReturnBox' => '1b',
			'vatAmountKind' => 'base',
		];

		$properties = RegisterSchema::schema('GLLine')['properties'];
		self::assertArrayHasKey('vatTariffCode', $properties);
		self::assertSame([], RegisterSchema::errors(slug: 'GLLine', object: $line));
		self::assertNotSame([], RegisterSchema::errors(slug: 'GLLine', object: ['vatReturnBox' => '9z'] + $line));
		self::assertNotSame([], RegisterSchema::errors(slug: 'GLLine', object: ['vatAmountKind' => 'net'] + $line));
	}//end testAGlLineCarriesItsTariffBoxAndKind()

	/**
	 * A VAT declaration and a VAT line say which box they belong to.
	 *
	 * @return void
	 */
	public function testDeclarationsAndVatLinesCarryTheirBox(): void {
		foreach (['VATDeclaration', 'VATLine'] as $slug) {
			$property = (RegisterSchema::schema($slug)['properties']['returnBox'] ?? []);
			self::assertSame('string', ($property['type'] ?? null), $slug);
			self::assertContains('5b', ($property['enum'] ?? []), $slug);
		}
	}//end testDeclarationsAndVatLinesCarryTheirBox()

	/**
	 * The tariff seeder finds its file, seeds every statutory tariff with the
	 * box it books to, and a second run adds nothing. Before this change it
	 * asked for a file that does not exist and seeded no tariff at all.
	 *
	 * @return void
	 */
	public function testTheTariffSeederSeedsEveryTariffWithItsBoxOnce(): void {
		$store = new InMemoryObjectServiceStub();
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$service = new SettingsService(
			appConfig: $appConfig,
			appManager: $appManager,
			container: $container,
			groupManager: $this->createStub(IGroupManager::class),
			userSession: $this->createStub(IUserSession::class),
			logger: new NullLogger(),
		);

		$first = $service->seedBtwTariffs();
		self::assertTrue($first['success'], (string)($first['message'] ?? ''));

		$boxes = [];
		foreach ($store->setSchema('VatTariff')->findAll() as $tariff) {
			$boxes[$tariff['code']] = ($tariff['section'] ?? null);
			self::assertSame([], RegisterSchema::errors(slug: 'VatTariff', object: $tariff), $tariff['code']);
		}

		self::assertSame(
			[
				'high' => '1a',
				'low' => '1b',
				'zero' => '1e',
				'exempt' => null,
				'reverse-charge' => '2a',
				'reverse-charge-supply' => '1e',
				'intra-eu-supply' => '3b',
				'export' => '3a',
				'intra-eu-acquisition' => '4b',
				'import-non-eu' => '4a',
			],
			$boxes
		);

		$second = $service->seedBtwTariffs();
		self::assertSame(0, $second['seeded']);
		self::assertCount(10, $store->setSchema('VatTariff')->findAll());
	}//end testTheTariffSeederSeedsEveryTariffWithItsBoxOnce()
}//end class
