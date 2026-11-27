<?php

/**
 * Dual GAAP Derivation Service
 *
 * Derives the other framework's entry of a posted transaction that is marked
 * as divergent, and writes the reconciling difference on the period's bridge
 * (REQ-DGAAP-011).
 *
 * A GLTransaction is divergent when it carries a `divergenceReasonCode` other
 * than NONE and an `adjustmentCalculationId`. That StandardSpecificCalculation
 * is the configured adjustment rule: its `outputs.ifrsAmount` is the amount
 * under IFRS of an entry booked under Dutch GAAP, its `outputs.rjAmount` the
 * amount under Dutch GAAP of an entry booked under IFRS. The derived entry is
 * the posted lines scaled to that amount, still balanced; both entries are
 * stored on one DualTransaction. The difference (IFRS minus Dutch GAAP) is
 * appended to the period's net result ReconciliationBridge from NL-GAAP-RJ to
 * IFRS-EU, created when the period has none, and the bridge's closing IFRS
 * balance is recomputed. A transaction is derived once: a second post finds
 * its DualTransaction and writes nothing.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-reporting-ifrs-calculations/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\DualGaapDerivationServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives the second framework's entry and the bridge line on post.
 *
 * @spec openspec/changes/archive/2026-11-24-reporting-ifrs-calculations/tasks.md#task-1.1
 */
class DualGaapDerivationService implements DualGaapDerivationServiceInterface {

	/**
	 * Dutch GAAP framework identifier.
	 */
	public const FRAMEWORK_RJ = 'NL-GAAP-RJ';

	/**
	 * IFRS framework identifier.
	 */
	public const FRAMEWORK_IFRS = 'IFRS-EU';

	/**
	 * @param IAppConfig             $appConfig     The register slug.
	 * @param LoggerInterface        $logger        Diagnostics; never logs amounts.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Derive the other framework's entry of a posted transaction and write the bridge line.
	 *
	 * @param array<string, mixed> $transaction The posted GLTransaction, with its `id`.
	 *
	 * @return array{derived: bool, message: string, differenceCents?: int, dualTransaction?: array<string, mixed>, bridge?: array<string, mixed>}
	 *
	 * @spec openspec/changes/archive/2026-11-24-reporting-ifrs-calculations/tasks.md#task-1.1
	 */
	public function deriveOnPost(array $transaction): array {
		$transactionId = (string)($transaction['id'] ?? ($transaction['@self']['id'] ?? ''));
		$reason = (string)($transaction['divergenceReasonCode'] ?? '');
		$calculationId = (string)($transaction['adjustmentCalculationId'] ?? '');
		if ($transactionId === '' || $reason === '' || $reason === 'NONE' || $calculationId === '') {
			return ['derived' => false, 'message' => 'The transaction is not marked as divergent.'];
		}

		if ($this->rows(schema: 'DualTransaction', filters: ['baseTransactionId' => $transactionId]) !== []) {
			return ['derived' => false, 'message' => 'This transaction is already derived.'];
		}

		$calculation = $this->find(schema: 'StandardSpecificCalculation', id: $calculationId);
		if ($calculation === null) {
			return ['derived' => false, 'message' => 'The adjustment calculation does not exist.'];
		}

		$framework = self::frameworkOf(transaction: $transaction);
		$otherCents = self::otherAmountCents(calculation: $calculation, postedFramework: $framework);
		if ($otherCents === null) {
			return ['derived' => false, 'message' => 'The adjustment calculation gives no amount under the other framework.'];
		}

		$lines = $this->linesOf(transaction: $transaction, transactionId: $transactionId);
		$postedCents = self::debitCents(lines: $lines);
		if ($postedCents === 0) {
			return ['derived' => false, 'message' => 'The transaction has no amount to derive from.'];
		}

		return $this->write(
			transaction: $transaction,
			calculation: $calculation,
			lines: $lines,
			amounts: ['posted' => $postedCents, 'other' => $otherCents, 'framework' => $framework]
		);
	}//end deriveOnPost()

