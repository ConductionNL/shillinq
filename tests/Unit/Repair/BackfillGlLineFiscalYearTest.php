<?php

/**
 * Unit tests for the BackfillGlLineFiscalYear repair step.
 *
 * Pins where the step reads and writes: both schemas come from the register
 * slug the app is configured with, and every stamped line is written back to
 * that same register with RBAC and multi-tenancy bypassed. A failing slug
 * lookup is reported as a warning, never thrown.
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

use OCA\Shillinq\Repair\BackfillGlLineFiscalYear;
use OCA\Shillinq\Service\Migration\GlLineFiscalYearBackfillMigrator;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the register wiring of BackfillGlLineFiscalYear.
 *
 * @covers \OCA\Shillinq\Repair\BackfillGlLineFiscalYear
 *
 * @uses \OCA\Shillinq\Service\Migration\GlLineFiscalYearBackfillMigrator
 */
final class BackfillGlLineFiscalYearTest extends TestCase {

	/**
	 * The in-memory store double: rows, register reads and saves.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * Collected output messages, prefixed with their level.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * Build the step over a store holding one unstamped line and its parent.
	 *
	 * @param RegisterLocation $settings The register-location double handing out the slug.
	 *
	 * @return BackfillGlLineFiscalYear The step under test.
	 */
	private function makeStep(RegisterLocation $settings): BackfillGlLineFiscalYear {
		$this->store = new class() {

			/**
			 * Rows by schema slug.
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			public array $rows = [
				'GLLine' => [['id' => 'l1', 'transactionId' => 'tx-1', 'amount' => 10.0]],
				'GLTransaction' => [['id' => 'tx-1', 'fiscalYearId' => 'fy-2026']],
			];

			/**
			 * Every register slug passed to setRegister(), in order.
			 *
			 * @var array<int, string>
			 */
			public array $registers = [];

			/**
			 * Recorded saveObject() calls.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * The schema selected through the fluent setter.
			 *
			 * @var string
			 */
			public string $schema = '';

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
				$this->schema = $schema;

				return $this;

			}//end setSchema()

			/**
			 * Serve the current rows of the selected schema.
			 *
			 * @param array<string, mixed> $config The findAll options.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config = []): array {
				if ((int)($config['offset'] ?? 0) > 0) {
					return [];
				}

				return array_values($this->rows[$this->schema] ?? []);

			}//end findAll()

			/**
			 * Record a save and apply it in place.
			 *
			 * @param array<string, mixed> $object The payload.
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param string|null $uuid The object uuid.
			 * @param bool $_rbac RBAC flag.
			 * @param bool $_multitenancy Multi-tenancy flag.
			 *
			 * @return array<string, mixed> The saved payload.
			 */
			public function saveObject(
				array $object,
				string $register = '',
				string $schema = '',
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$this->saves[] = [
					'register' => $register,
					'schema' => $schema,
					'uuid' => $uuid,
					'fiscalYearId' => ($object['fiscalYearId'] ?? null),
					'_rbac' => $_rbac,
					'_multitenancy' => $_multitenancy,
				];
				$this->rows[$schema] = [$object];

				return $object;

			}//end saveObject()
		};

		return new BackfillGlLineFiscalYear(
			registerLocation: $settings,
			migrator: new GlLineFiscalYearBackfillMigrator(),
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($this->store),
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
	 * Reads and writes all go to the configured register slug.
	 *
	 * @return void
	 */
	public function testReadsAndWritesTheConfiguredRegister(): void {
		$settings = $this->createMock(RegisterLocation::class);
		$settings->expects($this->once())->method('getRegisterSlug')->willReturn('finance');

		$this->makeStep($settings)->run($this->recordingOutput());

		self::assertNotSame([], $this->store->registers);
		self::assertSame(['finance'], array_values(array_unique($this->store->registers)));
		self::assertSame(
			[
				[
					'register' => 'finance',
					'schema' => 'GLLine',
					'uuid' => 'l1',
					'fiscalYearId' => 'fy-2026',
					'_rbac' => false,
					'_multitenancy' => false,
				],
			],
			$this->store->saves
		);
		self::assertContains('info: Shillinq: every one of 1 GLLine row(s) now carries a fiscalYearId.', $this->messages);

	}//end testReadsAndWritesTheConfiguredRegister()

	/**
	 * A slug lookup that throws is reported as a warning and nothing is written.
	 *
	 * @return void
	 */
	public function testSlugLookupFailureIsReportedNotThrown(): void {
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willThrowException(new RuntimeException('config down'));

		$this->makeStep($settings)->run($this->recordingOutput());

		self::assertSame([], $this->store->saves);
		self::assertSame(['warning: Shillinq: GLLine fiscal-year backfill failed: config down'], $this->messages);

	}//end testSlugLookupFailureIsReportedNotThrown()
}//end class
