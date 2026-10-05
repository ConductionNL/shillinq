<?php

/**
 * VAT return check provider
 *
 * The checks a BTW return (BtwAangifte) must pass before it is filed
 * (REQ-TVRB-001, design D3): every VAT line has a box; posted VAT equals base
 * times rate per tariff within one cent; the VAT accounts' period movement
 * equals the return's totals; nothing in the period is still a draft; the
 * previous period's return is submitted; reverse-charge VAT is both owed and
 * deducted. Each is a rule in lib/Standards/rules/vat-return-checks.json, whose
 * severity says whether it blocks filing (`mandatory`) or warns
 * (`recommended`).
 *
 * A return check needs the period's ledger, not only the return object, so
 * each predicate reads the facts VatReturnCheckService puts in the context
 * under `vatReturnFacts`. Without those facts (the generic rule audit, which
 * evaluates every object type with a bare context) a check cannot be run and
 * is not counted as a violation; the service, which is the only caller that
 * decides anything, always supplies them.
 *
 * `offenders()` names what makes a check fail (transaction numbers, accounts,
 * documents), so the checks tab and the submit refusal can say what to fix.
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Executable checks on a BtwAangifte, over the facts of its period.
 *
 * @spec openspec/changes/tax-vat-return-from-books/specs/bookkeeping-vat-btw-filing/spec.md#requirement-checks-run-on-the-vat-return-and-block-filing-when-they-fail-req-tvrb-001
 */
final class VatReturnChecks implements CheckProvider {

	public const LINE_BOX = 'nl-vat-return-line-box';

	public const RATE_PER_LINE = 'nl-vat-return-rate-per-line';

	public const ACCOUNT_MOVEMENT = 'nl-vat-return-account-movement';

	public const NO_DRAFTS = 'nl-vat-return-no-drafts';

	public const PREVIOUS_FILED = 'nl-vat-return-previous-filed';

	public const REVERSE_CHARGE_PAIR = 'nl-vat-return-reverse-charge-pair';

	/**
	 * The checks in the order the checks tab shows them.
	 */
	public const RULE_IDS = [
		self::LINE_BOX,
		self::RATE_PER_LINE,
		self::ACCOUNT_MOVEMENT,
		self::NO_DRAFTS,
		self::PREVIOUS_FILED,
		self::REVERSE_CHARGE_PAIR,
	];

