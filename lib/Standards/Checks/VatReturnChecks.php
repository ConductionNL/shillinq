<?php

/**
 * VAT Return Checks
 *
 * The checks a VAT return (BtwAangifte) runs before it is filed, read from
 * the books of its administration and period.
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

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\MaterialiseGlTransactionActionInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Vat\VatLineStamper;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IL10N;
use Throwable;

/**
 * Six checks on a BtwAangifte, each with an id, a severity and a message.
 *
 * Unlike the invoice checks of the RuleEngine, which look at one object,
 * these read the ledger: the posted lines of the return's period, the VAT
 * accounts' movement and the previous return. A blocking check that fails
 * stops the `submit` transition (VatReturnChecksGuard); a warning is shown
 * but does not stop it. When the books cannot be read every check fails, so
 * a return is never filed on checks that did not run.
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Six independent ledger checks share one read of the books.
 */
class VatReturnChecks {

	/**
	 * A failing check of this severity refuses the submit.
	 *
	 * @var string
	 */
	public const BLOCKING = 'blocking';

	/**
	 * A failing check of this severity is shown and does not refuse.
	 *
	 * @var string
	 */
	public const WARNING = 'warning';

	/**
	 * The check ids, in the order they run, with their severity.
	 *
	 * @var array<string, string>
	 */
	public const CHECKS = [
		'vat-line-has-box' => self::BLOCKING,
		'vat-equals-base-times-rate' => self::WARNING,
		'vat-accounts-match-return' => self::BLOCKING,
		'no-unposted-documents' => self::WARNING,
		'previous-return-filed' => self::WARNING,
		'reverse-charge-owed-and-deductible' => self::BLOCKING,
	];

	/**
	 * The return states that count as filed with the tax authority.
	 *
	 * @var array<int, string>
	 */
	private const FILED_STATES = ['submitted', 'verified', 'filed'];

	/**
	 * Transaction states that are booked.
	 *
	 * @var array<int, string>
	 */
	private const BOOKED_STATES = ['posted', 'reversed'];

	/**
	 * Transaction states that are neither booked nor pending.
	 *
	 * @var array<int, string>
	 */
	private const CLOSED_STATES = ['posted', 'reversed', 'cancelled', 'voided'];

