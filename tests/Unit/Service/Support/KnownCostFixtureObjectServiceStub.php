<?php

/**
 * Read/write in-memory ObjectService stub for `budget-known-costs` writer
 * tests.
 *
 * `budget-core-schema`'s two existing doubles each model one half of what
 * {@see \OCA\Shillinq\Service\KnownCostBudgetWriter} needs:
 * `FilteredObjectServiceStub` models `{in: [...]}`-filtered reads but every
 * write throws `unsupported()`; `InMemoryObjectServiceStub` models
 * `saveObject()`/`find()` but only equality `findAll()` filters. The writer
 * needs BOTH in one double (reads scoped by `annualBudgetId: {in: [...]}`,
 * PLUS `saveObject()`/`updateObject()` for the upsert paths), so this class
 * combines them rather than editing either shared double for a need neither
 * of their existing consumers has.
 *
 * ## `updateObject()` REPLACES, deliberately, not merges
 *
 * `InMemoryObjectServiceStub::updateObject()`'s own docblock records that
 * the REAL `ObjectServiceInterface::updateObject()` replaces the stored
 * object with exactly the fields given, and that its own merge-on-update
 * behaviour is the STUB's convenience, not a faithful model. This stub
 * intentionally does NOT repeat that convenience: `updateObject()` here
 * replaces the row outright, so a writer that forgot to merge onto the full
 * already-loaded object before calling `updateObject()` (silently dropping
 * every other field) fails a test here instead of passing one that could
 * not have caught it.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Support;

use LogicException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUser;
use RuntimeException;

/**
 * Minimal in-memory store supporting equality + `{in: [...]}` reads AND
 * replace-semantics writes.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Mirrors the 26-method contract.
 */
final class KnownCostFixtureObjectServiceStub implements ObjectServiceInterface {

	/**
	 * Schema => rows.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $data;

	/**
	 * Active schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Auto-increment id counter.
	 *
	 * @var integer
	 */
	private int $idCounter = 0;

	/**
	 * Constructor.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 */
	public function __construct(array $data = []) {
		$this->data = $data;

	}//end __construct()

	/**
	 * Test helper: every row stored on a schema, regardless of any filter.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function dump(string $schema): array {
		return ($this->data[$schema] ?? []);

	}//end dump()

	/**
	 * Test helper: replace every row stored on a schema outright — standing
	 * in for "an operator edited/deleted this object via the UI/API since
	 * the last run" without reaching into this class's private state via
	 * reflection.
	 *
	 * @param string $schema The schema slug.
	 * @param array<int,array<string,mixed>> $rows The replacement rows.
	 *
	 * @return void
	 */
	public function replace(string $schema, array $rows): void {
		$this->data[$schema] = $rows;

	}//end replace()

	/**
	 * @param string|int $register Register slug or id.
	 *
	 * @return static
	 */
	public function setRegister(string|int $register): static {
		return $this;

	}//end setRegister()

	/**
	 * @param string|int $schema Schema slug or id.
	 *
	 * @return static
	 */
	public function setSchema(string|int $schema): static {
		$this->schema = (string)$schema;
		return $this;

	}//end setSchema()

	/**
	 * @return void
	 */
	public function clearCurrents(): void {
		$this->schema = '';

	}//end clearCurrents()

