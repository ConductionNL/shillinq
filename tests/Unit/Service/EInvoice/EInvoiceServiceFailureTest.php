<?php

/**
 * Unit tests for EInvoiceService's hand-off to integriq and its failure
 * paths: integriq absent, a failed event save, an unstored payload, a failed
 * invoice save and query, and the object URI it announces.
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\EInvoice;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper;
use OCA\Shillinq\Service\EInvoice\EInvoiceArtefactStore;
use OCA\Shillinq\Service\EInvoice\EInvoiceService;
use OCA\Shillinq\Service\EInvoice\EInvoiceValidationService;
use OCA\Shillinq\Service\IntegriqCloudEventPublisher;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCA\Shillinq\Service\ViesService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Shillinq\Service\EInvoice\EInvoiceService
 * @uses   \OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper
 * @uses   \OCA\Shillinq\Service\EInvoice\EInvoiceValidationService
 * @uses   \OCA\Shillinq\Service\EInvoice\EInvoiceSend
 * @uses   \OCA\Shillinq\Service\EInvoice\DebtorCheck
 * @uses   \OCA\Shillinq\Service\InvoicePdfGenerator
 */
final class EInvoiceServiceFailureTest extends TestCase {

	private const STORED = '/jan/files/Shillinq/adm-1/e-invoices/2026/invoice-2026-0042.xml';

	private ObjectServiceInterface&MockObject $objects;

	private LoggerInterface&MockObject $logger;

	private EInvoiceArtefactStore&MockObject $store;

	private IntegriqCloudEventPublisher&MockObject $publisher;

	protected function setUp(): void {
		$this->objects   = $this->createMock(ObjectServiceInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->store     = $this->createMock(EInvoiceArtefactStore::class);
		$this->publisher = $this->createMock(IntegriqCloudEventPublisher::class);
		$this->objects->method('setSchema')->willReturnSelf();
	}

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
	}

	private function entity(array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}

	private function service(string $register = 'shillinq'): EInvoiceService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($register);

		$administrations = $this->createMock(AdministrationContextService::class);
		$administrations->method('canAccess')->willReturn(true);

		$vies = $this->createMock(ViesService::class);
		$vies->method('validate')->willReturn(['valid' => true, 'outage' => false]);

		$port = $this->createMock(PeppolTransmissionPortInterface::class);
		$port->method('lookupParticipant')->willReturn('0106:00000000');
		$port->expects(self::never())->method('submit');

