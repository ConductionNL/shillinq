<?php

/**
 * Retainer Resolver
 *
 * Reads the active RetainerSchedule version for a given invoice month and
 * returns the applicable monthly amount + overage configuration (Task 25,
 * issue #111). Mirrors RateCardResolver in style — pure lookup, no GL
 * posting, no schema mutation.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolve RetainerSchedule entries from the OR engine.
 */
class RetainerResolver
{
    /**
     * Construct the retainer resolver.
     *
     * @param ContainerInterface $container DI container.
     * @param IAppConfig         $appConfig App config.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the monthly retainer + overage amounts for a given invoice month.
     *
     * @param string $scheduleId   FK to RetainerSchedule.
     * @param string $invoiceMonth ISO date — any day within the target month.
     *
     * @return array{monthlyAmountCents:int,overageHoursThreshold:?float,overageHourlyRateCents:?int,effectiveDate:string,label:string}
     */
    public function resolveRetainerAmount(string $scheduleId, string $invoiceMonth): array
    {
        $records = $this->findAll(
            schema: 'RetainerSchedule',
            filters: ['scheduleId' => $scheduleId]
        );

        $best = null;
        foreach ($records as $record) {
            $effective = (string) ($record['effectiveDate'] ?? '');
            $end       = (string) ($record['endDate'] ?? '');

            if ($effective !== '' && $effective > $invoiceMonth) {
                continue;
            }

            if ($end !== '' && $end < $invoiceMonth) {
                continue;
            }

            if ($best === null || (string) ($best['effectiveDate'] ?? '') < $effective) {
                $best = $record;
            }
        }

        if ($best === null) {
            $this->logger->warning(sprintf('RetainerResolver: no active schedule for %s on %s', $scheduleId, $invoiceMonth));
            return [
                'monthlyAmountCents'     => 0,
                'overageHoursThreshold'  => null,
                'overageHourlyRateCents' => null,
                'effectiveDate'          => $invoiceMonth,
                'label'                  => 'Retainer',
            ];
        }

        if (isset($best['overageHoursThreshold']) === true) {
            $overageHoursThreshold = (float) $best['overageHoursThreshold'];
        } else {
            $overageHoursThreshold = null;
        }

        if (isset($best['overageHourlyRate']) === true) {
            $overageHourlyRateCents = $this->toCents(value: $best['overageHourlyRate']);
        } else {
            $overageHourlyRateCents = null;
        }

        return [
            'monthlyAmountCents'     => $this->toCents(value: $best['monthlyAmount'] ?? 0),
            'overageHoursThreshold'  => $overageHoursThreshold,
            'overageHourlyRateCents' => $overageHourlyRateCents,
            'effectiveDate'          => (string) ($best['effectiveDate'] ?? $invoiceMonth),
            'label'                  => (string) ($best['label'] ?? 'Retainer'),
        ];

    }//end resolveRetainerAmount()

    /**
     * Convert a stored money value to integer cents.
     *
     * @param mixed $value Stored value.
     *
     * @return int Cents.
     */
    private function toCents(mixed $value): int
    {
        if (is_int($value) === true) {
            return $value;
        }

        return (int) round((float) $value * 100);

    }//end toCents()

    /**
     * Find all matching records via the real OR ObjectService API.
     *
     * @param string              $schema  Schema slug.
     * @param array<string,mixed> $filters Filter map.
     *
     * @return array<int,array<string,mixed>>
     */
    private function findAll(string $schema, array $filters): array
    {
        try {
            $svc = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rs  = $svc
                ->setRegister($this->register())
                ->setSchema($schema)
                ->findAll(['filters' => $filters]);

            if (is_array($rs) === true) {
                return $rs;
            }

            return [];
        } catch (\Throwable $e) {
            $this->logger->error('RetainerResolver findAll failed: '.$e->getMessage());
            return [];
        }

    }//end findAll()

    /**
     * Resolve the OpenRegister register slug.
     *
     * @return string
     */
    private function register(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($register === '') {
            return 'shillinq';
        }

        return $register;

    }//end register()
}//end class