	/**
	 * The framework a transaction is booked under; absent means Dutch GAAP.
	 *
	 * @param array<string, mixed> $transaction The transaction.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-24-reporting-ifrs-calculations/tasks.md#task-1.1
	 */
	public static function frameworkOf(array $transaction): string {
		if ((string)($transaction['reportingFramework'] ?? '') === self::FRAMEWORK_IFRS) {
			return self::FRAMEWORK_IFRS;
		}

		return self::FRAMEWORK_RJ;
	}//end frameworkOf()

	/**
	 * The amount under the other framework from the calculation outputs, in cents.
	 *
	 * @param array<string, mixed> $calculation     The StandardSpecificCalculation.
	 * @param string               $postedFramework The framework the entry is booked under.
	 *
	 * @return int|null Null when the outputs carry no such amount.
	 *
	 * @spec openspec/changes/archive/2026-11-24-reporting-ifrs-calculations/tasks.md#task-1.1
	 */
	public static function otherAmountCents(array $calculation, string $postedFramework): ?int {
		$outputs = (array)($calculation['outputs'] ?? []);
		$key = 'ifrsAmount';
		if ($postedFramework === self::FRAMEWORK_IFRS) {
			$key = 'rjAmount';
		}

		$value = $outputs[$key] ?? null;
		if (is_int($value) === false && is_float($value) === false && (is_string($value) === false || is_numeric($value) === false)) {
			return null;
		}

		return (int)round(abs((float)$value) * 100);
	}//end otherAmountCents()

	/**
	 * The debit total of journal lines, in cents.
	 *
	 * @param array<int, array<string, mixed>> $lines The lines.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/archive/2026-11-24-reporting-ifrs-calculations/tasks.md#task-1.1
	 */
	public static function debitCents(array $lines): int {
		$total = 0;
		foreach ($lines as $line) {
			if ((string)($line['side'] ?? '') === 'debit') {
				$total += (int)round(abs((float)($line['amount'] ?? 0)) * 100);
			}
		}

		return $total;
	}//end debitCents()

	/**
	 * The lines scaled from one total to another, still balanced.
	 *
	 * Each line is scaled and rounded to the cent; the rounding rest of a side
	 * goes on its largest line, so each side adds up to the target exactly.
	 *
	 * @param array<int, array<string, mixed>> $lines       The posted lines.
	 * @param int                              $fromCents   Their debit total.
	 * @param int                              $targetCents The total to scale to.
	 *
	 * @return array<int, array{accountNumber: string, side: string, amount: float}>
	 *
	 * @spec openspec/changes/archive/2026-11-24-reporting-ifrs-calculations/tasks.md#task-1.1
	 */
	public static function scaleLines(array $lines, int $fromCents, int $targetCents): array {
		$scaled = [];
		$sums = ['debit' => 0, 'credit' => 0];
		$largest = [];
		foreach (array_values($lines) as $index => $line) {
			$side = (string)($line['side'] ?? '');
			if ($side !== 'debit' && $side !== 'credit') {
				continue;
			}

			$lineCents = (int)round(abs((float)($line['amount'] ?? 0)) * 100);
			$cents = (int)round($lineCents * $targetCents / $fromCents);
			$scaled[$index] = ['accountNumber' => (string)($line['accountNumber'] ?? ''), 'side' => $side, 'cents' => $cents];
			$sums[$side] += $cents;
			if (isset($largest[$side]) === false || $cents > $scaled[$largest[$side]]['cents']) {
				$largest[$side] = $index;
			}
		}

		foreach ($largest as $side => $index) {
			$scaled[$index]['cents'] += ($targetCents - $sums[$side]);
		}

		return array_values(
			array_map(
				static fn (array $line): array => [
					'accountNumber' => $line['accountNumber'],
					'side' => $line['side'],
					'amount' => round($line['cents'] / 100, 2),
				],
				$scaled
			)
		);
	}//end scaleLines()

