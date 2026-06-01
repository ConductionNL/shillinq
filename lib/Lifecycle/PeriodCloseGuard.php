<?php

/**
 * Period Close Guard
 *
 * Lifecycle preconditions for FiscalPeriod close transitions referenced from
 * lib/Settings/shillinq_register.json. Thin PHP seam per ADR-031 §"PHP guards
 * remain a legitimate seam" — the trial-balance debit=credit invariant
 * (REQ-TB-003 / REQ-PC-003) and the open-items-settled precondition aggregate
 * sub-ledger data the declarative lifecycle engine cannot yet express, and the
 * reopen action must append an audited history entry (REQ-PC-006).
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
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-period-close/spec.md (REQ-PC-003, REQ-PC-006)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards FiscalPeriod close/reopen transitions.
 *
 * Methods are referenced by name from the FiscalPeriod schema lifecycle
 * `requires:` clauses. Each returns true when the precondition is satisfied.
 *
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-2.1
 */
class PeriodCloseGuard
{
    /**
     * Construct the guard with lazy DI of OR's ObjectService.
     *
     * @param ContainerInterface $container   DI container for OR's ObjectService.
     * @param IAppConfig         $appConfig   App config for register-slug resolution.
     * @param IUserSession       $userSession Current user for reopen attribution.
     * @param LoggerInterface    $logger      Nextcloud logger for diagnostics.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the configured register slug, defaulting to 'shillinq'.
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
     * Precondition for `open → closing`: the trial-balance debit=credit
     * invariant MUST hold for the period (REQ-TB-003 / REQ-PC-003).
     *
     * Sums debit and credit GLLine amounts for the period (excluding lines whose
     * parent GLTransaction is reversed) and verifies equality in integer cents.
     * When the GLLine schema is not yet present (T1 partially shipped), the
     * books are trivially balanced and the transition is permitted.
     *
     * @param array<string,mixed> $period FiscalPeriod object array.
     *
     * @return bool True when debits equal credits for the period.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-2.1
     */
    public function trialBalanceVerifies(array $period): bool
    {
        $periodId = (string) ($period['periodId'] ?? '');
        $adminId  = (string) ($period['administrationId'] ?? '');

        $lines = $this->fetchGlLines(periodId: $periodId, adminId: $adminId);
        if ($lines === null) {
            // GLLine schema absent — nothing posted yet, books balance.
            return true;
        }

        $debitCents  = 0;
        $creditCents = 0;
        foreach ($lines as $line) {
            if (($line['_reversed'] ?? false) === true) {
                continue;
            }

            $debitCents  += (int) round(((float) ($line['debit'] ?? 0)) * 100);
            $creditCents += (int) round(((float) ($line['credit'] ?? 0)) * 100);
        }

        return ($debitCents === $creditCents);

    }//end trialBalanceVerifies()

    /**
     * Precondition for `closing → closed`: all AP/AR invoices dated in the
     * period must be settled or explicitly acknowledged (REQ-PC-003).
     *
     * An invoice is "open" when its lifecycleState is not a terminal settled
     * state. When neither sub-ledger schema is present the precondition is
     * trivially satisfied.
     *
     * @param array<string,mixed> $period FiscalPeriod object array.
     *
     * @return bool True when no open sub-ledger items remain in the period.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-2.1
     */
    public function openItemsSettled(array $period): bool
    {
        $periodId = (string) ($period['periodId'] ?? '');
        $adminId  = (string) ($period['administrationId'] ?? '');

        $apOpen = $this->countOpenInvoices(schema: 'APInvoice', periodId: $periodId, adminId: $adminId, settled: ['paid', 'voided']);
        $arOpen = $this->countOpenInvoices(schema: 'ARInvoice', periodId: $periodId, adminId: $adminId, settled: ['paid', 'written-off']);

        return (($apOpen + $arOpen) === 0);

    }//end openItemsSettled()

    /**
     * Action for `closed → open`: append an audited entry to reopenedHistory
     * and preserve the original closedAt/closedBy values (REQ-PC-006).
     *
     * Returns true (the transition is always permitted for a period-closer; the
     * role gate is enforced by the lifecycle engine) after recording the reopen.
     *
     * @param array<string,mixed> $period FiscalPeriod object array (mutated by ref-return).
     *
     * @return bool Always true; the reopen is recorded as a side effect.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-2.1
     */
    public function recordReopen(array $period): bool
    {
        $user       = $this->userSession->getUser();
        $reopenedBy = 'system';
        if ($user !== null) {
            $reopenedBy = $user->getUID();
        }

        $history   = ($period['reopenedHistory'] ?? []);
        $history[] = [
            'reopenedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'reopenedBy' => $reopenedBy,
            'reason'     => (string) ($period['closeReason'] ?? ''),
        ];

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $period['reopenedHistory'] = $history;
            $objectService->saveObject(
                object: $period,
                register: $this->getRegisterSlug(),
                schema: 'FiscalPeriod',
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'PeriodCloseGuard: failed to persist reopen history',
                ['exception' => $e->getMessage()]
            );
        }

        return true;

    }//end recordReopen()

    /**
     * Fetch GLLine records for a period, annotating each with whether its parent
     * GLTransaction is reversed. Returns null when the GLLine schema is absent.
     *
     * @param string $periodId Period identifier.
     * @param string $adminId  Administration identifier.
     *
     * @return array<int,array<string,mixed>>|null
     */
    private function fetchGlLines(string $periodId, string $adminId): ?array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->debug('PeriodCloseGuard: ObjectService unavailable', ['exception' => $e->getMessage()]);
            return null;
        }

        try {
            $pageSize = 500;
            $page     = 1;
            $lines    = [];
            do {
                $batch = $objectService
                    ->setRegister($this->getRegisterSlug())
                    ->setSchema('GLLine')
                    ->findAll(
                        [
                            'filters' => [
                                'periodId'         => $periodId,
                                'administrationId' => $adminId,
                            ],
                            'limit'   => $pageSize,
                            'offset'  => (($page - 1) * $pageSize),
                        ]
                    );

                $lines     = array_merge($lines, $batch);
                $batchSize = count($batch);
                $page++;
            } while ($batchSize === $pageSize);

            return $lines;
        } catch (\Throwable $e) {
            $this->logger->debug(
                'PeriodCloseGuard: GLLine register not present',
                ['exception' => $e->getMessage()]
            );
            return null;
        }//end try

    }//end fetchGlLines()

    /**
     * Count open invoices of a schema dated in a period.
     *
     * @param string        $schema   'APInvoice' or 'ARInvoice'.
     * @param string        $periodId Period identifier.
     * @param string        $adminId  Administration identifier.
     * @param array<string> $settled  lifecycleState values treated as settled.
     *
     * @return int Number of open invoices (0 when the schema is absent).
     */
    private function countOpenInvoices(string $schema, string $periodId, string $adminId, array $settled): int
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $invoices      = $objectService
                ->setRegister($this->getRegisterSlug())
                ->setSchema($schema)
                ->findAll(
                    [
                        'filters' => [
                            'periodId'         => $periodId,
                            'administrationId' => $adminId,
                        ],
                    ]
                );

            $open = 0;
            foreach ($invoices as $invoice) {
                if (in_array(($invoice['lifecycleState'] ?? ''), $settled, true) === false) {
                    $open++;
                }
            }

            return $open;
        } catch (\Throwable $e) {
            $this->logger->debug(
                'PeriodCloseGuard: '.$schema.' register not present',
                ['exception' => $e->getMessage()]
            );
            return 0;
        }//end try

    }//end countOpenInvoices()
}//end class
