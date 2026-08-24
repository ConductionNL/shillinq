<?php

/**
 * Resolves an OpenRegister object's schema **slug** for shillinq's listeners.
 *
 * OpenRegister's {@see \OCA\OpenRegister\Db\MagicMapper} stamps the numeric
 * **ids** of the register and schema onto every {@see
 * \OCA\OpenRegister\Db\ObjectEntity} it materialises:
 *
 *     $result->setSchema((string) $schema->getId());
 *     $result->setRegister((string) $register->getId());
 *
 * Shillinq's listeners, however, compare that value against a schema **slug**
 * literal (`'leasecontract'`, `'ACMReport'`, `'commitment'`, ...). An id can
 * never equal a slug, so every one of those guards returned early on every
 * event: the handler bodies had never run once. There was no exception and no
 * log line — the listeners were still constructed and invoked on every object
 * write instance-wide, they simply did nothing.
 *
 * This resolver turns the id back into a slug so the existing literals match.
 * Three properties matter:
 *
 * 1. **Register-scoped.** Matching on schema alone is not safe: this instance
 *    carries two distinct schemas both slugged `automation` (ids 71 and 5103),
 *    so a schema-only match fires on another app's objects. Callers therefore
 *    get `''` for anything outside shillinq's own register.
 * 2. **Container-resolved.** OpenRegister is a soft dependency; the mappers are
 *    pulled from the DI container at call time and every failure degrades to
 *    `''`, so shillinq still boots and runs with OpenRegister absent.
 * 3. **Gated.** Waking these listeners is a behaviour change, not a bug fix —
 *    see {@see ListenerSlugContract}. While the contract is disabled this
 *    returns the raw entity value (the id), which reproduces today's dead
 *    behaviour byte for byte.
 *
 * {@see \OCA\OpenRegister\Db\SchemaMapper::find()} and
 * {@see \OCA\OpenRegister\Db\RegisterMapper::find()} are request-cached by
 * OpenRegister, so the lookup does not add a query per event.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns an OpenRegister entity's schema id into its slug, scoped to shillinq's
 * own register.
 *
 * @spec openspec/specs/missing-lifecycle-guards/spec.md
 */
class ListenerSchemaResolver {

	/**
	 * FQCN of OpenRegister's schema mapper.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * FQCN of OpenRegister's register mapper.
	 *
	 * @var string
	 */
	private const REGISTER_MAPPER = 'OCA\\OpenRegister\\Db\\RegisterMapper';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OpenRegister mappers
	 *                                      are resolved lazily so shillinq boots
	 *                                      without it.
	 * @param SettingsService $settingsService Supplies shillinq's configured register slug.
	 * @param ListenerSlugContract $contract Default-off gate for the corrected matching.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly ListenerSlugContract $contract,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the schema slug of an OpenRegister object entity.
	 *
	 * Returns `''` when the object does not belong to shillinq's register, when
	 * the schema cannot be resolved, or when OpenRegister is unavailable — every
	 * caller treats `''` as "not my object" and returns early, so an
	 * unresolvable entity is never mistaken for a match.
	 *
	 * While {@see ListenerSlugContract} is disabled this deliberately returns
	 * the entity's raw schema value (an id), preserving the pre-fix behaviour.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event.
	 *
	 * @return string The schema slug, or '' when this is not a shillinq object.
	 *
	 * @spec openspec/specs/missing-lifecycle-guards/spec.md
	 */
	public function schemaSlug(?object $entity): string {
		$rawSchema = $this->readAccessor(entity: $entity, getter: 'getSchema');
		if ($rawSchema === '') {
			return '';
		}

		// Gate closed: reproduce the pre-fix comparison exactly.
		if ($this->contract->isEnabled() === false) {
			return $rawSchema;
		}

		if ($this->isOwnRegister(entity: $entity) === false) {
			return '';
		}

		return $this->resolveSlug(service: self::SCHEMA_MAPPER, id: $rawSchema);
	}//end schemaSlug()

