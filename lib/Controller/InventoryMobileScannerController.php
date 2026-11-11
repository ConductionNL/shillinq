<?php

/**
 * Inventory Mobile Scanner Controller
 *
 * HTTP endpoints that back the offline-first warehouse PWA:
 *
 *   GET  /api/v1/inventory/sync — return InventoryStock deltas since the
 *                                   supplied ?since= timestamp so the mobile
 *                                   IndexedDB cache can apply server changes
 *                                   per REQ-OFFLINE-002.
 *   POST /api/v1/inventory/sync — accept a batch of warehouse operations
 *                                   (receive / transfer / pick / count) keyed
 *                                   by client transactionId per REQ-SYNC-001.
 *   GET  /api/v1/inventory/locations — list locations accessible to the
 *                                       current administration (for the
 *                                       "from / to" selectors).
 *
 * Authorisation: every endpoint requires an authenticated NC user
 * (#[NoAdminRequired]). The current user's administrationId comes from the
 * AdministrationContextService so cross-tenant access is impossible (ADR-005).
 * Permission gates per REQ-PERM-001 are evaluated server-side in
 * InventoryMobileScannerService::uploadOperations() using the user's
 * Nextcloud group memberships:
 *
 *   group "warehouse_manager"  -> may submit receive
 *   group "inventory_operator" -> may submit transfer / pick
 *   group "counter"            -> may submit count
 *
 * The shillinq Nextcloud admin group is unconditionally permitted.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InventoryMobileScannerService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Inventory mobile scanner sync endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class InventoryMobileScannerController extends Controller {

	/**
	 * Maximum batch size accepted per POST. Larger batches are rejected so
	 * a runaway client cannot exhaust server memory.
	 */
	public const MAX_BATCH_SIZE = 200;

	/**
	 * NC group ids that map to the three REQ-PERM-001 roles. Admins are
	 * resolved separately via IGroupManager::isAdmin().
	 *
	 * @var array<string,string>
	 */
	private const GROUP_ROLE_MAP = [
		'warehouse_manager' => 'warehouse_manager',
		'inventory_operator' => 'inventory_operator',
		'counter' => 'counter',
	];

	/**
	 * Construct the controller with DI dependencies.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param IUserSession $userSession Authenticated user session.
	 * @param IGroupManager $groupManager Group membership lookups.
	 * @param SettingsService $settings Shillinq settings.
	 * @param AdministrationContextService $admin Per-user administration scope.
	 * @param InventoryMobileScannerService $scanner Sync core.
	 * @param ContainerInterface $container DI container (locations lookup).
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly SettingsService $settings,
		private readonly AdministrationContextService $admin,
		private readonly InventoryMobileScannerService $scanner,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET /api/v1/inventory/sync?since=<iso8601>
	 *
	 * Returns InventoryStock deltas modified since the supplied timestamp,
	 * scoped to the current user's active administration. The `since`
	 * parameter is OPTIONAL; an empty value triggers a full initial sync.
	 *
	 * @param string|null $since ISO 8601 UTC timestamp (e.g. 2026-05-21T14:23:00Z).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/inventory-mobile-scanner/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadDeltas(?string $since = null): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return self::notLoggedIn();
		}

		$administrationId = $this->resolveAdministrationId();
		if ($administrationId === null) {
			return self::noAdministration();
		}

		$result = $this->scanner->downloadDeltas(since: self::cleanSince(since: $since), administrationId: $administrationId);

		return new JSONResponse(data: ['deltas' => $result['deltas'], 'serverTimestamp' => $result['serverTimestamp']]);
	}//end downloadDeltas()

	/**
	 * POST /api/v1/inventory/sync
	 *
	 * Body shape: { "operations": [ { type, sku, location, ..., transactionId, timestamp }, ... ] }
	 *
	 * The response shape is symmetric: { "results": [...], "serverTimestamp": "..." }
	 * where each result is { transactionId, status, ... } per
	 * MobileScannerSyncBatch.status (accepted / duplicate / rejected_permission /
	 * rejected_validation).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/inventory-mobile-scanner/spec.md
	 */
	#[NoAdminRequired]
	public function uploadOperations(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return self::notLoggedIn();
		}

		$administrationId = $this->resolveAdministrationId();
		if ($administrationId === null) {
			return self::noAdministration();
		}

		$raw = $this->request->getParam('operations');

		return self::batchRefusal(raw: $raw)
			?? $this->uploaded(user: $user, batch: new ScannerUpload(operations: $raw, administrationId: $administrationId));
	}//end uploadOperations()

	/**
	 * GET /api/v1/inventory/locations
	 *
	 * Returns the list of inventory locations accessible to the current
	 * user's administration so the PWA can populate the from/to selectors
	 * without re-querying for each operation.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/inventory-mobile-scanner/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listLocations(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return self::notLoggedIn();
		}

		$administrationId = $this->resolveAdministrationId();
		if ($administrationId === null) {
			return self::noAdministration();
		}

		$records = null;
		if ($this->settings->isOpenRegisterAvailable() === true) {
			$records = $this->locationRecords(administrationId: $administrationId);
		}

		return new JSONResponse(data: ['locations' => $this->locationRows(records: ($records ?? []))]);
	}//end listLocations()

	/**
	 * The 401 for a request without a session user.
	 *
	 * @return JSONResponse
	 */
	private static function notLoggedIn(): JSONResponse {
		return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
	}//end notLoggedIn()

	/**
	 * The 403 for a user without an active administration.
	 *
	 * @return JSONResponse
	 */
	private static function noAdministration(): JSONResponse {
		return new JSONResponse(
			data: ['message' => 'No administration in scope for current user'],
			statusCode: Http::STATUS_FORBIDDEN,
		);
	}//end noAdministration()

	/**
	 * The trimmed `since` value; null (a full initial sync) when absent or blank.
	 *
	 * @param string|null $since The query value.
	 *
	 * @return string|null
	 */
	private static function cleanSince(?string $since): ?string {
		if ($since !== null && trim($since) !== '') {
			return trim($since);
		}

		return null;
	}//end cleanSince()

	/**
	 * The 400 for a missing, non-array or oversized operations batch.
	 *
	 * @param mixed $raw The operations parameter.
	 *
	 * @return JSONResponse|null The refusal, or null for an acceptable batch.
	 */
	private static function batchRefusal(mixed $raw): ?JSONResponse {
		if (is_array($raw) === false) {
			return new JSONResponse(data: ['message' => 'Missing or invalid operations array'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		if (count($raw) > self::MAX_BATCH_SIZE) {
			return new JSONResponse(
				data: ['message' => 'Batch exceeds maximum size ' . self::MAX_BATCH_SIZE],
				statusCode: Http::STATUS_BAD_REQUEST,
			);
		}

		return null;
	}//end batchRefusal()

	/**
	 * Hand the batch to the sync core with the user's roles; answer its results.
	 *
	 * @param IUser         $user  The session user.
	 * @param ScannerUpload $batch The operations and their administration.
	 *
	 * @return JSONResponse
	 */
	private function uploaded(IUser $user, ScannerUpload $batch): JSONResponse {
		$roles = $this->resolveRoles(userId: $user->getUID());
		$result = $this->scanner->uploadOperations(
			operations: $batch->operations,
			userId: $user->getUID(),
			roles: $roles,
			administrationId: $batch->administrationId,
		);

		return new JSONResponse(data: ['results' => $result['results'], 'serverTimestamp' => $result['serverTimestamp']]);
	}//end uploaded()

	/**
	 * The administration's Location records (at most 500); a failed lookup is a warning.
	 *
	 * @param string $administrationId The administration in scope.
	 *
	 * @return array<int, mixed>|null The records, or null when the lookup failed.
	 */
	private function locationRecords(string $administrationId): ?array {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			return $objectService
				->setRegister($this->settings->getRegisterSlug())
				->setSchema('Location')
				->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => 500]);
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq mobile scanner: location list lookup failed', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end locationRecords()

	/**
	 * Project the records to code / name / warehouse strings.
	 *
	 * @param array<int, mixed> $records Location records (entities or arrays).
	 *
	 * @return array<int, array<string, string>>
	 */
	private function locationRows(array $records): array {
		$locations = [];
		foreach ($records as $record) {
			$row = $this->normalise(record: $record);
			$locations[] = [
				'code' => (string)($row['code'] ?? ''),
				'name' => (string)($row['name'] ?? ''),
				'warehouse' => (string)($row['warehouse'] ?? ''),
			];
		}

		return $locations;
	}//end locationRows()

	/**
	 * Resolve the administration id in scope for the authenticated user.
	 * Returns null when the user has no membership in any administration,
	 * which the caller treats as 403.
	 *
	 * @return string|null
	 */
	private function resolveAdministrationId(): ?string {
		$context = $this->admin->buildContext();
		$active = ($context['activeAdministrationId'] ?? null);
		if ($active === null || $active === '') {
			return null;
		}

		return (string)$active;
	}//end resolveAdministrationId()

	/**
	 * Resolve the list of roles the authenticated user holds, mapping
	 * Nextcloud group ids to the REQ-PERM-001 role strings. Admin users
	 * are tagged with 'admin' so they unconditionally pass permission
	 * checks.
	 *
	 * @param string $userId Authenticated user id.
	 *
	 * @return list<string>
	 */
	private function resolveRoles(string $userId): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		$roles = [];
		if ($this->groupManager->isAdmin(userId: $userId) === true) {
			$roles[] = 'admin';
		}

		return array_values(array_unique(array_merge($roles, $this->groupRoles(user: $user))));
	}//end resolveRoles()

	/**
	 * The REQ-PERM-001 roles the user's Nextcloud groups map to, in group order.
	 *
	 * @param IUser $user The session user.
	 *
	 * @return list<string>
	 */
	private function groupRoles(IUser $user): array {
		$roles = [];
		foreach ($this->groupManager->getUserGroupIds(user: $user) as $group) {
			$role = (self::GROUP_ROLE_MAP[$group] ?? null);
			if ($role !== null) {
				$roles[] = $role;
			}
		}

		return $roles;
	}//end groupRoles()

	/**
	 * Normalise an OR record (entity or array) to a flat array.
	 *
	 * @param mixed $record Record returned by ObjectService::findAll().
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $record): array {
		if (is_array($record) === true) {
			return $record;
		}

		if (is_object($record) === false) {
			return [];
		}

		return self::objectRow(record: $record);
	}//end normalise()

	/**
	 * An object record as an array: its jsonSerialize() payload when that is an array, else its public fields.
	 *
	 * @param object $record The record.
	 *
	 * @return array<string,mixed>
	 */
	private static function objectRow(object $record): array {
		if (method_exists($record, 'jsonSerialize') === true) {
			$serialised = $record->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return (array)$record;
	}//end objectRow()
}//end class
