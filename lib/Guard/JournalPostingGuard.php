<?php

/**
 * Journal Posting Guard
 *
 * Lifecycle preconditions and the cross-schema GL-materialisation seam for
 * JournalEntry state transitions, referenced from
 * lib/Settings/shillinq_register.json. Thin PHP seam per ADR-031 Risk-3
 * exception ("PHP guards remain a legitimate seam") — the declarative
 * lifecycle engine cannot, in T1, emit the cross-schema GLTransaction + GLLine
 * effect that posting a journal requires, so that single effect lives here.
 * No domain orchestration beyond materialisation + the balance precondition.
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
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
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
use RuntimeException;
use Throwable;

/**
 * Guards JournalEntry post transitions and materialises the GL posting.
 *
 * Methods are referenced by name from the JournalEntry schema's
 * x-openregister-lifecycle `requires:` clauses and `hooks.onPost.action`.
 * Precondition methods return true when the transition is permitted, false
 * otherwise. The materialisation method creates exactly one balanced
 * GLTransaction (with N GLLine children) atomically per REQ-JE-007.
 *
 * Server-authoritative amounts: balances are computed from the stored
 * `lines[].amountCents` integers only — never from any client-supplied total.
 *
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
 */
class JournalPostingGuard
{
    /**
     * Construct the guard with lazy DI of OR's ObjectService.
     *
     * @param ContainerInterface $container DI container — OR's ObjectService is fetched
     *                                      lazily so this class stays usable before the
     *                                      sibling GLTransaction register exists.
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
     * Single source of truth — mirrors AccountBalanceGuard so all reads and
     * writes use the same register even when the admin reconfigures the slug.
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
     * Sum the journal's debit and credit lines in integer cents and report
     * whether the entry is balanced (total debit === total credit).
     *
     * Integer-cent arithmetic avoids IEEE-754 float-equality drift: amounts
     * are stored as `amountCents` integers, so no rounding is involved.
     *
     * @param array<string, mixed> $journal JournalEntry object array (loaded by OR)
     *
     * @return bool True when total debit equals total credit
     */
    private function isBalanced(array $journal): bool
    {
        $lines = ($journal['lines'] ?? []);
        if (is_array($lines) === false || $lines === []) {
            // An empty journal cannot be a balanced posting.
            return false;
        }

        $debitCents  = 0;
        $creditCents = 0;
        foreach ($lines as $line) {
            if (is_array($line) === false) {
                return false;
            }

            $amountCents = (int) ($line['amountCents'] ?? 0);
            if ($amountCents < 0) {
                // Negative amounts are not permitted; polarity is the side enum.
                return false;
            }

            $side = ($line['side'] ?? '');
            if ($side === 'debit') {
                $debitCents += $amountCents;
                continue;
            }

            if ($side === 'credit') {
                $creditCents += $amountCents;
                continue;
            }

            // Unknown side cannot be classified — treat as unbalanced.
            return false;
        }//end foreach

        return $debitCents === $creditCents && $debitCents > 0;

    }//end isBalanced()

    /**
     * Precondition for the `submit` transition (draft → pending): the journal
     * must be balanced before it can be routed for approval (REQ-JE-007 /
     * REQ-GL-005 consumer side). An unbalanced journal never enters the
     * approval queue.
     *
     * @param array<string, mixed> $journal JournalEntry object array (loaded by OR)
     *
     * @return bool True when the journal is balanced
     *
     * @spec openspec/specs/bookkeeping-journal-entries/spec.md (REQ-JE-007)
     */
    public function requireBalanced(array $journal): bool
    {
        $balanced = $this->isBalanced(journal: $journal);
        if ($balanced === false) {
            $this->logger->info(
                'JournalPostingGuard: journal is not balanced (boeking is niet gebalanceerd) — submit denied',
                ['journalNumber' => ($journal['journalNumber'] ?? 'unknown')]
            );
        }

        return $balanced;

    }//end requireBalanced()