	/**
	 * Return rows for the active schema, applying equality filters and
	 * `{in: [...]}` filters.
	 *
	 * @param array $config Filters, limit, offset, sort and search.
	 * @param bool $_rbac Apply register RBAC (ignored).
	 * @param bool $_multitenancy Apply organisation scoping (ignored).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$rows = ($this->data[$this->schema] ?? []);
		$filters = ($config['filters'] ?? []);

		return array_values(
			array_filter(
				$rows,
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (is_array($value) === true && array_key_exists('in', $value) === true) {
							if (in_array(($row[$key] ?? null), $value['in'], true) === false) {
								return false;
							}

							continue;
						}

						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);

	}//end findAll()

	/**
	 * Find one row on the active schema by id or uuid.
	 *
	 * @param int|string $id Object id, UUID or slug.
	 * @param ?array $_extend Relations to expand (ignored).
	 * @param bool $files Include file metadata (ignored).
	 * @param string|int|null $register Register override (ignored).
	 * @param string|int|null $schema Schema override, when given.
	 * @param bool $_rbac Apply register RBAC (ignored).
	 * @param bool $_multitenancy Apply organisation scoping (ignored).
	 * @param bool $_render Render the entity (ignored).
	 * @param bool $_audit Write an audit entry (ignored).
	 *
	 * @return ?ObjectEntityInterface
	 */
	public function find(
		int|string $id,
		?array $_extend = [],
		bool $files = false,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_render = true,
		bool $_audit = true
	): ?ObjectEntityInterface {
		$target = $this->schema;
		if ($schema !== null) {
			$target = (string)$schema;
		}

		foreach (($this->data[$target] ?? []) as $row) {
			if ((string)($row['id'] ?? '') === (string)$id || (string)($row['uuid'] ?? '') === (string)$id) {
				return new ObjectEntityStub(payload: $row, schema: $target);
			}
		}

		return null;

	}//end find()

	/**
	 * Save an object; stamps an id when absent, replaces in place otherwise.
	 *
	 * @param array $object Object payload.
	 * @param ?array $extend Relations to expand (ignored).
	 * @param string|int|null $register Register override (ignored).
	 * @param string|int|null $schema Schema override, when given.
	 * @param ?string $uuid Explicit UUID (ignored).
	 * @param bool $_rbac Apply register RBAC (ignored).
	 * @param bool $_multitenancy Apply organisation scoping (ignored).
	 * @param bool $silent Suppress events (ignored).
	 * @param bool $_validation Validate against the schema (ignored).
	 * @param ?array $uploadedFiles Files uploaded alongside (ignored).
	 * @param ?IUser $currentUser Explicit acting user (ignored).
	 * @param bool $failIfExists Fail instead of updating (ignored).
	 *
	 * @return ObjectEntityInterface
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
		?array $uploadedFiles = null,
		?IUser $currentUser = null,
		bool $failIfExists = false
	): ObjectEntityInterface {
		$target = $this->schema;
		if ($schema !== null) {
			$target = (string)$schema;
		}

		if (isset($object['id']) === false || $object['id'] === '') {
			$this->idCounter++;
			$object['id'] = 'obj-' . $this->idCounter;
			$this->data[$target][] = $object;

			return new ObjectEntityStub(payload: $object, schema: $target);
		}

		foreach (($this->data[$target] ?? []) as $index => $row) {
			if (($row['id'] ?? null) === $object['id']) {
				$this->data[$target][$index] = $object;

				return new ObjectEntityStub(payload: $object, schema: $target);
			}
		}

		$this->data[$target][] = $object;

		return new ObjectEntityStub(payload: $object, schema: $target);

	}//end saveObject()

	/**
	 * REPLACE the stored object at `$objectId` with exactly `$data` (plus
	 * the id) — the real `ObjectServiceInterface::updateObject()` contract,
	 * deliberately not merged (see class docblock).
	 *
	 * @param string $objectId The object UUID or id.
	 * @param array $data The full replacement payload.
	 * @param bool $_rbac Apply register RBAC (ignored).
	 * @param bool $_multitenancy Apply organisation scoping (ignored).
	 *
	 * @return ObjectEntityInterface
	 */
	public function updateObject(
		string $objectId,
		array $data,
		bool $_rbac = true,
		bool $_multitenancy = true
	): ObjectEntityInterface {
		$data['id'] = $objectId;

		foreach (($this->data[$this->schema] ?? []) as $index => $row) {
			if ((string)($row['id'] ?? '') === $objectId) {
				$this->data[$this->schema][$index] = $data;

				return new ObjectEntityStub(payload: $data, schema: $this->schema);
			}
		}

		$this->data[$this->schema][] = $data;

		return new ObjectEntityStub(payload: $data, schema: $this->schema);

	}//end updateObject()

