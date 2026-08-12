<?php

/**
 * OSS EUR 10,000 Threshold Guard
 *
 * ADR-031 exception-path guard for the Union One-Stop-Shop threshold (REQ-OSS-002,
 * REQ-OSS-009). Computes the running calendar-year B2C-to-EU turnover (excluding
 * NL domestic and excluding B2B intra-community supplies), decides whether a new
 * invoice approaches or crosses the EUR 10,000 threshold, and enforces the
 * Article 369a 3-year voluntary-registration lock-in. The cross-period conditional
 * sum is documented declaratively on the OssThresholdCounter schema
 * (x-openregister-aggregations.b2cEuTurnoverByYear); this guard is the engine-side
 * fallback. Money arithmetic is performed in integer cents to avoid float drift,
 * mirroring BalanceGuard / TrialBalanceCalculator.
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use Psr\Log\LoggerInterface;

/**
 * Threshold monitoring and voluntary lock-in enforcement for OSS (REQ-OSS-002, REQ-OSS-009).
 *
 * Pure aggregation + decision logic: every public method takes plain arrays and
 * returns plain scalars/arrays so it is unit-testable in isolation. The caller
 * (invoice-save precondition / OssRegistration lifecycle transition) wires the
 * methods to live OssThresholdCounter + OssRegistration + Invoice data.
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 */
class OssThresholdGuard {
	/**
	 * The EUR 10,000 Union-wide annual threshold, in whole cents (REQ-OSS-002).
	 *
	 * @var int
	 */
	public const THRESHOLD_CENTS = 1000000;

	/**
	 * Warning band before the threshold, in whole cents (within EUR 100, REQ-OSS-002).
	 *
	 * @var int
	 */
	public const WARNING_BAND_CENTS = 10000;

	/**
	 * Construct the guard.
	 *
	 * @param LoggerInterface $logger Nextcloud logger for computation diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Convert a money amount to integer cents (REQ-OSS-002 precision rule).
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents.
	 */
	private function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100);
	}//end toCents()

	/**
	 * Sum B2C-to-EU turnover for an administration in a calendar year (REQ-OSS-002).
	 *
	 * Sums Invoice.netAmount for invoices whose ossContext.ossEligible is true in
	 * the year, excluding cancelled invoices, credit notes, and B2B reverse-charge
	 * supplies (those carry no OSS-eligible ossContext). Returns a float money sum.
	 *
	 * @param array<int,array<string,mixed>> $invoices Pre-fetched Invoice records.
	 * @param string $administrationId Administration scope.
	 * @param int $year Calendar year bound.
	 *
	 * @return float Running B2C-to-EU turnover for the administration in the year.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function currentB2cEuTurnover(array $invoices, string $administrationId, int $year): float {
		$cents = 0;
		foreach ($invoices as $invoice) {
			if ((string)($invoice['administrationId'] ?? '') !== $administrationId) {
				continue;
			}

			$ossContext = ($invoice['ossContext'] ?? null);
			if (is_array($ossContext) === false || ($ossContext['ossEligible'] ?? false) !== true) {
				continue;
			}

			$invoiceDate = (string)($invoice['invoiceDate'] ?? ($invoice['issueDate'] ?? ''));
			if (substr($invoiceDate, 0, 4) !== (string)$year) {
				continue;
			}

			$state = (string)($invoice['state'] ?? ($invoice['status'] ?? ''));
			if ($state === 'cancelled' || $state === 'credited') {
				continue;
			}

			if ((string)($invoice['documentType'] ?? '') === 'credit-note') {
				continue;
			}

			$cents += $this->toCents(amount: ($invoice['netAmount'] ?? ($invoice['amount'] ?? 0)));
		}//end foreach

		return ($cents / 100);
	}//end currentB2cEuTurnover()

	/**
	 * Evaluate the threshold outcome for a prospective invoice (REQ-OSS-002).
	 *
	 * Given the current running turnover, the incremental net amount of the invoice
	 * being saved, and whether an active OSS registration already exists, returns
	 * one of:
	 *  - 'ok'      : well below the threshold, no action.
	 *  - 'warning' : crossing into the EUR-100 warning band; non-blocking prompt.
	 *  - 'block'   : would cross EUR 10,000 with no registration; block with
	 *                `oss.threshold.crossed`.
	 *  - 'registered' : a registration is already active, so the threshold no
	 *                   longer gates the save.
	 *
	 * @param float $currentTurnover Running B2C-to-EU turnover before this invoice.
	 * @param float $incrementAmount Net amount of the invoice being saved.
	 * @param bool $hasRegistration True when an active OssRegistration exists.
	 *
	 * @return string One of: ok, warning, block, registered.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function evaluate(float $currentTurnover, float $incrementAmount, bool $hasRegistration): string {
		if ($hasRegistration === true) {
			return 'registered';
		}

		$projected = ($this->toCents(amount: $currentTurnover) + $this->toCents(amount: $incrementAmount));

		if ($projected >= self::THRESHOLD_CENTS) {
			return 'block';
		}

		if ($projected >= (self::THRESHOLD_CENTS - self::WARNING_BAND_CENTS)) {
			return 'warning';
		}

		return 'ok';
	}//end evaluate()

	/**
	 * Lifecycle precondition: may the seller opt in voluntarily below threshold (REQ-OSS-009)?
	 *
	 * Permits the `optInVoluntary` transition when the registration carries an
	 * OSS-identifier and an effective date — the minimum the Belastingdienst
	 * requires before a voluntary registration is meaningful.
	 *
	 * @param array<string,mixed> $registration OssRegistration object array.
	 *
	 * @return bool True when voluntary opt-in is permitted.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function canEnableVoluntary(array $registration): bool {
		return empty($registration['ossIdentifier']) === false
			&& empty($registration['effectiveDate']) === false;

	}//end canEnableVoluntary()

	/**
	 * Lifecycle precondition: may the seller deregister now (REQ-OSS-009)?
	 *
	 * A voluntary registration binds the seller for the current + following two
	 * calendar years (Article 369a paragraph 3). Deregistration is blocked while
	 * the lock-in is still running. A non-voluntary (threshold-driven) registration
	 * has no lock-in and may always be deregistered.
	 *
	 * @param array<string,mixed> $registration OssRegistration object array.
	 * @param string $onDate The date the deregistration is attempted (YYYY-MM-DD).
	 *
	 * @return bool True when deregistration is permitted on the given date.
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 */
	public function canDeregister(array $registration, string $onDate): bool {
		$voluntary = (($registration['voluntaryBelowThreshold'] ?? false) === true
			|| (string)($registration['registrationStatus'] ?? '') === 'voluntaryBelowThreshold');
		if ($voluntary === false) {
			return true;
		}

		$lockEnd = (string)($registration['lockInPeriodEndDate'] ?? '');
		if ($lockEnd === '') {
			// Voluntary but no lock-in end recorded — fail safe: block (REQ-OSS-009).
			$this->logger->warning('OssThresholdGuard: voluntary registration without lockInPeriodEndDate; blocking deregister.');
			return false;
		}

		return $onDate > $lockEnd;
	}//end canDeregister()
}//end class
