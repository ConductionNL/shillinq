<?php

/**
 * Shillinq DeductibleShareSplitter
 *
 * Splits a cost line whose deductible percentage is below 100 into the share
 * the business may deduct and the share it may not (REQ-TVRD-004, design D3).
 * The percentage is the line's own `deductiblePercentage`, else its account's,
 * else 100. Below 100 the deductible share of the net stays on the cost
 * account with the input VAT on that share; the rest of the net plus the VAT
 * on it goes to the account's `privateShareAccountNumber`, marked
 * `nonDeductible`. A line at 100 is returned unchanged, so it posts as before.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Ledger
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Ledger;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use RuntimeException;
use Throwable;

/**
 * The deductible and private shares of a cost line.
 *
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
 */
class DeductibleShareSplitter {

	/**
	 * The full share: a line at this percentage is not split.
	 */
	public const FULL = 100.0;

	/**
	 * The tax treatment the private share is booked with.
	 */
	public const PRIVATE_TREATMENT = 'nonDeductible';

	/**
	 * The posted-line fields that say which VAT return box a line counts in.
	 */
	private const VAT_STAMP_FIELDS = ['vatTariffCode', 'vatReturnBox', 'vatAmountKind'];

	/**
	 * The accounts of the administration by number, read once.
	 *
	 * @var array<string, array<string,mixed>>|null
	 */
	private ?array $accounts = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    OpenRegister's object service.
	 * @param string                 $register         The register slug.
	 * @param string                 $administrationId The administration whose accounts are read.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly string $register,
		private readonly string $administrationId,
	) {
	}//end __construct()

	/**
	 * The deductible percentage of a source line: the line's own, else its
	 * account's, else 100.
	 *
	 * @param array<string,mixed> $line The source line (accountNumber, optional deductiblePercentage).
	 *
	 * @return float The percentage, 0 to 100.
	 *
	 * @throws RuntimeException When the percentage is not a number from 0 to 100.
	 *
	 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-3.2
	 */
	public function percentage(array $line): float {
		$accountNumber = (string)($line['accountNumber'] ?? '');
		$value         = ($line['deductiblePercentage'] ?? null);
		if ($value === null || $value === '') {
			$value = ($this->account(number: $accountNumber)['deductiblePercentage'] ?? null);
		}

		if ($value === null || $value === '') {
			return self::FULL;
		}

		if (is_numeric($value) === false || (float)$value < 0 || (float)$value > self::FULL) {
			throw new RuntimeException(
				sprintf('The deductible percentage of account %s must be a number from 0 to 100; got "%s".', $accountNumber, (string)json_encode($value))
			);
		}

		return (float)$value;
	}//end percentage()

	/**
	 * Split one mapped cost line.
	 *
	 * The deductible base is rounded half up to the cent and the private base
	 * is the rest, so the two always add up to the line. The private VAT is
	 * rounded the same way; the deductible VAT is what is left of the line's
	 * VAT, so the caller books exactly the document's VAT less the private
	 * VAT as input VAT.
	 *
	 * @param array<string,mixed> $costLine   The mapped posting line (accountNumber, side, cents, description, stamps).
	 * @param float               $percentage The deductible percentage, 0 to 100.
	 * @param float|null          $vatCents   The line's VAT in (unrounded) cents; null when the line carries no VAT here.
	 *
	 * @return array{lines: list<array<string,mixed>>, base: int, vat: float|null, privateVat: int}
	 *         The posting lines (the deductible cost line, and the private line when there is a private share),
	 *         the deductible base and VAT for the line's tariff group, and the VAT moved to the private share.
	 *
	 * @throws RuntimeException When the account has no private-share account.
	 *
	 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-3.2
	 */
	public function split(array $costLine, float $percentage, ?float $vatCents): array {
		$cents = (int)$costLine['cents'];
		if ($percentage >= self::FULL) {
			return ['lines' => [$costLine], 'base' => $cents, 'vat' => $vatCents, 'privateVat' => 0];
		}

		$accountNumber  = (string)$costLine['accountNumber'];
		$privateAccount = (string)($this->account(number: $accountNumber)['privateShareAccountNumber'] ?? '');
		if ($privateAccount === '') {
			throw new RuntimeException(
				sprintf(
					'Account %s is %s%% deductible but names no private-share account, so its private share cannot be posted.',
					$accountNumber,
					self::format(percentage: $percentage)
				)
			);
		}

		$deductibleBase = self::share(cents: (float)$cents, percentage: $percentage);
		$privateVat     = 0;
		$deductibleVat  = null;
		if ($vatCents !== null) {
			$privateVat    = self::share(cents: $vatCents, percentage: (self::FULL - $percentage));
			$deductibleVat = ($vatCents - $privateVat);
		}

		$deductible          = $costLine;
		$deductible['cents'] = $deductibleBase;

		$private = [
			'accountNumber' => $privateAccount,
			'side' => (string)$costLine['side'],
			'cents' => (($cents - $deductibleBase) + $privateVat),
			'description' => sprintf(
				'%s (non-deductible %s%%)',
				(string)($costLine['description'] ?? ''),
				self::format(percentage: (self::FULL - $percentage))
			),
			'taxTreatment' => self::PRIVATE_TREATMENT,
		];
		// A base that counts in a return box (reverse charge) keeps counting in full.
		if ((string)($costLine['vatReturnBox'] ?? '') !== '') {
			foreach (self::VAT_STAMP_FIELDS as $field) {
				$private[$field] = $costLine[$field];
			}
		}

		return ['lines' => [$deductible, $private], 'base' => $deductibleBase, 'vat' => $deductibleVat, 'privateVat' => $privateVat];
	}//end split()

	/**
	 * A percentage share of an amount in cents, rounded half up.
	 *
	 * @param float $cents      The amount in cents.
	 * @param float $percentage The share, 0 to 100.
	 *
	 * @return int
	 */
	private static function share(float $cents, float $percentage): int {
		return (int)round(($cents * $percentage / self::FULL), 0, PHP_ROUND_HALF_UP);
	}//end share()

	/**
	 * A percentage for a message, without trailing zeros.
	 *
	 * @param float $percentage The percentage.
	 *
	 * @return string
	 */
	private static function format(float $percentage): string {
		return rtrim(rtrim(number_format($percentage, 2, '.', ''), '0'), '.');
	}//end format()

	/**
	 * The administration's account by number; empty when it is not in the register.
	 *
	 * @param string $number The account number.
	 *
	 * @return array<string,mixed>
	 */
	private function account(string $number): array {
		if ($this->accounts === null) {
			$this->accounts = [];
			try {
				$rows = $this->objectService->setRegister($this->register)->setSchema('Account')->findAll(
					['filters' => ['administrationId' => $this->administrationId], 'limit' => 5000]
				);
			} catch (Throwable) {
				$rows = [];
			}

			foreach ($rows as $row) {
				if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
					$row = $row->jsonSerialize();
				}

				if (is_array($row) === true && (string)($row['accountNumber'] ?? '') !== '') {
					$this->accounts[(string)$row['accountNumber']] = $row;
				}
			}
		}//end if

		return ($this->accounts[$number] ?? []);
	}//end account()
}//end class
