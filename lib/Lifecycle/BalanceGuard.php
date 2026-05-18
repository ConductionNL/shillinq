<?php

/**
 * Balance Guard — GL Transaction post-transition precondition.
 *
 * Single-method lifecycle guard per ADR-031 §"PHP guards remain a legitimate
 * seam". The double-entry balance invariant (sum of debit GLLine amounts =
 * sum of credit GLLine amounts) cannot be expressed as a declarative
 * cross-schema aggregation inside `x-openregister-lifecycle.requires` in the
 * current OR engine (see openspec/changes/spec/design.md §Declarative-vs-imperative
 * decision, row "Balance precondition"). This guard is the ADR-031 exception
 * path: exactly one method, no domain logic beyond the invariant check,
 * stateless, fail-closed.
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
 * @spec openspec/changes/spec/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the GLTransaction `draft → posted` lifecycle transition.
 *
 * Referenced from `lib/Settings/shillinq_register.json` under
 * `GLTransaction.x-openregister-lifecycle.transitions.post.requires`.
 *
 * ADR-031 exception: this guard exists because the OR engine cannot express
 * a cross-schema SUM aggregation inside a `requires` precondition. If the
 * engine gains that capability, this class MUST be retired and the check
 * moved to the declarative schema metadata.
 *
 * @spec openspec/changes/spec/tasks.md#task-7
 */
class BalanceGuard
{
    /**
     * Construct the guard with lazy DI of OR's ObjectService.
     *
     * @param ContainerInterface $container DI container — ObjectService fetched lazily.
     * @param LoggerInterface    $logger    Logger for fail-closed diagnostics.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Precondition for the GLTransaction `draft → posted` transition.
     *
     * Returns true iff the sum of all child GLLine.amount WHERE side='debit'
     * equals the sum WHERE side='credit' for the given transactionId, computed
     * at cent-level precision (zero rounding tolerance per REQ-GL-005).
     *
     * Fail-closed: any ObjectService error returns false, denying the transition.
     * This prevents a briefly unavailable OR from silently passing an unbalanced
     * posting through (CWE-863 avoidance per ADR-005 Rule 3).
     *
     * @param array<string, mixed> $transaction GLTransaction object array (loaded by OR).
     *
     * @return bool True when debits equal credits and the posting is balanced.
     *
     * @spec openspec/changes/spec/tasks.md#task-7 (REQ-GL-005)
     */
    public function isBalanced(array $transaction): bool
    {
        $transactionId = ($transaction['id'] ?? null);
        if ($transactionId === null || $transactionId === '') {
            $this->logger->warning(
                'BalanceGuard: transaction has no id — denying post (fail-closed)',
                ['transaction' => $transaction]
            );
            return false;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $lines = $objectService->findObjects(
                register: 'shillinq',
                schema: 'GLLine',
                params: [
                    'transactionId' => $transactionId,
                    '_limit'        => 1000,
                ]
            );

            if (count($lines) < 2) {
                $this->logger->info(
                    'BalanceGuard: transaction has fewer than 2 lines — cannot be balanced',
                    ['transactionId' => $transactionId, 'lineCount' => count($lines)]
                );
                return false;
            }

            $debitSum  = '0';
            $creditSum = '0';

            foreach ($lines as $line) {
                $amount = (string) ($line['amount'] ?? '0');
                $side   = ($line['side'] ?? '');
                if ($side === 'debit') {
                    $debitSum = $this->addDecimal(a: $debitSum, b: $amount);
                    continue;
                }

                $creditSum = $this->addDecimal(a: $creditSum, b: $amount);
            }

            $balanced = $debitSum === $creditSum;

            if ($balanced === false) {
                $this->logger->info(
                    'BalanceGuard: transaction is not balanced — denying post',
                    [
                        'transactionId' => $transactionId,
                        'debitSum'      => $debitSum,
                        'creditSum'     => $creditSum,
                    ]
                );
            }

            return $balanced;
        } catch (\Throwable $e) {
            $this->logger->error(
                'BalanceGuard: balance computation failed — denying post (fail-closed)',
                ['transactionId' => $transactionId, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try

    }//end isBalanced()

    /**
     * Add two decimal strings at cent precision (2 decimal places).
     *
     * Uses bcmath when available; falls back to float arithmetic as a best-effort
     * approximation (PHP's float precision is sufficient for amounts up to ~1e13
     * EUR at 2 decimal places).
     *
     * @param string $a First operand.
     * @param string $b Second operand.
     *
     * @return string Sum formatted to exactly 2 decimal places.
     */
    private function addDecimal(string $a, string $b): string
    {
        if (function_exists('bcadd') === true) {
            return bcadd(num1: $a, num2: $b, scale: 2);
        }

        return number_format(num: (float) $a + (float) $b, decimals: 2, decimal_separator: '.', thousands_separator: '');

    }//end addDecimal()
}//end class
