<?php

/**
 * VAT tariff resolver
 *
 * The VatTariff a line's code names on the document date, with the legacy line codes mapped.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Tax
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Tax;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * A dated lookup over the VatTariff records (design D2).
 *
 * The tariff is the record whose code matches and whose `effectiveFrom` /
 * `effectiveTo` contain the document date; both bounds are inclusive and an
 * absent `effectiveTo` is open-ended. An unknown code, or a code with no
 * record valid on the date, is refused: there is no default rate. The legacy
 * line codes BTW21, BTW9, BTW0 and VERLEGD resolve to the seeded high, low,
 * zero and reverse-charge tariffs.
 *
 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.1
 */
class VatTariffResolver {

	/**
	 * The legacy line codes and the seeded tariff each one means.
	 *
	 * @var array<string,string>
	 */
	public const LEGACY_CODES = [
		'BTW21' => 'high',
		'BTW9' => 'low',
		'BTW0' => 'zero',
		'VERLEGD' => 'reverse-charge',
	];

	/**
	 * The VatTariff records, read once per request.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private ?array $tariffs = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    OpenRegister's object surface.
	 * @param RegisterLocation       $registerLocation Where the register lives.
	 *
	 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.1
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
	) {

	}//end __construct()

	/**
	 * The seeded code a line code stands for: the legacy map, else the code itself.
	 *
	 * @param string $code The line's code.
	 *
	 * @return string The tariff code.
	 *
	 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.1
	 */
	public static function canonicalCode(string $code): string {
		$code = trim($code);

		return (self::LEGACY_CODES[strtoupper($code)] ?? $code);

	}//end canonicalCode()

	/**
	 * The tariff a code names on a date.
	 *
	 * @param string                   $code The line's tariff code, legacy codes included.
	 * @param DateTimeInterface|string $date The document date.
	 *
	 * @return array<string,mixed> The VatTariff record.
	 *
	 * @throws VatTariffRefusedException When the code is unknown or no record of it is valid on the date.
	 *
	 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.1
	 */
	public function forCode(string $code, DateTimeInterface|string $date): array {
		$day       = self::day(date: $date);
		$canonical = self::canonicalCode(code: $code);
		$known     = false;
		foreach ($this->tariffs() as $tariff) {
			if ((string)($tariff['code'] ?? '') !== $canonical) {
				continue;
			}

			$known = true;
			if (self::validOn(tariff: $tariff, day: $day) === true) {
				return $tariff;
			}
		}

		$why = 'unknown';
		if ($known === true) {
			$why = 'expired';
		}

		throw new VatTariffRefusedException(tariffCode: trim($code), date: $day, why: $why);

	}//end forCode()

	/**
	 * The percentage a code charges on a date.
	 *
	 * @param string                   $code The line's tariff code.
	 * @param DateTimeInterface|string $date The document date.
	 *
	 * @return float The percentage, for example 21.0.
	 *
	 * @throws VatTariffRefusedException When the code is unknown or not valid on the date.
	 *
	 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.1
	 */
	public function rateFor(string $code, DateTimeInterface|string $date): float {
		return self::percentageOf(tariff: $this->forCode(code: $code, date: $date));

	}//end rateFor()

	/**
	 * A tariff's percentage: `ratePercentage`, else `rate` as a fraction.
	 *
	 * @param array<string,mixed> $tariff The VatTariff record.
	 *
	 * @return float The percentage.
	 *
	 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.1
	 */
	public static function percentageOf(array $tariff): float {
		if (is_numeric($tariff['ratePercentage'] ?? null) === true) {
			return (float)$tariff['ratePercentage'];
		}

		return round(((float)($tariff['rate'] ?? 0)) * 100, 4);

	}//end percentageOf()

	/**
	 * Whether a tariff's dates contain the day; both bounds inclusive, an absent end open.
	 *
	 * @param array<string,mixed> $tariff The VatTariff record.
	 * @param string              $day    Y-m-d.
	 *
	 * @return bool
	 */
	private static function validOn(array $tariff, string $day): bool {
		$from = substr(trim((string)($tariff['effectiveFrom'] ?? '')), 0, 10);
		$to   = substr(trim((string)($tariff['effectiveTo'] ?? '')), 0, 10);

		return ($from === '' || $from <= $day) && ($to === '' || $day <= $to);

	}//end validOn()

	/**
	 * The date as Y-m-d.
	 *
	 * @param DateTimeInterface|string $date The date.
	 *
	 * @return string Y-m-d.
	 *
	 * @throws VatTariffRefusedException When a string date cannot be read.
	 */
	private static function day(DateTimeInterface|string $date): string {
		if ($date instanceof DateTimeInterface) {
			return $date->format('Y-m-d');
		}

		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($date), 0, 10));
		if ($parsed === false) {
			throw new VatTariffRefusedException(tariffCode: '', date: trim($date), why: 'unknown');
		}

		return $parsed->format('Y-m-d');

	}//end day()

	/**
	 * Every VatTariff record of the register.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function tariffs(): array {
		if ($this->tariffs !== null) {
			return $this->tariffs;
		}

		$scoped  = $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema('VatTariff');
		$records = [];
		foreach ($scoped->findAll([]) as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		$this->tariffs = $records;

		return $records;

	}//end tariffs()
}//end class
