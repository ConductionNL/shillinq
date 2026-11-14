<?php

/**
 * Unit tests for InitializeBbvAdministration.
 *
 * Pins the BBV bootstrap: only municipality, province and water-authority
 * administrations get an algemene reserve and a taakveld 0.10, nothing is
 * seeded twice, every unavailable register or refused write is skipped with a
 * warning rather than failing the upgrade, and the work runs under the system
 * identity when OpenRegister offers one.
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

use OCA\Shillinq\Repair\InitializeBbvAdministration;
use OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for InitializeBbvAdministration.
 */
#[CoversClass(InitializeBbvAdministration::class)]
#[UsesClass(RunsUnderSystemIdentity::class)]
class InitializeBbvAdministrationTest extends TestCase {

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The info lines written to the output.
	 *
	 * @var array<int, string>
	 */
	private array $info = [];

	/**
	 * Set up shared fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->info = [];
	}//end setUp()

	/**
	 * A fake ObjectService that answers per schema and records writes.
	 *
	 * @param array<string, mixed> $answers   Per schema: rows, or a Throwable to throw on findAll.
	 * @param array<int, string>   $failSaves Schemas whose saveObject throws.
	 * @param bool                 $system    Whether it offers runAsSystem.
	 *
	 * @return object
	 */
	private function objects(array $answers, array $failSaves = [], bool $system = false): object {
		$class = new class ($answers, $failSaves) {
			/**
			 * The schema set last.
			 *
			 * @var string
			 */
			public string $schema = '';

			/**
			 * The registers set.
			 *
			 * @var array<int, string>
			 */
			public array $registers = [];

			/**
			 * The findAll calls as [schema, config].
			 *
			 * @var array<int, array{0: string, 1: array<string, mixed>}>
			 */
			public array $finds = [];

			/**
			 * The saves as [schema, register, object].
			 *
			 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
			 */
			public array $saves = [];

			/**
			 * Whether runAsSystem ran.
			 *
			 * @var bool
			 */
			public bool $ranAsSystem = false;

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $answers   The answers.
			 * @param array<int, string>   $failSaves Failing schemas.
			 */
			public function __construct(private array $answers, private array $failSaves) {
			}

			/**
			 * Set the register.
			 *
			 * @param string $register The register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->registers[] = $register;
				return $this;
			}

			/**
			 * Set the schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * Find rows of the current schema.
			 *
			 * @param array<string, mixed> $config The config.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = []): array {
				$this->finds[] = [$this->schema, $config];
				$answer = ($this->answers[$this->schema] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}

			/**
			 * Save an object.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				if (in_array($schema, $this->failSaves, true) === true) {
					throw new RuntimeException('refused '.$schema);
				}

				$this->saves[] = [$schema, $register, $object];
				return $object;
			}
		};

		if ($system === false) {
			return $class;
		}

		return new class ($class) {
			/**
			 * Constructor.
			 *
			 * @param object $inner The wrapped fake.
			 */
			public function __construct(public object $inner) {
			}

			/**
			 * Run as the system user.
			 *
			 * @param callable $work The work.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $work): void {
				$this->inner->ranAsSystem = true;
				$work();
			}

			/**
			 * Forward every other call.
			 *
			 * @param string            $name      The method.
			 * @param array<int, mixed> $arguments The arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $arguments): mixed {
				$result = $this->inner->$name(...$arguments);
				return ($result === $this->inner) ? $this : $result;
			}
		};
	}//end objects()

	/**
	 * Run the step over the given object service.
	 *
	 * @param object|null $objects  The object service, or null when the container has none.
	 * @param string      $register The configured register slug.
	 *
	 * @return void
	 */
	private function runStep(?object $objects, string $register = 'shillinq'): void {
		$container = $this->createMock(ContainerInterface::class);
		if ($objects === null) {
			$container->method('get')->willThrowException(new RuntimeException('no OpenRegister'));
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn($register);
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->info[] = $message;
			}
		);

		(new InitializeBbvAdministration($container, $appConfig, $this->logger))->run($output);
	}//end runStep()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testName(): void {
		$step = new InitializeBbvAdministration(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->logger
		);

		$this->assertSame('Bootstrap BBV-administraties with algemene reserve and taakveld 0.10', $step->getName());
	}//end testName()

