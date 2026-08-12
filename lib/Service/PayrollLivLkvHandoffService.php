<?php

/**
 * Payroll LIV/LKV Handoff Service
 *
 * Aggregates Werknemer.inkomenniveau + the year's LoonStrook.fiscaalLoon
 * sum into a LIV/LKV eligibility payload that the future
 * bookkeeping-liv-lkv app consumes to claim the Lage Inkomens Voordeel
 * (LIV) and Loonkostenvoordelen (LKV) at UWV. This service does not make
 * the LIV/LKV claim itself; it produces the per-werknemer per-jaar payload
 * with the eligibility input shape stable for the downstream app.
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
 * Pure aggregate: per-(werknemer, jaar) LIV/LKV eligibility payload.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
class PayrollLivLkvHandoffService
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
     * Build the LIV/LKV eligibility payload for one werknemer + year.
     *
     * Returns: werknemerId, jaar, inkomenniveau (Werknemer master), totaal
     * fiscaalLoon for the year, contracturen per week (Werknemer master),
     * any LKV-categorie carried on the Werknemer (banenafspraak,
     * herplaatsing, doelgroepverklaring), and the administrationId scope.
     *
     * @param string $administrationId Administration scope (server-resolved).
     * @param string $werknemerId      Employee id.
     * @param int    $jaar             Calendar year.
     *
     * @return array<string,mixed>|null The eligibility payload or null when werknemer not found.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function toLivLkvEligibilityPayload(string $administrationId, string $werknemerId, int $jaar): ?array
    {
        $werknemer = $this->findWerknemer(administrationId: $administrationId, werknemerId: $werknemerId);
        if ($werknemer === null) {
            return null;
        }

        $totaalFiscaal = $this->sumFiscaalLoonYear(
            administrationId: $administrationId,
            werknemerId: $werknemerId,
            jaar: $jaar
        );

        return [
            'employeeId'          => $werknemerId,
            'year'                => $jaar,
            'inkomenniveau'       => (string) ($werknemer['inkomenniveau'] ?? ''),
            'fiscaalLoonJaar'     => $totaalFiscaal,
            'contracturenPerWeek' => (float) ($werknemer['contracturenPerWeek'] ?? 0),
            'lkvCategorie'        => (string) ($werknemer['lkvCategorie'] ?? ''),
            'doelgroepverklaring' => (bool) ($werknemer['doelgroepverklaring'] ?? false),
            'administrationId'    => $administrationId,
            'source'              => 'Werknemer+LoonStrook',
        ];

    }//end toLivLkvEligibilityPayload()

    /**
     * Look up the werknemer (administration-scoped).
     *
     * @param string $administrationId Administration scope.
     * @param string $werknemerId      Employee id.
     *
     * @return array<string,mixed>|null
     */
    private function findWerknemer(string $administrationId, string $werknemerId): ?array
    {
        $results = $this->objectService()
            ->setRegister($this->register())
            ->setSchema('Werknemer')
            ->findAll(
                [
                    'filters' => [
                        'administrationId' => $administrationId,
                        'id'               => $werknemerId,
                    ],
                ]
            );

        foreach ($results as $r) {
            return (array) $r;
        }

        return null;

    }//end findWerknemer()

    /**
     * Sum LoonStrook.fiscaalLoon for the (werknemer, jaar) tuple.
     *
     * @param string $administrationId Administration scope.
     * @param string $werknemerId      Employee id.
     * @param int    $jaar             Calendar year.
     *
     * @return float Sum in euros.
     */
    private function sumFiscaalLoonYear(string $administrationId, string $werknemerId, int $jaar): float
    {
        $results = $this->objectService()
            ->setRegister($this->register())
            ->setSchema('LoonStrook')
            ->findAll(
                [
                    'filters' => [
                        'administrationId' => $administrationId,
                        'employeeId'       => $werknemerId,
                    ],
                ]
            );

        $totaalC = 0;
        foreach ($results as $r) {
            $row     = (array) $r;
            $periode = (string) ($row['periodId'] ?? '');
            if (preg_match('/(?<year>20[0-9]{2})/', $periode, $m) === 1 && (int) $m['year'] !== $jaar) {
                continue;
            }

            $totaalC += $this->calculator->toCents(amount: (float) ($row['fiscalLoon'] ?? 0));
        }

        return $this->calculator->fromCents(cents: $totaalC);

    }//end sumFiscaalLoonYear()

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
