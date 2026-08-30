<?php

/**
 * Unit tests for WBSOExportValidationGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\WBSOExportValidationGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WBSOExportValidationGuardTest extends TestCase {
	/**
	 * @param array<int,array<string,mixed>> $entries UrenRegistratie rows.
	 *
	 * @return WBSOExportValidationGuard
	 */
	private function buildGuard(array $entries): WBSOExportValidationGuard {
		$stub = new class($entries) {
			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $entries;

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<int,array<string,mixed>> $entries UrenRegistratie rows.
			 */
			public function __construct(array $entries) {
				$this->entries = $entries;
			}//end __construct()

			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				if ($this->schema === 'WBSOActivityCode') {
					// Not reachable in tests (shillinq#434) — guard degrades
					// gracefully via its own try/catch around this lookup.
					throw new \RuntimeException('WBSOActivityCode schema not registered (shillinq#434)');
				}

				return $this->entries;
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new WBSOExportValidationGuard(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end buildGuard()

	/**
	 * Good path: every entry fully tagged.
	 *
	 * @return void
	 */
	public function testValidateAllowedWhenAllEntriesTagged(): void {
		$guard = $this->buildGuard(
			[
				['id' => 'ur-1', 'wbsoTagId' => 'tag-1', 'activityCodeId' => 'code-1'],
				['id' => 'ur-2', 'wbsoTagId' => 'tag-1', 'activityCodeId' => 'code-2'],
			]
		);

		$allowed = $guard->requireEligibleEntries(
			['administrationId' => 'adm-1', 'periodStart' => '2026-01-01', 'periodEnd' => '2026-03-31']
		);
		self::assertTrue($allowed);

	}//end testValidateAllowedWhenAllEntriesTagged()

	/**
	 * Bad path: an entry is missing wbsoTagId — deny validate.
	 *
	 * @return void
	 */
	public function testValidateDeniedWhenEntryMissingWbsoTag(): void {
		$guard = $this->buildGuard(
			[
				['id' => 'ur-1', 'wbsoTagId' => null, 'activityCodeId' => 'code-1'],
			]
		);

		$allowed = $guard->requireEligibleEntries(
			['administrationId' => 'adm-1', 'periodStart' => '2026-01-01', 'periodEnd' => '2026-03-31']
		);
		self::assertFalse($allowed);

	}//end testValidateDeniedWhenEntryMissingWbsoTag()

	/**
	 * Bad path: an entry is missing activityCodeId — deny validate.
	 *
	 * @return void
	 */
	public function testValidateDeniedWhenEntryMissingActivityCode(): void {
		$guard = $this->buildGuard(
			[
				['id' => 'ur-1', 'wbsoTagId' => 'tag-1', 'activityCodeId' => null],
			]
		);

		$allowed = $guard->requireEligibleEntries(
			['administrationId' => 'adm-1', 'periodStart' => '2026-01-01', 'periodEnd' => '2026-03-31']
		);
		self::assertFalse($allowed);

	}//end testValidateDeniedWhenEntryMissingActivityCode()

	/**
	 * No entries in range — trivially allowed.
	 *
	 * @return void
	 */
	public function testValidateAllowedWithNoEntries(): void {
		$guard = $this->buildGuard([]);

		$allowed = $guard->requireEligibleEntries(
			['administrationId' => 'adm-1', 'periodStart' => '2026-01-01', 'periodEnd' => '2026-03-31']
		);
		self::assertTrue($allowed);

	}//end testValidateAllowedWithNoEntries()
}//end class
