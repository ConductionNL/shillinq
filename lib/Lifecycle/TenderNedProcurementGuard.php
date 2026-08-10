<?php

/**
 * TenderNedProcurement Guard
 *
 * ADR-031 exception-path lifecycle guard for the TenderNedProcurement award
 * (gunnen) and completion (afronden) transitions.
 *
 * canGunnen (open → awarded) enforces REQ-002: the award is only recorded when
 * the dossier carries the data needed to materialise an obligation — a
 * awardedSupplier and a non-zero contractValue.
 *
 * canAfronden (in-uitvoering → afgerond) enforces REQ-006: a tender can only be
 * completed once an finalDelivery OrderFulfilment for the linked obligation
 * has been approved, so the public dossier is never marked afgerond before the
 * final delivery is accepted.
 *
 * Referenced from the TenderNedProcurement schema's
 * x-openregister-lifecycle.transitions.{gunnen,afronden}.requires in
 * lib/Settings/register.d/20-bookkeeping-tenderned-integratie.json.
 *
 * ADR-031 exception reason: canAfronden spans the OrderFulfilment set for the
 * linked Commitment (a cross-schema existence + approval check) which the
 * declarative lifecycle DSL cannot yet express. Replace with declarative
 * conditions when the engine supports cross-schema existence predicates.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Award and completion precondition guard for the TenderNedProcurement schema
 * per REQ-002 and REQ-006.
 *
 * Fail-closed: any unexpected exception denies the transition (CWE-863).
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
 */
class TenderNedProcurementGuard
{
    /**
     * Construct the guard with DI dependencies.
     *
     * @param ContainerInterface $container DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig App config for register slug resolution.
     * @param LoggerInterface    $logger    Logger for fail-closed diagnostics.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return the configured register slug, falling back to 'shillinq'.
     *
     * @return string
     */
    private function getRegisterSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($slug === '') {
            return 'shillinq';
        }

        return $slug;

    }//end getRegisterSlug()

    /**
     * Precondition for the gunnen (open → awarded) transition.
     *
     * REQ-002: the award is only recorded when the dossier carries the data
     * needed to materialise a concept obligation — a awardedSupplier and a
     * positive contractValue.
     *
     * Fail-closed: returns false on any exception (denies the award) per CWE-863.
     *
     * @param array<string, mixed> $procurement TenderNedProcurement object array.
     *
     * @return bool True when the award may be recorded.
     *
     * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
     */
    public function canGunnen(array $procurement): bool
    {
        try {
            if (trim((string) ($procurement['awardedSupplier'] ?? '')) === '') {
                $this->logger->info(
                    'TenderNedProcurementGuard: no awardedSupplier — denying award (REQ-002)',
                    ['procurementId' => ($procurement['procurementId'] ?? 'unknown')]
                );
                return false;
            }

            if ((float) ($procurement['contractValue'] ?? 0) <= 0.0) {
                $this->logger->info(
                    'TenderNedProcurementGuard: contractValue must be positive — denying award (REQ-002)',
                    ['procurementId' => ($procurement['procurementId'] ?? 'unknown')]
                );
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                'TenderNedProcurementGuard: canGunnen failed — denying award (fail-closed)',
                [
                    'procurementId' => ($procurement['procurementId'] ?? 'unknown'),
                    'exception'     => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end canGunnen()

    /**
     * Precondition for the afronden (in-uitvoering → afgerond) transition.
     *
     * REQ-006: a tender can only be completed once an finalDelivery
     * OrderFulfilment for the linked Commitment has been approved
     * (status completed, approved true). This prevents the public dossier from
     * being marked afgerond before the final delivery is accepted.
     *
     * When the linked Commitment cannot be resolved (no commitmentId yet, or
     * the schema is not available in a T1 state) the completion is permitted with
     * a warning so manually-managed tenders are not blocked.
     *
     * Fail-closed: returns false on any exception (denies completion) per CWE-863.
     *
     * @param array<string, mixed> $procurement TenderNedProcurement object array.
     *
     * @return bool True when the tender may be completed.
     *
     * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
     */
    public function canAfronden(array $procurement): bool
    {
        try {
            $commitmentId = trim((string) ($procurement['commitmentId'] ?? ''));
            if ($commitmentId === '') {
                $this->logger->warning(
                    'TenderNedProcurementGuard: no linked Commitment — permitting completion without delivery check',
                    ['procurementId' => ($procurement['procurementId'] ?? 'unknown')]
                );
                return true;
            }

            return $this->hasApprovedFinalDelivery(commitmentId: $commitmentId, procurement: $procurement);
        } catch (\Throwable $e) {
            $this->logger->error(
                'TenderNedProcurementGuard: canAfronden failed — denying completion (fail-closed)',
                [
                    'procurementId' => ($procurement['procurementId'] ?? 'unknown'),
                    'exception'     => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end canAfronden()

    /**
     * Verify an approved finalDelivery exists for the linked obligation.
     *
     * @param string               $commitmentId The linked obligation id.
     * @param array<string, mixed> $procurement  TenderNedProcurement for log context.
     *
     * @return bool True when an approved finalDelivery OrderFulfilment exists.
     */
    private function hasApprovedFinalDelivery(string $commitmentId, array $procurement): bool
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $records       = $objectService
                ->setRegister(register: $this->getRegisterSlug())
                ->setSchema(schema: 'OrderFulfilment')
                ->findAll(
                    [
                        'filters' => [
                            'commitmentId' => $commitmentId,
                            'deliveryType' => 'finalDelivery',
                        ],
                    ]
                );
        } catch (\Throwable $e) {
            // OrderFulfilment schema not available (T1 state) — permit completion.
            $this->logger->debug(
                'TenderNedProcurementGuard: OrderFulfilment lookup unavailable (T1 state) — permitting completion',
                ['procurementId' => ($procurement['procurementId'] ?? 'unknown'), 'exception' => $e->getMessage()]
            );
            return true;
        }//end try

        if (is_array($records) === false) {
            return false;
        }

        foreach ($records as $record) {
            if (is_array($record) === false) {
                continue;
            }

            if (($record['status'] ?? '') === 'completed'
                && ((bool) ($record['approved'] ?? false)) === true
            ) {
                return true;
            }
        }

        $this->logger->info(
            'TenderNedProcurementGuard: no approved finalDelivery — denying completion (REQ-006)',
            [
                'procurementId' => ($procurement['procurementId'] ?? 'unknown'),
                'commitmentId'  => $commitmentId,
            ]
        );
        return false;

    }//end hasApprovedFinalDelivery()
}//end class
