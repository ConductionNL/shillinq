<?php

/**
 * Subject Cost Report Controller
 *
 * The rows behind the two case cost tables: the Iv3 page's case costs per
 * taakveld (REQ-IV3-010) and the "Zaakkosten per zaaktype" page (REQ-IV3-012),
 * for one administration and one period. The rows come from SubjectCostReport,
 * the same sections the Iv3 and per-type CSVs are written from.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Reporting\CostSource\SubjectCostReport;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Answers the case cost rows of one administration and period.
 *
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
 */
class SubjectCostReportController extends Controller {

	/**
	 * A year, a quarter or a month, as ReportPeriod reads them.
	 */
	private const PERIOD = '/^\d{4}(-Q[1-4]|-(0[1-9]|1[0-2]))?$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request      The request.
	 * @param SubjectCostReport                     $subjectCosts The case cost sections.
	 * @param AdministrationContextServiceInterface $context      The caller and their administrations.
	 */
	public function __construct(
		IRequest $request,
		private readonly SubjectCostReport $subjectCosts,
		private readonly AdministrationContextServiceInterface $context,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * The rows for `period` (YYYY, YYYY-Qn or YYYY-MM), grouped per taakveld or,
	 * with `grouping=type`, per type. Without `administrationId` the caller's
	 * active administration is read; either way it must be one of theirs.
	 *
	 * @return JSONResponse `{rows: [{label, objects, hours, cost, incomplete, types}]}`;
	 *                      401 anonymous, 400 without a valid period or an
	 *                      administration, 404 for an administration not theirs.
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
	 */
	#[NoAdminRequired]
	public function rows(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'not-logged-in'], Http::STATUS_UNAUTHORIZED);
		}

		$period = trim((string)$this->request->getParam('period', ''));
		$administrationId = $this->administrationId();
		if (preg_match(self::PERIOD, $period) !== 1 || $administrationId === '') {
			return new JSONResponse(['error' => 'period and administration are required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		$perType = ((string)$this->request->getParam('grouping', '') === 'type');
		$sections = $this->subjectCosts->perTaskField(period: $period, administrationId: $administrationId);
		if ($perType === true) {
			$sections = $this->subjectCosts->perType(period: $period, administrationId: $administrationId);
		}

		return new JSONResponse(['rows' => $this->subjectCosts->tableRows(sections: $sections, perType: $perType)]);

	}//end rows()

	/**
	 * The requested administration, else the caller's active one, else ''.
	 *
	 * @return string
	 */
	private function administrationId(): string {
		$requested = trim((string)$this->request->getParam('administrationId', ''));
		if ($requested !== '') {
			return $requested;
		}

		try {
			return (string)($this->context->buildContext()['activeAdministrationId'] ?? '');
		} catch (Throwable $e) {
			return '';
		}

	}//end administrationId()
}//end class
