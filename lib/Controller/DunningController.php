<?php

/**
 * Dunning Controller
 *
 * Tier-2 credit-control & dunning ladder API (REQ-CCD-002 .. REQ-CCD-010).
 * Exposes a handful of imperative endpoints layered on top of the declarative
 * registers + the DunningRunService orchestrator:
 *
 *  - POST /api/dunning/bik           — compute the IncassoKostenBerekening for
 *                                      an arbitrary hoofdsom + partyType
 *                                      (REQ-CCD-003). Pure compute, no write.
 *  - POST /api/dunning/runs/execute  — execute a single DunningRun stage
 *                                      (REQ-CCD-002 / 016). Refuses when a
 *                                      DunningPauseDispute is active.
 *  - POST /api/dunning/pauses        — create a DunningPauseDispute (REQ-CCD-004).
 *  - POST /api/dunning/pauses/{id}/resume — resolve / expire the pause.
 *  - POST /api/dunning/writeoffs     — record an OninbaarAfschrijving (REQ-CCD-010).
 *  - POST /api/dunning/incasso/dossier — assemble the stage-5 dossier bundle
 *                                      (REQ-CCD-008).
 *
 * Every route is admin-scope guarded via AdministrationContextService so the
 * server-resolved administration_id is the only trust boundary; no client-
 * supplied tenant id is honoured directly.
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BIKStaffelCalculator;
use OCA\Shillinq\Service\Dunning\IncassoDossierComposer;
use OCA\Shillinq\Service\DunningRunService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Dunning API surface.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic; early-return refactor deferred pending full behavioral
 * verification of each branch.
 */
