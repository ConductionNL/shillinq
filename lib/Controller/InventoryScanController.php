<?php

/**
 * Shillinq Inventory Scan Controller
 *
 * Server-authoritative API for the inventory mobile scanner. Three endpoints:
 *   - GET  /api/inventory/resolve?barcode=...  resolve a scanned barcode (or
 *          typed SKU) to its InventoryItem (REQ-SKU-001 / REQ-BARCODE-002).
 *   - GET  /api/inventory/sync?since=...        download the InventoryStock delta
 *          modified since a timestamp, for client last-write-wins sync
 *          (REQ-OFFLINE-002/003).
 *   - POST /api/inventory/scan                  upload a batch of scan operations;
 *          each is role-gated (REQ-PERM-001), deduplicated on its transactionId
 *          (REQ-SYNC-001), and applied server-side to the stock ledger.
 *
 * Authorization (REQ-PERM-001): every endpoint requires an authenticated user
 * (@NoAdminRequired — normal warehouse staff, not admins). The POST endpoint
 * additionally re-checks the acting user's role per operation type against
 * Nextcloud group membership; admins implicitly hold all roles. The asserted
 * client-side role is never trusted. The acting user id is taken from the
 * session, never from the request body, AND the administration named on the
 * request is checked against the caller's memberships
 * (AdministrationContextService::canAccess(), ADR-005 / REQ-MA-001).
 *
 * ⚠️ This paragraph used to end "IDOR-safe: the acting user id is taken from the
 * session, never from the request body." True and irrelevant: the ADMINISTRATION
 * id was a request parameter with the hardcoded default `'adm-1'`, so a request
 * that simply omitted it read (and wrote) tenant `adm-1`. The default is gone;
 * an absent or non-member administration is now a 400/404.
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
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T5.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InventoryScanService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Server-authoritative inventory scanner API controller.
 *
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T5.1
 */
class InventoryScanController extends Controller {

	/**
	 * Map of operation type to the Nextcloud group whose members may perform it
	 * (REQ-PERM-001). Admins implicitly hold every role.
	 *
	 * @var array<string, string>
	 */
	private const ROLE_GROUPS = [
		'receive' => 'shillinq-warehouse-manager',
		'transfer' => 'shillinq-inventory-operator',
		'pick' => 'shillinq-inventory-operator',
		'count' => 'shillinq-counter',
	];

