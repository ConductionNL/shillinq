<?php

/**
 * Unit tests for CycleCountService::extractId().
 *
 * These tests exist because the shipped implementation had TWO stacked
 * defects and a test suite that could not see either of them:
 *
 *   1. It probed with `method_exists()`. OpenRegister's ObjectEntity reaches
 *      `getId()` / `getUuid()` through `OCP\AppFramework\Db\Entity::__call()`,
 *      so `method_exists()` is FALSE for both and neither arm ever ran.
 *   2. The obvious repair — `property_exists($saved,'id')` then `getId()` —
 *      returns the NUMERIC bigint row id, while the value is written to
 *      `InventoryCycleCountLine.adjustmentStockMoveId`, a declared relation to
 *      `StockMove.id`, which renders as the UUID.
 *
 * ⚠️ The shared stub at `tests/stubs/OpenRegister/Db/ObjectEntity.php` DECLARES
 * `getId()` and `getUuid()` concretely, so under that stub `method_exists()`
 * answers TRUE — the double inverts the exact predicate under test, and the
 * defect is invisible. Every double in this file therefore mirrors the real
 * collaborator's SHAPE (magic accessors via `__call`, a concrete
 * `jsonSerialize()`) rather than the caller's expectations, and
 * `testTheDoubleIsFaithfulToTheRealEntityShape()` below asserts that shape so a
 * future edit cannot quietly make these tests vacuous again.
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
 * @spec openspec/specs/inventory-cycle-count/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\VarianceGate;
use OCA\Shillinq\Service\CycleCountService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests the identifier extraction seam of CycleCountService (REQ-ICC-007).
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
class CycleCountServiceExtractIdTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var CycleCountService
	 */
	private CycleCountService $service;

	/**
	 * Reflected accessor for the private extractId().
	 *
	 * @var ReflectionMethod
	 */
	private ReflectionMethod $extractId;

	/**
	 * The UUID every consumer of adjustmentStockMoveId actually joins on.
	 *
	 * @var string
	 */
	private const UUID = '4dbf0ba6-c05f-4ca8-a76b-65ff48488af6';

	/**
	 * The numeric bigint row id that getId() returns on a saveObject() result.
	 *
	 * @var integer
	 */
	private const ROW_ID = 5;

	/**
	 * Set up the service and reflect the private method under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new CycleCountService(
			appConfig: $appConfig,
			logger: $logger,
			varianceGate: new VarianceGate(
				appConfig: $appConfig,
				logger: $logger,
			container: $this->createMock(ContainerInterface::class),
		),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		$this->extractId = new ReflectionMethod(CycleCountService::class, 'extractId');
		$this->extractId->setAccessible(true);

	}//end setUp()

	/**
	 * Invoke the private extractId() with the given value.
	 *
	 * @param mixed $saved The stand-in saveObject() return value.
	 *
	 * @return string
	 */
	private function extract(mixed $saved): string {
		return (string)$this->extractId->invoke($this->service, $saved);
	}//end extract()

	/**
	 * Build a double shaped like a real ObjectEntity returned by saveObject().
	 *
	 * `getId()` and `getUuid()` are DELIBERATELY not declared — on the real
	 * entity they arrive through `Entity::__call()`, which is precisely why
	 * `method_exists()` is false for them in production. `jsonSerialize()` IS
	 * declared, because on the real entity it is concrete.
	 *
	 * @param integer|string|null $id Value the magic getId() yields.
	 * @param string|null $uuid Value the magic getUuid() yields.
	 * @param boolean $serialiseThrows Whether jsonSerialize() throws.
	 *
	 * @return object
	 */
	private function savedEntityDouble(
		int|string|null $id,
		?string $uuid,
		bool $serialiseThrows = false,
	): object {
		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		return new class($id, $uuid, $serialiseThrows) {
			public function __construct(
				private int|string|null $id,
				private ?string $uuid,
				private bool $serialiseThrows,
			) {
			}

			public function __call(string $name, array $arguments): mixed {
				return match ($name) {
					'getId' => $this->id,
					'getUuid' => $this->uuid,
					default => throw new \BadMethodCallException($name),
				};
			}

			public function jsonSerialize(): array {
				if ($this->serialiseThrows === true) {
					throw new \RuntimeException('render failed');
				}

				return [
					'id' => $this->uuid,
					'name' => 'probe',
				];
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

	}//end savedEntityDouble()

	/**
	 * Build a double with magic accessors and NO jsonSerialize() at all.
	 *
	 * Exercises the `property_exists('uuid')` fallback arm on its own.
	 *
	 * @param string|null $uuid Value the magic getUuid() yields.
	 *
	 * @return object
	 */
	private function entityWithoutSerialiser(?string $uuid): object {
		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		return new class($uuid) {
			public function __construct(
				private ?string $uuid,
			) {
			}

			public function __call(string $name, array $arguments): mixed {
				return match ($name) {
					'getUuid' => $this->uuid,
					default => throw new \BadMethodCallException($name),
				};
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

	}//end entityWithoutSerialiser()

	/**
	 * The double must mirror the real entity's SHAPE, not the caller's wishes.
	 *
	 * This is a fixture-faithfulness assertion. If it ever fails, every other
	 * test in this file has stopped testing the defect it is named for.
	 *
	 * @return void
	 */
	public function testTheDoubleIsFaithfulToTheRealEntityShape(): void {
		$double = $this->savedEntityDouble(self::ROW_ID, self::UUID);

		// Exactly what was measured against the real OCA\OpenRegister\Db\ObjectEntity
		// in a running container.
		$this->assertFalse(
			method_exists($double, 'getId'),
			'getId() must be magic on the double, as it is on the real entity'
		);
		$this->assertFalse(
			method_exists($double, 'getUuid'),
			'getUuid() must be magic on the double, as it is on the real entity'
		);
		$this->assertTrue(
			method_exists($double, 'jsonSerialize'),
			'POSITIVE CONTROL: jsonSerialize() is concrete on the real entity'
		);
		$this->assertTrue(
			property_exists($double, 'id'),
			'the real entity carries an `id` property, which is what Entity::getter() decides on'
		);
		$this->assertTrue(property_exists($double, 'uuid'));

		// NEGATIVE CONTROL: this single line is why `is_callable()` is not the
		// fix. On a __call() class it is true for a method that cannot exist.
		$this->assertTrue(
			is_callable([$double, 'totalNonsenseXyz']),
			'is_callable() is NOT a membership test on a __call() class'
		);

	}//end testTheDoubleIsFaithfulToTheRealEntityShape()

	/**
	 * A saveObject() return must yield the UUID.
	 *
	 * This is the test that fails on the shipped `method_exists()` body: both
	 * probes are false there, so extractId() returned '' and emitAdjustments()
	 * bailed after it had already persisted a draft StockMove.
	 *
	 * @return void
	 */
	public function testSavedEntityYieldsTheUuid(): void {
		$result = $this->extract($this->savedEntityDouble(self::ROW_ID, self::UUID));

		$this->assertSame(self::UUID, $result);

	}//end testSavedEntityYieldsTheUuid()

	/**
	 * The numeric row id must never be returned.
	 *
	 * `adjustmentStockMoveId` is declared in inventory-cycle-count.json as a
	 * relation to `StockMove.id`, and OR renders `id` as the UUID. Returning
	 * the bigint would store a dangling foreign key that fails silently and
	 * much later — strictly worse than the dead path it replaced. This is the
	 * assertion the naive `property_exists('id') + getId()` repair fails.
	 *
	 * @return void
	 */
	public function testTheNumericRowIdIsNeverReturned(): void {
		$result = $this->extract($this->savedEntityDouble(self::ROW_ID, self::UUID));

		$this->assertNotSame(
			(string)self::ROW_ID,
			$result,
			'extractId() must not return the bigint row id — the FK target renders as a UUID'
		);
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
			$result,
			'the extracted identifier must be UUID-shaped'
		);

	}//end testTheNumericRowIdIsNeverReturned()

	/**
	 * The fixture trap: a findAll()-sourced entity yields getId() === null.
	 *
	 * A test built from a READ rather than a SAVE sees getId() return null,
	 * falls through, and certifies a probe-only fix as correct. Pinning both
	 * sources here keeps that asymmetry from hiding a regression.
	 *
	 * @return void
	 */
	public function testReadSourcedEntityAlsoYieldsTheUuid(): void {
		$result = $this->extract($this->savedEntityDouble(null, self::UUID));

		$this->assertSame(self::UUID, $result);

	}//end testReadSourcedEntityAlsoYieldsTheUuid()

	/**
	 * With no serialiser, the uuid property probe carries the extraction.
	 *
	 * @return void
	 */
	public function testEntityWithoutSerialiserFallsBackToTheUuidProbe(): void {
		$result = $this->extract($this->entityWithoutSerialiser(self::UUID));

		$this->assertSame(self::UUID, $result);

	}//end testEntityWithoutSerialiserFallsBackToTheUuidProbe()

	/**
	 * A throwing jsonSerialize() must not escape; the uuid probe takes over.
	 *
	 * @return void
	 */
	public function testThrowingSerialiserFallsThroughToTheUuidProbe(): void {
		$result = $this->extract($this->savedEntityDouble(self::ROW_ID, self::UUID, true));

		$this->assertSame(self::UUID, $result);

	}//end testThrowingSerialiserFallsThroughToTheUuidProbe()

	/**
	 * An entity with NO uuid property at all yields '' — the property_exists arm.
	 *
	 * Distinct from the case below: here `property_exists($saved, 'uuid')` is
	 * false, so the magic accessor is never called. There the property exists and
	 * merely holds null.
	 *
	 * @return void
	 */
	public function testEntityWithoutUuidPropertyYieldsEmptyString(): void {
		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$noUuidProperty = new class {
			public function jsonSerialize(): array {
				return ['name' => 'no identifiers here'];
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

		$this->assertFalse(property_exists($noUuidProperty, 'uuid'), 'fixture precondition');
		$this->assertSame('', $this->extract($noUuidProperty));

	}//end testEntityWithoutUuidPropertyYieldsEmptyString()

	/**
	 * A jsonSerialize() returning a non-array is ignored, not trusted.
	 *
	 * @return void
	 */
	public function testNonArrayRenderedPayloadIsIgnored(): void {
		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$scalarPayload = new class {

			private ?string $uuid = '4dbf0ba6-c05f-4ca8-a76b-65ff48488af6';

			public function __call(string $name, array $arguments): mixed {
				return match ($name) {
					'getUuid' => $this->uuid,
					default => throw new \BadMethodCallException($name),
				};
			}

			public function jsonSerialize(): mixed {
				return 'not-an-array';
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

		// Falls past the rendered arm and recovers via the uuid probe.
		$this->assertSame(self::UUID, $this->extract($scalarPayload));

	}//end testNonArrayRenderedPayloadIsIgnored()

	/**
	 * A throwing magic accessor is contained and yields ''.
	 *
	 * `Entity::__call()` raises BadFunctionCallException for a property the
	 * entity does not carry; that must not escape into the caller.
	 *
	 * @return void
	 */
	public function testThrowingAccessorIsContained(): void {
		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$throwingAccessor = new class {

			private ?string $uuid = null;

			public function __call(string $name, array $arguments): mixed {
				throw new \BadFunctionCallException($name);
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

		$this->assertTrue(property_exists($throwingAccessor, 'uuid'), 'fixture precondition');
		$this->assertSame('', $this->extract($throwingAccessor));

	}//end testThrowingAccessorIsContained()

	/**
	 * An entity carrying neither a rendered id nor a uuid yields ''.
	 *
	 * The caller treats '' as "no id derivable" and refuses — fail-closed.
	 *
	 * @return void
	 */
	public function testEntityWithNoDerivableIdentifierYieldsEmptyString(): void {
		$result = $this->extract($this->savedEntityDouble(self::ROW_ID, null));

		$this->assertSame('', $result);

	}//end testEntityWithNoDerivableIdentifierYieldsEmptyString()

	/**
	 * The array arm returns the top-level id, which is already the UUID.
	 *
	 * @return void
	 */
	public function testArrayShapeReturnsTopLevelId(): void {
		$result = $this->extract(['id' => self::UUID, 'name' => 'probe']);

		$this->assertSame(self::UUID, $result);

	}//end testArrayShapeReturnsTopLevelId()

	/**
	 * The array arm falls back to the @self envelope.
	 *
	 * @return void
	 */
	public function testArrayShapeFallsBackToSelfEnvelope(): void {
		$result = $this->extract(['@self' => ['id' => self::UUID]]);

		$this->assertSame(self::UUID, $result);

	}//end testArrayShapeFallsBackToSelfEnvelope()

	/**
	 * Both arms must agree on the identifier space.
	 *
	 * The array arm always returned the UUID; before this fix the object arm
	 * would have returned the bigint. A function answering in two identifier
	 * spaces depending on an input shape the caller does not control is the
	 * underlying defect, so assert the agreement directly.
	 *
	 * @return void
	 */
	public function testBothArmsAgreeOnTheIdentifierSpace(): void {
		$fromArray = $this->extract(['id' => self::UUID]);
		$fromEntity = $this->extract($this->savedEntityDouble(self::ROW_ID, self::UUID));

		$this->assertSame($fromArray, $fromEntity);

	}//end testBothArmsAgreeOnTheIdentifierSpace()

	/**
	 * Values that are neither array nor object yield ''.
	 *
	 * @return void
	 */
	public function testScalarAndNullYieldEmptyString(): void {
		$this->assertSame('', $this->extract(null));
		$this->assertSame('', $this->extract('some-string'));
		$this->assertSame('', $this->extract(42));
		$this->assertSame('', $this->extract([]));

	}//end testScalarAndNullYieldEmptyString()
}//end class
