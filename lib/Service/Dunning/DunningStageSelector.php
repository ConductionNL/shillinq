<?php

/**
 * Dunning Stage Selector
 *
 * Picks the ladder stage the next dunning tick sends for one invoice
 * (receivables-automatic-dunning REQ-RAD-002, design D3): the lowest-numbered
 * stage not yet sent whose threshold is reached, and a later stage only once
 * the gap between its threshold and the previous sent stage's has passed since
 * that stage went out. A run whose send FAILED does not count as sent, so the
 * next tick tries that stage again (REQ-RAD-003).
 *
 * It reads only the stages and the invoice's runs it is handed and does no I/O,
 * so the tick and its preview ask the same question and get the same answer.
 * DunningRunService consults it in tickInvoice() and previewInvoice().
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The stage the next dunning tick sends.
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-1.1
 */
class DunningStageSelector {

	/**
	 * Construct the selector.
	 *
	 * @param LoggerInterface|null $logger Logs an invoice whose due date cannot be read.
	 */
	public function __construct(
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Days an invoice is overdue, or null when it is not due yet or has no
	 * readable due date.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 * @param DateTimeImmutable   $now     Now.
	 *
	 * @return int|null The days overdue.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-1.1
	 */
	public function daysOverdue(array $invoice, DateTimeImmutable $now): ?int {
		$dueDateRaw = (string)($invoice['dueDate'] ?? '');
		if ($dueDateRaw === '') {
			return null;
		}

		try {
			$dueDate = new DateTimeImmutable($dueDateRaw);
		} catch (Throwable $e) {
			$this->logger?->warning('Shillinq: tickInvoice malformed dueDate: ' . $dueDateRaw);
			return null;
		}

		if ($now < $dueDate) {
			return null;
		}

		return (int)$dueDate->diff($now)->days;
	}//end daysOverdue()

	/**
	 * Pick the highest ladder stage applicable to an invoice now.
	 *
	 * Given the resolved stages (base or override) and the number of days the
	 * invoice has been overdue, walk the stages by ascending `dagenNaVervalDatum`
	 * and return the last stage whose threshold has been reached. Returns null
	 * when no stage applies yet (invoice is still within terms).
	 *
	 * @param array<int,array<string,mixed>> $stages Resolved stages.
	 * @param int $daysInArrears Days the invoice has been overdue (>= 0).
	 *
	 * @return array<string,mixed>|null The applicable stage definition or null.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-12
	 */
	public function highestReached(array $stages, int $daysInArrears): ?array {
		if ($daysInArrears < 0) {
			return null;
		}

		$sorted = $stages;
		usort(
			$sorted,
			static function (array $a, array $b): int {
				return (int)($a['daysAfterExpiryDate'] ?? 0) <=> (int)($b['daysAfterExpiryDate'] ?? 0);
			}
		);

		$picked = null;
		foreach ($sorted as $stage) {
			$threshold = (int)($stage['daysAfterExpiryDate'] ?? 0);
			if ($daysInArrears >= $threshold) {
				$picked = $stage;
				continue;
			}

			break;
		}

		return $picked;
	}//end highestReached()

	/**
	 * Pick the stage definition for a given stageNr from a resolved ladder.
	 *
	 * @param array<int,array<string,mixed>> $stages Resolved stages.
	 * @param int $stageNr Stage number to retrieve.
	 *
	 * @return array<string,mixed>|null Stage definition, null when no such stage exists.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-18
	 */
	public function definition(array $stages, int $stageNr): ?array {
		foreach ($stages as $stage) {
			if ((int)($stage['nr'] ?? 0) === $stageNr) {
				return $stage;
			}
		}

		return null;
	}//end definition()

	/**
	 * The stage the next tick sends, or null when none is due.
	 *
	 * @param array<int, mixed>                $stages        The resolved ladder stages.
	 * @param array<int, array<string, mixed>> $runs          The invoice's DunningRun records.
	 * @param int                              $daysInArrears Days the invoice is overdue.
	 * @param DateTimeImmutable                $now           Now.
	 *
	 * @return array<string, mixed>|null The stage.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-1.1
	 */
	public function next(array $stages, array $runs, int $daysInArrears, DateTimeImmutable $now): ?array {
		$sorted = array_values(array_filter($stages, 'is_array'));
		usort($sorted, static fn (array $a, array $b): int => (int)($a['nr'] ?? 0) <=> (int)($b['nr'] ?? 0));

		$sentOn = $this->sentOn(runs: $runs);
		$previous = null;
		foreach ($sorted as $stage) {
			if (array_key_exists((int)($stage['nr'] ?? 0), $sentOn) === true) {
				$previous = $stage;
				continue;
			}

			$threshold = (int)($stage['daysAfterExpiryDate'] ?? 0);
			if ($daysInArrears < $threshold) {
				return null;
			}

			if ($previous !== null && $this->gapHasPassed(previous: $previous, threshold: $threshold, sentOn: $sentOn, now: $now) === false) {
				return null;
			}

			return $stage;
		}

		return null;
	}//end next()

	/**
	 * The stages that went out, each with the latest date it went out: every
	 * run but a FAILED one.
	 *
	 * @param array<int, array<string, mixed>> $runs The invoice's DunningRun records.
	 *
	 * @return array<int, string> Stage number => executedOn ('' when unknown).
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-1.1
	 */
	public function sentOn(array $runs): array {
		$sent = [];
		foreach ($runs as $run) {
			if (strtoupper((string)($run['deliveryStatus'] ?? '')) === 'FAILED') {
				continue;
			}

			$stageNr = (int)($run['stageNr'] ?? 0);
			$executedOn = (string)($run['executedOn'] ?? '');
			if (isset($sent[$stageNr]) === false || strcmp($executedOn, $sent[$stageNr]) > 0) {
				$sent[$stageNr] = $executedOn;
			}
		}

		return $sent;
	}//end sentOn()

	/**
	 * Whether the gap between two stages' thresholds has passed since the
	 * previous stage went out. A run without a readable date does not hold the
	 * next stage back.
	 *
	 * @param array<string, mixed> $previous  The previous sent stage.
	 * @param int                  $threshold The next stage's threshold.
	 * @param array<int, string>   $sentOn    Stage number => executedOn.
	 * @param DateTimeImmutable    $now       Now.
	 *
	 * @return bool True when the next stage may go.
	 */
	private function gapHasPassed(array $previous, int $threshold, array $sentOn, DateTimeImmutable $now): bool {
		$gap = ($threshold - (int)($previous['daysAfterExpiryDate'] ?? 0));
		$sentRaw = ($sentOn[(int)($previous['nr'] ?? 0)] ?? '');
		if ($sentRaw === '') {
			return true;
		}

		try {
			$sent = new DateTimeImmutable($sentRaw);
		} catch (Throwable $e) {
			return true;
		}

		return ($sent <= $now && (int)$sent->diff($now)->days >= $gap);
	}//end gapHasPassed()
}//end class