	/**
	 * A bridge with one more adjustment and its closing IFRS balance recomputed.
	 *
	 * @param array<string, mixed> $bridge     The ReconciliationBridge.
	 * @param array<string, mixed> $adjustment The adjustment, with its `amount`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-24-reporting-ifrs-calculations/tasks.md#task-1.1
	 */
	public static function withAdjustment(array $bridge, array $adjustment): array {
		$adjustments = array_values((array)($bridge['adjustments'] ?? []));
		$adjustments[] = $adjustment;
		$cents = (int)round((float)($bridge['openingBalanceRj'] ?? 0) * 100);
		foreach ($adjustments as $row) {
			$cents += (int)round((float)((array)$row)['amount'] * 100);
		}

		$bridge['adjustments'] = $adjustments;
		$bridge['closingBalanceIfrs'] = round($cents / 100, 2);

		return $bridge;
	}//end withAdjustment()

	/**
	 * The Dutch GAAP and IFRS lines and totals: the posted lines under their framework, the scaled ones under the other.
	 *
	 * @param array<int, array<string, mixed>>              $lines   The posted lines.
	 * @param array{posted: int, other: int, framework: string} $amounts Debit totals in cents and the posted framework.
	 *
	 * @return array{rjLines: array<int, array<string, mixed>>, ifrsLines: array<int, array<string, mixed>>, rjCents: int, ifrsCents: int}
	 *
	 * @spec openspec/changes/archive/2026-11-24-reporting-ifrs-calculations/tasks.md#task-1.1
	 */
	public static function entriesByFramework(array $lines, array $amounts): array {
		$posted = array_values(
			array_map(
				static fn (array $line): array => [
					'accountNumber' => (string)($line['accountNumber'] ?? ''),
					'side' => (string)($line['side'] ?? ''),
					'amount' => round(abs((float)($line['amount'] ?? 0)), 2),
				],
				$lines
			)
		);
		$derived = self::scaleLines(lines: $lines, fromCents: $amounts['posted'], targetCents: $amounts['other']);
		if ($amounts['framework'] === self::FRAMEWORK_IFRS) {
			return ['rjLines' => $derived, 'ifrsLines' => $posted, 'rjCents' => $amounts['other'], 'ifrsCents' => $amounts['posted']];
		}

		return ['rjLines' => $posted, 'ifrsLines' => $derived, 'rjCents' => $amounts['posted'], 'ifrsCents' => $amounts['other']];
	}//end entriesByFramework()

