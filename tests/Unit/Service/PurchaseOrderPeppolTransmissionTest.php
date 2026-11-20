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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
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
	 * Transitions the recording runner ran, as [objectId, action, asSystem].
	 *
	 * @var array<int,array{0:string,1:string,2:bool}>
	 */
	private array $transitions = [];

	/**
	 * A participant supplier: the order is handed off and shows the dispatch
	 * pending via Peppol; it stays approved until integriq reports delivery.
	 *
	 * @return void
	 */
	public function testSendToPeppolHandsTheOrderOffAndShowsItPending(): void {
		$saved = [];
		$adapter = $this->stubAdapter(participant: '0192:1234567890', failOnSubmit: false);
		$mailer = $this->stubMailer();
		$runner = $this->createMock(ObjectTransitionRunner::class);
		$runner->expects(self::never())->method('run');
		$service = $this->buildService(data: ['PurchaseOrder' => [$this->approvedPo()]], saved: $saved, adapter: $adapter, mailer: $mailer, runner: $runner);

		$result = $service->sendToPeppol(administrationId: 'adm-1', purchaseOrderId: 'po-1');

		self::assertSame('approved', $result['statusCode']);
		self::assertSame('peppol', $result['dispatchChannel']);
		self::assertSame('pending', $result['dispatchStatus']);
		self::assertNull($result['dispatchError']);
		self::assertSame('urn:uuid:test-message-id', $result['peppolMessageId']);
		self::assertArrayNotHasKey('sentAt', $result);
		self::assertSame('po-1', $adapter->lastOrderId);
		self::assertSame(['adm' => 'adm-1', 'sup' => 'sup-1'], $adapter->lastLookup);
		self::assertStringContainsString('urn:fdc:peppol.eu:poacc:bis:order_only:3', $adapter->lastUbl);
		self::assertSame(0, $mailer->callCount);
		self::assertSame('pending', $this->findSaved($saved, 'PurchaseOrder')['dispatchStatus']);

	}//end testSendToPeppolHandsTheOrderOffAndShowsItPending()

	/**
	 * A supplier that is not a participant: the failure is recorded, the order
	 * stays approved and nothing is mailed behind the buyer's back.
	 *
	 * @return void
	 */
	public function testANonParticipantSupplierIsOfferedEmailInstead(): void {
		$saved = [];
		$mailer = $this->stubMailer();
		$service = $this->buildService(data: ['PurchaseOrder' => [$this->approvedPo()]], saved: $saved, adapter: $this->stubAdapter(participant: null, failOnSubmit: true), mailer: $mailer);

		$result = $service->sendToPeppol(administrationId: 'adm-1', purchaseOrderId: 'po-1');

		self::assertSame('approved', $result['statusCode']);
		self::assertSame('failed', $result['dispatchStatus']);
		self::assertSame('The supplier is not a Peppol participant.', $result['dispatchError']);
		self::assertSame('supplier_not_peppol_participant', $result['peppolFallbackReason']);
		self::assertArrayNotHasKey('peppolMessageId', $result);
		self::assertSame(0, $mailer->callCount);

	}//end testANonParticipantSupplierIsOfferedEmailInstead()

	/**
	 * A refused hand-off and an unconfigured adapter are recorded, never claimed.
	 *
	 * @return void
	 */
	public function testAFailedHandOffIsRecordedNotClaimed(): void {
		$saved = [];
		$service = $this->buildService(data: ['PurchaseOrder' => [$this->approvedPo()]], saved: $saved, adapter: $this->stubAdapter(participant: '0192:1234567890', failOnSubmit: true), mailer: $this->stubMailer());
		$result = $service->sendToPeppol(administrationId: 'adm-1', purchaseOrderId: 'po-1');
		self::assertSame(['approved', 'failed', 'The order could not be handed to the Peppol access point.', 'peppol_send_failed'], [$result['statusCode'], $result['dispatchStatus'], $result['dispatchError'], $result['peppolFallbackReason']]);

		$saved = [];
		$service = $this->buildService(data: ['PurchaseOrder' => [$this->approvedPo()]], saved: $saved, adapter: $this->stubAdapter(participant: '', failOnSubmit: false, failOnLookup: true), mailer: $this->stubMailer());
		$result = $service->sendToPeppol(administrationId: 'adm-1', purchaseOrderId: 'po-1');
		self::assertSame(['approved', 'failed', 'Peppol sending is not available: Peppol sending is not configured'], [$result['statusCode'], $result['dispatchStatus'], $result['dispatchError']]);

	}//end testAFailedHandOffIsRecordedNotClaimed()

	/**
	 * A dispatch already pending is not sent a second time.
	 *
	 * @return void
	 */
	public function testAPendingDispatchIsNotSentAgain(): void {
		$po = $this->approvedPo();
		$po['dispatchStatus'] = 'pending';
		$saved = [];
		$service = $this->buildService(data: ['PurchaseOrder' => [$po]], saved: $saved, adapter: $this->stubAdapter(participant: '0192:1234567890', failOnSubmit: false), mailer: $this->stubMailer());

		foreach (['sendToPeppol', 'sendToPDFEmail'] as $method) {
			try {
				$service->$method(administrationId: 'adm-1', purchaseOrderId: 'po-1');
				self::fail($method . ' sent a pending order again');
			} catch (\RuntimeException $e) {
				self::assertSame('Purchase order cannot be sent: a dispatch is already pending', $e->getMessage());
			}
		}

		self::assertSame([], $this->savesForSchema($saved, 'PurchaseOrder'));

	}//end testAPendingDispatchIsNotSentAgain()

	/**
	 * The send-block precondition (slice 02) is reused — neither
	 * sendToPeppol nor sendToPDFEmail may transition a PO whose chain is
	 * still pending (REQ-PO3W-002 send-block precondition).
	 *
	 * @return void
	 */
	public function testSendToPeppolRefusesWhenApprovalIncomplete(): void {
		$po = $this->approvedPo();
		$po['approvalChain'][1]['decision'] = 'pending';
		$po['approvalChain'][1]['decidedAt'] = '';
		$saved = [];
		$service = $this->buildService(data: ['PurchaseOrder' => [$po]], saved: $saved, adapter: $this->stubAdapter(participant: '0192:1234567890', failOnSubmit: true), mailer: $this->stubMailer());

		try {
			$service->sendToPeppol(administrationId: 'adm-1', purchaseOrderId: 'po-1');
			self::fail('sendToPeppol must refuse to advance an incomplete chain');
		} catch (\RuntimeException $e) {
			self::assertSame('Purchase order cannot be sent: approval chain incomplete', $e->getMessage());
		}

		self::assertSame([], $this->savesForSchema($saved, 'PurchaseOrder'));

	}//end testSendToPeppolRefusesWhenApprovalIncomplete()

	/**
	 * An accepted email moves the order to sent through the declared `send` transition.
	 *
	 * @return void
	 */
	public function testAnAcceptedEmailRunsTheSendTransition(): void {
		$saved = [];
		$runner = null;
		$service = $this->buildService(data: ['PurchaseOrder' => [$this->approvedPo()]], saved: $saved, adapter: $this->stubAdapter(participant: null, failOnSubmit: true), mailer: $mailer = $this->stubMailer(), runner: $runner);

		$result = $service->sendToPDFEmail(administrationId: 'adm-1', purchaseOrderId: 'po-1', fallbackReason: 'supplier_request_pdf');

		self::assertSame([['po-1', 'send', false]], $this->transitions);
		self::assertSame('sent', $result['statusCode']);
		self::assertSame('email', $result['dispatchChannel']);
		self::assertSame('sent', $result['dispatchStatus']);
		self::assertSame('supplier_request_pdf', $result['peppolFallbackReason']);
		self::assertNotEmpty($result['sentAt']);
		self::assertSame(1, $mailer->callCount);

	}//end testAnAcceptedEmailRunsTheSendTransition()

	/**
	 * A refused email is recorded and the order stays approved.
	 *
	 * @return void
	 */
	public function testARefusedEmailKeepsTheOrderApproved(): void {
		$saved = [];
		$service = $this->buildService(data: ['PurchaseOrder' => [$this->approvedPo()]], saved: $saved, adapter: $this->stubAdapter(participant: null, failOnSubmit: true), mailer: $this->stubMailer(accepts: false));

		$result = $service->sendToPDFEmail(administrationId: 'adm-1', purchaseOrderId: 'po-1');

		self::assertSame([], $this->transitions);
		self::assertSame(['approved', 'email', 'failed', 'The email channel did not accept the message.'], [$result['statusCode'], $result['dispatchChannel'], $result['dispatchStatus'], $result['dispatchError']]);

	}//end testARefusedEmailKeepsTheOrderApproved()

	/**
	 * Without a mail channel the send is refused and nothing is written or claimed.
	 *
	 * @return void
	 */
	public function testWithoutAMailChannelNothingIsClaimed(): void {
		$saved = [];
		$mailer = $this->stubMailer(available: false);
		$service = $this->buildService(data: ['PurchaseOrder' => [$this->approvedPo()]], saved: $saved, adapter: $this->stubAdapter(participant: null, failOnSubmit: true), mailer: $mailer);

		try {
			$service->sendToPDFEmail(administrationId: 'adm-1', purchaseOrderId: 'po-1');
			self::fail('an order was sent without a mail channel');
		} catch (\RuntimeException $e) {
			self::assertSame('Email sending is not configured', $e->getMessage());
		}

		self::assertSame(0, $mailer->callCount);
		self::assertSame([], $this->savesForSchema($saved, 'PurchaseOrder'));
		self::assertSame([], $this->transitions);

	}//end testWithoutAMailChannelNothingIsClaimed()

	/**
	 * integriq's delivery status sent moves a pending Peppol order to sent as the system.
	 *
	 * @return void
	 */
	public function testADeliveredPeppolOrderIsSent(): void {
		$po = array_merge($this->approvedPo(), ['dispatchChannel' => 'peppol', 'dispatchStatus' => 'pending']);
		$saved = [];
		$service = $this->buildService(data: ['PurchaseOrder' => [$po]], saved: $saved, adapter: $this->stubAdapter(participant: null, failOnSubmit: true), mailer: $this->stubMailer());

		self::assertTrue($service->applyPeppolDeliveryStatus(purchaseOrderId: 'po-1', status: 'sent', detail: ''));

		self::assertSame([['po-1', 'send', true]], $this->transitions);
		$persisted = $this->findSaved($saved, 'PurchaseOrder');
		self::assertSame('sent', $persisted['dispatchStatus']);
		self::assertNotEmpty($persisted['peppolSentAt']);
		self::assertFalse($service->applyPeppolDeliveryStatus(purchaseOrderId: 'po-1', status: 'sent', detail: ''), 'a sent order is not sent again');

	}//end testADeliveredPeppolOrderIsSent()

	/**
	 * A failed delivery is shown with its reason and the order stays approved.
	 *
	 * @return void
	 */
	public function testAFailedPeppolDeliveryIsShownNotHidden(): void {
		$po = array_merge($this->approvedPo(), ['dispatchChannel' => 'peppol', 'dispatchStatus' => 'pending']);
		$saved = [];
		$service = $this->buildService(data: ['PurchaseOrder' => [$po]], saved: $saved, adapter: $this->stubAdapter(participant: null, failOnSubmit: true), mailer: $this->stubMailer());

		self::assertTrue($service->applyPeppolDeliveryStatus(purchaseOrderId: 'po-1', status: 'failed', detail: 'receiver unreachable'));

		$persisted = $this->findSaved($saved, 'PurchaseOrder');
		self::assertSame(['approved', 'failed', 'receiver unreachable'], [$persisted['statusCode'], $persisted['dispatchStatus'], $persisted['dispatchError']]);
		self::assertSame([], $this->transitions);

	}//end testAFailedPeppolDeliveryIsShownNotHidden()

	/**
	 * A status for an order without a Peppol dispatch, an unknown order or an
	 * in-between status changes nothing.
	 *
	 * @return void
	 */
	public function testAStatusThatDoesNotApplyChangesNothing(): void {
		$saved = [];
		$pending = array_merge($this->approvedPo(), ['id' => 'po-2', 'dispatchChannel' => 'peppol', 'dispatchStatus' => 'pending']);
		$service = $this->buildService(data: ['PurchaseOrder' => [$this->approvedPo(), $pending]], saved: $saved, adapter: $this->stubAdapter(participant: null, failOnSubmit: true), mailer: $this->stubMailer());

		self::assertFalse($service->applyPeppolDeliveryStatus(purchaseOrderId: 'po-1', status: 'sent', detail: ''));
		self::assertFalse($service->applyPeppolDeliveryStatus(purchaseOrderId: 'missing', status: 'sent', detail: ''));
		self::assertFalse($service->applyPeppolDeliveryStatus(purchaseOrderId: 'po-2', status: 'queued', detail: ''));
		self::assertSame([], $saved);
		self::assertSame([], $this->transitions);

	}//end testAStatusThatDoesNotApplyChangesNothing()

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
		$mailer = $this->stubMailer();

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
			'statusCode' => 'approved',
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
					'decision' => 'approved',
					'decidedAt' => '2026-06-01T12:00:00+00:00',
					'userId' => 'teamleider-1',
				],
				[
					'role' => 'facility_manager',
					'order' => 2,
					'decision' => 'approved',
					'decidedAt' => '2026-06-02T09:00:00+00:00',
					'userId' => 'facility-1',
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
	 * @param bool $failOnLookup Whether the lookup should throw.
	 *
	 * @return PeppolTransmissionAdapterInterface
	 */
	private function stubAdapter(?string $participant, bool $failOnSubmit, bool $failOnLookup = false): object {
		return new class($participant, $failOnSubmit, $failOnLookup) implements PeppolTransmissionAdapterInterface {
			/**
			 * Captured lookup arguments.
			 *
			 * @var array<string,string>
			 */
			public array $lastLookup = [];

			/**
			 * Captured UBL.
			 *
			 * @var string
			 */
			public string $lastUbl = '';

			/**
			 * Captured order id.
			 *
			 * @var string
			 */
			public string $lastOrderId = '';

			/**
			 * Constructor.
			 *
			 * @param string|null $participant Lookup return value.
			 * @param bool $failOnSubmit Whether submitOrder() should throw.
			 * @param bool $failOnLookup Whether lookupParticipant() should throw.
			 */
			public function __construct(
				private readonly ?string $participant,
				private readonly bool $failOnSubmit,
				private readonly bool $failOnLookup,
			) {
			}

			/**
			 * @inheritDoc
			 */
			public function lookupParticipant(string $administrationId, string $partyId): ?string {
				if ($this->failOnLookup === true) {
					throw new \RuntimeException('Peppol sending is not configured');
				}

				$this->lastLookup = ['adm' => $administrationId, 'sup' => $partyId];
				return $this->participant;
			}

			/**
			 * @inheritDoc
			 */
			public function submitOrder(string $participantId, string $ublOrderXml, array $purchaseOrder = []): string {
				unset($participantId);
				if ($this->failOnSubmit === true) {
					throw new \RuntimeException('Peppol AP unreachable');
				}

				$this->lastUbl = $ublOrderXml;
				$this->lastOrderId = (string)($purchaseOrder['id'] ?? '');
				return 'urn:uuid:test-message-id';
			}

			/**
			 * Not exercised by sendToPeppol().
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
	 * @param bool $available What isAvailable() answers.
	 * @param bool $accepts   Whether the channel accepts the message.
	 *
	 * @return PurchaseOrderMailerInterface
	 */
	private function stubMailer(bool $available = true, bool $accepts = true): object {
		return new class($available, $accepts) implements PurchaseOrderMailerInterface {
			/**
			 * Number of times sendPurchaseOrderEmail was invoked.
			 *
			 * @var int
			 */
			public int $callCount = 0;

			/**
			 * Constructor.
			 *
			 * @param bool $available What isAvailable() answers.
			 * @param bool $accepts   What sendPurchaseOrderEmail() answers.
			 */
			public function __construct(
				private readonly bool $available,
				private readonly bool $accepts,
			) {
			}

			/**
			 * @inheritDoc
			 */
			public function sendPurchaseOrderEmail(string $administrationId, array $purchaseOrder): bool {
				unset($administrationId, $purchaseOrder);
				$this->callCount++;
				return $this->accepts;
			}

			/**
			 * @inheritDoc
			 */
			public function isAvailable(): bool {
				return $this->available;
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
		?ObjectTransitionRunner $runner = null,
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
			 * Set one field on a stored row, as the transition engine would.
			 *
			 * @param string $schema Schema slug.
			 * @param string $id     Row id.
			 * @param string $field  Field.
			 * @param mixed  $value  Value.
			 *
			 * @return void
			 */
			public function setField(string $schema, string $id, string $field, mixed $value): void {
				foreach (($this->data[$schema] ?? []) as $idx => $row) {
					if (($row['id'] ?? null) === $id) {
						$this->data[$schema][$idx][$field] = $value;
					}
				}
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
			transitionRunner: ($runner ?? $this->recordingRunner(stub: $stub)),
		);

	}//end buildService()

	/**
	 * A transition runner that records each run and moves the stored order to sent.
	 *
	 * @param object $stub The in-memory object store.
	 *
	 * @return ObjectTransitionRunner
	 */
	private function recordingRunner(object $stub): ObjectTransitionRunner {
		$runner = $this->createMock(ObjectTransitionRunner::class);
		$record = function (string $objectId, string $action, bool $asSystem) use ($stub): void {
			$this->transitions[] = [$objectId, $action, $asSystem];
			$stub->setField(schema: 'PurchaseOrder', id: $objectId, field: 'statusCode', value: 'sent');
		};
		$runner->method('run')->willReturnCallback(
			static function (string $objectId, string $action) use ($record): void {
				$record($objectId, $action, false);
			}
		);
		$runner->method('runAsSystem')->willReturnCallback(
			static function (string $objectId, string $action) use ($record): mixed {
				$record($objectId, $action, true);
				return null;
			}
		);

		return $runner;
	}//end recordingRunner()

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
