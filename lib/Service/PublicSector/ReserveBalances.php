<?php

/**
 * Reserve Balances
 *
 * A reserve's balances follow from its mutations
 * (public-sector-reserves-and-interest, REQ-PSRI-001, REQ-PSRI-002): the
 * opening balance of its opening year, then per year the additions and the
 * withdrawals, each year's opening being the previous year's closing. The
 * multi-year overview lists that chain per reserve for a year and the four
 * years after it, marks a year that counts planned mutations, and flags a
 * closing balance under the reserve's floor or over its ceiling. Realising a
 * mutation books it: additions debit the result account and credit the
 * reserve account, withdrawals the other way round. Amounts are euros,
 * summed in cents.
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

/**
 * Balances, the multi-year overview and the booking of reserve mutations.
 *
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 */
class ReserveBalances {
	/**
	 * How many years the overview shows: the year asked for and four ahead.
	 *
	 * @var integer
	 */
	public const OVERVIEW_YEARS = 5;

	/**
	 * Constructor.
	 *
	 * @param PublicSectorRecords $records Reserves, mutations and journals.
	 */
	public function __construct(
		private readonly PublicSectorRecords $records,
	) {
	}//end __construct()

	/**
	 * The multi-year overview of an administration's reserves.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $fromYear         The first year shown.
	 *
	 * @return array{fromYear:int,rows:list<array<string,mixed>>}
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function overview(string $administrationId, int $fromYear): array {
		$mutations = [];
		foreach ($this->records->records(schema: 'ReserveMutation', filters: ['administrationId' => $administrationId]) as $mutation) {
			$mutations[(string)($mutation['reserveId'] ?? '')][] = $mutation;
		}

		$rows = [];
		foreach ($this->records->records(schema: 'Reserve', filters: ['administrationId' => $administrationId]) as $reserve) {
			$own = [];
			foreach (self::keysOf(reserve: $reserve) as $key) {
				$own = array_merge($own, ($mutations[$key] ?? []));
			}

			foreach ($this->chain(reserve: $reserve, mutations: $own, fromYear: $fromYear) as $row) {
				$rows[] = $row;
			}
		}

		return ['fromYear' => $fromYear, 'rows' => $rows];

	}//end overview()

	/**
	 * One reserve's years from the first year shown, opening to closing.
	 *
	 * @param array<string,mixed>       $reserve   The reserve.
	 * @param list<array<string,mixed>> $mutations Its mutations, planned and realised.
	 * @param int                       $fromYear  The first year shown.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function chain(array $reserve, array $mutations, int $fromYear): array {
		$opening = $this->opening(reserve: $reserve);
		$startYear = min($opening['year'], $fromYear);
		$balance = 0;
		$lastYear = ($fromYear + self::OVERVIEW_YEARS - 1);
		$rows = [];
		for ($year = $startYear; $year <= $lastYear; $year++) {
			// Before its opening year the reserve holds nothing; that year starts from its opening balance.
			if ($year === $opening['year']) {
				$balance = self::cents(amount: $opening['amount']);
			}

			$sums = self::sums(mutations: $mutations, year: $year, realisedOnly: false);
			$closing = ($balance + $sums['additions'] - $sums['withdrawals']);
			if ($year >= $fromYear) {
				$rows[] = [
					'reserveId'    => (string)($reserve['id'] ?? ''),
					'reserveName'  => (string)($reserve['name'] ?? ''),
					'year'         => $year,
					'opening'      => ($balance / 100),
					'additions'    => ($sums['additions'] / 100),
					'withdrawals'  => ($sums['withdrawals'] / 100),
					'closing'      => ($closing / 100),
					'planned'      => $sums['planned'],
					'belowFloor'   => (isset($reserve['floorCents']) === true && $closing < (int)$reserve['floorCents']),
					'aboveCeiling' => (isset($reserve['plafondCents']) === true && $closing > (int)$reserve['plafondCents']),
				];
			}

			$balance = $closing;
		}

		return $rows;

	}//end chain()

	/**
	 * A reserve's realised balance on 1 January of a year.
	 *
	 * @param array<string,mixed> $reserve The reserve.
	 * @param int                 $year    The year.
	 *
	 * @return float Euros.
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function balanceOnFirstJanuary(array $reserve, int $year): float {
		$opening = $this->opening(reserve: $reserve);
		if ($year < $opening['year']) {
			return 0.0;
		}

		$mutations = $this->mutationsOf(reserve: $reserve);
		$balance = self::cents(amount: $opening['amount']);
		for ($past = $opening['year']; $past < $year; $past++) {
			$sums = self::sums(mutations: $mutations, year: $past, realisedOnly: true);
			$balance += ($sums['additions'] - $sums['withdrawals']);
		}

		return ($balance / 100);

	}//end balanceOnFirstJanuary()

	/**
	 * Book a mutation that is being realised and answer it with its journal entry.
	 *
	 * @param array<string,mixed> $mutation The mutation, status realised.
	 *
	 * @return array<string,mixed> The mutation with journalEntryId.
	 *
	 * @throws DomainException When the reserve or its accounts are missing, or the amount is not positive.
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function realise(array $mutation): array {
		if ((string)($mutation['journalEntryId'] ?? '') !== '') {
			return $mutation;
		}

		$reserve = $this->records->find(schema: 'Reserve', id: (string)($mutation['reserveId'] ?? ''));
		if ($reserve === null) {
			throw new DomainException('The reserve of this mutation does not exist.');
		}

		$mutation['journalEntryId'] = $this->records->postJournal(journal: $this->journalFor(mutation: $mutation, reserve: $reserve));
		if ((string)($mutation['reserveName'] ?? '') === '') {
			$mutation['reserveName'] = (string)($reserve['name'] ?? '');
		}

		return $mutation;

	}//end realise()

	/**
	 * The balanced journal entry that books a mutation.
	 *
	 * @param array<string,mixed> $mutation The mutation.
	 * @param array<string,mixed> $reserve  Its reserve.
	 *
	 * @return array<string,mixed> The JournalEntry payload in state draft.
	 *
	 * @throws DomainException When an account is missing or the amount is not positive.
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function journalFor(array $mutation, array $reserve): array {
		$reserveAccount = trim((string)($reserve['balanceAccountNumber'] ?? ''));
		$resultAccount = trim((string)($reserve['resultAccountNumber'] ?? ''));
		if ($reserveAccount === '' || $resultAccount === '') {
			throw new DomainException('Enter the reserve account and the result account on the reserve first.');
		}

		$amount = round((float)($mutation['amount'] ?? 0), 2);
		if ($amount <= 0) {
			throw new DomainException('The amount of a mutation must be more than zero.');
		}

		$year = (int)($mutation['year'] ?? 0);
		$kind = (string)($mutation['kind'] ?? 'addition');
		$label = 'Toevoeging';
		if ($kind === 'withdrawal') {
			$label = 'Onttrekking';
		}

		$description = sprintf('%s %d: %s', $label, $year, (string)($reserve['name'] ?? ''));
		$resolution = trim((string)($mutation['councilResolution'] ?? ''));
		if ($resolution !== '') {
			$description .= ' (raadsbesluit ' . $resolution . ')';
		}

		$reserveSide = 'credit';
		$resultSide = 'debit';
		if ($kind === 'withdrawal') {
			$reserveSide = 'debit';
			$resultSide = 'credit';
		}

		$key = (string)($mutation['id'] ?? ($mutation['@self']['slug'] ?? $description));
		return [
			'journalNumber'    => 'RES-' . substr(hash('sha256', $key), 0, 12),
			'entryDate'        => self::entryDate(year: $year),
			'description'      => $description,
			'lines'            => [
				['accountNumber' => $resultAccount, 'side' => $resultSide, 'amount' => $amount, 'description' => $description],
				['accountNumber' => $reserveAccount, 'side' => $reserveSide, 'amount' => $amount, 'description' => $description],
			],
			'journalType'      => 'manual',
			'approvalState'    => 'not-required',
			'administrationId' => (string)($mutation['administrationId'] ?? ($reserve['administrationId'] ?? '')),
			'state'            => 'draft',
		];

	}//end journalFor()

	/**
	 * The year and amount a reserve's balances start from.
	 *
	 * @param array<string,mixed> $reserve The reserve.
	 *
	 * @return array{year:int,amount:float}
	 */
	private function opening(array $reserve): array {
		$year = (int)($reserve['openingBalanceYear'] ?? 0);
		if ($year <= 0) {
			$year = (int)gmdate('Y');
		}

		$amount = $reserve['openingBalance'] ?? null;
		if ($amount === null && isset($reserve['balanceYearStartCents']) === true) {
			$amount = ((int)$reserve['balanceYearStartCents'] / 100);
		}

		return ['year' => $year, 'amount' => (float)($amount ?? 0)];

	}//end opening()

