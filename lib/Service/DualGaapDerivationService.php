<?php

/**
 * Dual GAAP Derivation Service
 *
 * Derives the other framework's entry when a divergent transaction is posted
 * (REQ-DGAAP-011). A posted GLTransaction is divergent when a
 * StandardSpecificCalculation names its source reference as
 * `contractOrPositionReference`: that calculation is the configured adjustment
 * rule, and its outputs give the other framework's amount (`amount`, or
 * `factor` times the posted amount). The service writes one DualTransaction
 * with both entries and the difference (IFRS minus Dutch GAAP), and adds that
 * difference as an adjustment to the period's ReconciliationBridge. The
 * framework a transaction is posted under is the administration's active
 * FrameworkElection, Dutch GAAP when there is none. A transaction is derived
 * once: a second posting finds its DualTransaction and writes nothing.
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
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives the other framework's entry of a posted divergent transaction.
 *
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
 */
class DualGaapDerivationService {

	/**
	 * Dutch GAAP (Richtlijnen voor de Jaarverslaggeving).
	 */
	public const FRAMEWORK_RJ = 'NL-GAAP-RJ';

	/**
	 * IFRS as adopted in the EU.
	 */
	public const FRAMEWORK_IFRS = 'IFRS-EU';

	/**
	 * The DualTransaction reason code of each StandardSpecificCalculation standard.
	 *
	 * @var array<string, string>
	 */
	private const REASON_BY_STANDARD = [
		'IFRS-16' => 'LEASE_IFRS16',
		'IAS-19' => 'PENSION_IAS19',
		'IFRS-9' => 'ECL_IFRS9',
		'IFRS-15' => 'REVENUE_IFRS15',
		'IAS-36' => 'IMPAIRMENT_IAS36',
		'IAS-23' => 'BORROWING_COST_IAS23',
		'IAS-12' => 'DEFERRED_TAX_IAS12',
		'IFRS-3' => 'BUSINESS_COMBINATION_IFRS3',
	];

	/**
	 * The divergence classifications a rule may name.
	 *
	 * @var array<int, string>
	 */
	private const CLASSIFICATIONS = ['temporary', 'permanent', 'reclassification'];

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
	 * Derive the other framework's entry of one posted transaction.
	 *
	 * @param array<string, mixed> $transaction The GLTransaction, with its `id`.
	 *
	 * @return array{derived: bool, message: string, differenceCents?: int, dualTransaction?: array<string, mixed>, bridge?: array<string, mixed>}
	 *
	 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
	 */
	public function deriveForTransaction(array $transaction): array {
		$transactionId = (string)($transaction['id'] ?? ($transaction['@self']['id'] ?? ''));
		$reference = trim((string)($transaction['sourceReference'] ?? ''));
		if ((string)($transaction['state'] ?? '') !== 'posted') {
			return ['derived' => false, 'message' => 'The transaction is not posted.'];
		}

		if ($transactionId === '' || $reference === '') {
			return ['derived' => false, 'message' => 'The transaction has no source reference, so no adjustment rule applies.'];
		}

		if ($this->rows(schema: 'DualTransaction', filters: ['baseTransactionId' => $transactionId]) !== []) {
			return ['derived' => false, 'message' => 'This transaction is already derived.'];
		}

		$administrationId = (string)($transaction['administrationId'] ?? '');
		$rule = $this->rule(reference: $reference, administrationId: $administrationId);
		if ($rule === null) {
			return ['derived' => false, 'message' => 'No adjustment rule for this transaction: it is not divergent.'];
		}

		$lines = $this->rows(schema: 'GLLine', filters: ['transactionId' => $transactionId]);
		$postedCents = self::debitCents(lines: $lines);
		if ($postedCents === 0) {
			return ['derived' => false, 'message' => 'The transaction has no debit lines to derive.'];
		}

		$otherCents = self::ruleCents(outputs: (array)($rule['outputs'] ?? []), postedCents: $postedCents);
		if ($otherCents === null) {
			return ['derived' => false, 'message' => 'The adjustment rule gives no amount for the other framework.'];
		}

		return $this->record(
			transaction: $transaction,
			rule: $rule,
			lines: $lines,
			amounts: ['posted' => $postedCents, 'other' => $otherCents],
			postedUnder: $this->framework(administrationId: $administrationId)
		);
	}//end deriveForTransaction()

