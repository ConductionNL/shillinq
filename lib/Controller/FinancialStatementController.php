<?php

/**
 * Financial Statement Controller
 *
 * GET /api/statements/profit-and-loss and GET /api/statements/balance-sheet:
 * the statements of one administration the caller belongs to, computed from
 * posted ledger lines (reporting-financial-statements REQ-RFS-001, REQ-RFS-002).
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
 * @spec openspec/changes/reporting-financial-statements
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use Closure;
use InvalidArgumentException;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Reporting\ProfitLossComparison;
use OCA\Shillinq\Reporting\StatementCalculator;
use OCA\Shillinq\Reporting\StatementPeriods;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read endpoints for the profit and loss and the balance sheet pages.
 *
 * Both answer 401 without a user, 400 without an administration or with a
 * malformed date, and a masked 404 for an administration the caller does not
 * belong to, before anything is read.
 *
 * @spec openspec/changes/reporting-financial-statements
 */
class FinancialStatementController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request    The request.
	 * @param AdministrationContextService $context    Who may read which administration.
	 * @param StatementCalculator          $calculator The statement figures.
	 * @param ProfitLossComparison         $comparison The compared profit and loss.
	 * @param LoggerInterface              $logger     PSR logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextService $context,
		private readonly StatementCalculator $calculator,
		private readonly ProfitLossComparison $comparison,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The profit and loss of `from` to `to`, compared as `compare` asks.
	 *
	 * @return JSONResponse The statement, or an error.
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	#[NoAdminRequired]
	public function profitAndLoss(): JSONResponse {
		return $this->answer(
			compute: function (string $administrationId): array {
				$from = (string)$this->request->getParam('from', '');
				$to = (string)$this->request->getParam('to', '');
				StatementPeriods::assertRange(from: $from, to: $to);
				$comparisons = StatementPeriods::comparisons(compare: (string)$this->request->getParam('compare', ''));

				return $this->comparison->compare(
					administrationId: $administrationId,
					from: $from,
					to: $to,
					comparisons: $comparisons
				);
			}
		);
	}//end profitAndLoss()

	/**
	 * The balance sheet at the end of `asOf`.
	 *
	 * @return JSONResponse The statement, or an error.
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	#[NoAdminRequired]
	public function balanceSheet(): JSONResponse {
		return $this->answer(
			compute: function (string $administrationId): array {
				$asOf = (string)$this->request->getParam('asOf', '');
				StatementPeriods::date(date: $asOf, field: 'asOf');

				return $this->calculator->balancesAsOf(administrationId: $administrationId, asOf: $asOf);
			}
		);
	}//end balanceSheet()

	/**
	 * Check the caller and the administration, then compute.
	 *
	 * @param Closure(string): array<string, mixed> $compute The statement for an administration.
	 *
	 * @return JSONResponse The statement, or an error.
	 */
	private function answer(Closure $compute): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->administrationId();
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'No administration selected'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			return new JSONResponse($compute($administrationId));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('FinancialStatementController: statement failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Statement unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end answer()

	/**
	 * The administration asked for, else the caller's active one.
	 *
	 * @return string The administration id, or an empty string.
	 */
	private function administrationId(): string {
		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		if ($administrationId !== '') {
			return $administrationId;
		}

		return trim((string)($this->context->buildContext()['activeAdministrationId'] ?? ''));
	}//end administrationId()
}//end class
