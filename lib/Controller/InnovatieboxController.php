<?php

/**
 * Innovatiebox Controller
 *
 * Read-only innovatiebox-administratie API (REQ-IBA-006, REQ-IBA-009,
 * REQ-IBA-004). Exposes the per-asset Vpb roll-up, a non-persisting nexus
 * scenario calculator, and the doorsnijdingsverbod year-end check. Every method
 * is available to any authenticated user (#[NoAdminRequired]); the
 * administration scope is validated and reads are delegated to OpenRegister's
 * ObjectService, which enforces multitenancy / RBAC, so no cross-administration
 * data leaks (REQ-IBA-008). These endpoints never mutate innovatiebox records —
 * registration/calculation writes go through the OpenRegister object UI under
 * the audit-trail-immutable schemas.
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
 * @spec openspec/changes/bookkeeping-innovatiebox-administratie/tasks.md#task-7-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\DoorsnijdingsVerbodValidator;
use OCA\Shillinq\Service\InnovatieboxAggregationService;
use OCA\Shillinq\Service\NexusCalculationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Read-only innovatiebox-administratie endpoints (REQ-IBA-006/009/004).
 *
 * @spec openspec/changes/bookkeeping-innovatiebox-administratie/tasks.md#task-7-1
 */
class InnovatieboxController extends Controller
{
    /**
     * Identifier validation pattern (short slugs only; blocks path traversal).
     *
     * @var string
     */
    private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

    /**
     * Constructor for the InnovatieboxController.
     *
     * @param IRequest                       $request     The request object.
     * @param InnovatieboxAggregationService $aggregation The per-asset Vpb roll-up service.
     * @param NexusCalculationService        $nexus       The nexus arithmetic helper (scenario).
     * @param DoorsnijdingsVerbodValidator   $doorsnijden The doorsnijdingsverbod validator.
     * @param LoggerInterface                $logger      Logger (no stack traces to client).
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly InnovatieboxAggregationService $aggregation,
        private readonly NexusCalculationService $nexus,
        private readonly DoorsnijdingsVerbodValidator $doorsnijden,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the per-asset innovatiebox roll-up for a year (REQ-IBA-006).
     *
     * Query parameters:
     *  - administration_id (required) administration scope (REQ-IBA-008).
     *  - boekjaar          (required) fiscal year (4-digit).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-innovatiebox-administratie/tasks.md#task-7-1
     */
    #[NoAdminRequired]
    public function aggregation(): JSONResponse
    {
        $administrationId = trim((string) $this->request->getParam('administration_id', ''));
        $boekjaar         = trim((string) $this->request->getParam('boekjaar', ''));

        $error = $this->requireAdministration(administrationId: $administrationId);
        if ($error !== null) {
            return $error;
        }

        $yearError = $this->requireYear(boekjaar: $boekjaar);
        if ($yearError !== null) {
            return $yearError;
        }

        try {
            $result = $this->aggregation->aggregate(
                administrationId: $administrationId,
                boekjaar: (int) $boekjaar
            );
        } catch (\Throwable $e) {
            return $this->fail(
                message: 'Failed to compute innovatiebox aggregation',
                context: [
                    'administrationId' => $administrationId,
                    'boekjaar'         => $boekjaar,
                    'exception'        => $e->getMessage(),
                ]
            );
        }

        return new JSONResponse($result, Http::STATUS_OK);

    }//end aggregation()

