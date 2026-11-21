<?php

/**
 * Unit tests for IntegriqPurchaseOrderMailer and PurchaseOrderPdfRenderer.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\PurchaseOrder
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\PurchaseOrder;

use OCA\Shillinq\Service\PurchaseOrder\IntegriqPurchaseOrderMailer;
use OCA\Shillinq\Service\PurchaseOrder\PurchaseOrderPdfRenderer;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The order goes to integriq's mail channel as a PDF to the supplier's
 * address; without integriq, without an address or on a refusal nothing is claimed.
 *
 * @covers \OCA\Shillinq\Service\PurchaseOrder\IntegriqPurchaseOrderMailer
 * @covers \OCA\Shillinq\Service\PurchaseOrder\PurchaseOrderPdfRenderer
 * @uses \OCA\Shillinq\Support\FleetAppId
 *
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.1
 */
final class IntegriqPurchaseOrderMailerTest extends TestCase {

	/**
	 * Every payload dispatched.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $dispatched = [];

	/**
	 * What the dispatcher answers; an exception is thrown.
	 *
	 * @var mixed
	 */
	public mixed $answer = null;

	/**
	 * The order PO-2026-032 to Adviesbureau Groen.
	 *
	 * @var array<string,mixed>
	 */
	private const ORDER = [
		'id' => 'po-32',
		'poNumber' => 'PO-2026-032',
		'supplierId' => 'sup-groen',
		'currency' => 'EUR',
		'lines' => [['description' => 'Advies (uren)', 'quantityOrdered' => 40, 'unitPrice' => 95]],
		'totalExclVat' => 3800,
		'totalVat' => 798,
		'totalInclVat' => 4598,
	];

