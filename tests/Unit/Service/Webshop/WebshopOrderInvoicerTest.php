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
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Listener\WebshopOrderListener;
use OCA\Shillinq\Service\Webshop\WebshopOrderInvoicer;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A received web shop order becomes one invoice, once (REQ-SWO-001), on the right debtor (REQ-SWO-002).
 */
#[CoversClass(WebshopOrderInvoicer::class)]
#[CoversClass(WebshopOrderListener::class)]
#[UsesClass(ObjectIdentifier::class)]
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
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity {
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
		$config->method('getValueString')->willReturn('shillinq');

		return new WebshopOrderInvoicer(
			objectService: $objectService,
			transitions: $transitions,
			appConfig: $config,
			logger: $this->createStub(LoggerInterface::class),
		);
	}//end invoicer()

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
		self::assertSame(
			['channelId' => self::CHANNEL, 'shopOrderId' => '100231', 'orderId' => self::ORDER, 'buyerName' => 'J. Bakker', 'paymentReference' => 'tr_7Qw2'],
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
	 * Unknown channels, empty orders, foreign buyers, wrong totals and a missing collective debtor are refused with the reason.
	 *
	 * @return void
	 */
	public function testAnOrderThatCannotBeInvoicedIsRefused(): void {
		$cases = [
			'The order names a web shop channel shillinq does not know.' => [self::order(overrides: ['channelId' => 'unknown']), []],
			'The order has no lines.' => [self::order(overrides: ['lines' => []]), []],
			'The buyer is in BE; orders to another country are not invoiced until their VAT route is set up.' => [self::order(overrides: ['buyer' => ['type' => 'consumer', 'name' => 'A', 'countryCode' => 'be']]), []],
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
		self::assertSame('customermaster-1', $this->savesOf(schema: 'ARInvoice')[0][1]['customerId']);
	}//end testANewBusinessBuyerIsCreated()

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
		$invoicer = new WebshopOrderInvoicer(objectService: $objectService, transitions: $transitions, appConfig: $config, logger: $this->createStub(LoggerInterface::class));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(
			'WebshopOrderListener: the web shop order could not be invoiced',
			['orderId' => self::ORDER, 'exception' => 'guard refused']
		);

		(new WebshopOrderListener(invoicer: $invoicer, schemaResolver: $resolver, logger: $logger))->handle(new ObjectCreatedEvent($entity));
	}//end testTheListenerIgnoresOtherSchemasAndLogsFailures()
}//end class
