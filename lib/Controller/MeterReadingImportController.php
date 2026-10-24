<?php

/**
 * Meter reading import controller
 *
 * `POST /api/meter-readings/import`: the Import readings action on the meter
 * readings page (sales-usage-billing, REQ-USB-001).
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
 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Usage\MeterReadingImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Imports meter readings into an administration the caller belongs to.
 */
class MeterReadingImportController extends Controller {

	/**
	 * The most rows one import takes.
	 *
	 * @var integer
	 */
	private const MAX_ROWS = 5000;

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request  The request.
	 * @param AdministrationContextService $context  The caller's administrations.
	 * @param MeterReadingImportService    $importer Creates the readings.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextService $context,
		private readonly MeterReadingImportService $importer,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Create an unrated reading per valid row; name each refused row with its reason.
	 *
	 * Body: `administrationId`, `rows` (objects with meterId, customerId,
	 * resourceType, quantity, unit, periodStart, periodEnd and an optional
	 * ratePlanId).
	 *
	 * @return JSONResponse 200 with `created` and `refused`; 400, 401 or 404.
	 *
	 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
	 */
	#[NoAdminRequired]
	public function import(): JSONResponse {
		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		$refusal = $this->requireMember(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		$rows = $this->request->getParam('rows', []);
		if (is_array($rows) === false || $rows === [] || count($rows) > self::MAX_ROWS) {
			return new JSONResponse(['error' => 'rows must hold 1 to ' . self::MAX_ROWS . ' readings'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->importer->import(administrationId: $administrationId, rows: array_values($rows)));

	}//end import()

	/**
	 * Refuse an anonymous caller, and an administration the caller is not a member of.
	 *
	 * @param string $administrationId The administration from the request.
	 *
	 * @return JSONResponse|null 401 or 404, or null when the caller is a member.
	 */
	private function requireMember(string $administrationId): ?JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return null;

	}//end requireMember()
}//end class
