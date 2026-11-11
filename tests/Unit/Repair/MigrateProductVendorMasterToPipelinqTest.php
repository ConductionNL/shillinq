<?php

/**
 * Characterisation tests for MigrateProductVendorMasterToPipelinq's register wiring.
 *
 * The step reads Product, ProductAttribute and VendorMaster from the register
 * slug the app is configured with, writes the export and marks itself done. A
 * failing slug lookup defers the migration without marking it completed.
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
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\MigrateProductVendorMasterToPipelinq;
use OCA\Shillinq\Service\SettingsService;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins where MigrateProductVendorMasterToPipelinq reads from.
 *
 * @covers \OCA\Shillinq\Repair\MigrateProductVendorMasterToPipelinq
 */
final class MigrateProductVendorMasterToPipelinqTest extends TestCase {

	/**
	 * The empty store; records every register slug it is pointed at.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * App values written through IConfig::setAppValue(), by key.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Collected output messages, prefixed with their level.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * Build the step around an empty recording store.
	 *
	 * @param SettingsService $settings The settings double handing out the register slug.
	 *
	 * @return MigrateProductVendorMasterToPipelinq The step under test.
	 */
	private function makeStep(SettingsService $settings): MigrateProductVendorMasterToPipelinq {
		$this->store = new class() {

			/**
			 * Every register slug passed to setRegister(), in order.
			 *
			 * @var array<int, string>
			 */
			public array $registers = [];

			/**
			 * Fluent register setter that records the slug.
			 *
			 * @param string $register The register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->registers[] = $register;

				return $this;

			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;

			}//end setSchema()

			/**
			 * The store is empty.
			 *
			 * @param array<string, mixed> $config The findAll options.
			 *
			 * @return array<int, array<string, mixed>> No rows.
			 */
			public function findAll(array $config = []): array {
				return [];

			}//end findAll()
		};

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->written[$key] = $value;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);

		return new MigrateProductVendorMasterToPipelinq(
			config: $config,
			logger: $this->createMock(LoggerInterface::class),
			settingsService: $settings,
			container: $container,
			contactsManager: $this->createMock(IContactsManager::class),
		);

	}//end makeStep()

	/**
	 * An output double that records every message with its level.
	 *
	 * @return IOutput The output double.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function ($message): void {
				$this->messages[] = 'info: ' . $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function ($message): void {
				$this->messages[] = 'warning: ' . $message;
			}
		);

		return $output;

	}//end recordingOutput()

	/**
	 * All three schemas are read from the configured register and the step completes.
	 *
	 * @return void
	 */
	public function testReadsTheConfiguredRegisterAndCompletes(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->once())->method('getRegisterSlug')->willReturn('finance');

		$this->makeStep($settings)->run($this->recordingOutput());

		self::assertSame(['finance', 'finance', 'finance'], $this->store->registers);
		self::assertSame('completed', ($this->written['shillinq_pvm_migration_status'] ?? null));
		self::assertSame('info: MigrateProductVendorMasterToPipelinq: completed successfully.', end($this->messages));

	}//end testReadsTheConfiguredRegisterAndCompletes()

	/**
	 * A slug lookup that throws defers the migration and leaves it unmarked.
	 *
	 * @return void
	 */
	public function testSlugLookupFailureDefersTheMigration(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willThrowException(new RuntimeException('config down'));

		$this->makeStep($settings)->run($this->recordingOutput());

		self::assertSame([], $this->store->registers);
		self::assertSame([], $this->written);
		self::assertSame(
			'warning: MigrateProductVendorMasterToPipelinq: exception — migration deferred. Check Nextcloud log for details.',
			end($this->messages)
		);

	}//end testSlugLookupFailureDefersTheMigration()
}//end class
