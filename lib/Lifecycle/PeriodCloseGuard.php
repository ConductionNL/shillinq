<?php

/**
 * Period Close Guard
 *
 * ADR-031 exception-path lifecycle guard for the FiscalPeriod close transition.
 * Referenced from the FiscalPeriod schema's x-openregister-lifecycle
 * transitions.start-close.requires clause as
 * OCA\Shillinq\Lifecycle\PeriodCloseGuard::trialBalanceVerifies.
 *
 * ADR-031 exception reason: the trial-balance invariant (sum of period debits
 * equals sum of period credits across all posted GL lines — REQ-TB-003) is a
 * cross-schema aggregation (SUM of GLLine grouped by side, filtered by the
 * period's GLTransactions) that the declarative lifecycle DSL cannot yet
 * express inside a `requires:` clause. The single method trialBalanceVerifies()
 * performs the aggregation in PHP. When the engine gains cross-schema
 * aggregation in lifecycle preconditions, replace this reference with a
 * declarative condition and delete this file.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-6.1
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
 * Lifecycle precondition guard for the FiscalPeriod start-close transition.
 *
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-6.1
 */
class PeriodCloseGuard
{
    /**
     * Construct the guard with DI dependencies.
     *
     * @param ContainerInterface $container DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig App config for the register slug.
     * @param LoggerInterface    $logger    Logger for fail-closed diagnostics.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns true iff the trial balance for the given period verifies (REQ-TB-003).
     *
     * Sums all posted GLLine amounts for the period grouped by side and confirms
     * SUM(debit) == SUM(credit) using integer-cent arithmetic to avoid IEEE-754
     * float equality issues. Fail-closed: returns false on any exception so a
     * period can never be closed over an unverifiable ledger (CWE-863).
     *
     * @param string $periodId The FiscalPeriod.periodId to verify.
     *
     * @return bool True when the period's trial balance verifies and close may start.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-6.1
     */
    public function trialBalanceVerifies(string $periodId): bool
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $register      = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
            if ($register === '') {
                $register = 'shillinq';
            }

            $lines = $objectService
                ->setRegister($register)
                ->setSchema('GLLine')
                ->findAll(['filters' => ['periodId' => $periodId]]);

            $debitCents  = 0;
            $creditCents = 0;
            foreach ($lines as $line) {
                $cents = (int) round((float) ($line['amount'] ?? 0) * 100);
                if (($line['side'] ?? '') === 'debit') {
                    $debitCents += $cents;
                    continue;
                }

                $creditCents += $cents;
            }

            return $debitCents === $creditCents;
        } catch (\Throwable $e) {
            $this->logger->error(
                'PeriodCloseGuard: trial-balance verification failed — denying close (fail-closed)',
                ['periodId' => $periodId, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try

    }//end trialBalanceVerifies()
}//end class
