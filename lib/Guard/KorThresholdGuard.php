<?php

/**
 * KOR Threshold Guard
 *
 * Cross-period YTD revenue aggregation for the KOR omzetdrempel lifecycle,
 * referenced from lib/Settings/shillinq_register.json. ADR-031 exception
 * (design.md Declarative-vs-imperative table, Risk 3): OpenRegister's
 * declarative aggregation engine cannot span fiscal periods inside a
 * lifecycle `requires` clause, so this thin single-purpose guard sums YTD
 * revenue in PHP. No domain logic, no state — a precondition seam only.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards KOR regime threshold-crossing transitions.
 *
 * `reachesWarning` and `reachesThreshold` are referenced by name from the
 * KorRegime schema's x-openregister-lifecycle `requires` clauses. Each
 * returns true when the YTD revenue has reached the relevant fraction of the
 * omzetdrempel (warning at 80%, exceeded at 100%).
 *
 * ADR-031 exception: documented in
 * openspec/changes/add-shillinq-bookkeeping-operations/design.md
 * (Declarative-vs-imperative decision table, Risk 3). Single method of work
 * (`currentYtdRevenue`); no persisted state.
 *
 * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 */
class KorThresholdGuard
{
    /**
     * Default KOR omzetdrempel in EUR per Wet OB 1968 art. 25 lid 1.
     *
     * @var float
     */
    private const DEFAULT_THRESHOLD = 20000.0;

    /**
     * Warning fraction of the omzetdrempel (80%).
     *
     * @var float
     */
    private const WARNING_FRACTION = 0.8;

    /**
     * Construct the guard with lazy DI of OR's ObjectService.
     *
     * @param ContainerInterface $container DI container — OR's ObjectService is fetched
     *                                      lazily so this class stays usable before the
     *                                      Invoice register exists.
     * @param IAppConfig         $appConfig App config for dynamic register slug resolution.
     * @param LoggerInterface    $logger    Nextcloud logger for fail-closed diagnostics.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return the configured register slug, falling back to 'shillinq' if unset.
     *
     * Mirrors AccountBalanceGuard::getRegisterSlug so all reads use the same
     * register even when the admin reconfigures the slug.
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
     * Sum YTD revenue for an administration in the given calendar year.
     *
     * Pages through all Invoice records for the administration/year via OR's
     * real `setRegister()->setSchema()->findAll()` API and sums the `amount`
     * field. Uses integer cents to avoid IEEE-754 float drift.
     *
     * @param string $adminId The administrationId to aggregate.
     * @param int    $year    The calendar year.
     *
     * @return float The YTD revenue total in EUR.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md (REQ-KOR-004)
     */
    public function currentYtdRevenue(string $adminId, int $year): float
    {
        if ($adminId === '') {
            return 0.0;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $pageSize   = 500;
            $page       = 1;
            $totalCents = 0;
            $batchSize  = 0;
            do {
                $batch = $objectService
                    ->setRegister($this->getRegisterSlug())
                    ->setSchema('Invoice')
                    ->findAll(
                        [
                            'filters' => [
                                'administrationId' => $adminId,
                                'year'             => $year,
                            ],
                            'limit'   => $pageSize,
                            'offset'  => (($page - 1) * $pageSize),
                        ]
                    );

                foreach ($batch as $invoice) {
                    $totalCents += (int) round(((float) ($invoice['amount'] ?? 0)) * 100);
                }

                $batchSize = count($batch);
                $page++;
            } while ($batchSize === $pageSize);

            return ($totalCents / 100);
        } catch (\Throwable $e) {
            $this->logger->error(
                'KorThresholdGuard: YTD revenue computation failed',
                ['exception' => $e->getMessage()]
            );
            return 0.0;
        }//end try

    }//end currentYtdRevenue()

    /**
     * Resolve the omzetdrempel for a KorRegime record, falling back to the
     * statutory default.
     *
     * @param array<string, mixed> $regime KorRegime object array.
     *
     * @return float The threshold in EUR.
     */
    private function thresholdFor(array $regime): float
    {
        $configured = ($regime['thresholdAmount'] ?? null);
        if (is_numeric($configured) === true && (float) $configured > 0) {
            return (float) $configured;
        }

        return self::DEFAULT_THRESHOLD;

    }//end thresholdFor()

    /**
     * Precondition for the `warn` transition (opted-in → threshold-warning):
     * YTD revenue must have reached 80% of the omzetdrempel.
     *
     * @param array<string, mixed> $regime KorRegime object array (loaded by OR).
     *
     * @return bool True when the warning threshold is reached.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md (REQ-KOR-002, REQ-KOR-005)
     */
    public function reachesWarning(array $regime): bool
    {
        $adminId = (string) ($regime['administrationId'] ?? '');
        $year    = (int) ($regime['year'] ?? (int) date('Y'));
        $revenue = $this->currentYtdRevenue(adminId: $adminId, year: $year);

        return $revenue >= ($this->thresholdFor(regime: $regime) * self::WARNING_FRACTION);

    }//end reachesWarning()

    /**
     * Precondition for the `exceed` transition (threshold-warning →
     * threshold-exceeded): YTD revenue must have reached 100% of the
     * omzetdrempel.
     *
     * @param array<string, mixed> $regime KorRegime object array (loaded by OR).
     *
     * @return bool True when the omzetdrempel is reached or exceeded.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md (REQ-KOR-002, REQ-KOR-005)
     */
    public function reachesThreshold(array $regime): bool
    {
        $adminId = (string) ($regime['administrationId'] ?? '');
        $year    = (int) ($regime['year'] ?? (int) date('Y'));
        $revenue = $this->currentYtdRevenue(adminId: $adminId, year: $year);

        return $revenue >= $this->thresholdFor(regime: $regime);

    }//end reachesThreshold()
}//end class