    /**
     * Recalculate a nexus break for a what-if scenario without persisting (REQ-IBA-009).
     *
     * Query parameters (all required, numeric, >= 0):
     *  - eigen_rd_kosten, uitbesteed_derden, uitbesteed_verbonden.
     *
     * Returns the applied nexusbreuk and the full breakdown; no records are
     * modified. Used by the scenario planner ("what if I outsource EUR X to a
     * related party?").
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-innovatiebox-administratie/tasks.md#task-7-1
     */
    #[NoAdminRequired]
    public function scenario(): JSONResponse
    {
        $eigen     = $this->request->getParam('eigen_rd_kosten', null);
        $derden    = $this->request->getParam('uitbesteed_derden', null);
        $verbonden = $this->request->getParam('uitbesteed_verbonden', null);

        foreach (['eigen_rd_kosten' => $eigen, 'uitbesteed_derden' => $derden, 'uitbesteed_verbonden' => $verbonden] as $name => $value) {
            if ($value === null || is_numeric($value) === false || (float) $value < 0.0) {
                return new JSONResponse(
                    ['error' => $name.' must be a non-negative number'],
                    Http::STATUS_BAD_REQUEST
                );
            }
        }

        $result = $this->nexus->calculateNexusBreak(
            eigenRdKosten: (float) $eigen,
            uitbesteedDerden: (float) $derden,
            uitbesteedVerbonden: (float) $verbonden
        );

        return new JSONResponse($result, Http::STATUS_OK);

    }//end scenario()

    /**
     * Run the doorsnijdingsverbod year-end check (REQ-IBA-004).
     *
     * Query parameters:
     *  - administration_id (required) administration scope.
     *  - boekjaar          (required) fiscal year.
     *
     * Returns the duplication findings and whether the year-end close is blocked.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-innovatiebox-administratie/tasks.md#task-7-1
     */
    #[NoAdminRequired]
    public function doorsnijdingsverbod(): JSONResponse
    {
        $administrationId = trim((string) $this->request->getParam('administration_id', ''));
        $boekjaar         = trim((string) $this->request->getParam('boekjaar', ''));

        $error = $this->requireAdministration(administrationId: $administrationId);
        if ($error !== null) {
            return $error;
        }

        $yearError = $this->requireYear(boekjaar: $boekjaar);
        if ($yearError !== null) {
            return $yearError;
        }

        try {
            $result = $this->doorsnijden->validateNoDuplication(
                administrationId: $administrationId,
                boekjaar: (int) $boekjaar
            );
        } catch (\Throwable $e) {
            return $this->fail(
                message: 'Failed to run doorsnijdingsverbod check',
                context: [
                    'administrationId' => $administrationId,
                    'boekjaar'         => $boekjaar,
                    'exception'        => $e->getMessage(),
                ]
            );
        }

        return new JSONResponse($result, Http::STATUS_OK);

    }//end doorsnijdingsverbod()

    /**
     * Validate the administration_id parameter (REQ-IBA-008).
     *
     * @param string $administrationId The raw parameter value.
     *
     * @return JSONResponse|null A 400 response when invalid, null when valid.
     */
    private function requireAdministration(string $administrationId): ?JSONResponse
    {
        if ($administrationId === '') {
            return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
        }

        if (preg_match(self::ID_PATTERN, $administrationId) !== 1) {
            return new JSONResponse(['error' => 'administration_id must be a valid administration identifier'], Http::STATUS_BAD_REQUEST);
        }

        return null;

    }//end requireAdministration()

    /**
     * Validate the boekjaar parameter (4-digit year).
     *
     * @param string $boekjaar The raw parameter value.
     *
     * @return JSONResponse|null A 400 response when invalid, null when valid.
     */
    private function requireYear(string $boekjaar): ?JSONResponse
    {
        if ($boekjaar === '' || preg_match('/^\d{4}$/', $boekjaar) !== 1) {
            return new JSONResponse(['error' => 'boekjaar must be a 4-digit fiscal year'], Http::STATUS_BAD_REQUEST);
        }

        return null;

    }//end requireYear()

    /**
     * Log a failure and return a 500 without leaking a stack trace (ADR-005).
     *
     * @param string              $message Client-facing error message.
     * @param array<string,mixed> $context Structured log context.
     *
     * @return JSONResponse
     */
    private function fail(string $message, array $context): JSONResponse
    {
        $this->logger->error('InnovatieboxController: '.$message, $context);

        return new JSONResponse(['error' => $message], Http::STATUS_INTERNAL_SERVER_ERROR);

    }//end fail()
}//end class
