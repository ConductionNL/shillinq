<?php

/**
 * Unit tests for IntercompanyToleranceGuard.
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
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-13
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\IntercompanyToleranceGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for IntercompanyToleranceGuard (REQ-ICE-004).
 *
 * - Perfect match (mismatch ~0) is always within tolerance.
 * - One-sided matches are never within tolerance.
 * - A configured relation-type ToleranceRule is applied.
 * - max-of vs min-of combination methods are honoured.
 * - With no rule configured, conservative defaults (EUR 10 / 0.5% / max-of) apply.
 * - ObjectService failure fails closed (returns false).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IntercompanyToleranceGuardTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var IntercompanyToleranceGuard
	 */
	private IntercompanyToleranceGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = $this->buildGuard(
			store: $this->buildObjectServiceStub(relations: [], rules: [])
		);

	}//end setUp()

	/**
	 * Build the guard over a seeded in-memory store.
	 *
	 * ADR-084 injects the ObjectService through the constructor, so a test's
	 * store has to be present when the guard is built — parking it on the
	 * container after the fact leaves the guard reading an empty world.
	 *
	 * @param object $store The duck-typed in-memory ObjectService double.
	 *
	 * @return IntercompanyToleranceGuard
	 */
	private function buildGuard(object $store): IntercompanyToleranceGuard {
		return new IntercompanyToleranceGuard(
			$this->appConfig,
			$this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end buildGuard()

	/**
	 * A near-zero mismatch is always within tolerance without any rule lookup.
	 *
	 * @return void
	 */
	public function testPerfectMatchAlwaysWithin(): void {
		$match = [
			'matchId' => 'm1',
			'matchStatus' => 'perfect-match',
			'mismatchAmount' => 0.0,
		];
		self::assertTrue($this->guard->isWithinTolerance($match));

	}//end testPerfectMatchAlwaysWithin()

	/**
	 * One-sided matches are never within tolerance (await counterparty booking).
	 *
	 * @return void
	 */
	public function testOneSidedNeverWithin(): void {
		self::assertFalse(
			$this->guard->isWithinTolerance(['matchId' => 'm2', 'matchStatus' => 'one-sided-A', 'mismatchAmount' => 5.0])
		);
		self::assertFalse(
			$this->guard->isWithinTolerance(['matchId' => 'm3', 'matchStatus' => 'one-sided-B', 'mismatchAmount' => 5.0])
		);

	}//end testOneSidedNeverWithin()

	/**
	 * A small mismatch within the configured rule's relative threshold passes.
	 *
	 * @return void
	 */
	public function testWithinConfiguredRule(): void {
		$this->guard = $this->buildGuard(
			store: $this->buildObjectServiceStub(
				relations: [['relationId' => 'rel1', 'relationType' => 'sales-of-services']],
				rules: [
					[
						'relationTypeFilter' => 'sales-of-services',
						'toleranceAbsolute' => 10.0,
						'toleranceRelative' => 0.5,
						'toleranceMethod' => 'max-of-absolute-relative',
					],
				]
			)
		);

		$match = [
			'matchId' => 'm4',
			'matchStatus' => 'outside-tolerance',
			'relationId' => 'rel1',
			'administrationId' => 'adm1',
			'mismatchAmount' => 7.0,
			'mismatchPercentage' => 0.007,
			'totalAmountA' => 100000.0,
			'totalAmountB' => 99993.0,
		];
		self::assertTrue($this->guard->isWithinTolerance($match));

	}//end testWithinConfiguredRule()

	/**
	 * A mismatch beyond both thresholds of the configured rule is rejected.
	 *
	 * @return void
	 */
	public function testOutsideConfiguredRule(): void {
		$this->guard = $this->buildGuard(
			store: $this->buildObjectServiceStub(
				relations: [['relationId' => 'rel1', 'relationType' => 'sales-of-services']],
				rules: [
					[
						'relationTypeFilter' => 'sales-of-services',
						'toleranceAbsolute' => 10.0,
						'toleranceRelative' => 0.5,
						'toleranceMethod' => 'max-of-absolute-relative',
					],
				]
			)
		);

		$match = [
			'matchId' => 'm5',
			'matchStatus' => 'outside-tolerance',
			'relationId' => 'rel1',
			'administrationId' => 'adm1',
			'mismatchAmount' => 25000.0,
			'mismatchPercentage' => 25.0,
			'totalAmountA' => 100000.0,
			'totalAmountB' => 75000.0,
		];
		self::assertFalse($this->guard->isWithinTolerance($match));

	}//end testOutsideConfiguredRule()

	/**
	 * The min-of-absolute-relative method requires both thresholds, so a
	 * passing-absolute but failing-relative mismatch is rejected.
	 *
	 * @return void
	 */
	public function testMinOfMethodStricter(): void {
		$this->guard = $this->buildGuard(
			store: $this->buildObjectServiceStub(
				relations: [['relationId' => 'rel1', 'relationType' => 'dividend']],
				rules: [
					[
						'relationTypeFilter' => 'dividend',
						'toleranceAbsolute' => 5000.0,
						'toleranceRelative' => 0.01,
						'toleranceMethod' => 'min-of-absolute-relative',
					],
				]
			)
		);

		$match = [
			'matchId' => 'm6',
			'matchStatus' => 'outside-tolerance',
			'relationId' => 'rel1',
			'administrationId' => 'adm1',
			'mismatchAmount' => 100.0,
			'mismatchPercentage' => 1.0,
			'totalAmountA' => 10000.0,
			'totalAmountB' => 9900.0,
		];
		// Absolute passes (100 <= 5000) but relative fails (1% > 0.01%) -> reject.
		self::assertFalse($this->guard->isWithinTolerance($match));

	}//end testMinOfMethodStricter()

	/**
	 * With no rule configured, the conservative defaults (EUR 10 / 0.5% / max-of) apply.
	 *
	 * @return void
	 */
	public function testDefaultsWhenNoRule(): void {
		$this->guard = $this->buildGuard(
			store: $this->buildObjectServiceStub(
				relations: [['relationId' => 'rel1', 'relationType' => 'sales-of-goods']],
				rules: []
			)
		);

		$match = [
			'matchId' => 'm7',
			'matchStatus' => 'outside-tolerance',
			'relationId' => 'rel1',
			'administrationId' => 'adm1',
			'mismatchAmount' => 7.0,
			'mismatchPercentage' => 0.007,
			'totalAmountA' => 100000.0,
			'totalAmountB' => 99993.0,
		];
		self::assertTrue($this->guard->isWithinTolerance($match));

	}//end testDefaultsWhenNoRule()

	/**
	 * An ObjectService failure fails closed (denies within-tolerance).
	 *
	 * @return void
	 */
	public function testFailClosedOnException(): void {
		$this->guard = $this->buildGuard(store: $this->buildUnavailableObjectServiceStub());

		$match = [
			'matchId' => 'm8',
			'matchStatus' => 'outside-tolerance',
			'relationId' => 'rel1',
			'administrationId' => 'adm1',
			'mismatchAmount' => 7.0,
			'totalAmountA' => 100000.0,
			'totalAmountB' => 99993.0,
		];
		self::assertFalse($this->guard->isWithinTolerance($match));

	}//end testFailClosedOnException()

	/**
	 * Build an anonymous ObjectService stub returning relations or rules by schema.
	 *
	 * @param array<mixed> $relations IntercompanyRelation rows.
	 * @param array<mixed> $rules ToleranceRule rows.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $relations, array $rules): object {
		return new class($relations, $rules) {
			/**
			 * IntercompanyRelation rows.
			 *
			 * @var array<mixed>
			 */
			private array $relations;

			/**
			 * ToleranceRule rows.
			 *
			 * @var array<mixed>
			 */
			private array $rules;

			/**
			 * The schema last selected via setSchema().
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<mixed> $relations IntercompanyRelation rows.
			 * @param array<mixed> $rules ToleranceRule rows.
			 */
			public function __construct(array $relations, array $rules) {
				$this->relations = $relations;
				$this->rules = $rules;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter that records the selected schema.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return relations or rules depending on the selected schema.
			 *
			 * @param array<string,mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				if ($this->schema === 'ToleranceRule') {
					return $this->rules;
				}

				return $this->relations;
			}//end findAll()
		};
	}//end buildObjectServiceStub()

	/**
	 * Build a store that models an unavailable OpenRegister.
	 *
	 * Before ADR-084 this scenario was expressed as
	 * `$container->method('get')->willThrowException(...)`. The container is no
	 * longer consulted, so the refusal has to come from the store itself; every
	 * read throws exactly as a downed ObjectService would, which is what the
	 * guard's fail-closed arm is there to catch.
	 *
	 * @return object
	 */
	private function buildUnavailableObjectServiceStub(): object {
		return new class {
			/**
			 * Fluent register setter — returns self.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — returns self.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Refuse every list query.
			 *
			 * @param array<string,mixed> $params Query parameters (unused).
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('OR unavailable');
			}//end findAll()

			/**
			 * Refuse every single-object lookup.
			 *
			 * @param string|int $id Object ID.
			 *
			 * @return object|null
			 *
			 * @throws \RuntimeException Always.
			 */
			public function find(string|int $id): ?object {
				throw new \RuntimeException('OR unavailable');
			}//end find()
		};
	}//end buildUnavailableObjectServiceStub()
}//end class
