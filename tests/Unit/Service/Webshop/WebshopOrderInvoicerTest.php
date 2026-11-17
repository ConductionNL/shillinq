<?php

/**
 * Unit tests for WebshopOrderInvoicer and WebshopOrderListener.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Webshop
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Webshop;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Kernel\Contracts\ViesServiceInterface;
use OCA\Shillinq\Listener\WebshopOrderListener;
use OCA\Shillinq\Service\OssInvoiceRouter;
use OCA\Shillinq\Service\OssRateResolver;
use OCA\Shillinq\Service\Webshop\WebshopOrderInvoicer;
use OCA\Shillinq\Service\Webshop\WebshopVatRoute;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * A received web shop order becomes one invoice, once (REQ-SWO-001), on the right debtor (REQ-SWO-002).
 */
#[CoversClass(WebshopOrderInvoicer::class)]
#[CoversClass(WebshopOrderListener::class)]
#[UsesClass(ObjectIdentifier::class)]
#[UsesClass(WebshopVatRoute::class)]
#[UsesClass(OssInvoiceRouter::class)]
#[UsesClass(OssRateResolver::class)]
final class WebshopOrderInvoicerTest extends TestCase {

	private const CHANNEL = 'c0ffee00-0000-4000-8000-00000000c001';

	private const ORDER = 'c0ffee00-0000-4000-8000-00000000a001';

	/**
	 * Rows findAll() answers per schema.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $store = [];

	/**
	 * Captured saveObject calls as [schema, object, uuid].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>,2:?string}>
	 */
	private array $saves = [];

	/**
	 * Transitions run as [objectId, action].
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private array $transitionsRun = [];

	/**
	 * The schema the object service is scoped to.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * The VAT ids VIES confirms.
	 *
	 * @var array<int,string>
	 */
	private array $validVatIds = [];

	/**
	 * Seed the channel of theehandelvandijk.nl.
	 *
	 * @param array<string,mixed> $overrides Channel fields to change.
	 *
	 * @return void
	 */
	private function seedChannel(array $overrides = []): void {
		$this->store['WebshopChannel'] = [$overrides + [
			'id' => self::CHANNEL,
			'name' => 'theehandelvandijk.nl',
			'administrationId' => 'adm-1',
			'consumerDebtorId' => 'cm-consumers',
			'revenueAccount' => '8000',
			'shippingAccount' => '8100',
			'clearingAccount' => '1290',
			'issueMode' => 'auto-issue',
		]];
	}//end seedChannel()

	/**
	 * Order 100231 from J. Bakker: 2 x Earl Grey los 250 g at EUR 8.95 and EUR 4.95 shipping, prices including 9 percent VAT.
	 *
	 * @param array<string,mixed> $overrides Order fields to change.
	 *
	 * @return array<string,mixed>
	 */
	private static function order(array $overrides = []): array {
		return $overrides + [
			'channelId' => self::CHANNEL,
			'shopOrderId' => '100231',
			'orderedAt' => '2026-11-12T10:00:00+01:00',
			'buyer' => ['type' => 'consumer', 'name' => 'J. Bakker', 'email' => 'j.bakker@example.nl', 'countryCode' => 'NL'],
			'currency' => 'EUR',
			'pricesIncludeVat' => true,
			'lines' => [['sku' => 'EG-250', 'description' => 'Earl Grey los 250 g', 'quantity' => 2, 'unitPrice' => 8.95, 'vatRate' => 9]],
			'shipping' => 4.95,
			'totals' => ['net' => 20.96, 'vat' => 1.89, 'gross' => 22.85],
			'payment' => ['status' => 'paid', 'method' => 'ideal', 'providerPaymentId' => 'tr_7Qw2'],
			'intakeState' => 'received',
		];
	}//end order()

