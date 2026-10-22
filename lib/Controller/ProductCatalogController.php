<?php

/**
 * Product Catalog Controller
 *
 * The route half of the inventory product catalog (#860). Serves the two
 * read-only surfaces `/inventory/products` and `/inventory/product-attributes`
 * that `shillinq-product-vendor-to-pipelinq` REQ-SPVP-004 requires to stay
 * deep-linkable after the local `Product` / `ProductAttribute` registers were
 * removed, and that `tests/e2e/spec-coverage/inventory.spec.ts` asserts.
 *
 * ## Authorisation posture
 *
 * `#[NoAdminRequired]` — inventory is an operator capability. Both methods take
 * NO request parameters: the administration scope is resolved server-side from
 * the caller's own AdministrationMembership set (REQ-MA-001) inside
 * {@see \OCA\Shillinq\Service\ProductCatalogService}. No caller-supplied object
 * identifier crosses the boundary, so there is no per-object check in either
 * method body — the absence is the design, not an omission.
 *
 * No `#[NoCSRFRequired]`: both are GETs issued by the SPA through
 * `@nextcloud/axios`, which carries the request token.
 *
 * Both endpoints are strictly READ-ONLY. There is deliberately no create,
 * update or delete route: REQ-SPVP-004's second scenario is that no shillinq
 * surface may accept a product definition, and a write route here would be
 * exactly that surface.
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
 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\ProductCatalogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only product catalog endpoints (REQ-IPC-008 / REQ-SPVP-004).
 *
 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
 */
class ProductCatalogController extends Controller {
	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request.
	 * @param ProductCatalogService $catalogService Resolves the catalog.
	 * @param IUserSession $userSession Session for the auth body-guard.
	 * @param LoggerInterface $logger Logger.
	 * @param IL10N $l10n Translations for the source note and the refusal.
	 */
	public function __construct(
		IRequest $request,
		private readonly ProductCatalogService $catalogService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * List the product catalog (REQ-IPC-008).
	 *
	 * Answers 200 with an explicit `source` / `authoritative` envelope whenever
	 * the caller holds an administration — including when the pipelinq master
	 * is unreachable, where the body carries the locally-projected rows and
	 * `authoritative: false`. The UI is required to render that distinction; a
	 * bare 200 with rows would let a stale cache read as the master.
	 *
	 * Answers **403** when the caller holds no valid AdministrationMembership.
	 * That is the authorisation decision this endpoint makes: it takes no
	 * parameters, so there is no caller-supplied object to compare against —
	 * the caller's own membership set IS the scope, and an empty set is a
	 * refusal rather than an empty catalog.
	 *
	 * @return JSONResponse The catalog envelope, or a JSON error envelope.
	 *
	 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
	 */
	#[NoAdminRequired]
	public function products(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$catalog = $this->catalogService->listProducts();
			if ($catalog === null) {
				return $this->noAdministration();
			}

			$catalog['sourceNote'] = $this->l10n->t('The product master is unavailable, so these are the products your stock and barcode records reference.')
				. ' ' . $this->l10n->t('Names, categories and prices are owned elsewhere and stay blank.');
			if (($catalog['authoritative'] ?? false) === true) {
				$catalog['sourceNote'] = $this->l10n->t('These products come from the product master.');
			}

			return new JSONResponse($catalog);
		} catch (Throwable $e) {
			$this->logger->error(
				'ProductCatalogController: product catalog lookup failed',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'catalog_unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end products()

	/**
	 * List the catalog's attribute definitions (REQ-IPC-004).
	 *
	 * Carries the same 403-on-no-membership refusal as {@see products()}.
	 *
	 * @return JSONResponse The attribute-definition envelope, or a JSON error envelope.
	 *
	 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-004
	 */
	#[NoAdminRequired]
	public function productAttributes(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$attributes = $this->catalogService->listAttributes();
			if ($attributes === null) {
				return $this->noAdministration();
			}

			$attributes['sourceNote'] = $this->l10n->t('The product master is unavailable, so these are the attributes the integration contract publishes.')
				. ' ' . $this->l10n->t('Owned by says which application authors each value.');
			if (($attributes['authoritative'] ?? false) === true) {
				$attributes['sourceNote'] = $this->l10n->t('These are the attribute names the product master\'s own products declare.');
			}

			return new JSONResponse($attributes);
		} catch (Throwable $e) {
			$this->logger->error(
				'ProductCatalogController: product attribute lookup failed',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'catalog_unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end productAttributes()

	/**
	 * The refusal for a caller without an administration.
	 *
	 * @return JSONResponse 403 with the reason.
	 */
	private function noAdministration(): JSONResponse {
		return new JSONResponse(
			[
				'error' => 'no_accessible_administration',
				'message' => $this->l10n->t('You have no administration yet, so there is no inventory to show. Ask an administrator for access.'),
			],
			Http::STATUS_FORBIDDEN
		);

	}//end noAdministration()
}//end class