	/**
	 * The mailer over a supplier store and, when $integriq, a recording dispatcher.
	 *
	 * @param bool                $integriq Whether integriq is installed.
	 * @param array<string,mixed> $supplier The supplier row.
	 *
	 * @return IntegriqPurchaseOrderMailer
	 */
	private function mailer(bool $integriq = true, array $supplier = ['id' => 'sup-groen', 'administrationId' => 'adm-1', 'name' => 'Adviesbureau Groen', 'email' => 'inkoop@example.nl']): IntegriqPurchaseOrderMailer {
		$test = $this;
		$dispatcher = new class($test) {
			/**
			 * @param IntegriqPurchaseOrderMailerTest $test The recording test.
			 */
			public function __construct(private IntegriqPurchaseOrderMailerTest $test) {
			}

			/**
			 * @param array<string,mixed> $payload The message.
			 * @return mixed
			 */
			public function dispatch(array $payload): mixed {
				$this->test->dispatched[] = $payload;
				if ($this->test->answer instanceof \Throwable) {
					throw $this->test->answer;
				}

				return $this->test->answer;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($integriq, $dispatcher): object {
				if ($integriq === true && $class === 'OCA\\Integriq\\Service\\NotificationDispatcher') {
					return $dispatcher;
				}

				throw new RuntimeException('not registered');
			}
		);

		$store = new class([$supplier]) {
			/**
			 * @param array<int,array<string,mixed>> $rows Payee rows.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param mixed $register Unused.
			 * @return static
			 */
			public function setRegister(mixed $register): static {
				return $this;
			}

			/**
			 * @param mixed $schema Unused.
			 * @return static
			 */
			public function setSchema(mixed $schema): static {
				return $this;
			}

			/**
			 * @param array<string,mixed> $config The query.
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config = []): array {
				return array_values(array_filter($this->rows, static function (array $row) use ($config): bool {
					foreach (($config['filters'] ?? []) as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}));
			}
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new IntegriqPurchaseOrderMailer(
			container: $container,
			objectService: new DuckObjectServiceAdapter($store),
			appConfig: $appConfig,
			pdfRenderer: new PurchaseOrderPdfRenderer(),
			logger: new NullLogger(),
		);
	}//end mailer()

	/**
	 * The order goes to the supplier's address as a PDF and the hand-off is accepted.
	 *
	 * @return void
	 */
	public function testTheOrderIsMailedToTheSupplierAsAPdf(): void {
		$mailer = $this->mailer();

		self::assertTrue($mailer->isAvailable());
		self::assertTrue($mailer->sendPurchaseOrderEmail(administrationId: 'adm-1', purchaseOrder: self::ORDER));

		$payload = $this->dispatched[0];
		self::assertSame('inkoop@example.nl', $payload['to']);
		self::assertSame('Purchase order PO-2026-032', $payload['subject']);
		self::assertStringStartsWith('Dear Adviesbureau Groen,', $payload['body']);
		self::assertSame('PO-2026-032.pdf', $payload['attachments'][0]['filename']);
		self::assertSame('application/pdf', $payload['attachments'][0]['contentType']);
		self::assertStringStartsWith('%PDF-1.4', $payload['attachments'][0]['content']);
		self::assertStringEndsWith("%%EOF\n", $payload['attachments'][0]['content']);
		self::assertStringContainsString('(1. Advies \\(uren\\)  40.00 x EUR 95.00) Tj', $payload['attachments'][0]['content']);
	}//end testTheOrderIsMailedToTheSupplierAsAPdf()

	/**
	 * Without integriq the channel is unavailable and nothing is sent.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsSent(): void {
		$mailer = $this->mailer(integriq: false);

		self::assertFalse($mailer->isAvailable());
		self::assertFalse($mailer->sendPurchaseOrderEmail(administrationId: 'adm-1', purchaseOrder: self::ORDER));
		self::assertSame([], $this->dispatched);
	}//end testWithoutIntegriqNothingIsSent()

	/**
	 * A supplier without an address, in another administration, a refusal and a throw are not accepted.
	 *
	 * @return void
	 */
	public function testWhatDoesNotGoIsNotAccepted(): void {
		self::assertFalse($this->mailer(supplier: ['id' => 'sup-groen', 'administrationId' => 'adm-1', 'name' => 'Groen'])->sendPurchaseOrderEmail(administrationId: 'adm-1', purchaseOrder: self::ORDER));
		self::assertFalse($this->mailer()->sendPurchaseOrderEmail(administrationId: 'adm-2', purchaseOrder: self::ORDER));
		self::assertSame([], $this->dispatched);

		$this->answer = false;
		self::assertFalse($this->mailer()->sendPurchaseOrderEmail(administrationId: 'adm-1', purchaseOrder: self::ORDER));
		$this->answer = new RuntimeException('smtp down');
		self::assertFalse($this->mailer()->sendPurchaseOrderEmail(administrationId: 'adm-1', purchaseOrder: self::ORDER));
		self::assertCount(2, $this->dispatched);
	}//end testWhatDoesNotGoIsNotAccepted()

	/**
	 * The PDF lists the order's fields and totals, and splits long orders over pages.
	 *
	 * @return void
	 */
	public function testThePdfListsTheOrderAndPages(): void {
		$renderer = new PurchaseOrderPdfRenderer();
		$order = self::ORDER + ['supplierReference' => 'GR-77', 'paymentTerms' => '30 dagen'];

		self::assertSame(
			[
				'Purchase order PO-2026-032',
				'',
				'Supplier: Adviesbureau Groen',
				'Your reference: GR-77',
				'Payment terms: 30 dagen',
				'',
				'1. Advies (uren)  40.00 x EUR 95.00',
				'',
				'Total excl. VAT: EUR 3800.00',
				'VAT: EUR 798.00',
				'Total incl. VAT: EUR 4598.00',
			],
			$renderer->lines(purchaseOrder: $order, supplierName: 'Adviesbureau Groen')
		);

		$long = self::ORDER;
		$long['lines'] = array_fill(0, 70, ['description' => 'Folder', 'quantity' => 1, 'unitPrice' => 1]);
		self::assertStringContainsString('/Count 2', $renderer->render(purchaseOrder: $long, supplierName: 'Drukkerij'));
	}//end testThePdfListsTheOrderAndPages()
}//end class
