<?php

/**
 * Interest Allocation Service
 *
 * The yearly interest allocation under the BBV (public-sector-reserves-and-
 * interest, REQ-PSRI-003). Calculate: the pooled interest rate over each
 * investment's book value on 1 January, charged to its task field, and over
 * the 1 January balance of each reserve marked for interest. An investment
 * without a book value or a task field is listed with the reason and left
 * out. Post: one journal entry debiting the interest cost per task field and
 * crediting Treasury (task field 0.5), plus each reserve's interest as an
 * addition (result account to reserve account); each reserve then carries a
 * realised addition naming the run. Amounts are euros, rounded to cents.
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
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PublicSector;

use DomainException;
use OCA\Shillinq\Service\KapitaallastenCalculator;

/**
 * Calculates and posts a yearly interest allocation run.
 *
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 */
class InterestAllocationService {

	/**
	 * Constructor.
	 *
	 * @param PublicSectorRecords      $records  Investments, reserves and journals.
	 * @param ReserveBalances          $balances Reserve balances on 1 January.
	 * @param KapitaallastenCalculator $schedule The depreciation schedule when an investment has none stored.
	 */
	public function __construct(
		private readonly PublicSectorRecords $records,
		private readonly ReserveBalances $balances,
		private readonly KapitaallastenCalculator $schedule,
	) {
	}//end __construct()

