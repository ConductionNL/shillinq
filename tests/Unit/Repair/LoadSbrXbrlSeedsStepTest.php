<?php

/**
 * Unit tests for LoadSbrXbrlSeedsStep.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\LoadSbrXbrlSeedsStep;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * The step re-creates the shipped SBR/XBRL seed records from
 * `bookkeeping-sbr-xbrl-reporting.json`, one per slug, under the system identity: it seeds only
 * its own schemas, skips a slug that already exists, keeps going past a
 * record that fails, and skips the whole run with a warning when the
 * fragment or OpenRegister is unavailable.
 *
 * @covers \OCA\Shillinq\Repair\LoadSbrXbrlSeedsStep
 * @uses \OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity
 */
final class LoadSbrXbrlSeedsStepTest extends TestCase {

	/**
	 * The schemas this step seeds.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMAS = ['XBRLTaxonomy', 'SBRDocumentType', 'XBRLMapping'];

	/**
	 * Info lines written to the output.
	 *
	 * @var array<int, string>
	 */
	private array $info = [];

	/**
	 * Warning lines written to the output.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Temp files to remove.
	 *
	 * @var array<int, string>
	 */
	private array $temp = [];

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Remove temp files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->temp as $file) {
			@unlink($file);
		}
	}//end tearDown()

	/**
	 * An output that records its lines.
	 *
	 * @return IOutput The output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->info[] = $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return $output;
	}//end recordingOutput()

	/**
	 * An object service whose existing slugs and failing slugs are given.
	 *
	 * @param array<int, string> $existing Slugs already stored.
	 * @param array<int, string> $failing  Slugs whose save throws.
	 *
	 * @return object The double.
	 */
	private function objectService(array $existing = [], array $failing = []): object {
		return new class ($existing, $failing) {

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $queries = [];

			/**
			 * @var boolean
			 */
			public bool $asSystem = false;

			/**
			 * @var boolean
			 */
			public bool $inSystemCall = false;

			/**
			 * @var string
			 */
			private string $register = '';

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<int, string> $existing Existing slugs.
			 * @param array<int, string> $failing  Failing slugs.
			 */
			public function __construct(private readonly array $existing, private readonly array $failing) {
			}

			/**
			 * @param callable $operation The work.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $operation): void {
				$this->inSystemCall = true;
				$operation();
				$this->inSystemCall = false;
				$this->asSystem     = true;
			}

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->register = $register;

				return $this;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;

				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed> The matches.
			 */
			public function findAll(array $config): array {
				$this->queries[] = ['register' => $this->register, 'schema' => $this->schema, 'config' => $config];

				return in_array($config['filters']['slug'], $this->existing, true) === true ? [['slug' => $config['filters']['slug']]] : [];
			}

			/**
			 * @param array<string, mixed> $object        The payload.
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 * @param boolean              $_rbac         RBAC.
			 * @param boolean              $_multitenancy Multitenancy.
			 *
			 * @return array<string, mixed> The payload.
			 */
			public function saveObject(array $object, string $register, string $schema, bool $_rbac, bool $_multitenancy): array {
				if (in_array($object['slug'], $this->failing, true) === true) {
					throw new RuntimeException('constraint violated');
				}

				$this->saved[] = [
					'object' => $object,
					'register' => $register,
					'schema' => $schema,
					'rbac' => $_rbac,
					'multitenancy' => $_multitenancy,
					'asSystem' => $this->inSystemCall,
				];

				return $object;
			}
		};
	}//end objectService()

	/**
	 * The step over the given object service (null: unavailable).
	 *
	 * @param object|null $objectService The object service.
	 *
	 * @return LoadSbrXbrlSeedsStep The step.
	 */
	private function step(?object $objectService): LoadSbrXbrlSeedsStep {
		$container = $this->createMock(ContainerInterface::class);
		if ($objectService === null) {
			$container->method('get')->willThrowException(new RuntimeException('not installed'));
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn('books');

		return new LoadSbrXbrlSeedsStep($container, $config, $this->logger);
	}//end step()

	/**
	 * Point the step at a fragment with this content (null: no file).
	 *
	 * @param LoadSbrXbrlSeedsStep $step    The step.
	 * @param string|null $content The fragment content.
	 *
	 * @return void
	 */
	private function fragment(LoadSbrXbrlSeedsStep $step, ?string $content): void {
		$path = sys_get_temp_dir() . '/seed-' . bin2hex(random_bytes(6)) . '.json';
		if ($content !== null) {
			file_put_contents($path, $content);
			$this->temp[] = $path;
		}

		(new ReflectionProperty(LoadSbrXbrlSeedsStep::class, 'seedPath'))->setValue($step, $path);
	}//end fragment()

	/**
	 * The records of this step's schemas in the shipped fragment.
	 *
	 * @return array<int, array<string, mixed>> The records.
	 */
	private static function shippedRecords(): array {
		$data = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/bookkeeping-sbr-xbrl-reporting.json'), true);

		return array_values(
			array_filter(
				$data['objects'],
				static fn (array $object): bool => in_array($object['@self']['schema'], self::SCHEMAS, true)
			)
		);
	}//end shippedRecords()

	/**
	 * The step names what it seeds.
	 *
	 * @return void
	 */
	public function testTheStepIsNamed(): void {
		$this->assertSame('Seed SBR / XBRL example records (bookkeeping-sbr-xbrl-reporting)', $this->step(null)->getName());
	}//end testTheStepIsNamed()

	/**
	 * Every shipped record of its schemas is saved once, without @self, under the system identity.
	 *
	 * @return void
	 */
	public function testTheShippedRecordsAreSeeded(): void {
		$service  = $this->objectService();
		$expected = self::shippedRecords();
		$this->assertNotSame([], $expected, 'the fragment ships records for this step');

		$this->step($service)->run($this->recordingOutput());

		$this->assertTrue($service->asSystem);
		$this->assertCount(count($expected), $service->saved);
		foreach ($expected as $index => $record) {
			$saved = $service->saved[$index];
			$this->assertSame($record['@self']['schema'], $saved['schema']);
			$this->assertSame('books', $saved['register']);
			$this->assertFalse($saved['rbac']);
			$this->assertFalse($saved['multitenancy']);
			$this->assertTrue($saved['asSystem']);
			$this->assertArrayNotHasKey('@self', $saved['object']);
			$this->assertSame($record['@self']['slug'], $saved['object']['slug']);
			$this->assertSame(
				['filters' => ['slug' => $record['@self']['slug']], 'limit' => 1],
				$service->queries[$index]['config']
			);
		}

		$this->assertContains(sprintf('SBR/XBRL seed: %d created, %d skipped (already exist).', count($expected), 0), $this->info);
		$this->assertSame([], $this->warnings);
	}//end testTheShippedRecordsAreSeeded()

	/**
	 * A slug that already exists is skipped, and a failing save is logged and passed.
	 *
	 * @return void
	 */
	public function testExistingSlugsAreSkippedAndFailuresPassed(): void {
		$records = self::shippedRecords();
		$first   = $records[0]['@self']['slug'];
		$last    = $records[count($records) - 1]['@self']['slug'];
		$service = $this->objectService([$first], [$last]);
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'SBR/XBRL seed: failed to save record',
				['schema' => $records[count($records) - 1]['@self']['schema'], 'slug' => $last, 'exception' => 'constraint violated']
			);

		$this->step($service)->run($this->recordingOutput());

		$this->assertCount(count($records) - 2, $service->saved);
		$this->assertNotContains($first, array_map(static fn (array $saved): string => $saved['object']['slug'], $service->saved));
		$this->assertContains(sprintf('SBR/XBRL seed: %d created, %d skipped (already exist).', count($records) - 2, 1), $this->info);
	}//end testExistingSlugsAreSkippedAndFailuresPassed()

	/**
	 * Records of other schemas, without a slug, or not records at all are ignored.
	 *
	 * @return void
	 */
	public function testOnlyItsOwnSlugsAreSeeded(): void {
		$service = $this->objectService();
		$step    = $this->step($service);
		$this->fragment(
			$step,
			(string)json_encode(
				[
					'objects' => [
						'not a record',
						['@self' => ['schema' => 'Invoice', 'slug' => 'inv-1']],
						['@self' => ['schema' => self::SCHEMAS[0]]],
						['@self' => ['schema' => self::SCHEMAS[0], 'slug' => 'own-1'], 'name' => 'Own'],
					],
				]
			)
		);

		$step->run($this->recordingOutput());

		$this->assertSame([['name' => 'Own', 'slug' => 'own-1']], array_column($service->saved, 'object'));
	}//end testOnlyItsOwnSlugsAreSeeded()

	/**
	 * A missing, unparsable or empty fragment skips the run before OpenRegister is asked.
	 *
	 * @return void
	 */
	public function testAnUnusableFragmentSkipsTheRun(): void {
		foreach ([null, '{not json', '{"objects": []}'] as $content) {
			$service = $this->objectService();
			$step    = $this->step($service);
			$this->fragment($step, $content);

			$step->run($this->recordingOutput());

			$this->assertSame([], $service->queries);
		}

		$this->assertCount(2, $this->warnings);
		$this->assertStringContainsString('seed fragment not found at', $this->warnings[0]);
		$this->assertStringStartsWith('Failed to parse SBR/XBRL seed fragment: ', $this->warnings[1]);
		$this->assertContains('SBR/XBRL seed fragment carries no objects; nothing to seed', $this->info);
	}//end testAnUnusableFragmentSkipsTheRun()

	/**
	 * Without OpenRegister the run is skipped with a warning.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheRunIsSkipped(): void {
		$this->step(null)->run($this->recordingOutput());

		$this->assertSame(['OpenRegister ObjectService unavailable; skipping SBR/XBRL seed: not installed'], $this->warnings);
	}//end testWithoutOpenRegisterTheRunIsSkipped()
}//end class
