<?php

/**
 * Payroll Controller
 *
 * Read/compute API for the NL loonadministratie engine (REQ-PAY-001, REQ-PAY-011,
 * REQ-PAY-012). Exposes three GET endpoints that compute a payslip, the period
 * LH-afdracht aggregate and the balanced GL journal for one administration +
 * period. Every endpoint is available to any authenticated user (#[NoAdminRequired]);
 * the administrationId is validated and reads are delegated to OpenRegister's
 * ObjectService, which enforces multitenancy / RBAC, so no cross-administration
 * data leaks. No stack traces are returned to the client (ADR-005); BSN is never
 * echoed unmasked.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\PayrollService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Payroll compute endpoints (read-only, period-scoped).
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
class PayrollController extends Controller
{

    /**
     * Identifier pattern shared by all scope parameters (short slugs only).
     *
     * @var string
     */
    private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

    /**
     * Constructor for the PayrollController.
     *
     * @param IRequest        $request        The request object.
     * @param PayrollService  $payrollService The payroll computation service.
     * @param LoggerInterface $logger         Logger (no stack traces to client, no raw BSN).
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly PayrollService $payrollService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Compute one employee's payslip for a period (REQ-PAY-001, REQ-PAY-010).
     *
     * Query parameters: administration_id, werknemer_id, periode_id (all required).
     *
     * @return JSONResponse 200 with the LoonStrook payload; 400 on a bad param;
     *                       404 when a record is missing; 500 without a stack trace.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    #[NoAdminRequired]
    public function loonstrook(): JSONResponse
    {
        $administrationId = $this->scopeParam(name: 'administration_id');
        $werknemerId      = $this->scopeParam(name: 'werknemer_id');
        $periodeId        = $this->scopeParam(name: 'periode_id');

        $error = $this->firstBlank(
            values: [
                'administration_id' => $administrationId,
                'werknemer_id'      => $werknemerId,
                'periode_id'        => $periodeId,
            ]
        );
        if ($error !== null) {
            return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
        }

        try {
            $strook = $this->payrollService->berekenLoonStrook(
                administrationId: $administrationId,
                werknemerId: $werknemerId,
                periodeId: $periodeId
            );
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error(
                'PayrollController: failed to compute loonstrook',
                ['administrationId' => $administrationId, 'periodeId' => $periodeId, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Kon loonstrook niet berekenen'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

        return new JSONResponse($strook, Http::STATUS_OK);

    }//end loonstrook()

    /**
     * Compute the period LH-afdracht aggregate (REQ-PAY-011).
     *
     * Query parameters: administration_id, periode_id (required); eindheffingen_wkr (optional).
     *
     * @return JSONResponse 200 with the LHAfdracht payload; 400/500 as above.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    #[NoAdminRequired]
    public function lhAfdracht(): JSONResponse
    {
        $administrationId = $this->scopeParam(name: 'administration_id');
        $periodeId        = $this->scopeParam(name: 'periode_id');

        $error = $this->firstBlank(values: ['administration_id' => $administrationId, 'periode_id' => $periodeId]);
        if ($error !== null) {
            return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
        }

        $wkr = (float) $this->request->getParam('eindheffingen_wkr', 0);
        if ($wkr < 0.0) {
            $wkr = 0.0;
        }

        try {
            $afdracht = $this->payrollService->berekenLHAfdracht(
                administrationId: $administrationId,
                periodeId: $periodeId,
                eindheffingenWKR: $wkr
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'PayrollController: failed to compute LH-afdracht',
                ['administrationId' => $administrationId, 'periodeId' => $periodeId, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Kon LH-afdracht niet berekenen'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($afdracht, Http::STATUS_OK);

    }//end lhAfdracht()

    /**
     * Build the balanced GL journal for a period's payroll (REQ-PAY-012).
     *
     * Query parameters: administration_id, periode_id (required).
     *
     * @return JSONResponse 200 with the Loonjournaalpost payload; 400/500 as above.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    #[NoAdminRequired]
    public function journaalpost(): JSONResponse
    {
        $administrationId = $this->scopeParam(name: 'administration_id');
        $periodeId        = $this->scopeParam(name: 'periode_id');

        $error = $this->firstBlank(values: ['administration_id' => $administrationId, 'periode_id' => $periodeId]);
        if ($error !== null) {
            return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
        }

        try {
            $journaal = $this->payrollService->bouwLoonjournaalpost(
                administrationId: $administrationId,
                periodeId: $periodeId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'PayrollController: failed to build journaalpost',
                ['administrationId' => $administrationId, 'periodeId' => $periodeId, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Kon loonjournaalpost niet opbouwen'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($journaal, Http::STATUS_OK);

    }//end journaalpost()

    /**
     * Read and validate a scope parameter, returning '' when blank or malformed.
     *
     * @param string $name Query-parameter name.
     *
     * @return string The validated value or '' (blank/malformed).
     */
    private function scopeParam(string $name): string
    {
        $value = trim((string) $this->request->getParam($name, ''));
        if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
            return '';
        }

        return $value;

    }//end scopeParam()

    /**
     * Return a validation error message for the first blank/invalid scope value.
     *
     * @param array<string,string> $values Name => value map.
     *
     * @return string|null Error message or null when all values are valid.
     */
    private function firstBlank(array $values): ?string
    {
        foreach ($values as $name => $value) {
            if ($value === '') {
                return sprintf('%s is verplicht en moet een geldige identifier zijn', $name);
            }
        }

        return null;

    }//end firstBlank()
}//end class