	/**
	 * MERGE `$data` onto the stored object at `$objectId`, preserving every
	 * field `$data` omits — the real contract's PATCH counterpart to
	 * {@see updateObject()}'s REPLACE semantics (see class docblock).
	 *
	 * Follows the RFC-7386-shaped merge rules the real
	 * `ObjectServiceInterface::patchObject()` documents: a key present with a
	 * non-null value overwrites the stored value; a key ABSENT from `$data`
	 * leaves the stored value untouched; a key present with an explicit
	 * `null` clears the stored value; nested associative arrays merge
	 * recursively; lists (JSON arrays) are replaced wholesale.
	 *
	 * @param string $objectId The object UUID or id.
	 * @param array $data The partial data to merge. Omitted keys are preserved.
	 * @param string|int|null $register Register override (ignored).
	 * @param string|int|null $schema Schema override, when given.
	 * @param bool $_rbac Apply register RBAC (ignored).
	 * @param bool $_multitenancy Apply organisation scoping (ignored).
	 * @param ?IUser $currentUser Explicit acting user (ignored).
	 *
	 * @return ObjectEntityInterface
	 */
	public function patchObject(
		string $objectId,
		array $data,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		?IUser $currentUser = null
	): ObjectEntityInterface {
		$target = $this->schema;
		if ($schema !== null) {
			$target = (string)$schema;
		}

		$existing = [];
		foreach (($this->data[$target] ?? []) as $row) {
			if ((string)($row['id'] ?? '') === $objectId) {
				$existing = $row;
				break;
			}
		}

		$merged = self::mergeRecursive(base: $existing, patch: $data);
		$merged['id'] = $objectId;

		foreach (($this->data[$target] ?? []) as $index => $row) {
			if ((string)($row['id'] ?? '') === $objectId) {
				$this->data[$target][$index] = $merged;

				return new ObjectEntityStub(payload: $merged, schema: $target);
			}
		}

		$this->data[$target][] = $merged;

		return new ObjectEntityStub(payload: $merged, schema: $target);

	}//end patchObject()

	/**
	 * RFC-7386-shaped recursive merge used by {@see patchObject()}: a key
	 * absent from `$patch` is preserved; a key present with an explicit
	 * `null` clears the stored value; nested associative arrays merge
	 * recursively; lists (JSON arrays) are replaced wholesale rather than
	 * element-merged.
	 *
	 * @param array<string,mixed> $base The stored row.
	 * @param array<string,mixed> $patch The partial payload to merge on top.
	 *
	 * @return array<string,mixed>
	 */
	private static function mergeRecursive(array $base, array $patch): array {
		foreach ($patch as $key => $value) {
			if ($value === null) {
				unset($base[$key]);
				continue;
			}

			if (is_array($value) === true && array_is_list($value) === false
				&& isset($base[$key]) === true && is_array($base[$key]) === true && array_is_list($base[$key]) === false
			) {
				$base[$key] = self::mergeRecursive(base: $base[$key], patch: $value);
				continue;
			}

			$base[$key] = $value;
		}

		return $base;

	}//end mergeRecursive()

	/**
	 * Count rows on the active schema, applying the same filter semantics as `findAll()`.
	 *
	 * @param array $config Filters, limit, offset, sort and search.
	 *
	 * @return int
	 */
	public function count(array $config = []): int {
		return count($this->findAll(config: $config));

	}//end count()

	/**
	 * Refuse a call this stub does not model.
	 *
	 * @param string $method The contract method that was called.
	 *
	 * @return never
	 *
	 * @throws LogicException Always.
	 */
	private function unsupported(string $method): never {
		throw new LogicException('KnownCostFixtureObjectServiceStub does not model ' . $method . '().');

	}//end unsupported()

