<?php

/**
 * Adapter that lets a duck-typed in-memory ObjectService double satisfy the
 * published ADR-084 contract.
 *
 * ## Why this exists
 *
 * Before ADR-083 a service reached OpenRegister through an untyped
 * `Psr\Container\ContainerInterface`, so a test could park any duck-typed
 * double on `$container->method('get')->willReturn($stub)` and the subject
 * would pick it up. ADR-083 removed the container and ADR-084 replaced it with
 * a constructor-injected `OCA\OpenRegister\Contract\ObjectServiceInterface`.
 *
 * The mechanical sweep that adopted the contract kept every container mock in
 * place and injected `$this->createMock(ObjectServiceInterface::class)` — a
 * FRESH, UNCONFIGURED double. The seeded store is still built, still parked on
 * the container, and never consulted again, because nothing asks the container
 * any more. The subject then sees an empty world: `findAll()` answers `[]`,
 * `find()` answers `null`, and `setRegister()` hands back a different mock, so
 * a fluent chain loses the schema. Every read takes its not-found branch.
 *
 * That is why the failures read like product defects — "Purchase order not
 * found", "null is identical to 675.0" — when the code under test is correct
 * and the store simply was not plugged in.
 *
 * The obvious repair, deleting the `objectService:` argument, is forbidden:
 * named parameters are gate-enforced here, and the parameter genuinely exists.
 * The obvious alternative, rewriting each of the ~100 bespoke anonymous doubles
 * to `implements ObjectServiceInterface`, would mean restating a 25-method
 * contract a hundred times. This adapter keeps each store exactly as written
 * and changes only how it is REACHED — the same move the fleet board
 * recommends: "keep the store, change only how it is reached".
 *
 * ## What it does and does not model
 *
 * It forwards the five methods the app's services actually use — setRegister,
 * setSchema, findAll, find, saveObject — to the wrapped double, calling each
 * with a SINGLE positional argument so it fits every variant the suite has
 * written (`saveObject(array $o)`, `saveObject(array $o, string $r, string $s)`
 * and so on). Return values are normalised to the contract's types: an array
 * coming back from a double's `saveObject()`/`find()` is wrapped in an
 * {@see ObjectEntityStub}, an entity is passed through untouched.
 *
 * Anything the wrapped double does not implement, and every contract method the
 * app does not exercise, throws {@see \LogicException} naming the method.
 * Returning an empty value instead would be indistinguishable from a real empty
 * result — which is the exact failure this class exists to undo.
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
 * Contract-conformant wrapper around a duck-typed ObjectService double.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Mirrors the 25-method contract.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) Mirrors the 25-method contract.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DuckObjectServiceAdapter implements ObjectServiceInterface {

	/**
	 * The wrapped duck-typed double.
	 *
	 * @var object
	 */
	private object $inner;

	/**
	 * Constructor.
	 *
	 * @param object $inner The duck-typed in-memory double to delegate to.
	 */
	public function __construct(object $inner) {
		$this->inner = $inner;

	}//end __construct()

	/**
	 * Return the wrapped double, so a test can still assert on its state.
	 *
	 * @return object The wrapped double.
	 */
	public function inner(): object {
		return $this->inner;

	}//end inner()

	/**
	 * Fluent register setter.
	 *
	 * Always returns THIS adapter rather than whatever the inner double hands
	 * back: a double that returns `$this` would otherwise drop the caller out
	 * of the contract-typed chain half way through.
	 *
	 * @param string|int $register Register slug or id.
	 *
	 * @return static
	 */
	public function setRegister(string|int $register): static {
		if (method_exists($this->inner, 'setRegister') === true) {
			$this->inner->setRegister($register);
		}

		return $this;

	}//end setRegister()

	/**
	 * Fluent schema setter.
	 *
	 * @param string|int $schema Schema slug or id.
	 *
	 * @return static
	 */
	public function setSchema(string|int $schema): static {
		if (method_exists($this->inner, 'setSchema') === true) {
			$this->inner->setSchema($schema);
		}

		return $this;

	}//end setSchema()

	/**
	 * Drop the register/schema scope.
	 *
	 * @return void
	 */
	public function clearCurrents(): void {
		if (method_exists($this->inner, 'clearCurrents') === true) {
			$this->inner->clearCurrents();
		}

	}//end clearCurrents()

	/**
	 * Delegate a list query to the wrapped double.
	 *
	 * @param array $config        Filters, limit, offset, sort and search.
	 * @param bool  $_rbac         Apply register RBAC (the double ignores it).
	 * @param bool  $_multitenancy Apply organisation scoping (the double ignores it).
	 *
	 * @return array
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		if (method_exists($this->inner, 'findAll') === false) {
			$this->unsupported(method: 'findAll');
		}

		return $this->inner->findAll($config);

	}//end findAll()

	/**
	 * Delegate a single-object lookup to the wrapped double.
	 *
	 * Falls back to scanning `findAll()` for a matching `id`/`uuid` when the
	 * double models no `find()` of its own, which most of them do not.
	 *
	 * @param int|string      $id            Object id, UUID or slug.
	 * @param ?array          $_extend       Relations to expand (ignored).
	 * @param bool            $files         Include file metadata (ignored).
	 * @param string|int|null $register      Register override (ignored).
	 * @param string|int|null $schema        Schema override, applied when given.
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
		if ($schema !== null) {
			$this->setSchema(schema: $schema);
		}

		if (method_exists($this->inner, 'find') === true) {
			return $this->entity(value: $this->inner->find($id));
		}

		if (method_exists($this->inner, 'findAll') === false) {
			$this->unsupported(method: 'find');
		}

		foreach ($this->inner->findAll([]) as $row) {
			if (is_array($row) === false) {
				continue;
			}

			if ((string) ($row['id'] ?? '') === (string) $id || (string) ($row['uuid'] ?? '') === (string) $id) {
				return new ObjectEntityStub(payload: $row);
			}
		}

		return null;

	}//end find()

	/**
	 * Delegate a write to the wrapped double and normalise the result.
	 *
	 * The payload is passed as the ONLY positional argument, because the
	 * suite's doubles declare `saveObject()` with anywhere between one and
	 * three parameters and every one of them takes the payload first.
	 *
	 * @param array           $object        Object payload.
	 * @param ?array          $extend        Relations to expand (ignored).
	 * @param string|int|null $register      Register override (ignored).
	 * @param string|int|null $schema        Schema override, applied when given.
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
		if (method_exists($this->inner, 'saveObject') === false) {
			$this->unsupported(method: 'saveObject');
		}

		if ($schema !== null) {
			$this->setSchema(schema: $schema);
		}

		$saved = $this->inner->saveObject($object);

		// A double declared `: void`, or one that returns nothing on some
		// branch, still persisted the payload — echo it back rather than
		// handing the caller a null it cannot distinguish from a refusal.
		if ($saved === null) {
			return new ObjectEntityStub(payload: $object);
		}

		return $this->entity(value: $saved) ?? new ObjectEntityStub(payload: $object);

	}//end saveObject()

	/**
	 * Normalise a double's return value to a contract entity.
	 *
	 * @param mixed $value Whatever the double returned.
	 *
	 * @return ?ObjectEntityInterface
	 */
	private function entity(mixed $value): ?ObjectEntityInterface {
		if ($value === null) {
			return null;
		}

		if ($value instanceof ObjectEntityInterface) {
			return $value;
		}

		if (is_array($value) === true) {
			return new ObjectEntityStub(payload: $value);
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$payload = $value->jsonSerialize();
			if (is_array($payload) === true) {
				return new ObjectEntityStub(payload: $payload);
			}
		}

		throw new LogicException(
			'DuckObjectServiceAdapter cannot turn a ' . get_debug_type($value) . ' into an '
			. 'ObjectEntityInterface. The wrapped double returned a shape the contract has no '
			. 'place for; model it explicitly rather than letting it read as an empty result.'
		);

	}//end entity()

	/**
	 * Count rows the given query would return.
	 *
	 * @param array $config Filters, limit, offset, sort and search.
	 *
	 * @return int
	 */
	public function count(array $config = []): int {
		return count($this->findAll(config: $config));

	}//end count()

	/**
	 * Scoped list query, expressed through the modelled methods.
	 *
	 * @param string $registerSlug  The register slug.
	 * @param string $schemaSlug    The schema slug.
	 * @param array  $filters       Equality filters.
	 * @param bool   $_rbac         Apply register RBAC (ignored).
	 * @param bool   $_multitenancy Apply organisation scoping (ignored).
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
	 * Apply a partial update through the modelled methods.
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
		$merged   = $data;
		if ($existing !== null) {
			$merged = array_merge($existing->getObject(), $data);
		}

		$merged['id'] = $objectId;

		return $this->saveObject(object: $merged);

	}//end updateObject()

	/**
	 * Find without audit or read events.
	 *
	 * @param string          $id            Object id, UUID or slug.
	 * @param ?array          $_extend       Relations to expand (ignored).
	 * @param bool            $files         Include file metadata (ignored).
	 * @param string|int|null $register      Register override (ignored).
	 * @param string|int|null $schema        Schema override, applied when given.
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
			throw new RuntimeException('DuckObjectServiceAdapter: no object ' . $id);
		}

		return $found;

	}//end findSilent()

	/**
	 * Run an operation with system privileges — the adapter simply runs it.
	 *
	 * @param callable $operation The operation to run.
	 *
	 * @return mixed
	 */
	public function runAsSystem(callable $operation) {
		return $operation();

	}//end runAsSystem()

	/**
	 * No context object is ever held by the adapter.
	 *
	 * @return ?ObjectEntityInterface
	 */
	public function getObject(): ?ObjectEntityInterface {
		return null;

	}//end getObject()

	/**
	 * Refuse a call this adapter does not model.
	 *
	 * @param string $method The contract method that was called.
	 *
	 * @return never
	 *
	 * @throws LogicException Always.
	 */
	private function unsupported(string $method): never {
		throw new LogicException(
			'DuckObjectServiceAdapter does not model ' . $method . '(), and the wrapped double '
			. 'does not either. Returning an empty value here would be indistinguishable from a '
			. 'real empty result, so the adapter refuses instead. Model the method on the double '
			. 'if the code under test needs it.'
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
	 * Delegate a delete when the double models one, refuse otherwise.
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
		if (method_exists($this->inner, 'deleteObject') === false) {
			$this->unsupported(method: 'deleteObject');
		}

		return (bool) $this->inner->deleteObject($uuid);

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
	 * Delegate a bulk write when the double models one, refuse otherwise.
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
		if (method_exists($this->inner, 'saveObjects') === true) {
			return $this->inner->saveObjects($objects);
		}

		$out = [];
		foreach ($objects as $object) {
			$out[] = $this->saveObject(object: $object, schema: $schema);
		}

		return $out;

	}//end saveObjects()

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
	 * Not modelled.
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
}//end class