	/**
	 * The box input VAT is declared in.
	 */
	private const INPUT_VAT_BOX = '5b';

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
	 */
	public static function checks(): array {
		$checks = [];
		foreach (self::RULE_IDS as $ruleId) {
			$checks[$ruleId] = static function (array $object, array $context) use ($ruleId): bool {
				$facts = ($context['vatReturnFacts'] ?? null);
				if (is_array($facts) === false) {
					return true;
				}

				return self::offenders(ruleId: $ruleId, facts: $facts) === [];
			};
		}

		return ['BtwAangifte' => $checks];
	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * What makes a check fail, named so a bookkeeper can find it. Empty when
	 * the check passes.
	 *
	 * @param string              $ruleId One of RULE_IDS.
	 * @param array<string,mixed> $facts  The facts VatReturnCheckService gathered.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
	 */
	public static function offenders(string $ruleId, array $facts): array {
		return match ($ruleId) {
			self::LINE_BOX => self::linesWithoutBox(facts: $facts),
			self::RATE_PER_LINE => self::ratesOff(facts: $facts),
			self::ACCOUNT_MOVEMENT => self::accountsOff(facts: $facts),
			self::NO_DRAFTS => array_values(array_map('strval', (array)($facts['drafts'] ?? []))),
			self::PREVIOUS_FILED => self::previousNotFiled(facts: $facts),
			self::REVERSE_CHARGE_PAIR => self::reverseChargeUnpaired(facts: $facts),
			default => [],
		};
	}//end offenders()

	/**
	 * Transactions with a line on a VAT account, or carrying VAT, that has no box.
	 *
	 * @param array<string,mixed> $facts The facts.
	 *
	 * @return list<string>
	 */
	private static function linesWithoutBox(array $facts): array {
		$vatAccounts = array_values((array)($facts['vatAccounts'] ?? []));
		$found = [];
		foreach (self::lines(facts: $facts) as $line) {
			$onVatAccount = in_array((string)($line['accountNumber'] ?? ''), $vatAccounts, true);
			$carriesVat = (($line['vatAmountKind'] ?? null) === 'vat');
			if (($onVatAccount === true || $carriesVat === true) && (string)($line['vatReturnBox'] ?? '') === '') {
				$found[self::transactionName(facts: $facts, line: $line)] = true;
			}
		}

		return array_keys($found);
	}//end linesWithoutBox()

	/**
	 * Transaction and tariff pairs whose VAT is more than a cent off base times rate.
	 * A reverse-charge tariff is left to the reverse-charge check.
	 *
	 * @param array<string,mixed> $facts The facts.
	 *
	 * @return list<string>
	 */
	private static function ratesOff(array $facts): array {
		$rates = (array)($facts['rates'] ?? []);
		$reverse = (array)($facts['reverseChargeCodes'] ?? []);
		$groups = [];
		foreach (self::lines(facts: $facts) as $line) {
			$code = (string)($line['vatTariffCode'] ?? '');
			$kind = (string)($line['vatAmountKind'] ?? '');
			if ($code === '' || in_array($code, $reverse, true) === true || in_array($kind, ['base', 'vat'], true) === false) {
				continue;
			}

			$key = self::transactionName(facts: $facts, line: $line) . ' (' . $code . ')';
			$groups[$key] ??= ['code' => $code, 'base' => 0, 'vat' => 0];
			$groups[$key][$kind] += abs(self::cents(amount: ($line['amount'] ?? 0)));
		}

		$found = [];
		foreach ($groups as $key => $group) {
			$rate = ($rates[$group['code']] ?? null);
			if ($rate === null) {
				continue;
			}

			if (abs((int)round($group['base'] * (float)$rate / 100) - $group['vat']) > 1) {
				$found[] = $key;
			}
		}

		return $found;
	}//end ratesOff()

	/**
	 * The VAT accounts whose period movement differs from the return.
	 *
	 * @param array<string,mixed> $facts The facts.
	 *
	 * @return list<string>
	 */
	private static function accountsOff(array $facts): array {
		$accounts = (array)($facts['vatAccounts'] ?? []);
		$output = (string)($accounts['output'] ?? '');
		$input = (string)($accounts['input'] ?? '');
		$movement = [$output => 0, $input => 0];
		foreach (self::lines(facts: $facts) as $line) {
			$account = (string)($line['accountNumber'] ?? '');
			if (array_key_exists($account, $movement) === false) {
				continue;
			}

			$cents = self::cents(amount: ($line['amount'] ?? 0));
			if ((string)($line['side'] ?? '') !== 'credit') {
				$cents = -$cents;
			}

			$movement[$account] += $cents;
		}

		$found = [];
		$owed = (int)($facts['totals']['collectedCents'] ?? 0);
		if ($movement[$output] !== $owed) {
			$found[] = sprintf(
				'%s: %s on the account, %s owed in the return',
				$output,
				self::money(cents: $movement[$output]),
				self::money(cents: $owed)
			);
		}

		$deductible = (int)($facts['totals']['paidCents'] ?? 0);
		if (-$movement[$input] !== $deductible) {
			$found[] = sprintf(
				'%s: %s on the account, %s input tax in the return',
				$input,
				self::money(cents: -$movement[$input]),
				self::money(cents: $deductible)
			);
		}

		return $found;
	}//end accountsOff()

	/**
	 * The previous period's return when it is missing or still a draft, unless
	 * the administration has no earlier return at all.
	 *
	 * @param array<string,mixed> $facts The facts.
	 *
	 * @return list<string>
	 */
	private static function previousNotFiled(array $facts): array {
		$previous = (array)($facts['previousPeriod'] ?? []);
		$start = (string)($facts['period']['start'] ?? '');
		$earlier = false;
		$filed = false;
		$draft = '';
		foreach ((array)($facts['returns'] ?? []) as $other) {
			if ((string)($other['startDate'] ?? '') === '' || (string)$other['startDate'] >= $start) {
				continue;
			}

			$earlier = true;
			if ((string)($other['period'] ?? '') !== (string)($previous['kind'] ?? '')
				|| (int)($other['periodYear'] ?? 0) !== (int)($previous['year'] ?? 0)
				|| (int)($other['periodNumber'] ?? 0) !== (int)($previous['number'] ?? 0)
			) {
				continue;
			}

			if ((string)($other['statusCode'] ?? 'draft') !== 'draft') {
				$filed = true;
				continue;
			}

			$draft = (string)($other['returnNumber'] ?? '');
		}//end foreach

		if ($earlier === false || $filed === true) {
			return [];
		}

		if ($draft !== '') {
			return [$draft . ' is still a draft'];
		}

		return ['No return for ' . (string)($previous['label'] ?? 'the previous period')];
	}//end previousNotFiled()

	/**
	 * Transactions with a reverse-charged base whose VAT is not both owed in
	 * the tariff's box and deducted in 5b for the same amount.
	 *
	 * @param array<string,mixed> $facts The facts.
	 *
	 * @return list<string>
	 */
	private static function reverseChargeUnpaired(array $facts): array {
		$reverse = (array)($facts['reverseChargeCodes'] ?? []);
		$pairs = [];
		foreach (self::lines(facts: $facts) as $line) {
			$code = (string)($line['vatTariffCode'] ?? '');
			if (in_array($code, $reverse, true) === false) {
				continue;
			}

			$name = self::transactionName(facts: $facts, line: $line);
			$pairs[$name] ??= ['base' => false, 'owed' => 0, 'deducted' => 0];
			$cents = abs(self::cents(amount: ($line['amount'] ?? 0)));
			if (($line['vatAmountKind'] ?? null) === 'base') {
				$pairs[$name]['base'] = true;
				continue;
			}

			if (($line['vatReturnBox'] ?? null) === self::INPUT_VAT_BOX) {
				$pairs[$name]['deducted'] += $cents;
				continue;
			}

			$pairs[$name]['owed'] += $cents;
		}//end foreach

		$found = [];
		foreach ($pairs as $name => $pair) {
			if ($pair['base'] === true && ($pair['owed'] === 0 || $pair['owed'] !== $pair['deducted'])) {
				$found[] = $name;
			}
		}

		return $found;
	}//end reverseChargeUnpaired()

	/**
	 * The ledger lines of the period's booked transactions.
	 *
	 * @param array<string,mixed> $facts The facts.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function lines(array $facts): array {
		return array_values(array_filter((array)($facts['lines'] ?? []), 'is_array'));
	}//end lines()

	/**
	 * The number of a line's transaction, else its id.
	 *
	 * @param array<string,mixed> $facts The facts.
	 * @param array<string,mixed> $line  The line.
	 *
	 * @return string
	 */
	private static function transactionName(array $facts, array $line): string {
		$id = (string)($line['transactionId'] ?? '');
		$number = (string)($facts['transactions'][$id]['transactionNumber'] ?? '');
		if ($number === '') {
			return $id;
		}

		return $number;
	}//end transactionName()

	/**
	 * An amount in whole cents.
	 *
	 * @param mixed $amount The amount.
	 *
	 * @return int
	 */
	private static function cents(mixed $amount): int {
		return (int)round((float)$amount * 100);
	}//end cents()

	/**
	 * Cents as EUR with two decimals.
	 *
	 * @param int $cents The amount in cents.
	 *
	 * @return string
	 */
	private static function money(int $cents): string {
		return 'EUR ' . number_format(($cents / 100), 2, '.', '');
	}//end money()
}//end class
