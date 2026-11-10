<?php

/**
 * Administration Results Controller
 *
 * GET /api/budget-line-commitments, /api/budget-line-commitments/lines and
 * /api/segment-results: commitment and segment figures for one
 * administration the caller belongs to.
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
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AdministrationResultsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves commitment and segment figures scoped to one administration.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
class AdministrationResultsController extends Controller {

	/**
	 * A FiscalPeriod periodId such as 2026-M09 or 2026-Q3.
	 *
	 * @var string
	 */
	private const PERIOD_PATTERN = '/^[A-Za-z0-9_.-]{1,32}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request The request.
	 * @param AdministrationContextService $context The caller's administrations.
	 * @param AdministrationResultsService $results The figures.
	 * @param LoggerInterface              $logger  The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AdministrationContextService $context,
		private readonly AdministrationResultsService $results,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET /api/budget-line-commitments?administrationId=
	 *
	 * @return JSONResponse The aggregation envelope, 400, 401, a masked 404 or 500.
	 *
	 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
	 */
	#[NoAdminRequired]
	public function commitments(): JSONResponse {
		return $this->answer(
			build: fn (string $administrationId): array => $this->results->commitmentsPerBudgetLine(administrationId: $administrationId)
		);

	}//end commitments()

	/**
	 * GET /api/budget-line-commitments/lines?programme=&costCentre=&financialYear=&generalLedgerAccount=
	 *
	 * @return JSONResponse `{results, total}`, 400, 401, a masked 404 or 500.
	 *
	 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
	 */
	#[NoAdminRequired]
	public function commitmentLines(): JSONResponse {
		return $this->answer(
			build: function (string $administrationId): array {
				$budgetLine = [];
				foreach (AdministrationResultsService::COMMITMENT_LINE_FILTERS as $property) {
					$budgetLine[$property] = (string)$this->request->getParam($property, '');
				}

				$lines = $this->results->commitmentLines(administrationId: $administrationId, budgetLine: $budgetLine);
				return ['results' => $lines, 'total' => count($lines)];
			}
		);

	}//end commitmentLines()

	/**
	 * GET /api/segment-results?segment=&periodId=&administrationId=
	 *
	 * @return JSONResponse The aggregation envelope plus `periods`, 400, 401, a masked 404 or 500.
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
	 */
	#[NoAdminRequired]
	public function segments(): JSONResponse {
		$periodId = trim((string)$this->request->getParam('periodId', ''));
		if ($periodId !== '' && preg_match(self::PERIOD_PATTERN, $periodId) !== 1) {
			return new JSONResponse(['error' => 'Invalid period'], Http::STATUS_BAD_REQUEST);
		}

		$segment = (string)$this->request->getParam('segment', 'costCenter');

		return $this->answer(
			build: function (string $administrationId) use ($segment, $periodId): array {
				$envelope = $this->results->segmentResults(
					administrationId: $administrationId,
					segment: $segment,
					periodId: $periodId
				);
				$envelope['periods'] = $this->results->fiscalPeriods(administrationId: $administrationId);
				return $envelope;
			}
		);

	}//end segments()

	/**
	 * Resolve the administration, check membership, then build the body.
	 *
	 * The administration defaults to the one the caller works in. One the
	 * caller is not a member of is masked as absent (ADR-005).
	 *
	 * @param callable(string): array<string, mixed> $build Builds the body for the administration.
	 *
	 * @return JSONResponse The response.
	 */
	private function answer(callable $build): JSONResponse {
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
			return new JSONResponse($build($administrationId));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('AdministrationResultsController: request failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Figures unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end answer()
}//end class
