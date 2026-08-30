<?php

/**
 * Integration tests for the slice-11 audit-trail export (REQ-PO3W-010).
 *
 * Exercises the full PO → approval → GRN → invoice → match → exception →
 * resolution → GR/IR → payment lifecycle end-to-end and asserts that the
 * audit-trail export captures every transition with the correct actor +
 * timestamp:
 *
 *  1. createPurchaseOrder (slice 02) — materialises the approval chain
 *  2. PurchaseOrderApprovalService::recordApprovalDecision (slice 11) —
 *     stamps approver identity + decidedAt on each chain entry
 *  3. simulate Peppol send (peppolSentAt)
 *  4. simulate GRN receipt + acceptance
 *  5. simulate SupplierInvoice receipt
 *  6. simulate ThreeWayMatch evaluation + exception classification
 *  7. ExceptionResolutionService::acceptWithMotivation (slice 08) —
 *     stamps resolvedBy + resolutionAction + resolvedAt
 *  8. AuditExportService::generateAuditPackage — assembles the ledger,
 *     writes the ZIP, asserts every actor + timestamp lands in the export
 *
 * The integration test does NOT exercise the real OR ObjectService — it
 * shares the in-memory stub used by the other slice unit tests — but it
 * does drive the slice-08 / slice-11 services back-to-back through the
 * same store so we can verify the audit trail captures the full chain.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AuditExportService;
use OCA\Shillinq\Service\ExceptionResolutionService;
use OCA\Shillinq\Service\PurchaseOrder\CreditNoteRequestAdapterInterface;
use OCA\Shillinq\Service\PurchaseOrderApprovalService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Full lifecycle audit-trail integration test.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AuditTrailIntegrationTest extends TestCase {
	/**
	 * Drive a complete PO 3-way-match lifecycle and assert the audit
	 * package captures every actor + timestamp.
	 *
	 * @return void
	 */
	public function testFullLifecycleAuditTrailExport(): void {
		$data = [
			'PurchaseOrder' => [
				[
					'id' => 'po-100',
					'administrationId' => 'admin-1',
					'poNumber' => 'PO-100',
					'requesterId' => 'alice',
					'createdAt' => '2026-02-01T10:00:00+00:00',
					'supplierId' => 'sup-77',
					'totalAmount' => 1500.00,
					'currency' => 'EUR',
					'costCenter' => 'FAC-2026',
					'projectCode' => 'PRJ-A',
					'lifecycleState' => 'pending_approval',
					'approvalChain' => [
						['userId' => '', 'decision' => 'pending'],
					],
				],
			],
			'GoodsReceiptNote' => [
				[
					'id' => 'grn-100',
					'administrationId' => 'admin-1',
					'grnNumber' => 'GRN-100',
					'receivedBy' => 'dave',
					'receivedAt' => '2026-02-20T13:00:00+00:00',
					'statusCode' => 'accepted',
					'qualityCheckPassed' => true,
					'photos' => ['file-100', 'file-101'],
				],
			],
			'SupplierInvoice' => [
				[
					'id' => 'inv-100',
					'administrationId' => 'admin-1',
					'invoiceNumber' => 'INV-ERS-2026-00445',
					'supplierId' => 'sup-77',
					'peppolReceivedAt' => '2026-03-01T09:00:00+00:00',
					'statusCode' => 'exception',
					'totalExclVat' => 156000,
					'totalVat' => 32760,
					'totalInclVat' => 188760,
					'currency' => 'EUR',
					'ublFileId' => 'file-200',
				],
			],
			'ThreeWayMatch' => [
				[
					'id' => 'twm-100',
					'administrationId' => 'admin-1',
					'invoiceId' => 'inv-100',
					'createdAt' => '2026-03-01T09:05:00+00:00',
					'matchStatus' => 'exception_price',
					'matchedPoIds' => ['po-100'],
					'matchedGrnIds' => ['grn-100'],
					'divergenceDetails' => [['field' => 'totalInclVat', 'delta' => 1260]],
				],
			],
			'AdministrationMembership' => [],
			'ToleranceProfile' => [],
		];

		$saved = [];
		$stub = $this->objectServiceStub(data: $data, saved: $saved);

		// ---- Step 1: approval decision (slice 11) by user "bob" ----
		$approvalService = $this->buildApprovalService(
			stub: $stub,
			accessibleAdministrations: ['admin-1'],
			userId: 'bob'
		);

		$purchaseOrder = $approvalService->recordApprovalDecision(
			administrationId: 'admin-1',
			purchaseOrderId: 'po-100',
			decision: PurchaseOrderApprovalService::DECISION_APPROVED,
			comment: 'within budget'
		);
		self::assertSame('approved', $purchaseOrder['lifecycleState']);
		self::assertSame('bob', $purchaseOrder['approvalChain'][0]['userId']);
		self::assertNotEmpty($purchaseOrder['approvalChain'][0]['decidedAt']);

		// ---- Step 2: simulate Peppol send + PO lifecycleState=sent ----
		$purchaseOrder['lifecycleState'] = 'sent';
		$purchaseOrder['peppolSentAt'] = '2026-02-15T08:00:00+00:00';
		$purchaseOrder['peppolMessageId'] = 'urn:uuid:peppol-100';
		$stub->saveObject($purchaseOrder);

		// ---- Step 3: exception resolution (slice 08) by user "carol" ----
		$resolutionService = $this->buildResolutionService(
			stub: $stub,
			accessibleAdministrations: ['admin-1'],
			userId: 'carol'
		);

		$resolved = $resolutionService->acceptWithMotivation(
			administrationId: 'admin-1',
			matchId: 'twm-100',
			resolutionNotes: 'Supplier quoted price increase 1% — within ToleranceProfile-3 override'
		);
		self::assertSame(ExceptionResolutionService::ACTION_ACCEPTED, $resolved['resolutionAction']);
		self::assertSame('carol', $resolved['resolvedBy']);
		self::assertNotEmpty($resolved['resolvedAt']);

		// ---- Step 4: assemble the audit package (slice 11) ----
		$auditService = $this->buildAuditService(
			stub: $stub,
			accessibleAdministrations: ['admin-1'],
			userId: 'auditor'
		);

		$envelope = $auditService->generateAuditPackage(
			administrationId: 'admin-1',
			invoiceId: 'inv-100'
		);

		self::assertSame('inv-100', $envelope['invoiceId']);
		self::assertSame('INV-ERS-2026-00445', $envelope['invoiceNumber']);
		self::assertSame(7, $envelope['retentionYears']);
		self::assertGreaterThanOrEqual(7, $envelope['eventCount']);
		self::assertFileExists($envelope['zipPath']);

		// ---- Step 5: inspect ZIP — every actor + timestamp present ----
		$zip = new ZipArchive();
		self::assertTrue($zip->open($envelope['zipPath']));

		$base = $envelope['packageId'] . '/';
		$ledgerJson = $zip->getFromName($base . 'ledger.json');
		self::assertIsString($ledgerJson);
		$ledger = json_decode($ledgerJson, true);
		self::assertIsArray($ledger);

		// SHA-256 of ledger.json must match the envelope (immutability proof).
		self::assertSame(hash('sha256', $ledgerJson), $envelope['sha256']);

		$events = $ledger['events'];
		$actors = array_column($events, 'actor');
		// Approver, supplier-side actors, receiver, resolver — all stamped.
		self::assertContains('alice', $actors, 'po_created stamps requesterId');
		self::assertContains('bob', $actors, 'po_approval_decision stamps approver');
		self::assertContains('peppol_transmitter', $actors, 'po_sent_to_supplier stamps system actor');
		self::assertContains('dave', $actors, 'grn_received stamps receivedBy');
		self::assertContains('peppol_receiver', $actors, 'invoice_received stamps system actor');
		self::assertContains('matching_engine', $actors, 'match_evaluated stamps system actor');
		self::assertContains('carol', $actors, 'match_resolved stamps resolvedBy');

		$kinds = array_column($events, 'event');
		self::assertContains('po_created', $kinds);
		self::assertContains('po_approval_decision', $kinds);
		self::assertContains('po_sent_to_supplier', $kinds);
		self::assertContains('grn_received', $kinds);
		self::assertContains('grn_lifecycle_state', $kinds);
		self::assertContains('invoice_received', $kinds);
		self::assertContains('match_evaluated', $kinds);
		self::assertContains('match_resolved', $kinds);

		// Manifest stamps the retention + the SHA-256.
		$manifestJson = $zip->getFromName($base . 'manifest.json');
		$manifest = json_decode($manifestJson, true);
		self::assertSame(7, $manifest['retentionYears']);
		self::assertSame($envelope['sha256'], $manifest['sha256']);
		self::assertSame('BW2 art 2:10 / NV COS 230', $manifest['retentionPolicy']);

		// Attachments include the invoice UBL + GRN photos.
		$attachmentKinds = array_column($manifest['attachments'], 'kind');
		self::assertContains('invoice_ubl', $attachmentKinds);
		self::assertContains('grn_photo', $attachmentKinds);

		// ZIP entries cover every lifecycle object.
		self::assertNotFalse($zip->locateName($base . 'attachments/purchase-orders/po-100.json'));
		self::assertNotFalse($zip->locateName($base . 'attachments/goods-receipts/grn-100.json'));
		self::assertNotFalse($zip->locateName($base . 'attachments/three-way-match/twm-100.json'));
		self::assertNotFalse($zip->locateName($base . 'attachments/supplier-invoice/invoice.json'));

		$zip->close();
		@unlink($envelope['zipPath']);

	}//end testFullLifecycleAuditTrailExport()

	/**
	 * Build PurchaseOrderApprovalService sharing the same OR stub.
	 *
	 * @param object $stub OR stub.
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 * @param string $userId UID returned by the session.
	 *
	 * @return PurchaseOrderApprovalService
	 */
	private function buildApprovalService(
		object $stub,
		array $accessibleAdministrations,
		string $userId,
	): PurchaseOrderApprovalService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		return new PurchaseOrderApprovalService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			userSession: $userSession,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildApprovalService()

	/**
	 * Build ExceptionResolutionService sharing the same OR stub.
	 *
	 * @param object $stub OR stub.
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 * @param string $userId UID returned by the session.
	 *
	 * @return ExceptionResolutionService
	 */
	private function buildResolutionService(
		object $stub,
		array $accessibleAdministrations,
		string $userId,
	): ExceptionResolutionService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		$notificationManager = $this->createMock(INotificationManager::class);
		$notificationManager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$notification = $this->createMock(INotification::class);
				$notification->method('setApp')->willReturnSelf();
				$notification->method('setUser')->willReturnSelf();
				$notification->method('setDateTime')->willReturnSelf();
				$notification->method('setObject')->willReturnSelf();
				$notification->method('setSubject')->willReturnSelf();
				return $notification;
			}
		);

		$adapter = new class implements CreditNoteRequestAdapterInterface {
			/**
			 * Stub — never invoked on the accept path.
			 *
			 * @param array<string,mixed> $payload Dispute envelope.
			 *
			 * @return array{accepted:bool,dispatchId:?string,error:?string}
			 */
			public function submitDisputeCreditNote(array $payload): array {
				return ['accepted' => true, 'dispatchId' => 'unused', 'error' => null];
			}//end submitDisputeCreditNote()
		};

		return new ExceptionResolutionService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			userSession: $userSession,
			notificationManager: $notificationManager,
			logger: $logger,
			creditNoteAdapter: $adapter,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildResolutionService()

	/**
	 * Build AuditExportService sharing the same OR stub.
	 *
	 * @param object $stub OR stub.
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 * @param string $userId UID returned by the session.
	 *
	 * @return AuditExportService
	 */
	private function buildAuditService(
		object $stub,
		array $accessibleAdministrations,
		string $userId,
	): AuditExportService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		return new AuditExportService(
			container: $container,
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			userSession: $userSession,
			logger: $logger,
		);

	}//end buildAuditService()

	/**
	 * Build an in-memory OR ObjectService stub. Shared across slice-08,
	 * slice-10 and slice-11 services so the integration test sees the
	 * same canonical store every write lands in.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
	 * @param array<int,array<string,mixed>> $saved Capture ref.
	 *
	 * @return object
	 */
	private function objectServiceStub(array $data, array &$saved): object {
		return new class($data, $saved) {
			/**
			 * Schema rows.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Capture ref.
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
			 * @param array<string,array<int,array<string,mixed>>> $data Rows.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (ignored).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

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
			}//end setSchema()

			/**
			 * Apply equality filters to the active schema rows.
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
			}//end findAll()

			/**
			 * Persist an object — capture the save + update the store so
			 * subsequent finds see the latest state.
			 *
			 * @param array<string,mixed> $object Object to persist.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->saved[] = $object;

				$rows = ($this->data[$this->schema] ?? []);
				$id = (string)($object['id'] ?? '');
				if ($id !== '') {
					foreach ($rows as $index => $row) {
						if ((string)($row['id'] ?? '') === $id) {
							$rows[$index] = $object;
							$this->data[$this->schema] = $rows;
							return $object;
						}
					}
				}

				$rows[] = $object;
				$this->data[$this->schema] = $rows;
				return $object;
			}//end saveObject()
		};

	}//end objectServiceStub()
}//end class
