<?php

/**
 * ObjectServiceInterface stub for unit tests that mock/typehint
 * OpenRegister's object service contract (ADR-084) without depending on the
 * OpenRegister app being autoloaded. Several controllers
 * (CBSSubmissionController, InvoiceApiController, and others) already
 * type-hint their constructor against this interface, and
 * `OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter` — wired
 * into 128 test classes per commit ef9c8fa5 ("wire the seeded OpenRegister
 * store back into 128 test classes") — already `implements` it. No
 * `Contract/` directory previously existed under tests/stubs/OpenRegister/,
 * so every one of those (and every test directly mocking this interface,
 * e.g. InvoiceApiControllerTest) fatal'd with "Interface ... not found"
 * before this file was added. When run inside a deployed Nextcloud tree
 * with OpenRegister installed, `lib/base.php` provides the real interface
 * and this stub is simply shadowed (same convention as the sibling stubs
 * registered in tests/bootstrap-unit.php).
 *
 * The full 26-method surface (and every parameter name/type) is reverse
 * -derived from `DuckObjectServiceAdapter`'s own `implements
 * ObjectServiceInterface` — that adapter's docblock explicitly documents it
 * as "the published ADR-084 contract" and models every method the real
 * interface declares, throwing for the handful production never calls. This
 * stub's signatures are copied from that adapter verbatim so both it and
 * every duck-typed double it wraps stay assignment-compatible.
 *
 * `patchObject()` was added to the published contract in hydra-gates v1.8.1;
 * keep this stub's method count in step with the real interface, since this
 * stub — not the real one — is what the local unit suite loads, and a gap
 * here is invisible until a deployed-Nextcloud CI run fatals at class load.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests
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

namespace OCA\OpenRegister\Contract;

use OCP\IUser;

/**
 * Stub for OCA\OpenRegister\Contract\ObjectServiceInterface used by shillinq tests.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Mirrors the real 26-method contract.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
interface ObjectServiceInterface {

	/**
	 * Scope subsequent calls to a register.
	 *
	 * @param string|int $register Register slug or id.
	 *
	 * @return static
	 */
	public function setRegister(string|int $register): static;

	/**
	 * Scope subsequent calls to a schema.
	 *
	 * @param string|int $schema Schema slug or id.
	 *
	 * @return static
	 */
	public function setSchema(string|int $schema): static;

	/**
	 * Drop the register/schema scope.
	 *
	 * @return void
	 */
	public function clearCurrents(): void;

	/**
	 * List objects matching a filter/paging config.
	 *
	 * @param array $config Filters, limit, offset, sort and search.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 *
	 * @return array
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Fetch a single object by id.
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
	): ?ObjectEntityInterface;

	/**
	 * Create or update an object.
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
	): ObjectEntityInterface;

	/**
	 * Count rows the given query would return.
	 *
	 * @param array $config Filters, limit, offset, sort and search.
	 *
	 * @return int
	 */
	public function count(array $config = []): int;

	/**
	 * Scoped list query by register/schema slug.
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
	): array|int;

	/**
	 * Apply a partial update to an object.
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
	): ObjectEntityInterface;

	/**
	 * Merge a partial update onto a stored object.
	 *
	 * Added to the published contract in hydra-gates v1.8.1 (@contract-shift,
	 * openregister#2543). Every `implements ObjectServiceInterface` test
	 * double must declare this method or fatal at class load in a deployed
	 * Nextcloud tree, where the real OpenRegister interface — not this stub —
	 * is the one that is actually loaded.
	 *
	 * @param string $objectId The object UUID or id.
	 * @param array $data The partial data to merge. Omitted keys are preserved.
	 * @param string|int|null $register Register id, UUID or slug.
	 * @param string|int|null $schema Schema id, UUID or slug.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 * @param ?IUser $currentUser Explicit acting user; null uses the session.
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
	): ObjectEntityInterface;

	/**
	 * Find without audit or read events.
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
	): ObjectEntityInterface;

	/**
	 * Run an operation with system privileges.
	 *
	 * No return type is declared — mirrors
	 * `DuckObjectServiceAdapter::runAsSystem()`, the reference implementer,
	 * which also declares none (an explicit `: mixed` here would be
	 * incompatible with an implementation that omits the return type
	 * entirely).
	 *
	 * @param callable $operation The operation to run.
	 *
	 * @return mixed
	 */
	public function runAsSystem(callable $operation);

	/**
	 * The currently-held context object, if any.
	 *
	 * @return ?ObjectEntityInterface
	 */
	public function getObject(): ?ObjectEntityInterface;

	/**
	 * Full-text/faceted object search.
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
	): array|int;

	/**
	 * Delete an object by id.
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
	): bool;

	/**
	 * Paginated full-text/faceted object search.
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
	): array;

	/**
	 * Build a search query from raw request parameters.
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
	): array;

	/**
	 * Bulk-persist objects.
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
	): array;

	/**
	 * Append rows with every safeguard switched OFF.
	 *
	 * No RBAC, no multitenancy, no validation, no audit trail, no lifecycle
	 * events, no search index, no relations, no files. Calling it is the same
	 * declaration as `_rbac: false`: the caller has taken the authorisation
	 * responsibility. It exists for high-volume, low-value rows such as traffic
	 * events and telemetry; use saveObjects() for anything a person will edit,
	 * audit or revert.
	 *
	 * Each row is a plain property map plus the optional metadata keys `uuid`
	 * (generated when absent), `expires` (ISO 8601 or DateTimeInterface, swept
	 * by purgeExpiredObjectsRaw()), `owner` and `organisation`.
	 *
	 * @param array      $objects  Rows to append, as plain arrays.
	 * @param string|int $register Register id, uuid or slug.
	 * @param string|int $schema   Schema id, uuid or slug, resolved within the register.
	 *
	 * @return int The number of rows written.
	 *
	 * @contract-shift announced — openregister#3406 names the fleet test doubles that
	 * must declare this method or fatal at class load: pipelinq
	 * tests/Stubs/Service/ObjectService.php (with its paired
	 * tests/Stubs/Contract/ObjectServiceInterface.php) and shillinq
	 * tests/Unit/Service/Support/{InMemoryObjectServiceStub,DuckObjectServiceAdapter}.php.
	 * createMock() sites are unaffected. The break lands on the
	 * `conduction/hydra-gates` RELEASE carrying this contract, not on this
	 * merge, because leaf apps read it from vendor/: land the doubles before
	 * the release, or pin.
	 */
	public function appendObjectsRaw(array $objects, string|int $register, string|int $schema): int;

	/**
	 * Hard-delete the rows of a register+schema whose `expires` has passed.
	 *
	 * The sweep for appendObjectsRaw(). Bypasses soft-delete and the audit
	 * trail on purpose: raw rows never had either.
	 *
	 * @param string|int $register Register id, uuid or slug.
	 * @param string|int $schema   Schema id, uuid or slug, resolved within the register.
	 *
	 * @return int The number of rows removed.
	 *
	 * @contract-shift announced — openregister#3406 names the fleet test doubles that
	 * must declare this method or fatal at class load: pipelinq
	 * tests/Stubs/Service/ObjectService.php (with its paired
	 * tests/Stubs/Contract/ObjectServiceInterface.php) and shillinq
	 * tests/Unit/Service/Support/{InMemoryObjectServiceStub,DuckObjectServiceAdapter}.php.
	 * createMock() sites are unaffected. The break lands on the
	 * `conduction/hydra-gates` RELEASE carrying this contract, not on this
	 * merge, because leaf apps read it from vendor/: land the doubles before
	 * the release, or pin.
	 */
	public function purgeExpiredObjectsRaw(string|int $register, string|int $schema): int;

	/**
	 * Release an advisory lock.
	 *
	 * @param string|int $identifier The object id or UUID.
	 * @param bool $advisory Take an advisory lock.
	 *
	 * @return bool
	 */
	public function unlockObject(string|int $identifier, bool $advisory = false): bool;

	/**
	 * Take an advisory lock.
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
	): array;

	/**
	 * Bulk-delete objects.
	 *
	 * @param array $uuids The object UUIDs.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 *
	 * @return array
	 */
	public function deleteObjects(array $uuids = [], bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Fetch an object's audit log.
	 *
	 * @param string $uuid The object UUID.
	 * @param array $filters Equality filters.
	 * @param bool $_rbac Apply register RBAC.
	 * @param bool $_multitenancy Apply organisation scoping.
	 *
	 * @return array
	 */
	public function getLogs(string $uuid, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Objects the given object uses (outgoing relations).
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
	): array;

	/**
	 * Objects that use the given object (incoming relations).
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
	): array;

	/**
	 * Find objects by a relation term.
	 *
	 * @param string $search The term to match relations against.
	 * @param bool $partialMatch Match relations partially.
	 *
	 * @return array
	 */
	public function findByRelations(string $search, bool $partialMatch = true): array;

	/**
	 * Count a search query's results without fetching them.
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
	): int;

}//end interface
