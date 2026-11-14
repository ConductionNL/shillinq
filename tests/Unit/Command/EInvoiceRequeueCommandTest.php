<?php

/**
 * Unit tests for the e-invoice requeue command and the service methods it calls.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Command;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Command\EInvoiceRequeueCommand;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper;
use OCA\Shillinq\Service\EInvoice\EInvoiceArtefactStore;
use OCA\Shillinq\Service\EInvoice\EInvoiceService;
use OCA\Shillinq\Service\EInvoice\EInvoiceValidationService;
use OCA\Shillinq\Service\IntegriqCloudEventPublisher;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCA\Shillinq\Service\ViesService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers `occ shillinq:einvoice:requeue`: which queued invoices count as stuck
 * (the old path's placeholder reference or none), that nothing is sent
 * without confirmation, that a confirmed run hands each to integriq and clears
 * the made-up transmission id, and that a failure stays queued and fails the run.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class EInvoiceRequeueCommandTest extends TestCase {
	/**
	 * Files path the artefact store reports for a stored XML.
	 *
	 * @var string
	 */
	private const STORED = '/jan/files/Shillinq/adm-1/e-invoices/2026/invoice.xml';

	/**
	 * Captured ARInvoice saves.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Captured hand-offs: [type, data].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $published = [];

	/**
	 * An issued invoice at queued, as the given path left it.
	 *
	 * @param string      $number         The invoice number.
	 * @param string|null $payloadFileUri The payload reference it carries.
	 * @param string      $lifecycleState The lifecycle state.
	 *
	 * @return array<string,mixed>
	 */
	private static function queuedInvoice(string $number, ?string $payloadFileUri, string $lifecycleState = 'issued'): array {
		$invoice = [
			'id' => 'inv-' . $number,
			'invoiceNumber' => $number,
			'customerId' => 'DEB-0001',
			'administrationId' => 'adm-1',
			'invoiceDate' => '2026-06-10',
			'dueDate' => '2026-07-10',
			'netAmount' => 1000.0,
			'vatAmount' => 210.0,
			'grossAmount' => 1210.0,
			'currency' => 'EUR',
			'lifecycleState' => $lifecycleState,
			'deliveryStatus' => 'queued',
			'transmissionId' => 'urn:uuid:made-up-by-the-log-port',
			'sellerName' => 'Shillinq Consultancy B.V.',
			'sellerVatId' => 'NL809876543B01',
			'buyerVatId' => 'NL001234567B01',
			'buyerLegalRegId' => '12340001',
		];
		if ($payloadFileUri !== null) {
			$invoice['payloadFileUri'] = $payloadFileUri;
		}

		return $invoice;
	}//end queuedInvoice()

	/**
	 * The register as the tests seed it: two stuck, one handed over, one not queued.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function register(): array {
		$notQueued = self::queuedInvoice(number: '2026-0004', payloadFileUri: null);
		$notQueued['deliveryStatus'] = 'not-sent';

		return [
			self::queuedInvoice(number: '2026-0001', payloadFileUri: 'docudesk://file/9f86d081884c7d65'),
			self::queuedInvoice(number: '2026-0002', payloadFileUri: null),
			self::queuedInvoice(number: '2026-0003', payloadFileUri: self::STORED),
			$notQueued,
		];
	}//end register()

	/**
	 * Build the real service over an ObjectService that answers ObjectEntity
	 * rows, the way OpenRegister's findAll() does.
	 *
	 * @param array<int,array<string,mixed>> $rows The ARInvoice rows.
	 * @param bool $integriq Whether integriq is installed.
	 * @param string|null $participant The participant id the lookup finds.
	 *
	 * @return EInvoiceService
	 */
	private function service(array $rows, bool $integriq = true, ?string $participant = '0106:00000000'): EInvoiceService {
		$inner = new class($rows, $this->saved) {
			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * @param array<int,array<string,mixed>> $rows Rows.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(
				private array $rows,
				array &$saved,
			) {
				$this->saved = &$saved;
			}

			/**
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,ObjectEntity>
			 */
			public function findAll(array $params = []): array {
				$entities = [];
				foreach ($this->rows as $row) {
					foreach (($params['filters'] ?? []) as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$entity = new ObjectEntity();
					$entity->setObject($row);
					$entities[] = $entity;
				}

				return $entities;
			}

			/**
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return ObjectEntity
			 */
			public function saveObject(array $object): ObjectEntity {
				$this->saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setObject($object);
				return $entity;
			}
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		// The command runs without a user session: it must not depend on canAccess.
		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->expects(self::never())->method('canAccess');

		$vies = $this->createMock(ViesService::class);
		$vies->method('validate')->willReturn(['valid' => true, 'outage' => false]);

		$port = $this->createMock(PeppolTransmissionPortInterface::class);
		$port->method('lookupParticipant')->willReturn($participant);
		$port->expects(self::never())->method('submit');

		$store = $this->createMock(EInvoiceArtefactStore::class);
		$store->method('store')->willReturn(self::STORED);

		$publisher = $this->createMock(IntegriqCloudEventPublisher::class);
		$publisher->method('isAvailable')->willReturn($integriq);
		$publisher->method('publish')->willReturnCallback(
			function (string $type, string $source, string $subject, array $data): bool {
				unset($source, $subject);
				$this->published[] = [$type, $data];
				return true;
			}
		);

		return new EInvoiceService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: new NullLogger(),
			ublMapper: new ArInvoiceUblMapper(),
			pdfGenerator: new InvoicePdfGenerator(),
			validationService: new EInvoiceValidationService(vies: $vies, peppolPort: $port),
			objectService: new DuckObjectServiceAdapter($inner),
			artefactStore: $store,
			publisher: $publisher,
		);
	}//end service()

	/**
	 * Only queued invoices without a stored payload are stuck, entity rows included.
	 *
	 * @return void
	 */
	public function testOnlyQueuedInvoicesWithoutAStoredFileAreListed(): void {
		$stuck = $this->service(rows: self::register())->queuedByTheOldPath();

		self::assertSame(['2026-0001', '2026-0002'], array_column($stuck, 'invoiceNumber'));
	}//end testOnlyQueuedInvoicesWithoutAStoredFileAreListed()

	/**
	 * A requeue hands the invoice to integriq, stores its file and clears the
	 * log-only port's transmission id.
	 *
	 * @return void
	 */
	public function testARequeueHandsTheInvoiceToIntegriq(): void {
		$result = $this->service(rows: self::register())->requeue(invoice: self::register()[0]);

		self::assertSame('queued', $result['deliveryStatus']);
		self::assertSame(self::STORED, $result['payloadFileUri']);
		self::assertCount(1, $this->published);
		self::assertSame(EInvoiceService::EVENT_OUTBOUND_REQUESTED, $this->published[0][0]);
		self::assertSame(self::STORED, $this->published[0][1]['payloadFileUri']);
		self::assertSame('openregister://shillinq/ARInvoice/inv-2026-0001', $this->published[0][1]['objectUri']);
		self::assertCount(1, $this->saved);
		self::assertSame('queued', $this->saved[0]['deliveryStatus']);
		self::assertSame(self::STORED, $this->saved[0]['payloadFileUri']);
		self::assertNull($this->saved[0]['transmissionId']);
	}//end testARequeueHandsTheInvoiceToIntegriq()

	/**
	 * What a requeue refuses, and that nothing is handed over for it.
	 *
	 * @return void
	 */
	public function testARequeueRefusesWhatIsNotStuck(): void {
		$service = $this->service(rows: self::register());
		$noNumber = self::queuedInvoice(number: '2026-0001', payloadFileUri: null);
		$noNumber['invoiceNumber'] = ' ';
		$cases = [
			'handed over' => [self::register()[2], 'ARInvoice 2026-0003 is not waiting from the old send path'],
			'not queued' => [self::register()[3], 'ARInvoice 2026-0004 is not waiting from the old send path'],
			'paid' => [
				self::queuedInvoice(number: '2026-0005', payloadFileUri: null, lifecycleState: 'paid'),
				'ARInvoice must be in lifecycleState "issued" to send an e-invoice',
			],
			'no number' => [$noNumber, 'The invoice names no administration or invoice number'],
		];

		foreach ($cases as $name => [$invoice, $message]) {
			try {
				$service->requeue(invoice: $invoice);
				self::fail($name . ': no refusal');
			} catch (RuntimeException $e) {
				self::assertSame($message, $e->getMessage(), $name);
			}
		}

		self::assertSame([], $this->published);
		self::assertSame([], $this->saved);
	}//end testARequeueRefusesWhatIsNotStuck()

	/**
	 * Without confirmation the command lists and sends nothing.
	 *
	 * @return void
	 */
	public function testWithoutConfirmationNothingIsSent(): void {
		$tester = new CommandTester(new EInvoiceRequeueCommand(eInvoiceService: $this->service(rows: self::register())));

		$tester->setInputs(['no']);
		$exit = $tester->execute([]);

		$display = $tester->getDisplay();
		self::assertSame(0, $exit);
		self::assertStringContainsString('2 e-invoice(s) are at queued but were never handed to integriq', $display);
		self::assertStringContainsString('2026-0001', $display);
		self::assertStringContainsString('docudesk://file/9f86d081884c7d65', $display);
		self::assertStringNotContainsString('2026-0003', $display);
		self::assertStringContainsString('Nothing was sent.', $display);
		self::assertSame([], $this->published);

		$quiet = new CommandTester(new EInvoiceRequeueCommand(eInvoiceService: $this->service(rows: self::register())));
		self::assertSame(0, $quiet->execute([], ['interactive' => false]));
		self::assertStringContainsString('Nothing was sent.', $quiet->getDisplay());
		self::assertSame([], $this->published);
	}//end testWithoutConfirmationNothingIsSent()

	/**
	 * A confirmed run hands every stuck invoice to integriq.
	 *
	 * @return void
	 */
	public function testAConfirmedRunSendsEachAgain(): void {
		$tester = new CommandTester(new EInvoiceRequeueCommand(eInvoiceService: $this->service(rows: self::register())));

		$exit = $tester->execute(['--yes' => true]);

		self::assertSame(0, $exit);
		self::assertSame(['2026-0001', '2026-0002'], array_column($this->saved, 'invoiceNumber'));
		self::assertCount(2, $this->published);
		self::assertStringContainsString('2026-0002: queued, payload ' . self::STORED, $tester->getDisplay());
		self::assertStringContainsString('2 e-invoice(s) handed to integriq.', $tester->getDisplay());
	}//end testAConfirmedRunSendsEachAgain()

	/**
	 * Without integriq each invoice is reported not sent, stays queued, and the run fails.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqTheRunFailsAndNothingChanges(): void {
		$tester = new CommandTester(new EInvoiceRequeueCommand(eInvoiceService: $this->service(rows: self::register(), integriq: false)));

		$exit = $tester->execute(['--yes' => true]);

		self::assertSame(1, $exit);
		self::assertSame([], $this->saved);
		self::assertStringContainsString('2026-0001: not sent. Peppol sending is not available on this instance', $tester->getDisplay());
		self::assertStringContainsString('2 of 2 e-invoice(s) were not sent; they stay at queued.', $tester->getDisplay());
	}//end testWithoutIntegriqTheRunFailsAndNothingChanges()

	/**
	 * Nothing stuck: the command says so and succeeds.
	 *
	 * @return void
	 */
	public function testNothingStuck(): void {
		$tester = new CommandTester(new EInvoiceRequeueCommand(eInvoiceService: $this->service(rows: [self::register()[2]])));

		self::assertSame(0, $tester->execute([]));
		self::assertStringContainsString('No e-invoice is waiting at queued from the old send path.', $tester->getDisplay());
	}//end testNothingStuck()
}//end class
