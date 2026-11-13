<?php

/**
 * Unit tests for which register RgsAccountMapper reads.
 *
 * Every read of the chart of accounts and the RGS reference goes to the
 * register the app is configured with. A blank configured value falls back
 * to the app's own register rather than reading an unnamed one.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\BbvMigration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-bbv-compliance/specs/bookkeeping-bbv-compliance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\BbvMigration;

use OCA\Shillinq\Service\BbvMigration\RgsAccountMapper;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The register the RGS mapper reads.
 *
 * @spec openspec/changes/bookkeeping-bbv-compliance/specs/bookkeeping-bbv-compliance/spec.md
 */
class RgsAccountMapperRegisterTest extends TestCase {

	/**
	 * An object service that records every register and schema it is pointed at.
	 *
	 * @return object
	 */
	private function recordingObjectService(): object {
		return new class {
			/**
			 * @var array<int, string>
			 */
			public array $reads = [];

			private string $register = '';

			/**
			 * @param string $register The register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->register = $register;
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema The schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->reads[] = $this->register . '/' . $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $params The query.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return [];
			}//end findAll()
		};
	}//end recordingObjectService()

	/**
	 * Run suggestMappings with the given configured register value.
	 *
	 * @param string $configured The configured `register` value.
	 *
	 * @return array<int, string> The register/schema pairs read.
	 */
	private function readsWith(string $configured): array {
		$objects = $this->recordingObjectService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $appId, string $key, string $default): string => ($key === 'register') ? $configured : $default
		);

		$result = (new RgsAccountMapper(container: $container, appConfig: $appConfig))->suggestMappings(administrationId: 'admin-1');

		self::assertTrue($result['success']);
		self::assertSame([], $result['suggestions']);

		return $objects->reads;
	}//end readsWith()

	/**
	 * A configured register is used for every read.
	 *
	 * @return void
	 */
	public function testEveryReadGoesToTheConfiguredRegister(): void {
		$reads = $this->readsWith('gemeente-zuid');

		self::assertNotSame([], $reads);
		self::assertSame(['gemeente-zuid'], array_values(array_unique(array_map(static fn (string $r): string => explode('/', $r)[0], $reads))));
	}//end testEveryReadGoesToTheConfiguredRegister()

	/**
	 * A blank configured register falls back to the app's own register.
	 *
	 * @return void
	 */
	public function testABlankConfiguredRegisterFallsBackToShillinq(): void {
		$blank = $this->readsWith('');

		self::assertSame($this->readsWith('shillinq'), $blank);
		self::assertStringStartsWith('shillinq/', $blank[0]);
	}//end testABlankConfiguredRegisterFallsBackToShillinq()
}//end class
