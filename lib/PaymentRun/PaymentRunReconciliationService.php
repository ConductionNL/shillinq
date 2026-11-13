<?php

/**
 * Payment-run reconciliation service (CAMT.053 match)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reconciles a PaymentRun in lifecycle state `exported` against an imported
 * CAMT.053 bank statement. It parses the statement's booked outgoing entries
 * (Camt053StatementParser) and matches each back to the run's paymentLines[]:
 *
 *  - PRIMARY key: EndToEndId. Each exported line carries a deterministic
 *    <runNumber>-<lineIndex> EndToEndId emitted in the pain.001 export; an exact
 *    match is authoritative.
 *  - FALLBACK: (amount, creditorIban) against an as-yet-unmatched line — used
 *    when a statement entry truncates / omits the EndToEndId.
 *
 * On a FULL match (every line matched), it sets reconciledAt and requests the
 * declarative exported → reconciled transition through OpenRegister's lifecycle
 * engine (it does NOT hand-roll a state machine — ADR-031). On a PARTIAL /
 * unmatched result, the run STAYS exported and the service records a mismatch
 * note (which lines did not match) — it does NOT transition the run.
 *
 * CAMT.053 parsing is imperative file ingestion (ADR-031 explicit exception,
 * justified like the export generator); native XML reader only — no new
 * composer dependency.
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentRun;

use OCA\Shillinq\Kernel\Contracts\PaymentRunReconcileGuardInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRunReconciliationServiceInterface;
use OCA\Shillinq\Service\ServerWriteAuthority;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Matches CAMT.053 booked entries to a PaymentRun and drives exported → reconciled.
 */
class PaymentRunReconciliationService implements PaymentRunReconciliationServiceInterface {

	/**
	 * The OpenRegister register slug shillinq's objects live under.
	 *
	 * @var string
	 */
	private const REGISTER = 'shillinq';

	/**
	 * The PaymentRun schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA = 'PaymentRun';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container App container — lazily
	 *                                      resolves OpenRegister's
	 *                                      ObjectService.
	 * @param Camt053StatementParser $parser The CAMT.053 statement parser.
	 * @param LoggerInterface $logger Fail-soft warning logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly Camt053StatementParser $parser,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Reconcile an exported PaymentRun against a CAMT.053 statement.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 * @param string $contents The raw CAMT.053 statement XML.
	 *
	 * @return array<string, mixed> An envelope: `{ result: 'full'|'partial',
	 *                              matchedCount, totalLines, unmatchedLines,
	 *                              reconciledAt?, lifecycleState, mismatchNote? }`
	 *                              or `{ error: ... }` on rejection.
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.3
	 */
	public function reconcile(array $paymentRun, string $contents): array {
		$state = (string)($paymentRun['lifecycleState'] ?? $paymentRun['status'] ?? '');
		if ($state !== 'exported') {
			$this->logger->warning('PaymentRunReconciliationService: run not exported', ['state' => $state]);
			return ['error' => 'not-exported', 'state' => $state];
		}

		$matched = $this->matchLines(paymentRun: $paymentRun, entries: $this->parser->parse(contents: $contents));

		// PARTIAL / unmatched — stay exported, record a mismatch note.
		if (count(array_filter($matched)) < count($matched) || $matched === []) {
			return $this->recordPartial(paymentRun: $paymentRun, matched: $matched);
		}

		// FULL match — set reconciledAt + request exported → reconciled.
		return $this->recordFull(paymentRun: $paymentRun, matched: $matched);

	}//end reconcile()

	/**
	 * Keep the run exported and record which lines did not match.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 * @param array<int, bool>     $matched    Per-line matched flag.
	 *
	 * @return array<string, mixed> The partial envelope.
	 */
	private function recordPartial(array $paymentRun, array $matched): array {
		$note = $this->mismatchNote(matched: $matched);
		$saved = $this->saveRun(run: array_merge($paymentRun, ['reconciliationNote' => $note]));

		return [
			'result' => 'partial',
			'matchedCount' => count(array_filter($matched)),
			'totalLines' => count($matched),
			'unmatchedLines' => $this->unmatchedLineNumbers(matched: $matched),
			'mismatchNote' => $note,
			'lifecycleState' => (string)($saved['lifecycleState'] ?? 'exported'),
			'paymentRun' => $saved,
		];
	}//end recordPartial()

	/**
	 * The mismatch note: how many lines matched and which did not.
	 *
	 * @param array<int, bool> $matched Per-line matched flag.
	 *
	 * @return string The note.
	 */
	private function mismatchNote(array $matched): string {
		$unmatched = $this->unmatchedLineNumbers(matched: $matched);
		$unmatchedLabel = 'none';
		if ($unmatched !== []) {
			$unmatchedLabel = implode(', ', $unmatched);
		}

		return sprintf(
			'Reconciliation partial: %d of %d lines matched. Unmatched line(s): %s.',
			count(array_filter($matched)),
			count($matched),
			$unmatchedLabel
		);
	}//end mismatchNote()

	/**
	 * The 1-based numbers of the lines that did not match.
	 *
	 * @param array<int, bool> $matched Per-line matched flag.
	 *
	 * @return list<int> The unmatched line numbers.
	 */
	private function unmatchedLineNumbers(array $matched): array {
		$unmatched = [];
		foreach ($matched as $index => $isMatched) {
			if ($isMatched === false) {
				$unmatched[] = ($index + 1);
			}
		}

		return $unmatched;
	}//end unmatchedLineNumbers()