class DunningController extends Controller
{
    /**
     * Wire the dunning controller dependencies.
     *
     * @param IRequest                     $request NC request.
     * @param BIKStaffelCalculator         $bik     Pure BIK + rente calculator.
     * @param DunningRunService            $runs    Run orchestrator.
     * @param IncassoDossierComposer       $dossier Stage-5 dossier composer.
     * @param AdministrationContextService $context Admin-scope context.
     * @param LoggerInterface              $logger  Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly BIKStaffelCalculator $bik,
        private readonly DunningRunService $runs,
        private readonly IncassoDossierComposer $dossier,
        private readonly AdministrationContextService $context,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Compute the IncassoKostenBerekening for a hypothetical hoofdsom.
     *
     * Body JSON: { hoofdsom, partyType, dagenVerzuim, administration_id,
     *              ingangsdatum?, berekendOp?, tariefB2B?, tariefB2C? }.
     *
     * @return JSONResponse 200 with the calc shape; 400 on validation; 422 when
     *                      B2C is below the 14-day grace cut-off.
     *
     * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
     */
    #[NoAdminRequired]
    public function bik(): JSONResponse
    {
        if ($this->context->currentUserId() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $administrationId = trim((string) $this->request->getParam('administration_id', ''));
        if ($administrationId === '') {
            return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
        }

        if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
            return new JSONResponse(['error' => 'administration_id must be a valid identifier'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $allowed = $this->context->canAccess(administrationId: $administrationId);
        } catch (\Throwable $e) {
            $this->logger->error('DunningController: admin access check failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($allowed === false) {
            return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
        }

        $hoofdsom     = (float) $this->request->getParam('hoofdsom', 0.0);
        $partyType    = (string) $this->request->getParam('partyType', 'B2B');
        $dagenVerzuim = (int) $this->request->getParam('dagenVerzuim', 0);
        $factuurId    = (string) $this->request->getParam('invoiceId', '');
        $ingangsRaw   = (string) $this->request->getParam('ingangsdatum', '');
        $berekendRaw  = (string) $this->request->getParam('calculatedOn', '');
        $tariefB2B    = $this->request->getParam('tariefB2B');
        $tariefB2C    = $this->request->getParam('tariefB2C');

        if ($hoofdsom < 0) {
            return new JSONResponse(['error' => 'hoofdsom must be non-negative'], Http::STATUS_BAD_REQUEST);
        }

        if (in_array($partyType, ['B2B', 'B2C', 'GOVERNMENT'], true) === false) {
            return new JSONResponse(['error' => 'partyType must be one of B2B/B2C/GOVERNMENT'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->bik->isCalculationPermitted(partyType: $partyType, dagenVerzuim: $dagenVerzuim) === false) {
            return new JSONResponse(
                [
                    'error' => 'B2C incassokostenberekening niet toegestaan voor dag 44 (14-dagenperiode per art. 6:96 BW)',
                    'code'  => 'B2C_GRACE_PERIOD',
                ],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        try {
            if ($ingangsRaw === '') {
                $ingangsdatum = new DateTimeImmutable();
            } else {
                $ingangsdatum = new DateTimeImmutable($ingangsRaw);
            }

            if ($berekendRaw === '') {
                $berekendOp = new DateTimeImmutable();
            } else {
                $berekendOp = new DateTimeImmutable($berekendRaw);
            }
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => 'Invalid ingangsdatum / berekendOp'], Http::STATUS_BAD_REQUEST);
        }

        $tariefB2BValue = null;
        if ($tariefB2B !== null) {
            $tariefB2BValue = (float) $tariefB2B;
        }

        $tariefB2CValue = null;
        if ($tariefB2C !== null) {
            $tariefB2CValue = (float) $tariefB2C;
        }

        $body = $this->bik->compose(
            factuurId: $factuurId,
            administrationId: $administrationId,
            partyType: $partyType,
            hoofdsom: $hoofdsom,
            ingangsdatum: $ingangsdatum,
            berekendOp: $berekendOp,
            tariefB2B: $tariefB2BValue,
            tariefB2C: $tariefB2CValue,
        );

        return new JSONResponse($body, Http::STATUS_OK);

    }//end bik()

    /**
     * Execute one DunningRun (stage dispatch + evidence capture).
     *
     * @return JSONResponse 201 with the persisted DunningRun; 400 on validation;
     *                      409 when the invoice is paused or admin-error detected.
     *
     * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
     */
    #[NoAdminRequired]
    public function executeRun(): JSONResponse
    {
        if ($this->context->currentUserId() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $administrationId = trim((string) $this->request->getParam('administration_id', ''));
        if ($administrationId === '') {
            return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
        }

        if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
            return new JSONResponse(['error' => 'administration_id must be a valid identifier'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $allowed = $this->context->canAccess(administrationId: $administrationId);
        } catch (\Throwable $e) {
            $this->logger->error('DunningController: admin access check failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($allowed === false) {
            return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
        }

        $params = $this->request->getParams();
        if (($params['invoiceId'] ?? '') === '') {
            return new JSONResponse(['error' => 'factuurId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $persisted = $this->runs->executeStage(administrationId: $administrationId, params: $params);
        } catch (\Throwable $e) {
            $this->logger->info('Shillinq: executeRun rejected: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        }

        return new JSONResponse($persisted, Http::STATUS_CREATED);

    }//end executeRun()

    /**
     * Create a DunningPauseDispute (REQ-CCD-004).
     *
     * @return JSONResponse 201 with the persisted pause; 400 on validation.
     *
     * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
     */
    #[NoAdminRequired]
    public function pause(): JSONResponse
    {
        if ($this->context->currentUserId() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $administrationId = trim((string) $this->request->getParam('administration_id', ''));
        if ($administrationId === '') {
            return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
        }

        if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
            return new JSONResponse(['error' => 'administration_id must be a valid identifier'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $allowed = $this->context->canAccess(administrationId: $administrationId);
        } catch (\Throwable $e) {
            $this->logger->error('DunningController: admin access check failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($allowed === false) {
            return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
        }

        $factuurId    = (string) $this->request->getParam('invoiceId', '');
        $reden        = (string) $this->request->getParam('reason', '');
        $details      = (string) $this->request->getParam('details', '');
        $byUser       = (string) $this->context->currentUserId();
        $evidenceRefs = $this->request->getParam('evidenceRefs');

        if ($factuurId === '' || in_array($reden, ['DISPUTED', 'PAYMENT_PLAN', 'OTHER'], true) === false) {
            return new JSONResponse(['error' => 'factuurId + valid reden are required'], Http::STATUS_BAD_REQUEST);
        }

        $evidenceRefsValue = null;
        if (is_array($evidenceRefs) === true) {
            $evidenceRefsValue = $evidenceRefs;
        }

        try {
            $persisted = $this->runs->pause(
                administrationId: $administrationId,
                factuurId: $factuurId,
                reden: $reden,
                details: $details,
                gepauzeerdDoor: $byUser,
                evidenceRefs: $evidenceRefsValue,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: pause failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Failed to create dunning pause'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($persisted, Http::STATUS_CREATED);

    }//end pause()

    /**
     * Resume a DunningPauseDispute.
     *
     * @param string $pauseId The pause record id.
     *
     * @return JSONResponse 200 with the updated pause; 404 when not found.
     *
     * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
     */
    #[NoAdminRequired]
    public function resumePause(string $pauseId): JSONResponse
    {
        if ($this->context->currentUserId() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $administrationId = trim((string) $this->request->getParam('administration_id', ''));
        if ($administrationId === '') {
            return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
        }

        if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
            return new JSONResponse(['error' => 'administration_id must be a valid identifier'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $allowed = $this->context->canAccess(administrationId: $administrationId);
        } catch (\Throwable $e) {
            $this->logger->error('DunningController: admin access check failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($allowed === false) {
            return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
        }

        $resolution = (string) $this->request->getParam('resolution', 'resolve');
        $partial    = $this->request->getParam('partialSettlement');
        if (in_array($resolution, ['resolve', 'expire'], true) === false) {
            return new JSONResponse(['error' => 'resolution must be resolve or expire'], Http::STATUS_BAD_REQUEST);
        }

        $partialSettlement = null;
        if ($partial !== null) {
            $partialSettlement = (float) $partial;
        }

        try {
            $persisted = $this->runs->resumePause(
                administrationId: $administrationId,
                pauseId: $pauseId,
                resolution: $resolution,
                partialSettlement: $partialSettlement,
            );
        } catch (\Throwable $e) {
            $this->logger->info('Shillinq: resumePause failed: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($persisted, Http::STATUS_OK);

    }//end resumePause()

    /**
     * Record an OninbaarAfschrijving (REQ-CCD-010).
     *
     * @return JSONResponse 201 with the persisted write-off.
     *
     * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
     */
    #[NoAdminRequired]
    public function writeOff(): JSONResponse
    {
        if ($this->context->currentUserId() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $administrationId = trim((string) $this->request->getParam('administration_id', ''));
        if ($administrationId === '') {
            return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
        }

        if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
            return new JSONResponse(['error' => 'administration_id must be a valid identifier'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $allowed = $this->context->canAccess(administrationId: $administrationId);
        } catch (\Throwable $e) {
            $this->logger->error('DunningController: admin access check failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($allowed === false) {
            return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
        }

        $params = $this->request->getParams();
        if (($params['invoiceId'] ?? '') === ''
            || ($params['art29OBDeclaration'] ?? '') === ''
            || ((float) ($params['hoofdsomAfgeschreven'] ?? 0)) <= 0
        ) {
            return new JSONResponse(
                ['error' => 'factuurId, hoofdsomAfgeschreven (>0) and art29OBVerklaring are required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $persisted = $this->runs->writeOff(administrationId: $administrationId, params: $params);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: writeOff failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Failed to record write-off'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($persisted, Http::STATUS_CREATED);

    }//end writeOff()

    /**
     * Compose the stage-5 incasso dossier bundle (REQ-CCD-008).
     *
     * @return JSONResponse 200 with the dossier; 400 on validation.
     *
     * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
     */
    #[NoAdminRequired]
    public function dossier(): JSONResponse
    {
        if ($this->context->currentUserId() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $administrationId = trim((string) $this->request->getParam('administration_id', ''));
        if ($administrationId === '') {
            return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
        }

        if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
            return new JSONResponse(['error' => 'administration_id must be a valid identifier'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $allowed = $this->context->canAccess(administrationId: $administrationId);
        } catch (\Throwable $e) {
            $this->logger->error('DunningController: admin access check failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($allowed === false) {
            return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
        }

        $factuurId = (string) $this->request->getParam('invoiceId', '');
        $klantId   = (string) $this->request->getParam('customerId', '');
        if ($factuurId === '' || $klantId === '') {
            return new JSONResponse(['error' => 'factuurId + klantId required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $bundle = $this->dossier->compose(
                administrationId: $administrationId,
                factuurId: $factuurId,
                klantId: $klantId,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: dossier compose failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Failed to compose dossier'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($bundle, Http::STATUS_OK);

    }//end dossier()
}//end class