    /**
     * Precondition for the `postDirect` / `postApproved` transitions: the
     * journal MUST be balanced AND, when approval is required, already approved.
     *
     * The post transition that follows triggers materialisation via the
     * `hooks.onPost.action` → materializeGLTransaction. Returning false here
     * blocks the transition atomically — no GLTransaction is created and the
     * journal stays in its current state (REQ-JE-007 atomic-failure scenario).
     *
     * Approval enforcement is server-authoritative: a journal whose
     * `approvalState` is `pending` or `rejected` can never be posted, even if a
     * client requests it. Approval routing itself is OR's approval-workflow
     * extension's responsibility per ADR-022; this guard only refuses to post
     * an un-approved journal.
     *
     * @param array<string, mixed> $journal JournalEntry object array (loaded by OR)
     *
     * @return bool True when the journal may be posted
     *
     * @spec openspec/specs/bookkeeping-journal-entries/spec.md (REQ-JE-007, REQ-JE-008)
     */
    public function requirePostable(array $journal): bool
    {
        if ($this->isBalanced(journal: $journal) === false) {
            $this->logger->info(
                'JournalPostingGuard: post denied — boeking is niet gebalanceerd',
                ['journalNumber' => ($journal['journalNumber'] ?? 'unknown')]
            );
            return false;
        }

        // Approval gate (REQ-JE-008): a journal that still needs approval and
        // has not been approved MUST NOT post. `not-required` and `approved`
        // are the only states from which a post may proceed.
        $approvalState = ($journal['approvalState'] ?? 'not-required');
        if (in_array($approvalState, ['not-required', 'approved'], true) === false) {
            $this->logger->info(
                'JournalPostingGuard: post denied — approval not granted',
                [
                    'journalNumber' => ($journal['journalNumber'] ?? 'unknown'),
                    'approvalState' => $approvalState,
                ]
            );
            return false;
        }

        return true;

    }//end requirePostable()

