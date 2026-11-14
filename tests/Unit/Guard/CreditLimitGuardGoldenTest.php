<?php

/**
 * Golden tests for CreditLimitGuard: queries, paging and log lines.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\CreditLimitGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the exact queries, the paging, the self-exclusion and every log line of
 * CreditLimitGuard::requireWithinCreditLimit() before it is split into named steps.
 */
final class CreditLimitGuardGoldenTest extends TestCase {
	/**
	 * Recorded calls, in order.
	 *
	 * @var array<int, string>
	 */
	public array $calls = [];

	/**
	 * Build the guard over customers and invoice pages.
	 *
	 * @param array<int, array<string,mixed>>             $customers The customers findAll returns.
	 * @param array<int, mixed>                           $pages     The ARInvoice pages, in order ('throw' to fail).
	 *
	 * @return CreditLimitGuard
	 */
	private function guard(array $customers, array $pages): CreditLimitGuard {
		$test = $this;
		$store = new class($test, $customers, $pages) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param CreditLimitGuardGoldenTest                  $test      The test.
			 * @param array<int, array<string,mixed>>             $customers Customers.
			 * @param array<int, array<int, array<string,mixed>>> $pages     Invoice pages.
			 */
			public function __construct(
				private $test,
				private array $customers,
				private array $pages,
			) {
			}//end __construct()

			public function setRegister(string $r): self {
				$this->test->calls[] = 'register:' . $r;
				return $this;
			}//end setRegister()

			public function setSchema(string $s): self {
				$this->schema = $s;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $query The query.
			 *
			 * @return array<int, array<string,mixed>>
			 */
			public function findAll(array $query): array {
				$this->test->calls[] = 'findAll:' . $this->schema . ':' . json_encode($query);
				if ($this->schema === 'CustomerMaster') {
					return $this->customers;
				}

				$next = array_shift($this->pages);
				if ($next === 'throw') {
					throw new \RuntimeException('store down');
				}

				return ($next ?? []);
			}//end findAll()
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('books');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = 'error:' . $message . json_encode($context);
			}
		);

		return new CreditLimitGuard(appConfig: $appConfig, logger: $logger, objectService: new DuckObjectServiceAdapter($store));
	}//end guard()

	/**
	 * A full page of 500 is followed by the next page; the excluded invoice and closed statuses do not count.
	 *
	 * @return void
	 */
	public function testPagesAndExcludesItself(): void {
		$full = array_fill(0, 500, ['status' => 'issued', 'totalCents' => 1, 'invoiceNumber' => 'other']);
		$full[0] = ['status' => 'issued', 'totalCents' => 1000, 'invoiceNumber' => 'INV-1'];
		$last = [['status' => 'paid', 'totalCents' => 50], ['status' => 'overdue', 'totalCents' => 1]];
		$invoice = ['customerNumber' => 'C1', 'administrationId' => 'A1', 'invoiceNumber' => 'INV-1', 'totalCents' => 100];

		$this->assertTrue($this->guard([['creditLimitCents' => '600']], [$full, $last])->requireWithinCreditLimit($invoice));
		$this->assertSame(
			[
				'register:books',
				'findAll:CustomerMaster:{"filters":{"customerNumber":"C1","administrationId":"A1"},"limit":1}',
				'register:books',
				'findAll:ARInvoice:{"filters":{"customerNumber":"C1","administrationId":"A1"},"limit":500,"offset":0}',
				'register:books',
				'findAll:ARInvoice:{"filters":{"customerNumber":"C1","administrationId":"A1"},"limit":500,"offset":500}',
			],
			$this->calls
		);

		$this->assertFalse($this->guard([['creditLimitCents' => 599]], [$full, $last])->requireWithinCreditLimit($invoice));
	}//end testPagesAndExcludesItself()

	/**
	 * An invoice without a number excludes nothing, so a blank-numbered outstanding invoice counts.
	 *
	 * @return void
	 */
	public function testBlankInvoiceNumberExcludesNothing(): void {
		$invoice = ['customerNumber' => 'C1', 'administrationId' => 'A1', 'totalCents' => 10];
		$pages = [[['status' => 'draft', 'totalCents' => 91], ['status' => 'disputed', 'totalCents' => 0, 'invoiceNumber' => '']]];

		$this->assertFalse($this->guard([['creditLimitCents' => 100]], $pages)->requireWithinCreditLimit($invoice));
		$this->assertTrue($this->guard([['creditLimitCents' => 101]], $pages)->requireWithinCreditLimit($invoice));
	}//end testBlankInvoiceNumberExcludesNothing()

	/**
	 * No limit permits without reading invoices.
	 *
	 * @return void
	 */
	public function testNoLimitReadsNoInvoices(): void {
		$invoice = ['customerNumber' => 'C1', 'administrationId' => 'A1', 'totalCents' => 10 ** 9];

		$this->assertTrue($this->guard([['creditLimitCents' => null]], [])->requireWithinCreditLimit($invoice));
		$this->assertCount(2, $this->calls);
	}//end testNoLimitReadsNoInvoices()

	/**
	 * Each denial logs its own line.
	 *
	 * @return void
	 */
	public function testDenialLogLines(): void {
		$this->assertFalse($this->guard([], [])->requireWithinCreditLimit(['customerNumber' => 'C1', 'invoiceNumber' => 'INV-9']));
		$this->assertFalse($this->guard([], [])->requireWithinCreditLimit(['customerNumber' => 'C1', 'administrationId' => 'A1']));
		$this->assertFalse($this->guard([['creditLimitCents' => 5]], ['throw'])->requireWithinCreditLimit(['customerNumber' => 'C1', 'administrationId' => 'A1']));

		$errors = array_values(array_filter($this->calls, static fn (string $c): bool => str_starts_with($c, 'error:')));
		$this->assertSame(
			[
				'error:CreditLimitGuard: invoice missing customerNumber or administrationId — denying issue (fail-closed){"invoiceNumber":"INV-9"}',
				'error:CreditLimitGuard: customer not found — denying issue (fail-closed){"customerNumber":"C1","administrationId":"A1"}',
				'error:CreditLimitGuard: credit-limit computation failed — denying issue (fail-closed){"exception":"store down"}',
			],
			$errors
		);
	}//end testDenialLogLines()
}//end class
