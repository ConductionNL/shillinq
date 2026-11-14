<?php

/**
 * Unit tests for SelfBilledInvoiceIntake (REQ-SEIX-006).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\EInvoice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.2
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\EInvoice;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Service\EInvoice\SelfBilledInvoiceIntake;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Covers the received self-billed invoice: a draft for a customer with a
 * current agreement, written once per sender and number; a refusal with the
 * reason for a missing agreement, an expired one, an unknown customer or
 * administration and an unreadable document, recorded once; and an ordinary
 * invoice handed to the supplier invoice intake. The UBL is parsed by the real
 * SupplierInvoiceService::parseUblInvoice().
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SelfBilledInvoiceIntakeTest extends TestCase {
	/**
	 * Where integriq stored the received document.
	 *
	 * @var string
	 */
	private const PAYLOAD = '/admin/files/Integriq/peppol/inbound/sb-2026-00042.xml';

	/**
	 * The sender's Peppol participant id.
	 *
	 * @var string
	 */
	private const SENDER = '9944:nl000099998b57';

	/**
	 * Schema => rows, the register behind the intake.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $rows = [];

	/**
	 * The received document's content, or null when it cannot be read.
	 *
	 * @var string|null
	 */
	private ?string $payload = null;

	/**
	 * Calls to the supplier invoice intake: [administrationId, ublXml, context].
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $supplierIngests = [];

	/**
	 * Seed Akkerbouwbedrijf Jansen and its customer De Kuil, with an agreement.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			'Administration' => [
				['id' => 'adm-other', 'name' => 'Other B.V.', 'vatNumber' => 'NL009999999B01', 'kvkNumber' => '99999999'],
				['id' => 'adm-jansen', 'name' => 'Akkerbouwbedrijf Jansen', 'vatNumber' => 'NL001122334B01', 'kvkNumber' => '12345678'],
			],
			'CustomerMaster' => [
				[
					'id' => 'cm-dekuil',
					'customerId' => 'DEB-0042',
					'administrationId' => 'adm-jansen',
					'legalName' => 'Aardappelverwerking De Kuil B.V.',
					'vatId' => 'NL 0000 99998 B57',
					'selfBillingAgreement' => ['agreedOn' => '2026-01-05', 'validUntil' => '2026-12-31'],
				],
			],
			'ARInvoice' => [],
			'SelfBilledInvoiceRefusal' => [],
		];
		$this->payload = self::fixture(name: 'self-billed-invoice.xml');
	}//end setUp()

	/**
	 * A fixture's content.
	 *
	 * @param string $name The file name.
	 *
	 * @return string
	 */
	private static function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/fixtures/' . $name);
	}//end fixture()

	/**
	 * The intake over the in-memory register, a Files double and a fixed clock.
	 *
	 * @return SelfBilledInvoiceIntake
	 */
	private function intake(): SelfBilledInvoiceIntake {
		$store = new class($this->rows) {
			/**
			 * @param array<string,array<int,array<string,mixed>>> $rows Rows by schema, by reference.
			 */
			public function __construct(
				private array &$rows,
			) {
			}

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				unset($register);
				return $this;
			}

			/**
			 * Entity rows, as OpenRegister's findAll() answers them.
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,ObjectEntity>
			 */
			public function findAll(array $params = []): array {
				$found = [];
				foreach (($this->rows[$this->schema] ?? []) as $row) {
					foreach (($params['filters'] ?? []) as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$entity = new ObjectEntity();
					$entity->setObject($row);
					$found[] = $entity;
				}

				return $found;
			}

			/**
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return ObjectEntity
			 */
			public function saveObject(array $object): ObjectEntity {
				$object['id'] = strtolower($this->schema) . '-' . (count($this->rows[$this->schema] ?? []) + 1);
				$this->rows[$this->schema][] = $object;
				$entity = new ObjectEntity();
				$entity->setObject($object);
				return $entity;
			}
		};

		$file = $this->createStub(File::class);
		$file->method('getContent')->willReturnCallback(fn (): string => (string)$this->payload);
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('get')->willReturnCallback(
			function (string $path) use ($file): File {
				if ($path !== self::PAYLOAD || $this->payload === null) {
					throw new NotFoundException($path);
				}

				return $file;
			}
		);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-09-22T08:15:00+00:00'));

		// The real parser; only the supplier intake's write is replaced.
		$supplierInvoices = $this->getMockBuilder(SupplierInvoiceService::class)
			->disableOriginalConstructor()
			->onlyMethods(['ingestUBLInvoice'])
			->getMock();
		$supplierInvoices->method('ingestUBLInvoice')->willReturnCallback(
			function (string $administrationId, string $ublXml, array $context = []): array {
				$this->supplierIngests[] = [$administrationId, $ublXml, $context];
				return ['id' => 'si-1'];
			}
		);

		return new SelfBilledInvoiceIntake(
			objectService: new DuckObjectServiceAdapter($store),
			supplierInvoices: $supplierInvoices,
			rootFolder: $rootFolder,
			appConfig: $appConfig,
			timeFactory: $clock,
			logger: new NullLogger(),
		);
	}//end intake()

	/**
	 * The data integriq's inbound CloudEvent carries.
	 *
	 * @param string $reference The payload reference.
	 *
	 * @return array<string,string>
	 */
	private static function received(string $reference = self::PAYLOAD): array {
		return [
			'senderPeppolId' => self::SENDER,
			'documentType' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice',
			'payloadReference' => $reference,
		];
	}//end received()

	/**
	 * REQ-SEIX-006 scenario: a potato processor self-bills a delivery.
	 *
	 * @return void
	 */
	public function testASelfBilledInvoiceFromACustomerWithAnAgreementBecomesADraft(): void {
		$outcome = $this->intake()->receive(data: self::received());

		self::assertSame(['result' => 'drafted', 'id' => 'arinvoice-1'], $outcome);
		self::assertCount(1, $this->rows['ARInvoice']);
		$invoice = $this->rows['ARInvoice'][0];
		self::assertSame('SB-2026-00042', $invoice['invoiceNumber']);
		self::assertSame('adm-jansen', $invoice['administrationId']);
		self::assertSame('DEB-0042', $invoice['customerId']);
		self::assertSame('2026-09', $invoice['periodId']);
		self::assertSame('2026-09-21', $invoice['invoiceDate']);
		self::assertSame('2026-10-05', $invoice['dueDate']);
		self::assertSame('draft', $invoice['lifecycleState']);
		self::assertSame([6000.0, 540.0, 6540.0], [$invoice['netAmount'], $invoice['vatAmount'], $invoice['grossAmount']]);
		self::assertTrue($invoice['selfBilled']);
		self::assertSame('Factuur uitgereikt door afnemer', $invoice['selfBillingMention']);
		self::assertSame(
			[
				'buyerInvoiceNumber' => 'SB-2026-00042',
				'receivedAt' => '2026-09-22T08:15:00+00:00',
				'payloadFileUri' => self::PAYLOAD,
				'senderPeppolId' => self::SENDER,
			],
			$invoice['selfBilling']
		);
		self::assertSame(
			[
				[
					'lineId' => '1',
					'quantity' => 30000.0,
					'unitCode' => 'C62',
					'itemName' => 'Levering consumptieaardappelen week 38, 30.000 kg',
					'netPrice' => 0.2,
					'netAmount' => 6000.0,
					'vatCategory' => 'S',
					'vatRate' => 9.0,
				],
			],
			$invoice['invoiceLines']
		);
		self::assertSame([['category' => 'S', 'taxableAmount' => 6000.0, 'taxAmount' => 540.0, 'rate' => 9.0]], $invoice['vatBreakdown']);
		self::assertSame([], $this->rows['SelfBilledInvoiceRefusal']);
		self::assertSame([], $this->supplierIngests);
	}//end testASelfBilledInvoiceFromACustomerWithAnAgreementBecomesADraft()

	/**
	 * A repeated delivery of the same document writes no second invoice.
	 *
	 * @return void
	 */
	public function testARepeatedDeliveryIsWrittenOnce(): void {
		$intake = $this->intake();
		$intake->receive(data: self::received());

		self::assertSame(['result' => 'duplicate', 'id' => 'arinvoice-1'], $intake->receive(data: self::received()));
		self::assertCount(1, $this->rows['ARInvoice']);
	}//end testARepeatedDeliveryIsWrittenOnce()

	/**
	 * REQ-SEIX-006 scenario: without an agreement no invoice is written, and a
	 * refusal names the sender and the reason, once.
	 *
	 * @return void
	 */
	public function testWithoutAnAgreementTheInvoiceIsRefusedOnce(): void {
		unset($this->rows['CustomerMaster'][0]['selfBillingAgreement']);
		$intake = $this->intake();

		$first = $intake->receive(data: self::received());
		$again = $intake->receive(data: self::received());

		self::assertSame(['result' => 'refused', 'reason' => 'agreement', 'id' => 'selfbilledinvoicerefusal-1'], $first);
		self::assertSame($first, $again);
		self::assertSame([], $this->rows['ARInvoice']);
		self::assertSame(
			[
				[
					'receivedAt' => '2026-09-22T08:15:00+00:00',
					'senderPeppolId' => self::SENDER,
					'buyerInvoiceNumber' => 'SB-2026-00042',
					'reason' => 'No self-billing agreement is recorded for this customer',
					'payloadFileUri' => self::PAYLOAD,
					'administrationId' => 'adm-jansen',
					'id' => 'selfbilledinvoicerefusal-1',
				],
			],
			$this->rows['SelfBilledInvoiceRefusal']
		);
	}//end testWithoutAnAgreementTheInvoiceIsRefusedOnce()

	/**
	 * Every other reason to refuse, each with its text and no invoice.
	 *
	 * @return void
	 */
	public function testTheOtherRefusals(): void {
		$cases = [
			'expired' => function (): void {
				$this->rows['CustomerMaster'][0]['selfBillingAgreement'] = ['agreedOn' => '2026-01-05', 'validUntil' => '2026-06-30'];
			},
			'customer' => function (): void {
				$this->rows['CustomerMaster'][0]['vatId'] = 'NL000011111B01';
			},
			'administration' => function (): void {
				$this->rows['Administration'][1]['vatNumber'] = 'NL000000000B00';
				$this->rows['Administration'][1]['kvkNumber'] = '00000000';
			},
			'unreadable' => function (): void {
				$this->payload = null;
			},
		];

		foreach ($cases as $reason => $arrange) {
			$this->setUp();
			$arrange();

			$outcome = $this->intake()->receive(data: self::received());

			self::assertSame('refused', $outcome['result'], $reason);
			self::assertSame($reason, $outcome['reason'], $reason);
			self::assertSame([], $this->rows['ARInvoice'], $reason);
			self::assertSame(SelfBilledInvoiceIntake::REASONS[$reason], $this->rows['SelfBilledInvoiceRefusal'][0]['reason'], $reason);
		}

		self::assertSame('', $this->rows['SelfBilledInvoiceRefusal'][0]['buyerInvoiceNumber'], 'an unreadable document has no number');
	}//end testTheOtherRefusals()

	/**
	 * A reference that is not a Files path, and a document that is not XML, are
	 * refused as unreadable; a document that is not an invoice is skipped.
	 *
	 * @return void
	 */
	public function testAnUnusableReferenceOrDocumentIsRefused(): void {
		self::assertSame('unreadable', $this->intake()->receive(data: self::received(reference: 'peppol://inbound/42'))['reason']);

		$this->setUp();
		$this->payload = 'not xml';
		self::assertSame('unreadable', $this->intake()->receive(data: self::received())['reason']);

		$this->setUp();
		$this->payload = str_replace(['<Invoice ', '</Invoice>'], ['<CreditNote ', '</CreditNote>'], self::fixture(name: 'self-billed-invoice.xml'));
		self::assertSame(['result' => 'skipped', 'reason' => 'document'], $this->intake()->receive(data: self::received()));
		self::assertSame([], $this->rows['ARInvoice']);
		self::assertSame([], $this->rows['SelfBilledInvoiceRefusal']);
		self::assertSame([], $this->supplierIngests);
	}//end testAnUnusableReferenceOrDocumentIsRefused()

	/**
	 * REQ-SEIX-006: an ordinary invoice goes to the supplier invoice intake of
	 * the administration it is addressed to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.3
	 */
	public function testAnOrdinaryInvoiceGoesToTheSupplierIntake(): void {
		$this->payload = self::fixture(name: 'ordinary-invoice.xml');

		$outcome = $this->intake()->receive(data: self::received());

		self::assertSame(['result' => 'supplier', 'id' => 'si-1'], $outcome);
		self::assertSame(
			[['adm-jansen', $this->payload, ['ublSourceUri' => self::PAYLOAD, 'peppolReceivedAt' => '2026-09-22T08:15:00+00:00']]],
			$this->supplierIngests
		);
		self::assertSame([], $this->rows['ARInvoice']);
		self::assertSame([], $this->rows['SelfBilledInvoiceRefusal']);
	}//end testAnOrdinaryInvoiceGoesToTheSupplierIntake()

	/**
	 * An ordinary invoice addressed to no administration is skipped, not ingested.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.3
	 */
	public function testAnOrdinaryInvoiceForNoAdministrationIsSkipped(): void {
		$this->payload = self::fixture(name: 'ordinary-invoice.xml');
		$this->rows['Administration'] = [$this->rows['Administration'][0]];

		self::assertSame(['result' => 'skipped', 'reason' => 'administration'], $this->intake()->receive(data: self::received()));
		self::assertSame([], $this->supplierIngests);
	}//end testAnOrdinaryInvoiceForNoAdministrationIsSkipped()

	/**
	 * Which agreements cover an invoice date.
	 *
	 * @return void
	 */
	public function testAgreementGap(): void {
		$agreement = ['agreedOn' => '2026-01-05', 'validUntil' => '2026-12-31'];
		$cases = [
			[$agreement, '2026-01-05', null],
			[$agreement, '2026-12-31', null],
			[$agreement, '2026-01-04', 'expired'],
			[$agreement, '2027-01-01', 'expired'],
			[['agreedOn' => '2026-01-05'], '2030-06-01', null],
			[['agreedOn' => '2026-01-05T00:00:00Z', 'validUntil' => '2026-12-31T00:00:00Z'], '2026-12-31', null],
			[$agreement, '', 'expired'],
			[['validUntil' => '2026-12-31'], '2026-06-01', 'agreement'],
			[null, '2026-06-01', 'agreement'],
			['yes', '2026-06-01', 'agreement'],
		];

		foreach ($cases as $index => [$given, $date, $expected]) {
			self::assertSame($expected, SelfBilledInvoiceIntake::agreementGap(agreement: $given, date: $date), 'case ' . $index);
		}
	}//end testAgreementGap()
}//end class
