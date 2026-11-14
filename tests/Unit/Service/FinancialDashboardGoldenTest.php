<?php

/**
 * Golden characterisation of FinancialDashboardService over two administrations.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
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

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Service\FinancialDashboardService;
use OCA\Shillinq\Service\FinancialSeriesCalculator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins every public payload, the administration scoping and the failure paths.
 */
final class FinancialDashboardGoldenTest extends TestCase {

	/**
	 * Recorded log lines and queries.
	 *
	 * @var array<int,mixed>
	 */
	private array $events = [];

	/**
	 * Records per schema for two administrations, with one serialisable
	 * object and one scalar record.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	private static function records(): array {
		$both = static function (array $rows): array {
			$out = [];
			foreach (['adm-1', 'adm-2'] as $n => $adm) {
				foreach ($rows as $row) {
					$row['administrationId'] = $adm;
					foreach (['amount', 'grossAmount', 'netAmount', 'totalAmount', 'balance'] as $money) {
						if (isset($row[$money]) === true) {
							$row[$money] = ($row[$money] * ($n + 1));
						}
					}
					$out[] = $row;
				}
			}
			return $out;
		};
		$serialisable = new class {
			public function jsonSerialize(): array {
				return ['accountNumber' => '8100', 'name' => 'Omzet producten', 'accountType' => 'revenue', 'administrationId' => 'adm-1'];
			}
		};

		return [
			'Account' => array_merge(
				$both(
					[
						['accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue'],
						['accountNumber' => '4000', 'name' => 'Kosten', 'accountType' => 'expenses'],
						['accountNumber' => '1010', 'name' => 'Bank', 'accountType' => 'assets', 'accountSubType' => 'bank'],
						['accountNumber' => '1000', 'name' => 'Kas', 'accountType' => 'assets', 'accountSubType' => 'cash'],
						['accountNumber' => '1300', 'name' => 'Debiteuren', 'accountType' => 'assets'],
					]
				),
				[$serialisable, 'scalar']
			),
			'GLTransaction' => $both(
				[
					['id' => 'tx-1', 'transactionNumber' => 'TX-1', 'postingDate' => '2025-11-05 00:00:00', 'state' => 'posted'],
					['id' => 'tx-2', 'transactionNumber' => 'TX-2', 'postingDate' => '2026-03-20 00:00:00', 'state' => 'posted'],
					['id' => 'tx-3', 'transactionNumber' => 'TX-3', 'postingDate' => '2026-04-02 00:00:00', 'state' => 'posted'],
					['id' => 'tx-4', 'transactionNumber' => 'TX-4', 'postingDate' => '2026-04-09 00:00:00', 'state' => 'posted'],
				]
			),
			'GLLine' => $both(
				[
					['transactionId' => 'tx-1', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 700],
					['transactionId' => 'tx-1', 'accountNumber' => '1300', 'side' => 'debit', 'amount' => 700],
					['transactionId' => 'tx-2', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 250],
					['transactionId' => 'tx-2', 'accountNumber' => '1010', 'side' => 'credit', 'amount' => 250],
					['transactionId' => 'tx-3', 'accountNumber' => '1010', 'side' => 'debit', 'amount' => 900],
					['transactionId' => 'tx-3', 'accountNumber' => '1300', 'side' => 'credit', 'amount' => 900],
					['transactionId' => 'tx-4', 'accountNumber' => '8100', 'side' => 'credit', 'amount' => 120],
					['transactionId' => 'tx-4', 'accountNumber' => '1000', 'side' => 'debit', 'amount' => 120],
				]
			),
			'UrenRegistratie' => [['date' => '2026-04-02', 'hours' => 6, 'recognisedRate' => 90], ['date' => '2026-03-03', 'hours' => 2]],
			'ARInvoice' => $both(
				[
					['id' => 'i1', 'customerId' => 'c1', 'invoiceDate' => '2026-01-10', 'dueDate' => '2026-02-10', 'grossAmount' => 1210, 'netAmount' => 1000, 'lifecycleState' => 'overdue'],
					['id' => 'i2', 'customerId' => 'c2', 'invoiceDate' => '2026-03-10', 'dueDate' => '2026-06-10', 'grossAmount' => 605, 'netAmount' => 500, 'lifecycleState' => 'issued'],
					['id' => 'i3', 'customerId' => 'c3', 'invoiceDate' => '2026-02-01', 'dueDate' => '2026-03-01', 'grossAmount' => 121, 'netAmount' => 100.555, 'lifecycleState' => 'paid'],
					['id' => 'i4', 'customerId' => 'c1', 'invoiceDate' => '2025-12-30', 'grossAmount' => 50, 'netAmount' => 50, 'lifecycleState' => 'paid'],
					['id' => 'i5', 'customerId' => 'c9', 'invoiceDate' => '2026-02-02', 'grossAmount' => 70, 'netAmount' => 70, 'lifecycleState' => 'draft'],
					['id' => 'i6', 'customerId' => 'c4', 'invoiceDate' => '2026-02-03', 'grossAmount' => 10, 'netAmount' => 20, 'lifecycleState' => 'partially-paid'],
					['id' => 'i7', 'customerId' => 'c5', 'invoiceDate' => '2026-02-04', 'grossAmount' => 10, 'netAmount' => 30, 'lifecycleState' => 'issued'],
					['id' => 'i8', 'customerId' => 'c6', 'invoiceDate' => '2026-02-05', 'grossAmount' => 10, 'netAmount' => 40, 'lifecycleState' => 'issued'],
					['id' => 'i9', 'invoiceDate' => '2026-02-06', 'grossAmount' => 10, 'netAmount' => 60, 'lifecycleState' => 'issued'],
				]
			),
			'APTransaction' => $both([['id' => 'b', 'dueDate' => '2026-05-01', 'totalAmount' => 484, 'state' => 'received']]),
			'CustomerMaster' => $both(
				[
					['customerId' => 'c1', 'legalName' => 'Alfa BV'],
					['@self' => ['id' => 'c2'], 'tradeName' => 'Bravo'],
					['id' => 'c3', 'legalName' => ''],
					['id' => 'c4', 'customerId' => 7, 'tradeName' => 'Delta'],
				]
			),
			'BankAccount' => $both([['id' => 'ba-1', 'iban' => 'NL01BANK0123456789', 'glAccountNumber' => '1010', 'name' => 'Zakelijk']]),
			'BankStatement' => $both([['bankAccountId' => 'ba-1', 'statementDate' => '2026-04-01', 'closingBalance' => 1234.5, 'balance' => 10]]),
		];

	}//end records()

	/**
	 * Build the service; `$fail` names schemas whose query throws, and
	 * `$noStore` makes the container throw.
	 *
	 * @param array<int,string> $fail    Failing schemas.
	 * @param boolean           $noStore Whether OpenRegister is unavailable.
	 *
	 * @return FinancialDashboardService
	 */
	private function service(array $fail = [], bool $noStore = false): FinancialDashboardService {
		$events = &$this->events;
		$store = new class(self::records(), $fail, $events) {
			private string $schema = '';

			public function __construct(private array $records, private array $fail, private array &$events) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $params = []): array {
				$this->events[] = ['find', $this->schema, $params];
				if (in_array($this->schema, $this->fail, true) === true) {
					throw new \RuntimeException('down ' . $this->schema);
				}
				return ($this->records[$this->schema] ?? []);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		if ($noStore === true) {
			$container->method('get')->willThrowException(new \RuntimeException('no OR'));
		} else {
			$container->method('get')->willReturn($store);
		}
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level, &$events): void {
					$events[] = [$level, $message, $context];
				}
			);
		}

		return new FinancialDashboardService($container, $config, new FinancialSeriesCalculator(), $logger);

	}//end service()

	/**
	 * Every payload of the grid, plus the events each produced.
	 *
	 * @return array<string,mixed>
	 */
	private function payloads(): array {
		$now = new DateTimeImmutable('2026-04-15 10:00:00');
		$calls = [
			'seriesRange' => fn (FinancialDashboardService $s): array => $s->series('2026-02-01', '2026-04-30', $now),
			'seriesFallback' => fn (FinancialDashboardService $s): array => $s->series(null, '2026-04-30', $now),
			'seriesEmptyRange' => fn (FinancialDashboardService $s): array => $s->series('2026-05-01', '2026-01-01', $now),
			'summaryRange' => fn (FinancialDashboardService $s): array => $s->summary('2026-03-01', '2026-04-30', $now),
			'summaryFallback' => fn (FinancialDashboardService $s): array => $s->summary('', '', $now),
			'widgetsAdm1' => fn (FinancialDashboardService $s): array => $s->widgetFigures('adm-1', $now),
			'widgetsAdm2' => fn (FinancialDashboardService $s): array => $s->widgetFigures('adm-2', $now),
			'widgetsForeign' => fn (FinancialDashboardService $s): array => $s->widgetFigures('adm-9', $now),
			'cashAdm1' => fn (FinancialDashboardService $s): array => $s->cashPosition('adm-1'),
			'cashAdm2' => fn (FinancialDashboardService $s): array => $s->cashPosition('adm-2'),
		];
		$out = [];
		foreach ($calls as $label => $call) {
			$this->events = [];
			$out[$label] = ['result' => $call($this->service()), 'events' => $this->events];
		}
		$this->events = [];
		$out['failingLines'] = ['result' => $this->service(['GLLine'])->cashPosition('adm-1'), 'events' => $this->events];
		$this->events = [];
		$out['noStore'] = ['result' => $this->service([], true)->summary(null, null, $now), 'events' => $this->events];

		return $out;

	}//end payloads()

	/**
	 * Pin every payload and event against the golden fixture.
	 *
	 * @return void
	 */
	public function testPayloadsMatchTheGolden(): void {
		$golden = (string)file_get_contents(__DIR__ . '/fixtures/financial-dashboard-golden.json');
		self::assertSame($golden, json_encode($this->payloads(), (JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)) . "\n");

	}//end testPayloadsMatchTheGolden()

	/**
	 * Allowed and refused scoping: each administration sees only its own
	 * figures, and an unknown one sees none.
	 *
	 * @return void
	 */
	public function testWidgetAndCashFiguresAreScopedToOneAdministration(): void {
		$now = new DateTimeImmutable('2026-04-15 10:00:00');
		$one = $this->service()->widgetFigures('adm-1', $now);
		$two = $this->service()->widgetFigures('adm-2', $now);
		$none = $this->service()->widgetFigures('adm-9', $now);

		self::assertSame(['c1', 'c2', 'c3', ''], array_slice(array_column($one['topCustomers'], 'customerId'), 0, 4));
		self::assertSame(1000.0, $one['topCustomers'][0]['revenue']);
		self::assertSame(2000.0, $two['topCustomers'][0]['revenue']);
		self::assertSame([], $none['topCustomers']);
		self::assertSame(0, $none['receivables']['count']);
		self::assertSame(0.0, $this->service()->cashPosition('adm-9')['total']);

	}//end testWidgetAndCashFiguresAreScopedToOneAdministration()
}//end class
