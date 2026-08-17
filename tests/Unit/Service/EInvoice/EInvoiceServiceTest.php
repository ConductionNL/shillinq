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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\EInvoice;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper;
use OCA\Shillinq\Service\EInvoice\EInvoiceService;
use OCA\Shillinq\Service\EInvoice\EInvoiceValidationService;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCA\Shillinq\Service\ViesService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Covers REQ-EINV-005/006: send emits exactly one outbound-requested event and
 * advances deliveryStatus to queued; per-administration IDOR guard; B2G send
 * records provenance (transmissionId + payloadFileUri).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class EInvoiceServiceTest extends TestCase {
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
	 * @param IEventDispatcher $dispatcher Event dispatcher (mock).
	 * @param PeppolTransmissionPortInterface|null $peppolPort Optional port override.
	 * @param string|null $viesOutcomeValid VIES valid outcome (default true).
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 *
	 * @return EInvoiceService
	 */
	private function buildService(
		array $data,
		array &$saved,
		IEventDispatcher $dispatcher,
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

		return new EInvoiceService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: new NullLogger(),
			eventDispatcher: $dispatcher,
			ublMapper: new ArInvoiceUblMapper(),
			pdfGenerator: new InvoicePdfGenerator(),
			validationService: $validationService,
			peppolPort: $port,
			objectService: new DuckObjectServiceAdapter($stub),
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
	 * REQ-EINV-005 scenario 1: send emits exactly one outbound-requested event
	 * and advances deliveryStatus to queued.
	 *
	 * @return void
	 */
	public function testSendEmitsExactlyOneOutboundRequestedEventAndQueues(): void {
		$saved = [];
		$data = ['ARInvoice' => [$this->issuedInvoice()]];

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::once())
			->method('dispatch')
			->with(
				self::equalTo(EInvoiceService::EVENT_OUTBOUND_REQUESTED),
				self::isInstanceOf(Event::class)
			);

		$service = $this->buildService(data: $data, saved: $saved, dispatcher: $dispatcher);

		$result = $service->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

		self::assertSame('queued', $result['deliveryStatus']);
		self::assertNotNull($result['transmissionId']);
		self::assertNotNull($result['payloadFileUri']);
		self::assertFalse($result['fallback']);

		$arSaves = array_values(array_filter($saved, static fn (array $s): bool => ($s['schema'] === 'ARInvoice')));
		self::assertCount(1, $arSaves);
		self::assertSame('queued', $arSaves[0]['object']['deliveryStatus']);

	}//end testSendEmitsExactlyOneOutboundRequestedEventAndQueues()

	/**
	 * REQ-EINV-006: B2G send records transmissionId + payloadFileUri provenance.
	 *
	 * @return void
	 */
	public function testB2GSendRecordsProvenance(): void {
		$saved = [];
		$data = ['ARInvoice' => [$this->issuedInvoice()]];

		$dispatcher = $this->createMock(IEventDispatcher::class);

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			dispatcher: $dispatcher,
			peppolPort: $this->stubPort('0106:00000000')
		);

		$result = $service->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

		self::assertStringStartsWith('urn:uuid:', (string)$result['transmissionId']);
		self::assertStringStartsWith('docudesk://file/', (string)$result['payloadFileUri']);

	}//end testB2GSendRecordsProvenance()

	/**
	 * REQ-EINV-005 scenario (IDOR): the send action rejects another
	 * administration's invoice.
	 *
	 * @return void
	 */
	public function testSendRejectsCrossTenantAdministration(): void {
		$saved = [];
		$data = ['ARInvoice' => [$this->issuedInvoice()]];

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::never())->method('dispatch');

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			dispatcher: $dispatcher,
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

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::never())->method('dispatch');

		$service = $this->buildService(data: $data, saved: $saved, dispatcher: $dispatcher);

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

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::never())->method('dispatch');

		$service = $this->buildService(data: $data, saved: $saved, dispatcher: $dispatcher);

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

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::never())->method('dispatch');

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			dispatcher: $dispatcher,
			peppolPort: $this->stubPort(null)
		);

		$result = $service->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

		self::assertSame('not-sent', $result['deliveryStatus']);
		self::assertTrue($result['fallback']);
		self::assertSame([], array_filter($saved, static fn (array $s): bool => ($s['schema'] === 'ARInvoice')));

	}//end testUnknownParticipantFallsBackWithoutEmitting()
}//end class