	/**
	 * A reserve's mutations, found by its id and by its slug.
	 *
	 * @param array<string,mixed> $reserve The reserve.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function mutationsOf(array $reserve): array {
		$mutations = [];
		foreach (self::keysOf(reserve: $reserve) as $key) {
			foreach ($this->records->records(schema: 'ReserveMutation', filters: ['reserveId' => $key]) as $mutation) {
				$mutations[] = $mutation;
			}
		}

		return $mutations;

	}//end mutationsOf()

	/**
	 * The keys a mutation may name its reserve by: its id and its slug.
	 *
	 * @param array<string,mixed> $reserve The reserve.
	 *
	 * @return list<string>
	 */
	private static function keysOf(array $reserve): array {
		return array_values(array_unique(array_filter([(string)($reserve['id'] ?? ''), (string)($reserve['@self']['slug'] ?? '')])));

	}//end keysOf()

	/**
	 * Additions and withdrawals of one year, in cents.
	 *
	 * @param list<array<string,mixed>> $mutations    The mutations.
	 * @param int                       $year         The year.
	 * @param bool                      $realisedOnly Whether planned mutations are left out.
	 *
	 * @return array{additions:int,withdrawals:int,planned:bool}
	 */
	private static function sums(array $mutations, int $year, bool $realisedOnly): array {
		$sums = ['additions' => 0, 'withdrawals' => 0, 'planned' => false];
		foreach ($mutations as $mutation) {
			if ((int)($mutation['year'] ?? 0) !== $year) {
				continue;
			}

			$realised = (($mutation['status'] ?? 'planned') === 'realised');
			if ($realisedOnly === true && $realised === false) {
				continue;
			}

			$key = 'additions';
			if (($mutation['kind'] ?? '') === 'withdrawal') {
				$key = 'withdrawals';
			}

			$sums[$key] += self::cents(amount: (float)($mutation['amount'] ?? 0));
			$sums['planned'] = ($sums['planned'] || $realised === false);
		}

		return $sums;

	}//end sums()

	/**
	 * Euros to whole cents.
	 *
	 * @param float $amount Euros.
	 *
	 * @return int
	 */
	private static function cents(float $amount): int {
		return (int)round($amount * 100);

	}//end cents()

	/**
	 * The booking date of a mutation: today within its year, else the year's last day.
	 *
	 * @param int $year The mutation's year.
	 *
	 * @return string
	 */
	private static function entryDate(int $year): string {
		if ($year <= 0 || $year === (int)gmdate('Y')) {
			return gmdate('Y-m-d');
		}

		return sprintf('%04d-12-31', $year);

	}//end entryDate()
}//end class
