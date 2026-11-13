<?php

/**
 * Unit tests for EInvoiceService's failure paths: storage and query errors,
 * fail-soft event dispatch and logging, and the object URI it announces.
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
use OCA\Shillinq\Service\EInvoice\EInvoiceService;
use OCA\Shillinq\Service\EInvoice\EInvoiceValidationService;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCA\Shillinq\Service\ViesService;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
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

	/** @var ObjectServiceInterface&MockObject The OpenRegister object store. */
	private ObjectServiceInterface&MockObject $objects;

	/** @var LoggerInterface&MockObject Receives the service's log lines. */
	private LoggerInterface&MockObject $logger;

	/** @var IEventDispatcher&MockObject Receives the outbound-requested event. */
	private IEventDispatcher&MockObject $dispatcher;

	protected function setUp(): void {
		$this->objects    = $this->createMock(ObjectServiceInterface::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->objects->method('setSchema')->willReturnSelf();
	}

	/**
	 * An issued B2G invoice ready to send.
	 *
	 * @return array<string, mixed>
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
		$port->method('submit')->willReturn('urn:uuid:tx-1');

		return new EInvoiceService(
			appConfig: $appConfig,
			administrationContext: $administrations,
			logger: $this->logger,
			eventDispatcher: $this->dispatcher,
			ublMapper: new ArInvoiceUblMapper(),
			pdfGenerator: new InvoicePdfGenerator(),
			validationService: new EInvoiceValidationService(vies: $vies, peppolPort: $port),
			objectService: $this->objects,
			peppolPort: $port,
		);
	}

	/**
	 * Persisted entity whose serialisation is the given array.
	 *
	 * @param array<string, mixed> $data The serialised object.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}

	public function testAFailingInvoiceQueryIsLoggedAndReadsAsNotFound(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->objects->expects(self::never())->method('saveObject');
		$this->logger->expects(self::once())->method('error')
			->with('EInvoiceService: failed to query OpenRegister', ['schema' => 'ARInvoice', 'exception' => 'db down']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('ARInvoice not found');

		$this->service()->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');
	}

	public function testAFailingSaveIsLoggedAndRaisedWithoutAnnouncingTheSend(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('findAll')->willReturn([$this->issuedInvoice()]);
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('constraint'));
		$this->dispatcher->expects(self::never())->method('dispatch');
		$this->logger->expects(self::once())->method('error')
			->with('EInvoiceService: failed to persist object', ['schema' => 'ARInvoice', 'exception' => 'constraint']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to persist ARInvoice');

		$this->service()->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');
	}

	public function testAFailingDispatchIsLoggedAndTheInvoiceStillQueues(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('findAll')->willReturn([$this->issuedInvoice()]);
		$this->objects->method('saveObject')->willReturn($this->entity($this->issuedInvoice()));
		$this->dispatcher->method('dispatch')->willThrowException(new RuntimeException('listener broke'));

		$infos = [];
		$this->logger->method('info')->willReturnCallback(
			function (string $message, array $context) use (&$infos): void {
				$infos[$message] = $context;
			}
		);

		$result = $this->service()->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

		self::assertSame('queued', $result['deliveryStatus']);
		self::assertSame('urn:uuid:tx-1', $result['transmissionId']);
		self::assertFalse($result['fallback']);
		self::assertSame(
			['exception' => 'listener broke'],
			$infos['EInvoiceService: outbound-requested dispatch failed — fail-soft']
		);
	}

	public function testAFailingLoggerDoesNotLoseTheArtefactReference(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('findAll')->willReturn([$this->issuedInvoice()]);
		$this->objects->method('saveObject')->willReturn($this->entity($this->issuedInvoice()));
		$this->logger->method('info')->willThrowException(new RuntimeException('log disk full'));

		$result = $this->service()->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

		self::assertSame('queued', $result['deliveryStatus']);
		self::assertMatchesRegularExpression('#^docudesk://file/[0-9a-f]{16}$#', (string)$result['payloadFileUri']);
	}

	public function testAPersistedInvoiceWithoutIdIsAnnouncedByItsNumberInTheDefaultRegister(): void {
		$withoutId = $this->issuedInvoice();
		unset($withoutId['id']);

		$this->objects->expects(self::atLeastOnce())->method('setRegister')->with('shillinq')->willReturnSelf();
		$this->objects->method('findAll')->willReturn([$this->issuedInvoice()]);
		$this->objects->method('saveObject')->willReturn($this->entity($withoutId));

		$announced = null;
		$this->dispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
			function (string $name, GenericEvent $event) use (&$announced): void {
				$announced = $event->getArguments();
			}
		);

		$this->service(register: '')->sendEInvoice(administrationId: 'adm-1', invoiceNumber: '2026-0042');

		self::assertSame('openregister://shillinq/ARInvoice/2026-0042', $announced['objectUri']);
		self::assertSame('0106:00000000', $announced['recipientPeppolId']);
		self::assertSame('adm-1', $announced['administrationId']);
	}
}
