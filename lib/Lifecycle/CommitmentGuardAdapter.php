<?php

/**
 * Commitment Guard Adapter
 *
 * Lets OpenRegister's lifecycle call the two commitment guards the Commitment
 * schema names, `MandateEnforcer::requiresApproval` on `indienen` and
 * `BudgetBlocker::canCommit` on `aangaan` and `goedkeuren`
 * (planning-commitment-year-end, REQ-PCYE-001). Both methods take
 * `(string $commitmentNumber, ?array $object)`, so the shared
 * RegisterRequiresGuardAdapter, which passes the object alone, cannot wrap
 * them. Neither tag was registered, so every one of those transitions failed
 * (shillinq#433 for these two).
 *
 * A budget refusal names the programme, the year and the shortfall.
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
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\Service\Commitment\CommitmentLedger;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adapts `bool <method>(string $commitmentNumber, ?array $object)` to a lifecycle guard.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
final class CommitmentGuardAdapter implements LifecycleGuardInterface {
	/**
	 * Constructor.
	 *
	 * @param MandateEnforcer|BudgetBlocker $guard       The wrapped guard.
	 * @param string                        $method      `requiresApproval` or `canCommit`.
	 * @param string                        $denyMessage The refusal when no better reason is known.
	 * @param CommitmentLedger              $ledger      Reads lines and budgets for the shortfall.
	 * @param LoggerInterface               $logger      Fail-closed diagnostics.
	 */
	public function __construct(
		private readonly MandateEnforcer|BudgetBlocker $guard,
		private readonly string $method,
		private readonly string $denyMessage,
		private readonly CommitmentLedger $ledger,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Allow or refuse the transition.
	 *
	 * @param array<string,mixed> $object The commitment.
	 * @param string              $action The transition.
	 * @param string              $userId The acting user.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-1.1
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$number = (string)($object['commitmentNumber'] ?? ($object['id'] ?? ''));
		try {
			$allowed = ($this->guard->{$this->method}($number, $object) === true);
		} catch (Throwable $e) {
			$this->logger->error(
				'CommitmentGuardAdapter: guard threw, transition refused',
				['method' => $this->method, 'action' => $action, 'exception' => $e->getMessage()]
			);
			return GuardResult::deny($this->denyMessage);
		}

		if ($allowed === true) {
			return GuardResult::allow();
		}

		if ($this->guard instanceof BudgetBlocker) {
			return GuardResult::deny($this->shortfall(commitment: $object) ?? $this->denyMessage);
		}

		return GuardResult::deny($this->denyMessage);
	}//end check()

	/**
	 * The first budget a commitment's lines do not fit, as a sentence.
	 *
	 * @param array<string,mixed> $commitment The commitment.
	 *
	 * @return string|null Null when no line is short (for example, a missing budget).
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-1.1
	 */
	public function shortfall(array $commitment): ?string {
		$administrationId = (string)($commitment['administrationId'] ?? '');
		foreach ($this->ledger->openLines(commitmentNumber: (string)($commitment['commitmentNumber'] ?? '')) as $line) {
			$programme = (string)($line['programme'] ?? '');
			$year = (int)($line['financialYear'] ?? 0);
			$budget = $this->ledger->budget(administrationId: $administrationId, programme: $programme, year: $year);
			if ($budget === null) {
				return sprintf('There is no %d budget for programme %s.', $year, $programme);
			}

			$short = ((int)($line['amount_excl_vat'] ?? 0) - $this->ledger->free(budget: $budget));
			if ($short > 0) {
				return sprintf(
					'The %d budget for programme %s is EUR %s short.',
					$year,
					$programme,
					number_format($short / 100, 2, '.', ',')
				);
			}
		}

		return null;
	}//end shortfall()
}//end class