	/**
	 * The boxes whose VAT is owed under reverse charge.
	 *
	 * @var array<int, string>
	 */
	private const REVERSE_CHARGE_BOXES = ['2a', '4a', '4b'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    Reads the books.
	 * @param RegisterLocation       $registerLocation Where the register lives.
	 * @param IAppConfig             $appConfig        The output and input VAT account numbers.
	 * @param IL10N                  $l10n             The check messages.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Run every check on a return.
	 *
	 * @param array<string, mixed> $return The BtwAangifte: administrationId, startDate, endDate, totals.
	 *
	 * @return list<array{id: string, severity: string, passed: bool, message: string}>
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
	 */
	public function run(array $return): array {
		try {
			$books = $this->books(return: $return);
		} catch (Throwable $e) {
			$message = $this->l10n->t('The books of this period could not be read: %s', [$e->getMessage()]);
			return array_map(
				static fn (string $id): array => ['id' => $id, 'severity' => self::CHECKS[$id], 'passed' => false, 'message' => $message],
				array_keys(self::CHECKS)
			);
		}

		$failures = [
			'vat-line-has-box' => $this->linesWithoutBox(books: $books),
			'vat-equals-base-times-rate' => $this->vatOffRate(books: $books),
			'vat-accounts-match-return' => $this->accountsOffReturn(books: $books, return: $return),
			'no-unposted-documents' => $this->unposted(books: $books),
			'previous-return-filed' => $this->previousNotFiled(return: $return),
			'reverse-charge-owed-and-deductible' => $this->reverseChargeOneSided(books: $books),
		];

		$results = [];
		foreach ($failures as $id => $failure) {
			$results[] = [
				'id' => $id,
				'severity' => self::CHECKS[$id],
				'passed' => $failure === null,
				'message' => ($failure ?? $this->l10n->t('Passed')),
			];
		}

		return $results;
	}//end run()

	/**
	 * The period's transactions and booked lines, and the VAT accounts.
	 *
	 * @param array<string, mixed> $return The return.
	 *
	 * @return array{booked: array<string, array>, pending: array<string, array>, lines: list<array>, output: string, input: string}
	 */
	private function books(array $return): array {
		$administrationId = (string)($return['administrationId'] ?? '');
		$start = substr((string)($return['startDate'] ?? ''), 0, 10);
		$end   = substr((string)($return['endDate'] ?? ''), 0, 10);

		$booked  = [];
		$pending = [];
		foreach ($this->rows(schema: 'GLTransaction', administrationId: $administrationId) as $transaction) {
			$date = substr((string)($transaction['postingDate'] ?? ($transaction['transactionDate'] ?? '')), 0, 10);
			if ($date < $start || $date > $end) {
				continue;
			}

			$state = (string)($transaction['state'] ?? '');
			if (in_array($state, self::BOOKED_STATES, true) === true) {
				$booked[(string)$transaction['id']] = $transaction;
			} else if (in_array($state, self::CLOSED_STATES, true) === false) {
				$pending[(string)$transaction['id']] = $transaction;
			}
		}

		$lines = array_values(
			array_filter(
				$this->rows(schema: 'GLLine', administrationId: $administrationId),
				static fn (array $line): bool => isset($booked[(string)($line['transactionId'] ?? '')]) === true
			)
		);

		return [
			'booked' => $booked,
			'pending' => $pending,
			'lines' => $lines,
			'output' => $this->vatAccount(key: MaterialiseGlTransactionActionInterface::CFG_OUTPUT_VAT_ACCOUNT, default: '2110'),
			'input' => $this->vatAccount(key: MaterialiseGlTransactionActionInterface::CFG_INPUT_VAT_ACCOUNT, default: '1230'),
		];
	}//end books()

	/**
	 * Lines on a VAT account, or with a tariff, that carry no box or amount kind.
	 *
	 * @param array $books The books.
	 *
	 * @return string|null The failure message, or null when the check passes.
	 */
	private function linesWithoutBox(array $books): ?string {
		$names = [];
		foreach ($books['lines'] as $line) {
			$account = (string)($line['accountNumber'] ?? '');
			$onVat   = in_array($account, [$books['output'], $books['input']], true) || (string)($line['vatTariffCode'] ?? '') !== '';
			$stamped = (string)($line['vatReturnBox'] ?? '') !== '' && in_array(($line['vatAmountKind'] ?? null), ['base', 'vat'], true) === true;
			if ($onVat === true && $stamped === false) {
				$names[] = $this->transactionName(books: $books, id: (string)$line['transactionId']);
			}
		}

		if ($names === []) {
			return null;
		}

		return $this->l10n->t('These transactions have a VAT line without a box: %s', [implode(', ', array_unique($names))]);
	}//end linesWithoutBox()

	/**
	 * Transactions whose VAT per tariff differs from base times rate by more than one cent.
	 *
	 * @param array $books The books.
	 *
	 * @return string|null
	 */
	private function vatOffRate(array $books): ?string {
		$groups = [];
		foreach ($books['lines'] as $line) {
			$code = (string)($line['vatTariffCode'] ?? '');
			$kind = (string)($line['vatAmountKind'] ?? '');
			if ($code === '' || in_array($kind, ['base', 'vat'], true) === false) {
				continue;
			}

			$key = (string)$line['transactionId'].'|'.$code;
			$groups[$key] ??= ['transactionId' => (string)$line['transactionId'], 'code' => $code, 'base' => 0, 'vat' => 0];
			$groups[$key][$kind] += self::cents(value: ($line['amount'] ?? 0));
		}

		$stamper = new VatLineStamper(objectService: $this->objectService, register: $this->registerLocation->getRegisterSlug());
		$names   = [];
		foreach ($groups as $group) {
			$rate = $stamper->ratePercentage(code: $group['code']);
			if ($rate === null || $rate <= 0.0 || $group['base'] === 0 || $group['vat'] === 0) {
				continue;
			}

			if (abs($group['vat'] - (int)round($group['base'] * $rate / 100)) > 1) {
				$names[] = $this->transactionName(books: $books, id: $group['transactionId']).' ('.$group['code'].')';
			}
		}

		if ($names === []) {
			return null;
		}

		return $this->l10n->t('The VAT posted differs from the base times the rate on: %s', [implode(', ', $names)]);
	}//end vatOffRate()

	/**
	 * The output and input VAT accounts' period movement against the return's totals.
	 *
	 * @param array                $books  The books.
	 * @param array<string, mixed> $return The return.
	 *
	 * @return string|null
	 */
	private function accountsOffReturn(array $books, array $return): ?string {
		$movement = [$books['output'] => 0, $books['input'] => 0];
		foreach ($books['lines'] as $line) {
			$account = (string)($line['accountNumber'] ?? '');
			if (array_key_exists($account, $movement) === false) {
				continue;
			}

			$credit = (string)($line['side'] ?? '') === 'credit';
			$cents  = self::cents(value: ($line['amount'] ?? 0));
			if (($account === $books['output']) !== $credit) {
				$cents = -$cents;
			}

			$movement[$account] += $cents;
		}

		$expected = [
			$books['output'] => self::cents(value: ($return['totalVATCollected'] ?? 0)),
			$books['input'] => self::cents(value: ($return['totalVATPaid'] ?? 0)),
		];
		$off = [];
		foreach ($expected as $account => $cents) {
			if ($movement[$account] !== $cents) {
				$off[] = $this->l10n->t(
					'account %1$s moved %2$s, the return says %3$s',
					[(string)$account, self::euros(cents: $movement[$account]), self::euros(cents: $cents)]
				);
			}
		}

		if ($off === []) {
			return null;
		}

		return $this->l10n->t('The VAT accounts do not match the return: %s', [implode('; ', $off)]);
	}//end accountsOffReturn()

	/**
	 * Draft or otherwise unposted transactions dated in the period.
	 *
	 * @param array $books The books.
	 *
	 * @return string|null
	 */
	private function unposted(array $books): ?string {
		if ($books['pending'] === []) {
			return null;
		}

		$names = array_map(static fn (array $transaction): string => self::nameOf(transaction: $transaction), array_values($books['pending']));

		return $this->l10n->t('These documents in the period are not posted: %s', [implode(', ', $names)]);
	}//end unposted()

	/**
	 * Whether the return of the period before is filed, when the administration has an earlier return.
	 *
	 * @param array<string, mixed> $return The return.
	 *
	 * @return string|null
	 */
	private function previousNotFiled(array $return): ?string {
		$start = substr((string)($return['startDate'] ?? ''), 0, 10);
		$dayBefore = (new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');

		$earlier = false;
		foreach ($this->rows(schema: 'BtwAangifte', administrationId: (string)($return['administrationId'] ?? '')) as $other) {
			if ((string)$other['id'] === (string)($return['id'] ?? '')) {
				continue;
			}

			$end = substr((string)($other['endDate'] ?? ''), 0, 10);
			if ($end === $dayBefore) {
				if (in_array((string)($other['statusCode'] ?? ''), self::FILED_STATES, true) === true) {
					return null;
				}

				return $this->l10n->t('The return %s of the previous period is not filed yet.', [(string)($other['returnNumber'] ?? $other['id'])]);
			}

			$earlier = ($earlier || ($end !== '' && $end < $start));
		}

		if ($earlier === false) {
			return null;
		}

		return $this->l10n->t('There is no return for the period ending %s.', [$dayBefore]);
	}//end previousNotFiled()

	/**
	 * Reverse-charged transactions whose owed VAT differs from the VAT they deduct.
	 *
	 * @param array $books The books.
	 *
	 * @return string|null
	 */
	private function reverseChargeOneSided(array $books): ?string {
		$byTransaction = [];
		foreach ($books['lines'] as $line) {
			$box = (string)($line['vatReturnBox'] ?? '');
			$id  = (string)$line['transactionId'];
			if (in_array($box, self::REVERSE_CHARGE_BOXES, true) === true) {
				$byTransaction[$id] ??= ['owed' => 0, 'deducted' => 0];
				if (($line['vatAmountKind'] ?? null) === 'vat') {
					$byTransaction[$id]['owed'] += self::cents(value: ($line['amount'] ?? 0));
				}
			}
		}

		foreach ($books['lines'] as $line) {
			$id = (string)$line['transactionId'];
			$deducts = (string)($line['vatReturnBox'] ?? '') === VatLineStamper::INPUT_VAT_BOX && ($line['vatAmountKind'] ?? null) === 'vat';
			if (isset($byTransaction[$id]) === true && $deducts === true) {
				$byTransaction[$id]['deducted'] += self::cents(value: ($line['amount'] ?? 0));
			}
		}

		$names = [];
		foreach ($byTransaction as $id => $sides) {
			if ($sides['owed'] !== $sides['deducted']) {
				$names[] = $this->transactionName(books: $books, id: (string)$id);
			}
		}

		if ($names === []) {
			return null;
		}

		return $this->l10n->t('Reverse-charged VAT is not both owed and deducted on: %s', [implode(', ', $names)]);
	}//end reverseChargeOneSided()

	/**
	 * The rows of a schema in the administration, as records with an id.
	 *
	 * @param string $schema           The schema.
	 * @param string $administrationId The administration.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema, string $administrationId): array {
		$rows = $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema)
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$records = [];
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null && (string)($record['id'] ?? '') !== '') {
				$records[] = $record;
			}
		}

		return $records;
	}//end rows()

	/**
	 * A booked transaction's journal number, else its id.
	 *
	 * @param array  $books The books.
	 * @param string $id    The transaction id.
	 *
	 * @return string
	 */
	private function transactionName(array $books, string $id): string {
		return self::nameOf(transaction: ($books['booked'][$id] ?? ['id' => $id]));
	}//end transactionName()

	/**
	 * A transaction's journal number, else its transaction number, else its id.
	 *
	 * @param array<string, mixed> $transaction The transaction.
	 *
	 * @return string
	 */
	private static function nameOf(array $transaction): string {
		return (string)($transaction['journalNumber'] ?? ($transaction['transactionNumber'] ?? ($transaction['id'] ?? '')));
	}//end nameOf()

	/**
	 * A configured VAT account number, else its default.
	 *
	 * @param string $key     The app config key.
	 * @param string $default The default number.
	 *
	 * @return string
	 */
	private function vatAccount(string $key, string $default): string {
		$configured = trim($this->appConfig->getValueString(AppIdentity::APP_ID, $key, ''));
		if ($configured === '') {
			return $default;
		}

		return $configured;
	}//end vatAccount()

	/**
	 * An amount in cents.
	 *
	 * @param mixed $value The amount in euros.
	 *
	 * @return int
	 */
	private static function cents(mixed $value): int {
		if (is_numeric($value) === false) {
			return 0;
		}

		return (int)round((float)$value * 100);
	}//end cents()

	/**
	 * Cents as euros with two decimals.
	 *
	 * @param int $cents The amount.
	 *
	 * @return string
	 */
	private static function euros(int $cents): string {
		return 'EUR '.number_format($cents / 100, 2, '.', '');
	}//end euros()
}//end class
