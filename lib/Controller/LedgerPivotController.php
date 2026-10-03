<?php

/**
 * Ledger Pivot Controller
 *
 * GET /api/analysis/pivot: the result of posted ledger lines over two chosen
 * axes for one administration the caller belongs to
 * (reporting-custom-analysis REQ-RCA-002).
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
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Pivot\LedgerPivotService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Serves the financial pivot.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */
class LedgerPivotController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request The request.
	 * @param AdministrationContextService $context The caller's administrations.
	 * @param LedgerPivotService           $pivot   The pivot.
	 * @param LoggerInterface              $logger  The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextService $context,
		private readonly LedgerPivotService $pivot,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET /api/analysis/pivot?rows=&columns=&from=&to=&administrationId=
	 *
	 * The administration defaults to the one the caller is working in. One the
	 * caller is not a member of is masked as absent (ADR-005).
	 *
	 * @return JSONResponse The pivot, 400 on a refused request, 401, a masked 404 or 500.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	#[NoAdminRequired]
	public function pivot(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		if ($administrationId === '') {
			$administrationId = trim((string)($this->context->buildContext()['activeAdministrationId'] ?? ''));
		}

		if ($administrationId === '') {
			return new JSONResponse(['error' => 'No administration selected'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			return new JSONResponse(
				$this->pivot->pivot(
					administrationId: $administrationId,
					rowAxis: (string)$this->request->getParam('rows', ''),
					columnAxis: (string)$this->request->getParam('columns', ''),
					from: (string)$this->request->getParam('from', ''),
					to: (string)$this->request->getParam('to', '')
				)
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('LedgerPivotController: pivot failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Pivot unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end pivot()
}//end class
