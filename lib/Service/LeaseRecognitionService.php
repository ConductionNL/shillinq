<?php

/**
 * Lease Recognition Service
 *
 * Computes the opening Right-of-Use asset and lease liability at lease
 * commencement (REQ-LA-001, REQ-LA-005, REQ-LA-006) and shapes the balanced
 * journal-entry lines that bookkeeping-general-ledger posts. Per design.md D3 the
 * RoU asset is recognised as a fixed-asset (is-rou-asset=true) so the standard
 * depreciation engine handles it; this service produces the recognition payload
 * (opening balances + JE lines + fixed-asset record) and never writes a parallel
 * GL table — posting is delegated to the generic GL surface via the real
 * OpenRegister ObjectService API (ADR-022).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Shapes the recognition payload for an IFRS 16 lease at commencement.
 *
 * Pure-compute over a single lease array: produces the opening RoU asset and
 * liability, two balanced journal-entry lines (RoU debit / liability credit, plus
 * a restoration-obligation split where present), and the fixed-asset record that
 * carries the RoU asset into the depreciation engine. No persistence here —
 * LeasePaymentScheduleService / the lifecycle listener wires this to the GL.
 *
 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
 */
class LeaseRecognitionService {
	/**
	 * Map an asset class onto its RoU GL account subtype (REQ-LA-001).
	 *
	 * @var array<string,string>
	 */
	private const ROU_SUBTYPE = [
		'vehicle' => 'rou-vehicles',
		'real-estate' => 'rou-real-estate',
		'IT-hardware' => 'rou-IT',
		'machinery' => 'rou-other',
		'other' => 'rou-other',
	];

	/**
	 * Construct the service.
	 *
	 * @param LeaseAmortizationCalculator $calculator Pure-logic IFRS 16 arithmetic helper.
	 */
	public function __construct(
		private readonly LeaseAmortizationCalculator $calculator,
	) {
	}//end __construct()

	/**
	 * Build the recognition payload for a lease at commencement (REQ-LA-001).
	 *
	 * Returns the opening balances, the balanced journal-entry lines, and the
	 * fixed-asset record. The journal-entry lines always balance (sum of debits =
	 * sum of credits) so the downstream JournalEntryGuard::canPost accepts them.
	 *
	 * @param array<string,mixed> $lease A LeaseContract array.
	 *
	 * @return array{
	 *   liability: float,
	 *   rouAsset: float,
	 *   restorationPv: float,
	 *   journalLines: array<int,array<string,mixed>>,
	 *   fixedAsset: array<string,mixed>
	 * }
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function recognise(array $lease): array {
		$opening = $this->calculator->openingBalances(lease: $lease);
		$assetClass = (string)($lease['assetClass'] ?? 'other');
		$rouSubtype = (self::ROU_SUBTYPE[$assetClass] ?? 'rou-other');

		// RoU asset attributable to the discounted payment stream (and adjustments)
		// versus the restoration obligation, which credits a separate liability.
		$restorationPv = $opening['restorationPv'];
		$rouFromLiability = $this->calculator->fromCents(
			cents: ($this->calculator->toCents(amount: $opening['rouAsset']) - $this->calculator->toCents(amount: $restorationPv))
		);

		$journalLines = [
			[
				'side' => 'debit',
				'amount' => $opening['rouAsset'],
				'leaseAccountSubtype' => $rouSubtype,
				'narrative' => 'IFRS 16 RoU asset recognition at commencement',
			],
			[
				'side' => 'credit',
				'amount' => $rouFromLiability,
				'leaseAccountSubtype' => 'lease-liability-noncurrent',
				'narrative' => 'IFRS 16 lease liability recognition at commencement',
			],
		];

		if ($this->calculator->toCents(amount: $restorationPv) > 0) {
			$journalLines[] = [
				'side' => 'credit',
				'amount' => $restorationPv,
				'leaseAccountSubtype' => 'lease-restoration-obligation',
				'narrative' => 'IFRS 16 restoration obligation recognition',
			];
		}

		$periodsPerYear = $this->calculator->periodsPerYear(
			paymentFrequency: (string)($lease['paymentFrequency'] ?? 'monthly')
		);
		$usefulLifeMonths = $this->calculator->scheduleLength(lease: $lease) * (12 / $periodsPerYear);

		$fixedAsset = [
			'isRouAsset' => true,
			'sourceLease' => (string)($lease['@self']['slug'] ?? ($lease['leaseNumber'] ?? '')),
			'assetType' => 'RoU-' . $assetClass,
			'depreciationMethod' => 'straight-line',
			'usefulLifeMonths' => $usefulLifeMonths,
			'purchaseCost' => $opening['rouAsset'],
			'administrationId' => (string)($lease['administrationId'] ?? ''),
		];

		return [
			'liability' => $opening['liability'],
			'rouAsset' => $opening['rouAsset'],
			'restorationPv' => $restorationPv,
			'journalLines' => $journalLines,
			'fixedAsset' => $fixedAsset,
		];

	}//end recognise()

	/**
	 * Returns true iff the recognition journal lines balance (debit = credit).
	 *
	 * Mirrors JournalEntryGuard::canPost in integer cents so the recognition
	 * payload is provably postable before it reaches the GL (REQ-LA-001).
	 *
	 * @param array<int,array<string,mixed>> $journalLines The recognition lines.
	 *
	 * @return bool True when debits equal credits.
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function linesBalance(array $journalLines): bool {
		$debit = 0;
		$credit = 0;
		foreach ($journalLines as $line) {
			$cents = $this->calculator->toCents(amount: ($line['amount'] ?? 0));
			if (($line['side'] ?? '') === 'debit') {
				$debit += $cents;
				continue;
			}

			$credit += $cents;
		}

		return $debit === $credit;
	}//end linesBalance()
}//end class
