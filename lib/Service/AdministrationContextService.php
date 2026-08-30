<?php

/**
 * Administration Context Service
 *
 * Resolves the administratie-aware RBAC context for the authenticated user:
 * the set of administrations the user may access (via AdministrationMembership
 * records), the active administration for the session, and IDOR-safe access
 * checks used to scope every financial query to a single administration
 * (REQ-MA-001, REQ-MA-003). A user who requests a record belonging to an
 * administration they have no membership for is treated as if the record does
 * not exist (masked 404, never 403, so the existence of other tenants' data is
 * not disclosed — REQ-MA-001).
 *
 * Memberships are stored as AdministrationMembership records in OpenRegister and
 * read through the real ObjectService API (find / findAll). A user/person is a
 * Nextcloud entity — the membership references the Nextcloud uid, no person
 * schema is invented.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the administratie-aware RBAC context and enforces tenant isolation.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-11
 */
class AdministrationContextService {
	/**
	 * Roles permitted to post journal entries when the membership does not
	 * explicitly set mayPostJournalEntries (REQ-MA-003). Read-only roles
	 * (inkijker, accountant_extern) are deliberately excluded.
	 *
	 * @var array<string,bool>
	 */
	private const POSTING_ROLES = [
		'eigenaar' => true,
		'controller' => true,
		'boekhouder' => true,
		'debiteurenadmin' => true,
		'crediteurenadmin' => true,
		'salarisadministrateur' => true,
	];

	/**
	 * Per-request memoisation of {@see membershipsForUser()}, keyed by uid.
	 *
	 * {@see canAccess()} / {@see accessibleAdministrationIds()} /
	 * {@see buildContext()} / {@see canPostJournalEntry()} are all
	 * independent public entry points that
	 * (directly or via buildContext()) each used to re-run the
	 * AdministrationMembership findAll() query on every call. A single
	 * request commonly calls more than one of them (e.g. a controller
	 * calling canAccess() as an IDOR guard, then canPostJournalEntry(),
	 * which itself calls buildContext()) — this service is a plain
	 * autowired NC service with no factory override (verified: not listed
	 * in Application.php's register()), so the container hands back the
	 * SAME instance for the lifetime of one request, making this property
	 * safe to use as a request-scoped cache. It carries no state across
	 * requests because a new container — and therefore a new instance — is
	 * built per request.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $membershipsCache = [];

	/**
	 * Per-request memoisation of {@see findAdministration()}, keyed by the
	 * administrationId/administrationCode looked up. See
	 * {@see $membershipsCache} for why an instance property is safe here.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private array $administrationCache = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched lazily.
	 * @param IUserSession $userSession The current user session.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the uid of the authenticated user, or null if anonymous.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-11
	 */
	public function currentUserId(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end currentUserId()

	/**
	 * Build the session administration context for the authenticated user (REQ-MA-003).
	 *
	 * Returns the list of accessible administrations (id + code + name + the
	 * user's role and posting/closing rights), and the default active
	 * administration (the first accessible one). An anonymous user, or a user
	 * with no memberships, gets an empty list and a null active administration.
	 *
	 * @return array{userId:?string,administrations:array<int,array<string,mixed>>,activeAdministrationId:?string}
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-11
	 */
	public function buildContext(): array {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return [
				'userId' => null,
				'administrations' => [],
				'activeAdministrationId' => null,
			];
		}

		$memberships = $this->membershipsForUser(userId: $userId);
		$administrations = [];
		foreach ($memberships as $membership) {
			$administrationId = (string)($membership['administrationId'] ?? '');
			if ($administrationId === '') {
				continue;
			}

			if ($this->membershipIsCurrentlyValid(membership: $membership) === false) {
				continue;
			}

			$administration = $this->findAdministration(administrationId: $administrationId);
			if ($administration === null) {
				continue;
			}

			$administrations[] = [
				'administrationId' => $administrationId,
				'administrationCode' => (string)($administration['administrationCode'] ?? ''),
				'name' => (string)($administration['name'] ?? ''),
				'status' => (string)($administration['status'] ?? 'actief'),
				'role' => (string)($membership['role'] ?? 'inkijker'),
				'mayPostJournalEntries' => $this->resolvePostingRight(membership: $membership),
				'mayCloseFiscalYear' => (bool)($membership['mayCloseFiscalYear'] ?? false),
			];
		}//end foreach

		$activeId = null;
		if ($administrations !== []) {
			$activeId = $administrations[0]['administrationId'];
		}

		return [
			'userId' => $userId,
			'administrations' => $administrations,
			'activeAdministrationId' => $activeId,
		];

	}//end buildContext()