	/**
	 * Fill a run's investment and reserve lines and its totals.
	 *
	 * @param array<string,mixed> $run The run.
	 *
	 * @return array<string,mixed> The run with lines, reserveLines and totals.
	 *
	 * @throws DomainException When the year or the percentage is missing.
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function calculate(array $run): array {
		$year = (int)($run['year'] ?? 0);
		$rate = (float)($run['omslagrentePercentage'] ?? 0);
		if ($year <= 0 || $rate <= 0) {
			throw new DomainException('Enter the year and the pooled interest rate before calculating.');
		}

		$administrationId = (string)($run['administrationId'] ?? '');
		$lines = [];
		$investmentTotal = 0;
		foreach ($this->records->records(schema: 'Investering', filters: ['administrationId' => $administrationId]) as $investment) {
			$line = $this->investmentLine(investment: $investment, year: $year, rate: $rate);
			$investmentTotal += (int)round($line['interest'] * 100);
			$lines[] = $line;
		}

		$reserveLines = [];
		$reserveTotal = 0;
		foreach ($this->records->records(schema: 'Reserve', filters: ['administrationId' => $administrationId]) as $reserve) {
			if (($reserve['rentetoerekening'] ?? false) !== true) {
				continue;
			}

			$balance = $this->balances->balanceOnFirstJanuary(reserve: $reserve, year: $year);
			$interest = self::interest(amount: $balance, rate: $rate);
			$reserveTotal += (int)round($interest * 100);
			$reserveLines[] = [
				'reserveId' => (string)($reserve['id'] ?? ''),
				'name'      => (string)($reserve['name'] ?? ''),
				'balance'   => $balance,
				'interest'  => $interest,
			];
		}

		$run['lines'] = $lines;
		$run['reserveLines'] = $reserveLines;
		$run['investmentInterestTotal'] = (float)($investmentTotal / 100);
		$run['reserveInterestTotal'] = (float)($reserveTotal / 100);
		return $run;

	}//end calculate()

	/**
	 * Post a calculated run: one journal entry, and a realised addition per reserve.
	 *
	 * @param array<string,mixed> $run The calculated run.
	 *
	 * @return array<string,mixed> The run with journalEntryId.
	 *
	 * @throws DomainException When the run has nothing to post or a reserve lacks its accounts.
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function post(array $run): array {
		if ((string)($run['journalEntryId'] ?? '') !== '') {
			return $run;
		}

		$journal = $this->journalFor(run: $run);
		$run['journalEntryId'] = $this->records->postJournal(journal: $journal);
		foreach ((array)($run['reserveLines'] ?? []) as $line) {
			if ((float)($line['interest'] ?? 0) <= 0) {
				continue;
			}

			$this->records->create(
				schema: 'ReserveMutation',
				object: [
					'administrationId' => (string)($run['administrationId'] ?? ''),
					'reserveId'        => (string)($line['reserveId'] ?? ''),
					'reserveName'      => (string)($line['name'] ?? ''),
					'year'             => (int)($run['year'] ?? 0),
					'kind'             => 'addition',
					'amount'           => (float)$line['interest'],
					'description'      => 'Toegerekende rente ' . (int)($run['year'] ?? 0),
					'status'           => 'realised',
					'journalEntryId'   => $run['journalEntryId'],
					'interestRunId'    => (string)($run['id'] ?? ''),
				]
			);
		}

		return $run;

	}//end post()

	/**
	 * The balanced journal entry of a calculated run.
	 *
	 * @param array<string,mixed> $run The calculated run.
	 *
	 * @return array<string,mixed> The JournalEntry payload in state draft.
	 *
	 * @throws DomainException When there is nothing to post or an account is missing.
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function journalFor(array $run): array {
		$year = (int)($run['year'] ?? 0);
		$costAccount = trim((string)($run['interestCostAccountNumber'] ?? ''));
		$treasuryAccount = trim((string)($run['treasuryAccountNumber'] ?? ''));
		if ($costAccount === '' || $treasuryAccount === '') {
			throw new DomainException('Enter the interest cost account and the Treasury account on the run.');
		}

		$perTaskField = [];
		foreach ((array)($run['lines'] ?? []) as $line) {
			$cents = (int)round((float)($line['interest'] ?? 0) * 100);
			if ($cents <= 0 || (string)($line['taskFieldCode'] ?? '') === '') {
				continue;
			}

			$code = (string)$line['taskFieldCode'];
			$perTaskField[$code] = (($perTaskField[$code] ?? 0) + $cents);
		}

		ksort($perTaskField, SORT_NATURAL);
		$lines = [];
		$treasury = 0;
		foreach ($perTaskField as $code => $cents) {
			$treasury += $cents;
			$lines[] = self::line(account: $costAccount, side: 'debit', cents: $cents, description: sprintf('Rente %d taakveld %s', $year, $code));
		}

		if ($treasury > 0) {
			$lines[] = self::line(account: $treasuryAccount, side: 'credit', cents: $treasury, description: sprintf('Rente %d taakveld 0.5 Treasury', $year));
		}

		foreach ($this->reserveBookings(run: $run) as $booking) {
			$lines[] = $booking;
		}

		if ($lines === []) {
			throw new DomainException('There is no interest to post. Calculate the run first.');
		}

		return [
			'journalNumber'    => sprintf('RENTE-%d-%s', $year, substr(hash('sha256', (string)($run['administrationId'] ?? '') . '|' . $year), 0, 8)),
			'entryDate'        => sprintf('%04d-12-31', $year),
			'description'      => sprintf('Rentetoerekening %d (omslagrente %s%%)', $year, (string)($run['omslagrentePercentage'] ?? '')),
			'lines'            => $lines,
			'journalType'      => 'manual',
			'approvalState'    => 'not-required',
			'administrationId' => (string)($run['administrationId'] ?? ''),
			'state'            => 'draft',
		];

	}//end journalFor()

	/**
	 * An investment's book value on 1 January of a year, or null when it cannot be told.
	 *
	 * The book value is the gross amount less the depreciation of the years
	 * before, from the stored capital charges schedule or, without one, the
	 * straight-line schedule over its term.
	 *
	 * @param array<string,mixed> $investment The investment.
	 * @param int                 $year       The year.
	 *
	 * @return float|null Euros.
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function bookValue(array $investment, int $year): ?float {
		if (isset($investment['gross']) === false || is_numeric($investment['gross']) === false) {
			return null;
		}

		$schedule = (array)($investment['capitalChargesSchedule'] ?? []);
		if ($schedule === []) {
			$first = (int)($investment['firstDepreciationYear'] ?? 0);
			$term = (int)($investment['depreciationTerm'] ?? 0);
			if ($first <= 0 || $term <= 0) {
				return null;
			}

			$schedule = $this->schedule->schedule(gross: (float)$investment['gross'], firstDepreciationYear: $first, depreciationTerm: $term);
		}

		$cents = (int)round((float)$investment['gross'] * 100);
		foreach ($schedule as $scheduleYear => $amount) {
			if ((int)$scheduleYear < $year) {
				$cents -= (int)round((float)$amount * 100);
			}
		}

		return (max(0, $cents) / 100);

	}//end bookValue()

	/**
	 * One investment's line.
	 *
	 * @param array<string,mixed> $investment The investment.
	 * @param int                 $year       The year.
	 * @param float               $rate       The pooled rate, percent.
	 *
	 * @return array<string,mixed>
	 */
	private function investmentLine(array $investment, int $year, float $rate): array {
		$bookValue = $this->bookValue(investment: $investment, year: $year);
		$taskField = trim((string)($investment['taskFieldCode'] ?? ''));
		// Leave out what is unknown rather than writing null: the register's
		// validator refuses a null inside an array item.
		$line = [
			'investmentId'  => (string)($investment['id'] ?? ''),
			'description'   => (string)($investment['description'] ?? ''),
			'taskFieldCode' => $taskField,
			'interest'      => 0.0,
		];
		if ($bookValue === null) {
			$line['excludedReason'] = 'No book value: enter the gross amount and the depreciation.';
			return $line;
		}

		$line['bookValue'] = $bookValue;
		if ($taskField === '') {
			$line['excludedReason'] = 'No task field to charge the interest to.';
			return $line;
		}

		$line['interest'] = self::interest(amount: $bookValue, rate: $rate);
		return $line;

	}//end investmentLine()

