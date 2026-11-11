<?php

/**
 * Pins what the CBS and SBR/XBRL seed repair steps report from run(): a
 * missing, unparseable or empty seed fragment, an unavailable ObjectService,
 * and a seed that saves one record.
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\LoadCbsSeedsStep;
use OCA\Shillinq\Repair\LoadSbrXbrlSeedsStep;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * Runs each step against a seed fragment written per case and records its output.
 */
final class SeedStepRunGoldenTest extends TestCase {

	/**
	 * The output lines, as "info <text>" / "warning <text>".
	 *
	 * @var array<int, string>
	 */
	private array $lines = [];

	/**
	 * The records saved, as "<schema> <slug>".
	 *
	 * @var array<int, string>
	 */
	public array $saved = [];

	/**
	 * The temporary fragment, removed after each case.
	 *
	 * @var string|null
	 */
	private ?string $fragment = null;

	/**
	 * Remove the temporary fragment.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->fragment !== null && file_exists($this->fragment) === true) {
			unlink($this->fragment);
		}

	}//end tearDown()

	/**
	 * Both steps: [class, label, a schema it seeds, its opening line].
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function steps(): array {
		return [
			'cbs' => [LoadCbsSeedsStep::class, 'CBS', 'CBSLine', 'Seeding CBSSubmission + CBSLine example records...'],
			'sbr/xbrl' => [LoadSbrXbrlSeedsStep::class, 'SBR/XBRL', 'XBRLMapping', 'Seeding XBRLTaxonomy / SBRDocumentType / XBRLMapping example records...'],
		];

	}//end steps()

	/**
	 * Run a step over a fragment holding $json (null: no fragment at all).
	 *
	 * @param string      $class       The step class.
	 * @param string|null $json        The fragment content.
	 * @param bool        $serviceDown Whether the ObjectService lookup throws.
	 *
	 * @return array<int, string> The output lines.
	 */
	private function runStep(string $class, ?string $json, bool $serviceDown=false): array {
		$this->fragment = sys_get_temp_dir() . '/seed-step-' . bin2hex(random_bytes(4)) . '.json';
		if ($json !== null) {
			file_put_contents($this->fragment, $json);
		}

		$test = $this;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function () use ($serviceDown, $test): object {
				if ($serviceDown === true) {
					throw new \RuntimeException('down');
				}

				return new class($test) {
					private string $schema = '';

					public function __construct(
						private readonly SeedStepRunGoldenTest $test,
					) {
					}

					public function setRegister(string $register): self {
						return $this;
					}

					public function setSchema(string $schema): self {
						$this->schema = $schema;
						return $this;
					}

					public function findAll(array $config): array {
						return ($config['filters']['slug'] === 'present') ? [['id' => 'x']] : [];
					}

					public function saveObject(array $object, string $register, string $schema, bool $_rbac, bool $_multitenancy): array {
						$this->test->saved[] = $register . ' ' . $schema . ' ' . $object['slug'];
						return $object;
					}
				};
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(fn (string $m) => $this->lines[] = 'info ' . $m);
		$output->method('warning')->willReturnCallback(fn (string $m) => $this->lines[] = 'warning ' . $m);

		$step = new $class($container, $appConfig, new NullLogger());
		(new ReflectionProperty($step, 'seedPath'))->setValue($step, $this->fragment);
		$step->run($output);

		return $this->lines;

	}//end run()

	/**
	 * Each way the fragment can fail, and the ObjectService being unavailable.
	 *
	 * @return void
	 */
	#[DataProvider('steps')]
	public function testRefusals(string $class, string $label, string $schema, string $opening): void {
		self::assertSame(['info ' . $opening, 'warning ' . $label . ' seed fragment not found at ' . $this->fragmentPathAfter($class, null) . ', skipping'], $this->lines);

		$this->lines = [];
		self::assertSame(['info ' . $opening, 'warning Failed to parse ' . $label . ' seed fragment: Syntax error'], $this->runStep($class, '{'));

		$this->lines = [];
		self::assertSame(['info ' . $opening, 'warning Failed to parse ' . $label . ' seed fragment: No error'], $this->runStep($class, '5'));

		$this->lines = [];
		self::assertSame(['info ' . $opening, 'info ' . $label . ' seed fragment carries no objects; nothing to seed'], $this->runStep($class, '{"objects": []}'));

		$this->lines = [];
		self::assertSame(['info ' . $opening, 'info ' . $label . ' seed fragment carries no objects; nothing to seed'], $this->runStep($class, '{"objects": "x"}'));

		$this->lines = [];
		self::assertSame(
			['info ' . $opening, 'warning OpenRegister ObjectService unavailable; skipping ' . $label . ' seed: down'],
			$this->runStep($class, '{"objects": [{"@self": {"schema": "' . $schema . '", "slug": "a"}}]}', true)
		);
		self::assertSame([], $this->saved);

	}//end testRefusals()

	/**
	 * A fragment with one new and one present record saves the new one.
	 *
	 * @return void
	 */
	#[DataProvider('steps')]
	public function testSeeds(string $class, string $label, string $schema, string $opening): void {
		$json = '{"objects": [{"@self": {"schema": "' . $schema . '", "slug": "new"}}, {"@self": {"schema": "' . $schema . '", "slug": "present"}}, {"@self": {"schema": "Other", "slug": "z"}}]}';

		self::assertSame(['info ' . $opening, 'info ' . $label . ' seed: 1 created, 1 skipped (already exist).'], $this->runStep($class, $json));
		self::assertSame(['shillinq ' . $schema . ' new'], $this->saved);

	}//end testSeeds()

	/**
	 * Run with no fragment and answer the path the step was pointed at.
	 *
	 * @param string      $class The step class.
	 * @param string|null $json  Always null: no fragment.
	 *
	 * @return string
	 */
	private function fragmentPathAfter(string $class, ?string $json): string {
		$this->runStep($class, $json);
		return (string)$this->fragment;

	}//end fragmentPathAfter()
}//end class
