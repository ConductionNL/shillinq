<?php

/**
 * Shillinq Barcode Lookup Controller
 *
 * Synchronous barcode-resolution endpoint for POS terminals (pipelinq
 * pos-barcode-scan). Resolves a scanned barcode value to its Barcode register
 * record plus the expanded Product data, returning the per-UoM quantity so the
 * POS can offer "add 1 carton (= N units)" semantics correctly per REQ-SKU-009.
 *
 * Note: the inventory-barcode-sku spec text refers to the product schema as
 * `InventoryItem`; the live shillinq catalog (inventory-product-catalog)
 * registers it as `Product`. This controller queries `Product` for the
 * expanded product payload — semantically identical to the spec.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-barcode-sku/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Barcode lookup endpoint for POS scanning per REQ-SKU-007 / REQ-SKU-008.
 *
 * Authentication (ADR-005): the endpoint is `#[PublicPage]` so provisioned
 * POS terminals (which hold no Nextcloud session) can call it, but every
 * request MUST present a valid Bearer API key matching the app config secret
 * `barcode_lookup_api_key`. When no key is configured the endpoint fails
 * secure — it falls back to requiring an authenticated Nextcloud user and
 * rejects anonymous callers (never an open endpoint). The API key is read
 * from app config and is never returned in any response.
 *
 * @spec openspec/changes/inventory-barcode-sku/tasks.md#task-12
 */
class BarcodeLookupController extends Controller {

	/**
	 * App config key holding the shared POS API key (secret, never returned).
	 */
	private const API_KEY_CONFIG = 'barcode_lookup_api_key';

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request object.
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug + API key.
	 * @param IUserSession $userSession Session for the fail-secure fallback.
	 * @param LoggerInterface $logger Logger for diagnostics (never logs secrets).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Resolve a scanned barcode to its record plus expanded product data.
	 *
	 * `GET /index.php/apps/shillinq/api/barcode/lookup/{code}[?uomCode=EA]`
	 *
	 * Returns 401 when the Bearer API key is missing/invalid (and no NC
	 * session fallback applies), 404 when no active barcode matches, 200
	 * with the barcode + product envelope otherwise. Inactive barcodes
	 * (`isActive=false`) are never returned per REQ-SKU-008.
	 *
	 * @param string $code The scanned barcode value (path parameter).
	 * @param string|null $uomCode Optional UoM filter (e.g. 'CA' for cartons).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/inventory-barcode-sku/tasks.md#task-12
	 * Rate limit: lookup against published product data — the code is a GTIN,
	 * not a credential, so this is a volume ceiling and not a brute-force
	 * counter. Loose, because a scanner in a stockroom fires these in rapid
	 * succession.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function lookup(string $code, ?string $uomCode = null): JSONResponse {
		$guardResponse = $this->guard();
		if ($guardResponse instanceof JSONResponse) {
			return $guardResponse;
		}

		$code = trim($code);
		if ($code === '') {
			return new JSONResponse(['error' => 'Barcode not found'], Http::STATUS_NOT_FOUND);
		}

		$barcode = $this->findActiveBarcode(code: $code, uomCode: $uomCode);
		if ($barcode === null) {
			return new JSONResponse(['error' => 'Barcode not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(
			[
				'barcode' => $this->presentBarcode(barcode: $barcode),
				'product' => $this->findProduct(sku: (string)($barcode['productSku'] ?? '')),
			]
		);

	}//end lookup()

	/**
	 * Auth guard for the lookup endpoint (ADR-005, fail-secure).
	 *
	 * Returns a 401 `JSONResponse` when the caller is not authorized; returns
	 * `null` to indicate the caller may proceed. The actual auth check is
	 * delegated to `isAuthorized()` — keeping the 401 response shape isolated
	 * here mirrors the `WidgetApiController::guard()` pattern used elsewhere
	 * in the codebase and keeps the lookup endpoint body focused on its
	 * happy-path semantics.
	 *
	 * @return JSONResponse|null 401 response when blocked, null when allowed.
	 */
	private function guard(): ?JSONResponse {
		if ($this->isAuthorized() === true) {
			return null;
		}

		return new JSONResponse(['error' => 'Unauthorized'], 401);
	}//end guard()

	/**
	 * Verify the caller is authorized (ADR-005, fail-secure).
	 *
	 * When an API key is configured, the request MUST carry a matching
	 * Bearer token (constant-time `hash_equals`). When no API key is
	 * configured, fall back to requiring an authenticated Nextcloud user —
	 * anonymous callers are always rejected.
	 *
	 * @return bool True when the caller may proceed.
	 */
	private function isAuthorized(): bool {
		$configuredKey = $this->appConfig->getValueString(
			Application::APP_ID,
			self::API_KEY_CONFIG,
			''
		);

		if ($configuredKey === '') {
			// No POS key provisioned — fail secure: only logged-in NC users.
			return $this->userSession->getUser() !== null;
		}

		$header = (string)$this->request->getHeader('Authorization');
		if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) !== 1) {
			return false;
		}

		return hash_equals($configuredKey, trim($matches[1]));
	}//end isAuthorized()

