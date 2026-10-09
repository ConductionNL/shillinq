<?php

/**
 * Fido Quarter
 *
 * Computes the Wet Fido figures of one quarter for an organisation: the cash
 * limit (kasgeldlimiet) against the average month-end net floating debt, and
 * the interest risk norm (renterisiconorm) against the refinancing and rate
 * revisions of long-term loans in this year and the next three. It keeps the
 * figures on KasgeldLimiet and RenteRisicoNorm and returns the snapshots the
 * quarterly report stores (REQ-FDO-010, REQ-FDO-011).
 *
 * The organisation's GL is read as the administration with the same id.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-wet-fido-treasury/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PublicSector;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The Wet Fido figures of one quarter.
 *
 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-wet-fido-treasury/spec.md
 */
class FidoQuarter {

	/**
	 * Loan states that count as outstanding debt.
	 */
	private const COUNTED_STATES = ['recorded', 'recorded-with-override', 'locked'];

	/**
	 * Account types whose balance is a floating asset.
	 */
	private const CASH_ACCOUNT_TYPES = ['bank', 'cash'];

	/**
	 * The statutory interest risk norm percentage when none is recorded.
	 */
	private const DEFAULT_RISK_PERCENTAGE = 20.0;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     App config for the register slug.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Compute and keep the Fido figures of a quarter.
	 *
	 * @param string $organisationId The organisation.
	 * @param string $year           The audit year, for example "2026".
	 * @param int    $quarter        The quarter, 1 to 4.
	 *
	 * @return array{cashStatus: array<string,mixed>, renteRiskStatus: array<string,mixed>} The snapshots for the quarterly report.
	 *
	 * @throws InvalidArgumentException When the quarter is invalid or the year has no cash limit record.
	 *
	 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-wet-fido-treasury/spec.md
	 */
	public function compute(string $organisationId, string $year, int $quarter): array {
		$auditYear = (int)$year;
		if ($quarter < 1 || $quarter > 4 || $auditYear < 1900 || $organisationId === '') {
			throw new InvalidArgumentException('A Fido quarter needs an organisation, a year and a quarter from 1 to 4.');
		}

		$limit = $this->first(schema: 'KasgeldLimiet', filters: ['organisationId' => $organisationId, 'auditYear' => $auditYear]);
		if ($limit === null) {
			throw new InvalidArgumentException('Record the cash limit (budget and percentage) for ' . $auditYear . ' before computing a quarter.');
		}

		$budgetCents = $this->cents(value: $limit['baseBudget'] ?? 0);
		$loans = $this->loans(organisationId: $organisationId);

		$cashStatus = $this->cashStatus(
			limit: $limit,
			budgetCents: $budgetCents,
			loans: $loans,
			organisationId: $organisationId,
			auditYear: $auditYear,
			quarter: $quarter
		);
		$riskStatus = $this->riskStatus(budgetCents: $budgetCents, loans: $loans, organisationId: $organisationId, auditYear: $auditYear);

		return ['cashStatus' => $cashStatus, 'renteRiskStatus' => $riskStatus];

	}//end compute()

