<?php

/**
 * Racing-default decorator over InMemoryObjectServiceStub.
 *
 * Simulates a concurrent `BudgetScenarioDefaultPromoter::promote()` call
 * (e.g. a second browser tab) interleaving its own write BETWEEN this call's
 * demotion/promotion writes and its own post-promotion verification re-read
 * (`openspec/changes/budget-scenarios/design.md` §3b, open question §13.1).
 * `InMemoryObjectServiceStub` is `final` (cannot be extended), so this
 * decorates by composition, forwarding every {@see ObjectServiceInterface}
 * method to the wrapped stub UNCHANGED except `findAll()`: on the SECOND
 * `isDefault: true`-filtered `findAll()` call for the target administration
 * — `BudgetScenarioDefaultPromoter::promote()`'s own POST-PROMOTION
 * VERIFICATION read, never its first, pre-write "who is currently default"
 * read — it injects a second `isDefault: true` row directly into the
 * wrapped stub (a real `saveObject()` write, so it is visible to every
 * subsequent read) before returning, reproducing exactly the "two defaults
 * momentarily exist" inconsistency the verification step exists to catch.
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
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
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
 * Forwarding decorator that injects a racing default on the FIRST
 * `isDefault: true`-filtered `findAll()` call it observes after construction.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Mirrors the 25-method contract.
 */
final class RacingDefaultObjectServiceDecorator implements ObjectServiceInterface {

	/**
	 * Whether the racing default has already been injected — injects
	 * exactly once, on the SECOND qualifying call, so the target scenario's
	 * own pre-write "who is currently default" read (the first qualifying
	 * call) is unaffected and only the post-promotion verification re-read
	 * (the second) observes the race.
	 *
	 * @var boolean
	 */
	private bool $injected = false;

	/**
	 * How many `isDefault: true`-filtered `findAll()` calls for the target
	 * administration have been observed so far.
	 *
	 * @var integer
	 */
	private int $qualifyingCallCount = 0;

	/**
	 * The racing scenario id + administrationId to inject.
	 *
	 * @var array{id: string, administrationId: string}
	 */
	private array $racer;

	/**
	 * Construct the decorator.
	 *
	 * @param ObjectServiceInterface $inner The wrapped store (an InMemoryObjectServiceStub).
	 * @param string $racerId The id of the injected racing default scenario.
	 * @param string $administrationId The administration the race occurs within.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $inner,
		string $racerId,
		string $administrationId
	) {
		$this->racer = ['id' => $racerId, 'administrationId' => $administrationId];

	}//end __construct()

	/**
	 * @param array $config Filters, limit, offset, sort and search.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 *
	 * @return array
	 */
	public function findAll(array $config = [], bool $rbac = true, bool $multitenancy = true): array {
		$rows = $this->inner->findAll($config, $rbac, $multitenancy);

		$isDefaultQuery = (($config['filters']['isDefault'] ?? null) === true);
		$administrationMatches = (($config['filters']['administrationId'] ?? null) === $this->racer['administrationId']);

		if ($isDefaultQuery === true && $administrationMatches === true) {
			$this->qualifyingCallCount++;
		}

		if ($isDefaultQuery === true && $administrationMatches === true
			&& $this->qualifyingCallCount === 2 && $this->injected === false
		) {
			$this->injected = true;
			$this->inner->saveObject(
				[
					'id' => $this->racer['id'],
					'administrationId' => $this->racer['administrationId'],
					'name' => 'Racing scenario',
					'isDefault' => true,
					'status' => 'active',
				]
			);

			return $this->inner->findAll($config, $rbac, $multitenancy);
		}

		return $rows;

	}//end findAll()

	/**
	 * @param string|int $register Register slug or id.
	 *
	 * @return static
	 */
	public function setRegister(string|int $register): static {
		$this->inner->setRegister($register);
		return $this;

	}//end setRegister()

	/**
	 * @param string|int $schema Schema slug or id.
	 *
	 * @return static
	 */
	public function setSchema(string|int $schema): static {
		$this->inner->setSchema($schema);
		return $this;

	}//end setSchema()

	/**
	 * @return void
	 */
	public function clearCurrents(): void {
		$this->inner->clearCurrents();

	}//end clearCurrents()

	/**
	 * @param int|string $id Object id, UUID or slug.
	 * @param ?array $extend Relations to expand.
	 * @param bool $files Include file metadata.
	 * @param string|int|null $register Register override.
	 * @param string|int|null $schema Schema override.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 * @param bool $render Render the entity.
	 * @param bool $audit Write an audit entry.
	 *
	 * @return ?ObjectEntityInterface
	 */
	public function find(
		int|string $id,
		?array $extend = [],
		bool $files = false,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $rbac = true,
		bool $multitenancy = true,
		bool $render = true,
		bool $audit = true
	): ?ObjectEntityInterface {
		return $this->inner->find($id, $extend, $files, $register, $schema, $rbac, $multitenancy, $render, $audit);

	}//end find()

