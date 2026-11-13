<?php

/**
 * Unit tests for UnifyAnalyticalDimensions.
 *
 * Verifies that CostCenter and KostenDrager rows become AnalyticalDimension
 * records with the right discriminator and carried fields, that a dimension
 * already present for the same code, administration and type is skipped, that
 * the work runs under OpenRegister's system identity, and that failures are
 * warned about rather than thrown.
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

use OCA\Shillinq\Repair\UnifyAnalyticalDimensions;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for UnifyAnalyticalDimensions.
 *
 * @covers \OCA\Shillinq\Repair\UnifyAnalyticalDimensions
 * @uses   \OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches
 * @uses   \OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity
 */
class UnifyAnalyticalDimensionsTest extends TestCase {

	/**
	 * The location.
	 *
	 * @var RegisterLocation&MockObject
	 */
	private RegisterLocation&MockObject $location;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Every info and warning written.
	 *
	 * @var list<string>
	 */
	private array $messages = [];

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->location = $this->createMock(RegisterLocation::class);
		$this->location->method('getRegisterSlug')->willReturn('shillinq');
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A fake object service over rows per schema; AnalyticalDimension lookups
	 * match on every filter, and saves land in that schema.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows        Rows per schema.
	 * @param bool                                      $asSystem    Whether it offers runAsSystem().
	 * @param list<string>                              $failingCodes Codes whose save throws.
	 *
	 * @return object
	 */
	private function objectService(array $rows, bool $asSystem = false, array $failingCodes = []): object {
		$service = new class($rows, $failingCodes) {
			/**
			 * The schema.
			 *
			 * @var string
			 */
			public string $schema = '';

			/** @var list<array<string, mixed>> */
			public array $saved = [];

			/**
			 * @param array<string, list<array<string, mixed>>> $rows         Rows per schema.
			 * @param list<string>                              $failingCodes Codes whose save throws.
			 */
			public function __construct(public array $rows, private array $failingCodes) {
			}

			/**
			 * Set register.
			 *
			 * @param string $register The register.
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * Set schema.
			 *
			 * @param string $schema The schema.
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $config): array {
				$rows = ($this->rows[$this->schema] ?? []);
				foreach (($config['filters'] ?? []) as $field => $value) {
					$rows = array_values(array_filter($rows, static fn (array $row): bool => ($row[$field] ?? null) === $value));
				}

				return array_slice($rows, (int)($config['offset'] ?? 0), (int)$config['limit']);
			}

			/**
			 * Save a record, or refuse it.
			 *
			 * @param array<string, mixed> $object        The record.
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 * @param bool                 $_rbac         Whether RBAC applies.
			 * @param bool                 $_multitenancy Whether multitenancy applies.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, bool $_rbac, bool $_multitenancy): array {
				if (in_array($object['code'], $this->failingCodes, true) === true) {
					throw new RuntimeException('validation failed');
				}

				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				return $object;
			}
		};

		if ($asSystem === false) {
			return $service;
		}

		return new class($service) {
			/**
			 * The systemRuns.
			 *
			 * @var int
			 */
			public int $systemRuns = 0;

			/**
			 * __construct.
			 *
			 * @param object $inner The inner.
			 */
			public function __construct(public object $inner) {
			}

			/**
			 * Run as system.
			 *
			 * @param callable $work The work.
			 * @return void
			 */
			public function runAsSystem(callable $work): void {
				$this->systemRuns++;
				$work();
			}

			/**
			 * @param string       $name The method.
			 * @param array<mixed> $args The arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $args): mixed {
				return $this->inner->$name(...$args);
			}
		};
	}//end objectService()

	/**
	 * Run the step with this object service.
	 *
	 * @param object|null $service The object service the container hands out, or null for none.
	 *
	 * @return void
	 */
	private function runStep(?object $service): void {
		$container = $this->createMock(ContainerInterface::class);
		if ($service === null) {
			$container->method('get')->willThrowException(new RuntimeException('OpenRegister not installed'));
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($service);
		}

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->messages[] = 'info: ' . $message;
		});
		$output->method('warning')->willReturnCallback(function (string $message): void {
			$this->messages[] = 'warning: ' . $message;
		});

		(new UnifyAnalyticalDimensions(
			registerLocation: $this->location,
			logger: $this->logger,
			container: $container,
		))->run($output);
	}//end runStep()

