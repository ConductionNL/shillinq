<?php

/**
 * Unit tests for EInvoiceService (REQ-EINV-005 / REQ-EINV-006).
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
 * @spec openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-005
 * @spec openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-006
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\EInvoice;

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
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Covers REQ-EINV-005/006 and REQ-SEIX-001: send hands exactly one
 * outbound request to integriq and then advances deliveryStatus to queued;
 * per-administration IDOR guard; the send records the stored payload.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class EInvoiceServiceTest extends TestCase {
	/**
	 * Files path the artefact store reports for the stored XML.
	 *
	 * @var string
	 */
	private const STORED = '/jan/files/Shillinq/adm-1/e-invoices/2026/invoice-2026-0042.xml';

	/**
	 * Outbound requests handed to integriq, as [type, source, subject, data].
	 *
	 * @var array<int,array{0:string,1:string,2:string,3:array<string,mixed>}>
	 */
	private array $published = [];

	/**
	 * A realistic issued ARInvoice ready to send (B2G debtor).
	 *
	 * @return array<string,mixed>
	 */
	private function issuedInvoice(): array {
		return [
			'id' => 'invoice-obj-1',
			'invoiceNumber' => '2026-0042',
			'customerId' => 'DEB-0001',
			'administrationId' => 'adm-1',
			'invoiceDate' => '2026-06-10',
			'dueDate' => '2026-07-10',
			'netAmount' => 1000.0,
			'vatAmount' => 210.0,
			'grossAmount' => 1210.0,
			'currency' => 'EUR',
			'lifecycleState' => 'issued',
			'deliveryStatus' => 'not-sent',
			'sellerName' => 'Shillinq Consultancy B.V.',
			'sellerVatId' => 'NL809876543B01',
			'buyerVatId' => 'NL001234567B01',
			'buyerLegalRegId' => '12340001',
		];

	}//end issuedInvoice()

	/**
	 * Build an EInvoiceService over an in-memory ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 * @param PeppolTransmissionPortInterface|null $peppolPort Optional port override.
	 * @param string|null $viesOutcomeValid VIES valid outcome (default true).
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 *
	 * @return EInvoiceService
	 */
	private function buildService(
		array $data,
		array &$saved,
		?PeppolTransmissionPortInterface $peppolPort = null,
		?bool $viesOutcomeValid = true,
		array $accessibleAdministrations = ['adm-1'],
	): EInvoiceService {
		$stub = new class($data, $saved) {
			/**
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
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
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}

			/**
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		$vies = $this->createMock(ViesService::class);
		$vies->method('validate')->willReturn(['valid' => $viesOutcomeValid, 'outage' => false]);

		$port = ($peppolPort ?? $this->stubPort('0106:00000000'));

		$validationService = new EInvoiceValidationService(vies: $vies, peppolPort: $port);

		$store = $this->createMock(EInvoiceArtefactStore::class);
		$store->method('store')->willReturn(self::STORED);

		$publisher = $this->createMock(IntegriqCloudEventPublisher::class);
		$publisher->method('isAvailable')->willReturn(true);
		$publisher->method('publish')->willReturnCallback(
			function (string $type, string $source, string $subject, array $data): bool {
				$this->published[] = [$type, $source, $subject, $data];
				return true;
			}
		);

		return new EInvoiceService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: new NullLogger(),
			ublMapper: new ArInvoiceUblMapper(),
			pdfGenerator: new InvoicePdfGenerator(),
			validationService: $validationService,
			objectService: new DuckObjectServiceAdapter($stub),
			artefactStore: $store,
			publisher: $publisher,
		);

	}//end buildService()

	/**
	 * Build a stub Peppol port with a fixed lookupParticipant()/submit() outcome.
	 *
	 * @param string|null $participantId Lookup return value.
	 * @param bool $failOnSubmit Whether submit() should throw.
	 *
	 * @return PeppolTransmissionPortInterface
	 */
	private function stubPort(?string $participantId, bool $failOnSubmit = false): PeppolTransmissionPortInterface {
		return new class($participantId, $failOnSubmit) implements PeppolTransmissionPortInterface {
			/**
			 * @param string|null $participantId Lookup return value.
			 * @param bool $failOnSubmit Whether submit() should throw.
			 */
			public function __construct(
				private readonly ?string $participantId,
				private readonly bool $failOnSubmit,
			) {
			}

			/**
			 * @inheritDoc
			 */
			public function lookupParticipant(string $administrationId, string $partyId): ?string {
				unset($administrationId, $partyId);
				return $this->participantId;
			}

			/**
			 * @inheritDoc
			 */
			public function submit(string $participantId, string $documentType, string $payloadFileUri): string {
				unset($documentType, $payloadFileUri);
				if ($this->failOnSubmit === true) {
					throw new RuntimeException('Peppol AP unreachable');
				}

				return 'urn:uuid:' . substr(hash('sha256', $participantId), 0, 32);
			}
		};

	}//end stubPort()

	/**
	 * REQ-SEIX-001: send hands exactly one outbound request to integriq and
	 * then advances deliveryStatus to queued.
	 *
	 * @return void
	 */
	public function testSendHandsOneOutboundRequestToIntegriqAndQueues(): void {
		$saved = [];
		$data = ['ARInvoice' => [$this->issuedInvoice()]];

		$service = $this->buildService(data: $data, saved: $saved);

		$result = $service->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

		self::assertSame('queued', $result['deliveryStatus']);
		self::assertNull($result['transmissionId']);
		self::assertSame(self::STORED, $result['payloadFileUri']);
		self::assertFalse($result['fallback']);
		self::assertCount(1, $this->published);
		self::assertSame(EInvoiceService::EVENT_OUTBOUND_REQUESTED, $this->published[0][0]);

		$arSaves = array_values(array_filter($saved, static fn (array $s): bool => ($s['schema'] === 'ARInvoice')));
		self::assertCount(1, $arSaves);
		self::assertSame('queued', $arSaves[0]['object']['deliveryStatus']);

	}//end testSendHandsOneOutboundRequestToIntegriqAndQueues()

	/**
	 * REQ-SEIX-002: the send records the stored XML as its payload, and no
	 * made-up transmission id.
	 *
	 * @return void
	 */
	public function testSendRecordsTheStoredPayload(): void {
		$saved = [];
		$data = ['ARInvoice' => [$this->issuedInvoice()]];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			peppolPort: $this->stubPort('0106:00000000')
		);

		$service->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

		self::assertSame(self::STORED, $saved[0]['object']['payloadFileUri']);
		self::assertArrayNotHasKey('transmissionId', $saved[0]['object']);

	}//end testSendRecordsTheStoredPayload()

	/**
	 * REQ-EINV-005 scenario (IDOR): the send action rejects another
	 * administration's invoice.
	 *
	 * @return void
	 */
	public function testSendRejectsCrossTenantAdministration(): void {
		$saved = [];
		$data = ['ARInvoice' => [$this->issuedInvoice()]];


		$service = $this->buildService(
			data: $data,
			saved: $saved,
			accessibleAdministrations: []
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('ARInvoice not found');

		$service->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

	}//end testSendRejectsCrossTenantAdministration()

	/**
	 * A draft (non-issued) ARInvoice cannot be sent.
	 *
	 * @return void
	 */
	public function testDraftInvoiceCannotBeSent(): void {
		$saved = [];
		$invoice = $this->issuedInvoice();
		$invoice['lifecycleState'] = 'draft';
		$data = ['ARInvoice' => [$invoice]];


		$service = $this->buildService(data: $data, saved: $saved);

		$this->expectException(RuntimeException::class);

		$service->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

	}//end testDraftInvoiceCannotBeSent()

	/**
	 * A malformed BTW-nummer blocks send — no event is emitted.
	 *
	 * @return void
	 */
	public function testValidationFailureBlocksSendWithNoEvent(): void {
		$saved = [];
		$invoice = $this->issuedInvoice();
		$invoice['buyerVatId'] = 'NL123';
		$data = ['ARInvoice' => [$invoice]];


		$service = $this->buildService(data: $data, saved: $saved);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/BTW-nummer/');

		$service->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

	}//end testValidationFailureBlocksSendWithNoEvent()

	/**
	 * REQ-EINV-003 scenario 2 (via orchestration): an unknown Peppol
	 * participant offers the PDF+email fallback — no event, deliveryStatus
	 * stays not-sent.
	 *
	 * @return void
	 */
	public function testUnknownParticipantFallsBackWithoutEmitting(): void {
		$saved = [];
		$data = ['ARInvoice' => [$this->issuedInvoice()]];


		$service = $this->buildService(
			data: $data,
			saved: $saved,
			peppolPort: $this->stubPort(null)
		);

		$result = $service->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

		self::assertSame('not-sent', $result['deliveryStatus']);
		self::assertTrue($result['fallback']);
		self::assertSame([], array_filter($saved, static fn (array $s): bool => ($s['schema'] === 'ARInvoice')));
		self::assertSame([], $this->published);

	}//end testUnknownParticipantFallsBackWithoutEmitting()
	/**
	 * The refusals before any save, in order, with their messages: no
	 * administration, an inaccessible one (masked as not found), an unknown
	 * invoice.
	 *
	 * @return void
	 */
	public function testRefusalsAndTheirMessages(): void {
		$messages = [];
		foreach ([['', '2026-0042'], ['adm-2', '2026-0042'], ['adm-1', 'nope']] as [$administrationId, $invoiceNumber]) {
			$saved      = [];
			$service = $this->buildService(data: ['ARInvoice' => [$this->issuedInvoice()]], saved: $saved);
			try {
				$service->sendEInvoice($administrationId, $invoiceNumber);
				$messages[] = 'accepted';
			} catch (RuntimeException $e) {
				$messages[] = $e->getMessage();
			}

			self::assertSame([], $saved);
		}

		self::assertSame([], $this->published);

		self::assertSame(['administrationId is required', 'ARInvoice not found', 'ARInvoice not found'], $messages);

	}//end testRefusalsAndTheirMessages()

	/**
	 * A queued send answers its exact envelope, saves the invoice with its
	 * payload and recipient, and hands integriq the envelope its Peppol
	 * transmission reads.
	 *
	 * @return void
	 */
	public function testTheQueuedEnvelopeAndTheHandOff(): void {
		$saved   = [];
		$service = $this->buildService(data: ['ARInvoice' => [$this->issuedInvoice()]], saved: $saved);

		$queued = $service->sendEInvoice('adm-1', '2026-0042');
		self::assertSame(['deliveryStatus', 'transmissionId', 'payloadFileUri', 'fallback', 'warnings'], array_keys($queued));
		self::assertSame(['queued', null, self::STORED, false], array_slice(array_values($queued), 0, 4));
		self::assertCount(1, $saved);
		self::assertSame(['queued', self::STORED, '0106:00000000'], [$saved[0]['object']['deliveryStatus'], $saved[0]['object']['payloadFileUri'], $saved[0]['object']['buyerPeppolParticipantId']]);
		self::assertSame(
			[
				[
					EInvoiceService::EVENT_OUTBOUND_REQUESTED,
					'/apps/shillinq/ar-invoices',
					'invoice-obj-1',
					[
						'sourceApp' => 'shillinq',
						'objectType' => 'ar-invoice',
						'objectUri' => 'openregister://shillinq/ARInvoice/invoice-obj-1',
						'recipientPeppolId' => '0106:00000000',
						'documentType' => EInvoiceService::DOCUMENT_TYPE,
						'payloadFileUri' => self::STORED,
						'administrationId' => 'adm-1',
					],
				],
			],
			$this->published
		);

	}//end testTheQueuedEnvelopeAndTheHandOff()
}//end class
