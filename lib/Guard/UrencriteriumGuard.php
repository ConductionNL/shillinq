<?php

/**
 * Urencriterium Guard
 *
 * Cross-period YTD qualifying-hours aggregation for the ZZP 1225-urencriterium
 * (Wet IB 2001 art. 3.6), referenced from lib/Settings/shillinq_register.json.
 * ADR-031 exception (design.md Declarative-vs-imperative table, Risk 3):
 * OpenRegister's declarative aggregation engine cannot span fiscal periods
 * inside a lifecycle/requires clause, so this thin single-purpose guard sums
 * the qualifying hours in PHP, filtering out statutorily-excluded categories
 * (sick / parental-leave / vacation / non-billable-admin). No domain logic,
 * no state — a precondition seam only.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-zzp-tax-regime/spec.md
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
 * Guards the ZZP urencriterium qualification.
 *
 * `currentYtdHours` is referenced by name from the ZzpDeduction schema's
 * x-openregister-requires clause. It returns the YTD qualifying hours for a
 * person in a calendar year, excluding the statutory non-qualifying
 * categories.
 *
 * ADR-031 exception: documented in
 * openspec/changes/add-shillinq-bookkeeping-operations/design.md
 * (Declarative-vs-imperative decision table, Risk 3). Single method of work;
 * no persisted state.
 *
 * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-zzp-tax-regime/spec.md
 */
class UrencriteriumGuard
{
    /**
     * Categories that do not count toward the urencriterium per Wet IB 2001.
     *
     * @var array<int, string>
     */
    private const EXCLUDED_CATEGORIES = ['excluded'];

    /**
     * Construct the guard with lazy DI of OR's ObjectService.
     *
     * @param ContainerInterface $container DI container — OR's ObjectService is fetched lazily.
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
     * Sum YTD qualifying hours for a person in the given calendar year.
     *
     * Pages through all UrenRegistratie records for the person/year via OR's
     * real `setRegister()->setSchema()->findAll()` API and sums the `hours`
     * field, skipping records whose category is statutorily excluded.
     *
     * @param string $personId The personId to aggregate.
     * @param int    $year     The calendar year.
     *
     * @return float The YTD qualifying hours total.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-zzp-tax-regime/spec.md (REQ-ZZP-003)
     */
    public function currentYtdHours(string $personId, int $year): float
    {
        if ($personId === '') {
            return 0.0;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $pageSize  = 500;
            $page      = 1;
            $total     = 0.0;
            $batchSize = 0;
            do {
                $batch = $objectService
                    ->setRegister($this->getRegisterSlug())
                    ->setSchema('UrenRegistratie')
                    ->findAll(
                        [
                            'filters' => [
                                'personId' => $personId,
                                'year'     => $year,
                            ],
                            'limit'   => $pageSize,
                            'offset'  => (($page - 1) * $pageSize),
                        ]
                    );

                foreach ($batch as $registratie) {
                    $category = (string) ($registratie['category'] ?? '');
                    if (in_array($category, self::EXCLUDED_CATEGORIES, true) === true) {
                        continue;
                    }

                    $total += (float) ($registratie['hours'] ?? 0);
                }

                $batchSize = count($batch);
                $page++;
            } while ($batchSize === $pageSize);

            return $total;
        } catch (\Throwable $e) {
            $this->logger->error(
                'UrencriteriumGuard: YTD hours computation failed',
                ['exception' => $e->getMessage()]
            );
            return 0.0;
        }//end try

    }//end currentYtdHours()

    /**
     * Precondition: the person meets the 1225-urencriterium for the year.
     *
     * @param array<string, mixed> $deduction ZzpDeduction object array (loaded by OR).
     *
     * @return bool True when YTD qualifying hours >= 1225.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-zzp-tax-regime/spec.md (REQ-ZZP-004)
     */
    public function qualifies(array $deduction): bool
    {
        $personId = (string) ($deduction['personId'] ?? '');
        $year     = (int) ($deduction['year'] ?? (int) date('Y'));

        return $this->currentYtdHours(personId: $personId, year: $year) >= 1225.0;

    }//end qualifies()
}//end class
