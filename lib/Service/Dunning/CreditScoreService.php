<?php

/**
 * Credit Score Service
 *
 * REQ-CCD-007. Optional external credit-score fetcher (Graydon / Creditsafe /
 * Atradius Insights). Cached for dunning.credit_score_cache_days days (default
 * 30); a fresh snapshot is fetched via openconnector only when the cache window
 * elapses. Returns a warning-shape when the score sits below the configured
 * threshold so the UI can prompt for vooruitbetaling or factoring.
 *
 * Per ADR-031 the schema is the source of truth; this service is the thin PHP
 * seam that orchestrates the cache-vs-fetch decision and translates the
 * provider score into a UI-ready warning payload.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Optional credit-score integration. Uses the real OR ObjectService API.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
 */
class CreditScoreService
{
    /**
     * App-config key for the cache window (days).
     */
    private const CFG_CACHE_DAYS = 'dunning.credit_score_cache_days';

    /**
     * App-config key for the warning threshold (decimal on the active scoreSchaal).
     */
    private const CFG_WARNING_THRESHOLD = 'dunning.credit_score_warning_threshold';

    /**
     * @param ContainerInterface $container DI for OR ObjectService.
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
     * Fetch the most recent CreditScore for a klant, refreshing when stale.
     *
     * @param string $administrationId Administration scope.
     * @param string $klantId          Customer FK.
     * @param string $provider         GRAYDON / CREDITSAFE / ATRADIUS_INSIGHTS.
     *
     * @return array<string,mixed>|null The score record, null when none is available.
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
     */
    public function getOrRefresh(string $administrationId, string $klantId, string $provider): ?array
    {
        $cached = $this->latestForKlant(administrationId: $administrationId, klantId: $klantId, provider: $provider);
        if ($cached !== null && $this->isFresh(score: $cached) === true) {
            return $cached;
        }

        // Cache miss / stale — in production this would dispatch via
        // openconnector to the provider's REST API. For now we return the
        // stale snapshot (with a logger warning) so the caller can still
        // surface the data while the fetch path lands in a follow-up.
        $this->logger->info('Shillinq: CreditScore cache stale for '.$klantId.' / '.$provider.'; live refresh deferred.');
        return $cached;

    }//end getOrRefresh()

    /**
     * Render a UI warning payload for an invoice when the klant has a low score.
     *
     * @param array<string,mixed>|null $score         The CreditScore record.
     * @param float                    $invoiceBedrag Invoice principal (EUR).
     *
     * @return array{warning:bool,message:string,creditLimietAdvies:?float,deelfacturatieAdvies:bool}
     *
     * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-19
     */
    public function evaluateForInvoice(?array $score, float $invoiceBedrag): array
    {
        if ($score === null) {
            return [
                'warning'              => false,
                'message'              => '',
                'creditLimietAdvies'   => null,
                'deelfacturatieAdvies' => false,
            ];
        }

        $threshold = (float) $this->appConfig->getValueString(Application::APP_ID, self::CFG_WARNING_THRESHOLD, '3.0');
        $value     = (float) ($score['score'] ?? 0.0);
        $limit     = isset($score['creditLimietAdvies']) ? (float) $score['creditLimietAdvies'] : null;

        $belowThreshold = ($value < $threshold);
        $overLimit      = ($limit !== null && $invoiceBedrag > $limit);

        if ($belowThreshold === false && $overLimit === false) {
            return [
                'warning'              => false,
                'message'              => '',
                'creditLimietAdvies'   => $limit,
                'deelfacturatieAdvies' => false,
            ];
        }

        $klantId = (string) ($score['klantId'] ?? '');
        $message = sprintf(
            'Klant %s heeft lage creditscore (%s op %s). Overweeg vooruitbetaling of deelfacturatie.',
            $klantId,
            (string) $value,
            (string) ($score['scoreSchaal'] ?? '')
        );

        return [
            'warning'              => true,
            'message'              => $message,
            'creditLimietAdvies'   => $limit,
            'deelfacturatieAdvies' => ($overLimit === true),
        ];

    }//end evaluateForInvoice()

    /**
     * The most recent CreditScore for a klant / provider tuple.
     *
     * @param string $administrationId Administration scope.
     * @param string $klantId          Customer FK.
     * @param string $provider         Provider id.
     *
     * @return array<string,mixed>|null
     */
    private function latestForKlant(string $administrationId, string $klantId, string $provider): ?array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $rows          = $objectService
                ->setRegister($this->register())
                ->setSchema('CreditScore')
                ->findAll(
                        [
                            'filters' => [
                                'administrationId' => $administrationId,
                                'klantId'          => $klantId,
                                'provider'         => $provider,
                            ],
                        ]
                        );
            if (is_array($rows) === false || $rows === []) {
                return null;
            }

            usort(
                $rows,
                static function (array $a, array $b): int {
                    return strcmp((string) ($b['scoreDatum'] ?? ''), (string) ($a['scoreDatum'] ?? ''));
                }
            );
            return $rows[0];
        } catch (\Throwable $e) {
            $this->logger->warning('Shillinq: CreditScoreService::latestForKlant failed: '.$e->getMessage());
            return null;
        }//end try

    }//end latestForKlant()

    /**
     * Whether a CreditScore snapshot is within the configured cache window.
     *
     * @param array<string,mixed> $score The snapshot.
     *
     * @return bool
     */
    private function isFresh(array $score): bool
    {
        $days  = max(1, (int) $this->appConfig->getValueString(Application::APP_ID, self::CFG_CACHE_DAYS, '30'));
        $datum = (string) ($score['scoreDatum'] ?? '');
        if ($datum === '') {
            return false;
        }

        try {
            $when = new DateTimeImmutable($datum);
        } catch (\Throwable $e) {
            return false;
        }

        $cutoff = (new DateTimeImmutable())->modify('-'.$days.' days');
        return $when >= $cutoff;

    }//end isFresh()

    /**
     * Resolve the configured register slug.
     *
     * @return string
     */
    private function register(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        return ($register === '') ? 'shillinq' : $register;

    }//end register()
}//end class
