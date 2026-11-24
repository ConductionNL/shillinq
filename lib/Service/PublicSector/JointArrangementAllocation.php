<?php

/**
 * Joint Arrangement Allocation
 *
 * The allocation run of a joint arrangement (gemeenschappelijke regeling,
 * REQ-GRC-007 and REQ-GRC-008). Propose: the costs posted in the period on
 * the allocation key's cost-cluster accounts, split over the active
 * participants by share, one line per participant, with the rounding
 * remainder on the largest share so the lines add up to the total to the
 * cent. Confirm: lock the lines; a key and period has at most one confirmed
 * run. Invoice: one draft contribution invoice per participant line, naming
 * the run. Amounts are euros, summed in cents.
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
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-gr-consolidation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PublicSector;

use DateTimeImmutable;
use DomainException;
use OCA\Shillinq\Kernel\Contracts\JointArrangementAllocationInterface;
use OCA\Shillinq\Service\LedgerActuals;

/**
 * Proposes, confirms and invoices a joint arrangement allocation run.
 *
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-gr-consolidation/spec.md
 */
class JointArrangementAllocation implements JointArrangementAllocationInterface {

	/**
	 * The run states that lock a key and period.
	 */
	private const LOCKED_STATES = ['confirmed', 'invoiced'];

	/**
	 * The payment term when the run names none.
	 */
	private const DEFAULT_PAYMENT_TERM_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param PublicSectorRecords $records Keys, participants, GL lines, runs and invoices.
	 */
	public function __construct(
		private readonly PublicSectorRecords $records,
	) {
	}//end __construct()

	/**
	 * Fill a run's lines and total from the posted lines on its key's cost clusters.
	 *
	 * @param array<string,mixed> $run The run, with administrationId, keyId, periodFrom and periodTo.
	 *
	 * @return array<string,mixed> The run with keyName, name, total and lines.
	 *
	 * @throws DomainException When the run is incomplete, its key is unknown, the
	 *                         key and period already have a confirmed run, or
	 *                         there is no active participant with a share.
	 *
	 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-gr-consolidation/spec.md
	 */
	public function propose(array $run): array {
		$this->assertComplete(run: $run);
		$key = $this->records->find(schema: 'GRVerdeelsleutel', id: (string)$run['keyId']);
		if ($key === null) {
			throw new DomainException('The allocation key of the run was not found.');
		}

		$this->assertNotLocked(run: $run);

		$participants = $this->participants();
		if ($participants === []) {
			throw new DomainException('There is no active participant with a share to split the costs over.');
		}

		$totalCents = $this->clusterCents(
			administrationId: (string)$run['administrationId'],
			accounts: self::clusterAccounts(key: $key),
			periodFrom: (string)$run['periodFrom'],
			periodTo: (string)$run['periodTo']
		);

		$keyName = trim((string)($key['keyName'] ?? ''));
		$run['keyName'] = $keyName;
		$run['name'] = trim($keyName . ' ' . $run['periodFrom'] . ' – ' . $run['periodTo']);
		$run['total'] = (float)($totalCents / 100);
		$run['lines'] = self::split(totalCents: $totalCents, participants: $participants);
		return $run;

	}//end propose()

	/**
	 * Lock a proposed run.
	 *
	 * @param array<string,mixed> $run The proposed run.
	 *
	 * @return array<string,mixed> The run, unchanged.
	 *
	 * @throws DomainException When the run has no lines or the key and period
	 *                         already have a confirmed run.
	 *
	 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-gr-consolidation/spec.md
	 */
	public function confirm(array $run): array {
		$this->assertComplete(run: $run);
		if (is_array($run['lines'] ?? null) === false || $run['lines'] === []) {
			throw new DomainException('Propose the run before confirming it.');
		}

		$this->assertNotLocked(run: $run);
		return $run;

	}//end confirm()

	/**
	 * Write one draft contribution invoice per participant line of a confirmed run.
	 *
	 * Every participant is checked for a customer before any invoice is written,
	 * and a line that already has an invoice keeps it, so a retried run never
	 * invoices a participant twice.
	 *
	 * @param array<string,mixed> $run The confirmed run.
	 *
	 * @return array<string,mixed> The run with each line's invoiceId.
	 *
	 * @throws DomainException When a participant to invoice has no customer.
	 *
	 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-gr-consolidation/spec.md
	 */
	public function invoice(array $run): array {
		$lines = array_values((array)($run['lines'] ?? []));
		$customers = [];
		$missing = [];
		foreach ($lines as $index => $line) {
			if ((string)($line['invoiceId'] ?? '') !== '' || self::cents(amount: $line['amount'] ?? 0) === 0) {
				continue;
			}

			$participant = $this->records->find(schema: 'GRDeelnemer', id: (string)($line['participantId'] ?? ''));
			$customerId = trim((string)($participant['customerId'] ?? ''));
			if ($customerId === '') {
				$missing[] = (string)($line['participantName'] ?? $line['participantId'] ?? '');
				continue;
			}

			$customers[$index] = $customerId;
		}

		if ($missing !== []) {
			throw new DomainException('Link a customer to these participants before invoicing: ' . implode(', ', $missing) . '.');
		}

		$invoiceDate = (string)($run['invoiceDate'] ?? '');
		if (self::isDate(day: $invoiceDate) === false) {
			$invoiceDate = (new DateTimeImmutable('today'))->format('Y-m-d');
		}

		$termDays = self::DEFAULT_PAYMENT_TERM_DAYS;
		if (is_numeric($run['paymentTermDays'] ?? null) === true && (int)$run['paymentTermDays'] >= 0) {
			$termDays = (int)$run['paymentTermDays'];
		}

		$dueDate = (new DateTimeImmutable($invoiceDate))->modify('+' . $termDays . ' days')->format('Y-m-d');
		foreach ($customers as $index => $customerId) {
			$lines[$index]['invoiceId'] = $this->records->create(
				schema: 'ARInvoice',
				object: self::invoicePayload(
					run: $run,
					line: $lines[$index],
					customerId: $customerId,
					dates: ['invoice' => $invoiceDate, 'due' => $dueDate],
					sequence: ($index + 1)
				)
			);
		}

		$run['lines'] = $lines;
		return $run;

	}//end invoice()

