<?php

/**
 * Budget Overrun Guard
 *
 * ADR-031 exception-path lifecycle guard for the budget-overrun precondition on
 * GL posting (REQ-010). When a JournalEntry is materialised (boekstuk posted),
 * the system validates that the cumulative lasten posted to a programma /
 * taakveld do not exceed the authorized lasten of the vastgestelde begroting
 * plus its vastgestelde wijzigingen. If the booking would exceed the authorised
 * amount the post is denied with a budgetoverschrijding error, and any draft
 * wijzigingen that could resolve the overrun are surfaced.
 *
 * ADR-031 exception reason: the cumulative-posted SUM grouped by taakveld,
 * joined against the stacked authorized amount (basis + vastgestelde
 * wijzigingen), is not yet expressible in the declarative `requires:` clause.
 * This guard provides the fallback until the engine supports cross-schema
 * aggregation with the wijziging-stack join.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-27
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\BegrotingswijzigingStacker;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Precondition guard preventing GL lasten postings beyond the authorized budget.
 *
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-27
 */
class BudgetOverrunGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param BegrotingswijzigingStacker $stacker Computes the stacked authorized lasten.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly BegrotingswijzigingStacker $stacker,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Pure overrun check: would $attempted on top of $alreadyPosted exceed budget?
	 *
	 * Performed in integer euro-cents to avoid IEEE-754 drift. Returns true when
	 * the booking stays within (or exactly at) the authorized lasten.
	 *
	 * @param float $authorizedExpenses The stacked authorized lasten (basis + wijzigingen).
	 * @param float $alreadyPosted The cumulative lasten already posted to the taakveld.
	 * @param float $attempted The new posting amount.
	 *
	 * @return bool True when the posting is within budget.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-27
	 */
	public function isWithinBudget(float $authorizedExpenses, float $alreadyPosted, float $attempted): bool {
		$authorizedCents = (int)round($authorizedExpenses * 100);
		$postedCents = (int)round($alreadyPosted * 100);
		$attemptedCents = (int)round($attempted * 100);

		return (($postedCents + $attemptedCents) <= $authorizedCents);
	}//end isWithinBudget()

	/**
	 * Returns true iff posting $attempted lasten to a taakveld stays within budget.
	 *
	 * Resolves the vastgestelde basis Taakvelden and vastgestelde wijzigingen for
	 * the begroting, stacks them to the authorized lasten for the taakveldCode,
	 * sums the lasten already posted via GLLine, and checks the new posting fits.
	 * Fail-closed: returns false on any exception (CWE-863) so a lookup failure
	 * never silently authorises an over-budget booking.
	 *
	 * @param string $budgetId The Programmabegroting.id authorising the budget.
	 * @param string $taskFieldCode The taakveld being posted to.
	 * @param float $attempted The new lasten posting amount.
	 *
	 * @return bool True when the posting is within the stacked authorized lasten.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-27
	 */
	public function canPost(string $budgetId, string $taskFieldCode, float $attempted): bool {
		try {
			if ($budgetId === '' || $taskFieldCode === '') {
				return false;
			}

			$register = $this->resolveRegister();

			$basis = $this->toRows(
				rows: $this->objectService->setRegister($register)->setSchema('Taakveld')
					->findAll(['filters' => ['budgetId' => $budgetId]])
			);
			$wijzigingen = $this->toRows(
				rows: $this->objectService->setRegister($register)->setSchema('Begrotingswijziging')
					->findAll(['filters' => ['budgetId' => $budgetId]])
			);

			$authorized = $this->stacker->authorizedLasten(
				taskFieldCode: $taskFieldCode,
				basisTaskFields: $basis,
				wijzigingen: $wijzigingen
			);

			$glLines = $this->toRows(
				rows: $this->objectService->setRegister($register)->setSchema('GLLine')
					->findAll(['filters' => ['taskFieldCode' => $taskFieldCode, 'side' => 'debit']])
			);
			$alreadyPostedCents = 0;
			foreach ($glLines as $line) {
				$alreadyPostedCents += (int)round(((float)($line['amount'] ?? 0)) * 100);
			}

			return $this->isWithinBudget(
				authorizedExpenses: $authorized,
				alreadyPosted: ($alreadyPostedCents / 100),
				attempted: $attempted
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BudgetOverrunGuard: budget check failed — denying GL post (fail-closed)',
				['budgetId' => $budgetId, 'taskFieldCode' => $taskFieldCode, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canPost()

	/**
	 * Normalise an ObjectService result into an array of array rows.
	 *
	 * @param iterable<mixed> $rows The raw ObjectService result.
	 *
	 * @return array<int,array<string,mixed>> The array rows.
	 */
	private function toRows(iterable $rows): array {
		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end toRows()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
