<?php

/**
 * KOR Threshold Calculator
 *
 * Pure-logic helper for the Kleine Ondernemersregeling (KOR) threshold-bewaking
 * (REQ-KOR-002, REQ-KOR-003, REQ-KOR-004, REQ-KOR-011). Holds the side-effect-free
 * fiscal arithmetic that KorMonitorService applies after fetching AR-invoice data
 * via the OpenRegister ObjectService: running KOR-eligible revenue, threshold-benutting,
 * the linear month-average end-of-year prognose, the 80/90/100 % alert-schijf,
 * the suppletie-amount on mid-year overschrijding (amount * 0.21 / 1.21 over the
 * KOR-facturen between effectiveDate and revocationDate), and the herzieningsregels
 * proportional voorbelasting recovery. All money arithmetic is performed in integer
 * cents to avoid IEEE-754 drift, mirroring TrialBalanceCalculator.
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
 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free KOR fiscal arithmetic helper.
 *
 * No OpenRegister dependency: every method takes plain arrays/scalars and returns
 * plain arrays/scalars so the logic is unit-testable in isolation. KorMonitorService
 * wires this helper to live AR-invoice + KORRegistration data.
 *
 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * Pre-existing debt (issue #506): early-return refactor and variable
 * renames deferred pending a dedicated pass.
 */
class KorThresholdCalculator {
	/**
	 * The standard NL VAT rate used for suppletie / herziening (21%).
	 *
	 * @var float
	 */
	public const STANDARD_VAT_RATE = 0.21;