	/**
	 * List the administration ids the authenticated user may access.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	public function accessibleAdministrationIds(): array {
		// Access is granted by a valid AdministrationMembership (REQ-MA-001) — the
		// membership IS the access grant. Derive the accessible ids directly from
		// the user's currently-valid memberships rather than from buildContext(),
		// which additionally requires an Administration metadata object to exist;
		// a membership must still authorise access even when the (optional)
		// Administration record has not been materialised.
		$userId = $this->currentUserId();
		if ($userId === null) {
			return [];
		}

		$ids = [];
		foreach ($this->membershipsForUser(userId: $userId) as $membership) {
			$administrationId = (string)($membership['administrationId'] ?? '');
			if ($administrationId === '') {
				continue;
			}

			if ($this->membershipIsCurrentlyValid(membership: $membership) === false) {
				continue;
			}

			$ids[] = $administrationId;
		}

		return array_values(array_unique($ids));
	}//end accessibleAdministrationIds()

	/**
	 * Whether the authenticated user may access a given administration (REQ-MA-001).
	 *
	 * Used as the IDOR guard before any single-administration read/write: if this
	 * returns false the caller MUST mask the resource as a 404, never a 403.
	 *
	 * @param string $administrationId The administration id to check.
	 *
	 * @return bool True when the user has a valid membership for the administration.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	public function canAccess(string $administrationId): bool {
		if ($administrationId === '') {
			return false;
		}

		return in_array(needle: $administrationId, haystack: $this->accessibleAdministrationIds(), strict: true);
	}//end canAccess()

	/**
	 * Whether the authenticated user may post journal entries in the administration (REQ-MA-003).
	 *
	 * Returns false when the user has no membership for the administration or when
	 * the membership's role/flag does not grant posting rights.
	 *
	 * @param string $administrationId The administration id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-21
	 */
	public function canPostJournalEntry(string $administrationId): bool {
		foreach ($this->buildContext()['administrations'] as $administration) {
			if ((string)$administration['administrationId'] === $administrationId) {
				return (bool)$administration['mayPostJournalEntries'];
			}
		}

		return false;
	}//end canPostJournalEntry()

	/**
	 * Validate a requested active administration and return the id to switch to (REQ-MA-003).
	 *
	 * Returns the requested id when the user has a valid membership for it; returns
	 * null otherwise (the caller masks this as a 404, never confirming the
	 * administration exists for a non-member).
	 *
	 * @param string $targetId The administration the user wants to switch to.
	 *
	 * @return string|null The id to make active, or null when access is denied.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-11
	 */
	public function resolveSwitchTarget(string $targetId): ?string {
		if ($this->canAccess(administrationId: $targetId) === false) {
			return null;
		}

		return $targetId;
	}//end resolveSwitchTarget()

	/**
	 * Resolve the effective posting right of a membership (REQ-MA-003).
	 *
	 * Honours an explicit mayPostJournalEntries flag when present; otherwise
	 * derives from the role (read-only roles never post).
	 *
	 * @param array<string,mixed> $membership The membership record.
	 *
	 * @return bool
	 */
	private function resolvePostingRight(array $membership): bool {
		if (array_key_exists('mayPostJournalEntries', $membership) === true
			&& $membership['mayPostJournalEntries'] !== null
		) {
			return (bool)$membership['mayPostJournalEntries'];
		}

		$role = (string)($membership['role'] ?? '');
		return (self::POSTING_ROLES[$role] ?? false);
	}//end resolvePostingRight()

	/**
	 * Whether a membership is valid as of today (validFrom/validUntil window).
	 *
	 * @param array<string,mixed> $membership The membership record.
	 *
	 * @return bool
	 */
	private function membershipIsCurrentlyValid(array $membership): bool {
		$today = date('Y-m-d');

		$validFrom = (string)($membership['validFrom'] ?? '');
		if ($validFrom !== '' && $validFrom > $today) {
			return false;
		}

		$validUntil = (string)($membership['validUntil'] ?? '');
		if ($validUntil !== '' && $validUntil < $today) {
			return false;
		}

		return true;
	}//end membershipIsCurrentlyValid()

