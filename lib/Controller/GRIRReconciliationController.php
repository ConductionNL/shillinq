<?php

/**
 * GR/IR Reconciliation Controller
 *
 * Change revive-gl-tax-capabilities (shillinq#424 / #446): the missing
 * operator surface for the GR/IR period-end reconciliation.
 * {@see \OCA\Shillinq\Service\GRIRClearingService::reconcileGRIRSaldoForPeriod()}
 * computes the GR/IR clearing-account saldo for a fiscal period and flags
 * dangling goods-in-transit (a non-zero saldo = goods received but never
 * invoiced) — REQ-PO3W-009's period-end control. The grir-accrual-wiring
 * change wired the two POSTING methods on the same class but deliberately
 * left this one out of scope, and it has had no route, no controller and no
 * CLI command since: the class is dependency-injected, but this method is
 * never called. Class-injected is not method-called.
 *
 * This endpoint gives the control a user-reachable surface.
 *
 * Every endpoint is `#[NoAdminRequired]` with a per-administration IDOR
 * guard in the controller (ADR-005); a cross-tenant administration is masked
 * as 404.
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
 * @spec openspec/changes/revive-gl-tax-capabilities/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\GRIRClearingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * GR/IR period-end reconciliation endpoint (REQ-GLTAX-003).
 *
 * @spec openspec/changes/revive-gl-tax-capabilities/specs/revive-gl-tax-capabilities/spec.md
 */
class GRIRReconciliationController extends Controller
{

    /**
     * Short-slug identifier pattern shared by every scope/path parameter.
     *
     * @var string
     */
    private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

    /**
     * Constructor.
     *
     * @param IRequest                     $request               The request object.
     * @param GRIRClearingService          $grirClearingService   The GR/IR clearing service.
     * @param AdministrationContextService $administrationContext IDOR + tenant scope (ADR-005).
     * @param IUserSession                 $userSession           User-session guard.
     * @param LoggerInterface              $logger                Logger (no stack traces to the client).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.LongVariable) administrationContext is the
     * canonical name fleet-wide.
     */
    public function __construct(
        IRequest $request,
        private readonly GRIRClearingService $grirClearingService,
        private readonly AdministrationContextService $administrationContext,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Read the GR/IR clearing-account saldo for a fiscal period (REQ-PO3W-009).
     *
     * GET /api/gr-ir/saldo?administrationId=…&periodId=…
     *
     * A zero saldo means every goods receipt in the period has a matching
     * approved invoice; a non-zero saldo is dangling goods-in-transit the
     * operator must investigate before the period can be closed.
     *
     * @return JSONResponse 200 with {periodId, clearingAccount, debitCents,
     *                      creditCents, saldoCents, balanced}; 400 on
     *                      validation; 401 anonymous; 404 cross-tenant; 500
     *                      without a stack trace.
     *
     * @spec openspec/changes/revive-gl-tax-capabilities/specs/revive-gl-tax-capabilities/spec.md
     */
    #[NoAdminRequired]
    public function saldo(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        $administrationId = $this->scopeParam(name: 'administrationId');
        if ($administrationId === '') {
            return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
            return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
        }

        $periodId = $this->scopeParam(name: 'periodId');
        if ($periodId === '') {
            return new JSONResponse(['error' => 'periodId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $saldo = $this->grirClearingService->reconcileGRIRSaldoForPeriod(
                administrationId: $administrationId,
                periodId: $periodId
            );
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found') === true) {
                return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error(
                'GRIRReconciliationController: failed to compute the GR/IR saldo',
                [
                    'administrationId' => $administrationId,
                    'periodId'         => $periodId,
                    'exception'        => $e->getMessage(),
                ]
            );
            return new JSONResponse(
                ['error' => 'Could not compute the GR/IR saldo'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        return new JSONResponse($saldo, Http::STATUS_OK);

    }//end saldo()

    /**
     * Read + validate a scope parameter; '' when blank/malformed.
     *
     * @param string $name Parameter name.
     *
     * @return string
     */
    private function scopeParam(string $name): string
    {
        $value = trim((string) $this->request->getParam($name, ''));
        if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
            return '';
        }

        return $value;

    }//end scopeParam()
}//end class
