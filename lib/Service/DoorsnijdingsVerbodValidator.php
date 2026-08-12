<?php

/**
 * Doorsnijdingsverbod Validator
 *
 * Enforces the doorsnijdingsverbod (non-duplication rule, Wet Vpb art. 12bd
 * lid 2, REQ-IBA-004): costs allocated to an innovatiebox asset
 * (IBExpenseAllocation with exclusief_in_winstbepaling=true) MUST NOT be
 * deducted again in the regular general ledger. The validator scans both feeds
 * per administration + boekjaar and flags any (grootboekrekening, kostenplaats)
 * pair that appears in BOTH. The findings are non-blocking warnings during the
 * year, but block the year-end close until resolved.
 *
 * Reads use the real OpenRegister ObjectService API (find/findAll, ADR-022) and
 * are scoped to the supplied administration (server-resolved, never client
 * trust). The pure-detection step (detectDuplicates) takes plain arrays so it
 * is unit-testable without OpenRegister.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-004
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
 * Detects innovatiebox/GL cost duplication (doorsnijdingsverbod, REQ-IBA-004).
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-004
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class DoorsnijdingsVerbodValidator
{
    /**
     * Construct the validator with lazy DI of OpenRegister's ObjectService.
     *
     * @param ContainerInterface                $container   DI container — OR's ObjectService is fetched
     *                                                       lazily.
     * @param IAppConfig                        $appConfig   App config for the register slug.
     * @param InnovatieboxAuditEventLogger|null $auditLogger Optional audit-event logger. When
     *                                                       provided, every validateNoDuplication
     *                                                       run emits a DoorsnijdingsVerbod.check_run
     *                                                       event with the findings (REQ-IBA-008).
     *                                                       Optional so the existing unit tests can
     *                                                       construct the validator without the
     *                                                       OpenRegister event chain.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly ?InnovatieboxAuditEventLogger $auditLogger=null,
    ) {
    }//end __construct()

    /**
     * Run the doorsnijdingsverbod check for an administration + boekjaar (REQ-IBA-004).
     *
     * Fetches the exclusive IBExpenseAllocation rows and the GL deduction lines
     * (GLLine) for the year, then cross-checks (grootboekrekening, kostenplaats)
     * pairs. Returns the findings and whether the year-end close may proceed.
     *
     * @param string $administrationId Administration scope (server-resolved).
     * @param int    $boekjaar         Fiscal year to validate.
     *
     * @return array{findings: array<int,array<string,mixed>>, blocking: bool, total: int}
     *
     * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-004
     */
    public function validateNoDuplication(string $administrationId, int $boekjaar): array
    {
        $allocations = $this->fetchExclusiveAllocations(administrationId: $administrationId, boekjaar: $boekjaar);
        $glLines     = $this->fetchGlDeductions(administrationId: $administrationId, boekjaar: $boekjaar);

        $findings = $this->detectDuplicates(allocations: $allocations, glLines: $glLines);

        if ($this->auditLogger !== null) {
            $totalAmount = 0.0;
            foreach ($findings as $finding) {
                $totalAmount += (float) ($finding['amount'] ?? 0);
            }

            if ($findings !== []) {
                $auditReason = 'doorsnijdingsverbod_duplicate';
            } else {
                $auditReason = null;
            }

            $this->auditLogger->record(
                options: [
                    'event_type'       => InnovatieboxAuditEventLogger::EVENT_DOORSNIJDINGSVERBOD_CHECK_RUN,
                    'administrationId' => $administrationId,
                    'financialYear'         => $boekjaar,
                    'reason'           => $auditReason,
                    'details'          => [
                        'findings'     => $findings,
                        'total_pairs'  => count($findings),
                        'total_bedrag' => $totalAmount,
                        'blocking'     => ($findings !== []),
                    ],
                ]
            );
        }//end if

        return [
            'findings' => $findings,
            'blocking' => ($findings !== []),
            'total'    => count($findings),
        ];

    }//end validateNoDuplication()

    /**
     * Pure-logic duplication detection (REQ-IBA-004).
     *
     * Builds the set of (grootboekrekening, kostenplaats) pairs present in the
     * GL deduction lines, then flags any exclusive allocation whose pair is in
     * that set. Each finding carries the pair, the allocated amount and a
     * human-readable message.
     *
     * @param array<int,array<string,mixed>> $allocations Exclusive IBExpenseAllocation rows.
     * @param array<int,array<string,mixed>> $glLines     GL deduction lines (account + kostenplaats).
     *
     * @return array<int,array<string,mixed>> Duplication findings (empty when clean).
     *
     * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-004
     */
    public function detectDuplicates(array $allocations, array $glLines): array
    {
        $glPairs = [];
        foreach ($glLines as $line) {
            $account = (string) ($line['accountNumber'] ?? ($line['grootboekrekening'] ?? ''));
            $plaats  = (string) ($line['kostenplaats'] ?? '');
            if ($account === '') {
                continue;
            }

            $glPairs[$account.'|'.$plaats] = true;
        }

        $findings = [];
        foreach ($allocations as $allocation) {
            if (($allocation['exclusief_in_winstbepaling'] ?? false) !== true) {
                continue;
            }

            $account = (string) ($allocation['grootboekrekening'] ?? '');
            $plaats  = (string) ($allocation['kostenplaats'] ?? '');
            if ($account === '') {
                continue;
            }

            if (isset($glPairs[$account.'|'.$plaats]) === true) {
                $bedrag     = (float) ($allocation['amount'] ?? 0);
                $plaatsText = '-';
                if ($plaats !== '') {
                    $plaatsText = $plaats;
                }

                $findings[] = [
                    'grootboekrekening' => $account,
                    'kostenplaats'      => $plaats,
                    'amount'            => $bedrag,
                    'message'           => sprintf(
                        'EUR %s (account %s, kostenplaats %s) appears in both innovatiebox '
                        .'allocation AND GL regular deduction. Resolve conflict before year-end close.',
                        number_format($bedrag, 0, ',', '.'),
                        $account,
                        $plaatsText
                    ),
                ];
            }
        }//end foreach

        return $findings;

    }//end detectDuplicates()

    /**
     * Fetch the exclusive IBExpenseAllocation rows for an administration + year.
     *
     * @param string $administrationId Administration scope.
     * @param int    $boekjaar         Fiscal year.
     *
     * @return array<int,array<string,mixed>> Exclusive allocation rows.
     */
    private function fetchExclusiveAllocations(string $administrationId, int $boekjaar): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $rows          = $objectService
            ->setRegister($this->register())
            ->setSchema('IBExpenseAllocation')
            ->findAll(
                [
                    'filters' => [
                        'administrationId'           => $administrationId,
                        'financialYear'                   => $boekjaar,
                        'exclusief_in_winstbepaling' => true,
                    ],
                ]
            );

        if (is_array($rows) === false) {
            return [];
        }

        return $rows;

    }//end fetchExclusiveAllocations()

    /**
     * Fetch the GL deduction lines for an administration + year.
     *
     * @param string $administrationId Administration scope.
     * @param int    $boekjaar         Fiscal year.
     *
     * @return array<int,array<string,mixed>> GL lines carrying accountNumber + kostenplaats.
     */
    private function fetchGlDeductions(string $administrationId, int $boekjaar): array
    {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $rows          = $objectService
            ->setRegister($this->register())
            ->setSchema('GLLine')
            ->findAll(['filters' => ['administrationId' => $administrationId, 'financialYear' => $boekjaar]]);

        if (is_array($rows) === false) {
            return [];
        }

        return $rows;

    }//end fetchGlDeductions()

    /**
     * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
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
