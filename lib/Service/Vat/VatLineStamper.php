<?php

/**
 * Shillinq VatLineStamper
 *
 * Says, for a document line about to be posted, which VAT tariff it was
 * booked at, which VAT return box that tariff counts in and how much VAT
 * belongs to each tariff of the document (REQ-VBTW-004, design D1).
 *
 * The box is the tariff's `section` in the VatTariff register, so an
 * operator-added tariff carries its own. Input VAT counts in 5b. The base of
 * an ordinary purchase is in no box; the base of a reverse-charged purchase is
 * in its tariff's box (2a, 4a or 4b). Without a tariff record no box is
 * guessed: the line keeps its tariff code and kind, so the return checks can
 * name it.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Vat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Vat;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Throwable;

/**
 * Tariff, box and VAT split for posted lines.
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md#requirement-req-vbtw-004-the-btw-journal-shall-be-derived-from-period-filtered-gl-aggregations
 */
class VatLineStamper {

	/**
	 * The box input VAT counts in.
	 */
	public const INPUT_VAT_BOX = '5b';

	/**
	 * The tariff whose rate a reverse-charged purchase is booked at: the
	 * supplier charges nothing, so the reverse-charge tariffs carry 0 percent
	 * and the VAT owed and deducted is the general rate (decision 72).
	 */
	public const REVERSE_CHARGE_RATE_TARIFF = 'high';

	/**
	 * Sales VAT categories (UNTDID 5305) that map to one tariff whatever the rate.
	 */
	private const SALE_CATEGORIES = [
		'Z' => 'zero',
		'E' => 'exempt',
		'AE' => 'reverse-charge-supply',
		'K' => 'intra-eu-supply',
		'G' => 'export',
	];

	/**
	 * Standard-rated sales by rate percentage.
	 */
	private const STANDARD_RATES = [
		'21' => 'high',
		'9' => 'low',
	];

	/**
	 * Purchase tax codes written before the tariff codes existed.
	 */
	private const LEGACY_PURCHASE_CODES = [
		'BTW21' => 'high',
		'BTW9' => 'low',
		'BTW0' => 'zero',
	];