	/**
	 * @param array $object Object payload.
	 * @param ?array $extend Relations to expand.
	 * @param string|int|null $register Register override.
	 * @param string|int|null $schema Schema override.
	 * @param ?string $uuid Explicit UUID.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 * @param bool $silent Suppress events.
	 * @param bool $validation Validate against the schema.
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
		bool $rbac = true,
		bool $multitenancy = true,
		bool $silent = false,
		bool $validation = true,
		?array $uploadedFiles = null,
		?IUser $currentUser = null,
		bool $failIfExists = false
	): ObjectEntityInterface {
		return $this->inner->saveObject(
			$object,
			$extend,
			$register,
			$schema,
			$uuid,
			$rbac,
			$multitenancy,
			$silent,
			$validation,
			$uploadedFiles,
			$currentUser,
			$failIfExists
		);

	}//end saveObject()

	/**
	 * @param array $config Filters, limit, offset, sort and search.
	 *
	 * @return int
	 */
	public function count(array $config = []): int {
		return $this->inner->count($config);

	}//end count()

	/**
	 * @param string $registerSlug The register slug.
	 * @param string $schemaSlug The schema slug.
	 * @param array $filters Equality filters.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 *
	 * @return array|int
	 */
	public function searchObjectsBySlug(
		string $registerSlug,
		string $schemaSlug,
		array $filters = [],
		bool $rbac = true,
		bool $multitenancy = true
	): array|int {
		return $this->inner->searchObjectsBySlug($registerSlug, $schemaSlug, $filters, $rbac, $multitenancy);

	}//end searchObjectsBySlug()

	/**
	 * @param string $objectId The object UUID or id.
	 * @param array $data The fields to change.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 *
	 * @return ObjectEntityInterface
	 */
	public function updateObject(
		string $objectId,
		array $data,
		bool $rbac = true,
		bool $multitenancy = true
	): ObjectEntityInterface {
		return $this->inner->updateObject($objectId, $data, $rbac, $multitenancy);

	}//end updateObject()

	/**
	 * @param string $objectId Object id, UUID or slug.
	 * @param array $data The partial data to merge.
	 * @param string|int|null $register Register id, UUID or slug.
	 * @param string|int|null $schema Schema id, UUID or slug.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 * @param ?IUser $currentUser Explicit acting user.
	 *
	 * @return ObjectEntityInterface
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
	 */
	public function patchObject(
		string $objectId,
		array $data,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $rbac = true,
		bool $multitenancy = true,
		?IUser $currentUser = null
	): ObjectEntityInterface {
		return $this->inner->patchObject($objectId, $data, $register, $schema, $rbac, $multitenancy, $currentUser);

	}//end patchObject()

	/**
	 * @param string $id Object id, UUID or slug.
	 * @param ?array $extend Relations to expand.
	 * @param bool $files Include file metadata.
	 * @param string|int|null $register Register override.
	 * @param string|int|null $schema Schema override.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 *
	 * @return ObjectEntityInterface
	 */
	public function findSilent(
		string $id,
		?array $extend = [],
		bool $files = false,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $rbac = true,
		bool $multitenancy = true
	): ObjectEntityInterface {
		return $this->inner->findSilent($id, $extend, $files, $register, $schema, $rbac, $multitenancy);

	}//end findSilent()

	/**
	 * @param callable $operation The operation to run.
	 *
	 * @return mixed
	 */
	public function runAsSystem(callable $operation) {
		return $this->inner->runAsSystem($operation);

	}//end runAsSystem()

	/**
	 * @return ?ObjectEntityInterface
	 */
	public function getObject(): ?ObjectEntityInterface {
		return $this->inner->getObject();

	}//end getObject()

	/**
	 * @param array $query The search query.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 * @param ?array $ids Restrict to these ids.
	 * @param ?string $uses Restrict to objects used by this one.
	 * @param ?array $views Restrict to these views.
	 *
	 * @return array|int
	 */
	public function searchObjects(
		array $query = [],
		bool $rbac = true,
		bool $multitenancy = true,
		?array $ids = null,
		?string $uses = null,
		?array $views = null
	): array|int {
		return $this->inner->searchObjects($query, $rbac, $multitenancy, $ids, $uses, $views);

	}//end searchObjects()

	/**
	 * @param string $uuid The object UUID.
	 * @param string|int|null $register Register id, UUID or slug.
	 * @param string|int|null $schema Schema id, UUID or slug.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 * @param bool $retentionSweep Run as part of a retention sweep.
	 * @param ?IUser $currentUser Explicit acting user.
	 * @param bool $permanent Delete permanently.
	 *
	 * @return bool
	 */
	public function deleteObject(
		string $uuid,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $rbac = true,
		bool $multitenancy = true,
		bool $retentionSweep = false,
		?IUser $currentUser = null,
		bool $permanent = false
	): bool {
		return $this->inner->deleteObject($uuid, $register, $schema, $rbac, $multitenancy, $retentionSweep, $currentUser, $permanent);

	}//end deleteObject()

