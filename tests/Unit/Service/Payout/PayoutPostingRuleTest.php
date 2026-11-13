<?php

/**
 * PayoutPostingRule tests (receivables-provider-payouts task 2.4)
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Payout
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Payout;

use InvalidArgumentException;
use OCA\Shillinq\Service\Payout\PayoutPostingRule;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * A payout books net to the bank, fees to bank costs, refunds against debtors and gross off clearing.
 *
 * @covers \OCA\Shillinq\Service\Payout\PayoutPostingRule
 * @uses   \OCA\Shillinq\Service\PaymentRevenueAccountResolver
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.4
 */
final class PayoutPostingRuleTest extends TestCase {

	/**
	 * The rule over the given app config values.
	 *
	 * @param array<string, string> $values App config values by key.
	 *
	 * @return PayoutPostingRule
	 */
	private function rule(array $values=[]): PayoutPostingRule {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($values[$key] ?? $default)
		);
		return new PayoutPostingRule(appConfig: $config);
	}//end rule()

	/**
	 * Payout st_example0001: 14 payments of EUR 612.40, a refund of EUR 22.85 and EUR 4.06 fees,
	 * EUR 585.49 paid out.
	 *
	 * @return array<string, mixed>
	 */
	private function payout(): array {
		$lines = [];
		$amounts = [43.75, 43.75, 43.75, 43.75, 43.75, 43.75, 43.75, 43.75, 43.75, 43.75, 43.75, 43.75, 43.75, 43.65];
		foreach ($amounts as $i => $amount) {
			$lines[] = ['providerPaymentId' => sprintf('tr_example%04d', ($i + 1)), 'type' => 'payment', 'amount' => $amount, 'status' => 'matched'];
		}

		$lines[] = ['providerPaymentId' => 're_example0015', 'type' => 'refund', 'amount' => 22.85, 'status' => 'matched', 'matchedObjectId' => 'inv-0601'];

		return [
			'administrationId' => 'THV',
			'provider' => 'mollie',
			'settlementReference' => 'st_example0001',
			'settledAt' => '2026-10-02',
			'amountGross' => 612.40,
			'amountFees' => 4.06,
			'amountRefunds' => 22.85,
			'amountChargebacks' => 0,
			'amountNet' => 585.49,
			'lines' => $lines,
		];
	}//end payout()

	/**
	 * The seed payout books one balanced entry, to the cent.
	 *
	 * @return void
	 */
	public function testTheSeedPayoutBooksBalancedToTheCent(): void {
		$journal = $this->rule(values: ['paymentRevenueAccounts' => '{"clearing": "1290"}'])->journal(payout: $this->payout());

		$this->assertSame('PO-st_example0001', $journal['journalNumber']);
		$this->assertSame('2026-10-02', $journal['entryDate']);
		$this->assertSame('THV', $journal['administrationId']);
		$this->assertSame(
			[
				['1020', 'debit', 585.49],
				['4720', 'debit', 4.06],
				['1100', 'debit', 22.85],
				['1290', 'credit', 612.40],
			],
			array_map(static fn (array $line): array => [$line['accountNumber'], $line['side'], $line['amount']], $journal['lines'])
		);
		$this->assertSame('Refund re_example0015 on inv-0601', $journal['lines'][2]['description']);

		$cents = static function (string $side) use ($journal): int {
			$total = 0;
			foreach ($journal['lines'] as $line) {
				if ($line['side'] === $side) {
					$total += (int)round($line['amount'] * 100);
				}
			}

			return $total;
		};
		$this->assertSame($cents('debit'), $cents('credit'));
	}//end testTheSeedPayoutBooksBalancedToTheCent()

	/**
	 * A provider's configured accounts replace the defaults; a payout without fees has no fee line.
	 *
	 * @return void
	 */
	public function testConfiguredAccountsAndNoFees(): void {
		$rule = $this->rule(values: ['payoutPostingAccounts' => '{"stripe": {"bank": "1021", "fees": "4725"}}']);
		$this->assertSame(
			['bank' => '1021', 'fees' => '4725', 'debtors' => '1100', 'clearing' => 'clearing'],
			$rule->accounts(provider: 'stripe')
		);
		$this->assertSame(['bank' => '1020', 'fees' => '4720', 'debtors' => '1100', 'clearing' => 'clearing'], $rule->accounts(provider: 'mollie'));

		$journal = $rule->journal(
			payout: [
				'provider' => 'stripe',
				'settlementReference' => 'po_1',
				'settledAt' => '2026-10-09',
				'amountGross' => 100,
				'amountNet' => 100,
				'lines' => [],
			]
		);
		$this->assertSame(
			[['1021', 'debit', 100.0], ['clearing', 'credit', 100.0]],
			array_map(static fn (array $line): array => [$line['accountNumber'], $line['side'], $line['amount']], $journal['lines'])
		);
	}//end testConfiguredAccountsAndNoFees()

	/**
	 * A payout whose amounts do not add up, or without a reference, is not booked.
	 *
	 * @return void
	 */
	public function testAPayoutThatDoesNotAddUpIsRefused(): void {
		$payout = $this->payout();
		$payout['amountNet'] = 585.50;
		try {
			$this->rule()->journal(payout: $payout);
			$this->fail('An unbalanced payout was booked.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(
				'Payout st_example0001 does not add up: net 585.50 + fees 4.06 + refunds and chargebacks 22.85 is not the gross 612.40.',
				$e->getMessage()
			);
		}

		$this->expectException(InvalidArgumentException::class);
		$this->rule()->journal(payout: ['amountGross' => 10, 'amountNet' => 10]);
	}//end testAPayoutThatDoesNotAddUpIsRefused()
}//end class
