<?php

/**
 * Tests for the quick draft of a sales invoice.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service\Sales
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

namespace OCA\Shillinq\Tests\Unit\Service\Sales;

use OCA\Shillinq\Service\Sales\QuickDraftInvoiceService;
use OCA\Shillinq\Service\Sales\QuickDraftRefusedException;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The server numbers, totals and stamps the draft; the browser only sends
 * the customer, dates and lines.
 */
final class QuickDraftInvoiceServiceTest extends TestCase {

	/**
	 * The object store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The random suffixes handed out, in order.
	 *
	 * @var list<string>
	 */
	private array $suffixes = ['a1b2c3d4'];

	/**
	 * The service over a store with one customer in each of two administrations.
	 *
	 * @param list<array<string,mixed>> $invoices ARInvoices already present.
	 *
	 * @return QuickDraftInvoiceService
	 */
	private function service(array $invoices=[]): QuickDraftInvoiceService {
		$this->store = new InMemoryObjectServiceStub(
			data: [
				'CustomerMaster' => [
					['id' => 'uuid-a', 'customerId' => 'cust-a', 'administrationId' => 'adm-1', 'paymentTerms' => 'net14'],
					['id' => 'uuid-x', 'customerId' => 'cust-x', 'administrationId' => 'adm-2'],
				],
				'ARInvoice' => $invoices,
			]
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(fn (): string => (string)array_shift($this->suffixes));

		return new QuickDraftInvoiceService(objectService: $this->store, settings: $settings, random: $random);
	}//end service()

	/**
	 * A valid request.
	 *
	 * @param array<string,mixed> $overrides Fields to replace.
	 *
	 * @return array<string,mixed>
	 */
	private static function request(array $overrides=[]): array {
		return array_merge(
			[
				'customerId' => 'cust-a',
				'invoiceDate' => '2026-10-21',
				'reference' => 'PO-7',
				'glAccount' => '8000',
				'lines' => [
					['description' => 'Advies', 'quantity' => 3, 'unitPrice' => 33.33, 'vatRate' => 21],
					['description' => 'Boek', 'quantity' => 1, 'unitPrice' => 10, 'vatRate' => 9, 'glAccount' => '8100'],
					['description' => '', 'quantity' => 1, 'unitPrice' => 0, 'vatRate' => 21],
				],
			],
			$overrides
		);
	}//end request()

	/**
	 * The server computes the totals and ignores totals, number, state and
	 * administration sent by the browser.
	 *
	 * @return void
	 */
	public function testTheServerTotalsNumbersAndStampsTheDraft(): void {
		$invoice = $this->service()->draft(
			administrationId: 'adm-1',
			request: self::request(
				[
					'netAmount' => 1,
					'vatAmount' => 1,
					'grossAmount' => 1,
					'invoiceNumber' => 'MINE-1',
					'lifecycleState' => 'paid',
					'administrationId' => 'adm-2',
				]
			)
		);

		$this->assertSame('adm-1', $invoice['administrationId']);
		$this->assertSame('draft', $invoice['lifecycleState']);
		$this->assertSame('DRAFT-20261021-a1b2c3d4', $invoice['invoiceNumber']);
		$this->assertSame(109.99, $invoice['netAmount']);
		$this->assertSame(21.9, $invoice['vatAmount']);
		$this->assertSame(131.89, $invoice['grossAmount']);
		$this->assertSame('2026-10', $invoice['periodId']);
		$this->assertSame('PO-7', $invoice['customerReference']);
		$this->assertCount(2, $invoice['invoiceLines']);
		$this->assertSame(99.99, $invoice['invoiceLines'][0]['netAmount']);
		$this->assertSame('8000', $invoice['invoiceLines'][0]['glAccount']);
		$this->assertSame('8100', $invoice['invoiceLines'][1]['glAccount']);
		$this->assertSame('obj-1', $invoice['id']);
		$this->assertCount(1, $this->store->saved);
		$this->assertSame('adm-1', $this->store->saved[0]['object']['administrationId']);
	}//end testTheServerTotalsNumbersAndStampsTheDraft()

	/**
	 * Without a due date the customer's payment terms set it.
	 *
	 * @return void
	 */
	public function testTheDueDateFollowsTheCustomersTerms(): void {
		$invoice = $this->service()->draft(administrationId: 'adm-1', request: self::request());
		$this->assertSame('2026-11-04', $invoice['dueDate']);

		$given = $this->service()->draft(administrationId: 'adm-1', request: self::request(['dueDate' => '2026-12-01']));
		$this->assertSame('2026-12-01', $given['dueDate']);
	}//end testTheDueDateFollowsTheCustomersTerms()

	/**
	 * A number already used in the administration is not reused.
	 *
	 * @return void
	 */
	public function testATakenNumberIsNotReused(): void {
		$this->suffixes = ['a1b2c3d4', 'e5f6a7b8'];
		$invoice = $this->service(
			[['id' => 'inv-1', 'administrationId' => 'adm-1', 'invoiceNumber' => 'DRAFT-20261021-a1b2c3d4']]
		)->draft(administrationId: 'adm-1', request: self::request());

		$this->assertSame('DRAFT-20261021-e5f6a7b8', $invoice['invoiceNumber']);
	}//end testATakenNumberIsNotReused()

	/**
	 * OpenRegister drops an undeclared property, so every key the draft
	 * writes, and every key of its lines, is declared on ARInvoice.
	 *
	 * @return void
	 */
	public function testEveryWrittenKeyIsDeclared(): void {
		$root = dirname(__DIR__, 4) . '/lib/Settings';
		$files = glob($root . '/register.d/*.json');
		sort($files);
		$declared = [];
		$lineDeclared = [];
		foreach (array_merge([$root . '/shillinq_register.json'], $files) as $file) {
			$schema = (json_decode((string)file_get_contents($file), true)['components']['schemas']['ARInvoice'] ?? []);
			$declared = array_merge($declared, array_keys($schema['properties'] ?? []));
			$lineDeclared = array_merge($lineDeclared, array_keys($schema['properties']['invoiceLines']['items']['properties'] ?? []));
		}

		$this->service()->draft(administrationId: 'adm-1', request: self::request());
		$written = $this->store->saved[0]['object'];
		unset($written['id']);

		$this->assertSame([], array_values(array_diff(array_keys($written), $declared)));
		$this->assertSame([], array_values(array_diff(array_keys($written['invoiceLines'][0]), $lineDeclared)));
	}//end testEveryWrittenKeyIsDeclared()

	/**
	 * A customer of another administration is refused and nothing is saved.
	 *
	 * @return void
	 */
	public function testACustomerOfAnotherAdministrationIsRefused(): void {
		$service = $this->service();
		try {
			$service->draft(administrationId: 'adm-1', request: self::request(['customerId' => 'cust-x']));
			$this->fail('A foreign customer must be refused.');
		} catch (QuickDraftRefusedException $e) {
			$this->assertSame([], $this->store->saved);
		}
	}//end testACustomerOfAnotherAdministrationIsRefused()

	/**
	 * Refusals: no administration, no line with an amount, a bad VAT rate,
	 * a bad invoice date.
	 *
	 * @return void
	 */
	public function testInvalidRequestsAreRefused(): void {
		$cases = [
			['', self::request()],
			['adm-1', self::request(['lines' => [['description' => '', 'quantity' => 1, 'unitPrice' => 0, 'vatRate' => 21]]])],
			['adm-1', self::request(['lines' => [['description' => 'X', 'quantity' => 1, 'unitPrice' => 5, 'vatRate' => 150]]])],
			['adm-1', self::request(['invoiceDate' => 'tomorrow'])],
		];
		foreach ($cases as $index => [$administrationId, $request]) {
			$service = $this->service();
			try {
				$service->draft(administrationId: $administrationId, request: $request);
				$this->fail('Case ' . $index . ' must be refused.');
			} catch (QuickDraftRefusedException $e) {
				$this->assertSame([], $this->store->saved, 'Case ' . $index . ' saves nothing.');
			}
		}
	}//end testInvalidRequestsAreRefused()
}//end class
