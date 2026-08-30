<?php

/**
 * In-memory ObjectService stub for procurement-governance service tests.
 *
 * Honours equality filters on findAll and stamps / updates ids on saveObject,
 * mirroring the fluent OpenRegister ObjectService surface the services consume
 * (setRegister -> setSchema -> findAll / saveObject / find).
 *
 * ## Why it implements the contract rather than quacking like it
 *
 * Before ADR-084 this was a duck-typed class handed to production through an
 * untyped `ContainerInterface`. Production now type-hints
 * `OCA\OpenRegister\Contract\ObjectServiceInterface`, so a duck cannot be passed
 * at all — and the usual workaround, `createMock(ObjectServiceInterface::class)`
 * with nothing configured, is worse than a compile error: `setRegister()` hands
 * back a fresh mock, `findAll()` answers `[]` and `find()` answers `null`, so
 * every test reads "no such record" and every guard reads "nothing to object
 * to". A whole suite can go green, or red for the wrong reason, without the
 * store ever being consulted.
 *
 * Declaring `implements ObjectServiceInterface` makes PHP itself the check: if
 * a signature moves upstream this file stops declaring and the suite says so,
 * instead of continuing to answer the old shape.
 *
 * Methods this app does not exercise throw {@see \LogicException} by name. An
 * unstubbed call must FAIL rather than return an empty value that a caller
 * cannot distinguish from a real empty result.
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
 * @spec openspec/specs/procurement-governance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Support;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUser;
use RuntimeException;

/**
 * Minimal in-memory ObjectService test double.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Mirrors the 25-method contract.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) Mirrors the 25-method contract.
 */
final class InMemoryObjectServiceStub implements ObjectServiceInterface {

	/**
	 * Schema => rows.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $data;

	/**
	 * Captured saves.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $saved = [];

	/**
	 * Active register.
	 *
	 * @var string
	 */
	private string $register = '';

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
	 * Whether `findAll()` answers ObjectEntityInterface instances (what the
	 * real engine returns) instead of plain arrays.
	 *
	 * @var boolean
	 */
	private bool $findAllRendersEntities = false;

	/**
	 * Constructor.
	 *
	 * ## `$findAllRendersEntities` — modelling what the engine really returns
	 *
	 * Real OpenRegister's `ObjectService::findAll()` ends in
	 * `RenderObject::renderEntities()`, declared `@psalm-return
	 * list<ObjectEntity>` — every row is an OBJECT. `ObjectEntity` does NOT
	 * implement `ArrayAccess`, so `$row['id']` against a real row is
	 * `Error: Cannot use object of type ...\ObjectEntity as array`, and
	 * `array_merge($row, [...])` is a `TypeError`.
	 *
	 * This double has always answered plain arrays, so a consumer that
	 * subscripts a row goes green locally and fatals the moment it meets the
	 * deployed engine. `BudgetScenarioDefaultPromoter::promote()` shipped
	 * exactly that and returned HTTP 500 on every demotion, under five green
	 * unit tests (shillinq budget-scenarios, CI run 32462209787).
	 *
	 * ⚠️ Opt-in rather than the default, for the same reason
	 * {@see OpenRegisterFaithfulObjectService} is opt-in: the app carries many
	 * live `findAll()` consumers written against the array shape, and flipping
	 * the default red-lines them all at once — repairs that are product
	 * decisions, not test-side ones. Pass `true` wherever a test must be ABLE
	 * to see this defect; a test whose double cannot fail here proves nothing
	 * about the row handling it exercises.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data                   Seed rows.
	 * @param array<int,array<string,mixed>>|null          $saveSink               Optional caller-owned
	 *                                                                             array to bind {@see $saved}
	 *                                                                             to, for tests that assert on
	 *                                                                             a local `$saved` variable.
	 * @param bool                                         $findAllRendersEntities Answer `findAll()` with
	 *                                                                             ObjectEntityInterface rows,
	 *                                                                             as the real engine does.
	 */
	public function __construct(
		array $data = [],
		?array &$saveSink = null,
		bool $findAllRendersEntities = false
	) {
		$this->data = $data;
		$this->findAllRendersEntities = $findAllRendersEntities;
		if ($saveSink !== null) {
			$this->saved = &$saveSink;
		}

	}//end __construct()

