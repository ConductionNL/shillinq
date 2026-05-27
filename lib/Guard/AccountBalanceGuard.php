<?php

/**
 * Account Balance Guard
 *
 * Lifecycle preconditions for Account state transitions referenced from
 * lib/Settings/shillinq_register.json. Thin PHP seam per ADR-031
 * §"PHP guards remain a legitimate seam" — no domain logic, only
 * preconditions that the declarative lifecycle engine cannot express.
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
 * @spec openspec/changes/add-shillinq-chart-of-accounts/specs/bookkeeping-chart-of-accounts/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards Account lifecycle transitions + closing-account uniqueness.
 *
 * Methods are referenced by name from the Account schema's
 * x-openregister-lifecycle `requires:` clauses. Each returns true when the
 * precondition is satisfied (transition / save permitted), false otherwise.
 *
 * @spec openspec/changes/add-shillinq-chart-of-accounts/specs/bookkeeping-chart-of-accounts/spec.md
 */
class AccountBalanceGuard
{
    /**
     * Construct the guard with lazy DI of OR's ObjectService.
     *
     * @param ContainerInterface $container DI container — OR's ObjectService is fetched
     *                                      lazily so this class stays usable in T1
     *                                      before T2's GLLine register exists.
     * @param LoggerInterface    $logger    Nextcloud logger for fail-closed diagnostics.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Precondition for `archive*` transitions: the account's running balance
     * must be zero before it can be archived (otherwise downstream period-close
     * + financial-statements would see orphan postings).
     *
     * **T1 behaviour:** balance is implicitly 0 because no GLLine register
     * exists yet (T2 `bookkeeping-general-ledger` introduces it). Returns
     * true unconditionally with a debug log noting the deferral.
     *
     * **T2+ behaviour:** retrieves all GLLine records for the account via
     * OR's real `setRegister()->setSchema()->findAll()` API and sums
     * debit minus credit in PHP. Returns true iff the sum is zero.
     *
     * @param array<string, mixed> $account Account object array (loaded by OR)
     *
     * @return bool True when archive is permitted
     *
     * @spec openspec/changes/add-shillinq-chart-of-accounts/specs/bookkeeping-chart-of-accounts/spec.md (REQ-CoA-005)
     */
    public function requireZeroBalance(array $account): bool
    {
        if ($this->isGLLineRegisterAvailable() === false) {
            $this->logger->debug(
                'AccountBalanceGuard: GLLine register not present (T1 state) — archive permitted by default',
                ['accountNumber' => ($account['accountNumber'] ?? 'unknown')]
            );
            return true;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $lines         = $objectService
                ->setRegister('shillinq')
                ->setSchema('GLLine')
                ->findAll(
                        [
                            'filters' => [
                                'accountNumber'    => ($account['accountNumber'] ?? ''),
                                'administrationId' => ($account['administrationId'] ?? ''),
                            ],
                        ]
                        );

            $balance = array_sum(
                array_map(
                    static fn($line) => (float) ($line['debit'] ?? 0) - (float) ($line['credit'] ?? 0),
                    $lines
                )
            );

            return $balance === 0.0;
        } catch (\Throwable $e) {
            $this->logger->error(
                'AccountBalanceGuard: balance computation failed — denying archive (fail-closed)',
                ['exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end requireZeroBalance()

    /**
     * Precondition for save: at most one Account per administration may have
     * `isClosingAccount: true` (REQ-CoA-009). Called from the Account schema's
     * lifecycle on every save attempt. Returns false if a different Account
     * in the same administration already has `isClosingAccount: true`.
     *
     * Permits the same account to be re-saved (e.g., editing its name) — the
     * uniqueness check excludes the current record by primary key.
     *
     * @param array<string, mixed> $account Account object array (loaded by OR)
     *
     * @return bool True when the closing-account invariant holds
     *
     * @spec openspec/changes/add-shillinq-chart-of-accounts/specs/bookkeeping-chart-of-accounts/spec.md (REQ-CoA-009)
     */
    public function requireSingleClosingAccount(array $account): bool
    {
        if (($account['isClosingAccount'] ?? false) !== true) {
            // Account isn't being marked as closing — invariant trivially holds.
            return true;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $existing      = $objectService
                ->setRegister('shillinq')
                ->setSchema('Account')
                ->findAll(
                        [
                            'filters' => [
                                'administrationId' => ($account['administrationId'] ?? ''),
                                'isClosingAccount' => true,
                            ],
                            'limit'   => 2,
                        ]
                        );

            // Filter out the current account (by id, if persisted; by accountNumber otherwise).
            // Defence-in-depth: also verify administrationId matches to prevent cross-tenant leakage.
            $currentId            = ($account['id'] ?? null);
            $currentAccountNumber = ($account['accountNumber'] ?? null);
            $currentAdminId       = ($account['administrationId'] ?? null);
            $otherClosing         = array_filter(
                $existing,
                static function ($candidate) use ($currentId, $currentAccountNumber, $currentAdminId) {
                    // Cross-tenant defence: only consider candidates in the same administration.
                    if ($currentAdminId !== null && ($candidate['administrationId'] ?? null) !== $currentAdminId) {
                        return false;
                    }

                    if ($currentId !== null && ($candidate['id'] ?? null) === $currentId) {
                        return false;
                    }

                    if ($currentId === null && ($candidate['accountNumber'] ?? null) === $currentAccountNumber) {
                        return false;
                    }

                    return true;
                }
            );

            return count($otherClosing) === 0;
        } catch (\Throwable $e) {
            $this->logger->error(
                'AccountBalanceGuard: closing-account uniqueness check failed — denying save (fail-closed)',
                ['exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end requireSingleClosingAccount()

    /**
     * Probe whether the GLLine schema is declared in the shillinq register
     * (i.e. T2 has shipped). Uses OR's real API: attempt to find the schema
     * via setRegister + setSchema; absence is treated as T1 state.
     *
     * @return bool True when the GLLine schema exists in OR's `shillinq` register.
     */
    private function isGLLineRegisterAvailable(): bool
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->setRegister('shillinq')->setSchema('GLLine');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }//end isGLLineRegisterAvailable()
}//end class