	/**
	 * Save the run as reconciled and answer the full envelope.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 * @param array<int, bool>     $matched    Per-line matched flag (all true).
	 *
	 * @return array<string, mixed> The full envelope.
	 */
	private function recordFull(array $paymentRun, array $matched): array {
		$reconciledAt = gmdate('Y-m-d\TH:i:s\Z');
		$saved = $this->saveReconciled(paymentRun: $paymentRun, reconciledAt: $reconciledAt);

		return [
			'result' => 'full',
			'matchedCount' => count(array_filter($matched)),
			'totalLines' => count($matched),
			'unmatchedLines' => [],
			'reconciledAt' => $reconciledAt,
			'lifecycleState' => (string)($saved['lifecycleState'] ?? 'reconciled'),
			'paymentRun' => $saved,
		];
	}//end recordFull()

	/**
	 * Request exported → reconciled: save reconciledAt and the reconciled
	 * state while holding the reconcile write authority for this run.
	 *
	 * @param array<string, mixed> $paymentRun   The PaymentRun object array.
	 * @param string               $reconciledAt The reconciliation timestamp.
	 *
	 * @return array<string, mixed> The saved run.
	 */
	private function saveReconciled(array $paymentRun, string $reconciledAt): array {
		$update = array_merge(
			$paymentRun,
			[
				'reconciledAt' => $reconciledAt,
				'lifecycleState' => 'reconciled',
				'status' => 'reconciled',
			]
		);

		return (new ServerWriteAuthority())->during(
			purpose: PaymentRunReconcileGuardInterface::PURPOSE,
			objectId: (string)($paymentRun['id'] ?? ($paymentRun['@self']['id'] ?? '')),
			work: fn (): array => $this->saveRun(run: $update)
		);
	}//end saveReconciled()

	/**
	 * Match parsed statement entries to the run's lines: pass 1 by the
	 * primary EndToEndId, pass 2 by (amount, creditorIban) for the lines
	 * still unmatched. Each entry matches at most one line.
	 *
	 * @param array<string, mixed>             $paymentRun The PaymentRun object array.
	 * @param array<int, array<string, mixed>> $entries    The parsed statement entries.
	 *
	 * @return array<int, bool> A per-line matched flag keyed by 0-based line index.
	 */
	private function matchLines(array $paymentRun, array $entries): array {
		$runNumber = (string)($paymentRun['runNumber'] ?? '');
		$lines = $this->lines(paymentRun: $paymentRun);
		$matcher = new StatementEntryMatcher(entries: $entries);

		$matched = [];
		foreach (array_keys($lines) as $index) {
			$matched[$index] = $matcher->claimByEndToEnd(endToEnd: $this->endToEndId(runNumber: $runNumber, index: $index));
		}

		foreach ($lines as $index => $line) {
			if ($matched[$index] === false) {
				$matched[$index] = $matcher->claimByCreditorAmount(line: $line);
			}
		}

		return $matched;
	}//end matchLines()

	/**
	 * The deterministic EndToEndId for a 0-based line index.
	 *
	 * @param string $runNumber The PaymentRun runNumber.
	 * @param int $index The 0-based line index.
	 *
	 * @return string
	 */
	private function endToEndId(string $runNumber, int $index): string {
		$stem = 'PR';
		if ($runNumber !== '') {
			$stem = $runNumber;
		}

		return $stem . '-' . ($index + 1);
	}//end endToEndId()

	/**
	 * The payment lines of the run as a 0-indexed list.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function lines(array $paymentRun): array {
		$lines = ($paymentRun['paymentLines'] ?? []);
		if (is_array($lines) === false) {
			return [];
		}

		return array_values($lines);
	}//end lines()

	/**
	 * Persist the updated PaymentRun through OpenRegister (drives the transition).
	 *
	 * @param array<string, mixed> $run The updated PaymentRun fields.
	 *
	 * @return array<string, mixed> The saved run (or the input on failure).
	 */
	private function saveRun(array $run): array {
		try {
			$objectService = $this->objectService();
			if ($objectService === null) {
				return $run;
			}

			$saved = $objectService
				->setRegister(self::REGISTER)
				->setSchema(self::SCHEMA)
				->saveObject($run);

			return $this->savedRun(saved: $saved, run: $run);
		} catch (\Throwable $e) {
			$this->logger->warning('PaymentRunReconciliationService: failed to save PaymentRun', ['exception' => $e->getMessage()]);
			return $run;
		}//end try

	}//end saveRun()

	/**
	 * The saved run as an array: a serialised entity, a returned array, or —
	 * for anything else — the run as submitted.
	 *
	 * @param mixed                $saved What saveObject() returned.
	 * @param array<string, mixed> $run   The submitted run.
	 *
	 * @return array<string, mixed> The saved run.
	 */
	private function savedRun(mixed $saved, array $run): array {
		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			return (array)$saved->jsonSerialize();
		}

		if (is_array($saved) === true) {
			return $saved;
		}

		return $run;
	}//end savedRun()

	/**
	 * Lazily resolve OpenRegister's ObjectService from the container (null on miss).
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning('PaymentRunReconciliationService: ObjectService unavailable', ['exception' => $e->getMessage()]);
			return null;
		}

	}//end objectService()
}//end class
