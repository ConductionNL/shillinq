<?php

/**
 * Rate Card Resolver
 *
 * Resolves the applicable rate for a (rateCard, resource, date) tuple from
 * the existing RateCard / RateCardVersion / RateRecord registers (Task 24,
 * issue #111). Returns the resolved rate as a snapshot so callers can persist
 * it into BillableInvoiceLine.rateApplied per design decision D2.
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
use RuntimeException;

/**
 * Look up rates from the existing RateCard family of schemas.
 */
class RateCardResolver
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container — OR ObjectService is fetched lazily.
     * @param IAppConfig         $appConfig App config for the register slug.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve a rate snapshot for a (rateCardId, resourceType, date) tuple.
     *
     * The lookup prefers a RateRecord matching (rateCardId, resourceType,
     * effectiveDate <= $date, expiresAt >= $date OR null); falls back to a
     * RateCardVersion default rate; and finally to a sensible default
     * encoded on the RateCard itself.
     *
     * @param string $rateCardId   FK to RateCard.
     * @param string $resourceType e.g. 'senior_consultant', 'junior_consultant'.
     * @param string $date         ISO date the rate should apply on.
     *
     * @return array{rateCents:int,currency:string,rateCardVersion:string,effectiveDate:string}
     */
    public function resolveRate(string $rateCardId, string $resourceType, string $date): array
    {
        $records = $this->findAll(
            schema: 'RateRecord',
            filters: [
                'rateCardId'   => $rateCardId,
                'resourceType' => $resourceType,
            ]
        );

        $best = null;
        foreach ($records as $record) {
            $effective = (string) ($record['effectiveDate'] ?? '');
            $expires   = (string) ($record['expiresAt'] ?? '');

            if ($effective !== '' && $effective > $date) {
                continue;
            }

            if ($expires !== '' && $expires < $date) {
                continue;
            }

            if ($best === null || (string) ($best['effectiveDate'] ?? '') < $effective) {
                $best = $record;
            }
        }//end foreach

        if ($best !== null) {
            return [
                'rateCents'       => $this->toCents(value: $best['rateCents'] ?? $best['hourlyRate'] ?? 0),
                'currency'        => (string) ($best['currency'] ?? 'EUR'),
                'rateCardVersion' => (string) ($best['rateCardVersion'] ?? ($best['version'] ?? 'v1')),
                'effectiveDate'   => (string) ($best['effectiveDate'] ?? $date),
            ];
        }

        // Fallback: read the RateCard's default rate (used for simple rate
        // cards that lack per-resource records). The default mirrors the
        // RateCard.defaultHourlyRate field shipped by the rate-card-engine.
        $rateCards = $this->findAll(schema: 'RateCard', filters: ['scheduleId' => $rateCardId]);
        if (count($rateCards) === 0) {
            $rateCards = $this->findAll(schema: 'RateCard', filters: ['id' => $rateCardId]);
        }

        if (count($rateCards) > 0) {
            $card = $rateCards[0];
            return [
                'rateCents'       => $this->toCents(value: $card['defaultHourlyRate'] ?? $card['hourlyRate'] ?? 10000),
                'currency'        => (string) ($card['currency'] ?? 'EUR'),
                'rateCardVersion' => (string) ($card['version'] ?? 'v1'),
                'effectiveDate'   => $date,
            ];
        }

        $this->logger->warning(
            sprintf(
                'RateCardResolver: no rate found for %s/%s on %s, falling back to €100/hr',
                $rateCardId,
                $resourceType,
                $date
            )
        );

        return [
            'rateCents'       => 10000,
            'currency'        => 'EUR',
            'rateCardVersion' => 'fallback',
            'effectiveDate'   => $date,
        ];

    }//end resolveRate()

    /**
     * Convert a stored money value to integer cents.
     *
     * Accepts a stored number-of-euros (multipleOf 0.01) or already-integer cents.
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
     * Find all matching records via the real OR ObjectService API (findAll).
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
            $this->logger->error('RateCardResolver findAll failed: '.$e->getMessage());
            return [];
        }

    }//end findAll()

    /**
     * Resolve the OpenRegister register slug, defaulting to 'shillinq'.
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