	/**
	 * Fluent register setter.
	 *
	 * @param string|int $register Register slug.
	 *
	 * @return static
	 */
	public function setRegister(string|int $register): static {
		$this->register = (string)$register;
		return $this;

	}//end setRegister()

	/**
	 * Fluent schema setter.
	 *
	 * @param string|int $schema Schema slug.
	 *
	 * @return static
	 */
	public function setSchema(string|int $schema): static {
		$this->schema = (string)$schema;
		return $this;

	}//end setSchema()

	/**
	 * Drop the register/schema scope.
	 *
	 * @return void
	 */
	public function clearCurrents(): void {
		$this->register = '';
		$this->schema = '';

	}//end clearCurrents()

	/**
	 * Return rows for the active schema, applying equality filters.
	 *
	 * Answers plain arrays by default; answers ObjectEntityInterface rows —
	 * the shape the real engine returns — when the double was constructed
	 * with `findAllRendersEntities: true`.
	 *
	 * @param array $config        Filters, limit, offset, sort and search.
	 * @param bool  $_rbac         Apply register RBAC (ignored by the stub).
	 * @param bool  $_multitenancy Apply organisation scoping (ignored by the stub).
	 *
	 * @return array<int,array<string,mixed>|ObjectEntityInterface>
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$rows = ($this->data[$this->schema] ?? []);
		$filters = ($config['filters'] ?? []);

		$matched = array_values(
			array_filter(
				$rows,
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);

		if ($this->findAllRendersEntities === false) {
			return $matched;
		}

		return array_map(
			fn (array $row): ObjectEntityInterface => new ObjectEntityStub(
				payload: $row,
				register: $this->register,
				schema: $this->schema
			),
			$matched
		);

	}//end findAll()

	/**
	 * Find one row on the active schema by id or uuid.
	 *
	 * @param int|string      $id            Object id, UUID or slug.
	 * @param ?array          $_extend       Relations to expand (ignored).
	 * @param bool            $files         Include file metadata (ignored).
	 * @param string|int|null $register      Register override (ignored).
	 * @param string|int|null $schema        Schema override, when given.
	 * @param bool            $_rbac         Apply register RBAC (ignored).
	 * @param bool            $_multitenancy Apply organisation scoping (ignored).
	 * @param bool            $_render       Render the entity (ignored).
	 * @param bool            $_audit        Write an audit entry (ignored).
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
				return new ObjectEntityStub(payload: $row, register: $this->register, schema: $target);
			}
		}

		return null;

	}//end find()

	/**
	 * Capture a saved object; stamp an id when absent, update in place otherwise.
	 *
	 * @param array           $object        Object payload.
	 * @param ?array          $extend        Relations to expand (ignored).
	 * @param string|int|null $register      Register override (ignored).
	 * @param string|int|null $schema        Schema override, when given.
	 * @param ?string         $uuid          Explicit UUID (ignored).
	 * @param bool            $_rbac         Apply register RBAC (ignored).
	 * @param bool            $_multitenancy Apply organisation scoping (ignored).
	 * @param bool            $silent        Suppress events (ignored).
	 * @param bool            $_validation   Validate against the schema (ignored).
	 * @param ?array          $uploadedFiles Files uploaded alongside (ignored).
	 * @param ?IUser          $currentUser   Explicit acting user (ignored).
	 * @param bool            $failIfExists  Fail instead of updating (ignored).
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
			$this->saved[] = ['schema' => $target, 'object' => $object];

			return new ObjectEntityStub(payload: $object, register: $this->register, schema: $target);
		}

		foreach (($this->data[$target] ?? []) as $index => $row) {
			if (($row['id'] ?? null) === $object['id']) {
				$this->data[$target][$index] = $object;
				$this->saved[] = ['schema' => $target, 'object' => $object];

				return new ObjectEntityStub(payload: $object, register: $this->register, schema: $target);
			}
		}

		$this->data[$target][] = $object;
		$this->saved[] = ['schema' => $target, 'object' => $object];

		return new ObjectEntityStub(payload: $object, register: $this->register, schema: $target);

	}//end saveObject()

	/**
	 * Count rows on the active schema.
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
	 * @throws \LogicException Always.
	 */
	private function unsupported(string $method): never {
		throw new \LogicException(
			'InMemoryObjectServiceStub does not model ' . $method . '(). Returning an empty '
			. 'value here would be indistinguishable from a real empty result, so the stub '
			. 'refuses instead. Model the method if the code under test needs it.'
		);

	}//end unsupported()