	/**
	 * Not modelled.
	 *
	 * @param string $registerSlug The register slug.
	 * @param string $schemaSlug The schema slug.
	 * @param array $filters Equality filters.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 *
	 * @return array|int
	 */
	public function searchObjectsBySlug(
		string $registerSlug,
		string $schemaSlug,
		array $filters = [],
		bool $_rbac = true,
		bool $_multitenancy = true
	): array|int {
		$this->unsupported(method: 'searchObjectsBySlug');

	}//end searchObjectsBySlug()

	/**
	 * Not modelled.
	 *
	 * @param array $query The search query.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 * @param ?array $ids Restrict to these ids.
	 * @param ?string $uses Restrict to objects used by this one.
	 * @param ?array $views Restrict to these views.
	 *
	 * @return array|int
	 */
	public function searchObjects(
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		?array $ids = null,
		?string $uses = null,
		?array $views = null
	): array|int {
		$this->unsupported(method: 'searchObjects');

	}//end searchObjects()

	/**
	 * Not modelled.
	 *
	 * @param string $uuid The object UUID.
	 * @param string|int|null $register Register id, UUID or slug.
	 * @param string|int|null $schema Schema id, UUID or slug.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 * @param bool $_retentionSweep Run as part of a retention sweep.
	 * @param ?IUser $currentUser Explicit acting user.
	 * @param bool $permanent Delete permanently.
	 *
	 * @return bool
	 */
	public function deleteObject(
		string $uuid,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_retentionSweep = false,
		?IUser $currentUser = null,
		bool $permanent = false
	): bool {
		$this->unsupported(method: 'deleteObject');

	}//end deleteObject()

	/**
	 * Not modelled.
	 *
	 * @param array $query The search query.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 * @param bool $deleted Include soft-deleted objects.
	 * @param ?array $ids Restrict to these ids.
	 * @param ?string $uses Restrict to objects used by this one.
	 * @param ?array $views Restrict to these views.
	 *
	 * @return array
	 */
	public function searchObjectsPaginated(
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $deleted = false,
		?array $ids = null,
		?string $uses = null,
		?array $views = null
	): array {
		$this->unsupported(method: 'searchObjectsPaginated');

	}//end searchObjectsPaginated()

	/**
	 * Not modelled.
	 *
	 * @param array $requestParams Raw request parameters.
	 * @param int|string|array|null $register Register id, UUID or slug.
	 * @param int|string|array|null $schema Schema id, UUID or slug.
	 * @param ?array $ids Restrict to these ids.
	 *
	 * @return array
	 */
	public function buildSearchQuery(
		array $requestParams,
		int|string|array|null $register = null,
		int|string|array|null $schema = null,
		?array $ids = null
	): array {
		$this->unsupported(method: 'buildSearchQuery');

	}//end buildSearchQuery()

	/**
	 * Not modelled.
	 *
	 * @param array $objects The objects to store.
	 * @param string|int|null $register Register id, UUID or slug.
	 * @param string|int|null $schema Schema id, UUID or slug.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 * @param bool $validation Validate against the schema.
	 * @param bool $events Emit events for each object.
	 * @param bool $deduplicateIds Drop duplicate ids.
	 * @param bool $enrich Enrich each object.
	 * @param bool $_audit Write an audit-trail entry.
	 *
	 * @return array
	 */
	public function saveObjects(
		array $objects,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $validation = false,
		bool $events = false,
		bool $deduplicateIds = true,
		bool $enrich = true,
		bool $_audit = true
	): array {
		$this->unsupported(method: 'saveObjects');

	}//end saveObjects()

	/**
	 * Run an operation with system privileges — the stub simply runs it.
	 *
	 * @param callable $operation The operation to run.
	 *
	 * @return mixed
	 */
	public function runAsSystem(callable $operation) {
		return $operation();

	}//end runAsSystem()

