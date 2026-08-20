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
	 * Active register, as set through the fluent setter.
	 *
	 * Production reaches OpenRegister as
	 * `->setRegister($r)->setSchema($s)->saveObject($data)`, so the scope
	 * arrives through the SETTERS, not as call arguments. Doubles, however,
	 * frequently declare `saveObject(array $o, string $register, string $schema)`
	 * and record what they were handed. The adapter therefore has to remember
	 * the scope and hand it on, or such a double records `''` where production
	 * sent `'shillinq'` — the adapter lying about the thing it stands in for.
	 *
	 * @var string
	 */
	private string $register = '';

	/**
	 * Active schema, as set through the fluent setter.
	 *
	 * @var string
	 */
	private string $schema = '';

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
		$this->register = (string) $register;
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
		$this->schema = (string) $schema;
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
		return $this->invokeInner(
			method: 'findAll',
			primary: $config,
			named: [
				'register'      => $this->register,
				'schema'        => $this->schema,
				'_rbac'         => $_rbac,
				'_multitenancy' => $_multitenancy,
			]
		);

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
		// The scope can arrive EITHER through the fluent setters or as named
		// arguments on the call itself -- BadoControleprotocolService does the
		// latter, `find(id: …, register: …, schema: …)`. Honouring only one of
		// the two routes makes the double record '' for a register production
		// really did send.
		if ($register !== null) {
			$this->setRegister(register: $register);
		}

		if ($schema !== null) {
			$this->setSchema(schema: $schema);
		}

		if (method_exists($this->inner, 'find') === true) {
			return $this->entity(
				value: $this->invokeInner(
					method: 'find',
					primary: $id,
					named: [
						'_extend'       => $_extend,
						'extend'        => $_extend,
						'files'         => $files,
						'register'      => $this->register,
						'schema'        => $this->schema,
						'_rbac'         => $_rbac,
						'_multitenancy' => $_multitenancy,
						'_render'       => $_render,
						'_audit'        => $_audit,
					]
				)
			);
		}

		if (method_exists($this->inner, 'findAll') === false) {
			$this->unsupported(method: 'find');
		}

		foreach ($this->findAll() as $row) {
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
		if ($schema !== null) {
			$this->setSchema(schema: $schema);
		}

		if ($register !== null) {
			$this->setRegister(register: $register);
		}

		$saved = $this->invokeInner(
			method: 'saveObject',
			primary: $object,
			named: [
				'register'       => $this->register,
				'schema'         => $this->schema,
				'uuid'           => $uuid,
				'extend'         => $extend,
				'_extend'        => $extend,
				'_rbac'          => $_rbac,
				'_multitenancy'  => $_multitenancy,
				'silent'         => $silent,
				'_validation'    => $_validation,
				'uploadedFiles'  => $uploadedFiles,
				'currentUser'    => $currentUser,
				'failIfExists'   => $failIfExists,
			]
		);

		// A double declared `: void`, or one that returns nothing on some
		// branch, still persisted the payload — echo it back rather than
		// handing the caller a null it cannot distinguish from a refusal.
		if ($saved === null) {
			return new ObjectEntityStub(payload: $object);
		}

		return $this->entity(value: $saved) ?? new ObjectEntityStub(payload: $object);

	}//end saveObject()

	/**
	 * Call a method on the wrapped double, supplying the arguments IT declares.
	 *
	 * The suite's doubles are not uniform. `saveObject()` alone appears as
	 * `(array $object)`, `(array $o, string $register = '', string $schema = '')`,
	 * `(array $o, string $register, string $schema)` — required, no defaults —
	 * and `(array $o, string $r, string $s, bool $_rbac, bool $_multitenancy,
	 * mixed $currentUser)`. A fixed positional call cannot serve all of those.
	 *
	 * So: the FIRST parameter is always the primary value (payload, id or
	 * config) whatever it is named — doubles spell it `$object`, `$data`,
	 * `$row`, `$id`, `$params` — and every later parameter is matched BY NAME
	 * against what the adapter knows, falling back to the parameter's own
	 * default.
	 *
	 * 🔴 Why this matters beyond tidiness: a required positional `$register`
	 * that the adapter fails to supply raises `ArgumentCountError`, and
	 * production listeners and repair steps `catch (\Throwable)`. The error is
	 * swallowed and surfaces as "0 rows written" — indistinguishable from a
	 * genuinely empty result. A defaulted `$register = ''` is worse still: no
	 * error at all, the row is filed under the empty-string key, and later
	 * reads of the real schema come back empty.
	 *
	 * @param string $method  The method to call on the double.
	 * @param mixed  $primary The first argument, whatever the double calls it.
	 * @param array  $named   Values the adapter can supply, keyed by parameter name.
	 *
	 * @return mixed Whatever the double returned.
	 */
	private function invokeInner(string $method, mixed $primary, array $named = []): mixed {
		if (method_exists($this->inner, $method) === false) {
			$this->unsupported(method: $method);
		}

		try {
			$reflected = new \ReflectionMethod($this->inner, $method);
		} catch (\ReflectionException) {
			// A double reaching the method through __call cannot be reflected;
			// fall back to the single-argument form, which is what every such
			// double modelled before this adapter existed.
			return $this->inner->$method($primary);
		}

		$args = [];
		foreach ($reflected->getParameters() as $index => $parameter) {
			if ($index === 0) {
				$args[] = $primary;
				continue;
			}

			if ($parameter->isVariadic() === true) {
				break;
			}

			$name = $parameter->getName();
			if (array_key_exists($name, $named) === true) {
				$args[] = $named[$name];
				continue;
			}

			if ($parameter->isDefaultValueAvailable() === true) {
				$args[] = $parameter->getDefaultValue();
				continue;
			}

			if ($parameter->allowsNull() === true) {
				$args[] = null;
				continue;
			}

			throw new LogicException(
				sprintf(
					'DuckObjectServiceAdapter cannot supply required parameter $%s of %s::%s(). '
					. 'Give it a default on the double, or rename it to one the adapter knows '
					. '(%s). Guessing a value here would file the row under the wrong key and '
					. 'read as an empty result later.',
					$name,
					get_debug_type($this->inner),
					$method,
					implode(', ', array_keys($named))
				)
			);
		}

		return $reflected->invokeArgs($this->inner, $args);

	}//end invokeInner()

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
		// Delegate to a double that models updateObject() directly, the way
		// deleteObject()/saveObjects() already do. Without this arm the
		// synthesised find()+saveObject() path below asks such a double for a
		// saveObject() it never declared, and the adapter refuses -- which
		// reads as "the update was never persisted".
		if (method_exists($this->inner, 'updateObject') === true) {
			return $this->entity(
				value: $this->invokeInner(
					method: 'updateObject',
					primary: $objectId,
					named: [
						'data'          => $data,
						'object'        => $data,
						'register'      => $this->register,
						'schema'        => $this->schema,
						'_rbac'         => $_rbac,
						'_multitenancy' => $_multitenancy,
					]
				)
			) ?? new ObjectEntityStub(payload: $data);
		}

		$existing = $this->find(id: $objectId);
		$merged   = $data;
		if ($existing !== null) {
			$merged = array_merge($existing->getObject(), $data);
		}

		$merged['id'] = $objectId;

		return $this->saveObject(object: $merged);

	}//end updateObject()

	/**
	 * Merge a partial update onto an object.
	 *
	 * Added to the published contract in hydra-gates v1.8.1; v1.8.0 declared the
	 * interface without it, so this adapter satisfied the contract by accident
	 * and taking v1.8.1 turns that into a load-time fatal.
	 *
	 * Same two arms as `updateObject()` above, for the same reason: a double
	 * that models `patchObject()` directly must be asked for it, or the
	 * synthesised find()+saveObject() path below would ask it for a
	 * `saveObject()` it never declared and the adapter would refuse — which
	 * reads as "the patch was never persisted" rather than as a missing double.
	 *
	 * @param string          $objectId      The object UUID or id.
	 * @param array           $data          The fields to merge.
	 * @param string|int|null $register      Register id, UUID or slug.
	 * @param string|int|null $schema        Schema id, UUID or slug.
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
		if (method_exists($this->inner, 'patchObject') === true) {
			return $this->entity(
				value: $this->invokeInner(
					method: 'patchObject',
					primary: $objectId,
					named: [
						'data'          => $data,
						'object'        => $data,
						'register'      => ($register ?? $this->register),
						'schema'        => ($schema ?? $this->schema),
						'_rbac'         => $_rbac,
						'_multitenancy' => $_multitenancy,
					]
				)
			) ?? new ObjectEntityStub(payload: $data);
		}

		$existing = $this->find(id: $objectId);
		$merged   = $data;
		if ($existing !== null) {
			$merged = array_merge($existing->getObject(), $data);
		}

		$merged['id'] = $objectId;

		return $this->saveObject(object: $merged);

	}//end patchObject()

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
		if ($schema !== null) {
			$this->setSchema(schema: $schema);
		}

		return (bool) $this->invokeInner(
			method: 'deleteObject',
			primary: $uuid,
			named: [
				'register'        => $this->register,
				'schema'          => $this->schema,
				'_rbac'           => $_rbac,
				'_multitenancy'   => $_multitenancy,
				'_retentionSweep' => $_retentionSweep,
				'currentUser'     => $currentUser,
				'permanent'       => $permanent,
			]
		);

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
