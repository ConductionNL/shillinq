<?php

/**
 * Behaviour tests for RetireCostProjectStep's failure paths and system identity.
 *
 * The sibling RetireCostProjectStepTest pins the field mapping and the happy
 * path. This file pins what the step does when OpenRegister is missing or a
 * read, a lookup or a save fails, and that it runs under the system identity.
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

use OCA\Shillinq\Repair\RetireCostProjectStep;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins RetireCostProjectStep's failure paths.
 *
 * @covers \OCA\Shillinq\Repair\RetireCostProjectStep
 */
final class RetireCostProjectStepFailuresTest extends TestCase {

	/**
	 * Output messages, prefixed with their level.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * Logger warnings.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * A store over CostProject rows.
	 *
	 * Behaviour switches: `readFails` throws on the CostProject read,
	 * `lookupFails` throws on every AnalyticalDimension lookup, `taken` lists
	 * codes that already exist, `saveFails` throws on save, `system` adds a
	 * runAsSystem() wrapper that records its use.
	 *
	 * @param array<int, mixed>    $rows    CostProject rows.
	 * @param array<string, mixed> $options Behaviour switches.
	 *
	 * @return object The ObjectService double.
	 */
	private function store(array $rows, array $options = []): object {
		$store = new class($rows, $options) {

			/**
			 * Saved objects.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * The current schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Build it.
			 *
			 * @param array<int, mixed>    $rows    Rows.
			 * @param array<string, mixed> $options Switches.
			 */
			public function __construct(private array $rows, private array $options) {
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Read a page or look a dimension up.
			 *
			 * @param array<string, mixed> $config The options.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $config = []): array {
				if ($this->schema === 'CostProject') {
					if (($this->options['readFails'] ?? false) === true) {
						throw new RuntimeException('no CostProject schema');
					}

					if (($config['offset'] ?? 0) !== 0) {
						return [];
					}

					return $this->rows;
				}

				if (($this->options['lookupFails'] ?? false) === true) {
					throw new RuntimeException('search down');
				}

				$code = ($config['filters']['code'] ?? null);
				if ($code !== null && in_array($code, ($this->options['taken'] ?? []), true) === true) {
					return [['code' => $code]];
				}

				return [];
			}//end findAll()

			/**
			 * Save one object.
			 *
			 * @param array<string, mixed> $object        The object.
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Multitenancy flag.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(array $object, string $register, string $schema, bool $_rbac, bool $_multitenancy): array {
				if (($this->options['saveFails'] ?? false) === true) {
					throw new RuntimeException('validation failed');
				}

				$this->saved[] = $object;
				return $object;
			}//end saveObject()
		};

		if (($options['system'] ?? false) === false) {
			return $store;
		}

		return new class($store) {

			/**
			 * Calls to runAsSystem().
			 *
			 * @var int
			 */
			public int $systemRuns = 0;

			/**
			 * Wrap the store.
			 *
			 * @param object $inner The store.
			 */
			public function __construct(public object $inner) {
			}//end __construct()

			/**
			 * Run work under the system identity.
			 *
			 * @param callable $work The work.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $work): void {
				$this->systemRuns++;
				$work();
			}//end runAsSystem()

			/**
			 * Forward everything else to the store.
			 *
			 * @param string            $name The method.
			 * @param array<int, mixed> $args The arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $args): mixed {
				$result = $this->inner->$name(...$args);
				if ($result === $this->inner) {
					return $this;
				}

				return $result;
			}//end __call()
		};
	}//end store()

	/**
	 * Build the step and run it.
	 *
	 * @param object|null $store The ObjectService, or null when OpenRegister is absent.
	 *
	 * @return void
	 */
	private function runStep(?object $store): void {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		$container = $this->createMock(ContainerInterface::class);
		if ($store === null) {
			$container->method('get')->willThrowException(new RuntimeException('not installed'));
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($store);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message): void {
				$this->warnings[] = (string)$message;
			}
		);

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

		(new RetireCostProjectStep(registerLocation: $location, logger: $logger, container: $container))->run($output);
	}//end runStep()

	/**
	 * Without OpenRegister the step fails soft with a warning.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStepFailsSoft(): void {
		$this->runStep(store: null);

		self::assertSame(['warning: Shillinq: RetireCostProjectStep failed: not installed'], $this->messages);
		self::assertSame(['Shillinq: RetireCostProjectStep outer failure'], $this->warnings);
	}//end testWithoutOpenRegisterTheStepFailsSoft()

	/**
	 * An unreadable CostProject schema migrates nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaMigratesNothing(): void {
		$store = $this->store([], ['readFails' => true]);
		$this->runStep(store: $store);

		self::assertSame([], $store->saved);
		self::assertSame(
			'info: Shillinq: RetireCostProjectStep — no CostProject schema or no records found (no CostProject schema); skipping.',
			$this->messages[1]
		);
		self::assertSame(
			'info: Shillinq: RetireCostProjectStep complete — 0 migrated, 0 skipped (already migrated), 0 failed (left in place, see log).',
			end($this->messages)
		);
	}//end testAnUnreadableSchemaMigratesNothing()

	/**
	 * A record without an id fails and is left in place.
	 *
	 * @return void
	 */
	public function testARecordWithoutAnIdFails(): void {
		$store = $this->store([['projectNumber' => 'P1']]);
		$this->runStep(store: $store);

		self::assertSame([], $store->saved);
		self::assertContains('warning: Shillinq: RetireCostProjectStep — CostProject record has no id; skipping.', $this->messages);
		self::assertStringContainsString('0 migrated, 0 skipped (already migrated), 1 failed', end($this->messages));
	}//end testARecordWithoutAnIdFails()

	/**
	 * When every lookup fails, codes count as taken, so no code can be minted.
	 *
	 * @return void
	 */
	public function testFailingLookupsLeaveTheRecordInPlace(): void {
		$store = $this->store([['id' => 'cp-1', 'projectNumber' => 'P 1']], ['lookupFails' => true]);
		$this->runStep(store: $store);

		self::assertSame([], $store->saved);
		self::assertContains(
			'warning: Shillinq: RetireCostProjectStep — CostProject id=cp-1 projectNumber=P 1: '
			. 'irrecoverable code collision after 99 attempts; skipping (left in place).',
			$this->messages
		);
	}//end testFailingLookupsLeaveTheRecordInPlace()

	/**
	 * A failing save is counted and logged, and the next record still migrates.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsCounted(): void {
		$store = $this->store([['id' => 'cp-1', 'projectNumber' => 'P1']], ['saveFails' => true]);
		$this->runStep(store: $store);

		self::assertContains('warning: Shillinq: RetireCostProjectStep — failed to migrate CostProject id=cp-1: validation failed', $this->messages);
		self::assertSame(['Shillinq: RetireCostProjectStep — migration failed for CostProject id=cp-1'], $this->warnings);
		self::assertStringContainsString('0 migrated, 0 skipped (already migrated), 1 failed', end($this->messages));
	}//end testAFailingSaveIsCounted()

	/**
	 * With runAsSystem() available the work runs under it, and a closed project is archived.
	 *
	 * @return void
	 */
	public function testTheStepRunsUnderTheSystemIdentity(): void {
		$store = $this->store(
			[['id' => 'cp-1', 'projectNumber' => 'P1', 'lifecycleState' => 'closed', 'costCenterCode' => 'DEP', 'totalBudget' => '12']],
			['system' => true, 'taken' => ['cp-p1']]
		);
		$this->runStep(store: $store);

		self::assertSame(1, $store->systemRuns);
		self::assertCount(1, $store->inner->saved);
		$saved = $store->inner->saved[0];
		self::assertSame('cp-p1-2', $saved['code']);
		self::assertSame('archived', $saved['lifecycleState']);
		self::assertSame('DEP', $saved['parentCode']);
		self::assertSame(12, $saved['totalBudget']);
		self::assertContains('info: Shillinq: RetireCostProjectStep — code collision on "cp-p1" resolved to "cp-p1-2".', $this->messages);
	}//end testTheStepRunsUnderTheSystemIdentity()
}//end class