	/**
	 * @param array $query The search query.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 * @param bool $deleted Include soft-deleted objects.
	 * @param ?array $ids Restrict to these ids.
	 * @param ?string $uses Restrict to objects used by this one.
	 * @param ?array $views Restrict to these views.
	 *
	 * @return array
	 */
	public function searchObjectsPaginated(
		array $query = [],
		bool $rbac = true,
		bool $multitenancy = true,
		bool $deleted = false,
		?array $ids = null,
		?string $uses = null,
		?array $views = null
	): array {
		return $this->inner->searchObjectsPaginated($query, $rbac, $multitenancy, $deleted, $ids, $uses, $views);

	}//end searchObjectsPaginated()

	/**
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
		return $this->inner->buildSearchQuery($requestParams, $register, $schema, $ids);

	}//end buildSearchQuery()

	/**
	 * @param array $objects The objects to store.
	 * @param string|int|null $register Register id, UUID or slug.
	 * @param string|int|null $schema Schema id, UUID or slug.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 * @param bool $validation Validate against the schema.
	 * @param bool $events Emit events for each object.
	 * @param bool $deduplicateIds Drop duplicate ids.
	 * @param bool $enrich Enrich each object.
	 * @param bool $audit Write an audit-trail entry.
	 *
	 * @return array
	 */
	public function saveObjects(
		array $objects,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $rbac = true,
		bool $multitenancy = true,
		bool $validation = false,
		bool $events = false,
		bool $deduplicateIds = true,
		bool $enrich = true,
		bool $audit = true
	): array {
		return $this->inner->saveObjects($objects, $register, $schema, $rbac, $multitenancy, $validation, $events, $deduplicateIds, $enrich, $audit);

	}//end saveObjects()

	/**
	 * @param string|int $identifier The object id or UUID.
	 * @param bool $advisory Take an advisory lock.
	 *
	 * @return bool
	 */
	public function unlockObject(string|int $identifier, bool $advisory = false): bool {
		return $this->inner->unlockObject($identifier, $advisory);

	}//end unlockObject()

	/**
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
		return $this->inner->lockObject($identifier, $process, $duration, $advisory);

	}//end lockObject()

	/**
	 * @param array $uuids The object UUIDs.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 *
	 * @return array
	 */
	public function deleteObjects(array $uuids = [], bool $rbac = true, bool $multitenancy = true): array {
		return $this->inner->deleteObjects($uuids, $rbac, $multitenancy);

	}//end deleteObjects()

	/**
	 * @param string $uuid The object UUID.
	 * @param array $filters Equality filters.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 *
	 * @return array
	 */
	public function getLogs(string $uuid, array $filters = [], bool $rbac = true, bool $multitenancy = true): array {
		return $this->inner->getLogs($uuid, $filters, $rbac, $multitenancy);

	}//end getLogs()

	/**
	 * @param string $objectId The object UUID.
	 * @param array $query The search query.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 *
	 * @return array
	 */
	public function getObjectUses(
		string $objectId,
		array $query = [],
		bool $rbac = true,
		bool $multitenancy = true
	): array {
		return $this->inner->getObjectUses($objectId, $query, $rbac, $multitenancy);

	}//end getObjectUses()

	/**
	 * @param string $objectId The object UUID.
	 * @param array $query The search query.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 *
	 * @return array
	 */
	public function getObjectUsedBy(
		string $objectId,
		array $query = [],
		bool $rbac = true,
		bool $multitenancy = true
	): array {
		return $this->inner->getObjectUsedBy($objectId, $query, $rbac, $multitenancy);

	}//end getObjectUsedBy()

	/**
	 * @param string $search The term to match relations against.
	 * @param bool $partialMatch Match relations partially.
	 *
	 * @return array
	 */
	public function findByRelations(string $search, bool $partialMatch = true): array {
		return $this->inner->findByRelations($search, $partialMatch);

	}//end findByRelations()

	/**
	 * @param array $query The search query.
	 * @param bool $rbac Apply register RBAC.
	 * @param bool $multitenancy Apply organisation scoping.
	 * @param ?array $ids Restrict to these ids.
	 * @param ?string $uses Restrict to objects used by this one.
	 *
	 * @return int
	 */
	public function countSearchObjects(
		array $query = [],
		bool $rbac = true,
		bool $multitenancy = true,
		?array $ids = null,
		?string $uses = null
	): int {
		return $this->inner->countSearchObjects($query, $rbac, $multitenancy, $ids, $uses);

	}//end countSearchObjects()
}//end class