	/**
	 * Compute, keep and return the cash limit snapshot.
	 *
	 * @param array<string,mixed>            $limit          The KasgeldLimiet record.
	 * @param int                            $budgetCents    The budget total in cents.
	 * @param list<array<string,mixed>>      $loans          The organisation's counted loans.
	 * @param string                         $organisationId The organisation.
	 * @param int                            $auditYear      The year.
	 * @param int                            $quarter        The quarter.
	 *
	 * @return array{ceiling: float, exposure: float, headroom: float, status: string}
	 */
	private function cashStatus(array $limit, int $budgetCents, array $loans, string $organisationId, int $auditYear, int $quarter): array {
		$ceilingCents = (int)round($budgetCents * ((float)($limit['percentage'] ?? 0)) / 100);

		$monthEnds = [];
		for ($month = (($quarter * 3) - 2); $month <= ($quarter * 3); $month++) {
			$monthEnds[] = (new DateTimeImmutable(sprintf('%04d-%02d-01', $auditYear, $month)))->modify('last day of this month')->format('Y-m-d');
		}

		$cashByDate = $this->cashBalances(organisationId: $organisationId, dates: $monthEnds);
		$sum = 0;
		foreach ($monthEnds as $date) {
			$sum += ($this->shortTermDebt(loans: $loans, date: $date) - $cashByDate[$date]);
		}

		$exposureCents = (int)round($sum / count($monthEnds));
		$headroomCents = ($ceilingCents - $exposureCents);
		$status = $this->ladder(
			breached: $exposureCents > $ceilingCents,
			previous: $this->previousCashStatus(organisationId: $organisationId, auditYear: $auditYear, quarter: $quarter)
		);

		$snapshot = [
			'ceiling' => $this->euros(cents: $ceilingCents),
			'exposure' => $this->euros(cents: $exposureCents),
			'headroom' => $this->euros(cents: $headroomCents),
			'status' => $status,
		];

		$limit['calculatedCeiling'] = $snapshot['ceiling'];
		$limit['currentExposure'] = $snapshot['exposure'];
		$limit['headroom'] = $snapshot['headroom'];
		$limit['status'] = $status;
		$this->save(schema: 'KasgeldLimiet', object: $limit);

		return $snapshot;

	}//end cashStatus()

	/**
	 * Compute, keep and return the interest risk norm snapshot for the year,
	 * with the three years after it on RenteRisicoNorm.
	 *
	 * @param int                       $budgetCents    The budget total in cents.
	 * @param list<array<string,mixed>> $loans          The organisation's counted loans.
	 * @param string                    $organisationId The organisation.
	 * @param int                       $auditYear      The year.
	 *
	 * @return array{ceiling: float, exposure: float, headroom: float, status: string}
	 */
	private function riskStatus(int $budgetCents, array $loans, string $organisationId, int $auditYear): array {
		$norm = $this->first(schema: 'RenteRisicoNorm', filters: ['organisationId' => $organisationId, 'auditYear' => $auditYear]);
		$percentage = (float)($norm['percentage'] ?? self::DEFAULT_RISK_PERCENTAGE);
		$ceilingCents = (int)round($budgetCents * $percentage / 100);

		$years = [];
		$status = 'binnen-norm';
		for ($year = $auditYear; $year < ($auditYear + 4); $year++) {
			$exposureCents = $this->riskExposure(loans: $loans, year: $year);
			$years[] = [
				'year' => $year,
				'exposure' => $this->euros(cents: $exposureCents),
				'ceiling' => $this->euros(cents: $ceilingCents),
				'headroom' => $this->euros(cents: ($ceilingCents - $exposureCents)),
			];
			if ($status === 'binnen-norm' && $exposureCents > $ceilingCents) {
				$status = 'overschrijding-jaar-' . $year;
			}
		}

		if ($norm !== null) {
			$norm['calculatedCeiling'] = $this->euros(cents: $ceilingCents);
			$norm['forwardLooking4Year'] = $years;
			$norm['headroomPerYear'] = array_column($years, 'headroom');
			$norm['status'] = $status;
			$this->save(schema: 'RenteRisicoNorm', object: $norm);
		}

		return [
			'ceiling' => $years[0]['ceiling'],
			'exposure' => $years[0]['exposure'],
			'headroom' => $years[0]['headroom'],
			'status' => $status,
		];

	}//end riskStatus()

	/**
	 * The enforcement ladder: a breach after a breach climbs one step.
	 *
	 * @param bool   $breached Whether this quarter is above the limit.
	 * @param string $previous The previous quarter's status, or ''.
	 *
	 * @return string
	 */
	private function ladder(bool $breached, string $previous): string {
		if ($breached === false) {
			return 'binnen-norm';
		}

		return match ($previous) {
			'overschrijding-1-kwartaal' => 'overschrijding-2-kwartalen',
			'overschrijding-2-kwartalen', 'sanering-verplicht' => 'sanering-verplicht',
			default => 'overschrijding-1-kwartaal',
		};

	}//end ladder()