	/**
	 * Not modelled.
	 *
	 * @param array   $query         The search query.
	 * @param bool    $_rbac         Apply register RBAC.
	 * @param bool    $_multitenancy Apply organisation scoping.
	 * @param ?array  $ids           Restrict to these ids.
	 * @param ?string $uses          Restrict to objects used by this one.
	 * @param ?array  $views         Restrict to these views.
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
	 * @param string          $uuid            The object UUID.
	 * @param string|int|null $register        Register id, UUID or slug.
	 * @param string|int|null $schema          Schema id, UUID or slug.
	 * @param bool            $_rbac           Apply register RBAC.
	 * @param bool            $_multitenancy   Apply organisation scoping.
	 * @param bool            $_retentionSweep Run as part of a retention sweep.
	 * @param ?IUser          $currentUser     Explicit acting user.
	 * @param bool            $permanent       Delete permanently.
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
	 * @param array   $query         The search query.
	 * @param bool    $_rbac         Apply register RBAC.
	 * @param bool    $_multitenancy Apply organisation scoping.
	 * @param bool    $deleted       Include soft-deleted objects.
	 * @param ?array  $ids           Restrict to these ids.
	 * @param ?string $uses          Restrict to objects used by this one.
	 * @param ?array  $views         Restrict to these views.
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
	 * @param string $registerSlug  The register slug.
	 * @param string $schemaSlug    The schema slug.
	 * @param array  $filters       Equality filters.
	 * @param bool   $_rbac         Apply register RBAC.
	 * @param bool   $_multitenancy Apply organisation scoping.
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
		return $this->setRegister(register: $registerSlug)
			->setSchema(schema: $schemaSlug)
			->findAll(config: ['filters' => $filters]);

	}//end searchObjectsBySlug()

	/**
	 * Not modelled.
	 *
	 * @param array                 $requestParams Raw request parameters.
	 * @param int|string|array|null $register      Register id, UUID or slug.
	 * @param int|string|array|null $schema        Schema id, UUID or slug.
	 * @param ?array                $ids           Restrict to these ids.
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
	 * @param array           $objects        The objects to store.
	 * @param string|int|null $register       Register id, UUID or slug.
	 * @param string|int|null $schema         Schema id, UUID or slug.
	 * @param bool            $_rbac          Apply register RBAC.
	 * @param bool            $_multitenancy  Apply organisation scoping.
	 * @param bool            $validation     Validate against the schema.
	 * @param bool            $events         Emit events for each object.
	 * @param bool            $deduplicateIds Drop duplicate ids.
	 * @param bool            $enrich         Enrich each object.
	 * @param bool            $_audit         Write an audit-trail entry.
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
	 * @param bool       $advisory   Take an advisory lock.
	 *
	 * @return bool
	 */
	public function unlockObject(string|int $identifier, bool $advisory = false): bool {
		$this->unsupported(method: 'unlockObject');

	}//end unlockObject()

	/**
	 * Not modelled.
	 *
	 * @param string  $identifier The object id or UUID.
	 * @param ?string $process    A label for the holding process.
	 * @param ?int    $duration   Lock duration in seconds.
	 * @param bool    $advisory   Take an advisory lock.
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
	 * @param array $uuids         The object UUIDs.
	 * @param bool  $_rbac         Apply register RBAC.
	 * @param bool  $_multitenancy Apply organisation scoping.
	 *
	 * @return array
	 */
	public function deleteObjects(array $uuids = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$this->unsupported(method: 'deleteObjects');

	}//end deleteObjects()

	/**
	 * Not modelled.
	 *
	 * @param string $uuid          The object UUID.
	 * @param array  $filters       Equality filters.
	 * @param bool   $_rbac         Apply register RBAC.
	 * @param bool   $_multitenancy Apply organisation scoping.
	 *
	 * @return array
	 */
	public function getLogs(string $uuid, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$this->unsupported(method: 'getLogs');

	}//end getLogs()