	/**
	 * Not modelled.
	 *
	 * @param string|int $identifier The object id or UUID.
	 * @param bool $advisory Take an advisory lock.
	 *
	 * @return bool
	 */
	public function unlockObject(string|int $identifier, bool $advisory = false): bool {
		$this->unsupported(method: 'unlockObject');

	}//end unlockObject()

	/**
	 * Not modelled.
	 *
	 * @param string $identifier The object id or UUID.
	 * @param ?string $process A label for the holding process.
	 * @param ?int $duration Lock duration in seconds.
	 * @param bool $advisory Take an advisory lock.
	 *
	 * @return array
	 */
	public function lockObject(
		string $identifier,
		?string $process = null,
		?int $duration = null,
		bool $advisory = false
	): array {
		$this->unsupported(method: 'lockObject');

	}//end lockObject()

	/**
	 * Not modelled.
	 *
	 * @param array $uuids The object UUIDs.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 *
	 * @return array
	 */
	public function deleteObjects(array $uuids = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$this->unsupported(method: 'deleteObjects');

	}//end deleteObjects()

	/**
	 * Not modelled.
	 *
	 * @param string $uuid The object UUID.
	 * @param array $filters Equality filters.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 *
	 * @return array
	 */
	public function getLogs(string $uuid, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$this->unsupported(method: 'getLogs');

	}//end getLogs()

	/**
	 * Not modelled.
	 *
	 * @param string $objectId The object UUID.
	 * @param array $query The search query.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 *
	 * @return array
	 */
	public function getObjectUses(
		string $objectId,
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true
	): array {
		$this->unsupported(method: 'getObjectUses');

	}//end getObjectUses()

	/**
	 * Not modelled.
	 *
	 * @param string $objectId The object UUID.
	 * @param array $query The search query.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 *
	 * @return array
	 */
	public function getObjectUsedBy(
		string $objectId,
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true
	): array {
		$this->unsupported(method: 'getObjectUsedBy');

	}//end getObjectUsedBy()

	/**
	 * Not modelled.
	 *
	 * @param string $search The term to match relations against.
	 * @param bool $partialMatch Match relations partially.
	 *
	 * @return array
	 */
	public function findByRelations(string $search, bool $partialMatch = true): array {
		$this->unsupported(method: 'findByRelations');

	}//end findByRelations()

	/**
	 * Find without audit or read events — delegates to {@see find()}.
	 *
	 * @param string $id Object id, UUID or slug.
	 * @param ?array $_extend Relations to expand (ignored).
	 * @param bool $files Include file metadata (ignored).
	 * @param string|int|null $register Register override (ignored).
	 * @param string|int|null $schema Schema override, when given.
	 * @param bool $_rbac Apply register RBAC (ignored).
	 * @param bool $_multitenancy Apply organisation scoping (ignored).
	 *
	 * @return ObjectEntityInterface
	 */
	public function findSilent(
		string $id,
		?array $_extend = [],
		bool $files = false,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true
	): ObjectEntityInterface {
		$found = $this->find(id: $id, schema: $schema);
		if ($found === null) {
			throw new RuntimeException('KnownCostFixtureObjectServiceStub: no object ' . $id);
		}

		return $found;

	}//end findSilent()

	/**
	 * Not modelled.
	 *
	 * @param array $query The search query.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 * @param ?array $ids Restrict to these ids.
	 * @param ?string $uses Restrict to objects used by this one.
	 *
	 * @return int
	 */
	public function countSearchObjects(
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		?array $ids = null,
		?string $uses = null
	): int {
		$this->unsupported(method: 'countSearchObjects');

	}//end countSearchObjects()

	/**
	 * No context object is ever held by the stub.
	 *
	 * @return ?ObjectEntityInterface
	 */
	public function getObject(): ?ObjectEntityInterface {
		return null;

	}//end getObject()
}//end class
