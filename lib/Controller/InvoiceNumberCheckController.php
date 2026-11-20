<?php

/**
 * Invoice Number Check Controller
 *
 * GET /api/sales/invoice-number-check: the missing and duplicate numbers of a
 * year of the active (or named) administration's issued sales invoices
 * (REQ-SIIC-004), for the invoice number check on the Reports page.
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
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\InvoiceNumberAuditService;
use OCA\Shillinq\Service\Sales\InvoiceNumberSequenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Serves the invoice number check.
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-3.1
 */
class InvoiceNumberCheckController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request   The request.
	 * @param InvoiceNumberAuditService             $audit     Lists gaps and duplicates.
	 * @param InvoiceNumberSequenceService          $sequences The series pattern of the administration.
	 * @param AdministrationContextServiceInterface $context   The caller's administrations.
	 * @param LoggerInterface                       $logger    Logger.
	 * @param IL10N                                 $l10n      Translates the finding of a row.
	 */
	public function __construct(
		IRequest $request,
		private readonly InvoiceNumberAuditService $audit,
		private readonly InvoiceNumberSequenceService $sequences,
		private readonly AdministrationContextServiceInterface $context,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The invoice number check of one year.
	 *
	 * @return JSONResponse The report, with `missingCount`, `duplicateCount` and one row per finding.
	 *
	 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-3.1
	 */
	#[NoAdminRequired]
	public function check(): JSONResponse {
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

		// ADR-005: an administration the caller is not a member of is masked as absent.
		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$year = $this->year();
		if ($year === null) {
			return new JSONResponse(['error' => 'The year must be four digits.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$report = $this->audit->gaps(
				administrationId: $administrationId,
				year: $year,
				pattern: $this->sequences->patternOf(administrationId: $administrationId, kind: InvoiceNumberSequenceService::KIND_SALES)
			);
		} catch (\Throwable $e) {
			$this->logger->error('InvoiceNumberCheckController: the check failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The invoice number check is unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$report['administrationId'] = $administrationId;
		$report['missingCount'] = count($report['missing']);
		$report['duplicateCount'] = count($report['duplicates']);
		// One row per finding, for the page's table.
		$report['rows'] = array_merge(
			array_map(fn (string $number): array => ['number' => $number, 'finding' => $this->l10n->t('Missing')], $report['missing']),
			array_map(fn (string $number): array => ['number' => $number, 'finding' => $this->l10n->t('Duplicate')], $report['duplicates'])
		);

		return new JSONResponse($report);
	}//end check()

	/**
	 * The requested year, this year when none is given, null when it is malformed.
	 *
	 * @return int|null The year.
	 */
	private function year(): ?int {
		$year = trim((string)$this->request->getParam('year', ''));
		if ($year === '') {
			return (int)(new DateTimeImmutable())->format('Y');
		}

		if (preg_match('/^\d{4}$/', $year) !== 1) {
			return null;
		}

		return (int)$year;
	}//end year()
}//end class