	/**
	 * The VatTariff records by code, read once.
	 *
	 * @var array<string, array<string,mixed>>|null
	 */
	private ?array $tariffs = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param string                 $register      The register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly string $register,
	) {
	}//end __construct()

	/**
	 * The tariff of a sales invoice line, from its VAT category and rate.
	 *
	 * @param array<string,mixed> $line The ARInvoice line.
	 *
	 * @return string The tariff code, empty when the line names none.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.2
	 */
	public function saleTariff(array $line): string {
		$category = strtoupper(trim((string)($line['vatCategory'] ?? '')));
		if (isset(self::SALE_CATEGORIES[$category]) === true) {
			return self::SALE_CATEGORIES[$category];
		}

		$rate = $this->percentage(rate: ($line['vatRate'] ?? null));
		if ($category !== 'S' || $rate === null) {
			return '';
		}

		$key = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
		return (self::STANDARD_RATES[$key] ?? '');
	}//end saleTariff()

	/**
	 * The tariff of a purchase invoice line, from its tax code.
	 *
	 * @param array<string,mixed> $line The APInvoice line.
	 *
	 * @return string The tariff code, empty when the line names none.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.2
	 */
	public function purchaseTariff(array $line): string {
		$code = trim((string)($line['taxCode'] ?? ''));
		return (self::LEGACY_PURCHASE_CODES[strtoupper($code)] ?? $code);
	}//end purchaseTariff()

	/**
	 * The fields a posted line carries for its tariff.
	 *
	 * @param string $code     The tariff code; empty stamps nothing.
	 * @param string $kind     `base` or `vat`.
	 * @param bool   $purchase Whether the line belongs to a purchase.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.2
	 */
	public function stamp(string $code, string $kind, bool $purchase): array {
		if ($code === '') {
			return [];
		}

		$stamp  = ['vatTariffCode' => $code, 'vatAmountKind' => $kind];
		$tariff = $this->tariff(code: $code);
		if ($tariff === null) {
			return $stamp;
		}

		$box = (string)($tariff['section'] ?? '');
		if ($purchase === true && $kind === 'vat') {
			$box = self::INPUT_VAT_BOX;
		}

		if ($purchase === true && $kind === 'base' && $this->isReverseCharge(code: $code) === false) {
			$box = '';
		}

		if ($box !== '') {
			$stamp['vatReturnBox'] = $box;
		}

		return $stamp;
	}//end stamp()

	/**
	 * The fields of the line that owes a reverse-charged purchase's VAT: the
	 * VAT kind, in the box of the purchase's own tariff (2a, 4a or 4b).
	 *
	 * @param string $code The reverse-charge tariff code.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.2
	 */
	public function stampOwed(string $code): array {
		$stamp = ['vatTariffCode' => $code, 'vatAmountKind' => 'vat'];
		$box   = (string)($this->tariff(code: $code)['section'] ?? '');
		if ($box !== '') {
			$stamp['vatReturnBox'] = $box;
		}

		return $stamp;
	}//end stampOwed()

	/**
	 * The rate a reverse-charged purchase's VAT is booked at, null when the
	 * tariff that supplies it has no record (then no rate is guessed).
	 *
	 * @return float|null
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.2
	 */
	public function reverseChargeRate(): ?float {
		return $this->ratePercentage(code: self::REVERSE_CHARGE_RATE_TARIFF);
	}//end reverseChargeRate()

	/**
	 * Whether a purchase at this tariff is reverse-charged: its VAT is not on
	 * the supplier's invoice.
	 *
	 * @param string $code The tariff code.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.2
	 */
	public function isReverseCharge(string $code): bool {
		return (($this->tariff(code: $code)['reverseCharge'] ?? false) === true);
	}//end isReverseCharge()

	/**
	 * The statutory percentage of a tariff, null when there is no record.
	 *
	 * @param string $code The tariff code.
	 *
	 * @return float|null
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.2
	 */
	public function ratePercentage(string $code): ?float {
		$tariff = $this->tariff(code: $code);
		if ($tariff === null) {
			return null;
		}

		return $this->percentage(rate: ($tariff['ratePercentage'] ?? ($tariff['rate'] ?? null)));
	}//end ratePercentage()

	/**
	 * The VAT on a base at a rate, in (unrounded) cents.
	 *
	 * @param int   $baseCents The base in cents.
	 * @param mixed $rate      The rate, as 21 or as 0.21.
	 *
	 * @return float|null Null when the rate is not a number.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.2
	 */
	public function vatCents(int $baseCents, mixed $rate): ?float {
		$percentage = $this->percentage(rate: $rate);
		if ($percentage === null) {
			return null;
		}

		return ($baseCents * $percentage / 100);
	}//end vatCents()

	/**
	 * Split a document's VAT over its tariffs, in cents.
	 *
	 * Each group's VAT is what the document says it charged when it says so,
	 * else its base times its rate. The cents the groups do not account for go
	 * to the group with the largest base (the first on a tie), so the VAT
	 * booked always equals the document's VAT and the posting balances.
	 *
	 * @param array<string, array{base: int, vat: float|null}> $groups     Per tariff code, in document order.
	 * @param int                                              $totalCents The document's VAT.
	 *
	 * @return array<string, int> VAT cents per tariff code.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.2
	 */
	public function splitVat(array $groups, int $totalCents): array {
		if ($groups === [] && $totalCents === 0) {
			return [];
		}

		if ($groups === []) {
			return ['' => $totalCents];
		}

		$split   = [];
		$largest = null;
		foreach ($groups as $code => $group) {
			$split[$code] = (int)round((float)($group['vat'] ?? 0.0));
			if ($largest === null || abs($group['base']) > abs($groups[$largest]['base'])) {
				$largest = $code;
			}
		}

		$split[$largest] += ($totalCents - array_sum($split));
		return $split;
	}//end splitVat()

	/**
	 * A rate as a percentage: 0.21 and 21 both read as 21.
	 *
	 * @param mixed $rate The rate.
	 *
	 * @return float|null
	 */
	private function percentage(mixed $rate): ?float {
		if (is_numeric($rate) === false) {
			return null;
		}

		$rate = (float)$rate;
		if ($rate > 0 && $rate < 1) {
			return round($rate * 100, 4);
		}

		return $rate;
	}//end percentage()

	/**
	 * The tariff record by code.
	 *
	 * @param string $code The tariff code.
	 *
	 * @return array<string,mixed>|null
	 */
	private function tariff(string $code): ?array {
		if ($this->tariffs === null) {
			$this->tariffs = [];
			try {
				$rows = $this->objectService->setRegister($this->register)->setSchema('VatTariff')->findAll(['limit' => 500]);
			} catch (Throwable) {
				$rows = [];
			}

			foreach ($rows as $row) {
				if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
					$row = $row->jsonSerialize();
				}

				if (is_array($row) === true && (string)($row['code'] ?? '') !== '') {
					$this->tariffs[(string)$row['code']] = $row;
				}
			}
		}

		return ($this->tariffs[$code] ?? null);
	}//end tariff()
}//end class