	/**
	 * The invoicer over the in-memory store.
	 *
	 * @return WebshopOrderInvoicer
	 */
	private function invoicer(): WebshopOrderInvoicer {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			function (string|int $schema) use ($objectService): ObjectServiceInterface {
				$this->schema = (string)$schema;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(fn (array $config = []): array => ($this->store[$this->schema] ?? []));
		$objectService->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntityInterface {
				foreach (($this->store[$this->schema] ?? []) as $row) {
					if (($row['id'] ?? '') === (string)$id) {
						$entity = $this->createStub(ObjectEntityInterface::class);
						$entity->method('getObject')->willReturn($row);
						return $entity;
					}
				}

				return null;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntity {
				$extend = $options->extend ?? [];
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->saves[] = [(string)$schema, $object, $uuid];
				$saved = new ObjectEntity();
				$saved->setObject(['id' => ($uuid ?? strtolower((string)$schema) . '-' . count($this->saves))] + $object);
				return $saved;
			}
		);

		$transitions = $this->createMock(ObjectTransitionRunnerInterface::class);
		$transitions->method('run')->willReturnCallback(
			function (string $objectId, string $action, array $data = []): void {
				$this->transitionsRun[] = [$objectId, $action];
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'register' ? 'shillinq' : $default)
		);

		return new WebshopOrderInvoicer(
			objectService: $objectService,
			transitions: $transitions,
			appConfig: $config,
			logger: $this->createStub(LoggerInterface::class),
			vatRoute: $this->vatRoute(objectService: $objectService, config: $config),
		);
	}//end invoicer()

	/**
	 * The real VAT route over the store's EuVatRate rows and a VIES that confirms $validVatIds.
	 *
	 * @param ObjectServiceInterface $objectService The object service over the store.
	 * @param IAppConfig             $config        The app config.
	 *
	 * @return WebshopVatRoute
	 */
	private function vatRoute(ObjectServiceInterface $objectService, IAppConfig $config): WebshopVatRoute {
		$vies = $this->createMock(ViesServiceInterface::class);
		$vies->method('validate')->willReturnCallback(
			fn (string $administrationId, string $vatId, string $now = ''): array => ['vatId' => $vatId, 'valid' => in_array($vatId, $this->validVatIds, true)]
		);
		$rates = new OssRateResolver(appConfig: $config, objectService: $objectService);

		return new WebshopVatRoute(router: new OssInvoiceRouter(rateResolver: $rates), rates: $rates, vies: $vies);
	}//end vatRoute()

	/**
	 * The saves of one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int,array{0:string,1:array<string,mixed>,2:?string}>
	 */
	private function savesOf(string $schema): array {
		return array_values(array_filter($this->saves, static fn (array $save): bool => $save[0] === $schema));
	}//end savesOf()

	/**
	 * The spec scenario: the tea order becomes an issued invoice for the collective debtor, totalling EUR 22.85.
	 *
	 * @return void
	 */
	public function testATeaOrderBecomesAnIssuedInvoice(): void {
		$this->seedChannel();

		$outcome = $this->invoicer()->handle(order: self::order(), orderId: self::ORDER, today: '2026-11-12');

		self::assertSame('invoiced', $outcome);
		$invoices = $this->savesOf(schema: 'ARInvoice');
		self::assertCount(1, $invoices);
		$invoice = $invoices[0][1];
		self::assertSame(['cm-consumers', 'J. Bakker', 'issued', 'adm-1', '2026-11-26'], [$invoice['customerId'], $invoice['buyerName'], $invoice['lifecycleState'], $invoice['administrationId'], $invoice['dueDate']]);
		self::assertSame([20.96, 1.89, 22.85], [$invoice['netAmount'], $invoice['vatAmount'], $invoice['grossAmount']]);
		self::assertSame(['Earl Grey los 250 g', 'Shipping'], array_column($invoice['invoiceLines'], 'itemName'));
		self::assertSame([16.42, 4.54], array_column($invoice['invoiceLines'], 'netAmount'));
		self::assertSame(['8000', '8100'], array_column($invoice['invoiceLines'], 'glAccount'));
		self::assertSame([9.0, 9.0], array_column($invoice['invoiceLines'], 'vatRate'));
		self::assertArrayNotHasKey('ossContext', $invoice);
		self::assertSame(
			['channelId' => self::CHANNEL, 'shopOrderId' => '100231', 'orderId' => self::ORDER, 'buyerName' => 'J. Bakker', 'paymentReference' => 'tr_7Qw2', 'vatRoute' => 'domestic'],
			$invoice['webshop']
		);

		$orders = $this->savesOf(schema: 'WebshopOrder');
		self::assertSame(self::ORDER, $orders[0][2]);
		self::assertSame('arinvoice-1', $orders[0][1]['arInvoiceId']);
		self::assertSame([[self::ORDER, 'invoice']], $this->transitionsRun);
	}//end testATeaOrderBecomesAnIssuedInvoice()

	/**
	 * The same order sent twice: no second invoice, the second record is a duplicate.
	 *
	 * @return void
	 */
	public function testTheSameOrderSentTwiceIsADuplicate(): void {
		$this->seedChannel();
		$this->store['WebshopOrder'] = [
			self::order(overrides: ['id' => self::ORDER, 'intakeState' => 'invoiced', 'arInvoiceId' => 'inv-1']),
			self::order(overrides: ['id' => 'second']),
		];

		$outcome = $this->invoicer()->handle(order: self::order(), orderId: 'second', today: '2026-11-12');

		self::assertSame('duplicate', $outcome);
		self::assertSame([], $this->saves);
		self::assertSame([['second', 'markDuplicate']], $this->transitionsRun);
	}//end testTheSameOrderSentTwiceIsADuplicate()

	/**
	 * Another shop's order with the same number, or the order's own record, is no duplicate; draft-for-review leaves a draft.
	 *
	 * @return void
	 */
	public function testOnlyTheSameChannelAndNumberIsADuplicate(): void {
		$this->seedChannel(overrides: ['issueMode' => 'draft-for-review']);
		$this->store['WebshopOrder'] = [
			self::order(overrides: ['id' => 'other-shop', 'channelId' => 'another', 'arInvoiceId' => 'inv-9']),
			self::order(overrides: ['id' => self::ORDER]),
		];

		self::assertSame('invoiced', $this->invoicer()->handle(order: self::order(), orderId: self::ORDER, today: '2026-11-12'));
		self::assertSame('draft', $this->savesOf(schema: 'ARInvoice')[0][1]['lifecycleState']);
	}//end testOnlyTheSameChannelAndNumberIsADuplicate()

	/**
	 * Unknown channels, empty orders, buyers without a VAT route, wrong totals and a missing collective debtor are refused with the reason.
	 *
	 * @return void
	 */
	public function testAnOrderThatCannotBeInvoicedIsRefused(): void {
		$cases = [
			'The order names a web shop channel shillinq does not know.' => [self::order(overrides: ['channelId' => 'unknown']), []],
			'The order has no lines.' => [self::order(overrides: ['lines' => []]), []],
			'The buyer is in US; shillinq has no VAT route for a sale outside the EU.' => [self::order(overrides: ['buyer' => ['type' => 'consumer', 'name' => 'A', 'countryCode' => 'us']]), []],
			'No BE VAT rate of category reduced1 is in force on 2026-11-12.' => [self::order(overrides: ['buyer' => ['type' => 'consumer', 'name' => 'A', 'countryCode' => 'BE']]), []],
			'The order names another administration than its web shop channel.' => [self::order(overrides: ['administrationId' => 'adm-2']), []],
			'The order lines add up to 22.85 but the order total is 23.85.' => [self::order(overrides: ['totals' => ['gross' => 23.85]]), []],
			'The web shop channel has no collective debtor for consumer orders.' => [self::order(), ['consumerDebtorId' => '']],
		];
		foreach ($cases as $reason => [$order, $channel]) {
			$this->saves = [];
			$this->transitionsRun = [];
			$this->seedChannel(overrides: $channel);

			self::assertSame('refused', $this->invoicer()->handle(order: $order, orderId: self::ORDER, today: '2026-11-12'), $reason);
			self::assertSame([], $this->savesOf(schema: 'ARInvoice'), $reason);
			self::assertSame($reason, $this->savesOf(schema: 'WebshopOrder')[0][1]['refusalReason']);
			self::assertSame([[self::ORDER, 'refuse']], $this->transitionsRun, $reason);
		}
	}//end testAnOrderThatCannotBeInvoicedIsRefused()

	/**
	 * An order that is invoiced, refused or not received is left alone.
	 *
	 * @return void
	 */
	public function testAnOrderThatDoesNotWaitIsSkipped(): void {
		$this->seedChannel();
		$invoicer = $this->invoicer();
		foreach ([['intakeState' => 'invoiced'], ['arInvoiceId' => 'inv-1'], ['refusalReason' => 'x'], ['intakeState' => 'duplicate']] as $state) {
			self::assertSame('skipped', $invoicer->handle(order: self::order(overrides: $state), orderId: self::ORDER, today: '2026-11-12'));
		}

		self::assertSame('skipped', $invoicer->handle(order: self::order(), orderId: '', today: '2026-11-12'));
		self::assertSame([], $this->saves);
		self::assertSame([], $this->transitionsRun);
	}//end testAnOrderThatDoesNotWaitIsSkipped()

	/**
	 * A returning business buyer is matched by VAT id before KvK number and email; prices without VAT get VAT added.
	 *
	 * @return void
	 */
	public function testABusinessBuyerIsMatchedByVatIdFirst(): void {
		$this->seedChannel();
		$this->store['CustomerMaster'] = [
			['id' => 'cm-email', 'administrationId' => 'adm-1', 'email' => 'inkoop@hotel.nl'],
			['id' => 'cm-other-admin', 'administrationId' => 'adm-2', 'vatId' => 'NL001234567B01'],
			['id' => 'cm-vat', 'administrationId' => 'adm-1', 'vatId' => 'NL 0012.34567.B01'],
		];
		$order = self::order(overrides: [
			'buyer' => ['type' => 'business', 'name' => 'P. de Vries', 'companyName' => 'Hotel De Linde', 'vatId' => 'NL001234567B01', 'email' => 'inkoop@hotel.nl', 'countryCode' => 'NL'],
			'pricesIncludeVat' => false,
			'lines' => [['description' => 'Rooibos 1 kg', 'quantity' => 3, 'unitPrice' => 10.0, 'vatRate' => 9]],
			'shipping' => 0,
			'totals' => ['gross' => 32.70],
		]);

		self::assertSame('invoiced', $this->invoicer()->handle(order: $order, orderId: self::ORDER, today: '2026-11-12'));

		$invoice = $this->savesOf(schema: 'ARInvoice')[0][1];
		self::assertSame('cm-vat', $invoice['customerId']);
		self::assertArrayNotHasKey('buyerName', $invoice);
		self::assertSame([30.0, 2.7, 32.7], [$invoice['netAmount'], $invoice['vatAmount'], $invoice['grossAmount']]);
		self::assertSame([], $this->savesOf(schema: 'CustomerMaster'));
	}//end testABusinessBuyerIsMatchedByVatIdFirst()

	/**
	 * A business buyer without a match becomes a new customer of the channel's administration.
	 *
	 * @return void
	 */
	public function testANewBusinessBuyerIsCreated(): void {
		$this->seedChannel();
		$this->store['CustomerMaster'] = [['id' => 'cm-1', 'administrationId' => 'adm-1', 'email' => 'someone@else.nl']];
		$order = self::order(overrides: ['buyer' => ['type' => 'business', 'name' => 'P. de Vries', 'companyName' => 'Hotel De Linde', 'kvkNumber' => '12345678', 'email' => 'inkoop@hotel.nl']]);

		$this->invoicer()->handle(order: $order, orderId: self::ORDER, today: '2026-11-12');

		$created = $this->savesOf(schema: 'CustomerMaster');
		self::assertCount(1, $created);
		self::assertSame(['Hotel De Linde', 'inkoop@hotel.nl', 'adm-1', 'active', '12345678'], [$created[0][1]['legalName'], $created[0][1]['email'], $created[0][1]['administrationId'], $created[0][1]['lifecycleState'], $created[0][1]['kvkNumber']]);
		self::assertStringStartsWith('WEB-', $created[0][1]['customerId']);
		self::assertSame(self::CHANNEL, $created[0][1]['createdByWebshopChannelId']);
		self::assertSame('customermaster-1', $this->savesOf(schema: 'ARInvoice')[0][1]['customerId']);
	}//end testANewBusinessBuyerIsCreated()

	/**
	 * A business buyer without a VAT id is matched by KvK number before email,
	 * ignoring spaces and dots, within the channel's administration only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.2
	 */
	public function testABusinessBuyerWithoutAVatIdIsMatchedByKvkNumber(): void {
		$this->seedChannel();
		$this->store['CustomerMaster'] = [
			['id' => 'cm-email', 'administrationId' => 'adm-1', 'email' => 'inkoop@hotel.nl'],
			['id' => 'cm-kvk-other-admin', 'administrationId' => 'adm-2', 'kvkNumber' => '12345678'],
			['id' => 'cm-kvk', 'administrationId' => 'adm-1', 'kvkNumber' => '1234 5678'],
		];
		$order = self::order(overrides: ['buyer' => ['type' => 'business', 'companyName' => 'Hotel De Linde', 'kvkNumber' => '12345678', 'email' => 'inkoop@hotel.nl']]);

		self::assertSame('invoiced', $this->invoicer()->handle(order: $order, orderId: self::ORDER, today: '2026-11-12'));

		self::assertSame('cm-kvk', $this->savesOf(schema: 'ARInvoice')[0][1]['customerId']);
		self::assertSame([], $this->savesOf(schema: 'CustomerMaster'));
	}//end testABusinessBuyerWithoutAVatIdIsMatchedByKvkNumber()

	/**
	 * A business buyer with only an email is matched by it, whatever its case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.2
	 */
	public function testABusinessBuyerWithOnlyAnEmailIsMatchedByIt(): void {
		$this->seedChannel();
		$this->store['CustomerMaster'] = [
			['id' => 'cm-kvk', 'administrationId' => 'adm-1', 'kvkNumber' => '87654321'],
			['id' => 'cm-email', 'administrationId' => 'adm-1', 'email' => 'Inkoop@Hotel.nl'],
		];
		$order = self::order(overrides: ['buyer' => ['type' => 'business', 'companyName' => 'Hotel De Linde', 'kvkNumber' => '12345678', 'email' => 'inkoop@hotel.nl']]);

		$this->invoicer()->handle(order: $order, orderId: self::ORDER, today: '2026-11-12');

		self::assertSame('cm-email', $this->savesOf(schema: 'ARInvoice')[0][1]['customerId']);
		self::assertSame([], $this->savesOf(schema: 'CustomerMaster'));
	}//end testABusinessBuyerWithOnlyAnEmailIsMatchedByIt()

	/**
	 * An invoiced order with its issued invoice, as the store holds them.
	 *
	 * @param array<string,mixed> $invoice Invoice fields to change.
	 *
	 * @return array<string,mixed> The order.
	 */
	private function invoicedOrder(array $invoice = []): array {
		$this->seedChannel();
		$this->store['ARInvoice'] = [$invoice + [
			'id' => 'inv-1', 'invoiceNumber' => 'WEB-100231', 'invoiceDate' => '2026-11-12', 'administrationId' => 'adm-1',
			'grossAmount' => 22.85, 'lifecycleState' => 'issued',
			'webshop' => ['channelId' => self::CHANNEL, 'shopOrderId' => '100231', 'orderId' => self::ORDER],
		]];

		return self::order(overrides: ['intakeState' => 'invoiced', 'arInvoiceId' => 'inv-1']);
	}//end invoicedOrder()

	/**
	 * REQ-SWO-004: a paid order's invoice is settled on the channel's clearing
	 * account through a posted receipt, keeps the provider's payment id and
	 * is marked paid; the order keeps the receipt so it is settled once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.4
	 */
	public function testAPaidOrderIsSettledOnTheClearingAccount(): void {
		$order = $this->invoicedOrder();

		self::assertSame('settled', $this->invoicer()->settle(order: $order, orderId: self::ORDER));

		$journal = $this->savesOf(schema: 'JournalEntry')[0][1];
		self::assertSame(
			[['1290', 'debit', 22.85], ['1100', 'credit', 22.85]],
			array_map(static fn (array $l): array => [$l['accountNumber'], $l['side'], $l['amount']], $journal['lines'])
		);
		self::assertSame(['2026-11-12', 'adm-1', 'draft'], [$journal['entryDate'], $journal['administrationId'], $journal['state']]);
		self::assertSame('Web shop order 100231 paid, invoice WEB-100231, reference tr_7Qw2', $journal['description']);
		self::assertSame([['journalentry-1', 'postDirect'], ['inv-1', 'mark-paid']], $this->transitionsRun);

		$invoice = $this->savesOf(schema: 'ARInvoice')[0];
		self::assertSame('inv-1', $invoice[2]);
		self::assertSame('tr_7Qw2', $invoice[1]['webshop']['paymentReference']);
		self::assertSame(self::CHANNEL, $invoice[1]['webshop']['channelId']);
		self::assertSame('journalentry-1', $this->savesOf(schema: 'WebshopOrder')[0][1]['settlementJournalId']);
	}//end testAPaidOrderIsSettledOnTheClearingAccount()

	/**
	 * A pending order is not settled; its invoice only keeps the payment id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.4
	 */
	public function testAPendingOrderKeepsItsPaymentIdAndIsNotSettled(): void {
		$order = ['payment' => ['status' => 'pending', 'method' => 'ideal', 'providerPaymentId' => 'tr_9Xk1']] + $this->invoicedOrder();

		self::assertSame('pending', $this->invoicer()->settle(order: $order, orderId: self::ORDER));

		self::assertSame([], $this->savesOf(schema: 'JournalEntry'));
		self::assertSame([], $this->transitionsRun);
		self::assertSame('tr_9Xk1', $this->savesOf(schema: 'ARInvoice')[0][1]['webshop']['paymentReference']);
	}//end testAPendingOrderKeepsItsPaymentIdAndIsNotSettled()

	/**
	 * An order already settled, one whose invoice is still a draft, and one
	 * not invoiced are not settled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.4
	 */
	public function testOnlyAnIssuedInvoiceIsSettledAndOnlyOnce(): void {
		$order = $this->invoicedOrder(invoice: ['lifecycleState' => 'draft']);
		$invoicer = $this->invoicer();

		self::assertSame('skipped', $invoicer->settle(order: $order, orderId: self::ORDER));
		self::assertSame('skipped', $invoicer->settle(order: ['settlementJournalId' => 'je-1'] + $this->invoicedOrder(), orderId: self::ORDER));
		self::assertSame('skipped', $invoicer->settle(order: self::order(), orderId: self::ORDER));

		self::assertSame([], $this->savesOf(schema: 'JournalEntry'));
		self::assertSame([], $this->transitionsRun);
	}//end testOnlyAnIssuedInvoiceIsSettledAndOnlyOnce()

	/**
	 * The spec scenario: a consumer in Antwerpen paying prices including VAT
	 * is invoiced at the Belgian rate through OSS, for what the buyer paid,
	 * and the invoice is marked for the OSS return (REQ-SWO-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.3
	 */
	public function testABelgianConsumerIsInvoicedAtTheBelgianRateThroughOss(): void {
		$this->seedChannel();
		$this->store['EuVatRate'] = [
			['id' => 'euvat-be-reduced1-2024', 'countryCode' => 'BE', 'rateCategory' => 'reduced1', 'ratePercentage' => 6.0, 'validFrom' => '2024-01-01', 'validUntil' => null],
		];
		$order = self::order(overrides: ['shopOrderId' => '100232', 'buyer' => ['type' => 'consumer', 'name' => 'L. Peeters', 'countryCode' => 'BE']]);

		self::assertSame('invoiced', $this->invoicer()->handle(order: $order, orderId: self::ORDER, today: '2026-11-12'));

		$invoice = $this->savesOf(schema: 'ARInvoice')[0][1];
		self::assertSame([6.0, 6.0], array_column($invoice['invoiceLines'], 'vatRate'));
		self::assertSame([16.89, 4.67], array_column($invoice['invoiceLines'], 'netAmount'));
		self::assertSame([21.56, 1.29, 22.85], [$invoice['netAmount'], $invoice['vatAmount'], $invoice['grossAmount']]);
		self::assertSame(
			[
				'destinationCountry' => 'BE',
				'appliedVatRate' => 6.0,
				'appliedRateCategory' => 'reduced1',
				'tedbRateVersion' => 'euvat-be-reduced1-2024',
				'ossEligible' => true,
				'ossReportingPeriod' => '2026-Q4',
			],
			$invoice['ossContext']
		);
		self::assertSame('oss', $invoice['webshop']['vatRoute']);
	}//end testABelgianConsumerIsInvoicedAtTheBelgianRateThroughOss()

	/**
	 * A business in another member state whose VAT id VIES confirms is
	 * invoiced under reverse charge with the mention "Btw verlegd".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.3
	 */
	public function testAnEuBusinessWithAValidVatIdIsReverseCharged(): void {
		$this->seedChannel();
		$this->validVatIds = ['DE123456789'];
		$order = self::order(overrides: [
			'buyer' => ['type' => 'business', 'name' => 'K. Schmidt', 'companyName' => 'Teehaus Schmidt GmbH', 'vatId' => 'DE123456789', 'countryCode' => 'DE'],
			'pricesIncludeVat' => false,
			'lines' => [['description' => 'Darjeeling 1 kg', 'quantity' => 4, 'unitPrice' => 25.0, 'vatRate' => 9]],
			'shipping' => 0,
			'totals' => ['gross' => 100.0],
		]);

		self::assertSame('invoiced', $this->invoicer()->handle(order: $order, orderId: self::ORDER, today: '2026-11-12'));

		$invoice = $this->savesOf(schema: 'ARInvoice')[0][1];
		self::assertSame([100.0, 0.0, 100.0], [$invoice['netAmount'], $invoice['vatAmount'], $invoice['grossAmount']]);
		self::assertSame([['K', 0.0]], array_map(null, array_column($invoice['invoiceLines'], 'vatCategory'), array_column($invoice['invoiceLines'], 'vatRate')));
		self::assertSame(['icp', 'Btw verlegd'], [$invoice['webshop']['vatRoute'], $invoice['webshop']['vatMention']]);
		self::assertSame('Btw verlegd', $invoice['vatBreakdown'][0]['exemptionReasonText']);
		self::assertSame([100.0, 0.0], [$invoice['vatBreakdown'][0]['taxableAmount'], $invoice['vatBreakdown'][0]['taxAmount']]);
		self::assertArrayNotHasKey('ossContext', $invoice);
	}//end testAnEuBusinessWithAValidVatIdIsReverseCharged()

	/**
	 * A business in another member state whose VAT id VIES does not confirm
	 * pays the destination rate through OSS, like a consumer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.3
	 */
	public function testAnEuBusinessWithoutAValidVatIdPaysTheDestinationRate(): void {
		$this->seedChannel();
		$this->store['EuVatRate'] = [
			['id' => 'euvat-de-standard-2024', 'countryCode' => 'DE', 'rateCategory' => 'standard', 'ratePercentage' => 19.0, 'validFrom' => '2024-01-01', 'validUntil' => null],
		];
		$order = self::order(overrides: [
			'buyer' => ['type' => 'business', 'name' => 'K. Schmidt', 'companyName' => 'Teehaus Schmidt GmbH', 'vatId' => 'DE999999999', 'countryCode' => 'DE'],
			'pricesIncludeVat' => false,
			'lines' => [['description' => 'Teapot', 'quantity' => 1, 'unitPrice' => 50.0, 'vatRate' => 21]],
			'shipping' => 0,
			'totals' => ['gross' => 59.50],
		]);

		self::assertSame('invoiced', $this->invoicer()->handle(order: $order, orderId: self::ORDER, today: '2026-11-12'));

		$invoice = $this->savesOf(schema: 'ARInvoice')[0][1];
		self::assertSame([50.0, 9.5, 59.5], [$invoice['netAmount'], $invoice['vatAmount'], $invoice['grossAmount']]);
		self::assertSame(['DE', 'standard', 19.0], [$invoice['ossContext']['destinationCountry'], $invoice['ossContext']['appliedRateCategory'], $invoice['ossContext']['appliedVatRate']]);
	}//end testAnEuBusinessWithoutAValidVatIdPaysTheDestinationRate()

	/**
	 * The listener hands a created WebshopOrder, stamped with a numeric schema id and its uuid, to the invoicer.
	 *
	 * @return void
	 */
	public function testTheListenerInvoicesACreatedOrder(): void {
		$this->seedChannel();
		$entity = new ObjectEntity();
		$entity->setSchema('91');
		$entity->setUuid(self::ORDER);
		$entity->setObject(self::order());

		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->method('matchesSchema')->willReturnCallback(
			static fn (?object $candidate, string $expectedSlug): bool => $candidate === $entity && $expectedSlug === 'WebshopOrder'
		);

		$listener = new WebshopOrderListener(invoicer: $this->invoicer(), schemaResolver: $resolver, logger: $this->createStub(LoggerInterface::class));
		$listener->handle(new ObjectCreatedEvent($entity));

		self::assertCount(1, $this->savesOf(schema: 'ARInvoice'));
		self::assertSame([[self::ORDER, 'invoice']], $this->transitionsRun);
	}//end testTheListenerInvoicesACreatedOrder()

	/**
	 * A paid status sent later as an update of an invoiced order settles its invoice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.4
	 */
	public function testTheListenerSettlesAnUpdatedPaidOrder(): void {
		$entity = new ObjectEntity();
		$entity->setUuid(self::ORDER);
		$entity->setObject($this->invoicedOrder());

		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->method('matchesSchema')->willReturn(true);

		(new WebshopOrderListener(invoicer: $this->invoicer(), schemaResolver: $resolver, logger: $this->createStub(LoggerInterface::class)))
			->handle(new ObjectUpdatedEvent($entity));

		self::assertCount(1, $this->savesOf(schema: 'JournalEntry'));
		self::assertSame([['journalentry-1', 'postDirect'], ['inv-1', 'mark-paid']], $this->transitionsRun);
	}//end testTheListenerSettlesAnUpdatedPaidOrder()

	/**
	 * Invoice an order, put its invoice in the store as issued, forget the
	 * saves and transitions, and return the order as invoiced.
	 *
	 * @param array<string,mixed> $order The order.
	 *
	 * @return array<string,mixed> The order, invoiced, its arInvoiceId set.
	 */
	private function invoicedThrough(array $order): array {
		$this->seedChannel();
		self::assertSame('invoiced', $this->invoicer()->handle(order: $order, orderId: self::ORDER, today: '2026-11-12'));
		$invoice = $this->savesOf(schema: 'ARInvoice')[0][1];
		$this->store['ARInvoice'] = [['id' => 'inv-1', 'lifecycleState' => 'issued'] + $invoice];
		$this->saves = [];
		$this->transitionsRun = [];

		return ['intakeState' => 'invoiced', 'arInvoiceId' => 'inv-1'] + $order;
	}//end invoicedThrough()

	/**
	 * The spec scenario: the customer returns the tea, and a credit note of
	 * EUR 22.85 referencing the invoice is written; the order is credited (REQ-SWO-005).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.5
	 */
	public function testARefundedOrderIsCreditedInFull(): void {
		$order = $this->invoicedThrough(order: self::order());
		$order['payment'] = ['status' => 'refunded'] + $order['payment'];

		self::assertSame('credited', $this->invoicer()->credit(order: $order, orderId: self::ORDER, today: '2026-11-20'));

		$credits = $this->savesOf(schema: 'ARInvoice');
		self::assertCount(1, $credits);
		$credit = $credits[0][1];
		self::assertNull($credits[0][2]);
		$number = $this->store['ARInvoice'][0]['invoiceNumber'];
		self::assertSame(['credit-note', 'issued', 'cm-consumers', 'J. Bakker', '2026-11-20'], [$credit['invoiceType'], $credit['lifecycleState'], $credit['customerId'], $credit['buyerName'], $credit['invoiceDate']]);
		self::assertSame($number . '-C', $credit['invoiceNumber']);
		self::assertSame([['reference' => $number, 'issueDate' => '2026-11-12']], $credit['precedingInvoiceReferences']);
		self::assertSame([-20.96, -1.89, -22.85], [$credit['netAmount'], $credit['vatAmount'], $credit['grossAmount']]);
		self::assertSame([-16.42, -4.54], array_column($credit['invoiceLines'], 'netAmount'));
		self::assertSame([2.0, 1.0], array_column($credit['invoiceLines'], 'quantity'));
		self::assertSame('refunded', $credit['webshop']['refundStatus']);
		self::assertSame('arinvoice-1', $this->savesOf(schema: 'WebshopOrder')[0][1]['creditNoteId']);
		self::assertSame([[self::ORDER, 'credit']], $this->transitionsRun);
	}//end testARefundedOrderIsCreditedInFull()

	/**
	 * A partial refund credits only the lines and quantities the shop names, at their invoiced price and rate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.5
	 */
	public function testAPartialRefundCreditsTheNamedLines(): void {
		$order = $this->invoicedThrough(order: self::order(overrides: [
			'lines' => [
				['description' => 'Earl Grey los 250 g', 'quantity' => 2, 'unitPrice' => 8.95, 'vatRate' => 9],
				['description' => 'Teapot', 'quantity' => 1, 'unitPrice' => 30.0, 'vatRate' => 21],
			],
			'totals' => ['gross' => 52.85],
		]));
		$order['payment'] = ['status' => 'cancelled', 'refundedLines' => [['line' => 1, 'quantity' => 1], ['line' => 7]], 'refundedShipping' => false];

		self::assertSame('credited', $this->invoicer()->credit(order: $order, orderId: self::ORDER, today: '2026-11-20'));

		$credit = $this->savesOf(schema: 'ARInvoice')[0][1];
		self::assertSame(['Earl Grey los 250 g'], array_column($credit['invoiceLines'], 'itemName'));
		self::assertSame([[1.0, -8.21, 9.0]], array_map(null, array_column($credit['invoiceLines'], 'quantity'), array_column($credit['invoiceLines'], 'netAmount'), array_column($credit['invoiceLines'], 'vatRate')));
		self::assertSame([-8.21, -0.74, -8.95], [$credit['netAmount'], $credit['vatAmount'], $credit['grossAmount']]);
		self::assertSame('cancelled', $credit['webshop']['refundStatus']);

		$this->saves = [];
		$order['payment'] = ['status' => 'refunded', 'refundedLines' => [], 'refundedShipping' => true];
		$this->invoicer()->credit(order: $order, orderId: self::ORDER, today: '2026-11-20');
		$shipping = $this->savesOf(schema: 'ARInvoice')[0][1];
		self::assertSame(['Shipping'], array_column($shipping['invoiceLines'], 'itemName'));
		self::assertSame(-4.95, $shipping['grossAmount']);
	}//end testAPartialRefundCreditsTheNamedLines()

	/**
	 * Only an invoiced, refunded or cancelled order whose invoice exists is credited, and only once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.5
	 */
	public function testOnlyARefundedInvoicedOrderIsCreditedAndOnlyOnce(): void {
		$order = $this->invoicedThrough(order: self::order());
		$refunded = ['payment' => ['status' => 'refunded']] + $order;
		$cases = [
			'paid' => $order,
			'pending' => ['payment' => ['status' => 'pending']] + $order,
			'already credited' => ['creditNoteId' => 'crn-1'] + $refunded,
			'not invoiced' => ['intakeState' => 'refused'] + $refunded,
			'invoice gone' => ['arInvoiceId' => 'missing'] + $refunded,
			'nothing named' => ['payment' => ['status' => 'refunded', 'refundedLines' => [['line' => 9]]]] + $order,
		];
		foreach ($cases as $case => $candidate) {
			self::assertSame('skipped', $this->invoicer()->credit(order: $candidate, orderId: self::ORDER, today: '2026-11-20'), $case);
		}

		self::assertSame([], $this->saves);
		self::assertSame([], $this->transitionsRun);
	}//end testOnlyARefundedInvoicedOrderIsCreditedAndOnlyOnce()

	/**
	 * A refund sent later as an update of the order credits its invoice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.5
	 */
	public function testTheListenerCreditsARefundedOrder(): void {
		$order = $this->invoicedThrough(order: self::order());
		$entity = new ObjectEntity();
		$entity->setUuid(self::ORDER);
		$entity->setObject(['payment' => ['status' => 'refunded']] + $order);

		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->method('matchesSchema')->willReturn(true);

		(new WebshopOrderListener(invoicer: $this->invoicer(), schemaResolver: $resolver, logger: $this->createStub(LoggerInterface::class)))
			->handle(new ObjectUpdatedEvent($entity));

		self::assertSame('credit-note', $this->savesOf(schema: 'ARInvoice')[0][1]['invoiceType']);
		self::assertSame([[self::ORDER, 'credit']], $this->transitionsRun);
	}//end testTheListenerCreditsARefundedOrder()

	/**
	 * Another schema's object is ignored, and a failing invoicer is logged rather than thrown.
	 *
	 * @return void
	 */
	public function testTheListenerIgnoresOtherSchemasAndLogsFailures(): void {
		$entity = new ObjectEntity();
		$entity->setUuid(self::ORDER);
		$entity->setObject(self::order());

		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->method('matchesSchema')->willReturn(false);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('error');
		(new WebshopOrderListener(invoicer: $this->invoicer(), schemaResolver: $resolver, logger: $logger))->handle(new ObjectCreatedEvent($entity));
		self::assertSame([], $this->saves);

		$this->store['WebshopChannel'] = [];
		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->method('matchesSchema')->willReturn(true);
		$transitions = $this->createMock(ObjectTransitionRunnerInterface::class);
		$transitions->method('run')->willThrowException(new RuntimeException('guard refused'));
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturn(null);
		$objectService->method('saveObject')->willReturn(new ObjectEntity());
		$config = $this->createStub(IAppConfig::class);
		$invoicer = new WebshopOrderInvoicer(objectService: $objectService, transitions: $transitions, appConfig: $config, logger: $this->createStub(LoggerInterface::class), vatRoute: $this->vatRoute(objectService: $objectService, config: $config));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(
			'WebshopOrderListener: the web shop order could not be invoiced',
			['orderId' => self::ORDER, 'exception' => 'guard refused']
		);

		(new WebshopOrderListener(invoicer: $invoicer, schemaResolver: $resolver, logger: $logger))->handle(new ObjectCreatedEvent($entity));
	}//end testTheListenerIgnoresOtherSchemasAndLogsFailures()
}//end class
