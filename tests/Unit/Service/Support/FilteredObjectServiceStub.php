<?php

/**
 * Filtered in-memory ObjectService stub supporting equality AND `{in: [...]}`
 * filters.
 *
 * `InMemoryObjectServiceStub` (the fleet-wide default double) only supports
 * strict-equality filters — a `['field' => ['in' => [...]]]` filter (the
 * `SpendAnalyticsService.php:183` precedent
 * {@see \OCA\Shillinq\Service\BudgetVsActualsReader} uses for its
 * `BudgetLine` batch read) would compare a row's scalar field against the
 * literal array `['in' => [...]]` and never match, silently returning empty
 * for every query using it. This stub adds `{in: [...]}` support (and
 * otherwise behaves identically, equality on every other filter key) so
 * fixtures exercising an `in`-filtered read return real rows instead of
 * silently empty ones — an empty result and a genuinely-filtered-out result
 * are indistinguishable to a caller, so a double that gets this wrong makes
 * a broken query look like a working one with no matching data.
 *
 * Deliberately minimal: only `setRegister`/`setSchema`/`findAll` are
 * modelled; every other {@see ObjectServiceInterface} method throws, the
 * same "must FAIL rather than answer an indistinguishable empty value"
 * convention `InMemoryObjectServiceStub` follows.
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
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Support;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUser;

/**
 * Minimal in-memory store supporting equality and `{in: [...]}` filters.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Mirrors the 26-method contract.
 */
final class FilteredObjectServiceStub implements ObjectServiceInterface {

	/**
	 * Active schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Constructor.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 */
	public function __construct(private readonly array $data) {
	}//end __construct()

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
	 * Refuse a call this stub does not model.
	 *
	 * @param string $method The contract method that was called.
	 *
	 * @return never
	 *
	 * @throws \LogicException Always.
	 */
	private function unsupported(string $method): never {
		throw new \LogicException('FilteredObjectServiceStub does not model ' . $method . '().');

	}//end unsupported()

	/**
	 * Not modelled.
	 *
	 * @param int|string $id Object id, UUID or slug.
	 * @param ?array $_extend Relations to expand.
	 * @param bool $files Include file metadata.
	 * @param string|int|null $register Register override.
	 * @param string|int|null $schema Schema override.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 * @param bool $_render Render the entity.
	 * @param bool $_audit Write an audit entry.
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
		$this->unsupported(method: 'find');

	}//end find()

	/**
	 * Not modelled.
	 *
	 * @param array $object Object payload.
	 * @param ?array $extend Relations to expand.
	 * @param string|int|null $register Register override.
	 * @param string|int|null $schema Schema override.
	 * @param ?string $uuid Explicit UUID.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 * @param bool $silent Suppress events.
	 * @param bool $_validation Validate against the schema.
	 * @param ?array $uploadedFiles Files uploaded alongside.
	 * @param ?IUser $currentUser Explicit acting user.
	 * @param bool $failIfExists Fail instead of updating.
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
		$this->unsupported(method: 'saveObject');

	}//end saveObject()

	/**
	 * Count rows on the active schema, applying the same filter semantics as
	 * `findAll()`.
	 *
	 * @param array $config Filters, limit, offset, sort and search.
	 *
	 * @return int
	 */
	public function count(array $config = []): int {
		return count($this->findAll(config: $config));

	}//end count()

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
	 * @param string $objectId The object UUID or id.
	 * @param array $data The fields to change.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 *
	 * @return ObjectEntityInterface
	 */
	public function updateObject(
		string $objectId,
		array $data,
		bool $_rbac = true,
		bool $_multitenancy = true
	): ObjectEntityInterface {
		$this->unsupported(method: 'updateObject');

	}//end updateObject()

	/**
	 * Not modelled.
	 *
	 * @param string $objectId Object id, UUID or slug.
	 * @param array $data The partial data to merge.
	 * @param string|int|null $register Register id, UUID or slug.
	 * @param string|int|null $schema Schema id, UUID or slug.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 * @param ?IUser $currentUser Explicit acting user.
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
		$this->unsupported(method: 'patchObject');

	}//end patchObject()

	/**
	 * Not modelled.
	 *
	 * @param string $id Object id, UUID or slug.
	 * @param ?array $_extend Relations to expand.
	 * @param bool $files Include file metadata.
	 * @param string|int|null $register Register override.
	 * @param string|int|null $schema Schema override.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
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
		$this->unsupported(method: 'findSilent');

	}//end findSilent()

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
	 * No context object is ever held by the stub.
	 *
	 * @return ?ObjectEntityInterface
	 */
	public function getObject(): ?ObjectEntityInterface {
		return null;

	}//end getObject()

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
	 * Not modelled.
	 *
	 * @param array      $objects  Rows to append, as plain arrays.
	 * @param string|int $register Register id, uuid or slug.
	 * @param string|int $schema   Schema id, uuid or slug.
	 *
	 * @return int
	 */
	public function appendObjectsRaw(array $objects, string|int $register, string|int $schema): int {
		$this->unsupported(method: 'appendObjectsRaw');

	}//end appendObjectsRaw()

	/**
	 * Not modelled.
	 *
	 * @param string|int $register Register id, uuid or slug.
	 * @param string|int $schema   Schema id, uuid or slug.
	 *
	 * @return int
	 */
	public function purgeExpiredObjectsRaw(string|int $register, string|int $schema): int {
		$this->unsupported(method: 'purgeExpiredObjectsRaw');

	}//end purgeExpiredObjectsRaw()

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
}//end class
