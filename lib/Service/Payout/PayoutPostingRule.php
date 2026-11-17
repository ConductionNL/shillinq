<?php

/**
 * Shillinq PayoutPostingRule.
 *
 * The one balanced journal entry that books a provider payout: debit the bank
 * account for the net amount, debit the fee account for the fees, debit the
 * debtors for each refund and chargeback, and credit the clearing account,
 * where the captures were booked, for the gross amount.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Payout
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Payout;

use InvalidArgumentException;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\PaymentRevenueAccountResolver;
use OCP\IAppConfig;

/**
 * Builds the journal entry of a provider payout.
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.4
 */
class PayoutPostingRule {

	/**
	 * App config key holding the payout posting accounts, as JSON per provider:
	 * `{"mollie": {"bank": "1020", "fees": "4720", "debtors": "1100"}}`.
	 */
	public const CONFIG_KEY = 'payoutPostingAccounts';

	/**
	 * The accounts used when a provider names none: the RGS bank, bank costs and debtors accounts.
	 *
	 * @var array{bank: string, fees: string, debtors: string}
	 */
	public const DEFAULT_ACCOUNTS = [
		'bank' => '1020',
		'fees' => '4720',
		'debtors' => '1100',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config for the posting accounts.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.4
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The posting accounts of a provider: bank, fees, debtors and clearing.
	 *
	 * The clearing account is the one the captures are booked to
	 * (`paymentRevenueAccounts.clearing`).
	 *
	 * @param string $provider The payment provider, e.g. `mollie`.
	 *
	 * @return array{bank: string, fees: string, debtors: string, clearing: string}
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.4
	 */
	public function accounts(string $provider): array {
		$configured = [];
		$decoded = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, ''), true);
		if (is_array($decoded) === true && is_array($decoded[$provider] ?? null) === true) {
			$configured = $decoded[$provider];
		}

		$accounts = [];
		foreach (self::DEFAULT_ACCOUNTS as $role => $default) {
			$value = trim((string)($configured[$role] ?? ''));
			$accounts[$role] = $default;
			if ($value !== '') {
				$accounts[$role] = $value;
			}
		}

		$accounts['clearing'] = ((new PaymentRevenueAccountResolver(appConfig: $this->appConfig))->resolve(requestType: 'clearing') ?? 'clearing');
		return $accounts;
	}//end accounts()

	/**
	 * The journal entry that books the payout, balanced to the cent.
	 *
	 * @param array<string, mixed> $payout The matched ProviderPayout.
	 *
	 * @return array<string, mixed> A draft JournalEntry with its lines.
	 *
	 * @throws InvalidArgumentException When the payout's amounts do not add up: net + fees + refunds + chargebacks must be gross.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.4
	 */
	public function journal(array $payout): array {
		$reference = (string)($payout['settlementReference'] ?? '');
		$provider = (string)($payout['provider'] ?? '');
		$gross = $this->cents(value: ($payout['amountGross'] ?? null));
		$net = $this->cents(value: ($payout['amountNet'] ?? null));
		$fees = $this->cents(value: ($payout['amountFees'] ?? null));
		$returned = $this->returnedLines(payout: $payout);
		$returnedTotal = array_sum(array_column($returned, 'cents'));

		if ($reference === '' || $gross <= 0) {
			throw new InvalidArgumentException('A payout needs a settlement reference and a gross amount to be booked.');
		}

		if ($net + $fees + $returnedTotal !== $gross) {
			throw new InvalidArgumentException(
				sprintf(
					'Payout %s does not add up: net %s + fees %s + refunds and chargebacks %s is not the gross %s.',
					$reference,
					$this->money(cents: $net),
					$this->money(cents: $fees),
					$this->money(cents: $returnedTotal),
					$this->money(cents: $gross)
				)
			);
		}

		$accounts = $this->accounts(provider: $provider);
		$memo = sprintf('Payout %s %s', $provider, $reference);
		$lines = [$this->line(account: $accounts['bank'], side: 'debit', cents: $net, description: $memo)];
		if ($fees > 0) {
			$lines[] = $this->line(account: $accounts['fees'], side: 'debit', cents: $fees, description: $memo . ' fees');
		}

		foreach ($returned as $line) {
			$lines[] = $this->line(account: $accounts['debtors'], side: 'debit', cents: $line['cents'], description: $line['description']);
		}

		$lines[] = $this->line(account: $accounts['clearing'], side: 'credit', cents: $gross, description: $memo);

		return [
			'journalNumber' => 'PO-' . $reference,
			'entryDate' => substr((string)($payout['settledAt'] ?? ''), 0, 10),
			'description' => $memo,
			'journalType' => 'manual',
			'approvalState' => 'not-required',
			'administrationId' => (string)($payout['administrationId'] ?? ''),
			'state' => 'draft',
			'lines' => array_values(array_filter($lines, static fn (array $line): bool => $line['amount'] > 0)),
		];
	}//end journal()

	/**
	 * The refund and chargeback lines, in cents, each against the invoice it concerns.
	 *
	 * @param array<string, mixed> $payout The payout.
	 *
	 * @return list<array{cents: int, description: string}>
	 */
	private function returnedLines(array $payout): array {
		$returned = [];
		foreach ((array)($payout['lines'] ?? []) as $line) {
			if (is_array($line) === false || in_array(($line['type'] ?? ''), ['refund', 'chargeback'], true) === false) {
				continue;
			}

			$description = sprintf('%s %s', ucfirst((string)$line['type']), (string)($line['providerPaymentId'] ?? ''));
			if (trim((string)($line['matchedObjectId'] ?? '')) !== '') {
				$description .= ' on ' . (string)$line['matchedObjectId'];
			}

			$returned[] = ['cents' => abs($this->cents(value: ($line['amount'] ?? null))), 'description' => $description];
		}

		return $returned;
	}//end returnedLines()

	/**
	 * One journal line.
	 *
	 * @param string $account     The account number.
	 * @param string $side        `debit` or `credit`.
	 * @param int    $cents       The amount in cents.
	 * @param string $description The line's description.
	 *
	 * @return array{accountNumber: string, side: string, amount: float, description: string}
	 */
	private function line(string $account, string $side, int $cents, string $description): array {
		return ['accountNumber' => $account, 'side' => $side, 'amount' => round(($cents / 100), 2), 'description' => $description];
	}//end line()

	/**
	 * An amount in whole cents; 0 when absent.
	 *
	 * @param mixed $value The amount.
	 *
	 * @return int
	 */
	private function cents(mixed $value): int {
		if (is_numeric($value) === false) {
			return 0;
		}

		return (int)round(((float)$value) * 100);
	}//end cents()

	/**
	 * Cents as a two-decimal amount.
	 *
	 * @param int $cents The amount in cents.
	 *
	 * @return string
	 */
	private function money(int $cents): string {
		return number_format(($cents / 100), 2, '.', '');
	}//end money()
}//end class
