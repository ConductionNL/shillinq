<?php

/**
 * Spend Analytics Controller
 *
 * Read-only API for single-dimension spend analysis over the Accounts-Payable
 * sub-ledger. `GET /api/analytics/spend?dimension=<supplier|category|
 * costCentre|period>` returns `{ dimension, label, groups:[{key,amount}],
 * total, backend }`. Every read is delegated to OpenRegister's aggregation-api
 * (`AggregationRunner::runAdhocByRef`, ADR-022), which enforces list-RBAC and
 * the active-organisation multi-tenant predicate server-side — the endpoint
 * exposes exactly the aggregate the caller could already compute over the
 * objects they may read. There is no create/update/delete route and no
 * client-supplied object identifier (no IDOR surface); the only input is the
 * closed `dimension` enum, validated before dispatch.
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
 * @spec openspec/changes/spend-analytics/specs/spend-analytics/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SpendAnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GET /api/analytics/spend?dimension=...
 *
 * @spec openspec/changes/spend-analytics/specs/spend-analytics/spec.md
 */
class SpendAnalyticsController extends Controller
{
    /**
     * The closed set of supported spend dimensions.
     *
     * @var string[]
     */
    private const DIMENSIONS = [
        'supplier',
        'category',
        'costCentre',
        'period',
    ];

    /**
     * Constructor for the SpendAnalyticsController.
     *
     * @param IRequest                     $request The request object.
     * @param SpendAnalyticsService        $service The spend-aggregation service (consumes OR aggregation-api).
     * @param AdministrationContextService $context Authenticated-user context (ADR-005).
     * @param IL10N                        $l10n    Translation of the human-readable dimension label.
     * @param LoggerInterface              $logger  Logger for diagnostics (no stack traces to client).
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly SpendAnalyticsService $service,
        private readonly AdministrationContextService $context,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Single-dimension spend analysis.
     *
     * Query parameters:
     *  - dimension (required) one of supplier|category|costCentre|period.
     *
     * Returns HTTP 200 with { dimension, label, groups:[{key,amount}], total,
     * backend }; HTTP 400 on an unknown dimension; HTTP 401 when anonymous;
     * HTTP 500 without a stack trace on an unexpected failure.
     *
     * @return JSONResponse The spend payload or an error envelope.
     *
     * @spec openspec/changes/spend-analytics/specs/spend-analytics/spec.md
     */
    #[NoAdminRequired]
    public function spend(): JSONResponse
    {
        // Authentication gate (ADR-005) — the data layer relies on a resolved
        // user for OR's RBAC scoping.
        if ($this->context->currentUserId() === null) {
            return new JSONResponse(
                ['error' => 'Not authenticated'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $dimension = trim((string) $this->request->getParam('dimension', ''));
        if (in_array($dimension, self::DIMENSIONS, true) === false) {
            return new JSONResponse(
                ['error' => 'dimension must be one of: '.implode(', ', self::DIMENSIONS)],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result          = $this->dispatch(dimension: $dimension);
            $result['label'] = $this->label(dimension: $dimension);
        } catch (\Throwable $e) {
            $this->logger->error(
                'SpendAnalyticsController: failed to compute spend-by-'.$dimension,
                ['exception' => $e->getMessage()]
            );

            return new JSONResponse(
                ['error' => 'Failed to compute spend analysis'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse($result, Http::STATUS_OK);

    }//end spend()

    /**
     * Dispatch to the matching service method for the validated dimension.
     *
     * @param string $dimension The validated dimension.
     *
     * @return array<string,mixed> The service payload.
     */
    private function dispatch(string $dimension): array
    {
        switch ($dimension) {
            case 'supplier':
                return $this->service->spendBySupplier();
            case 'category':
                return $this->service->spendByCategory();
            case 'costCentre':
                return $this->service->spendByCostCentre();
            default:
                return $this->service->spendByPeriod();
        }

    }//end dispatch()

    /**
     * Human-readable, translated label for the dimension (i18n EN + NL).
     *
     * @param string $dimension The validated dimension.
     *
     * @return string The translated label.
     */
    private function label(string $dimension): string
    {
        switch ($dimension) {
            case 'supplier':
                return $this->l10n->t('Spend by supplier');
            case 'category':
                return $this->l10n->t('Spend by category');
            case 'costCentre':
                return $this->l10n->t('Spend by cost centre');
            default:
                return $this->l10n->t('Spend by period');
        }

    }//end label()
}//end class