	/**
	 * The other framework's amount a rule gives, in cents, null when it gives none.
	 * An explicit `amount` (or `otherFrameworkAmount`) wins; otherwise `factor`
	 * times the posted amount.
	 *
	 * @param array<string, mixed> $outputs     The calculation's outputs.
	 * @param int                  $postedCents The posted amount.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
	 */
	public static function ruleCents(array $outputs, int $postedCents): ?int {
		foreach (['amount', 'otherFrameworkAmount'] as $key) {
			$amount = self::number(value: ($outputs[$key] ?? null));
			if ($amount !== null) {
				return (int)round(abs($amount) * 100);
			}
		}

		$factor = self::number(value: ($outputs['factor'] ?? null));
		if ($factor !== null) {
			return (int)round(abs($factor) * $postedCents);
		}

		return null;
	}//end ruleCents()

	/**
	 * The posted lines as journal entries scaled to a target amount: the same
	 * accounts and sides, each line in proportion, the rounding cents on the
	 * last line of each side so debits and credits both total the target.
	 *
	 * @param array<int, array<string, mixed>> $lines       The GLLine rows.
	 * @param int                              $postedCents The posted debit total.
	 * @param int                              $targetCents The amount to scale to.
	 *
	 * @return array<int, array{account: string, debit: float, credit: float}>
	 *
	 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
	 */
	public static function scaledEntries(array $lines, int $postedCents, int $targetCents): array {
		$entries = [];
		$last = ['debit' => null, 'credit' => null];
		$totals = ['debit' => 0, 'credit' => 0];
		foreach ($lines as $line) {
			$side = (string)($line['side'] ?? '');
			$cents = (int)round(abs(self::number(value: ($line['amount'] ?? null)) ?? 0.0) * 100);
			if (($side !== 'debit' && $side !== 'credit') || $cents === 0) {
				continue;
			}

			$scaled = (int)round($cents * $targetCents / $postedCents);
			$entries[] = ['account' => (string)($line['accountNumber'] ?? ''), 'side' => $side, 'cents' => $scaled];
			$last[$side] = (count($entries) - 1);
			$totals[$side] += $scaled;
		}

		foreach (['debit', 'credit'] as $side) {
			if ($last[$side] !== null) {
				$entries[$last[$side]]['cents'] += ($targetCents - $totals[$side]);
			}
		}

		$out = [];
		foreach ($entries as $entry) {
			$amount = round($entry['cents'] / 100, 2);
			$debit = 0.0;
			$credit = $amount;
			if ($entry['side'] === 'debit') {
				$debit = $amount;
				$credit = 0.0;
			}

			$out[] = ['account' => $entry['account'], 'debit' => $debit, 'credit' => $credit];
		}

		return $out;
	}//end scaledEntries()

	/**
	 * The bridge totals of a list of adjustments, in euros: temporary and
	 * permanent differences; a reclassification moves no result.
	 *
	 * @param array<int, mixed> $adjustments The bridge's adjustments.
	 *
	 * @return array{temporary: float, permanent: float}
	 *
	 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
	 */
	public static function bridgeTotals(array $adjustments): array {
		$cents = ['temporary' => 0, 'permanent' => 0];
		foreach ($adjustments as $adjustment) {
			if (is_array($adjustment) === false) {
				continue;
			}

			$kind = (string)($adjustment['classification'] ?? '');
			if (array_key_exists($kind, $cents) === true) {
				$cents[$kind] += (int)round((self::number(value: ($adjustment['amount'] ?? null)) ?? 0.0) * 100);
			}
		}

		return ['temporary' => round($cents['temporary'] / 100, 2), 'permanent' => round($cents['permanent'] / 100, 2)];
	}//end bridgeTotals()