	/**
	 * Convert a money amount to integer cents.
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100);
	}//end toCents()

	/**
	 * Convert integer cents back to a float money amount.
	 *
	 * @param int $cents Amount in whole cents.
	 *
	 * @return float Money amount.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function fromCents(int $cents): float {
		return ($cents / 100);
	}//end fromCents()

	/**
	 * Sum the KOR-eligible revenue of a set of AR invoices for a calendar year (REQ-KOR-002).
	 *
	 * Only invoices with vrijstellingsGrondslag == 'KOR_ART25_OB', a leveringsDatum
	 * in the target year, and a non-draft status count. Excluded grounds (vrijgestelde
	 * prestaties, intracommunautair, onroerend goed) are skipped because they never
	 * carry KOR_ART25_OB. Arithmetic in cents.
	 *
	 * @param array<int,array<string,mixed>> $invoices AR-invoice records.
	 * @param int $year Calendar year to bound the sum.
	 *
	 * @return int Running KOR revenue in cents.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function runningOmzetCents(array $invoices, int $year): int {
		$total = 0;
		foreach ($invoices as $invoice) {
			if ($this->isKorEligible(invoice: $invoice, year: $year) === false) {
				continue;
			}

			$total += $this->toCents(amount: ($invoice['amount'] ?? ($invoice['netAmount'] ?? 0)));
		}

		return $total;
	}//end runningOmzetCents()

	/**
	 * Decide whether an AR invoice counts toward the KOR threshold (REQ-KOR-002).
	 *
	 * @param array<string,mixed> $invoice The AR-invoice record.
	 * @param int $year Calendar year the leveringsDatum must fall in.
	 *
	 * @return bool True when the invoice is KOR-eligible revenue for the year.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function isKorEligible(array $invoice, int $year): bool {
		if ((string)($invoice['vrijstellingsGrondslag'] ?? '') !== 'KOR_ART25_OB') {
			return false;
		}

		$status = (string)($invoice['status'] ?? ($invoice['state'] ?? ''));
		if ($status === 'draft' || $status === 'cancelled' || $status === 'credited') {
			return false;
		}

		$leveringsDatum = (string)($invoice['leveringsDatum'] ?? ($invoice['invoiceDate'] ?? ''));
		return (substr($leveringsDatum, 0, 4) === (string)$year);
	}//end isKorEligible()

	/**
	 * Compute threshold-benutting as a fraction (REQ-KOR-002).
	 *
	 * @param int $revenueCents Running KOR revenue in cents.
	 * @param int $thresholdCents Threshold in cents (EUR 20.000 => 2_000_000).
	 *
	 * @return float Benutting fraction (0..1+); zero when the threshold is zero.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function benutting(int $revenueCents, int $thresholdCents): float {
		if ($thresholdCents <= 0) {
			return 0.0;
		}

		return ($revenueCents / $thresholdCents);
	}//end benutting()

	/**
	 * Project end-of-year revenue from the year-to-date monthly average (REQ-KOR-002).
	 *
	 * Prognose = lopende + (maandgemiddelde * resterende maanden), where the average
	 * is over the elapsed months (1..currentMonth). Returns cents.
	 *
	 * @param int $lopendeCents Running revenue in cents.
	 * @param int $currentMonth Current calendar month (1..12).
	 *
	 * @return int Projected end-of-year revenue in cents.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function prognoseEndOfYearCents(int $lopendeCents, int $currentMonth): int {
		$month = max(1, min(12, $currentMonth));
		$avg = intdiv($lopendeCents, $month);
		$remaining = (12 - $month);
		return ($lopendeCents + ($avg * $remaining));
	}//end prognoseEndOfYearCents()

	/**
	 * Resolve the prognose-status from the projected benutting (REQ-KOR-002).
	 *
	 * @param int $prognoseCents Projected end-of-year revenue in cents.
	 * @param int $thresholdCents Threshold in cents.
	 *
	 * @return string ONDER_DREMPEL | WAARSCHUWING | OVERSCHRIJDING_VERWACHT.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function prognoseStatus(int $prognoseCents, int $thresholdCents): string {
		$b = $this->benutting(revenueCents: $prognoseCents, thresholdCents: $thresholdCents);
		if ($b >= 1.0) {
			return 'OVERRUN_EXPECTED';
		}

		if ($b >= 0.8) {
			return 'WARNING';
		}

		return 'UNDER_THRESHOLD';
	}//end prognoseStatus()

	/**
	 * Determine which alert-schijf a benutting newly crosses (REQ-KOR-003).
	 *
	 * Returns the highest schijf that is reached at the new benutting but was NOT
	 * yet reached at the previous benutting, so each schijf fires exactly once as
	 * revenue climbs. Returns null when no new schijf is crossed.
	 *
	 * @param float $previousUtilisation Benutting before the posting.
	 * @param float $newUtilisation Benutting after the posting.
	 *
	 * @return array{trigger:string,severity:string}|null The crossed schijf or null.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function crossedSchijf(float $previousUtilisation, float $newUtilisation): ?array {
		$schijven = [
			['threshold' => 1.0, 'trigger' => 'THRESHOLD_100_PCT', 'severity' => 'OVERRUN'],
			['threshold' => 0.9, 'trigger' => 'THRESHOLD_90_PCT', 'severity' => 'CRITICAL'],
			['threshold' => 0.8, 'trigger' => 'THRESHOLD_80_PCT', 'severity' => 'EARLY'],
		];

		foreach ($schijven as $schijf) {
			if ($newUtilisation >= $schijf['threshold'] && $previousUtilisation < $schijf['threshold']) {
				return ['trigger' => $schijf['trigger'], 'severity' => $schijf['severity']];
			}
		}

		return null;
	}//end crossedSchijf()

	/**
	 * Compute the suppletie-amount on mid-year overschrijding (REQ-KOR-004).
	 *
	 * For every KOR-factuur with a leveringsDatum on/after effectiveDate and strictly
	 * before revocationDate, the VAT that would have been due under the regular regime
	 * is amount * 0.21 / 1.21 (the VAT embedded in the gross KOR amount). The suppletie
	 * is the sum of those amounts. Arithmetic in cents.
	 *
	 * @param array<int,array<string,mixed>> $invoices KOR AR-invoice records.
	 * @param string $effectiveDate KOR start date (YYYY-MM-DD).
	 * @param string $revocationDate Revocation date (YYYY-MM-DD), exclusive.
	 *
	 * @return int Suppletie-amount in cents.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function suppletieBedragCents(array $invoices, string $effectiveDate, string $revocationDate): int {
		$total = 0;
		foreach ($invoices as $invoice) {
			if ((string)($invoice['vrijstellingsGrondslag'] ?? '') !== 'KOR_ART25_OB') {
				continue;
			}

			$leveringsDatum = (string)($invoice['leveringsDatum'] ?? '');
			if ($leveringsDatum === '' || $leveringsDatum < $effectiveDate || $leveringsDatum >= $revocationDate) {
				continue;
			}

			$grossCents = $this->toCents(amount: ($invoice['amount'] ?? ($invoice['netAmount'] ?? 0)));
			// VAT embedded in the gross KOR amount: gross * rate / (1 + rate).
			$vatCents = (int)round(($grossCents * self::STANDARD_VAT_RATE) / (1.0 + self::STANDARD_VAT_RATE));
			$total += $vatCents;
		}//end foreach

		return $total;
	}//end suppletieBedragCents()

	/**
	 * Add three calendar years to a date for the heraanmelding-blokkade (REQ-KOR-004).
	 *
	 * @param string $date Date in YYYY-MM-DD.
	 *
	 * @return string Date plus three years in YYYY-MM-DD; empty string when input invalid.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function plusThreeYears(string $date): string {
		// Validate strictly as YYYY-MM-DD; pure string arithmetic avoids a
		// DateTime dependency and keeps the leveringsdatum + 3 year deterministic.
		if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $date, $match) !== 1) {
			return '';
		}

		$year = ((int)$match[1] + 3);
		$month = (int)$match[2];
		$day = (int)$match[3];
		if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
			return '';
		}

		// Clamp 29 February to 28 February when +3 years lands on a non-leap year.
		if ($month === 2 && $day === 29 && checkdate(2, 29, $year) === false) {
			$day = 28;
		}

		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}//end plusThreeYears()

	/**
	 * Resolve the canonical lock-in window for a KOR-NL registration (REQ-KOR-007).
	 *
	 * NL-KOR lock-in is three full calendar years counted from the effectiveDate.
	 * earliestTerminationDate is the three-month opt-out window, opening on the first
	 * day of October of the third year (lockInEndDate - 3 months). The returned
	 * dates are exact YYYY-MM-DD strings — the caller persists them on the
	 * KORRegistration record so the manifest pages can render the window.
	 *
	 * @param string $effectiveDate KOR-NL effective date (YYYY-MM-DD).
	 *
	 * @return array{lockInEndDate:string,earliestTerminationDate:string}|null Window or null on invalid input.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function lockInWindow(string $effectiveDate): ?array {
		if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $effectiveDate, $match) !== 1) {
			return null;
		}

		$year = ((int)$match[1] + 3);
		$month = (int)$match[2];
		$day = (int)$match[3];
		if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
			return null;
		}

		// LockInEindDatum: effectiveDate - 1 day + 3 years = end of third calendar year.
		// For a 1-1 ingangsdatum, that is 31-12 of (year + 2). We model the general
		// case as +3 years - 1 day; for the canonical 1-1 start the prior day is 31-12.
		if ($month === 1 && $day === 1) {
			$lockInYear = ($year - 1);
			$lockInEinde = sprintf('%04d-12-31', $lockInYear);
		} else {
			$priorDay = ($day - 1);
			$priorMonth = $month;
			if ($priorDay < 1) {
				$priorMonth = ($month - 1);
				if ($priorMonth < 1) {
					$priorMonth = 12;
					$year -= 1;
				}

				$priorDay = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $priorMonth)));
			}

			$lockInEinde = sprintf('%04d-%02d-%02d', $year, $priorMonth, $priorDay);
		}

		// Vroegste opzeg-date = three months before lockInEinde, rounded to the
		// first day of that month (canonical opt-out window opens 1 October when
		// lockInEinde is 31 December).
		if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $lockInEinde, $m2) !== 1) {
			return null;
		}

		$lyear = (int)$m2[1];
		$lmonth = (int)$m2[2];
		$opzegMonth = ($lmonth - 2);
		$opzegYear = $lyear;
		if ($opzegMonth < 1) {
			$opzegMonth += 12;
			$opzegYear -= 1;
		}

		$vroegsteOpzeg = sprintf('%04d-%02d-01', $opzegYear, $opzegMonth);

		return [
			'lockInEndDate' => $lockInEinde,
			'earliestTerminationDate' => $vroegsteOpzeg,
		];

	}//end lockInWindow()

	/**
	 * Decide whether a vrijwillige opzegging is permitted at a moment in time (REQ-KOR-007).
	 *
	 * Opt-out is blocked until earliestTerminationDate (three months before the end of
	 * the three-year lock-in) and again after lockInEndDate the registration is
	 * already at its natural end (different lifecycle path).
	 *
	 * @param string $today Today's date (YYYY-MM-DD).
	 * @param string $earliestTerminationDate Earliest opt-out date (YYYY-MM-DD).
	 * @param string $lockInEndDate End of the lock-in window (YYYY-MM-DD).
	 *
	 * @return bool True when an operator-initiated opt-out is permitted.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function isOptOutPermitted(string $today, string $earliestTerminationDate, string $lockInEndDate): bool {
		if ($earliestTerminationDate === '' || $lockInEndDate === '') {
			return false;
		}

		return ($today >= $earliestTerminationDate && $today <= $lockInEndDate);
	}//end isOptOutPermitted()

	/**
	 * Aggregate cross-border KOR-EU revenue per lidstaat (REQ-KOR-008).
	 *
	 * Walks the KOR-EU AR invoices, groups them by lidstaat-ISO-code, sums revenue
	 * per country, and resolves benutting against the per-lidstaat threshold passed
	 * in $thresholdsPerMemberState. Countries not in the drempels map fall back to
	 * the EU-wide default 100000 EUR ceiling. Arithmetic in cents.
	 *
	 * @param array<int,array<string,mixed>> $invoices KOR-EU AR-invoice records (must carry `lidstaat`).
	 * @param array<string,float> $thresholdsPerMemberState Per-country threshold (EUR); e.g. ['BE' => 25000, 'DE' => 22000].
	 * @param int $year Calendar year to bound the aggregation.
	 *
	 * @return array<string,array{revenue:float,threshold:float,benutting:float}> Per-lidstaat aggregate.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function perLidstaatAggregate(array $invoices, array $thresholdsPerMemberState, int $year): array {
		$defaultDrempelCents = $this->toCents(amount: 100000);
		$cents = [];
		foreach ($invoices as $invoice) {
			if ((string)($invoice['vrijstellingsGrondslag'] ?? '') !== 'KOR_ART25_OB') {
				continue;
			}

			$lidstaat = strtoupper((string)($invoice['lidstaat'] ?? ''));
			if ($lidstaat === '') {
				continue;
			}

			$leveringsDatum = (string)($invoice['leveringsDatum'] ?? '');
			if (substr($leveringsDatum, 0, 4) !== (string)$year) {
				continue;
			}

			$cents[$lidstaat] = (($cents[$lidstaat] ?? 0) + $this->toCents(amount: ($invoice['amount'] ?? 0)));
		}

		$result = [];
		foreach ($cents as $lidstaat => $revenueCents) {
			$thresholdCents = ($defaultDrempelCents);
			if (isset($thresholdsPerMemberState[$lidstaat]) === true) {
				$thresholdCents = $this->toCents(amount: $thresholdsPerMemberState[$lidstaat]);
			}

			$result[$lidstaat] = [
				'revenue' => $this->fromCents(cents: $revenueCents),
				'threshold' => $this->fromCents(cents: $thresholdCents),
				'benutting' => round($this->benutting(revenueCents: $revenueCents, thresholdCents: $thresholdCents), 4),
			];
		}

		ksort($result);
		return $result;
	}//end perLidstaatAggregate()

	/**
	 * Resolve a branche-specifieke advisory for a KOR-NL aanmelding (REQ-KOR-010).
	 *
	 * Combines the KvK activiteitscode-class and the administration's vrijstellingen
	 * to surface compatibility issues before lock-in:
	 *  - art. 11 OB full exemption -> KOR adds no benefit and disables voorbelasting (BLOCK).
	 *  - mixed-use vrijgesteld+belast -> effective threshold only on the belaste deel (WARN).
	 *  - intracommunautair -> OSS-regime is the better fit (WARN).
	 *  - fiscale-unit -> unit must apply, not the individual (BLOCK).
	 * Otherwise OK. The returned advisory is text-based (REQ-KOR-010 no chatbot).
	 *
	 * @param array<string,mixed> $branche Administration's branche profile.
	 *
	 * @return array{verdict:string,reason:string} Verdict (OK|WARN|BLOCK) + reason.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function brancheCompatibility(array $branche): array {
		$isFiscaleEenheid = (bool)($branche['fiscaleEenheid'] ?? false);
		if ($isFiscaleEenheid === true) {
			return [
				'verdict' => 'BLOCK',
				'reason' => 'KOR aanmelden door een fiscale unit is niet mogelijk; '
					. 'de unit zelf moet aanmelden, niet een individuele deelnemer.',
			];
		}

		$fullExempt = (bool)($branche['art11Vrijstelling'] ?? false);
		if ($fullExempt === true) {
			return [
				'verdict' => 'BLOCK',
				'reason' => 'Onderneming valt volledig onder art. 11 OB; KOR levert geen voordeel en blokkeert voorbelasting-aftrek.',
			];
		}

		$isMixed = (bool)($branche['vrijgesteldEnBelast'] ?? false);
		if ($isMixed === true) {
			return [
				'verdict' => 'WARN',
				'reason' => 'Mixed-use vrijgesteld + belast: effective KOR-threshold wordt calculated over alleen het belaste deel.',
			];
		}

		$isIntra = (bool)($branche['intracommunautair'] ?? false);
		if ($isIntra === true) {
			return [
				'verdict' => 'WARN',
				'reason' => 'Bedrijf doet structureel intracommunautaire leveringen; overweeg OSS-regime als alternatief.',
			];
		}

		return ['verdict' => 'OK', 'reason' => 'Geen branche-specifieke contra-indicaties; KOR is geschikt.'];
	}//end brancheCompatibility()

	/**
	 * Compute the voorraad-correctie suppletie for a Regulier -> KOR transition (REQ-KOR-011a).
	 *
	 * On Regulier -> KOR aanmelding, voorbelasting-aftrek that was claimed on
	 * investeringsgoederen still held at effectiveDate must be partially returned
	 * per herzieningsregels: corrected = original * (remainingMonths / totalMonths).
	 * Equipment lifetime defaults to 60 months, real estate to 120 months. The
	 * suppletie tax_return sums the corrections per asset. Arithmetic in cents.
	 *
	 * @param array<int,array<string,mixed>> $assets Asset records with vatCents + remainingMonths + totalMonths.
	 *
	 * @return int Total voorraad-correctie suppletie in cents.
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function voorraadCorrectieCents(array $assets): int {
		$total = 0;
		foreach ($assets as $asset) {
			$vatCents = (int)($asset['vatCents'] ?? 0);
			$remainingMonths = (int)($asset['remainingMonths'] ?? 0);
			$totalMonths = (int)($asset['totalMonths'] ?? 0);
			$total += $this->herzieningRecoveryCents(
				vatCents: $vatCents,
				remainingMonths: $remainingMonths,
				totalMonths: $totalMonths
			);
		}

		return $total;
	}//end voorraadCorrectieCents()

	/**
	 * Compute proportional voorbelasting recovery per herzieningsregels (REQ-KOR-006, REQ-KOR-011).
	 *
	 * On revocatie, voorbelasting on an asset purchased during KOR (where no aftrek
	 * was claimed) can be reclaimed proportional to the asset's remaining useful life:
	 * recovery = vatAmount * (remainingMonths / totalMonths). Arithmetic in cents.
	 *
	 * @param int $vatCents Original voorbelasting on the asset, in cents.
	 * @param int $remainingMonths Remaining useful-life months at revocatie.
	 * @param int $totalMonths Total useful-life months (60 for equipment, 120 for real estate).
	 *
	 * @return int Recoverable voorbelasting in cents (clamped to 0..vatCents).
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 */
	public function herzieningRecoveryCents(int $vatCents, int $remainingMonths, int $totalMonths): int {
		if ($totalMonths <= 0) {
			return 0;
		}

		$remaining = max(0, min($remainingMonths, $totalMonths));
		$recovery = (int)round(($vatCents * $remaining) / $totalMonths);
		return max(0, min($recovery, $vatCents));
	}//end herzieningRecoveryCents()
}//end class