	/**
	 * Each BBV administration gets a reserve; each overheidslaag a taakveld;
	 * other administrations and rows without an id are passed over.
	 *
	 * @return void
	 */
	public function testSeedsReserveAndTaakveldForBbvAdministrations(): void {
		$objects = $this->objects(
			[
				'Administration' => [
					['id' => 'gem-1', 'administrationType' => 'municipality'],
					new class implements \JsonSerializable {
						/**
						 * Serialise.
						 *
						 * @return array<string, string>
						 */
						public function jsonSerialize(): array {
							return ['uuid' => 'ws-1', 'administrationType' => 'waterAuthority'];
						}
					},
					['id' => 'bv-1', 'administrationType' => 'company'],
					['administrationType' => 'province'],
					'not a row',
				],
			]
		);

		$this->runStep($objects, register: 'fin');

		$reserves = array_values(array_filter($objects->saves, static fn (array $save): bool => $save[0] === 'Reserve'));
		$taakvelden = array_values(array_filter($objects->saves, static fn (array $save): bool => $save[0] === 'Taakveld'));
		$this->assertSame(['gem-1', 'ws-1'], array_map(static fn (array $save): string => $save[2]['administrationId'], $reserves));
		$this->assertSame(
			[
				'administrationId' => 'gem-1',
				'name' => 'Algemene reserve',
				'kind' => 'algemeen',
				'saldoBeginJaar' => 0,
				'rentetoerekening' => false,
				'_meta' => ['source' => 'bootstrap', 'createdBy' => 'InitializeBbvAdministration'],
			],
			$reserves[0][2]
		);
		$this->assertSame('fin', $reserves[0][1]);
		$this->assertSame(['municipality', 'waterAuthority'], array_map(static fn (array $save): string => $save[2]['overheidslaag'], $taakvelden));
		$this->assertSame(['0.10', 'Mutaties reserves', 0, '2025-01-01'], [$taakvelden[0][2]['code'], $taakvelden[0][2]['name'], $taakvelden[0][2]['mainFunction'], $taakvelden[0][2]['validFrom']]);
		$this->assertSame(['fin'], array_unique($objects->registers));
		$this->assertSame(['Administration', ['limit' => 500]], $objects->finds[0]);
		$this->assertSame(
			['Reserve', ['filters' => ['administrationId' => 'gem-1', 'name' => 'Algemene reserve'], 'limit' => 1]],
			$objects->finds[1]
		);
		$this->assertSame(
			['Taakveld', ['filters' => ['code' => '0.10', 'overheidslaag' => 'municipality'], 'limit' => 1]],
			$objects->finds[2]
		);
		$this->assertSame(['BBV-administration bootstrap: 2 algemene reserve(s) created, 0 skipped; 2 reserve-taakveld(en) created.'], $this->info);
	}//end testSeedsReserveAndTaakveldForBbvAdministrations()

	/**
	 * What already exists is counted as skipped and not written again; an
	 * empty register slug falls back to shillinq.
	 *
	 * @return void
	 */
	public function testExistingReserveAndTaakveldAreNotSeededAgain(): void {
		$objects = $this->objects(
			[
				'Administration' => [['id' => 'prov-1', 'administrationType' => 'province']],
				'Reserve' => [['name' => 'Algemene reserve']],
				'Taakveld' => [['code' => '0.10']],
			]
		);

		$this->runStep($objects, register: '');

		$this->assertSame([], $objects->saves);
		$this->assertSame(['shillinq'], array_unique($objects->registers));
		$this->assertSame(['BBV-administration bootstrap: 0 algemene reserve(s) created, 1 skipped; 0 reserve-taakveld(en) created.'], $this->info);
	}//end testExistingReserveAndTaakveldAreNotSeededAgain()

	/**
	 * Unavailable Reserve and Taakveld registers are warned about and skipped.
	 *
	 * @return void
	 */
	public function testUnavailableRegistersAreSkippedWithAWarning(): void {
		$objects = $this->objects(
			[
				'Administration' => [['id' => 'gem-1', 'administrationType' => 'municipality']],
				'Reserve' => new RuntimeException('no Reserve schema'),
				'Taakveld' => new RuntimeException('no Taakveld schema'),
			]
		);
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$this->runStep($objects);

		$this->assertSame([], $objects->saves);
		$this->assertSame(
			[
				['Shillinq: Reserve register unavailable, skipping algemene reserve bootstrap', ['administrationId' => 'gem-1', 'exception' => 'no Reserve schema']],
				['Shillinq: Taakveld register unavailable, skipping reserve-taakveld bootstrap', ['overheidslaag' => 'municipality', 'exception' => 'no Taakveld schema']],
			],
			$warnings
		);
		$this->assertSame(['BBV-administration bootstrap: 0 algemene reserve(s) created, 0 skipped; 0 reserve-taakveld(en) created.'], $this->info);
	}//end testUnavailableRegistersAreSkippedWithAWarning()

	/**
	 * A refused write is warned about and not counted.
	 *
	 * @return void
	 */
	public function testRefusedWritesAreWarnedAndNotCounted(): void {
		$objects = $this->objects(
			['Administration' => [['id' => 'gem-1', 'administrationType' => 'municipality']]],
			failSaves: ['Reserve', 'Taakveld']
		);
		$messages = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$this->runStep($objects);

		$this->assertSame(['Shillinq: failed to seed algemene reserve', 'Shillinq: failed to seed reserve-taakveld'], $messages);
		$this->assertSame(['BBV-administration bootstrap: 0 algemene reserve(s) created, 0 skipped; 0 reserve-taakveld(en) created.'], $this->info);
	}//end testRefusedWritesAreWarnedAndNotCounted()

	/**
	 * Without OpenRegister, without an Administration schema, or without
	 * administrations, the step says why it did nothing.
	 *
	 * @return void
	 */
	public function testSkipsWhenThereIsNothingToBootstrap(): void {
		$this->runStep(null);
		$this->runStep($this->objects(['Administration' => new RuntimeException('no schema')]));
		$empty = $this->objects(['Administration' => []]);
		$this->runStep($empty);

		$this->assertSame(
			[
				'Shillinq: ObjectService unavailable, skipping BBV-administration bootstrap',
				'Shillinq: Administration register not yet present, skipping BBV-administration bootstrap',
				'Shillinq: no administrations found, skipping BBV-administration bootstrap',
			],
			$this->info
		);
		$this->assertSame([], $empty->saves);
	}//end testSkipsWhenThereIsNothingToBootstrap()

	/**
	 * When OpenRegister offers a system identity, the bootstrap runs under it.
	 *
	 * @return void
	 */
	public function testRunsUnderTheSystemIdentity(): void {
		$objects = $this->objects(['Administration' => [['id' => 'gem-1', 'administrationType' => 'municipality']]], system: true);

		$this->runStep($objects);

		$this->assertTrue($objects->inner->ranAsSystem);
		$this->assertCount(2, $objects->inner->saves);
	}//end testRunsUnderTheSystemIdentity()
}//end class
