<?php

/**
 * Payment-run reconciliation service (CAMT.053 match)
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentRun;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Matches CAMT.053 booked entries to a PaymentRun and drives exported → reconciled.
 */
class PaymentRunReconciliationService {

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
	 * Cent tolerance for amount-fallback equality (rounding slack).
	 *
	 * @var float
	 */
	private const AMOUNT_EPSILON = 0.005;

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
	 */
	public function reconcile(array $paymentRun, string $contents): array {
		$state = (string)($paymentRun['lifecycleState'] ?? $paymentRun['status'] ?? '');
		if ($state !== 'exported') {
			$this->logger->warning('PaymentRunReconciliationService: run not exported', ['state' => $state]);
			return ['error' => 'not-exported', 'state' => $state];
		}

		$entries = $this->parser->parse(contents: $contents);

		$runNumber = (string)($paymentRun['runNumber'] ?? '');
		$lines = $this->lines(paymentRun: $paymentRun);
		$matched = $this->match(runNumber: $runNumber, lines: $lines, entries: $entries);

		$totalLines = count($lines);
		$matchedCount = count(array_filter($matched));
		$unmatched = [];
		foreach ($matched as $index => $isMatched) {
			if ($isMatched === false) {
				// Report the 1-based line index.
				$unmatched[] = ($index + 1);
			}
		}

		// PARTIAL / unmatched — stay exported, record a mismatch note.
		if ($matchedCount < $totalLines || $totalLines === 0) {
			$unmatchedLabel = 'none';
			if ($unmatched !== []) {
				$unmatchedLabel = implode(', ', $unmatched);
			}

			$note = sprintf(
				'Reconciliation partial: %d of %d lines matched. Unmatched line(s): %s.',
				$matchedCount,
				$totalLines,
				$unmatchedLabel
			);

			$update = array_merge($paymentRun, ['reconciliationNote' => $note]);
			$saved = $this->saveRun(run: $update);

			return [
				'result' => 'partial',
				'matchedCount' => $matchedCount,
				'totalLines' => $totalLines,
				'unmatchedLines' => $unmatched,
				'mismatchNote' => $note,
				'lifecycleState' => (string)($saved['lifecycleState'] ?? 'exported'),
				'paymentRun' => $saved,
			];
		}//end if

		// FULL match — set reconciledAt + request exported → reconciled.
		$reconciledAt = gmdate('Y-m-d\TH:i:s\Z');

		$update = array_merge(
			$paymentRun,
			[
				'reconciledAt' => $reconciledAt,
				'lifecycleState' => 'reconciled',
				'status' => 'reconciled',
			]
		);

		$saved = $this->saveRun(run: $update);

		return [
			'result' => 'full',
			'matchedCount' => $matchedCount,
			'totalLines' => $totalLines,
			'unmatchedLines' => [],
			'reconciledAt' => $reconciledAt,
			'lifecycleState' => (string)($saved['lifecycleState'] ?? 'reconciled'),
			'paymentRun' => $saved,
		];

	}//end reconcile()

	/**
	 * Match parsed statement entries to the run's lines.
	 *
	 * @param string $runNumber The PaymentRun runNumber (EndToEndId stem).
	 * @param array<int, array<string, mixed>> $lines The run payment lines (0-indexed).
	 * @param array<int, array<string, mixed>> $entries The parsed statement entries.
	 *
	 * @return array<int, bool> A per-line matched flag keyed by 0-based line index.
	 */
	private function match(string $runNumber, array $lines, array $entries): array {
		$matched = [];
		foreach (array_keys($lines) as $index) {
			$matched[$index] = false;
		}

		$usedEntries = [];

		// Pass 1: primary EndToEndId match.
		foreach ($lines as $index => $line) {
			$endToEnd = $this->endToEndId(runNumber: $runNumber, index: $index);
			foreach ($entries as $entryIndex => $entry) {
				if (isset($usedEntries[$entryIndex]) === true) {
					continue;
				}

				if ($entry['endToEndId'] !== '' && $entry['endToEndId'] === $endToEnd) {
					$matched[$index] = true;
					$usedEntries[$entryIndex] = true;
					break;
				}
			}
		}

		// Pass 2: (amount, creditorIban) fallback against unmatched lines.
		foreach ($lines as $index => $line) {
			if ($matched[$index] === true) {
				continue;
			}

			$lineAmount = round((float)($line['amount'] ?? 0), 2);
			$lineIban = strtoupper(trim((string)($line['creditorIban'] ?? '')));

			foreach ($entries as $entryIndex => $entry) {
				if (isset($usedEntries[$entryIndex]) === true) {
					continue;
				}

				$entryIban = strtoupper(trim($entry['creditorIban']));
				if ($lineIban !== '' && $entryIban === $lineIban
					&& abs($entry['amount'] - $lineAmount) <= self::AMOUNT_EPSILON
				) {
					$matched[$index] = true;
					$usedEntries[$entryIndex] = true;
					break;
				}
			}
		}//end foreach

		return $matched;
	}//end match()

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

			if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
				return (array)$saved->jsonSerialize();
			}

			if (is_array($saved) === true) {
				return $saved;
			}

			return $run;
		} catch (\Throwable $e) {
			$this->logger->warning('PaymentRunReconciliationService: failed to save PaymentRun', ['exception' => $e->getMessage()]);
			return $run;
		}//end try

	}//end saveRun()

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