	/**
	 * Get name names both sources and the target.
	 *
	 * @return void
	 */
	public function testGetNameNamesBothSourcesAndTheTarget(): void {
		$step = new UnifyAnalyticalDimensions(
			registerLocation: $this->location,
			logger: $this->logger,
			container: $this->createMock(ContainerInterface::class),
		);

		self::assertStringContainsString('CostCenter + KostenDrager', $step->getName());
		self::assertStringContainsString('AnalyticalDimension', $step->getName());
	}//end testGetNameNamesBothSourcesAndTheTarget()

	/**
	 * Both families become dimensions with their fields.
	 *
	 * @return void
	 */
	public function testBothFamiliesBecomeDimensionsWithTheirFields(): void {
		$service = $this->objectService(
			[
				'CostCenter' => [[
					'code' => 'CC-1',
					'name' => 'Beheer',
					'administrationId' => 'adm-1',
					'description' => 'Openbare ruimte',
					'status' => '',
					'parentCode' => 'CC-0',
					'budget' => 1000,
					'enterpriseActivity' => 1,
					'ignored' => 'x',
				]],
				'KostenDrager' => [
					[
						'code' => 'KD-1',
						'name' => 'Project A',
						'administrationId' => 'adm-1',
						'lifecycleState' => 'archived',
						'responsibleUser' => 'anne',
						'status' => 'kept out',
					],
					['code' => 'CC-1', 'administrationId' => 'adm-1'],
				],
			]
		);

		$this->runStep($service);

		self::assertSame(
			[
				[
					'dimensionType' => 'cost-center',
					'code' => 'CC-1',
					'name' => 'Beheer',
					'dataType' => 'string',
					'administrationId' => 'adm-1',
					'lifecycleState' => 'active',
					'description' => 'Openbare ruimte',
					'parentCode' => 'CC-0',
					'budget' => 1000,
					'enterpriseActivity' => true,
				],
				[
					'dimensionType' => 'cost-object',
					'code' => 'KD-1',
					'name' => 'Project A',
					'dataType' => 'string',
					'administrationId' => 'adm-1',
					'lifecycleState' => 'archived',
					'responsibleUser' => 'anne',
				],
				[
					'dimensionType' => 'cost-object',
					'code' => 'CC-1',
					'name' => '',
					'dataType' => 'string',
					'administrationId' => 'adm-1',
					'lifecycleState' => 'active',
				],
			],
			array_column($service->saved, 'object')
		);
		self::assertSame(['AnalyticalDimension'], array_values(array_unique(array_column($service->saved, 'schema'))));
		self::assertSame(['shillinq'], array_values(array_unique(array_column($service->saved, 'register'))));
		self::assertFalse($service->saved[0]['_rbac']);
		self::assertFalse($service->saved[0]['_multitenancy']);
		self::assertSame(
			'info: Shillinq: UnifyAnalyticalDimensions complete — CostCenter: 1 created, 0 skipped; KostenDrager: 2 created, 0 skipped.',
			end($this->messages)
		);
	}//end testBothFamiliesBecomeDimensionsWithTheirFields()

