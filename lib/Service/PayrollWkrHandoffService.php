<?php

/**
 * Payroll WKR Handoff Service
 *
 * Sums the period's fiscaalLoon across all LoonStrook records into the
 * loonsom-totaal that feeds the bookkeeping-wkr ceiling-tracking (REQ-PAY-011,
 * design.md D9). The WKR-app uses the loonsom to compute the free space
 * (2,47 procent over loonsom tot 400k, 1,18 procent boven 400k in 2026) and
 * returns the eindheffingenWKR back to the payroll engine via
 * PayrollService::berekenLHAfdracht(...eindheffingenWKR=...).
 *
 * This service is read-only and side-effect-free besides the OR-scoped
 * findAll; it produces the loonsom payload but never updates the WKR-app's
 * own state.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * Emits the period loonsom for the WKR app to compute the free-space ceiling.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
class PayrollWkrHandoffService
{
    /**
     * Construct the service.
     *
     * @param ContainerInterface $container  DI container (OR's ObjectService is lazy).
     * @param IAppConfig         $appConfig  App config for the register slug.
     * @param PayrollCalculator  $calculator Cents arithmetic helper.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly PayrollCalculator $calculator,
    ) {
    }//end __construct()

    /**
     * Build the WKR loonsom payload for a LoonPeriode.
     *
     * The loonsom sums fiscaalLoon (taxable wage) over every LoonStrook in
     * the period; the WKR app divides it into "tot 400k" and "boven 400k"
     * tranches at its own boundary so this service does not pre-tranche.
     *
     * @param string $administrationId Administration scope (server-resolved).
     * @param string $periodeId        Period id.
     *
     * @return array<string,mixed> Loonsom payload for the WKR app.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function toWkrLoonsomPayload(string $administrationId, string $periodeId): array
    {
        $stroken = $this->findStroken(administrationId: $administrationId, periodeId: $periodeId);

        $loonsomC = 0;
        $aantal   = 0;
        foreach ($stroken as $strook) {
            $loonsomC += $this->calculator->toCents(amount: (float) ($strook['fiscalLoon'] ?? 0));
            $aantal++;
        }

        return [
            'periodId'        => $periodeId,
            'administrationId' => $administrationId,
            'loonsom'          => $this->calculator->fromCents(cents: $loonsomC),
            'aantalStroken'    => $aantal,
            'currency'         => 'EUR',
            'source'           => 'LoonStrook',
        ];

    }//end toWkrLoonsomPayload()

    /**
     * Read all LoonStrook records for the period, administration-scoped.
     *
     * @param string $administrationId Administration scope.
     * @param string $periodeId        Period id.
     *
     * @return array<int,array<string,mixed>>
     */
    private function findStroken(string $administrationId, string $periodeId): array
    {
        $results = $this->objectService()
            ->setRegister($this->register())
            ->setSchema('LoonStrook')
            ->findAll(
                [
                    'filters' => [
                        'administrationId' => $administrationId,
                        'periodId'        => $periodeId,
                    ],
                ]
            );

        $out = [];
        foreach ($results as $r) {
            $out[] = (array) $r;
        }

        return $out;

    }//end findStroken()

    /**
     * Lazily fetch OpenRegister's ObjectService.
     *
     * @return object The ObjectService.
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Resolve the configured OpenRegister register slug.
     *
     * @return string The register slug.
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
