<?php

/**
 * BBV Provincie Programme Budget Reader
 *
 * The OpenRegister half of the provincies-BBV Compliance Dashboard
 * (#866/#862): every read the dashboard and the Budget-to-Programme Linker
 * need, and nothing else. {@see BbvProgrammeBudgetService} orchestrates,
 * {@see BbvProgrammeBudgetCalculator} does the arithmetic, and this class is
 * the only one that talks to the store.
 *
 * ## `filters` addresses JSON properties, and that is the correct use here
 *
 * Every lookup below filters on a DECLARED schema property —
 * `administrationId`, `state` — never on `id`. `findAll(['filters' => ['id' =>
 * …]])` matches NOTHING for every value, silently, because the entity's `id` is
 * its own column and not a property the filter can see.
 *
 * ## Why the GL-line query is unfiltered
 *
 * `GLLine` carries no `administrationId` of its own — it is scoped through its
 * parent `GLTransaction`. Filtering the lines on an administration would
 * therefore filter on a non-property and return zero rows for every value. The
 * lines are read whole and joined in memory against the POSTED transaction
 * index, which is itself administration-scoped, so the tenancy boundary is
 * enforced by the join rather than by a filter that could not work.
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
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the four schemas the provincies-BBV dashboards aggregate.
 *
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
class BbvProgrammeBudgetReader {
	/**
	 * Budget schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_BUDGET = 'Budget';

	/**
	 * GL transaction (header) schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_GL_TRANSACTION = 'GLTransaction';

	/**
	 * GL line schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_GL_LINE = 'GLLine';

	/**
	 * Commitment-line schema slug (verplichtingenadministratie).
	 *
	 * @var string
	 */
	public const SCHEMA_COMMITMENT_LINE = 'Verplichtingsregel';

	/**
	 * Chart-of-accounts schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_ACCOUNT = 'Account';

	/**
	 * Construct the reader.
	 *
	 * @param IAppConfig $appConfig App config (OpenRegister register slug).
	 * @param LoggerInterface $logger Logger — never receives a record body.
	 * @param ObjectServiceInterface $objectService OpenRegister object service (ADR-083/084).
	 * @param BbvBudgetVocabulary $vocabulary Reads a Budget under EITHER of the two
	 *                                        colliding `Budget` schemas — see that
	 *                                        class for why there are two.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly BbvBudgetVocabulary $vocabulary = new BbvBudgetVocabulary(),
	) {

	}//end __construct()

	/**
	 * Load the budgets in scope for the fiscal year.
	 *
	 * @param array<int,string> $administrationIds Administrations the caller may read.
	 * @param integer $fiscalYear Fiscal year.
	 * @param array<int,string>|null $statuses Budget-status filter; null = every status.
	 *
	 * @return list<array<string,mixed>> The matching budgets.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function budgetsFor(array $administrationIds, int $fiscalYear, ?array $statuses): array {
		$rows = [];
		foreach ($administrationIds as $administrationId) {
			$budgets = $this->query(
				schema: self::SCHEMA_BUDGET,
				filters: ['administrationId' => $administrationId]
			);
			foreach ($budgets as $budget) {
				if ($this->budgetYear(budget: $budget) !== $fiscalYear) {
					continue;
				}

				if ($statuses !== null && in_array((string)($budget['status'] ?? ''), $statuses, true) === false) {
					continue;
				}

				$rows[] = $budget;
			}
		}

		return $rows;

	}//end budgetsFor()

	/**
	 * Read a Budget's fiscal year across BOTH vocabularies the schema carries.
	 *
	 * ⚠️ `Budget` is declared TWICE in `lib/Settings/register.d/`, by two
	 * different changes, and the two declarations share no field names:
	 *
	 *   bookkeeping-provincies-bbv-variant     fiscalYear, totalAmount,
	 *                                          programmeStructure, status
	 *   bookkeeping-verplichtingenadministratie financialYear, authorised_amount,
	 *                                          programmeCode
	 *
	 * The fragments DEEP-MERGE and the later file's `required` wins, so the
	 * deployed schema demands `financialYear` + `authorised_amount` — measured
	 * on a live instance, where creating a BBV-shaped Budget is refused with
	 * "The required properties (financialYear, authorised_amount) are missing".
	 * A reader that knew only the BBV half would therefore report ZERO for a
	 * province whose budgets exist, which is indistinguishable from a province
	 * with no budget.
	 *
	 * Adapting on READ is the narrow fix. Renaming either fragment's properties
	 * is a DATA MIGRATION and neither vocabulary belongs to this change; the
	 * collision itself is reported rather than papered over.
	 *
	 * @param array<string,mixed> $budget The Budget record.
	 *
	 * @return integer The fiscal year, or 0 when neither field carries one.
	 */
	private function budgetYear(array $budget): int {
		return $this->vocabulary->year(budget: $budget);

	}//end budgetYear()

	/**
	 * Sum budget amounts per programme.
	 *
	 * @param list<array<string,mixed>> $budgets The budgets in scope.
	 *
	 * @return array<string,float> Programme code => budgeted amount.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function budgetByProgramme(array $budgets): array {
		$totals = [];
		foreach ($budgets as $budget) {
			// Both vocabularies again — see budgetYear(). `programmeCode` and
			// `authorised_amount` are the verplichtingenadministratie half of
			// the same colliding schema, and a budget written through that
			// half is still this province's budget.
			$programme = $this->vocabulary->programme(budget: $budget);
			if ($programme === '') {
				continue;
			}

			$amount = $this->vocabulary->amount(budget: $budget);
			$totals[$programme] = (($totals[$programme] ?? 0.0) + $amount);
		}

		return $totals;

	}//end budgetByProgramme()

	/**
	 * Sum POSTED GL spend per programme, and per month for the trend chart.
	 *
	 * Only `GLTransaction.state === 'posted'` transactions inside the window
	 * count: a draft journal is not spend, and a reversed one has a
	 * counter-posting of its own.
	 *
	 * @param array<int,string> $administrationIds Administrations the caller may read.
	 * @param array{fiscalYear:int,startDate:string,endDate:string} $window Fiscal-year window.
	 *
	 * @return array{byProgramme:array<string,float>,byMonth:array<string,float>} The sums.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function spendByProgramme(array $administrationIds, array $window): array {
		$monthOfTransaction = $this->postedTransactionMonths(
			administrationIds: $administrationIds,
			window: $window
		);

		$byProgramme = [];
		$byMonth = [];
		if ($monthOfTransaction === []) {
			return ['byProgramme' => $byProgramme, 'byMonth' => $byMonth];
		}

		foreach ($this->query(schema: self::SCHEMA_GL_LINE, filters: []) as $line) {
			$transactionId = (string)($line['transactionId'] ?? '');
			$programme = (string)($line['programmeStructure'] ?? '');
			if ($programme === '' || isset($monthOfTransaction[$transactionId]) === false) {
				continue;
			}

			$amount = $this->signedAmount(line: $line);
			$byProgramme[$programme] = (($byProgramme[$programme] ?? 0.0) + $amount);
			$month = $monthOfTransaction[$transactionId];
			$byMonth[$month] = (($byMonth[$month] ?? 0.0) + $amount);
		}

		return ['byProgramme' => $byProgramme, 'byMonth' => $byMonth];

	}//end spendByProgramme()

	/**
	 * Sum outstanding commitments per programme.
	 *
	 * @param array<int,string> $administrationIds Administrations the caller may read.
	 * @param integer $fiscalYear Fiscal year.
	 *
	 * @return array<string,float> Programme code => committed amount.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function committedByProgramme(array $administrationIds, int $fiscalYear): array {
		$totals = [];
		foreach ($administrationIds as $administrationId) {
			$lines = $this->query(
				schema: self::SCHEMA_COMMITMENT_LINE,
				filters: ['administrationId' => $administrationId]
			);
			foreach ($lines as $line) {
				$programme = (string)($line['programme'] ?? '');
				if ($programme === '' || (int)($line['financialYear'] ?? 0) !== $fiscalYear) {
					continue;
				}

				$totals[$programme] = (($totals[$programme] ?? 0.0) + (float)($line['remaining_committed'] ?? 0));
			}
		}

		return $totals;

	}//end committedByProgramme()

	/**
	 * Discover the fiscal years that have Budget data (REQ-BBC-002 filter options).
	 *
	 * @param array<int,string> $administrationIds Administrations the caller may read.
	 *
	 * @return list<int> The years, newest first.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function fiscalYearsFor(array $administrationIds): array {
		$years = [];
		foreach ($administrationIds as $administrationId) {
			$budgets = $this->query(
				schema: self::SCHEMA_BUDGET,
				filters: ['administrationId' => $administrationId]
			);
			foreach ($budgets as $budget) {
				$year = $this->budgetYear(budget: $budget);
				if ($year > 0) {
					$years[$year] = true;
				}
			}
		}

		$list = array_keys($years);
		rsort($list);

		return array_values($list);

	}//end fiscalYearsFor()

	/**
	 * Load the chart of accounts for the caller's administrations.
	 *
	 * @param array<int,string> $administrationIds Administrations the caller may read.
	 *
	 * @return list<array<string,mixed>> The accounts.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function accountsFor(array $administrationIds): array {
		$rows = [];
		foreach ($administrationIds as $administrationId) {
			$rows = array_merge(
				$rows,
				$this->query(schema: self::SCHEMA_ACCOUNT, filters: ['administrationId' => $administrationId])
			);
		}

		return $rows;

	}//end accountsFor()

	/**
	 * Index the posting month of every POSTED transaction inside the window.
	 *
	 * A GL line's `transactionId` references its parent by EITHER the
	 * OpenRegister object id or the human `transactionNumber`, depending on
	 * which writer created it, so BOTH are keyed — the idiom
	 * {@see FinancialSeriesCalculator::postedLinesByMonth()} already
	 * established here. Keying only one silently drops every line written by
	 * the other path, which reads on the dashboard as "this programme spent
	 * nothing" rather than as a fault.
	 *
	 * @param array<int,string> $administrationIds Administrations the caller may read.
	 * @param array{fiscalYear:int,startDate:string,endDate:string} $window Fiscal-year window.
	 *
	 * @return array<string,string> Transaction reference => `YYYY-MM` posting month.
	 */
	private function postedTransactionMonths(array $administrationIds, array $window): array {
		$months = [];
		foreach ($administrationIds as $administrationId) {
			$transactions = $this->query(
				schema: self::SCHEMA_GL_TRANSACTION,
				filters: ['administrationId' => $administrationId, 'state' => 'posted']
			);
			foreach ($transactions as $transaction) {
				$postingDate = (string)($transaction['postingDate'] ?? '');
				if ($this->inWindow(postingDate: $postingDate, window: $window) === false) {
					continue;
				}

				$month = substr($postingDate, 0, 7);
				foreach ($this->transactionRefs(transaction: $transaction) as $ref) {
					$months[$ref] = $month;
				}
			}
		}

		return $months;

	}//end postedTransactionMonths()

	/**
	 * Whether a posting date falls inside the fiscal-year window.
	 *
	 * @param string $postingDate The transaction's posting date.
	 * @param array{fiscalYear:int,startDate:string,endDate:string} $window Fiscal-year window.
	 *
	 * @return boolean True when the date is inside the window.
	 */
	private function inWindow(string $postingDate, array $window): bool {
		if ($postingDate === '') {
			return false;
		}

		return ($postingDate >= $window['startDate'] && $postingDate <= $window['endDate']);

	}//end inWindow()

	/**
	 * Every reference a GL line may use to name this transaction.
	 *
	 * @param array<string,mixed> $transaction The GL transaction.
	 *
	 * @return list<string> The non-empty references.
	 */
	private function transactionRefs(array $transaction): array {
		$refs = [];
		$objectId = (string)($transaction['@self']['id'] ?? $transaction['id'] ?? '');
		if ($objectId !== '') {
			$refs[] = $objectId;
		}

		$number = (string)($transaction['transactionNumber'] ?? '');
		if ($number !== '') {
			$refs[] = $number;
		}

		return $refs;

	}//end transactionRefs()

	/**
	 * Resolve a GL line's contribution to programme spend.
	 *
	 * A debit on a programme increases spend; a credit reduces it (a correction
	 * or a refund). Returning the raw amount for both would make a reversal
	 * look like more spend, not less.
	 *
	 * @param array<string,mixed> $line The GL line.
	 *
	 * @return float The signed amount.
	 */
	private function signedAmount(array $line): float {
		$amount = (float)($line['amount'] ?? 0);
		if ((string)($line['side'] ?? 'debit') === 'credit') {
			return -$amount;
		}

		return $amount;

	}//end signedAmount()

	/**
	 * Run one property-filtered query against the shillinq register.
	 *
	 * A failure is logged and answered as an empty result set: a missing
	 * commitment register must not stop the budget half of the dashboard from
	 * rendering.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Property filters (never `id`).
	 *
	 * @return list<array<string,mixed>> The matching records as plain arrays.
	 */
	private function query(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (Throwable $e) {
			$this->logger->error(
				'BbvProgrammeBudgetReader: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$payload = $row->getObject();
				if (is_array($payload) === true) {
					$result[] = $payload;
				}
			}
		}

		return $result;

	}//end query()

	/**
	 * Resolve the OpenRegister register slug from app config.
	 *
	 * @return string The register slug, defaulting to `shillinq`.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;

	}//end register()
}//end class
