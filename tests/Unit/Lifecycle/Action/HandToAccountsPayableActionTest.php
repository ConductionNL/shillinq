<?php

/**
 * Unit tests for HandToAccountsPayableAction.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/purchasing-supplier-invoice-intake/specs/bookkeeping-purchase-order-3way/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Lifecycle\Action\HandToAccountsPayableAction;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\Purchasing\SupplierInvoiceChecks;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Bank\LifecycleFaithfulTransitionEngine;
use OCA\Shillinq\Tests\Unit\Service\Purchasing\SupplierInvoiceChecksTest;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * REQ-PSII-002 and REQ-PSII-004: the hand-over to accounts payable.
 */
class HandToAccountsPayableActionTest extends TestCase {

	/**
	 * Everything written.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The engine that ran the AP transitions.
	 *
	 * @var LifecycleFaithfulTransitionEngine
	 */
	private LifecycleFaithfulTransitionEngine $engine;

	/**
	 * The action over the checks fixture and a declared-lifecycle engine.
	 *
	 * @return HandToAccountsPayableAction
	 */
	private function action(): HandToAccountsPayableAction {
		$this->saved = [];
		$store = new InMemoryObjectServiceStub(SupplierInvoiceChecksTest::records(), $this->saved);
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->engine = new LifecycleFaithfulTransitionEngine(store: $store, schemas: ['APTransaction']);
		$container = $this->createStub(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => $id === ObjectTransitionRunner::ENGINE_CLASS);
		$container->method('get')->willReturn($this->engine);

		return new HandToAccountsPayableAction($store, $settings, new SupplierInvoiceChecks($store, $settings), new ObjectTransitionRunner(container: $container));

	}//end action()

	/**
	 * Invoice 2026-0457: EUR 1,000.00 plus EUR 210.00 VAT on 4300.
	 *
	 * @return array<string, mixed>
	 */
	private function invoice(): array {
		return [
			'id' => 'si-0457', 'invoiceNumber' => '2026-0457', 'supplierId' => SupplierInvoiceChecksTest::DRUKKERIJ,
			'administrationId' => SupplierInvoiceChecksTest::ADMIN, 'statusCode' => 'approved', 'invoiceDate' => '2026-09-01',
			'currency' => 'EUR', 'totalExclVat' => 100000, 'totalVat' => 21000, 'totalInclVat' => 121000,
			'ublSourceUri' => 'peppol:msg-1',
			'lines' => [['lineNumber' => 1, 'description' => 'Folders', 'quantity' => 1, 'unitPrice' => 100000, 'lineExtension' => 100000, 'vatRate' => 21]],
		];

	}//end invoice()

	/**
	 * The AP transaction is written in euros, on the payee's default account, received and issued.
	 *
	 * @return void
	 */
	public function testTheInvoiceBecomesAnIssuedApTransaction(): void {
		$result = $this->action()->execute($this->invoice(), [], [], 'hand-to-accounts-payable');

		$written = array_values(array_filter($this->saved, static fn (array $s): bool => $s['schema'] === 'APTransaction'));
		$first = $written[0]['object'];
		$this->assertSame(1210.0, $first['totalAmount']);
		$this->assertSame(210.0, $first['taxAmount']);
		$this->assertSame('4300', $first['lines'][0]['accountNumber']);
		$this->assertSame(1000.0, $first['lines'][0]['amount']);
		$this->assertSame('2026-10-01', $first['dueDate']);
		$this->assertArrayNotHasKey('paymentBlocked', $first);
		$this->assertSame(['receive', 'issue'], array_column($this->engine->ran, 'action'));
		$this->assertSame('issued', end($this->engine->ran)['to']);
		$this->assertSame($first['id'], $result['apTransactionId']);

		unset($first['id']);
		$this->assertSame([], RegisterSchema::errors('APTransaction', $first));

	}//end testTheInvoiceBecomesAnIssuedApTransaction()

	/**
	 * An IBAN mismatch writes the AP transaction payment blocked with the reason.
	 *
	 * @return void
	 */
	public function testAnIbanMismatchBlocksPayment(): void {
		$invoice = $this->invoice();
		$invoice['ibanMismatch'] = 'NL02ABNA0123456789 / NL20INGB0001234567';

		$this->action()->execute($invoice, [], [], 'hand-to-accounts-payable');

		$ap = array_values(array_filter($this->saved, static fn (array $s): bool => $s['schema'] === 'APTransaction'))[0]['object'];
		$this->assertTrue($ap['paymentBlocked']);
		$this->assertSame(HandToAccountsPayableAction::IBAN_BLOCK_REASON, $ap['paymentBlockReason']);

	}//end testAnIbanMismatchBlocksPayment()

	/**
	 * A second run writes nothing new.
	 *
	 * @return void
	 */
	public function testItHandsOverOnce(): void {
		$invoice = $this->invoice();
		$invoice['apTransactionId'] = 'ap-existing';

		$this->assertSame('ap-existing', $this->action()->execute($invoice, [], [], 'hand-to-accounts-payable')['apTransactionId']);
		$this->assertSame([], $this->saved);

	}//end testItHandsOverOnce()
}//end class