	/**
	 * Find the first active barcode matching the code (and optional UoM).
	 *
	 * @param string $code The barcode value.
	 * @param string|null $uomCode Optional UoM filter.
	 *
	 * @return array<string, mixed>|null The matching active barcode, or null.
	 */
	private function findActiveBarcode(string $code, ?string $uomCode): ?array {
		$filters = ['barcode' => $code];
		if ($uomCode !== null && $uomCode !== '') {
			$filters['uomCode'] = $uomCode;
		}

		foreach ($this->findMany(schema: 'Barcode', filters: $filters) as $record) {
			// REQ-SKU-008: inactive barcodes are never returned. isActive
			// defaults to true when the field is absent on a record.
			if (($record['isActive'] ?? true) === false) {
				continue;
			}

			return $record;
		}

		return null;
	}//end findActiveBarcode()

	/**
	 * Resolve the related Product for the response envelope.
	 *
	 * Returns null when inventory-product-catalog has not yet seeded a
	 * matching Product (the barcode is still resolvable on its own).
	 *
	 * @param string $sku The product SKU to resolve.
	 *
	 * @return array<string, mixed>|null Expanded product data, or null.
	 */
	private function findProduct(string $sku): ?array {
		if ($sku === '') {
			return null;
		}

		$items = $this->findMany(schema: 'Product', filters: ['sku' => $sku], limit: 1);
		if (count($items) === 0) {
			return null;
		}

		// FindMany returns array<int, array<string, mixed>>; reset() on a
		// non-empty result is guaranteed to return the first element record.
		return $items[array_key_first($items)];
	}//end findProduct()

	/**
	 * Project a stored barcode record onto the public response shape.
	 *
	 * Exposes only the operationally relevant fields; never leaks internal
	 * OpenRegister metadata beyond the id.
	 *
	 * @param array<string, mixed> $barcode The stored barcode record.
	 *
	 * @return array<string, mixed> The presented barcode.
	 */
	private function presentBarcode(array $barcode): array {
		return [
			'id' => ($barcode['id'] ?? null),
			'barcode' => ($barcode['barcode'] ?? null),
			'barcodeType' => ($barcode['barcodeType'] ?? null),
			'format' => ($barcode['format'] ?? null),
			'productSku' => ($barcode['productSku'] ?? null),
			'uomCode' => ($barcode['uomCode'] ?? null),
			'quantity' => ($barcode['quantity'] ?? null),
			'isDefault' => ($barcode['isDefault'] ?? false),
			'isActive' => ($barcode['isActive'] ?? true),
		];

	}//end presentBarcode()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Find records by exact-match filters in the configured register.
	 *
	 * Returns an empty array when OpenRegister or the schema is unavailable
	 * — no stack trace leaks to the client per ADR-005.
	 *
	 * @param string $schema Schema name.
	 * @param array<string, mixed> $filters Exact-match filters.
	 * @param int|null $limit Optional result limit.
	 *
	 * @return array<int, array<string, mixed>> Matching records.
	 */
	private function findMany(string $schema, array $filters, ?int $limit = null): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$params = ['filters' => $filters];
			if ($limit !== null) {
				$params['limit'] = $limit;
			}

			$result = $objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: $schema)
				->findAll($params);

			if (is_array($result) === false) {
				return [];
			}

			return $result;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'BarcodeLookupController: schema lookup unavailable — treating as empty',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end findMany()
}//end class
