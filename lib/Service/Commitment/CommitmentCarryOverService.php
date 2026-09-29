<?php

/**
 * Commitment Carry Over Service
 *
 * At year end, every open commitment line continues in the next year under
 * the same commitment number (planning-commitment-year-end, REQ-PCYE-004):
 *
 * - preview(): each open line of the year with what remains, and per
 *   programme how much of it the next year's budget cannot absorb;
 * - execute(): per line a new line in the next year for what remains, with
 *   `carriedFromLine` naming the old one, the old line closed, a
 *   `carried_forward` movement out of the old year and one into the new,
 *   and the outstanding commitments moved between the two budgets.
 *
 * A signed commitment is an obligation whatever the next budget says, so a
 * shortfall is listed, not refused. A second run finds no open line and
 * writes nothing.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Commitment
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

namespace OCA\Shillinq\Service\Commitment;

/**
 * The year-end carry-over of open commitments.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
class CommitmentCarryOverService {
	/**
	 * Commitment states whose lines carry over.
	 *
	 * @var array<int,string>
	 */
	private const OPEN = ['committed', 'partially_delivered', 'partially_invoiced', 'partially_paid'];

	/**
	 * Constructor.
	 *
	 * @param CommitmentLedger $ledger Movements, lines and budgets.
	 */
	public function __construct(
		private readonly CommitmentLedger $ledger,
	) {

	}//end __construct()

	/**
	 * The open lines of a year and the next year's shortfalls.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $fromYear         The year that ends.
	 *
	 * @return array{fromYear:int,toYear:int,lines:list<array<string,mixed>>,shortfalls:list<array<string,mixed>>,total:int}
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function preview(string $administrationId, int $fromYear): array {
		$lines = [];
		$needed = [];
		foreach ($this->openLines(administrationId: $administrationId, year: $fromYear) as $line) {
			$programme = (string)($line['programme'] ?? '');
			$remaining = (int)$line['remaining_committed'];
			$lines[] = [
				'lineId'           => (string)$line['id'],
				'commitmentNumber' => (string)$line['commitment'],
				'description'      => (string)($line['description'] ?? ''),
				'programme'        => $programme,
				'remaining'        => $remaining,
			];
			$needed[$programme] = (($needed[$programme] ?? 0) + $remaining);
		}

		$shortfalls = [];
		foreach ($needed as $programme => $amount) {
			$budget = $this->ledger->budget(administrationId: $administrationId, programme: (string)$programme, year: $fromYear + 1);
			$free = 0;
			if ($budget !== null) {
				$free = CommitmentLedger::free(budget: $budget);
			}

			if ($amount > $free) {
				$shortfalls[] = [
					'programme'  => (string)$programme,
					'carried'    => $amount,
					'free'       => $free,
					'shortfall'  => ($amount - $free),
					'commitments' => $this->commitmentsOn(lines: $lines, programme: (string)$programme),
				];
			}
		}

		return [
			'fromYear'   => $fromYear,
			'toYear'     => ($fromYear + 1),
			'lines'      => $lines,
			'shortfalls' => $shortfalls,
			'total'      => array_sum(array_column($lines, 'remaining')),
		];

	}//end preview()

	/**
	 * The commitment numbers with a line on a programme.
	 *
	 * @param list<array<string,mixed>> $lines     The preview lines.
	 * @param string                    $programme The programme.
	 *
	 * @return list<string>
	 */
	private function commitmentsOn(array $lines, string $programme): array {
		$numbers = [];
		foreach ($lines as $line) {
			if ($line['programme'] === $programme && in_array($line['commitmentNumber'], $numbers, true) === false) {
				$numbers[] = $line['commitmentNumber'];
			}
		}

		return $numbers;

	}//end commitmentsOn()

	/**
	 * Carry every open line of a year to the next.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $fromYear         The year that ends.
	 * @param string $user             Who ran it.
	 *
	 * @return array{fromYear:int,toYear:int,lines:list<array<string,mixed>>,shortfalls:list<array<string,mixed>>,total:int}
	 *   The preview that was carried out.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function execute(string $administrationId, int $fromYear, string $user): array {
		$preview = $this->preview(administrationId: $administrationId, fromYear: $fromYear);
		foreach ($this->openLines(administrationId: $administrationId, year: $fromYear) as $line) {
			$this->carry(line: $line, toYear: $fromYear + 1, user: $user);
		}

		return $preview;

	}//end execute()

	/**
	 * Carry one line.
	 *
	 * @param array<string,mixed> $line   The open line.
	 * @param int                 $toYear The next year.
	 * @param string              $user   Who ran it.
	 *
	 * @return void
	 */
	private function carry(array $line, int $toYear, string $user): void {
		$remaining = (int)$line['remaining_committed'];
		$next = array_filter(
			[
				'administrationId'     => (string)$line['administrationId'],
				'commitment'           => (string)$line['commitment'],
				'ruleNumber'           => (int)($line['ruleNumber'] ?? 1),
				'description'          => (string)($line['description'] ?? ''),
				'financialYear'        => $toYear,
				'amount_excl_vat'      => $remaining,
				'generalLedgerAccount' => (string)($line['generalLedgerAccount'] ?? ''),
				'costCentre'           => (string)($line['costCentre'] ?? ''),
				'programme'            => (string)($line['programme'] ?? ''),
				'vat_code'             => (string)($line['vat_code'] ?? ''),
				'invoiced_amount'      => 0,
				'remaining_committed'  => $remaining,
				'afgesloten'           => false,
				'carriedFromLine'      => (string)$line['id'],
			],
			static fn ($value): bool => $value !== ''
		);
		$nextId = $this->ledger->create(schema: 'CommitmentLine', object: $next);
		$next['id'] = $nextId;

		$this->ledger->patch(schema: 'CommitmentLine', id: (string)$line['id'], data: ['remaining_committed' => 0, 'afgesloten' => true]);
		$this->ledger->adjustBudget(line: $line, outstanding: -$remaining, realised: 0);
		$this->ledger->adjustBudget(line: $next, outstanding: $remaining, realised: 0);

		$commitment = ['administrationId' => (string)$line['administrationId'], 'commitmentNumber' => (string)$line['commitment']];
		$note = sprintf('Carried from %d to %d', (int)$line['financialYear'], $toYear);
		$out = ['commitmentRule' => (string)$line['id'], 'user' => $user, 'notes' => $note];
		$into = ['commitmentRule' => $nextId, 'user' => $user, 'notes' => $note];
		$this->ledger->movement(commitment: $commitment, kind: 'carried_forward', amount: -$remaining, extra: $out);
		$this->ledger->movement(commitment: $commitment, kind: 'carried_forward', amount: $remaining, extra: $into);

	}//end carry()

	/**
	 * The open lines of a year with something remaining, on open commitments.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $year             The year.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function openLines(string $administrationId, int $year): array {
		$open = [];
		foreach ($this->ledger->records(schema: 'Commitment', filters: ['administrationId' => $administrationId]) as $commitment) {
			if (in_array((string)($commitment['status'] ?? ''), self::OPEN, true) === true) {
				$open[(string)($commitment['commitmentNumber'] ?? '')] = true;
			}
		}

		$lines = [];
		foreach ($this->ledger->records(schema: 'CommitmentLine', filters: ['administrationId' => $administrationId, 'financialYear' => $year]) as $line) {
			$remaining = (int)($line['remaining_committed'] ?? 0);
			if (($line['afgesloten'] ?? false) === true || $remaining <= 0 || isset($open[(string)($line['commitment'] ?? '')]) === false) {
				continue;
			}

			$lines[] = $line;
		}

		return $lines;

	}//end openLines()
}//end class