	/**
	 * Store the DualTransaction and the bridge line.
	 *
	 * @param array<string, mixed>                                    $transaction The posted transaction.
	 * @param array<string, mixed>                                    $calculation The adjustment calculation.
	 * @param array<int, array<string, mixed>>                        $lines       The posted lines.
	 * @param array{posted: int, other: int, framework: string}       $amounts     Debit totals in cents and the posted framework.
	 *
	 * @return array{derived: bool, message: string, differenceCents?: int, dualTransaction?: array<string, mixed>, bridge?: array<string, mixed>}
	 */
	private function write(array $transaction, array $calculation, array $lines, array $amounts): array {
		$transactionId = (string)($transaction['id'] ?? ($transaction['@self']['id'] ?? ''));
		$administrationId = (string)($transaction['administrationId'] ?? '');
		$entries = self::entriesByFramework(lines: $lines, amounts: $amounts);
		$rjLines = $entries['rjLines'];
		$ifrsLines = $entries['ifrsLines'];
		$rjCents = $entries['rjCents'];
		$ifrsCents = $entries['ifrsCents'];
		$differenceCents = ($ifrsCents - $rjCents);
		try {
			$dual = $this->save(
				schema: 'DualTransaction',
				data: [
					'baseTransactionId' => $transactionId,
					'rjJournalEntries' => $rjLines,
					'ifrsJournalEntries' => $ifrsLines,
					'divergenceAmount' => round(abs($differenceCents) / 100, 2),
					'divergenceReasonCode' => (string)$transaction['divergenceReasonCode'],
					'state' => 'open',
					'administrationId' => $administrationId,
				]
			);
			$bridge = $this->save(
				schema: 'ReconciliationBridge',
				data: self::withAdjustment(
					bridge: $this->bridgeFor(administrationId: $administrationId, period: (string)($transaction['periodId'] ?? '')),
					adjustment: [
						'description' => (string)($transaction['description'] ?? $transactionId),
						'amount' => round($differenceCents / 100, 2),
						'account' => (string)($ifrsLines[0]['accountNumber'] ?? ''),
						'standardReference' => (string)($calculation['standardCode'] ?? ''),
						'standardCalculationId' => (string)($calculation['id'] ?? ($calculation['@self']['id'] ?? '')),
						'sourceTransactionId' => $transactionId,
						'dualTransactionId' => (string)($dual['id'] ?? ($dual['@self']['id'] ?? '')),
					]
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning('DualGaapDerivationService: derivation failed', ['transaction' => $transactionId, 'exception' => $e->getMessage()]);
			return ['derived' => false, 'message' => 'The other framework\'s entry could not be stored.'];
		}//end try

		return [
			'derived' => true,
			'message' => 'The other framework\'s entry and the bridge line are stored.',
			'differenceCents' => $differenceCents,
			'dualTransaction' => $dual,
			'bridge' => $bridge,
		];
	}//end write()

	/**
	 * The period's net result bridge from Dutch GAAP to IFRS, a new one when absent.
	 *
	 * @param string $administrationId The administration.
	 * @param string $period           The period id.
	 *
	 * @return array<string, mixed>
	 */
	private function bridgeFor(string $administrationId, string $period): array {
		$key = [
			'administrationId' => $administrationId,
			'period' => $period,
			'metric' => 'net_result',
			'fromFramework' => self::FRAMEWORK_RJ,
			'toFramework' => self::FRAMEWORK_IFRS,
		];
		foreach ($this->rows(schema: 'ReconciliationBridge', filters: $key) as $row) {
			$matches = true;
			foreach ($key as $field => $value) {
				if (is_scalar($row[$field] ?? null) === false || (string)$row[$field] !== $value) {
					$matches = false;
				}
			}

			if ($matches === true) {
				return $row;
			}
		}

		return array_merge($key, ['openingBalanceRj' => 0, 'adjustments' => [], 'closingBalanceIfrs' => 0]);
	}//end bridgeFor()

	/**
	 * The lines of a transaction: its GLLine rows, else its embedded `lines`.
	 *
	 * @param array<string, mixed> $transaction   The transaction.
	 * @param string               $transactionId Its id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function linesOf(array $transaction, string $transactionId): array {
		$rows = $this->rows(schema: 'GLLine', filters: ['transactionId' => $transactionId]);
		if ($rows !== []) {
			return $rows;
		}

		return array_values(array_filter((array)($transaction['lines'] ?? []), 'is_array'));
	}//end linesOf()

	/**
	 * One object by id, null when absent.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find(string $schema, string $id): ?array {
		try {
			return ObjectIdentifier::findOneStrict(scoped: $this->objectService->setRegister($this->register())->setSchema($schema), id: $id);
		} catch (Throwable $e) {
			return null;
		}
	}//end find()

	/**
	 * Rows of a schema matching filters, as arrays.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$found = $this->objectService->setRegister($this->register())->setSchema($schema)->findAll(['filters' => $filters]);
		$rows = [];
		foreach ((array)($found['results'] ?? $found) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * Save one object and return it as an array.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $data   The object.
	 *
	 * @return array<string, mixed>
	 */
	private function save(string $schema, array $data): array {
		$id = (string)($data['id'] ?? ($data['@self']['id'] ?? ''));
		$uuid = null;
		if ($id !== '') {
			$uuid = $id;
		}

		$saved = $this->objectService->setRegister($this->register())->setSchema($schema)->saveObject(
			object: $data,
			options: new ObjectSaveOptions(uuid: $uuid)
		);
		$out = $saved->jsonSerialize();
		if (is_array($out) === false) {
			return [];
		}

		return $out;
	}//end save()

	/**
	 * The register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
