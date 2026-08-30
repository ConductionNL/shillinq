<?php

/**
 * Tests for {@see \OCA\Shillinq\Service\ListenerSchemaResolver}.
 *
 * These are POSITIVE controls for the id-vs-slug listener defect: each one
 * asserts the resolver actually PRODUCES the slug a listener guard compares
 * against, given the id-shaped entity OpenRegister really emits. A test that
 * only asserted "an unrelated schema is ignored" would pass against a resolver
 * that returned '' unconditionally — which is exactly how the original defect
 * stayed invisible.
 *
 * The id/slug pairs below are the real values on the development instance
 * (`oc_openregister_schemas`): LeaseContract=1089, ACMReport=1102,
 * SalesOrder=1119, and the two colliding `automation` schemas 71 / 5103.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\ListenerSlugContract;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers slug resolution, register scoping and the default-off gate.
 */
class ListenerSchemaResolverTest extends TestCase {

	/**
	 * Build a resolver over a fixed id => slug map.
	 *
	 * @param array<string,string> $schemas Schema id => slug.
	 * @param array<string,string> $registers Register id => slug.
	 * @param bool $enabled Whether the slug contract is on.
	 * @param string $ownSlug shillinq's configured register slug.
	 *
	 * @return ListenerSchemaResolver
	 */
	private function resolver(
		array $schemas,
		array $registers,
		bool $enabled = true,
		string $ownSlug = 'shillinq',
	): ListenerSchemaResolver {
		$schemaMapper = $this->mapperReturning(map: $schemas);
		$registerMapper = $this->mapperReturning(map: $registers);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $service) use ($schemaMapper, $registerMapper) {
				if (str_contains($service, 'SchemaMapper') === true) {
					return $schemaMapper;
				}

				return $registerMapper;
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn($ownSlug);

		$contract = $this->createMock(ListenerSlugContract::class);
		$contract->method('isEnabled')->willReturn($enabled);

		return new ListenerSchemaResolver(
			container: $container,
			settingsService: $settings,
			contract: $contract,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end resolver()

	/**
	 * A stand-in mapper whose find() resolves ids from a map.
	 *
	 * @param array<string,string> $map Id => slug.
	 *
	 * @return object
	 */
	private function mapperReturning(array $map): object {
		return new class($map) {
			/**
			 * Constructor.
			 *
			 * @param array<string,string> $map Id => slug.
			 */
			public function __construct(
				private array $map,
			) {
			}

			/**
			 * Resolve an id to a slug-bearing entity.
			 *
			 * @param string $id The id to resolve.
			 *
			 * @return object
			 *
			 * @throws \RuntimeException When the id is unknown.
			 */
			public function find(string $id): object {
				if (array_key_exists($id, $this->map) === false) {
					throw new \RuntimeException('not found');
				}

				return new class($this->map[$id]) {
					/**
					 * Constructor.
					 *
					 * @param string $slug The slug.
					 */
					public function __construct(
						private string $slug,
					) {
					}

					/**
					 * The slug.
					 *
					 * @return string
					 */
					public function getSlug(): string {
						return $this->slug;
					}
				};
			}
		};

	}//end mapperReturning()

	/**
	 * An ObjectEntity-shaped stub carrying ids, exactly as MagicMapper emits.
	 *
	 * @param string $registerId The register id.
	 * @param string $schemaId The schema id.
	 *
	 * @return object
	 */
	private function entity(string $registerId, string $schemaId): object {
		return new class($registerId, $schemaId) {
			/**
			 * Constructor.
			 *
			 * @param string $registerId The register id.
			 * @param string $schemaId The schema id.
			 */
			public function __construct(
				private string $registerId,
				private string $schemaId,
			) {
			}

			/**
			 * The register id, as OpenRegister stamps it.
			 *
			 * @return string
			 */
			public function getRegister(): string {
				return $this->registerId;
			}

			/**
			 * The schema id, as OpenRegister stamps it.
			 *
			 * @return string
			 */
			public function getSchema(): string {
				return $this->schemaId;
			}
		};

	}//end entity()

	/**
	 * POSITIVE CONTROL: an id-shaped entity resolves to the slug the listeners
	 * compare against. Before the fix this returned the id '1089'.
	 *
	 * @return void
	 */
	public function testResolvesSchemaIdToSlug(): void {
		$resolver = $this->resolver(
			schemas: ['1089' => 'LeaseContract'],
			registers: ['264' => 'shillinq'],
		);

		$slug = $resolver->schemaSlug(entity: $this->entity(registerId: '264', schemaId: '1089'));

		$this->assertSame('LeaseContract', $slug);
		// And the listeners' own normalisation still matches.
		$this->assertSame('leasecontract', strtolower($slug));

	}//end testResolvesSchemaIdToSlug()

	/**
	 * POSITIVE CONTROL: an uppercase slug survives verbatim. `ACMReport` and
	 * `AnnualReport` really are the slugs (ids 1102 / 1008) — 891 of this
	 * instance's schema slugs contain uppercase, so kebab-casing them would
	 * BREAK the guards rather than fix them.
	 *
	 * @return void
	 */
	public function testUppercaseSlugIsPreservedVerbatim(): void {
		$resolver = $this->resolver(
			schemas: ['1102' => 'ACMReport'],
			registers: ['264' => 'shillinq'],
		);

		$this->assertSame(
			'ACMReport',
			$resolver->schemaSlug(entity: $this->entity(registerId: '264', schemaId: '1102'))
		);

	}//end testUppercaseSlugIsPreservedVerbatim()

	/**
	 * The register scope is what stops a schema-only literal firing on another
	 * app's objects. Schema 5103 is also slugged `automation`, exactly like
	 * schema 71 — but it lives in a different register.
	 *
	 * @return void
	 */
	public function testForeignRegisterYieldsEmptySlug(): void {
		$resolver = $this->resolver(
			schemas: [
				'71' => 'automation',
				'5103' => 'automation',
			],
			registers: [
				'264' => 'shillinq',
				'9' => 'scholiq',
			],
		);

		// Same slug, foreign register -> refused.
		$this->assertSame(
			'',
			$resolver->schemaSlug(entity: $this->entity(registerId: '9', schemaId: '5103'))
		);

		// Positive control for the same call shape: own register -> resolved.
		$this->assertSame(
			'automation',
			$resolver->schemaSlug(entity: $this->entity(registerId: '264', schemaId: '71'))
		);

	}//end testForeignRegisterYieldsEmptySlug()

	/**
	 * With the contract disabled the resolver reproduces the pre-fix behaviour
	 * exactly: it hands back the raw id, so every listener guard still misses.
	 *
	 * @return void
	 */
	public function testDisabledContractReturnsTheRawIdSoListenersStayDead(): void {
		$resolver = $this->resolver(
			schemas: ['1089' => 'LeaseContract'],
			registers: ['264' => 'shillinq'],
			enabled: false,
		);

		$this->assertSame(
			'1089',
			$resolver->schemaSlug(entity: $this->entity(registerId: '264', schemaId: '1089'))
		);

	}//end testDisabledContractReturnsTheRawIdSoListenersStayDead()

	/**
	 * OpenRegister is a soft dependency: an absent mapper must degrade to '',
	 * never throw into the object-write path.
	 *
	 * @return void
	 */
	public function testUnresolvableSchemaDegradesToEmptyString(): void {
		$resolver = $this->resolver(
			schemas: [],
			registers: ['264' => 'shillinq'],
		);

		$this->assertSame(
			'',
			$resolver->schemaSlug(entity: $this->entity(registerId: '264', schemaId: '9999'))
		);

	}//end testUnresolvableSchemaDegradesToEmptyString()

	/**
	 * A null entity is not a match.
	 *
	 * @return void
	 */
	public function testNullEntityIsNotAMatch(): void {
		$resolver = $this->resolver(schemas: [], registers: []);

		$this->assertSame('', $resolver->schemaSlug(entity: null));
		$this->assertFalse($resolver->isOwnRegister(entity: null));

	}//end testNullEntityIsNotAMatch()

	/**
	 * An entity that already carries a slug (a hand-built one, or a future
	 * OpenRegister that stops stamping ids) is still recognised.
	 *
	 * @return void
	 */
	public function testRegisterSlugIsAcceptedDirectly(): void {
		$resolver = $this->resolver(
			schemas: ['1089' => 'LeaseContract'],
			registers: [],
		);

		$this->assertTrue(
			$resolver->isOwnRegister(entity: $this->entity(registerId: 'shillinq', schemaId: '1089'))
		);

	}//end testRegisterSlugIsAcceptedDirectly()
}//end class