	/**
	 * Refuse a run without an administration, a key or a valid period.
	 *
	 * @param array<string,mixed> $run The run.
	 *
	 * @return void
	 *
	 * @throws DomainException When a field is missing or the period runs backwards.
	 */
	private function assertComplete(array $run): void {
		if ((string)($run['administrationId'] ?? '') === '' || (string)($run['keyId'] ?? '') === '') {
			throw new DomainException('Choose the administration and the allocation key of the run.');
		}

		$from = (string)($run['periodFrom'] ?? '');
		$to = (string)($run['periodTo'] ?? '');
		if (self::isDate(day: $from) === false || self::isDate(day: $to) === false || $from > $to) {
			throw new DomainException('Enter the period of the run as two dates, the first on or before the second.');
		}

	}//end assertComplete()

	/**
	 * Refuse a run whose key and period already have another confirmed run.
	 *
	 * @param array<string,mixed> $run The run.
	 *
	 * @return void
	 *
	 * @throws DomainException Naming the confirmed run.
	 */
	private function assertNotLocked(array $run): void {
		$runId = (string)($run['id'] ?? '');
		$others = $this->records->records(schema: 'GRAllocationRun', filters: ['keyId' => (string)$run['keyId']]);
		foreach ($others as $other) {
			if ($runId !== '' && (string)($other['id'] ?? '') === $runId) {
				continue;
			}

			if ((string)($other['keyId'] ?? '') !== (string)$run['keyId']
				|| (string)($other['periodFrom'] ?? '') !== (string)$run['periodFrom']
				|| (string)($other['periodTo'] ?? '') !== (string)$run['periodTo']
				|| in_array((string)($other['state'] ?? ''), self::LOCKED_STATES, true) === false
			) {
				continue;
			}

			$name = trim((string)($other['name'] ?? ''));
			if ($name === '') {
				$name = (string)($other['id'] ?? '');
			}

			throw new DomainException('This key and period already have a confirmed allocation run: ' . $name . '.');
		}//end foreach

	}//end assertNotLocked()

	/**
	 * The active participants with a share, largest share first.
	 *
	 * @return list<array{id: string, name: string, share: float}>
	 */
	private function participants(): array {
		$participants = [];
		foreach ($this->records->records(schema: 'GRDeelnemer', filters: []) as $row) {
			$share = (float)($row['share'] ?? 0);
			if (($row['actief'] ?? true) === false || (string)($row['lifecycleState'] ?? 'active') !== 'active' || $share <= 0) {
				continue;
			}

			$participants[] = [
				'id'    => (string)($row['id'] ?? ''),
				'name'  => (string)($row['participantName'] ?? ''),
				'share' => $share,
			];
		}

		usort(
			$participants,
			static fn (array $a, array $b): int => [$b['share'], $a['name'], $a['id']] <=> [$a['share'], $b['name'], $b['id']]
		);
		return $participants;

	}//end participants()

	/**
	 * Split the total over the participants by share: each line rounded towards
	 * zero to the cent, the remainder on the largest share.
	 *
	 * @param int                                               $totalCents   The total in cents.
	 * @param list<array{id: string, name: string, share: float}> $participants Largest share first.
	 *
	 * @return list<array{participantId: string, participantName: string, share: float, amount: float}>
	 */
	private static function split(int $totalCents, array $participants): array {
		$shareSum = array_sum(array_column($participants, 'share'));
		$sign = 1;
		if ($totalCents < 0) {
			$sign = -1;
		}

		$absolute = abs($totalCents);

		$parts = [];
		foreach ($participants as $participant) {
			$parts[] = (int)floor(($absolute * $participant['share'] / $shareSum) + 1e-9);
		}

		$parts[0] += ($absolute - array_sum($parts));

		$lines = [];
		foreach ($participants as $index => $participant) {
			$lines[] = [
				'participantId'   => $participant['id'],
				'participantName' => $participant['name'],
				'share'           => round($participant['share'] / $shareSum, 6),
				'amount'          => (float)(($sign * $parts[$index]) / 100),
			];
		}

		return $lines;

	}//end split()