    /**
     * Materialise exactly one balanced GLTransaction (with N GLLine children
     * derived 1:1 from the journal's lines) and write back `glTransactionId`,
     * atomically, per REQ-JE-007. Invoked by the schema's
     * `x-openregister-lifecycle.hooks.onPost.action` on every transition into
     * `posted`.
     *
     * ADR-031 Risk-3 exception: this is the one cross-schema effect that the
     * declarative engine cannot express in T1. It performs no domain
     * orchestration beyond the 1:1 line copy and the back-reference write.
     *
     * Atomicity: if the GLTransaction register is not yet available (sibling
     * change has not shipped), or any step throws, the method records the gap
     * and returns false so the lifecycle engine aborts the post — leaving the
     * journal in its prior state with no partial GLTransaction.
     *
     * @param array<string, mixed> $journal JournalEntry object array (loaded by OR)
     *
     * @return bool True when the GLTransaction was materialised and posted
     *
     * @spec openspec/specs/bookkeeping-journal-entries/spec.md (REQ-JE-007)
     */
    public function materializeGLTransaction(array $journal): bool
    {
        // Re-validate the balance invariant at the materialisation seam so the
        // guard is safe even if invoked directly (defence in depth).
        if ($this->isBalanced(journal: $journal) === false) {
            $this->logger->warning(
                'JournalPostingGuard: refusing to materialise an unbalanced journal',
                ['journalNumber' => ($journal['journalNumber'] ?? 'unknown')]
            );
            return false;
        }

        if ($this->isGLTransactionRegisterAvailable() === false) {
            // The sibling general-ledger change has not shipped the GLTransaction
            // schema yet. Record the gap; the lifecycle engine must not mark the
            // journal posted without a materialised transaction.
            $this->logger->error(
                'JournalPostingGuard: GLTransaction register not available — cannot materialise (gap filed as OR issue)',
                ['journalNumber' => ($journal['journalNumber'] ?? 'unknown')]
            );
            return false;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $administrationId = (string) ($journal['administrationId'] ?? '');
            $currency         = 'EUR';

            // 1. Create the balanced GLTransaction header in posted state.
            $transaction = $objectService
                ->setRegister($this->getRegisterSlug())
                ->setSchema('GLTransaction')
                ->saveObject(
                    [
                        'administrationId' => $administrationId,
                        'transactionDate'  => (string) ($journal['entryDate'] ?? ''),
                        'description'      => (string) ($journal['description'] ?? ''),
                        'currency'         => $currency,
                        'journalEntryId'   => (string) ($journal['id'] ?? ''),
                        'state'            => 'posted',
                    ]
                );

            $transactionId = $this->extractId(saved: $transaction);
            if ($transactionId === '') {
                throw new RuntimeException('GLTransaction id missing after save');
            }

            // 2. Create N GLLine children, 1:1 from the journal lines.
            $lineNumber = 1;
            foreach (($journal['lines'] ?? []) as $line) {
                $amountCents = (int) ($line['amountCents'] ?? 0);
                $objectService
                    ->setRegister($this->getRegisterSlug())
                    ->setSchema('GLLine')
                    ->saveObject(
                        [
                            'transactionId' => $transactionId,
                            'lineNumber'    => $lineNumber,
                            'accountNumber' => (string) ($line['accountNumber'] ?? ''),
                            'side'          => (string) ($line['side'] ?? ''),
                            // GLLine.amount is a decimal; convert from integer cents.
                            'amount'        => round($amountCents / 100, 2),
                            'currency'      => $currency,
                            'description'   => ($line['description'] ?? null),
                        ]
                    );
                $lineNumber++;
            }

            // 3. Write back the journal's glTransactionId.
            $journal['glTransactionId'] = $transactionId;
            $objectService
                ->setRegister($this->getRegisterSlug())
                ->setSchema('JournalEntry')
                ->saveObject($journal);

            $this->logger->info(
                'JournalPostingGuard: materialised GLTransaction for journal',
                [
                    'journalNumber' => ($journal['journalNumber'] ?? 'unknown'),
                    'transactionId' => $transactionId,
                ]
            );

            return true;
        } catch (Throwable $e) {
            // Fail-closed and atomic: the lifecycle engine aborts the post on
            // false, so no partial posted-state with a missing transaction.
            $this->logger->error(
                'JournalPostingGuard: GLTransaction materialisation failed — aborting post (atomic)',
                ['exception' => $e->getMessage()]
            );
            return false;
        }//end try

    }//end materializeGLTransaction()

    /**
     * Extract the persisted object id from OR's saveObject return value, which
     * may be an array or an entity exposing getId()/getUuid().
     *
     * @param mixed $saved The saveObject return value.
     *
     * @return string The id, or '' when none can be resolved.
     */
    private function extractId(mixed $saved): string
    {
        if (is_array($saved) === true) {
            return (string) ($saved['id'] ?? ($saved['uuid'] ?? ''));
        }

        if (is_object($saved) === true) {
            if (method_exists($saved, 'getId') === true) {
                $id = $saved->getId();
                if ($id !== null && $id !== '') {
                    return (string) $id;
                }
            }

            if (method_exists($saved, 'getUuid') === true) {
                return (string) $saved->getUuid();
            }
        }

        return '';

    }//end extractId()

    /**
     * Probe whether the GLTransaction schema is declared in the configured
     * register (i.e. the sibling general-ledger change has shipped). Uses OR's
     * real API: a "schema not found" exception means it is absent.
     *
     * @return bool True when the GLTransaction schema exists.
     */
    private function isGLTransactionRegisterAvailable(): bool
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $objectService
                ->setRegister($this->getRegisterSlug())
                ->setSchema('GLTransaction')
                ->findAll(['limit' => 1]);
            return true;
        } catch (Throwable) {
            return false;
        }

    }//end isGLTransactionRegisterAvailable()
}//end class