	/**
	 * The cash status of the quarter before, from its quarterly report.
	 *
	 * @param string $organisationId The organisation.
	 * @param int    $auditYear      The year.
	 * @param int    $quarter        The quarter.
	 *
	 * @return string The previous status, or '' when there is no report.
	 */
	private function previousCashStatus(string $organisationId, int $auditYear, int $quarter): string {
		$year = $auditYear;
		$previous = ($quarter - 1);
		if ($previous === 0) {
			$year--;
			$previous = 4;
		}

		$report = $this->first(
			schema: 'QuartaalrapportageFido',
			filters: ['organisationId' => $organisationId, 'auditYear' => $year, 'quarter' => $previous]
		);

		return (string)($report['cashStatus']['status'] ?? '');

	}//end previousCashStatus()

	/**
	 * The outstanding principal of loans running under a year on a date.
	 *
	 * @param list<array<string,mixed>> $loans The counted loans.
	 * @param string                    $date  The date (Y-m-d).
	 *
	 * @return int Cents.
	 */
	private function shortTermDebt(array $loans, string $date): int {
		$cents = 0;
		foreach ($loans as $loan) {
			$issue = (string)($loan['issueDate'] ?? '');
			$maturity = (string)($loan['maturityDate'] ?? '');
			if ($this->isShortTerm(loan: $loan) === true && $issue <= $date && $maturity > $date) {
				$cents += $this->cents(value: $loan['principal'] ?? 0);
			}
		}

		return $cents;

	}//end shortTermDebt()

	/**
	 * Refinancing (maturity) and rate revisions of long-term loans in a year.
	 *
	 * @param list<array<string,mixed>> $loans The counted loans.
	 * @param int                       $year  The year.
	 *
	 * @return int Cents.
	 */
	private function riskExposure(array $loans, int $year): int {
		$cents = 0;
		foreach ($loans as $loan) {
			if ($this->isShortTerm(loan: $loan) === true) {
				continue;
			}

			$matures = (substr((string)($loan['maturityDate'] ?? ''), 0, 4) === (string)$year);
			$resets = (substr((string)($loan['interestResetMoment'] ?? ''), 0, 4) === (string)$year);
			if ($matures === true || $resets === true) {
				$cents += $this->cents(value: $loan['principal'] ?? 0);
			}
		}

		return $cents;

	}//end riskExposure()

	/**
	 * Whether a loan runs under one year.
	 *
	 * @param array<string,mixed> $loan The loan.
	 *
	 * @return bool
	 */
	private function isShortTerm(array $loan): bool {
		$issue = (string)($loan['issueDate'] ?? '');
		$maturity = (string)($loan['maturityDate'] ?? '');
		if ($issue === '' || $maturity === '') {
			return false;
		}

		return (new DateTimeImmutable($issue))->modify('+1 year')->format('Y-m-d') > substr($maturity, 0, 10);

	}//end isShortTerm()

	/**
	 * The organisation's loans that count as debt.
	 *
	 * @param string $organisationId The organisation.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function loans(string $organisationId): array {
		$loans = [];
		foreach ($this->rows(schema: 'Lening', filters: ['organisationId' => $organisationId]) as $loan) {
			if (in_array((string)($loan['status'] ?? ''), self::COUNTED_STATES, true) === true) {
				$loans[] = $loan;
			}
		}

		return $loans;

	}//end loans()

	/**
	 * The balance of the bank and cash accounts at each date, from posted lines.
	 *
	 * @param string       $organisationId The organisation (read as the administration).
	 * @param list<string> $dates          The dates (Y-m-d).
	 *
	 * @return array<string,int> Date => cents (debit positive).
	 */
	private function cashBalances(string $organisationId, array $dates): array {
		$balances = array_fill_keys($dates, 0);

		$cashAccounts = $this->cashAccounts(organisationId: $organisationId);
		if ($cashAccounts === []) {
			return $balances;
		}

		$dateByRef = $this->postingDates(organisationId: $organisationId);
		foreach ($this->rows(schema: 'GLLine', filters: []) as $line) {
			$date = ($dateByRef[(string)($line['transactionId'] ?? '')] ?? null);
			if ($date === null || isset($cashAccounts[(string)($line['accountNumber'] ?? '')]) === false) {
				continue;
			}

			$cents = $this->cents(value: $line['amount'] ?? 0);
			if ((string)($line['side'] ?? 'debit') === 'credit') {
				$cents = -$cents;
			}

			foreach ($dates as $until) {
				if ($date <= $until) {
					$balances[$until] += $cents;
				}
			}
		}

		return $balances;

	}//end cashBalances()

