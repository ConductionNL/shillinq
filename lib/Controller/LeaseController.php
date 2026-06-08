<?php

/**
 * Lease Controller
 *
 * Tier-4-specialized read-only IFRS 16 lease API. Exposes two GET endpoints —
 * the amortization-schedule preview for one lease (REQ-LA-002) and the period-end
 * disclosure table for one administration (REQ-LD-001). Both are available to any
 * authenticated user (#[NoAdminRequired]); the administration scope is validated
 * and reads are delegated to the lease services, which scope every query to the
 * passed administration so no cross-administration lease data leaks (ADR-005
 * IDOR safety). Lease contracts themselves are created / updated through
 * OpenRegister's generic CRUD surface (REQ-LC-001); this controller adds only the
 * computed read surfaces, never a write route.
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
 * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\LeaseDisclosureService;
use OCA\Shillinq\Service\LeasePaymentScheduleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Read-only computed IFRS 16 lease endpoints (schedule preview + disclosure table).
 *
 * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-accounting/spec.md
 */
class LeaseController extends Controller
{
    /**
     * Identifier validation pattern (short slugs / period labels).
     *
     * @var string
     */
    private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

    /**
     * Construct the controller.
     *
     * @param IRequest                    $request           The request object.
     * @param LeasePaymentScheduleService $scheduleService   Amortization schedule service.
     * @param LeaseDisclosureService      $disclosureService Disclosure aggregation service.
     * @param LoggerInterface             $logger            Logger (no stack traces to client).
     */
    public function __construct(
        IRequest $request,
        private readonly LeasePaymentScheduleService $scheduleService,
        private readonly LeaseDisclosureService $disclosureService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the amortization schedule preview for one lease (REQ-LA-002).
     *
     * Query parameters:
     *  - lease_id          (required) LeaseContract id or slug.
     *  - administration_id (required) administration scope (ADR-005).
     *
     * @return JSONResponse 200 with { data, total }; 400 on invalid input.
     *
     * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-accounting/spec.md
     */
    #[NoAdminRequired]
    public function schedule(): JSONResponse
    {
        $leaseId          = trim((string) $this->request->getParam('lease_id', ''));
        $administrationId = trim((string) $this->request->getParam('administration_id', ''));

        $invalid = $this->validateIdentifiers(
            identifiers: ['lease_id' => $leaseId, 'administration_id' => $administrationId]
        );
        if ($invalid !== null) {
            return $invalid;
        }

        try {
            $rows = $this->scheduleService->buildSchedule(
                leaseContractId: $leaseId,
                administrationId: $administrationId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'LeaseController: failed to build lease schedule',
                ['leaseId' => $leaseId, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
            );

            return new JSONResponse(['error' => 'Failed to build lease schedule'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['data' => $rows, 'total' => count($rows)], Http::STATUS_OK);

    }//end schedule()

    /**
     * Return the IFRS 16 disclosure table for an administration + period (REQ-LD-001).
     *
     * Query parameters:
     *  - administration_id (required) administration scope (ADR-005).
     *  - fiscal_period     (required) fiscal period label (e.g. "2026").
     *
     * @return JSONResponse 200 with the disclosure payload; 400 on invalid input.
     *
     * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-disclosures/spec.md
     */
    #[NoAdminRequired]
    public function disclosure(): JSONResponse
    {
        $administrationId = trim((string) $this->request->getParam('administration_id', ''));
        $fiscalPeriod     = trim((string) $this->request->getParam('fiscal_period', ''));

        $invalid = $this->validateIdentifiers(
            identifiers: ['administration_id' => $administrationId, 'fiscal_period' => $fiscalPeriod]
        );
        if ($invalid !== null) {
            return $invalid;
        }

        try {
            $table = $this->disclosureService->generateForPeriod(
                administrationId: $administrationId,
                fiscalPeriod: $fiscalPeriod
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'LeaseController: failed to generate disclosure table',
                ['administrationId' => $administrationId, 'fiscalPeriod' => $fiscalPeriod, 'exception' => $e->getMessage()]
            );

            return new JSONResponse(['error' => 'Failed to generate disclosure table'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($table, Http::STATUS_OK);

    }//end disclosure()

    /**
     * Validate a set of short-slug identifiers; returns a 400 response on the first
     * blank or malformed value, or null when all are valid.
     *
     * @param array<string,string> $identifiers param-name => value.
     *
     * @return JSONResponse|null A 400 response, or null when all identifiers are valid.
     */
    private function validateIdentifiers(array $identifiers): ?JSONResponse
    {
        foreach ($identifiers as $name => $value) {
            if ($value === '') {
                return new JSONResponse(['error' => $name.' is required'], Http::STATUS_BAD_REQUEST);
            }

            if (preg_match(self::ID_PATTERN, $value) !== 1) {
                return new JSONResponse(
                    ['error' => $name.' must be a valid identifier'],
                    Http::STATUS_BAD_REQUEST
                );
            }
        }

        return null;

    }//end validateIdentifiers()
}//end class