		return new EInvoiceService(
			appConfig: $appConfig,
			administrationContext: $administrations,
			logger: $this->logger,
			ublMapper: new ArInvoiceUblMapper(),
			pdfGenerator: new InvoicePdfGenerator(),
			validationService: new EInvoiceValidationService(vies: $vies, peppolPort: $port),
			objectService: $this->objects,
			artefactStore: $this->store,
			publisher: $this->publisher,
		);
	}

	public function testAFailingInvoiceQueryIsLoggedAndReadsAsNotFound(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->objects->expects(self::never())->method('saveObject');
		$this->publisher->expects(self::never())->method('publish');
		$this->logger->expects(self::once())->method('error')
			->with('EInvoiceService: failed to query OpenRegister', ['schema' => 'ARInvoice', 'exception' => 'db down']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('ARInvoice not found');

		$this->service()->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');
	}

	public function testWithoutIntegriqNothingIsStoredHandedOverOrQueued(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('findAll')->willReturn([$this->issuedInvoice()]);
		$this->objects->expects(self::never())->method('saveObject');
		$this->publisher->method('isAvailable')->willReturn(false);
		$this->publisher->expects(self::never())->method('publish');
		$this->store->expects(self::never())->method('store');

		$result = $this->service()->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

		self::assertSame(['not-sent', null, null, true], [$result['deliveryStatus'], $result['transmissionId'], $result['payloadFileUri'], $result['fallback']]);
		self::assertSame(
			['field' => 'transmission', 'code' => 'peppol_unavailable', 'message' => 'Peppol sending is not available on this instance'],
			end($result['warnings'])
		);
	}

	public function testAFailedHandOffLeavesTheInvoiceNotSent(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('findAll')->willReturn([$this->issuedInvoice()]);
		$this->objects->expects(self::never())->method('saveObject');
		$this->publisher->method('isAvailable')->willReturn(true);
		$this->store->method('store')->willReturn(self::STORED);
		$this->publisher->expects(self::once())->method('publish')->willReturn(false);

		$result = $this->service()->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

		self::assertSame('not-sent', $result['deliveryStatus']);
		self::assertNull($result['payloadFileUri']);
		self::assertTrue($result['fallback']);
		self::assertSame(EInvoiceService::UNAVAILABLE_WARNING, end($result['warnings']));
	}

	public function testAnUnstoredPayloadIsNeverHandedOver(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('findAll')->willReturn([$this->issuedInvoice()]);
		$this->objects->expects(self::never())->method('saveObject');
		$this->publisher->method('isAvailable')->willReturn(true);
		$this->publisher->expects(self::never())->method('publish');
		$this->store->expects(self::once())->method('store')
			->with(
				'adm-1',
				self::callback(static fn (array $invoice): bool => $invoice['invoiceNumber'] === '2026-0042'),
				self::stringStartsWith('%PDF'),
				self::stringContains('<cbc:ID>2026-0042</cbc:ID>')
			)
			->willReturn(null);

		$result = $this->service()->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

		self::assertSame('not-sent', $result['deliveryStatus']);
		self::assertSame(
			['field' => 'transmission', 'code' => 'peppol_payload_not_stored', 'message' => 'The e-invoice file could not be stored'],
			end($result['warnings'])
		);
	}

	public function testAFailingInvoiceSaveAfterTheHandOffIsLoggedAndRaised(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('findAll')->willReturn([$this->issuedInvoice()]);
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('constraint'));
		$this->publisher->method('isAvailable')->willReturn(true);
		$this->store->method('store')->willReturn(self::STORED);
		$this->publisher->expects(self::once())->method('publish')->willReturn(true);
		$this->logger->expects(self::once())->method('error')
			->with('EInvoiceService: failed to persist object', ['schema' => 'ARInvoice', 'exception' => 'constraint']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to persist ARInvoice');

		$this->service()->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');
	}

	public function testAnInvoiceWithoutIdIsHandedOverByItsNumberInTheDefaultRegister(): void {
		$withoutId = $this->issuedInvoice();
		unset($withoutId['id']);

		$this->objects->expects(self::atLeastOnce())->method('setRegister')->with('shillinq')->willReturnSelf();
		$this->objects->method('findAll')->willReturn([$withoutId]);
		$this->objects->method('saveObject')->willReturn($this->entity($withoutId));
		$this->publisher->method('isAvailable')->willReturn(true);
		$this->store->method('store')->willReturn(self::STORED);

		$published = [];
		$this->publisher->expects(self::once())->method('publish')->willReturnCallback(
			static function (string $type, string $source, string $subject, array $data) use (&$published): bool {
				$published = [$type, $source, $subject, $data];
				return true;
			}
		);

		$result = $this->service(register: '')->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

		self::assertSame('queued', $result['deliveryStatus']);
		self::assertSame(
			[
				'nl.conduction.peppol.outbound.requested',
				'/apps/shillinq/ar-invoices',
				'2026-0042',
				[
					'sourceApp' => 'shillinq',
					'objectType' => 'ar-invoice',
					'objectUri' => 'openregister://shillinq/ARInvoice/2026-0042',
					'recipientPeppolId' => '0106:00000000',
					'documentType' => 'ubl-invoice-2.1',
					'payloadFileUri' => self::STORED,
					'administrationId' => 'adm-1',
				],
			],
			$published
		);
	}
}
