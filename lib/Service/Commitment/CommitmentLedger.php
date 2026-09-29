<?php

/**
 * Commitment Ledger
 *
 * Writes what a commitment does to its lines, its movements and its budgets
 * (planning-commitment-year-end, REQ-PCYE-001 to REQ-PCYE-004):
 *
 * - committed(): one `committed` movement for the commitment, each line's
 *   remaining amount set, the matching budgets' outstanding commitments raised;
 * - invoiced(): an `invoiced` movement per line an approved supplier invoice
 *   pays, each line's invoiced and remaining amounts moved, the budgets'
 *   outstanding lowered and realised raised;
 * - closed(): the remainder released, one `closed` movement of minus that
 *   amount, every line closed, the budgets' outstanding lowered.
 *
 * Each is idempotent: a second call for the same commitment (and invoice)
 * writes nothing. A budget's free capacity is authorised minus realised minus
 * outstanding, the rule BudgetBlocker::freeRoom() applies. Amounts are EUR cents.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Movements, line amounts and budget figures of commitments.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
class CommitmentLedger {
	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object surface.
	 * @param SettingsService        $settings      Register slug.
	 * @param ITimeFactory           $time          Today.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly ITimeFactory $time,
	) {

	}//end __construct()

	/**
	 * Record a commitment entered into: the movement, the remaining amounts and the budgets.
	 *
	 * @param array<string,mixed> $commitment The commitment.
	 * @param string              $user       Who acted.
	 *
	 * @return int The amount recorded in cents, 0 when it was recorded before.
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-1.2
	 */
	public function committed(array $commitment, string $user): int {
		$number = (string)($commitment['commitmentNumber'] ?? '');
		if ($number === '' || $this->hasMovement(commitmentNumber: $number, kind: 'committed') === true) {
			return 0;
		}

		$total = 0;
		foreach ($this->lines(commitmentNumber: $number) as $line) {
			$amount = (int)($line['amount_excl_vat'] ?? 0);
			$remaining = ($amount - (int)($line['invoiced_amount'] ?? 0));
			$this->patch(schema: 'CommitmentLine', id: (string)$line['id'], data: ['remaining_committed' => $remaining]);
			$this->adjustBudget(line: $line, outstanding: $remaining, realised: 0);
			$total += $amount;
		}

		if ($total === 0) {
			$total = (int)($commitment['total_amount_excl_vat'] ?? 0);
		}

		$this->movement(commitment: $commitment, kind: 'committed', amount: $total, extra: ['user' => $user]);
		return $total;

	}//end committed()

	/**
	 * Record an approved supplier invoice on a commitment.
	 *
	 * The amount goes to the open line with the invoice's cost centre when
	 * exactly one has it, otherwise over the open lines in proportion to what
	 * they still have remaining.
	 *
	 * @param array<string,mixed> $commitment The commitment.
	 * @param array<string,mixed> $invoice    The supplier invoice, with its id.
	 *
	 * @return int The remaining amount of the commitment after the invoice, in cents.
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-2.2
	 */
	public function invoiced(array $commitment, array $invoice): int {
		$number = (string)($commitment['commitmentNumber'] ?? '');
		$invoiceId = (string)($invoice['id'] ?? '');
		$lines = $this->openLines(commitmentNumber: $number);
		if ($invoiceId === '' || $this->hasMovement(commitmentNumber: $number, kind: 'invoiced', relatedInvoice: $invoiceId) === true) {
			return $this->remaining(lines: $lines);
		}

		$amount = (int)($invoice['totalExclVat'] ?? 0);
		foreach ($this->allocate(lines: $lines, amount: $amount, costCentre: (string)($invoice['costCenter'] ?? '')) as $index => $share) {
			if ($share === 0) {
				continue;
			}

			$line = $lines[$index];
			$released = min($share, (int)($line['remaining_committed'] ?? 0));
			$lines[$index]['invoiced_amount'] = ((int)($line['invoiced_amount'] ?? 0) + $share);
			$lines[$index]['remaining_committed'] = ((int)($line['remaining_committed'] ?? 0) - $released);
			$this->patch(
				schema: 'CommitmentLine',
				id: (string)$line['id'],
				data: ['invoiced_amount' => $lines[$index]['invoiced_amount'], 'remaining_committed' => $lines[$index]['remaining_committed']]
			);
			$this->adjustBudget(line: $line, outstanding: -$released, realised: $share);
			$this->movement(
				commitment: $commitment,
				kind: 'invoiced',
				amount: $share,
				extra: ['commitmentRule' => (string)$line['id'], 'related_invoice' => $invoiceId, 'notes' => (string)($invoice['invoiceNumber'] ?? '')]
			);
		}//end foreach

		return $this->remaining(lines: $lines);

	}//end invoiced()

	/**
	 * Close a commitment: release what remains to the budgets.
	 *
	 * @param array<string,mixed> $commitment The commitment.
	 * @param string              $user       Who acted.
	 *
	 * @return int The released amount in cents.
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-2.1
	 */
	public function closed(array $commitment, string $user): int {
		$number = (string)($commitment['commitmentNumber'] ?? '');
		if ($number === '' || $this->hasMovement(commitmentNumber: $number, kind: 'closed') === true) {
			return 0;
		}

		$released = 0;
		foreach ($this->openLines(commitmentNumber: $number) as $line) {
			$remaining = (int)($line['remaining_committed'] ?? 0);
			$this->patch(schema: 'CommitmentLine', id: (string)$line['id'], data: ['remaining_committed' => 0, 'afgesloten' => true]);
			$this->adjustBudget(line: $line, outstanding: -$remaining, realised: 0);
			$released += $remaining;
		}

		$this->movement(commitment: $commitment, kind: 'closed', amount: -$released, extra: ['user' => $user]);
		return $released;

	}//end closed()

	/**
	 * What a commitment still has remaining over its open lines.
	 *
	 * @param string $commitmentNumber The commitment number.
	 *
	 * @return int Cents.
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-2.3
	 */
	public function remainingOf(string $commitmentNumber): int {
		return $this->remaining(lines: $this->openLines(commitmentNumber: $commitmentNumber));

	}//end remainingOf()

	/**
	 * The lines of a commitment that are not closed.
	 *
	 * @param string $commitmentNumber The commitment number.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function openLines(string $commitmentNumber): array {
		$lines = $this->lines(commitmentNumber: $commitmentNumber);
		return array_values(array_filter($lines, static fn (array $line): bool => ($line['afgesloten'] ?? false) !== true));

	}//end openLines()

	/**
	 * Every line of a commitment.
	 *
	 * @param string $commitmentNumber The commitment number.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function lines(string $commitmentNumber): array {
		if ($commitmentNumber === '') {
			return [];
		}

		return $this->records(schema: 'CommitmentLine', filters: ['commitment' => $commitmentNumber]);

	}//end lines()

	/**
	 * The budget of a programme in a year, or null.
	 *
	 * @param string $administrationId The administration.
	 * @param string $programme        The programme code.
	 * @param int    $year             The financial year.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function budget(string $administrationId, string $programme, int $year): ?array {
		$rows = $this->records(
			schema: 'CommitmentBudget',
			filters: ['administrationId' => $administrationId, 'programmeCode' => $programme, 'financialYear' => $year]
		);
		return ($rows[0] ?? null);

	}//end budget()

	/**
	 * Free capacity of a budget: authorised minus realised minus outstanding.
	 *
	 * @param array<string,mixed> $budget The budget.
	 *
	 * @return int Cents.
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-3.1
	 */
	public static function free(array $budget): int {
		return ((int)($budget['authorised_amount'] ?? 0) - (int)($budget['realised_amount'] ?? 0) - (int)($budget['outstanding_commitments'] ?? 0));

	}//end free()

	/**
	 * Move a budget's outstanding and realised amounts for a line's programme and year.
	 *
	 * @param array<string,mixed> $line        The commitment line.
	 * @param int                 $outstanding Change of outstanding commitments, cents.
	 * @param int                 $realised    Change of realised amount, cents.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function adjustBudget(array $line, int $outstanding, int $realised): void {
		if ($outstanding === 0 && $realised === 0) {
			return;
		}

		$budget = $this->budget(
			administrationId: (string)($line['administrationId'] ?? ''),
			programme: (string)($line['programme'] ?? ''),
			year: (int)($line['financialYear'] ?? 0)
		);
		if ($budget === null) {
			return;
		}

		$budget['outstanding_commitments'] = ((int)($budget['outstanding_commitments'] ?? 0) + $outstanding);
		$budget['realised_amount'] = ((int)($budget['realised_amount'] ?? 0) + $realised);
		$this->patch(
			schema: 'CommitmentBudget',
			id: (string)$budget['id'],
			data: [
				'outstanding_commitments' => $budget['outstanding_commitments'],
				'realised_amount'         => $budget['realised_amount'],
				'free_capacity'           => self::free(budget: $budget),
			]
		);

	}//end adjustBudget()

	/**
	 * Write a movement.
	 *
	 * @param array<string,mixed> $commitment The commitment.
	 * @param string              $kind       The movement kind.
	 * @param int                 $amount     Cents, negative for a release.
	 * @param array<string,mixed> $extra      Further fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function movement(array $commitment, string $kind, int $amount, array $extra = []): void {
		$movement = array_merge(
			[
				'administrationId' => (string)($commitment['administrationId'] ?? ''),
				'commitment'       => (string)($commitment['commitmentNumber'] ?? ''),
				'date'             => $this->time->getDateTime()->format('Y-m-d'),
				'kind'             => $kind,
				'amount'           => $amount,
				'currency'         => (string)($commitment['currency'] ?? 'EUR'),
			],
			array_filter($extra, static fn ($value): bool => $value !== '' && $value !== null)
		);
		$this->scoped(schema: 'CommitmentMovement')->saveObject($movement);

	}//end movement()

	/**
	 * Patch an object.
	 *
	 * @param string              $schema The schema.
	 * @param string              $id     The object id.
	 * @param array<string,mixed> $data   The fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function patch(string $schema, string $id, array $data): void {
		$this->scoped(schema: $schema)->patchObject($id, $data);

	}//end patch()

	/**
	 * Save a new object and answer its id.
	 *
	 * @param string              $schema The schema.
	 * @param array<string,mixed> $object The object.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function create(string $schema, array $object): string {
		return ObjectIdentifier::resolve(saved: $this->scoped(schema: $schema)->saveObject($object));

	}//end create()

	/**
	 * One object by id, with its id, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object id.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-2.2
	 */
	public function find(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$record = ObjectIdentifier::findOne(scoped: $this->scoped(schema: $schema), id: $id);
		if ($record === null) {
			return null;
		}

		$record['id'] = $id;
		return $record;

	}//end find()

	/**
	 * Records of a schema with their ids.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Property filters.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function records(string $schema, array $filters): array {
		$records = [];
		foreach ($this->scoped(schema: $schema)->findAll(['filters' => $filters, 'limit' => 10000]) as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;

	}//end records()

	/**
	 * Whether a movement of a kind (for an invoice) was written before.
	 *
	 * @param string      $commitmentNumber The commitment number.
	 * @param string      $kind             The kind.
	 * @param string|null $relatedInvoice   The invoice, for invoiced movements.
	 *
	 * @return bool
	 */
	private function hasMovement(string $commitmentNumber, string $kind, ?string $relatedInvoice = null): bool {
		$filters = ['commitment' => $commitmentNumber, 'kind' => $kind];
		if ($relatedInvoice !== null) {
			$filters['related_invoice'] = $relatedInvoice;
		}

		return $this->records(schema: 'CommitmentMovement', filters: $filters) !== [];

	}//end hasMovement()

	/**
	 * Share an amount over open lines: one cost centre match takes it all, else in proportion to what remains.
	 *
	 * @param list<array<string,mixed>> $lines      The open lines.
	 * @param int                       $amount     Cents.
	 * @param string                    $costCentre The invoice's cost centre.
	 *
	 * @return array<int,int> Share per line index, adding up to the amount.
	 */
	private function allocate(array $lines, int $amount, string $costCentre): array {
		if ($lines === []) {
			return [];
		}

		$matching = array_keys(
			array_filter($lines, static fn (array $line): bool => $costCentre !== '' && (string)($line['costCentre'] ?? '') === $costCentre)
		);
		if (count($matching) === 1) {
			return [$matching[0] => $amount];
		}

		$weights = array_map(static fn (array $line): int => max(0, (int)($line['remaining_committed'] ?? 0)), $lines);
		$total = array_sum($weights);
		if ($total === 0) {
			return [0 => $amount];
		}

		$shares = [];
		$given = 0;
		$last = array_key_last($lines);
		foreach ($weights as $index => $weight) {
			$share = (int)floor($amount * $weight / $total);
			if ($index === $last) {
				$share = ($amount - $given);
			}

			$shares[$index] = $share;
			$given += $share;
		}

		return $shares;

	}//end allocate()

	/**
	 * Sum of remaining amounts.
	 *
	 * @param list<array<string,mixed>> $lines The lines.
	 *
	 * @return int
	 */
	private function remaining(array $lines): int {
		return array_sum(array_map(static fn (array $line): int => (int)($line['remaining_committed'] ?? 0), $lines));

	}//end remaining()

	/**
	 * The object service on a schema of the register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);

	}//end scoped()
}//end class
