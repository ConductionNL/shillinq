<?php

/**
 * HrmqCostRateAdapter Unit Tests
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\HrmqCostRateAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

// shillinq's CI does not install hrmq, so the class the adapter probes for
// with class_exists() is genuinely absent here. Two of the tests below need it
// PRESENT to exercise the resolved path at all — without this they skip, and a
// test that always skips is a test that never protects anything.
//
// Aliasing a stub into hrmq's namespace makes class_exists() answer true while
// keeping the real dependency absent, which is exactly the shape the adapter
// has to cope with. Guarded so that an environment where hrmq IS installed
// uses the real class instead of colliding with it.
if (class_exists('OCA\\Hrmq\\Service\\EmployeeCostRateService') === false) {
	class_alias(HrmqCostRateServiceStub::class, 'OCA\\Hrmq\\Service\\EmployeeCostRateService');
}

/**
 * Stand-in for hrmq's cost-rate service. Never used directly — the container
 * stub returns the per-test double; this exists only so class_exists() passes.
 */
class HrmqCostRateServiceStub {
	/**
	 * @return array<string, mixed> An empty resolution.
	 */
	public function resolve(...$args): array {
		return [];
	}
}

/**
 * The adapter must degrade to "no rates", never to "zero".
 *
 * The distinction is the whole safety property. An absent key makes
 * SubjectCostAggregator withhold the total; a zero would be multiplied
 * happily, producing a cost that silently excludes that person.
 *
 * The hrmq-absent case is also the one this fleet has been bitten by: naming a
 * missing app's type anywhere the container resolves eagerly is fatal, so the
 * structural test below pins that no hrmq type appears in the constructor.
 *
 * @covers \OCA\Shillinq\Service\HrmqCostRateAdapter
 */
class HrmqCostRateAdapterTest extends TestCase {

	/**
	 * Build an adapter over a stub container.
	 *
	 * @param mixed $costRateService The hrmq service double, or null to omit it.
	 * @param array<string, mixed> $objects Object id => row returned by find().
	 * @param array<int, mixed> $contracts Rows returned by findAll().
	 *
	 * @return HrmqCostRateAdapter The adapter.
	 */
	private function adapter(mixed $costRateService, array $objects = [], array $contracts = []): HrmqCostRateAdapter {
		$objectService = new class($objects, $contracts) {
			/**
			 * @param array<string, mixed> $objects Rows by id.
			 * @param array<int, mixed> $contracts Contract rows.
			 */
			public function __construct(
				private array $objects,
				private array $contracts,
			) {
			}

			/**
			 * @return mixed The stubbed row.
			 */
			public function find(string $id, string $register, string $schema): mixed {
				if (array_key_exists($id, $this->objects) === false) {
					throw new RuntimeException('not found');
				}

				return $this->objects[$id];
			}

			/**
			 * @return array<int, mixed> The stubbed contracts.
			 */
			public function findAll(string $register, string $schema, array $filters = []): array {
				return $this->contracts;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $costRateService) {
				if (str_contains($id, 'ObjectService') === true) {
					return $objectService;
				}

				if ($costRateService === null) {
					throw new RuntimeException('hrmq absent');
				}

				return $costRateService;
			}
		);

		return new HrmqCostRateAdapter($container, new NullLogger());
	}

	/**
	 * A resolvable person is priced from hrmq's total.
	 *
	 * @return void
	 */
	public function testPricesAPersonFromHrmqsTotal(): void {
		$service = new class {
			/**
			 * @return array<string, mixed> The resolved rate.
			 */
			public function resolve(...$args): array {
				return ['totalCentsPerHour' => 6250];
			}
		};

		$rates = $this->adapter($service, ['alice' => ['id' => 'alice']])->ratesFor(['alice']);

		self::assertSame(['alice' => 6250], $rates);
	}

	/**
	 * With hrmq absent the map is EMPTY — never zeros.
	 *
	 * An empty map makes the aggregator withhold the cost. A map of zeros
	 * would be multiplied into a confident €0.00.
	 *
	 * @return void
	 */
	public function testAbsentHrmqYieldsNoRatesRatherThanZeroes(): void {
		$rates = $this->adapter(null, ['alice' => ['id' => 'alice']])->ratesFor(['alice', 'bob']);

		self::assertSame([], $rates);
		self::assertNotContains(0, $rates, 'a zero rate would be silently multiplied into a wrong total');
	}

	/**
	 * No hrmq type may appear in the constructor.
	 *
	 * This is the structural guard, and it exists because the fleet has
	 * already paid for the alternative: openregister named a ContextChat type
	 * in a constructor, `SimpleContainer::resolve()` reflected it, and that
	 * reflection loaded a class that does not exist on servers without the
	 * dependency — throwing on every occ invocation and every object write.
	 *
	 * @return void
	 */
	public function testNoHrmqTypeIsNamedInTheConstructor(): void {
		$constructor = (new ReflectionClass(HrmqCostRateAdapter::class))->getConstructor();
		self::assertNotNull(actual: $constructor);

		foreach ($constructor->getParameters() as $parameter) {
			$type = $parameter->getType();
			if ($type === null || $type->isBuiltin() === true) {
				continue;
			}

			self::assertStringNotContainsString(
				needle: 'Hrmq',
				haystack: $type->getName(),
				message: sprintf(
					'%s is typed to %s. shillinq does not depend on hrmq, so the container '
					. 'resolving this class would fatal wherever hrmq is absent. Resolve it '
					. 'lazily behind class_exists() instead.',
					$parameter->getName(),
					$type->getName()
				)
			);
		}
	}

	/**
	 * The hrmq class is referenced as a STRING, never `::class`.
	 *
	 * `Foo::class` on an imported name is compile-time and safe, but on an
	 * IMPORTED symbol it also requires the `use` statement — and a `use` of a
	 * missing class is itself harmless while being an invitation to typehint
	 * it later. Keeping the name a string keeps the boundary obvious.
	 *
	 * @return void
	 */
	public function testTheHrmqClassNameStaysAString(): void {
		$source = (string)file_get_contents(
			__DIR__ . '/../../../lib/Service/HrmqCostRateAdapter.php'
		);

		self::assertStringNotContainsString(
			needle: 'use OCA\\Hrmq\\',
			haystack: $source,
			message: 'importing an hrmq symbol makes it one edit away from a fatal constructor typehint'
		);
		self::assertStringContainsString(
			needle: 'class_exists(class: self::HRMQ_COST_RATE_SERVICE)',
			haystack: $source,
			message: 'the hrmq service must be probed before it is resolved'
		);
	}

	/**
	 * A person hrmq refuses is OMITTED, not defaulted.
	 *
	 * @return void
	 */
	public function testARefusedPersonIsOmittedFromTheMap(): void {
		$service = new class {
			/**
			 * @return array<string, mixed> Never returned — always throws.
			 */
			public function resolve(...$args): array {
				throw new RuntimeException('addition "overhead" has no basis');
			}
		};

		$rates = $this->adapter($service, ['alice' => ['id' => 'alice']])->ratesFor(['alice']);

		self::assertArrayNotHasKey('alice', $rates);
	}
}
