<?php

/**
 * Balance Guard
 *
 * ADR-031 exception-path lifecycle guard for GLTransaction balance invariant.
 * This guard is registered because OpenRegister's x-openregister-lifecycle
 * engine cannot yet express cross-schema aggregation queries (cross-line SUM)
 * inside the declarative `requires:` clause. The single method isBalanced()
 * performs the balance check in PHP and is referenced from the GLTransaction
 * schema's x-openregister-lifecycle.transitions.post.requires clause.
 *
 * ADR-031 exception reason: cross-schema aggregation (SUM of child GLLine rows
 * grouped by side) is not yet expressible in the declarative lifecycle DSL.
 * When the engine gains that capability, replace this reference with a
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
 * @spec openspec/changes/bookkeeping-general-ledger/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guard for GLTransaction balance invariant.
 *
 * Referenced from shillinq_register.json GLTransaction.x-openregister-lifecycle
 * transitions.post.requires as OCA\Shillinq\Lifecycle\BalanceGuard::isBalanced.
 *
 * @spec openspec/changes/bookkeeping-general-ledger/tasks.md#task-7
 */
class BalanceGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns true iff the GLTransaction's child lines are balanced (debit = credit).
	 *
	 * Queries all GLLine records for the given transactionId and computes
	 * SUM(amount WHERE side='debit') == SUM(amount WHERE side='credit') using
	 * integer-cent arithmetic to avoid IEEE-754 float equality issues.
	 *
	 * Fail-closed: returns false on any exception (REQ-GL-005 / CWE-863).
	 *
	 * @param string $transactionId The GLTransaction.id to check.
	 *
	 * @return bool True when the transaction is balanced and may be posted.
	 *
	 * @spec openspec/changes/bookkeeping-general-ledger/tasks.md#task-7
	 */
	public function isBalanced(string $transactionId): bool {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
			if ($register === '') {
				$register = 'shillinq';
			}

			$lines = $objectService
				->setRegister($register)
				->setSchema('GLLine')
				->findAll(['filters' => ['transactionId' => $transactionId]]);

			$debitCents = 0;
			$creditCents = 0;
			foreach ($lines as $line) {
				$cents = (int)round((float)($line['amount'] ?? 0) * 100);
				if (($line['side'] ?? '') === 'debit') {
					$debitCents += $cents;
					continue;
				}

				$creditCents += $cents;
			}

			return $debitCents === $creditCents;
		} catch (\Throwable $e) {
			$this->logger->error(
				'BalanceGuard: balance check failed — denying post transition (fail-closed)',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end isBalanced()
}//end class
