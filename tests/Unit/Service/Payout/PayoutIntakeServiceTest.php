<?php

/**
 * Unit tests for PayoutIntakeService.
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
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Payout;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Service\Payout\PayoutIntakeService;
use OCA\Shillinq\Service\Payout\PayoutMatchingService;
use OCA\Shillinq\Service\Payout\PayoutPostingRule;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * REQ-RPPO-001: one ProviderPayout per reported settlement, matched and booked.
 *
 * @covers \OCA\Shillinq\Service\Payout\PayoutIntakeService
 * @uses   \OCA\Shillinq\Service\Payout\PayoutMatchingService
 * @uses   \OCA\Shillinq\Service\Payout\PayoutPostingRule
 * @uses   \OCA\Shillinq\Service\PaymentRevenueAccountResolver
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.1
 */
final class PayoutIntakeServiceTest extends TestCase {

	/**
	 * The register rows by schema; saves land here too.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The transition runner the journal entry is posted through.
	 *
	 * @var ObjectTransitionRunnerInterface&MockObject
	 */
	private ObjectTransitionRunnerInterface $transitions;

	/**
	 * Seed the requests the settlement's payments paid.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			'PaymentRequest' => [
				['id' => 'pr-1', 'administrationId' => 'THV', 'providerPaymentId' => 'tr_example0001', 'invoiceReference' => 'inv-0601'],
				['id' => 'pr-2', 'administrationId' => 'THV', 'providerPaymentId' => 'tr_example0002', 'invoiceReference' => 'inv-0602'],
			],
		];
		$this->transitions = $this->createMock(ObjectTransitionRunnerInterface::class);
	}//end setUp()

	/**
	 * The service over an in-memory store that filters on properties as OpenRegister does.
	 *
	 * @return PayoutIntakeService
	 */
	private function service(): PayoutIntakeService {
		$schema = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			static function (string|int $slug) use (&$schema, $objects): ObjectServiceInterface {
				$schema = (string)$slug;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use (&$schema): array {
				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static function (array $row) use ($config): bool {
							foreach ((array)($config['filters'] ?? []) as $path => $wanted) {
								if (($row[$path] ?? null) !== $wanted) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) use (&$schema): ObjectEntityInterface {
				$object['id'] = strtolower($schema) . '-' . (count($this->rows[$schema] ?? []) + 1);
				$this->rows[$schema][] = $object;
				return new ObjectEntityStub(payload: $object, register: 'shillinq', schema: $schema);
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default=''): string => $default);
		$location = $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']);

		return new PayoutIntakeService(
			objectService: $objects,
			registerLocation: $location,
			matching: new PayoutMatchingService(objectService: $objects, appConfig: $config),
			posting: new PayoutPostingRule(appConfig: $config),
			transitions: $this->transitions,
			logger: new NullLogger(),
		);
	}//end service()

	/**
	 * The settlement integriq reports (design D1): two payments, a refund and an unknown payment.
	 *
	 * @param string $reference The settlement reference.
	 *
	 * @return array<string, mixed>
	 */
	private static function settlement(string $reference='st_example0001'): array {
		return [
			'settlementReference' => ' ' . $reference . ' ',
			'settledAt' => '2026-10-02T06:00:00+00:00',
			'currency' => 'eur',
			'amountGross' => 223.2,
			'amountFees' => 1.35,
			'amountRefunds' => 20.0,
			'amountChargebacks' => 0,
			'amountNet' => 201.85,
			'transactions' => [
				['providerPaymentId' => 'tr_example0001', 'type' => 'payment', 'amount' => 145.2, 'fee' => 0.45],
				['providerPaymentId' => 'tr_example0002', 'type' => 'payment', 'amount' => 60.0, 'fee' => 0.45],
				['providerPaymentId' => 'tr_example0099', 'type' => 'payment', 'amount' => 18.0, 'fee' => 0.45],
				['providerPaymentId' => 're_example0005', 'type' => 'refund', 'amount' => -20.0, 'originalPaymentId' => 'tr_example0001'],
				'not a transaction',
			],
		];
	}//end settlement()

	/**
	 * A settlement becomes one payout with its lines, matched, booked and placed in the administration it paid.
	 *
	 * @return void
	 */
	public function testASettlementIsKeptAsOneMatchedAndBookedPayout(): void {
		$this->transitions->expects($this->once())->method('runAsSystem')->with('journalentry-1', 'postDirect');

		$outcome = $this->service()->record(settlement: self::settlement());

		$this->assertSame(PayoutIntakeService::RESULT_RECORDED, $outcome['result']);
		$this->assertCount(1, $this->rows['ProviderPayout']);
		$payout = $this->rows['ProviderPayout'][0];
		$this->assertSame('providerpayout-1', $outcome['payout']['id']);
		$this->assertSame('st_example0001', $payout['settlementReference']);
		$this->assertSame('mollie', $payout['provider']);
		$this->assertSame('THV', $payout['administrationId']);
		$this->assertSame('2026-10-02', $payout['settledAt']);
		$this->assertSame('EUR', $payout['currency']);
		$this->assertSame(201.85, $payout['amountNet']);
		$this->assertSame('booked', $payout['state']);
		$this->assertSame('journalentry-1', $payout['glTransactionId']);
		$this->assertSame(3, $payout['matchedLineCount']);
		$this->assertSame(1, $payout['unmatchedLineCount']);
		$this->assertSame(
			[
				['tr_example0001', 'payment', 145.2, 'matched'],
				['tr_example0002', 'payment', 60.0, 'matched'],
				['tr_example0099', 'payment', 18.0, 'unmatched'],
				['re_example0005', 'refund', 20.0, 'matched'],
			],
			array_map(static fn (array $line): array => [$line['providerPaymentId'], $line['type'], $line['amount'], $line['status']], $payout['lines'])
		);
		$this->assertSame('inv-0601', $payout['lines'][3]['matchedObjectId']);
		$this->assertSame('tr_example0001', $payout['lines'][3]['originalPaymentId']);

		$journal = $this->rows['JournalEntry'][0];
		$this->assertSame('PO-st_example0001', $journal['journalNumber']);
		$this->assertSame('THV', $journal['administrationId']);
		$this->assertSame(
			[['1020', 'debit', 201.85], ['4720', 'debit', 1.35], ['1100', 'debit', 20.0], ['clearing', 'credit', 223.2]],
			array_map(static fn (array $line): array => [$line['accountNumber'], $line['side'], $line['amount']], $journal['lines'])
		);
	}//end testASettlementIsKeptAsOneMatchedAndBookedPayout()

	/**
	 * The same settlement reported twice is kept once and booked once.
	 *
	 * @return void
	 */
	public function testARepeatedSettlementIsKeptOnce(): void {
		$this->transitions->expects($this->once())->method('runAsSystem');
		$service = $this->service();

		$first = $service->record(settlement: self::settlement());
		$second = $service->record(settlement: self::settlement());

		$this->assertSame(PayoutIntakeService::RESULT_DUPLICATE, $second['result']);
		$this->assertSame($first['payout']['id'], $second['payout']['id']);
		$this->assertCount(1, $this->rows['ProviderPayout']);
		$this->assertCount(1, $this->rows['JournalEntry']);
	}//end testARepeatedSettlementIsKeptOnce()

	/**
	 * A payout whose posting is refused is still kept, at matched, without a journal entry id.
	 *
	 * @return void
	 */
	public function testAPayoutThatCannotBePostedIsKeptMatched(): void {
		$this->transitions->method('runAsSystem')->willThrowException(new RuntimeException('period closed'));

		$outcome = $this->service()->record(settlement: self::settlement());

		$this->assertSame(PayoutIntakeService::RESULT_RECORDED, $outcome['result']);
		$this->assertSame('matched', $this->rows['ProviderPayout'][0]['state']);
		$this->assertArrayNotHasKey('glTransactionId', $this->rows['ProviderPayout'][0]);
	}//end testAPayoutThatCannotBePostedIsKeptMatched()

	/**
	 * Amounts that do not add up are not booked: the payout stays matched and no journal entry is written.
	 *
	 * @return void
	 */
	public function testAPayoutThatDoesNotAddUpIsNotBooked(): void {
		$this->transitions->expects($this->never())->method('runAsSystem');
		$settlement = self::settlement();
		$settlement['amountNet'] = 200.0;

		$this->service()->record(settlement: $settlement);

		$this->assertSame('matched', $this->rows['ProviderPayout'][0]['state']);
		$this->assertArrayNotHasKey('JournalEntry', $this->rows);
	}//end testAPayoutThatDoesNotAddUpIsNotBooked()

	/**
	 * Without a reference or a net amount nothing is written.
	 *
	 * @return void
	 */
	public function testASettlementWithoutReferenceOrNetIsRefused(): void {
		$service = $this->service();
		$noReference = self::settlement(reference: '');
		$noNet = self::settlement();
		unset($noNet['amountNet']);

		$this->assertSame(PayoutIntakeService::RESULT_INVALID, $service->record(settlement: $noReference)['result']);
		$refused = $service->record(settlement: $noNet);
		$this->assertSame(PayoutIntakeService::RESULT_INVALID, $refused['result']);
		$this->assertSame('Settlement st_example0001 carries no net amount.', $refused['reason']);
		$this->assertArrayNotHasKey('ProviderPayout', $this->rows);
	}//end testASettlementWithoutReferenceOrNetIsRefused()

	/**
	 * The settlement's own provider and administration win; a missing gross is the sum of the payments;
	 * an unknown provider or line kind falls back.
	 *
	 * @return void
	 */
	public function testBuildTakesTheReportedProviderAndDerivesAMissingGross(): void {
		$service = $this->service();
		$settlement = ['provider' => 'Stripe', 'administrationId' => 'ADM-9', 'amountNet' => 10, 'transactions' => [
			['providerPaymentId' => 'pi_1', 'type' => 'payment', 'amount' => 7.255],
			['providerPaymentId' => 'pi_2', 'type' => 'transfer', 'amount' => 3],
			['providerPaymentId' => 'pi_3', 'type' => 'chargeback', 'amount' => 1, 'fee' => 'n/a'],
		]];

		$payout = $service->build(settlement: $settlement, reference: 'st_x');
		$this->assertSame('stripe', $payout['provider']);
		$this->assertSame('ADM-9', $payout['administrationId']);
		$this->assertSame(10.26, $payout['amountGross']);
		$this->assertSame(0.0, $payout['amountFees']);
		$this->assertSame(['payment', 'payment', 'chargeback'], array_column($payout['lines'], 'type'));
		$this->assertNull($payout['lines'][2]['fee']);
		$this->assertSame('received', $payout['state']);

		$this->assertSame('mollie', $service->build(settlement: ['provider' => 'paypal'], reference: 'st_y')['provider']);
	}//end testBuildTakesTheReportedProviderAndDerivesAMissingGross()
}//end class