	/**
	 * The organisation's bank and cash account numbers.
	 *
	 * @param string $organisationId The organisation (read as the administration).
	 *
	 * @return array<string,true>
	 */
	private function cashAccounts(string $organisationId): array {
		$cashAccounts = [];
		foreach ($this->rows(schema: 'Account', filters: ['administrationId' => $organisationId]) as $account) {
			if (in_array((string)($account['accountType'] ?? ''), self::CASH_ACCOUNT_TYPES, true) === true) {
				$cashAccounts[(string)($account['accountNumber'] ?? '')] = true;
			}
		}

		return $cashAccounts;

	}//end cashAccounts()

	/**
	 * The posting date of every posted transaction, under each reference a
	 * line may use: the object id or the transaction number.
	 *
	 * @param string $organisationId The organisation (read as the administration).
	 *
	 * @return array<string,string> Reference => Y-m-d.
	 */
	private function postingDates(string $organisationId): array {
		$dateByRef = [];
		foreach ($this->rows(schema: 'GLTransaction', filters: ['administrationId' => $organisationId, 'state' => 'posted']) as $transaction) {
			$date = substr((string)($transaction['postingDate'] ?? ''), 0, 10);
			if ($date === '') {
				continue;
			}

			foreach ([$transaction['id'] ?? null, $transaction['@self']['id'] ?? null, $transaction['transactionNumber'] ?? null] as $ref) {
				if ($ref !== null && (string)$ref !== '') {
					$dateByRef[(string)$ref] = $date;
				}
			}
		}

		return $dateByRef;

	}//end postingDates()

	/**
	 * The first row matching the filters, or null.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function first(string $schema, array $filters): ?array {
		$rows = $this->rows(schema: $schema, filters: $filters);
		return ($rows[0] ?? null);

	}//end first()

	/**
	 * Rows of a schema as arrays.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$found = $this->objectService->setRegister($this->register())->setSchema($schema)->findAll(['filters' => $filters]);
		$rows = [];
		foreach ($found as $row) {
			if (is_array($row) === false) {
				$row = $row->jsonSerialize();
			}

			$rows[] = $row;
		}

		return $rows;

	}//end rows()

	/**
	 * Save a record back under its own id.
	 *
	 * @param string              $schema The schema slug.
	 * @param array<string,mixed> $object The record.
	 *
	 * @return void
	 */
	private function save(string $schema, array $object): void {
		$uuid = (string)($object['id'] ?? ($object['@self']['id'] ?? ''));
		unset($object['@self']);
		if ($uuid === '') {
			$uuid = null;
		}

		$this->objectService->saveObject(object: $object, register: $this->register(), schema: $schema, uuid: $uuid);

	}//end save()

	/**
	 * Euros to integer cents.
	 *
	 * @param mixed $value The amount in euros.
	 *
	 * @return int
	 */
	private function cents(mixed $value): int {
		return (int)round(((float)$value) * 100);

	}//end cents()

	/**
	 * Integer cents to euros.
	 *
	 * @param int $cents The amount in cents.
	 *
	 * @return float
	 */
	private function euros(int $cents): float {
		return ((float)$cents / 100);

	}//end euros()

	/**
	 * The configured register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;

	}//end register()
}//end class