	/**
	 * The addition lines of the reserves' interest.
	 *
	 * @param array<string,mixed> $run The calculated run.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @throws DomainException When a reserve lacks its accounts.
	 */
	private function reserveBookings(array $run): array {
		$year = (int)($run['year'] ?? 0);
		$lines = [];
		foreach ((array)($run['reserveLines'] ?? []) as $line) {
			$cents = (int)round((float)($line['interest'] ?? 0) * 100);
			if ($cents <= 0) {
				continue;
			}

			$reserve = ($this->records->find(schema: 'Reserve', id: (string)($line['reserveId'] ?? '')) ?? []);
			$reserveAccount = trim((string)($reserve['balanceAccountNumber'] ?? ''));
			$resultAccount = trim((string)($reserve['resultAccountNumber'] ?? ''));
			if ($reserveAccount === '' || $resultAccount === '') {
				throw new DomainException(sprintf('Enter the reserve account and the result account on reserve %s first.', (string)($line['name'] ?? '')));
			}

			$text = sprintf('Rente %d toegevoegd aan %s', $year, (string)($line['name'] ?? ''));
			$lines[] = self::line(account: $resultAccount, side: 'debit', cents: $cents, description: $text);
			$lines[] = self::line(account: $reserveAccount, side: 'credit', cents: $cents, description: $text);
		}

		return $lines;

	}//end reserveBookings()

	/**
	 * A journal line.
	 *
	 * @param string $account     The account number.
	 * @param string $side        Debit or credit.
	 * @param int    $cents       The amount in cents.
	 * @param string $description The line text.
	 *
	 * @return array<string,mixed>
	 */
	private static function line(string $account, string $side, int $cents, string $description): array {
		return ['accountNumber' => $account, 'side' => $side, 'amount' => ($cents / 100), 'description' => $description];

	}//end line()

	/**
	 * Interest over an amount at a rate, rounded to cents.
	 *
	 * @param float $amount Euros.
	 * @param float $rate   Percent.
	 *
	 * @return float
	 */
	private static function interest(float $amount, float $rate): float {
		return round($amount * $rate / 100, 2);

	}//end interest()
}//end class
