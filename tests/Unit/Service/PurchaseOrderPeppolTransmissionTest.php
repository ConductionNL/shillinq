<?php

/**
 * Integration tests for PurchaseOrderService::sendToPeppol /
 * PurchaseOrderService::sendToPDFEmail (slice 03 of the
 * bookkeeping-purchase-order-3way chain).
 *
 * Each test stubs the Peppol adapter + mailer ports so the Peppol Access Point
 * is never hit. The OpenRegister ObjectService is stubbed with an in-memory
 * schema-keyed store (same harness pattern as PurchaseOrderServiceTest) so the
 * persisted PO records can be inspected after the transmission call.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrder\PeppolBisOrderMapper;
use OCA\Shillinq\Service\PurchaseOrder\PeppolTransmissionAdapterInterface;
use OCA\Shillinq\Service\PurchaseOrder\PurchaseOrderMailerInterface;
use OCA\Shillinq\Service\PurchaseOrderService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Slice-03 transmission tests.
 *
 * Covers REQ-PO3W-002 across two surfaces:
 *  - sendToPeppol with a registered participant records `peppolMessageId` /
 *    `peppolSentAt`, clears any prior fallback reason, and transitions the PO
 *    to `sent`;
 *  - sendToPDFEmail with a non-Peppol supplier records `peppolFallbackReason`
 *    and transitions the PO to `sent` (no peppolMessageId).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PurchaseOrderPeppolTransmissionTest extends TestCase {

	/**
	 * sendToPeppol path: registered participant → peppolMessageId persisted,
	 * lifecycle transitions to "sent".
	 *
	 * @return void
	 */
	public function testSendToPeppolRecordsMessageIdOnRegisteredParticipant(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [$this->approvedPo()],
		];
		$adapter = new class implements PeppolTransmissionAdapterInterface {

			/**
			 * Captured lookup arguments.
			 *
			 * @var array<string,string>
			 */
			public array $lastLookup = [];

			/**
			 * Captured submit XML.
			 *
			 * @var string
			 */
			public string $lastUbl = '';

			/**
			 * @inheritDoc
			 */
			public function lookupParticipant(string $administrationId, string $partyId): ?string {
				$this->lastLookup = ['adm' => $administrationId, 'sup' => $partyId];
				return '0192:1234567890';
			}

			/**
			 * @inheritDoc
			 */
			public function submitOrder(string $participantId, string $ublOrderXml): string {
				$this->lastUbl = $ublOrderXml;
				return 'urn:uuid:dead-beef-cafe';
			}

			/**
			 * Not exercised by sendToPeppol() (which calls submitOrder()) — present
			 * only to satisfy the generalised PeppolTransmissionPortInterface
			 * (REQ-EINV-004) that PeppolTransmissionAdapterInterface now extends.
			 *
			 * @inheritDoc
			 */
			public function submit(string $participantId, string $documentType, string $payloadFileUri): string {
				unset($participantId, $documentType, $payloadFileUri);
				return 'urn:uuid:not-used-by-po-path';
			}
		};

		$mailer = $this->stubMailer(failOnSend: true);
		$service = $this->buildService(
			data: $data,
			saved: $saved,
			adapter: $adapter,
			mailer: $mailer
		);

		$result = $service->sendToPeppol(
			administrationId: 'adm-1',
			purchaseOrderId: 'po-1'
		);

		self::assertSame('sent', $result['lifecycleState']);
		self::assertSame('urn:uuid:dead-beef-cafe', $result['peppolMessageId']);
		self::assertNotEmpty($result['peppolSentAt']);
		self::assertNotEmpty($result['sentAt']);
		self::assertNull($result['peppolFallbackReason']);
		self::assertSame(['adm' => 'adm-1', 'sup' => 'sup-1'], $adapter->lastLookup);
		self::assertStringContainsString('urn:fdc:peppol.eu:poacc:bis:order_only:3', $adapter->lastUbl);

		// The persisted record reflects the transition.
		$persisted = $this->findSaved($saved, 'PurchaseOrder');
		self::assertSame('sent', $persisted['lifecycleState']);
		self::assertSame('urn:uuid:dead-beef-cafe', $persisted['peppolMessageId']);

	}//end testSendToPeppolRecordsMessageIdOnRegisteredParticipant()

	/**
	 * Fallback path: supplier not registered → peppolFallbackReason recorded,
	 * lifecycle transitions to "sent".
	 *
	 * @return void
	 */
	public function testSendToPeppolFallsBackWhenSupplierNotRegistered(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [$this->approvedPo()],
		];
		$adapter = $this->stubAdapter(participant: null, failOnSubmit: true);
		$mailer = $this->stubMailer(failOnSend: false);

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			adapter: $adapter,
			mailer: $mailer
		);

		$result = $service->sendToPeppol(
			administrationId: 'adm-1',
			purchaseOrderId: 'po-1'
		);

		self::assertSame('sent', $result['lifecycleState']);
		self::assertSame('supplier_not_peppol_participant', $result['peppolFallbackReason']);
		self::assertArrayNotHasKey('peppolMessageId', $result, 'fallback path must NOT set peppolMessageId');
		self::assertNotEmpty($result['sentAt']);

		// Mailer was called exactly once for the fallback dispatch.
		self::assertSame(1, $mailer->callCount, 'PDF+email mailer must be invoked exactly once for the fallback');

		$persisted = $this->findSaved($saved, 'PurchaseOrder');
		self::assertSame('supplier_not_peppol_participant', $persisted['peppolFallbackReason']);

	}//end testSendToPeppolFallsBackWhenSupplierNotRegistered()

	/**
	 * The send-block precondition (slice 02) is reused — neither
	 * sendToPeppol nor sendToPDFEmail may transition a PO whose chain is
	 * still pending (REQ-PO3W-002 send-block precondition).
	 *
	 * @return void
	 */
	public function testSendToPeppolRefusesWhenApprovalIncomplete(): void {
		$po = $this->approvedPo();
		$po['approvalChain'][1]['status'] = 'pending';
		$po['approvalChain'][1]['signedAt'] = '';
		$data = [
			'PurchaseOrder' => [$po],
		];

		$saved = [];
		$adapter = $this->stubAdapter(participant: '0192:1234567890', failOnSubmit: true);
		$mailer = $this->stubMailer(failOnSend: true);

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			adapter: $adapter,
			mailer: $mailer
		);

		try {
			$service->sendToPeppol(administrationId: 'adm-1', purchaseOrderId: 'po-1');
			self::fail('sendToPeppol must refuse to advance an incomplete chain');
		} catch (\RuntimeException $e) {
			self::assertSame('Purchase order cannot be sent: approval chain incomplete', $e->getMessage());
		}

		// Nothing was persisted — no PurchaseOrder save touched the store.
		self::assertSame([], $this->savesForSchema($saved, 'PurchaseOrder'));

	}//end testSendToPeppolRefusesWhenApprovalIncomplete()

	/**
	 * sendToPDFEmail directly (no Peppol attempt) records the operator-chosen
	 * fallback reason and transitions the PO to "sent".
	 *
	 * @return void
	 */
	public function testSendToPDFEmailRecordsFallbackReason(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [$this->approvedPo()],
		];
		$adapter = $this->stubAdapter(participant: '0192:1234567890', failOnSubmit: true);
		$mailer = $this->stubMailer(failOnSend: false);

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			adapter: $adapter,
			mailer: $mailer
		);

		$result = $service->sendToPDFEmail(
			administrationId: 'adm-1',
			purchaseOrderId: 'po-1',
			fallbackReason: 'supplier_request_pdf'
		);

		self::assertSame('sent', $result['lifecycleState']);
		self::assertSame('supplier_request_pdf', $result['peppolFallbackReason']);
		self::assertNotEmpty($result['sentAt']);
		self::assertSame(1, $mailer->callCount, 'mailer is the only dispatch surface on the explicit fallback path');

	}//end testSendToPDFEmailRecordsFallbackReason()

	/**
	 * Cross-tenant IDOR: the service refuses to transmit a PO scoped to
	 * another administration (the caller has no canAccess grant).
	 *
	 * @return void
	 */
	public function testSendToPeppolRejectsCrossTenant(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [$this->approvedPo()],
		];
		$adapter = $this->stubAdapter(participant: '0192:1234567890', failOnSubmit: true);
		$mailer = $this->stubMailer(failOnSend: true);

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			adapter: $adapter,
			mailer: $mailer,
			accessibleAdministrations: []
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Purchase order not found');

		$service->sendToPeppol(administrationId: 'adm-1', purchaseOrderId: 'po-1');

	}//end testSendToPeppolRejectsCrossTenant()

	/**
	 * Build a fully-approved PO record (slice-02 lifecycle state expected by
	 * the slice-03 transmission methods).
	 *
	 * @return array<string,mixed>
	 */
	private function approvedPo(): array {
		return [
			'id' => 'po-1',
			'administrationId' => 'adm-1',
			'poNumber' => 'PO-2026-adm-1-000001',
			'supplierId' => 'sup-1',
			'currency' => 'EUR',
			'costCenter' => 'CC-FAC',
			'projectCode' => 'P-FAC',
			'totalAmount' => 18500.00,
			'notes' => 'Coffee machine refresh.',
			'lifecycleState' => 'pending_approval',
			'lines' => [
				[
					'lineNumber' => 1,
					'productCode' => 'COFFEE-PRO-1',
					'quantity' => 1.0,
					'unitPrice' => 18500.00,
					'lineTotal' => 18500.00,
					'vatRate' => 0.21,
					'vatAmount' => 3885.00,
					'glAccount' => '4400',
				],
			],
			'approvalChain' => [
				[
					'role' => 'teamleider',
					'order' => 1,
					'status' => 'approved',
					'signedAt' => '2026-06-01T12:00:00+00:00',
					'signedBy' => 'teamleider-1',
				],
				[
					'role' => 'facility_manager',
					'order' => 2,
					'status' => 'approved',
					'signedAt' => '2026-06-02T09:00:00+00:00',
					'signedBy' => 'facility-1',
				],
			],
		];

	}//end approvedPo()

	/**
	 * Stub a Peppol adapter that returns a fixed participant id (or null) and
	 * either returns a synthetic message id or throws on submit().
	 *
	 * @param string|null $participant Lookup return value.
	 * @param bool $failOnSubmit Whether submit() should throw.
	 *
	 * @return PeppolTransmissionAdapterInterface
	 */
	private function stubAdapter(?string $participant, bool $failOnSubmit): PeppolTransmissionAdapterInterface {
		return new class($participant, $failOnSubmit) implements PeppolTransmissionAdapterInterface {
			/**
			 * Constructor.
			 *
			 * @param string|null $participant Lookup return value.
			 * @param bool $failOnSubmit Whether submit() should throw.
			 */
			public function __construct(
				private readonly ?string $participant,
				private readonly bool $failOnSubmit,
			) {
			}

			/**
			 * @inheritDoc
			 */
			public function lookupParticipant(string $administrationId, string $partyId): ?string {
				unset($administrationId, $partyId);
				return $this->participant;
			}

			/**
			 * @inheritDoc
			 */
			public function submitOrder(string $participantId, string $ublOrderXml): string {
				unset($participantId, $ublOrderXml);
				if ($this->failOnSubmit === true) {
					throw new \RuntimeException('Peppol AP unreachable');
				}

				return 'urn:uuid:test-message-id';
			}

			/**
			 * Not exercised by sendToPeppol() (which calls submitOrder()) — present
			 * only to satisfy the generalised PeppolTransmissionPortInterface
			 * (REQ-EINV-004) that PeppolTransmissionAdapterInterface now extends.
			 *
			 * @inheritDoc
			 */
			public function submit(string $participantId, string $documentType, string $payloadFileUri): string {
				unset($participantId, $documentType, $payloadFileUri);
				return 'urn:uuid:not-used-by-po-path';
			}
		};

	}//end stubAdapter()

	/**
	 * Stub a mailer that counts dispatches (and optionally fails them).
	 *
	 * @param bool $failOnSend Whether sendPurchaseOrderEmail() should throw.
	 *
	 * @return PurchaseOrderMailerInterface
	 */
	private function stubMailer(bool $failOnSend): object {
		return new class($failOnSend) implements PurchaseOrderMailerInterface {
			/**
			 * Number of times sendPurchaseOrderEmail was invoked.
			 *
			 * @var int
			 */
			public int $callCount = 0;

			/**
			 * Constructor.
			 *
			 * @param bool $failOnSend Whether send should throw.
			 */
			public function __construct(
				private readonly bool $failOnSend,
			) {
			}

			/**
			 * @inheritDoc
			 */
			public function sendPurchaseOrderEmail(string $administrationId, array $purchaseOrder): void {
				unset($administrationId, $purchaseOrder);
				$this->callCount++;
				if ($this->failOnSend === true) {
					throw new \RuntimeException('Mailer down');
				}
			}
		};

	}//end stubMailer()

	/**
	 * Build the service over an in-memory ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 * @param PeppolTransmissionAdapterInterface $adapter Adapter stub.
	 * @param PurchaseOrderMailerInterface $mailer Mailer stub.
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 *
	 * @return PurchaseOrderService
	 */
	private function buildService(
		array $data,
		array &$saved,
		PeppolTransmissionAdapterInterface $adapter,
		PurchaseOrderMailerInterface $mailer,
		array $accessibleAdministrations = ['adm-1'],
	): PurchaseOrderService {
		$stub = new class($data, $saved) {

			/**
			 * Schema => rows.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Captured saves (mutable ref).
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
			}

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				unset($register);
				return $this;
			}

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * Return rows for the active schema, applying equality filters.
			 *
			 * @param array<string,mixed> $params Query parameters.
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
			 * Capture a saved object — also overwrite the matching row by id so
			 * subsequent findAll calls observe the transition.
			 *
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				if (isset($object['id']) === true && $object['id'] !== '') {
					$rows = ($this->data[$this->schema] ?? []);
					$matched = false;
					foreach ($rows as $idx => $row) {
						if (($row['id'] ?? null) === $object['id']) {
							$rows[$idx] = $object;
							$matched = true;
							break;
						}
					}

					if ($matched === false) {
						$rows[] = $object;
					}

					$this->data[$this->schema] = $rows;
				} else {
					$this->data[$this->schema][] = $object;
				}

				return $object;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('currentUserId')->willReturn('inkoper-1');
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		$notificationManager = $this->createMock(INotificationManager::class);
		$logger = new NullLogger();

		return new PurchaseOrderService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			notificationManager: $notificationManager,
			logger: $logger,
			peppolAdapter: $adapter,
			purchaseOrderMailer: $mailer,
			peppolMapper: new PeppolBisOrderMapper(),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Return the most recent saved object for a given schema.
	 *
	 * @param array<int,array<string,mixed>> $saved Captured saves.
	 * @param string $schema Schema slug.
	 *
	 * @return array<string,mixed>
	 */
	private function findSaved(array $saved, string $schema): array {
		$latest = [];
		foreach ($saved as $row) {
			if (($row['schema'] ?? '') === $schema) {
				$latest = $row['object'];
			}
		}

		return $latest;
	}//end findSaved()

	/**
	 * Return every save for a given schema (used by negative-assertion tests).
	 *
	 * @param array<int,array<string,mixed>> $saved Captured saves.
	 * @param string $schema Schema slug.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function savesForSchema(array $saved, string $schema): array {
		return array_values(
			array_filter(
				$saved,
				static fn (array $row): bool => (($row['schema'] ?? '') === $schema)
			)
		);

	}//end savesForSchema()

}//end class