	/**
	 * Read a value off an OpenRegister entity through an accessor that may be magic.
	 *
	 * `method_exists($entity, 'getSchema')` is FALSE for a real
	 * `OCA\OpenRegister\Db\ObjectEntity`: it extends Nextcloud's
	 * `OCP\AppFramework\Db\Entity`, which serves `getSchema()` / `getRegister()`
	 * through `__call()` rather than declaring them. Guarding an accessor with
	 * `method_exists()` therefore reported "this entity has no schema" for every
	 * entity that actually has one, which silently turned every listener built on
	 * this resolver into a no-op. Measured on a live instance: an
	 * `ObjectCreatingEvent` listener saw `getSchema` as missing on the very
	 * entity being persisted.
	 *
	 * Calls the accessor and tolerates failure — `Entity::__call()` throws
	 * `BadFunctionCallException` for an unknown property, and a non-scalar value
	 * is treated as absent.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event.
	 * @param string $getter The accessor name (e.g. 'getSchema').
	 *
	 * @return string The scalar value as a string, or '' when unavailable.
	 */
	private function readAccessor(?object $entity, string $getter): string {
		if ($entity === null) {
			return '';
		}

		try {
			$value = $entity->{$getter}();
		} catch (Throwable $e) {
			return '';
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end readAccessor()

	/**
	 * Whether an entity is an instance of the named shillinq schema.
	 *
	 * Unlike {@see schemaSlug()} this does NOT depend on {@see ListenerSlugContract}:
	 * while that gate is closed `schemaSlug()` deliberately returns the entity's
	 * raw schema value, which OpenRegister stamps as a numeric ID — so a caller
	 * comparing it against a slug literal never matches and its listener silently
	 * never fires. A write-path guard cannot be allowed to fail that way, so this
	 * method accepts either form: a raw value that already IS the slug (hand-built
	 * entities and unit-test doubles), or a schema ID resolved through
	 * OpenRegister's SchemaMapper.
	 *
	 * Scoped to shillinq's own register, so a same-slug schema owned by another
	 * app can never trip a shillinq guard.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event.
	 * @param string $expectedSlug The schema slug to match (e.g. 'OrderFulfilment').
	 *
	 * @return bool True when the entity is an instance of that schema.
	 *
	 * @spec openspec/specs/missing-lifecycle-guards/spec.md
	 */
	public function matchesSchema(?object $entity, string $expectedSlug): bool {
		if ($expectedSlug === '') {
			return false;
		}

		$rawSchema = $this->readAccessor(entity: $entity, getter: 'getSchema');
		if ($rawSchema === '') {
			return false;
		}

		// The entity already carries a slug rather than an id.
		if (strcasecmp($rawSchema, $expectedSlug) === 0) {
			return true;
		}

		if ($this->isOwnRegister(entity: $entity) === false) {
			return false;
		}

		return strcasecmp(
			$this->resolveSlug(service: self::SCHEMA_MAPPER, id: $rawSchema),
			$expectedSlug
		) === 0;

	}//end matchesSchema()

	/**
	 * Whether the entity belongs to shillinq's own OpenRegister register.
	 *
	 * This is the guard that keeps a schema-only literal (for example the two
	 * distinct schemas both slugged `automation`) from firing on another app's
	 * objects.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event.
	 *
	 * @return bool True when the entity sits in shillinq's register.
	 *
	 * @spec openspec/specs/missing-lifecycle-guards/spec.md
	 */
	public function isOwnRegister(?object $entity): bool {
		$rawRegister = $this->readAccessor(entity: $entity, getter: 'getRegister');
		if ($rawRegister === '') {
			return false;
		}

		$expected = $this->settingsService->getRegisterSlug();

		// Tolerate an entity that already carries a slug (a hand-built entity in
		// a test, or a future OpenRegister that stops stamping ids).
		if (strcasecmp($rawRegister, $expected) === 0) {
			return true;
		}

		return strcasecmp(
			$this->resolveSlug(service: self::REGISTER_MAPPER, id: $rawRegister),
			$expected
		) === 0;

	}//end isOwnRegister()

	/**
	 * Look an OpenRegister entity's slug up by id through a mapper FQCN.
	 *
	 * @param string $service The mapper FQCN (SchemaMapper or RegisterMapper).
	 * @param string $id The id to resolve.
	 *
	 * @return string The slug, or '' when unresolvable / OpenRegister absent.
	 */
	private function resolveSlug(string $service, string $id): string {
		try {
			$entity = $this->container->get($service)->find($id);
			if (is_object($entity) === true) {
				// `getSlug()` is a MAGIC accessor on OpenRegister's Schema /
				// Register entities (both extend OCP\AppFramework\Db\Entity), so
				// the previous `method_exists($entity, 'getSlug')` guard was
				// false for every entity the mapper returns and this method
				// answered '' for every id it was ever given — the silent
				// reason the slug matching below could never succeed.
				return $this->readAccessor(entity: $entity, getter: 'getSlug');
			}
		} catch (Throwable $e) {
			$this->logger->debug(
				'Shillinq: could not resolve an OpenRegister slug for a listener guard',
				[
					'service' => $service,
					'id' => $id,
					'exception' => $e->getMessage(),
				]
			);
		}//end try

		return '';
	}//end resolveSlug()
}//end class
