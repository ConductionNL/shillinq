<?php

/**
 * Unit tests for AdministrationArchivalService's administration lookup.
 *
 * The pre-existing suite constructs the service with a bare mocked container, so
 * `$container->get('OCA\OpenRegister\Service\ObjectService')` yields nothing
 * usable and `findAdministration()` always failed into its `catch`. Every
 * `assertWritableById()` path therefore collapsed onto one outcome and the whole
 * lookup — 24 statements including the record-shape handling — was never
 * executed. These tests supply a real fake so each arm is reached.
 *
 * ⚠️ Faithfulness note: `findAdministration()` probes
 * `method_exists($match, 'getObject')`. Unlike `getId()`/`getUuid()`, **that
 * probe is CORRECT** — `getObject()` is declared concretely on OpenRegister's
 * `ObjectEntity` (measured: `method_exists(ObjectEntity, 'getObject') === true`),
 * so it does not go through `Entity::__call()`. The entity double below
 * therefore declares `getObject()` concretely, mirroring the real collaborator.
 * Do not "fix" this probe the way the magic-accessor sites were fixed.
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
 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationArchivalService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers assertWritableById() and the record-shape handling behind it.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
class AdministrationArchivalServiceLookupTest extends TestCase {
	/**
	 * Build the service with a container serving the supplied fake ObjectService.
	 *
	 * @param mixed $objectService The fake, or null to make container->get() throw.
	 * @param string $registerSlug Value appConfig returns for the register key.
	 *
	 * @return AdministrationArchivalService
	 */
	private function buildService(mixed $objectService, string $registerSlug = 'shillinq'): AdministrationArchivalService {
		$container = $this->createMock(ContainerInterface::class);
		if ($objectService === null) {
			$container->method('get')->willThrowException(new \RuntimeException('OpenRegister absent'));
		} else {
			$container->method('get')->willReturn($objectService);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($registerSlug);

		return new AdministrationArchivalService(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end buildService()

	/**
	 * A fake ObjectService recording the query and returning fixed matches.
	 *
	 * @param array<int,mixed> $matches Rows findAll() should return.
	 * @param boolean $throws Whether findAll() should throw.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $matches, bool $throws = false): object {
		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		return new class($matches, $throws) {
			public string $seenRegister = '';

			public string $seenSchema = '';

			public array $seenConfig = [];

			public function __construct(
				private array $matches,
				private bool $throws,
			) {
			}

			public function setRegister(string $register): self {
				$this->seenRegister = $register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->seenSchema = $schema;
				return $this;
			}

			// ⚠️ THROWS on a miss, like the real one — it never returns null,
			// so a caller wanting a fallback needs its own try/catch. No row
			// here is addressed by uuid, so this always throws and the
			// administrationCode fallback is what answers. The double had no
			// find() at all, which is why it could not see that the service's
			// by-id lookup never worked.
			public function find(string $id): array {
				throw new \OCP\AppFramework\Db\DoesNotExistException(
					sprintf("Object with identifier '%s' not found in any magic table", $id)
				);
			}

			// ⚠️ `filters` addresses JSON PROPERTIES only. Real OpenRegister
			// matches ZERO rows for ['filters' => ['id' => …]] at every value,
			// because the entity's `id` is its own column — mirrored here so
			// this double cannot bless that shape again.
			public function findAll(array $config): array {
				$this->seenConfig = $config;
				if ($this->throws === true) {
					throw new \RuntimeException('findAll exploded');
				}

				if (array_key_exists('id', ($config['filters'] ?? [])) === true) {
					return [];
				}

				return $this->matches;
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

	}//end fakeObjectService()

	/**
	 * An entity-shaped row exposing getObject(), as ObjectEntity really does.
	 *
	 * @param mixed $payload What getObject() returns.
	 *
	 * @return object
	 */
	private function entityRow(mixed $payload): object {
		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		return new class($payload) {
			public function __construct(
				private mixed $payload,
			) {
			}

			public function getObject(): mixed {
				return $this->payload;
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

	}//end entityRow()

	/**
	 * An empty administration id is refused before any lookup happens.
	 *
	 * @return void
	 */
	public function testEmptyIdIsRefusedBeforeLookup(): void {
		$service = $this->buildService($this->fakeObjectService([]));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('administratie ontbreekt');

		$service->assertWritableById('');

	}//end testEmptyIdIsRefusedBeforeLookup()

	/**
	 * An array row for an active administration permits the write.
	 *
	 * @return void
	 */
	public function testArrayRowForActiveAdministrationIsWritable(): void {
		$fake = $this->fakeObjectService(
			[['id' => 'adm-1', 'administrationCode' => 'adm-1', 'status' => 'actief']]
		);
		$service = $this->buildService($fake);

		$service->assertWritableById('adm-1');

		// The lookup must be tenant-targeted and bounded.
		self::assertSame('shillinq', $fake->seenRegister);
		self::assertSame('Administration', $fake->seenSchema);

		// ⚠️ `administrationCode`, NOT `id`. This assertion used to pin
		// ['id' => 'adm-1'] — the shape real OpenRegister answers with zero
		// rows for every value, because `filters` addresses JSON properties
		// and the entity's `id` is its own column. The test was holding the
		// broken lookup in place as though it were the contract.
		self::assertSame(['administrationCode' => 'adm-1'], $fake->seenConfig['filters']);
		self::assertSame(1, $fake->seenConfig['limit']);

	}//end testArrayRowForActiveAdministrationIsWritable()

	/**
	 * An entity row is unwrapped through getObject() and honoured.
	 *
	 * @return void
	 */
	public function testEntityRowIsUnwrappedThroughGetObject(): void {
		$service = $this->buildService(
			$this->fakeObjectService([$this->entityRow(['id' => 'adm-1', 'status' => 'actief'])])
		);

		$service->assertWritableById('adm-1');

		self::assertTrue(true, 'no exception means the unwrapped record was accepted');

	}//end testEntityRowIsUnwrappedThroughGetObject()

	/**
	 * A read-only administration is refused even though it resolves.
	 *
	 * Distinguishes "found but archived" from "not found" — both throw, and the
	 * message is the only thing that separates them.
	 *
	 * @return void
	 */
	public function testArchivedAdministrationIsRefusedAsReadOnly(): void {
		$service = $this->buildService(
			$this->fakeObjectService([['id' => 'adm-1', 'status' => 'gearchiveerd']])
		);

		$this->expectException(RuntimeException::class);
		// Deliberately NOT asserting only the exception class: an unresolvable id
		// throws the same class, so the class alone cannot tell the two apart.
		$this->expectExceptionMessageMatches('/gearchiveerd|alleen-lezen|read-only|niet toegestaan/i');

		$service->assertWritableById('adm-1');

	}//end testArchivedAdministrationIsRefusedAsReadOnly()

	/**
	 * No matching row denies the write, default-secure.
	 *
	 * @return void
	 */
	public function testUnresolvableIdDeniesTheWrite(): void {
		$service = $this->buildService($this->fakeObjectService([]));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('administratie niet gevonden (id=adm-missing)');

		$service->assertWritableById('adm-missing');

	}//end testUnresolvableIdDeniesTheWrite()

	/**
	 * An entity whose getObject() is not an array falls through to "not found".
	 *
	 * @return void
	 */
	public function testEntityWithNonArrayPayloadDeniesTheWrite(): void {
		$service = $this->buildService(
			$this->fakeObjectService([$this->entityRow('not-an-array')])
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('administratie niet gevonden (id=adm-1)');

		$service->assertWritableById('adm-1');

	}//end testEntityWithNonArrayPayloadDeniesTheWrite()

	/**
	 * A row that is neither array nor getObject()-bearing denies the write.
	 *
	 * @return void
	 */
	public function testOpaqueRowDeniesTheWrite(): void {
		$service = $this->buildService($this->fakeObjectService([new \stdClass()]));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('administratie niet gevonden (id=adm-1)');

		$service->assertWritableById('adm-1');

	}//end testOpaqueRowDeniesTheWrite()

	/**
	 * A throwing findAll() denies the write rather than letting it through.
	 *
	 * Fail-closed: the lookup failing must not be read as "no restriction".
	 *
	 * @return void
	 */
	public function testThrowingLookupDeniesTheWrite(): void {
		$service = $this->buildService($this->fakeObjectService([], true));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('administratie niet gevonden (id=adm-1)');

		$service->assertWritableById('adm-1');

	}//end testThrowingLookupDeniesTheWrite()

	/**
	 * An absent OpenRegister denies the write.
	 *
	 * @return void
	 */
	public function testAbsentOpenRegisterDeniesTheWrite(): void {
		$service = $this->buildService(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('administratie niet gevonden (id=adm-1)');

		$service->assertWritableById('adm-1');

	}//end testAbsentOpenRegisterDeniesTheWrite()

	/**
	 * An empty configured register slug falls back to 'shillinq'.
	 *
	 * @return void
	 */
	public function testEmptyRegisterSlugFallsBackToShillinq(): void {
		$fake = $this->fakeObjectService([['id' => 'adm-1', 'status' => 'actief']]);
		$service = $this->buildService($fake, '');

		$service->assertWritableById('adm-1');

		self::assertSame('shillinq', $fake->seenRegister);

	}//end testEmptyRegisterSlugFallsBackToShillinq()

	/**
	 * A configured non-default register slug is honoured.
	 *
	 * Paired with the fallback test so the fallback cannot pass by coincidence:
	 * with only the empty-slug case, a hardcoded 'shillinq' would look correct.
	 *
	 * @return void
	 */
	public function testConfiguredRegisterSlugIsHonoured(): void {
		$fake = $this->fakeObjectService([['id' => 'adm-1', 'status' => 'actief']]);
		$service = $this->buildService($fake, 'custom-register');

		$service->assertWritableById('adm-1');

		self::assertSame('custom-register', $fake->seenRegister);

	}//end testConfiguredRegisterSlugIsHonoured()
}//end class