	/**
	 * Write the DualTransaction and the bridge adjustment.
	 *
	 * @param array<string, mixed>             $transaction The posted transaction.
	 * @param array<string, mixed>             $rule        The adjustment rule.
	 * @param array<int, array<string, mixed>> $lines       Its lines.
	 * @param array{posted: int, other: int}   $amounts     The posted and the derived amount, in cents.
	 * @param string                           $postedUnder The framework it was posted under.
	 *
	 * @return array{derived: bool, message: string, differenceCents?: int, dualTransaction?: array<string, mixed>, bridge?: array<string, mixed>}
	 */
	private function record(array $transaction, array $rule, array $lines, array $amounts, string $postedUnder): array {
		$transactionId = (string)($transaction['id'] ?? ($transaction['@self']['id'] ?? ''));
		$administrationId = (string)($transaction['administrationId'] ?? '');
		$posted = self::scaledEntries(lines: $lines, postedCents: $amounts['posted'], targetCents: $amounts['posted']);
		$derived = self::scaledEntries(lines: $lines, postedCents: $amounts['posted'], targetCents: $amounts['other']);
		$rjEntries = $posted;
		$ifrsEntries = $derived;
		$differenceCents = ($amounts['other'] - $amounts['posted']);
		if ($postedUnder === self::FRAMEWORK_IFRS) {
			$rjEntries = $derived;
			$ifrsEntries = $posted;
			$differenceCents = ($amounts['posted'] - $amounts['other']);
		}

		$standard = (string)($rule['standardCode'] ?? '');
		$classification = (string)(($rule['outputs'] ?? [])['classification'] ?? '');
		if (in_array($classification, self::CLASSIFICATIONS, true) === false) {
			$classification = 'temporary';
		}

		try {
			$dual = $this->save(
				schema: 'DualTransaction',
				data: [
					'baseTransactionId' => $transactionId,
					'rjJournalEntries' => $rjEntries,
					'ifrsJournalEntries' => $ifrsEntries,
					'divergenceAmount' => round($differenceCents / 100, 2),
					'divergenceReasonCode' => (self::REASON_BY_STANDARD[$standard] ?? 'NONE'),
					'divergenceClassification' => $classification,
					'state' => 'open',
					'administrationId' => $administrationId,
				]
			);
			$bridge = $this->addToBridge(
				transaction: $transaction,
				adjustment: [
					'description' => trim((string)($transaction['description'] ?? '')),
					'amount' => round($differenceCents / 100, 2),
					'standardReference' => $standard,
					'standardCalculationId' => (string)($rule['id'] ?? ($rule['@self']['id'] ?? '')),
					'dualTransactionId' => (string)($dual['id'] ?? ($dual['@self']['id'] ?? '')),
					'baseTransactionId' => $transactionId,
					'classification' => $classification,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('DualGaapDerivationService: derivation not written', ['transaction' => $transactionId, 'exception' => $e->getMessage()]);
			return ['derived' => false, 'message' => 'The derived entry could not be written.'];
		}//end try

		return [
			'derived' => true,
			'message' => 'Derived entry and bridge line written.',
			'differenceCents' => $differenceCents,
			'dualTransaction' => $dual,
			'bridge' => $bridge,
		];
	}//end record()

	/**
	 * Add one adjustment to the period's bridge, creating the bridge when the
	 * period has none, and recompute its totals.
	 *
	 * @param array<string, mixed> $transaction The posted transaction.
	 * @param array<string, mixed> $adjustment  The adjustment line.
	 *
	 * @return array<string, mixed>
	 */
	private function addToBridge(array $transaction, array $adjustment): array {
		$administrationId = (string)($transaction['administrationId'] ?? '');
		$period = substr((string)($transaction['periodId'] ?? ''), 0, 4);
		if (preg_match('/^\d{4}$/', $period) !== 1) {
			$period = substr((string)($transaction['postingDate'] ?? ''), 0, 4);
		}

		$bridge = [
			'period' => $period,
			'fromFramework' => self::FRAMEWORK_RJ,
			'toFramework' => self::FRAMEWORK_IFRS,
			'metric' => 'net_result',
			'adjustments' => [],
			'administrationId' => $administrationId,
		];
		$filters = ['period' => $period, 'fromFramework' => self::FRAMEWORK_RJ, 'toFramework' => self::FRAMEWORK_IFRS, 'metric' => 'net_result'];
		foreach ($this->rows(schema: 'ReconciliationBridge', filters: $filters) as $row) {
			if ((string)($row['period'] ?? '') === $period && (string)($row['administrationId'] ?? '') === $administrationId
				&& (string)($row['metric'] ?? '') === 'net_result'
			) {
				$bridge = $row;
				break;
			}
		}

		$bridge['adjustments'] = array_values(array_merge((array)($bridge['adjustments'] ?? []), [$adjustment]));
		$totals = self::bridgeTotals(adjustments: $bridge['adjustments']);
		$bridge['totalTemporaryDifferences'] = $totals['temporary'];
		$bridge['totalPermanentDifferences'] = $totals['permanent'];

		return $this->save(schema: 'ReconciliationBridge', data: $bridge);
	}//end addToBridge()

	/**
	 * The adjustment rule of a source reference: a StandardSpecificCalculation
	 * naming it, of the same administration where both carry one.
	 *
	 * @param string $reference        The transaction's source reference.
	 * @param string $administrationId The transaction's administration.
	 *
	 * @return array<string, mixed>|null
	 */
	private function rule(string $reference, string $administrationId): ?array {
		foreach ($this->rows(schema: 'StandardSpecificCalculation', filters: ['contractOrPositionReference' => $reference]) as $row) {
			$ruleAdministration = (string)($row['administrationId'] ?? '');
			if ((string)($row['contractOrPositionReference'] ?? '') !== $reference) {
				continue;
			}

			if ($ruleAdministration !== '' && $administrationId !== '' && $ruleAdministration !== $administrationId) {
				continue;
			}

			return $row;
		}

		return null;
	}//end rule()

	/**
	 * The framework an administration posts under: its active election, Dutch GAAP without one.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return string
	 */
	private function framework(string $administrationId): string {
		foreach ($this->rows(schema: 'FrameworkElection', filters: ['state' => 'active']) as $row) {
			if ((string)($row['state'] ?? '') !== 'active' || (string)($row['administrationId'] ?? '') !== $administrationId) {
				continue;
			}

			if ((string)($row['primaryFramework'] ?? '') === self::FRAMEWORK_IFRS) {
				return self::FRAMEWORK_IFRS;
			}

			return self::FRAMEWORK_RJ;
		}

		return self::FRAMEWORK_RJ;
	}//end framework()

	/**
	 * The debit total of a transaction's lines, in cents.
	 *
	 * @param array<int, array<string, mixed>> $lines The GLLine rows.
	 *
	 * @return int
	 */
	private static function debitCents(array $lines): int {
		$cents = 0;
		foreach ($lines as $line) {
			if ((string)($line['side'] ?? '') === 'debit') {
				$cents += (int)round(abs(self::number(value: ($line['amount'] ?? null)) ?? 0.0) * 100);
			}
		}

		return $cents;
	}//end debitCents()

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

		$saved = $this->objectService->setRegister($this->register())->setSchema($schema)->saveObject(object: $data, uuid: $uuid);
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

	/**
	 * A numeric value as float, null when absent or not a number.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return float|null
	 */
	private static function number(mixed $value): ?float {
		if (is_int($value) === true || is_float($value) === true || (is_string($value) === true && is_numeric($value) === true)) {
			return (float)$value;
		}

		return null;
	}//end number()
}//end class