	/**
	 * Fetch the AdministrationMembership records for a user.
	 *
	 * @param string $userId Nextcloud uid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function membershipsForUser(string $userId): array {
		if (array_key_exists($userId, $this->membershipsCache) === true) {
			return $this->membershipsCache[$userId];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$memberships = $objectService
				->setRegister($this->register())
				->setSchema('AdministrationMembership')
				->findAll(['filters' => ['userId' => $userId]]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'AdministrationContextService: failed to load memberships',
				['userId' => $userId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($memberships as $membership) {
			// OpenRegister's findAll() returns ObjectEntity instances; normalise.
			$arr = $this->asArray(row: $membership);
			if ($arr !== []) {
				$result[] = $arr;
			}
		}

		// Cache the successful result only — a transient query failure above
		// returns early without populating the cache, so a later call within
		// the same request is free to retry rather than being poisoned by
		// one failed attempt for the rest of the request.
		$this->membershipsCache[$userId] = $result;

		return $result;
	}//end membershipsForUser()

	/**
	 * Fetch a single Administration record by its id or its administrationCode.
	 *
	 * ⚠️ `findAll(['filters' => ['id' => …]])` MATCHES NOTHING. `filters` addresses
	 * the object's JSON properties, and `id` is the ObjectEntity's own identifier,
	 * not a property — so the filter is applied against a field that does not
	 * exist and returns an empty set for EVERY value, valid uuids included.
	 * Measured against a live register: filtering `id` on the real uuid returned
	 * 0 rows, filtering `administrationCode` on `ADM-001` returned 1, and
	 * `find($uuid)` returned the record.
	 *
	 * That was the whole of shillinq#569. buildContext() skips a membership whose
	 * administration does not resolve, and this path returns null WITHOUT logging,
	 * so every user's context came back `administrations: []` while first-time
	 * setup reported `completed: true` — no error anywhere, and every
	 * administration-scoped surface silently unreachable.
	 *
	 * Both id spaces are resolved because both are in the data: memberships
	 * written against the OpenRegister uuid, and fixtures/config written against
	 * the human `administrationCode` (the value `administration_id` app-config
	 * holds, and the one ~20 other call sites scope on).
	 *
	 * @param string $administrationId The administration uuid or administrationCode.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findAdministration(string $administrationId): ?array {
		// BuildContext() calls this once per membership; a user with several
		// administrations (or a request that calls buildContext() more than
		// once — canPostJournalEntry() does) previously re-issued the
		// find()/findAll() pair for the same administrationId every time.
		// Memoised per request for the same reason as membershipsForUser()
		// — see $administrationCache.
		if (array_key_exists($administrationId, $this->administrationCache) === true) {
			return $this->administrationCache[$administrationId];
		}

		return $this->administrationCache[$administrationId] = $this->findAdministrationUncached(administrationId: $administrationId);
	}//end findAdministration()

	/**
	 * Uncached implementation of {@see findAdministration()}.
	 *
	 * @param string $administrationId The administration uuid or administrationCode.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findAdministrationUncached(string $administrationId): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$scoped = $objectService
				->setRegister($this->register())
				->setSchema('Administration');

			// Primary: the OpenRegister uuid, via the single-object lookup that
			// actually addresses it.
			//
			// ⚠️ Its own try/catch, deliberately. find() THROWS
			// DoesNotExistException ("Object with identifier 'ADM-001' not found
			// in any magic table") for anything that is not a uuid — it does not
			// return null. Letting that reach the outer catch would return null
			// before the administrationCode fallback below ever runs, which is
			// exactly the bug this method is being fixed for, reintroduced one
			// layer down. A miss here is a normal id-space mismatch, not an error.
			try {
				$arr = $this->asArray(row: $scoped->find($administrationId));
				if ($arr !== []) {
					return $arr;
				}
			} catch (\Throwable $notAUuid) {
				// Fall through to the administrationCode lookup.
			}

			// Fallback: the human administrationCode, which IS a JSON property
			// and therefore is filterable.
			$matches = $scoped->findAll(
				[
					'filters' => ['administrationCode' => $administrationId],
					'limit' => 1,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'AdministrationContextService: failed to load administration',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		foreach ($matches as $match) {
			// OpenRegister's findAll() returns ObjectEntity instances; normalise.
			$arr = $this->asArray(row: $match);
			if ($arr !== []) {
				return $arr;
			}
		}

		// Reached only when a membership names an administration that does not
		// exist. Logged rather than dropped: a silent skip here is what made the
		// empty context so hard to attribute.
		$this->logger->warning(
			'AdministrationContextService: membership names an administration that does not resolve',
			['administrationId' => $administrationId]
		);

		return null;
	}//end findAdministrationUncached()

	/**
	 * Normalise an OpenRegister ObjectService row (ObjectEntity or array) to a
	 * plain array<string,mixed>.
	 *
	 * @param mixed $row Raw row from ObjectService::findAll()/find().
	 *
	 * @return array<string,mixed> The object as an array (empty array when unusable).
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$out = $row->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$out = $row->getObject();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		return [];
	}//end asArray()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
