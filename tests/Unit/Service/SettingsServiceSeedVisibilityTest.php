<?php

/**
 * Unit tests for SettingsService's skipped-seed reporting.
 *
 * WHY THIS EXISTS. OpenRegister's ImportHandler skips a seed object that
 * fails its schema's `required` list ONE BY ONE (per-entity resilience):
 * it increments $result['skipped'] and logs a warning, then keeps importing
 * the rest. It raises no error. shillinq was RETURNED that count and threw
 * it away, so runLoadConfiguration() reported success over a partially
 * imported register and `occ app:enable` exited 0 — which is why 81 broken
 * seeds stayed invisible until an endpoint merely LOOKED empty.
 *
 * These tests pin the reporting contract: the count is surfaced, it is
 * logged at ERROR level, and — deliberately — it does NOT flip success to
 * false, because failing `occ app:enable` on an existing install would
 * brick an upgrade over a pre-existing data defect.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests that a partially-imported register is reported rather than hidden.
 */
class SettingsServiceSeedVisibilityTest extends TestCase {

	/**
	 * Build a SettingsService whose OpenRegister import returns $importResult.
	 *
	 * @param array<string,mixed> $importResult What ConfigurationService::importFromApp() returns.
	 * @param LoggerInterface $logger Logger to observe.
	 *
	 * @return SettingsService
	 */
	private function serviceReturning(array $importResult, LoggerInterface $logger): SettingsService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		// A minimal stand-in for OCA\OpenRegister\Service\ConfigurationService.
		// Only importFromApp() is exercised by runLoadConfiguration().
		$configurationService = new class($importResult) {

			/**
			 * @param array<string,mixed> $result Canned import result.
			 */
			public function __construct(
				private array $result,
			) {
			}

			/**
			 * Mirrors the real signature — runLoadConfiguration() calls this
			 * with NAMED arguments, so the parameter names are part of the
			 * contract and a bare importFromApp() would TypeError.
			 *
			 * @param string $appId App id.
			 * @param array<string,mixed> $data Register configuration.
			 * @param string $version Configuration version.
			 * @param bool $force Force re-import.
			 *
			 * @return array<string,mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				return $this->result;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($configurationService);

		return new SettingsService(
			$this->createMock(IAppConfig::class),
			$appManager,
			$container,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$logger
		);

	}//end serviceReturning()

	/**
	 * A clean import must report zero skipped seed objects.
	 *
	 * This is the POSITIVE CONTROL for the test below: it proves the same
	 * call path reaches the reporting code and can produce 0, so a non-zero
	 * result there is a real measurement rather than an artefact.
	 *
	 * @return void
	 */
	public function testCleanImportReportsZeroSkippedSeedObjects(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$result = $this->serviceReturning(
			['version' => '1.0.0', 'skipped' => ['objects' => 0, 'seedObjects' => 0]],
			$logger
		)->loadConfigurationForced();

		$this->assertTrue($result['success']);
		$this->assertSame(0, $result['skippedSeedObjects']);

	}//end testCleanImportReportsZeroSkippedSeedObjects()

	/**
	 * Skipped seed objects must be counted, surfaced and logged at ERROR.
	 *
	 * Asserts on the COUNT ITSELF, not merely that some key exists — an
	 * assertion on the container would survive the exact bug this guards.
	 *
	 * @return void
	 */
	public function testSkippedSeedObjectsAreReportedAndLoggedAsError(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('7 seed object(s) could NOT be imported'),
				$this->callback(
					static fn (array $context): bool => ($context['skippedSeedObjects'] ?? null) === 7
				)
			);

		$result = $this->serviceReturning(
			['version' => '1.0.0', 'skipped' => ['objects' => 5, 'seedObjects' => 2]],
			$logger
		)->loadConfigurationForced();

		// 5 objects + 2 seedObjects — BOTH counters, because ImportHandler
		// increments them in different code paths for the same failure class.
		$this->assertSame(7, $result['skippedSeedObjects']);
		$this->assertSame(['objects' => 5, 'seedObjects' => 2], $result['skipped']);

		// Deliberately still a success: flipping this to false would fail
		// `occ app:enable` / `occ upgrade` on existing installs that already
		// carry these seeds, bricking an upgrade over a pre-existing defect.
		$this->assertTrue($result['success']);

	}//end testSkippedSeedObjectsAreReportedAndLoggedAsError()

}//end class
