<?php

/**
 * TenderNedAanbesteding Guard
 *
 * ADR-031 exception-path lifecycle guard for the TenderNedAanbesteding award
 * (gunnen) and completion (afronden) transitions.
 *
 * canGunnen (open → gegund) enforces REQ-002: the award is only recorded when
 * the dossier carries the data needed to materialise an obligation — a
 * gegundeLeverancier and a non-zero contractWaarde.
 *
 * canAfronden (in-uitvoering → afgerond) enforces REQ-006: a tender can only be
 * completed once an eindoplevering OpdrachtUitvoering for the linked obligation
 * has been approved, so the public dossier is never marked afgerond before the
 * final delivery is accepted.
 *
 * Referenced from the TenderNedAanbesteding schema's
 * x-openregister-lifecycle.transitions.{gunnen,afronden}.requires in
 * lib/Settings/register.d/20-bookkeeping-tenderned-integratie.json.
 *
 * ADR-031 exception reason: canAfronden spans the OpdrachtUitvoering set for the
 * linked Verplichting (a cross-schema existence + approval check) which the
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
 * Award and completion precondition guard for the TenderNedAanbesteding schema
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
     * Precondition for the gunnen (open → gegund) transition.
     *
     * REQ-002: the award is only recorded when the dossier carries the data
     * needed to materialise a concept obligation — a gegundeLeverancier and a
     * positive contractWaarde.
     *
     * Fail-closed: returns false on any exception (denies the award) per CWE-863.
     *
     * @param array<string, mixed> $aanbesteding TenderNedAanbesteding object array.
     *
     * @return bool True when the award may be recorded.
     *
     * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
     */
    public function canGunnen(array $aanbesteding): bool
    {
        try {
            if (trim((string) ($aanbesteding['gegundeLeverancier'] ?? '')) === '') {
                $this->logger->info(
                    'TenderNedProcurementGuard: no gegundeLeverancier — denying award (REQ-002)',
                    ['aanbestedingId' => ($aanbesteding['aanbestedingId'] ?? 'unknown')]
                );
                return false;
            }

            if ((float) ($aanbesteding['contractWaarde'] ?? 0) <= 0.0) {
                $this->logger->info(
                    'TenderNedProcurementGuard: contractWaarde must be positive — denying award (REQ-002)',
                    ['aanbestedingId' => ($aanbesteding['aanbestedingId'] ?? 'unknown')]
                );
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                'TenderNedProcurementGuard: canGunnen failed — denying award (fail-closed)',
                [
                    'aanbestedingId' => ($aanbesteding['aanbestedingId'] ?? 'unknown'),
                    'exception'      => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end canGunnen()

    /**
     * Precondition for the afronden (in-uitvoering → afgerond) transition.
     *
     * REQ-006: a tender can only be completed once an eindoplevering
     * OpdrachtUitvoering for the linked Verplichting has been approved
     * (status completed, goedgekeurd true). This prevents the public dossier from
     * being marked afgerond before the final delivery is accepted.
     *
     * When the linked Verplichting cannot be resolved (no verplichtingId yet, or
     * the schema is not available in a T1 state) the completion is permitted with
     * a warning so manually-managed tenders are not blocked.
     *
     * Fail-closed: returns false on any exception (denies completion) per CWE-863.
     *
     * @param array<string, mixed> $aanbesteding TenderNedAanbesteding object array.
     *
     * @return bool True when the tender may be completed.
     *
     * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
     */
    public function canAfronden(array $aanbesteding): bool
    {
        try {
            $verplichtingId = trim((string) ($aanbesteding['verplichtingId'] ?? ''));
            if ($verplichtingId === '') {
                $this->logger->warning(
                    'TenderNedProcurementGuard: no linked Verplichting — permitting completion without delivery check',
                    ['aanbestedingId' => ($aanbesteding['aanbestedingId'] ?? 'unknown')]
                );
                return true;
            }

            return $this->hasApprovedEindoplevering(verplichtingId: $verplichtingId, aanbesteding: $aanbesteding);
        } catch (\Throwable $e) {
            $this->logger->error(
                'TenderNedProcurementGuard: canAfronden failed — denying completion (fail-closed)',
                [
                    'aanbestedingId' => ($aanbesteding['aanbestedingId'] ?? 'unknown'),
                    'exception'      => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end canAfronden()

    /**
     * Verify an approved eindoplevering exists for the linked obligation.
     *
     * @param string               $verplichtingId The linked obligation id.
     * @param array<string, mixed> $aanbesteding   TenderNedAanbesteding for log context.
     *
     * @return bool True when an approved eindoplevering OpdrachtUitvoering exists.
     */
    private function hasApprovedEindoplevering(string $verplichtingId, array $aanbesteding): bool
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $records       = $objectService
                ->setRegister(register: $this->getRegisterSlug())
                ->setSchema(schema: 'OpdrachtUitvoering')
                ->findAll(
                    [
                        'filters' => [
                            'verplichtingId'  => $verplichtingId,
                            'opleveringsType' => 'eindoplevering',
                        ],
                    ]
                );
        } catch (\Throwable $e) {
            // OpdrachtUitvoering schema not available (T1 state) — permit completion.
            $this->logger->debug(
                'TenderNedProcurementGuard: OpdrachtUitvoering lookup unavailable (T1 state) — permitting completion',
                ['aanbestedingId' => ($aanbesteding['aanbestedingId'] ?? 'unknown'), 'exception' => $e->getMessage()]
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
                && ((bool) ($record['goedgekeurd'] ?? false)) === true
            ) {
                return true;
            }
        }

        $this->logger->info(
            'TenderNedProcurementGuard: no approved eindoplevering — denying completion (REQ-006)',
            [
                'aanbestedingId' => ($aanbesteding['aanbestedingId'] ?? 'unknown'),
                'verplichtingId' => $verplichtingId,
            ]
        );
        return false;

    }//end hasApprovedEindoplevering()
}//end class