	/**
	 * The signed cents posted in the period on the cluster's accounts, debit
	 * positive; lines flagged as eliminated are left out.
	 *
	 * @param string       $administrationId The joint arrangement's administration.
	 * @param list<string> $accounts         The cost-cluster account numbers.
	 * @param string       $periodFrom       The first day, inclusive.
	 * @param string       $periodTo         The last day, inclusive.
	 *
	 * @return int
	 */
	private function clusterCents(string $administrationId, array $accounts, string $periodFrom, string $periodTo): int {
		if ($accounts === []) {
			return 0;
		}

		$posted = [];
		$transactions = $this->records->records(schema: 'GLTransaction', filters: ['administrationId' => $administrationId, 'state' => 'posted']);
		foreach ($transactions as $transaction) {
			$day = substr((string)($transaction['postingDate'] ?? ''), 0, 10);
			if ((string)($transaction['administrationId'] ?? '') === $administrationId
				&& ($transaction['state'] ?? '') === 'posted'
				&& $day !== ''
				&& $day >= $periodFrom
				&& $day <= $periodTo
			) {
				$posted[] = $transaction;
			}
		}

		$ledger = new LedgerActuals();
		$monthByRef = $ledger->postingMonths(transactions: $posted);
		if ($monthByRef === []) {
			return 0;
		}

		$lines = array_values(
			array_filter(
				$this->records->records(schema: 'GLLine', filters: []),
				static fn (array $line): bool => ($line['eliminationFlag'] ?? false) !== true
			)
		);

		$wanted = array_flip($accounts);
		$cents = 0;
		foreach ($ledger->centsByAccountMonth(lines: $lines, monthByRef: $monthByRef) as $accountNumber => $months) {
			if (isset($wanted[(string)$accountNumber]) === true) {
				$cents += array_sum($months);
			}
		}

		return $cents;

	}//end clusterCents()

	/**
	 * The key's cost-cluster account numbers, trimmed and without blanks.
	 *
	 * @param array<string,mixed> $key The allocation key.
	 *
	 * @return list<string>
	 */
	private static function clusterAccounts(array $key): array {
		$accounts = [];
		foreach ((array)($key['costClusterAccountNumbers'] ?? []) as $account) {
			$account = trim((string)$account);
			if ($account !== '') {
				$accounts[] = $account;
			}
		}

		return array_values(array_unique($accounts));

	}//end clusterAccounts()

	/**
	 * The draft contribution invoice of one participant line.
	 *
	 * @param array<string,mixed>                 $run        The run.
	 * @param array<string,mixed>                 $line       The participant line.
	 * @param string                              $customerId The participant's customer.
	 * @param array{invoice: string, due: string} $dates      The invoice and due dates.
	 * @param int                                 $sequence   The line's number within the run.
	 *
	 * @return array<string,mixed> The ARInvoice payload.
	 */
	private static function invoicePayload(array $run, array $line, string $customerId, array $dates, int $sequence): array {
		$amount = (float)(self::cents(amount: $line['amount'] ?? 0) / 100);
		$runId = (string)($run['id'] ?? '');
		$description = trim('Bijdrage ' . (string)($run['keyName'] ?? '') . ' ' . $run['periodFrom'] . ' t/m ' . $run['periodTo']);

		return [
			'invoiceNumber'    => sprintf(
				'GR-%s-%s-%02d',
				str_replace('-', '', substr((string)$run['periodFrom'], 0, 7)),
				strtoupper(substr(str_replace('-', '', $runId), 0, 8)),
				$sequence
			),
			'customerId'       => $customerId,
			'administrationId' => (string)$run['administrationId'],
			'invoiceDate'      => $dates['invoice'],
			'dueDate'          => $dates['due'],
			'periodId'         => substr($dates['invoice'], 0, 7),
			'currency'         => 'EUR',
			'netAmount'        => $amount,
			'vatAmount'        => 0.0,
			'grossAmount'      => $amount,
			'lifecycleState'   => 'draft',
			'allocationRunId'  => $runId,
			'invoiceLines'     => [
				[
					'lineId'      => '1',
					'quantity'    => 1,
					'unitCode'    => 'C62',
					'itemName'    => $description,
					'netPrice'    => $amount,
					'netAmount'   => $amount,
					'vatCategory' => 'O',
				],
			],
		];

	}//end invoicePayload()

	/**
	 * A euro amount in cents.
	 *
	 * @param mixed $amount The amount.
	 *
	 * @return int
	 */
	private static function cents(mixed $amount): int {
		return (int)round(((float)$amount) * 100);

	}//end cents()

	/**
	 * Whether a value is a `Y-m-d` date.
	 *
	 * @param string $day The value.
	 *
	 * @return bool
	 */
	private static function isDate(string $day): bool {
		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
		return $parsed !== false && $parsed->format('Y-m-d') === $day;

	}//end isDate()
}//end class