	/**
	 * An existing dimension is skipped and a missing code is logged.
	 *
	 * @return void
	 */
	public function testAnExistingDimensionIsSkippedAndAMissingCodeIsLogged(): void {
		$service = $this->objectService(
			[
				'CostCenter' => [['code' => 'CC-1', 'administrationId' => 'adm-1'], ['name' => 'no code']],
				'KostenDrager' => [['code' => 'KD-1', 'administrationId' => 'adm-1'], ['name' => 'no code either']],
				'AnalyticalDimension' => [
					['code' => 'CC-1', 'administrationId' => 'adm-1', 'dimensionType' => 'cost-center'],
					['code' => 'KD-1', 'administrationId' => 'adm-1', 'dimensionType' => 'cost-object'],
				],
			]
		);
		$logged = [];
		$this->logger->method('warning')->willReturnCallback(static function (string $message) use (&$logged): void {
			$logged[] = $message;
		});

		$this->runStep($service);

		self::assertSame([], $service->saved);
		self::assertSame(
			[
				'Shillinq: UnifyAnalyticalDimensions — CostCenter record missing code; skipped.',
				'Shillinq: UnifyAnalyticalDimensions — KostenDrager record missing code; skipped.',
			],
			$logged
		);
		self::assertSame(
			'info: Shillinq: UnifyAnalyticalDimensions complete — CostCenter: 0 created, 1 skipped; KostenDrager: 0 created, 1 skipped.',
			end($this->messages)
		);
	}//end testAnExistingDimensionIsSkippedAndAMissingCodeIsLogged()

	/**
	 * No source rows says so for each family.
	 *
	 * @return void
	 */
	public function testNoSourceRowsSaysSoForEachFamily(): void {
		$service = $this->objectService([]);

		$this->runStep($service);

		self::assertContains('info: Shillinq: no CostCenter records found — skipping cost-center migration.', $this->messages);
		self::assertContains('info: Shillinq: no KostenDrager records found — skipping cost-object migration.', $this->messages);
		self::assertSame(
			'info: Shillinq: UnifyAnalyticalDimensions complete — CostCenter: 0 created, 0 skipped; KostenDrager: 0 created, 0 skipped.',
			end($this->messages)
		);
	}//end testNoSourceRowsSaysSoForEachFamily()

	/**
	 * A failing save is warned and the rest goes on.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsWarnedAndTheRestGoesOn(): void {
		$service = $this->objectService(
			[
				'CostCenter' => [['code' => 'CC-bad'], ['code' => 'CC-ok']],
				'KostenDrager' => [['code' => 'KD-bad'], ['code' => 'KD-ok']],
			],
			false,
			['CC-bad', 'KD-bad']
		);

		$this->runStep($service);

		self::assertSame(['CC-ok', 'KD-ok'], array_map(static fn (array $save): string => $save['object']['code'], $service->saved));
		self::assertContains('warning: Shillinq: failed to migrate CostCenter code=CC-bad: validation failed', $this->messages);
		self::assertContains('warning: Shillinq: failed to migrate KostenDrager code=KD-bad: validation failed', $this->messages);
	}//end testAFailingSaveIsWarnedAndTheRestGoesOn()

	/**
	 * The work runs under the system identity when offered.
	 *
	 * @return void
	 */
	public function testTheWorkRunsUnderTheSystemIdentityWhenOffered(): void {
		$service = $this->objectService(['CostCenter' => [['code' => 'CC-1']]], true);

		$this->runStep($service);

		self::assertSame(1, $service->systemRuns);
		self::assertCount(1, $service->inner->saved);
	}//end testTheWorkRunsUnderTheSystemIdentityWhenOffered()

	/**
	 * Without open register the failure is a warning.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheFailureIsAWarning(): void {
		$this->logger->expects(self::once())->method('warning')
			->with(
				'Shillinq: UnifyAnalyticalDimensions failed',
				self::callback(static fn (array $context): bool => $context['exception'] === 'OpenRegister not installed')
			);

		$this->runStep(null);

		self::assertSame(['warning: Shillinq: UnifyAnalyticalDimensions failed: OpenRegister not installed'], $this->messages);
	}//end testWithoutOpenRegisterTheFailureIsAWarning()
}//end class
