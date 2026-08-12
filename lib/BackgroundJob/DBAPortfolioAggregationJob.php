<?php

/**
 * Shillinq DBA monthly portfolio-aggregation background job.
 *
 * Computes DBAPortfolioRisico per active onderneming (REQ-DBA-005/006): aggregates
 * omzet-concentratie (12-mnd rolling), langjarige relaties, exclusiviteit-patronen
 * and multiple-engagement-zelfde-concern signals.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Enums\DBAConstants;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Monthly DBA portfolio aggregation.
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class DBAPortfolioAggregationJob extends TimedJob
{
    /**
     * Interval between job runs: 30 days in seconds.
     */
    private const INTERVAL_SECONDS = 2592000;

    /**
     * Constructor.
     *
     * @param ITimeFactory       $time      Nextcloud time factory.
     * @param ContainerInterface $container DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig App config.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL_SECONDS);
    }//end __construct()

    /**
     * Compute the concentratie-aggregate for a set of opdrachten (REQ-DBA-005).
     *
     * @param array<int,array<string,mixed>> $opdrachten Per-opdracht rows containing
     *                                                   `klantId` and `gerealiseerdeOmzet`
     *                                                   (eurocenten).
     *
     * @return array<string,mixed> concentratie object { grootsteKlant, aandeelOmzet12mnd, drempelHoog, status }.
     *
     * @spec openspec/specs/dba-compliance-marker/spec.md
     */
    public function computeConcentratie(array $opdrachten): array
    {
        $omzetPerKlant = [];
        $totaal        = 0;
        foreach ($opdrachten as $opdracht) {
            $klantId = (string) ($opdracht['klantId'] ?? '');
            $bedrag  = (int) ($opdracht['realisedRevenue'] ?? 0);
            if ($klantId === '' || $bedrag <= 0) {
                continue;
            }

            $omzetPerKlant[$klantId] = ($omzetPerKlant[$klantId] ?? 0) + $bedrag;
            $totaal += $bedrag;
        }

        if ($totaal <= 0 || count($omzetPerKlant) === 0) {
            return [
                'grootsteKlant'     => null,
                'revenueShare12m' => 0.0,
                'drempelHoog'       => DBAConstants::CONCENTRATIE_DREMPEL_HOOG,
                'status'            => 'VEILIG',
            ];
        }

        $grootsteKlant  = null;
        $grootsteBedrag = 0;
        foreach ($omzetPerKlant as $klantId => $bedrag) {
            if ($bedrag > $grootsteBedrag) {
                $grootsteBedrag = $bedrag;
                $grootsteKlant  = (string) $klantId;
            }
        }

        $aandeel = $grootsteBedrag / $totaal;
        $status  = 'VEILIG';
        if ($aandeel >= DBAConstants::CONCENTRATIE_DREMPEL_KRITIEK) {
            $status = 'KRITIEK';
        } else if ($aandeel >= DBAConstants::CONCENTRATIE_DREMPEL_HOOG) {
            $status = 'WAARSCHUWING';
        }

        return [
            'grootsteKlant'     => $grootsteKlant,
            'revenueShare12m' => round($aandeel, 4),
            'drempelHoog'       => DBAConstants::CONCENTRATIE_DREMPEL_HOOG,
            'status'            => $status,
        ];
    }//end computeConcentratie()

    /**
     * Compute langjarige-relaties (REQ-DBA-005).
     *
     * @param array<int,array<string,mixed>> $opdrachten Per-opdracht rows with
     *                                                   `klantId`, `startDatum`,
     *                                                   `gerealiseerdeOmzet`.
     * @param DateTimeImmutable              $now        Reference "now".
     *
     * @return array<int,array<string,mixed>> List of langjarige relaties.
     *
     * @spec openspec/specs/dba-compliance-marker/spec.md
     */
    public function computeLangjarigeRelaties(array $opdrachten, DateTimeImmutable $now): array
    {
        $result = [];
        $totaal = 0;
        foreach ($opdrachten as $opdracht) {
            $totaal += (int) ($opdracht['realisedRevenue'] ?? 0);
        }

        if ($totaal <= 0) {
            return $result;
        }

        // Group oldest startDatum + total omzet per klant.
        $perKlant = [];
        foreach ($opdrachten as $opdracht) {
            $klantId  = (string) ($opdracht['klantId'] ?? '');
            $startStr = (string) ($opdracht['startDatum'] ?? '');
            $bedrag   = (int) ($opdracht['realisedRevenue'] ?? 0);
            if ($klantId === '' || $startStr === '') {
                continue;
            }

            try {
                $start = new DateTimeImmutable($startStr);
            } catch (Throwable) {
                continue;
            }

            if (isset($perKlant[$klantId]) === false || $perKlant[$klantId]['start'] > $start) {
                $perKlant[$klantId] = ['start' => $start, 'revenue' => $bedrag];
            } else {
                $perKlant[$klantId]['revenue'] += $bedrag;
            }
        }

        foreach ($perKlant as $klantId => $row) {
            $duurJaren = (float) ($row['start']->diff($now)->days / 365.0);
            if ($row['revenue'] > 0) {
                $aandeel = ($row['revenue'] / $totaal);
            } else {
                $aandeel = 0.0;
            }

            if ($duurJaren >= DBAConstants::LANGJARIG_DREMPEL_JAREN
                && $aandeel >= DBAConstants::LANGJARIG_DREMPEL_OMZET
            ) {
                $result[] = [
                    'klantId'      => (string) $klantId,
                    'startDatum'   => $row['start']->format('Y-m-d'),
                    'duurJaren'    => round($duurJaren, 2),
                    'revenueShare' => round($aandeel, 4),
                ];
            }
        }

        return $result;
    }//end computeLangjarigeRelaties()

    /**
     * Compute overall risico-band from concentratie + langjarige relaties.
     *
     * @param array<string,mixed>            $concentratie       The concentratie block.
     * @param array<int,array<string,mixed>> $langjarigeRelaties List of langjarige relaties.
     *
     * @return string LAAG / MIDDEN / HOOG.
     *
     * @spec openspec/specs/dba-compliance-marker/spec.md
     */
    public function computeOverallRisico(array $concentratie, array $langjarigeRelaties): string
    {
        $status = (string) ($concentratie['status'] ?? 'VEILIG');
        if ($status === 'KRITIEK' || count($langjarigeRelaties) >= 2) {
            return 'HOOG';
        }

        if ($status === 'WAARSCHUWING' || count($langjarigeRelaties) === 1) {
            return 'MIDDEN';
        }

        return 'LAAG';
    }//end computeOverallRisico()

    /**
     * Execute the aggregation pass.
     *
     * @param mixed $argument Not used; required by TimedJob.
     *
     * @return void
     *
     * @spec openspec/specs/dba-compliance-marker/spec.md
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run(mixed $argument): void
    {
        $this->logger->info('Shillinq: DBAPortfolioAggregationJob started');

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            $this->logger->warning(
                'Shillinq DBAPortfolioAggregationJob: OpenRegister not available, skipping.',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $register = $this->resolveRegister();
        $now      = new DateTimeImmutable();
        $written  = 0;

        try {
            $rows = $objectService->setRegister($register)->setSchema('DBAOpdracht')->findAll(['limit' => 5000]);
        } catch (Throwable $e) {
            $this->logger->error(
                'Shillinq DBAPortfolioAggregationJob: failed to fetch opdrachten',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $perOnderneming = [];
        foreach ($rows as $row) {
            $opdracht = $this->toArray(entity: $row);
            if ($opdracht === null) {
                continue;
            }

            $ondernemingId = (string) ($opdracht['enterpriseId'] ?? '');
            if ($ondernemingId === '') {
                continue;
            }

            $perOnderneming[$ondernemingId] ??= [];
            $perOnderneming[$ondernemingId][] = $opdracht;
        }

        foreach ($perOnderneming as $ondernemingId => $opdrachten) {
            $concentratie     = $this->computeConcentratie(opdrachten: $opdrachten);
            $langjarig        = $this->computeLangjarigeRelaties(opdrachten: $opdrachten, now: $now);
            $overall          = $this->computeOverallRisico(concentratie: $concentratie, langjarigeRelaties: $langjarig);
            $administrationId = (string) ($opdrachten[0]['administrationId'] ?? '');

            try {
                $objectService->setRegister($register)->setSchema('DBAPortfolioRisico')->saveObject(
                        [
                            'administrationId'   => $administrationId,
                            'enterpriseId'      => (string) $ondernemingId,
                            'peilDatum'          => $now->format('Y-m-d'),
                            'actieveOpdrachten'  => count($opdrachten),
                            'concentratie'       => $concentratie,
                            'langjarigeRelaties' => $langjarig,
                            'exclusieveRelaties' => $this->countExclusief(opdrachten: $opdrachten),
                            'overallRisico'      => $overall,
                        ]
                        );
                $written++;
            } catch (Throwable $e) {
                $this->logger->error(
                    'Shillinq DBAPortfolioAggregationJob: failed to write portfolio',
                    ['enterpriseId' => (string) $ondernemingId, 'exception' => $e->getMessage()]
                );
            }
        }//end foreach

        $this->logger->info(
            sprintf('Shillinq DBAPortfolioAggregationJob: wrote %d portfolio records', $written)
        );
    }//end run()

    /**
     * Count exclusive relations (klanten that account for 100% of omzet) (REQ-DBA-005).
     *
     * @param array<int,array<string,mixed>> $opdrachten Per-opdracht rows.
     *
     * @return int The count of exclusive relations.
     */
    private function countExclusief(array $opdrachten): int
    {
        $omzetPerKlant = [];
        $totaal        = 0;
        foreach ($opdrachten as $opdracht) {
            $klantId = (string) ($opdracht['klantId'] ?? '');
            $bedrag  = (int) ($opdracht['realisedRevenue'] ?? 0);
            if ($klantId === '' || $bedrag <= 0) {
                continue;
            }

            $omzetPerKlant[$klantId] = ($omzetPerKlant[$klantId] ?? 0) + $bedrag;
            $totaal += $bedrag;
        }

        if ($totaal <= 0 || count($omzetPerKlant) === 0) {
            return 0;
        }

        $count = 0;
        foreach ($omzetPerKlant as $bedrag) {
            if (((float) $bedrag / (float) $totaal) >= 0.99) {
                $count++;
            }
        }

        return $count;
    }//end countExclusief()

    /**
     * Coerce an entity to an array.
     *
     * @param mixed $entity Entity from ObjectService.
     *
     * @return array<string,mixed>|null Plain array, or null when not coercible.
     */
    private function toArray(mixed $entity): ?array
    {
        if (is_array($entity) === true) {
            /*
             * @var array<string,mixed> $entity
             */

            return $entity;
        }

        if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
            $data = $entity->getObject();
            if (is_array($data) === true) {
                /*
                 * @var array<string,mixed> $data
                 */

                return $data;
            }
        }

        return null;
    }//end toArray()

    /**
     * Resolve the register slug from app-config.
     *
     * @return string The register slug.
     */
    private function resolveRegister(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($register === '') {
            return 'shillinq';
        }

        return $register;
    }//end resolveRegister()
}//end class