	/**
	 * Maximum number of operations accepted in a single POST batch.
	 */
	private const MAX_BATCH = 200;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession The user session (acting user identity).
	 * @param IGroupManager $groupManager Group membership for role checks.
	 * @param InventoryScanService $scanService Server-authoritative scan logic.
	 * @param AdministrationContextService $context RBAC guard — resolves the user's administration memberships.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly InventoryScanService $scanService,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Resolve a scanned barcode (or typed SKU) to its InventoryItem (REQ-SKU-001).
	 *
	 * @param string $barcode The barcode or SKU value.
	 * @param string $administrationId Administration scope (defaults to adm-1).
	 *
	 * @return JSONResponse `{found: bool, item?: object}`.
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T2.4
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	public function resolve(string $barcode = '', string $administrationId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$barcode = trim($barcode);
		if ($barcode === '') {
			return new JSONResponse(data: ['message' => 'A barcode or SKU is required.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$administrationId = trim($administrationId);
		$error = $this->requireAccessibleAdministration(administrationId: $administrationId);
		if ($error !== null) {
			return $error;
		}

		$item = $this->scanService->resolveBarcode(barcode: $barcode, administrationId: $administrationId);
		if ($item === null) {
			return new JSONResponse(data: ['found' => false]);
		}

		return new JSONResponse(data: ['found' => true, 'item' => $item]);
	}//end resolve()

	/**
	 * Download the InventoryStock delta modified since a timestamp (REQ-OFFLINE-002).
	 *
	 * @param string $since ISO-8601 UTC cut-off; empty for a full sync.
	 * @param string $administrationId Administration scope (defaults to adm-1).
	 *
	 * @return JSONResponse `{serverTime: string, stock: array}`.
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.3
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	public function sync(string $since = '', string $administrationId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$sinceArg = trim($since);
		if ($sinceArg === '') {
			$sinceArg = null;
		}

		$administrationId = trim($administrationId);
		$error = $this->requireAccessibleAdministration(administrationId: $administrationId);
		if ($error !== null) {
			return $error;
		}

		$stock = $this->scanService->getStockDelta(since: $sinceArg, administrationId: $administrationId);

		return new JSONResponse(
			data: [
				'serverTime' => gmdate('Y-m-d\TH:i:s\Z'),
				'stock' => $stock,
			]
		);

	}//end sync()

	/**
	 * Upload a batch of scan operations and apply them server-side (REQ-OFFLINE-002).
	 *
	 * Each operation is role-gated (REQ-PERM-001), deduplicated on its
	 * transactionId (REQ-SYNC-001), and applied to the stock ledger. The acting
	 * user id is taken from the session, and the administration is checked
	 * against the caller's memberships before any operation is applied
	 * (ADR-005 / REQ-MA-001). Returns one ACK per operation, keyed by
	 * transactionId.
	 *
	 * @param array<int, array<string, mixed>> $operations The operation batch.
	 * @param string $administrationId Administration scope.
	 *
	 * @return JSONResponse `{results: array<{transactionId, status, ...}>}`.
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.4
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	public function scan(array $operations = [], string $administrationId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($operations === []) {
			return new JSONResponse(data: ['message' => 'No operations supplied.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		if (count($operations) > self::MAX_BATCH) {
			return new JSONResponse(
				data: ['message' => 'Batch too large; maximum ' . self::MAX_BATCH . ' operations per request.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$admin = trim($administrationId);
		$error = $this->requireAccessibleAdministration(administrationId: $admin);
		if ($error !== null) {
			return $error;
		}

		$userId = $user->getUID();
		$results = [];

		foreach ($operations as $operation) {
			$results[] = $this->processOne(operation: $operation, userId: $userId, administrationId: $admin);
		}

		return new JSONResponse(data: ['results' => $results]);
	}//end scan()

	/**
	 * Require the caller to be a member of the named administration (ADR-005).
	 *
	 * The administration is a request parameter on every endpoint of this
	 * controller. It used to default to the literal `'adm-1'`, so omitting it
	 * silently selected a real tenant; the default is now empty and an empty
	 * value is refused.
	 *
	 * @param string $administrationId The already-trimmed administration id.
	 *
	 * @return JSONResponse|null A 400/404 response when refused, null when allowed.
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.3
	 */
	private function requireAccessibleAdministration(string $administrationId): ?JSONResponse {
		if ($administrationId === '') {
			return new JSONResponse(
				data: ['message' => 'An administrationId is required.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(
				data: ['message' => 'Administration not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return null;
	}//end requireAccessibleAdministration()

	/**
	 * Validate, authorise and apply a single operation, returning its ACK.
	 *
	 * @param mixed $operation The operation payload (must be an array).
	 * @param string $userId Acting user id.
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string, mixed> The ACK for this operation.
	 */
	private function processOne(mixed $operation, string $userId, string $administrationId): array {
		if (is_array($operation) === false) {
			return ['transactionId' => null, 'status' => 'rejected', 'message' => 'Malformed operation.'];
		}

		$transactionId = trim((string)($operation['transactionId'] ?? ''));
		$type = (string)($operation['type'] ?? '');

		if ($transactionId === '') {
			return ['transactionId' => null, 'status' => 'rejected', 'message' => 'Missing transactionId.'];
		}

		if (isset(self::ROLE_GROUPS[$type]) === false) {
			return ['transactionId' => $transactionId, 'status' => 'rejected', 'message' => 'Unknown operation type.'];
		}

		// REQ-PERM-001 — re-check the acting user's role server-side. The
		// client-asserted role is never trusted.
		if ($this->userHasRole(userId: $userId, type: $type) === false) {
			$this->logger->info(
				'InventoryScanController: permission denied for operation',
				['user' => $userId, 'type' => $type, 'transactionId' => $transactionId]
			);
			return [
				'transactionId' => $transactionId,
				'status' => 'rejected',
				'message' => 'You do not have permission to perform a ' . $type . ' operation.',
			];
		}

		try {
			return $this->scanService->applyOperation(
				operation: $operation,
				userId: $userId,
				administrationId: $administrationId
			);
		} catch (\Throwable $e) {
			// Fail-closed: never leak a stack trace to the client.
			$this->logger->error(
				'InventoryScanController: operation failed',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return [
				'transactionId' => $transactionId,
				'status' => 'rejected',
				'message' => 'The operation could not be applied. Please retry.',
			];
		}//end try

	}//end processOne()

	/**
	 * Whether the user may perform an operation of the given type (REQ-PERM-001).
	 * Nextcloud admins implicitly hold every warehouse role.
	 *
	 * @param string $userId Acting user id.
	 * @param string $type Operation type.
	 *
	 * @return bool True when the user is in the role's group (or is an admin).
	 */
	private function userHasRole(string $userId, string $type): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		$group = (self::ROLE_GROUPS[$type] ?? '');
		if ($group === '') {
			return false;
		}

		return $this->groupManager->isInGroup(userId: $userId, group: $group);
	}//end userHasRole()
}//end class