	/**
	 * Apply a partial update to a stored object.
	 *
	 * @param string $objectId      The object UUID or id.
	 * @param array  $data          The fields to change.
	 * @param bool   $_rbac         Apply register RBAC (ignored).
	 * @param bool   $_multitenancy Apply organisation scoping (ignored).
	 *
	 * @return ObjectEntityInterface
	 */
	public function updateObject(
		string $objectId,
		array $data,
		bool $_rbac = true,
		bool $_multitenancy = true
	): ObjectEntityInterface {
		$existing = $this->find(id: $objectId);
		$merged = $data;
		if ($existing !== null) {
			$merged = array_merge($existing->getObject(), $data);
		}

		$merged['id'] = $objectId;

		return $this->saveObject(object: $merged);

	}//end updateObject()

	/**
	 * Merge a partial update onto a stored object.
	 *
	 * Added to the published contract in hydra-gates v1.8.1. v1.8.0 declared the
	 * interface without it, so this stub satisfied the contract by accident;
	 * taking v1.8.1 turns the omission into a load-time fatal that kills the
	 * whole suite before a test runs.
	 *
	 * ⚠️ The REAL `updateObject()` REPLACES — the merge above is this stub's own
	 * behaviour, not the service's, and a test that relies on it is asserting
	 * something production does not do. Left as it is here deliberately: fixing
	 * it is a behaviour change to existing tests and does not belong in a
	 * dependency bump. `patchObject()` is the method that genuinely merges, so
	 * it is modelled with the same read-merge-write this file already performs.
	 *
	 * @param string          $objectId      The object UUID or id.
	 * @param array           $data          The fields to merge.
	 * @param string|int|null $register      Register id, UUID or slug (ignored).
	 * @param string|int|null $schema        Schema id, UUID or slug (ignored).
	 * @param bool            $_rbac         Apply register RBAC (ignored).
	 * @param bool            $_multitenancy Apply organisation scoping (ignored).
	 * @param ?IUser          $currentUser   Acting user (ignored).
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
		$existing = $this->find(id: $objectId);
		$merged   = $data;
		if ($existing !== null) {
			$merged = array_merge($existing->getObject(), $data);
		}

		$merged['id'] = $objectId;

		return $this->saveObject(object: $merged);

	}//end patchObject()

	/**
	 * Not modelled.
	 *
	 * @param string $objectId      The object UUID.
	 * @param array  $query         The search query.
	 * @param bool   $_rbac         Apply register RBAC.
	 * @param bool   $_multitenancy Apply organisation scoping.
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
	 * @param string $objectId      The object UUID.
	 * @param array  $query         The search query.
	 * @param bool   $_rbac         Apply register RBAC.
	 * @param bool   $_multitenancy Apply organisation scoping.
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
	 * @param string $search       The term to match relations against.
	 * @param bool   $partialMatch Match relations partially.
	 *
	 * @return array
	 */
	public function findByRelations(string $search, bool $partialMatch = true): array {
		$this->unsupported(method: 'findByRelations');

	}//end findByRelations()

	/**
	 * Find without audit or read events.
	 *
	 * @param string          $id            Object id, UUID or slug.
	 * @param ?array          $_extend       Relations to expand (ignored).
	 * @param bool            $files         Include file metadata (ignored).
	 * @param string|int|null $register      Register override (ignored).
	 * @param string|int|null $schema        Schema override, when given.
	 * @param bool            $_rbac         Apply register RBAC (ignored).
	 * @param bool            $_multitenancy Apply organisation scoping (ignored).
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
			throw new RuntimeException('InMemoryObjectServiceStub: no object ' . $id);
		}

		return $found;

	}//end findSilent()

	/**
	 * Count the objects a search query would return.
	 *
	 * @param array   $query         The search query.
	 * @param bool    $_rbac         Apply register RBAC.
	 * @param bool    $_multitenancy Apply organisation scoping.
	 * @param ?array  $ids           Restrict to these ids.
	 * @param ?string $uses          Restrict to objects used by this one.
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
